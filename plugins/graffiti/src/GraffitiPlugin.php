<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\Auth;
use App\Config;
use App\Csrf;
use App\Http;
use App\Plugin;
use App\PluginContext;
use App\PluginManifest;
use App\PostRepository;
use App\PostSaveEvent;

require_once __DIR__ . '/Bootstrap.php';
require_once __DIR__ . '/TokenGenerator.php';
require_once __DIR__ . '/InviteCodec.php';
require_once __DIR__ . '/FriendStore.php';
require_once __DIR__ . '/EnergyLedger.php';
require_once __DIR__ . '/StickerCatalogue.php';
require_once __DIR__ . '/GraffitiStore.php';
require_once __DIR__ . '/NonceCache.php';
require_once __DIR__ . '/PayloadValidator.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/Inbox.php';
require_once __DIR__ . '/HttpSender.php';
require_once __DIR__ . '/CatalogueFetcher.php';
require_once __DIR__ . '/Outbox.php';
require_once __DIR__ . '/OverlayRenderer.php';

/**
 * Graffiti — cross-blog sticker exchange between friends.
 *
 * Phase 1 (this file): skeleton only. Routes register and render placeholder
 * tab content via the shared admin-shell view. Later phases fill in:
 *   - Phase 2: Friend handshake (FriendStore + invite UI)
 *   - Phase 3: Energy ledger + post.save hook + navbar count
 *   - Phase 4: Inbox webhook (POST /graffiti/receive)
 *   - Phase 5: Receiver rate limiter
 *   - Phase 6: Outbox + retry queue
 *   - Phase 7: Catalogue admin + post-page overlay render
 *   - Phase 8: Moderation UI (Received tab + hide/unhide)
 */
final class GraffitiPlugin implements Plugin
{
    public function manifest(): PluginManifest
    {
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/../manifest.json'), true);
        return PluginManifest::fromArray($data);
    }

    public function register(PluginContext $ctx): void
    {
        Bootstrap::ensureDefaults($ctx->storagePath(), $ctx->pluginRoot());

        // Phase 7 will populate this stylesheet with overlay rules; ship empty
        // to avoid 404s if a post page references it before Phase 7 lands.
        $ctx->css('graffiti.css');

        // Admin-only navbar entry. Label includes unread count when > 0;
        // register() runs every request so the count is always fresh.
        $unread = (new GraffitiStore($ctx->storagePath()))->unreadCount();
        $navLabel = $unread > 0 ? "Graffiti ({$unread})" : 'Graffiti';
        $ctx->nav($navLabel, '/admin/graffiti', 'header', 'admin');

        $friends    = new FriendStore($ctx->storagePath());
        $ledger     = new EnergyLedger($ctx->storagePath());
        $store      = new GraffitiStore($ctx->storagePath());
        $nonces     = new NonceCache($ctx->storagePath());
        $catalogue  = new StickerCatalogue($ctx->storagePath(), $ctx->pluginRoot());
        $catFetcher = new CatalogueFetcher($ctx->storagePath());
        $outbox     = new Outbox($friends, $ctx->storagePath());

        // Mint energy on every newly-published post. Idempotent inside the
        // ledger via `minted_slugs`, so re-saves and accidental double-fires
        // never inflate balance.
        $ctx->onPostSave(function (PostSaveEvent $e) use ($ledger): void {
            if ($e->isNew && $e->published) {
                $ledger->mint(EnergyLedger::MINT_PER_POST, "post:{$e->slug}");
            }
        });

        // Public webhook (token-auth, not Auth::requireAuth). Inbox owns
        // the entire pipeline including 403/404/409/422/500 responses.
        $ctx->post('/graffiti/receive', function () use ($friends, $store, $nonces, $catalogue): void {
            $inbox = new Inbox($friends, $store, $nonces, $catalogue, new PostRepository());
            $inbox->handle();
        });

        // Public catalogue endpoint — friends' CatalogueFetcher reads this.
        $ctx->get('/graffiti/stickers.json', fn () => $this->publishCatalogue($catalogue));

        // Plugin health probe — used by friend handshake to verify the target
        // blog actually has graffiti enabled before storing tokens.
        $ctx->get('/graffiti/health', fn () => $this->publishHealth());

        // Post-page overlay: subscribe new core slot rendered inside </article>.
        // When admin is logged in, also emits the spray-can button + modal +
        // data island so the operator can decorate from the post page itself.
        //
        // The asset-prefix system only auto-loads our CSS on `/admin/graffiti/*`
        // (our registered route prefixes). Post pages are outside that. We
        // emit a direct `<link>` here so the overlay layer + spray button get
        // their absolute-positioning rules on every post that needs them.
        $renderer = new OverlayRenderer($store, $friends, $catalogue);
        $ctx->onPostArticleEnd(static function (array $context) use ($renderer, $catalogue, $store): ?string {
            $slug = (string) ($context['slug'] ?? '');
            if ($slug === '') {
                return null;
            }
            $overlay = $renderer->render($slug);
            $admin = Auth::check();

            // Skip the whole slot when no overlay items AND not admin — keeps
            // public visitors' post pages exactly as before in the common case.
            if ($overlay === '' && !$admin) {
                return null;
            }

            $cssUrl = Http::pluginAsset('graffiti', 'graffiti.css');
            $jsUrl  = Http::pluginAsset('graffiti', 'graffiti.js');
            $html = '<link rel="stylesheet" href="' . Http::e($cssUrl) . '">';
            $html .= $overlay;
            if ($admin) {
                $html .= self::sprayControlsHtml($slug, $catalogue);
            }
            // graffiti.js handles BOTH the admin spray modal AND the per-item
            // dismiss button (any visitor). Load it whenever the overlay
            // renders OR admin is around — same script, conditional setup.
            $html .= '<script src="' . Http::e($jsUrl) . '" defer></script>';
            return $html;
        });

        $ctx->adminGet('/admin/graffiti',           fn () => $this->showReceivedAndDrainOutbox($ctx, $outbox, $store, $friends, $catalogue));
        $ctx->adminPost('/admin/graffiti/hide/{id}',   fn (array $p) => $this->setHidden($store, (string) ($p['id'] ?? ''), true));
        $ctx->adminPost('/admin/graffiti/unhide/{id}', fn (array $p) => $this->setHidden($store, (string) ($p['id'] ?? ''), false));
        $ctx->adminPost('/admin/graffiti/delete/{id}', fn (array $p) => $this->deleteItem($store, (string) ($p['id'] ?? '')));
        $ctx->adminGet('/admin/graffiti/friends',   fn () => $this->afterDrain($outbox, fn () => $this->showFriends($ctx, $friends)));
        $ctx->adminGet('/admin/graffiti/stickers',  fn () => $this->afterDrain($outbox, fn () => $this->showStickers($ctx, $catalogue)));
        $ctx->adminGet('/admin/graffiti/energy',    fn () => $this->afterDrain($outbox, fn () => $this->showEnergy($ctx, $ledger)));
        $ctx->adminGet('/admin/graffiti/send',      fn () => $this->showSend($ctx, $friends, $ledger, $catFetcher, $catalogue));
        $ctx->adminPost('/admin/graffiti/send/submit', fn () => $this->submitSend($friends, $ledger, $outbox, $catFetcher, $store, $catalogue));
        $ctx->adminPost('/admin/graffiti/stickers/update', fn () => $this->updateSticker($catalogue));
        $ctx->adminPost('/admin/graffiti/stickers/toggle/{id}', fn (array $p) => $this->toggleSticker($catalogue, (string) ($p['id'] ?? '')));

        $ctx->adminPost('/admin/graffiti/friends/invite',        fn () => $this->createInvite($friends));
        $ctx->adminPost('/admin/graffiti/friends/accept',        fn () => $this->acceptInvite($friends));
        $ctx->adminPost('/admin/graffiti/friends/revoke/{id}',   fn (array $p) => $this->revokeFriend($friends, $p['id'] ?? ''));
    }

    private function showStickers(PluginContext $ctx, StickerCatalogue $catalogue): void
    {
        $store = new GraffitiStore($ctx->storagePath());
        $friends = new FriendStore($ctx->storagePath());
        $ledger = new EnergyLedger($ctx->storagePath());
        $ctx->view('admin-stickers', [
            'stickers' => $catalogue->all(),
            'csrf'     => Csrf::token(),
            'flash'    => self::popFlash(),
            'tabCounts' => self::tabCounts($store, $friends, $catalogue, $ledger),
        ]);
    }

    private function updateSticker(StickerCatalogue $catalogue): void
    {
        Csrf::requireValid();
        $id = (string) ($_POST['id'] ?? '');
        if ($id === '') {
            self::flash('missing sticker id');
            Http::redirect('/admin/graffiti/stickers');
            return;
        }
        // Price-only update — enabled state has its own toggle endpoint
        // so the SAVE button doesn't accidentally disable a sticker just
        // because the form omitted the (removed) checkbox.
        $catalogue->setOverride($id, [
            'price' => (int) ($_POST['price'] ?? 0),
        ]);
        self::flash("price saved for {$id}");
        Http::redirect('/admin/graffiti/stickers');
    }

    private function toggleSticker(StickerCatalogue $catalogue, string $id): void
    {
        Csrf::requireValid();
        $row = $catalogue->find($id);
        if ($row === null) {
            self::flash('sticker not found');
            Http::redirect('/admin/graffiti/stickers');
            return;
        }
        $next = !(bool) ($row['enabled'] ?? false);
        $catalogue->setOverride($id, ['enabled' => $next]);
        self::flash($next ? "enabled {$id}" : "disabled {$id}");
        Http::redirect('/admin/graffiti/stickers');
    }

    /**
     * Health probe — JSON `{ok, plugin, version, api_version}`. Hit by the
     * friend handshake on the other side to confirm the target has graffiti
     * enabled and is reachable before tokens get exchanged.
     */
    private function publishHealth(): void
    {
        $manifest = $this->manifest();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => true,
            'plugin' => 'graffiti',
            'version' => $manifest->version,
            'api_version' => $manifest->apiVersion,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ping the target blog's `/graffiti/health` before storing friendship
     * state. Returns true if the target is reachable AND has graffiti
     * enabled. Failure surfaces as a user-visible flash explaining why.
     */
    private static function probeFriendReachable(string $blogUrl): bool
    {
        $url = rtrim($blogUrl, '/') . '/graffiti/health';
        $res = HttpSender::get($url);
        if ($res['transport_failed'] || $res['status'] !== 200) {
            $why = $res['transport_failed']
                ? 'cannot reach ' . $blogUrl
                : "target returned HTTP {$res['status']} (graffiti plugin enabled there?)";
            self::flash("health check failed: {$why}");
            return false;
        }
        $body = json_decode($res['body'], true);
        if (!is_array($body) || ($body['plugin'] ?? '') !== 'graffiti') {
            self::flash('target is reachable but is NOT running the graffiti plugin');
            return false;
        }
        return true;
    }

    /**
     * Block self-friending. Compares normalized blog URLs against our own
     * SITE_URL so a copy-paste mistake doesn't create a useless local loop.
     */
    private static function isSelfBlog(string $blogUrl): bool
    {
        $ours = rtrim((string) Config::get('SITE_URL'), '/');
        return rtrim($blogUrl, '/') === $ours;
    }

    /**
     * Public sticker catalogue. Enabled-only, projected to a small shape
     * other blogs can fetch through CatalogueFetcher.
     */
    private function publishCatalogue(StickerCatalogue $catalogue): void
    {
        $rows = [];
        foreach ($catalogue->enabled() as $r) {
            $rows[] = [
                'id'    => (string) ($r['id'] ?? ''),
                'name'  => (string) ($r['name'] ?? ''),
                'price' => (int) ($r['default_price'] ?? 0),
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        echo json_encode($rows, JSON_UNESCAPED_SLASHES);
    }

    /** Drain a small outbox batch before every admin page render. */
    private function afterDrain(Outbox $outbox, callable $next): void
    {
        $outbox->processBatch(3);
        $next();
    }

    private function showReceivedAndDrainOutbox(
        PluginContext $ctx,
        Outbox $outbox,
        GraffitiStore $store,
        FriendStore $friends,
        StickerCatalogue $catalogue,
    ): void {
        $outbox->processBatch(3);

        $tabCounts = self::tabCounts($store, $friends, $catalogue, new EnergyLedger($ctx->storagePath()));

        $items = $store->all();
        // Snapshot unseen ids BEFORE marking, so the view can render a [NEW]
        // chip for items the owner just discovered on this page load.
        $unseenIds = [];
        foreach ($items as $row) {
            if (!(bool) ($row['hidden'] ?? false) && !(bool) ($row['seen_by_owner'] ?? false)) {
                $unseenIds[] = (string) $row['id'];
            }
        }
        if ($unseenIds !== []) {
            $store->markSeen($unseenIds);
        }

        // Newest first for display.
        usort($items, static fn (array $a, array $b): int =>
            ((int) ($b['received_at'] ?? 0)) <=> ((int) ($a['received_at'] ?? 0))
        );

        // Pre-resolve friend handles + sticker names so the view stays dumb.
        // 'self' is a sentinel id — synthesize the owner attribution from env.
        $friendCache = ['self' => self::selfFriendStub()];
        foreach ($items as &$row) {
            $fid = (string) ($row['from_friend_id'] ?? '');
            $friendCache[$fid] ??= $friends->find($fid);
            $row['_friend'] = $friendCache[$fid];

            $type = (string) ($row['type'] ?? '');
            $payload = (array) ($row['payload'] ?? []);
            if ($type === 'sticker' || $type === 'spray') {
                $key = $type === 'sticker' ? 'sticker_id' : 'spray_id';
                $id = (string) ($payload[$key] ?? '');
                $cat = $catalogue->find($id);
                $row['_preview'] = $cat['name'] ?? $id;
            } elseif ($type === 'text') {
                $row['_preview'] = mb_substr((string) ($payload['text'] ?? ''), 0, 60);
            } else {
                $row['_preview'] = '';
            }
        }
        unset($row);

        $ctx->view('admin-received', [
            'items'     => $items,
            'unseenIds' => $unseenIds,
            'csrf'      => Csrf::token(),
            'flash'     => self::popFlash(),
            'tabCounts' => $tabCounts,
        ]);
    }

    private function setHidden(GraffitiStore $store, string $id, bool $hidden): void
    {
        Csrf::requireValid();
        if ($id === '' || !$store->setHidden($id, $hidden)) {
            self::flash('item not found');
        } else {
            self::flash($hidden ? "hidden {$id}" : "unhidden {$id}");
        }
        Http::redirect('/admin/graffiti');
    }

    private function deleteItem(GraffitiStore $store, string $id): void
    {
        Csrf::requireValid();
        if ($id === '' || !$store->delete($id)) {
            self::flash('item not found');
        } else {
            self::flash("deleted {$id}");
        }
        Http::redirect('/admin/graffiti');
    }

    private function showSend(
        PluginContext $ctx,
        FriendStore $friends,
        EnergyLedger $ledger,
        CatalogueFetcher $catFetcher,
        StickerCatalogue $catalogue,
    ): void {
        $activeFriends = array_values(array_filter(
            $friends->all(),
            static fn (array $r): bool => ($r['state'] ?? '') === 'active',
        ));

        $selectedId = (string) ($_GET['friend'] ?? '');
        $catalogueRows = [];
        $selectedFriend = null;
        if ($selectedId === 'self') {
            // Composer for self-decoration: own posts, own catalogue, own prices.
            $selectedFriend = self::selfFriendStub();
            foreach ($catalogue->enabled() as $r) {
                $catalogueRows[] = [
                    'id' => (string) ($r['id'] ?? ''),
                    'name' => (string) ($r['name'] ?? ''),
                    'price' => (int) ($r['default_price'] ?? 0),
                ];
            }
        } elseif ($selectedId !== '') {
            $row = $friends->find($selectedId);
            if ($row !== null && ($row['state'] ?? '') === 'active') {
                $selectedFriend = $row;
                $catalogueRows = $catFetcher->fetch($selectedId, (string) $row['blog_url']);
            }
        }

        $ctx->view('admin-send', [
            'friends'        => $activeFriends,
            'selfStub'       => self::selfFriendStub(),
            'selectedFriend' => $selectedFriend,
            'catalogue'      => $catalogueRows,
            'balance'        => $ledger->balance(),
            'csrf'           => Csrf::token(),
            'flash'          => self::popFlash(),
        ]);
    }

    /**
     * Emit the in-page spray-paint controls visible only to a logged-in
     * admin viewing a post:
     *
     *   - A circular spray-can button anchored bottom-right (above the
     *     existing back-to-top button)
     *   - A modal with sticker picker + free-text input
     *   - A JSON data island carrying csrf + slug + enabled catalogue so
     *     graffiti.js can self-bootstrap without an extra fetch
     *   - <script src=...> tag loading the asset (cache-busted by mtime)
     *
     * The whole bundle is markup-only — `Auth::check()` gating happens in
     * the caller (`onPostArticleEnd` closure), so non-admin visitors never
     * see any trace of this surface.
     */
    private static function sprayControlsHtml(string $slug, StickerCatalogue $catalogue): string
    {
        // Project the catalogue into a small JSON shape the JS can render
        // the sticker picker from. Only enabled stickers offered.
        $items = [];
        foreach ($catalogue->enabled() as $row) {
            $items[] = [
                'id'    => (string) ($row['id'] ?? ''),
                'name'  => (string) ($row['name'] ?? ''),
                'svg'   => (string) ($row['svg_filename'] ?? ''),
                'price' => (int)    ($row['default_price'] ?? 0),
            ];
        }
        $ctxJson = (string) json_encode([
            'csrf'      => Csrf::token(),
            'slug'      => $slug,
            'catalogue' => $items,
        ], JSON_UNESCAPED_SLASHES);

        // graffiti.js itself is loaded by the parent onPostArticleEnd closure
        // (so it ships for all visitors — needed for the per-item dismiss).
        // Here we only emit the admin-specific UI + the JSON context island
        // the script will pick up if present.

        // Inline spray-can icon (currentColor) instead of a coloured emoji so
        // the button matches the .back-to-top accent chrome instead of
        // breaking the CRT monochrome scheme.
        $sprayIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" '
            . 'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="8" y="9" width="10" height="13" rx="1.5"/>'
            . '<line x1="11" y1="13" x2="15" y2="13"/>'
            . '<rect x="11" y="6" width="4" height="2"/>'
            . '<circle cx="4"  cy="4" r="0.9" fill="currentColor" stroke="none"/>'
            . '<circle cx="6"  cy="6" r="0.6" fill="currentColor" stroke="none"/>'
            . '<circle cx="3"  cy="7" r="0.5" fill="currentColor" stroke="none"/>'
            . '<circle cx="7"  cy="3" r="0.5" fill="currentColor" stroke="none"/>'
            . '</svg>';

        return <<<HTML
<button type="button" id="graffiti-spray-btn" class="graffiti-spray-btn"
        aria-label="Decorate this post" title="Decorate this post">{$sprayIcon}</button>

<div id="graffiti-modal" class="graffiti-modal" hidden role="dialog"
     aria-modal="true" aria-labelledby="graffiti-modal-title">
    <div class="graffiti-modal-card">
        <header>
            <h2 id="graffiti-modal-title">// GRAFFITI</h2>
            <button type="button" class="graffiti-modal-close" aria-label="Close">×</button>
        </header>
        <div class="graffiti-modal-stickers" data-grid></div>
        <div class="graffiti-modal-textrow">
            <input type="text" maxlength="140" data-text
                   placeholder="or type up to 140 chars…">
            <select data-font title="Font">
                <option value="marker" style="font-family:'Caveat',cursive">Marker</option>
                <option value="spray"  style="font-family:'Bangers',cursive">Spray</option>
                <option value="tag"    style="font-family:'Russo One',sans-serif">Tag</option>
                <option value="block"  style="font-family:'Bungee Spice',cursive">Block</option>
            </select>
            <select data-color title="Color">
                <option value="green"  style="color:#39ff14">Green</option>
                <option value="white"  style="color:#f5f5f5">White</option>
                <option value="pink"   style="color:#ff3399">Pink</option>
                <option value="yellow" style="color:#ffd700">Yellow</option>
                <option value="orange" style="color:#ff7700">Orange</option>
                <option value="red"    style="color:#ff3344">Red</option>
                <option value="blue"   style="color:#00b3ff">Blue</option>
                <option value="purple" style="color:#a855f7">Purple</option>
            </select>
            <button type="button" data-text-go>[ TEXT ]</button>
        </div>
    </div>
</div>

<script type="application/json" id="graffiti-ctx">{$ctxJson}</script>
HTML;
    }

    /**
     * Counts displayed in tab labels — computed once per admin page render
     * so all 4 tabs always show their live totals. Energy tab shows the
     * current ledger balance (not a count of items).
     *
     * @return array{received:int,friends:int,stickers:int,energy:int}
     */
    private static function tabCounts(
        GraffitiStore $store,
        FriendStore $friends,
        StickerCatalogue $catalogue,
        EnergyLedger $ledger,
    ): array {
        $visible = 0;
        foreach ($store->all() as $row) {
            if (!(bool) ($row['hidden'] ?? false)) {
                $visible++;
            }
        }
        $activeFriends = 0;
        foreach ($friends->all() as $row) {
            $state = (string) ($row['state'] ?? '');
            if ($state !== 'revoked') {
                $activeFriends++;
            }
        }
        return [
            'received' => $visible,
            'friends'  => $activeFriends,
            'stickers' => count($catalogue->enabled()),
            'energy'   => $ledger->balance(),
        ];
    }

    /** Synthetic friend-shaped row representing the operator themself. */
    private static function selfFriendStub(): array
    {
        return [
            'id'       => 'self',
            'handle'   => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'me'),
            'blog_url' => rtrim((string) Config::get('SITE_URL'), '/'),
            'state'    => 'active',
        ];
    }

    private function submitSend(
        FriendStore $friends,
        EnergyLedger $ledger,
        Outbox $outbox,
        CatalogueFetcher $catFetcher,
        GraffitiStore $store,
        StickerCatalogue $catalogue,
    ): void {
        Csrf::requireValid();

        $friendId = (string) ($_POST['friend_id'] ?? '');
        $isSelf   = $friendId === 'self';

        if (!$isSelf) {
            $friend = $friends->find($friendId);
            if ($friend === null || ($friend['state'] ?? '') !== 'active') {
                self::flash('friend not active');
                Http::redirect('/admin/graffiti/send');
                return;
            }
        }
        $redirect = '/admin/graffiti/send?friend=' . urlencode($friendId);

        $postSlug = trim((string) ($_POST['post_slug'] ?? ''));
        $type     = (string) ($_POST['type'] ?? 'sticker');
        $text     = trim((string) ($_POST['text'] ?? ''));
        $stickerId = trim((string) ($_POST['sticker_id'] ?? ''));
        $x = (float) ($_POST['x'] ?? 0.5);
        $y = (float) ($_POST['y'] ?? 0.5);
        $rotation = (float) ($_POST['rotation'] ?? 0);

        if ($postSlug === '') {
            self::flash('post_slug required');
            Http::redirect($redirect);
            return;
        }
        if (!in_array($type, ['text', 'sticker', 'spray'], true)) {
            self::flash('invalid type');
            Http::redirect($redirect);
            return;
        }

        // Build payload + resolve price from the correct catalogue (own for
        // self, friend's public catalogue for cross-blog).
        $payloadInner = ['position' => [
            'x' => max(0, min(1, $x)),
            'y' => max(0, min(1, $y)),
            'rotation' => max(-180, min(180, $rotation)),
        ]];
        $price = 1;

        if ($type === 'text') {
            if ($text === '' || mb_strlen($text) > PayloadValidator::TEXT_MAX_CHARS) {
                self::flash('text empty or too long');
                Http::redirect($redirect);
                return;
            }
            $payloadInner['text'] = $text;
            // Optional font / color tokens. Allowlist-check now so we don't
            // store junk that the renderer would silently fall back from.
            $font = (string) ($_POST['font'] ?? '');
            $color = (string) ($_POST['color'] ?? '');
            if ($font !== '' && in_array($font, PayloadValidator::TEXT_FONTS, true)) {
                $payloadInner['font'] = $font;
            }
            if ($color !== '' && in_array($color, PayloadValidator::TEXT_COLORS, true)) {
                $payloadInner['color'] = $color;
            }
            $price = 1;
        } else {
            if ($stickerId === '') {
                self::flash('pick a sticker');
                Http::redirect($redirect);
                return;
            }
            $key = $type === 'sticker' ? 'sticker_id' : 'spray_id';
            $payloadInner[$key] = $stickerId;

            $resolvedPrice = null;
            if ($isSelf) {
                $local = $catalogue->find($stickerId);
                if ($local !== null && (bool) ($local['enabled'] ?? false)) {
                    $resolvedPrice = (int) ($local['default_price'] ?? 0);
                }
            } else {
                $remote = $catFetcher->fetch($friendId, (string) $friend['blog_url']);
                foreach ($remote as $r) {
                    if ($r['id'] === $stickerId) {
                        $resolvedPrice = (int) $r['price'];
                        break;
                    }
                }
            }
            if ($resolvedPrice === null) {
                self::flash("sticker '{$stickerId}' not found in catalogue");
                Http::redirect($redirect);
                return;
            }
            $price = max(1, $resolvedPrice);
        }

        if (!$ledger->canSpend($price)) {
            self::flash("insufficient energy (need {$price}, have {$ledger->balance()})");
            Http::redirect($redirect);
            return;
        }

        if ($isSelf) {
            // No outbox / no webhook / no token / no nonce for self-decoration.
            // Slug existence check uses local PostRepository so a typo doesn't
            // create dangling graffiti for a non-existent post.
            if ((new PostRepository())->bySlug($postSlug) === null) {
                self::flash("post '{$postSlug}' not found on your blog");
                Http::redirect($redirect);
                return;
            }
            $ledger->spend($price, "graffiti:self");
            $id = $store->append([
                'from_friend_id' => 'self',
                'post_slug' => $postSlug,
                'type' => $type,
                'payload' => $payloadInner,
                'nonce' => 'self-' . bin2hex(random_bytes(4)),
            ]);
            // Self-stickers are seen by definition — don't bump the unread chip.
            $store->markSeen([$id]);
            self::flash("decorated {$postSlug} — debited {$price} energy");
            Http::redirect($redirect);
            return;
        }

        $nonce = bin2hex(random_bytes(8));
        $body = [
            'from' => [
                'blog_url' => rtrim((string) Config::get('SITE_URL'), '/'),
                'handle'   => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'anon'),
            ],
            'token'    => (string) $friend['outgoing_token'],
            'post_slug' => $postSlug,
            'type'     => $type,
            'payload'  => $payloadInner,
            'nonce'    => $nonce,
            'client_version' => '0.1.0',
        ];

        // Spend first so a failed enqueue doesn't leave an orphan debit.
        // Energy NOT refunded on permanent fail (lesson: don't graffiti dead
        // blogs); already checked friend.state above, so no refund path here.
        $ledger->spend($price, "graffiti:pending");
        $id = $outbox->enqueue($friendId, $body);

        $row = $outbox->find($id);
        $status = (string) ($row['status'] ?? '');
        if ($status === Outbox::STATUS_SENT) {
            self::flash("sent — debited {$price} energy");
        } elseif ($status === Outbox::STATUS_FAILED_PERM) {
            self::flash("rejected by target ({$row['last_error']}) — energy NOT refunded");
        } else {
            self::flash("queued (target unreachable, will retry) — debited {$price} energy");
        }

        Http::redirect($redirect);
    }

    private function showEnergy(PluginContext $ctx, EnergyLedger $ledger): void
    {
        // Fallback: catch any .md drops that bypassed the admin save flow
        // (operator scp/git pull). Reconcile is cheap — already-cached index.
        $ledger->reconcile(new PostRepository());
        $store = new GraffitiStore($ctx->storagePath());
        $friends = new FriendStore($ctx->storagePath());
        $catalogue = new StickerCatalogue($ctx->storagePath(), $ctx->pluginRoot());
        $ctx->view('admin-energy', [
            'balance' => $ledger->balance(),
            'ledger'  => $ledger->ledger(),
            'mintPerPost' => EnergyLedger::MINT_PER_POST,
            'tabCounts' => self::tabCounts($store, $friends, $catalogue, $ledger),
        ]);
    }

    private function showFriends(PluginContext $ctx, FriendStore $friends): void
    {
        $store = new GraffitiStore($ctx->storagePath());
        $catalogue = new StickerCatalogue($ctx->storagePath(), $ctx->pluginRoot());
        $ledger = new EnergyLedger($ctx->storagePath());
        $ctx->view('admin-friends', [
            'friends' => $friends->all(),
            'csrf'    => Csrf::token(),
            'flash'   => self::popFlash(),
            'block'   => self::popInviteBlock(),
            'tabCounts' => self::tabCounts($store, $friends, $catalogue, $ledger),
        ]);
    }

    private function createInvite(FriendStore $friends): void
    {
        Csrf::requireValid();
        $handle  = trim((string) ($_POST['handle'] ?? ''));
        $blogUrl = trim((string) ($_POST['blog_url'] ?? ''));

        if ($handle === '' || $blogUrl === '') {
            self::flash('handle and blog_url are required');
            Http::redirect('/admin/graffiti/friends');
            return;
        }
        if (self::isSelfBlog($blogUrl)) {
            self::flash("can't friend yourself — this IS your blog");
            Http::redirect('/admin/graffiti/friends');
            return;
        }
        if ($friends->findByBlogUrl($blogUrl) !== null) {
            self::flash("already have a friend at {$blogUrl}");
            Http::redirect('/admin/graffiti/friends');
            return;
        }
        if (!self::probeFriendReachable($blogUrl)) {
            Http::redirect('/admin/graffiti/friends');
            return;
        }

        $incomingToken = TokenGenerator::generate();
        $endpoint = rtrim($blogUrl, '/') . '/graffiti/receive';
        $id = $friends->create([
            'handle'            => $handle,
            'blog_url'          => rtrim($blogUrl, '/'),
            'graffiti_endpoint' => $endpoint,
            'incoming_token'    => $incomingToken,
            'outgoing_token'    => null,
            'state'             => 'pending',
        ]);

        $ourBlock = InviteCodec::encode([
            'blog_url' => rtrim((string) Config::get('SITE_URL'), '/'),
            'handle'   => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'anon'),
            'endpoint' => rtrim((string) Config::get('SITE_URL'), '/') . '/graffiti/receive',
            'token'    => $incomingToken,
        ]);

        self::flash("invite created for {$handle} — send the block below; paste their reply with [ ACCEPT ]");
        self::stashInviteBlock($ourBlock);
        Http::redirect('/admin/graffiti/friends#friend-' . $id);
    }

    private function acceptInvite(FriendStore $friends): void
    {
        Csrf::requireValid();
        $block = (string) ($_POST['block'] ?? '');

        try {
            $invite = InviteCodec::decode($block);
        } catch (\Throwable $e) {
            self::flash('paste failed: ' . $e->getMessage());
            Http::redirect('/admin/graffiti/friends');
            return;
        }

        if (self::isSelfBlog($invite['blog_url'])) {
            self::flash("can't friend yourself — this invite block points at your own blog");
            Http::redirect('/admin/graffiti/friends');
            return;
        }
        if (!self::probeFriendReachable($invite['blog_url'])) {
            Http::redirect('/admin/graffiti/friends');
            return;
        }

        // Two flows:
        //   (1) We initiated → row already exists in `pending` state with our
        //       incoming_token set. Their paste-back fills outgoing_token,
        //       state → active. No reciprocal block needed.
        //   (2) They initiated → no row exists. We create row with their
        //       token as outgoing_token, mint our incoming_token, state →
        //       active immediately (we already have both halves), and emit
        //       our invite block so they can complete on their side.
        $existing = $friends->findByBlogUrl($invite['blog_url']);

        if ($existing !== null && ($existing['state'] ?? '') === 'pending'
            && empty($existing['outgoing_token'])) {
            $friends->update((string) $existing['id'], [
                'outgoing_token' => $invite['token'],
                'graffiti_endpoint' => $invite['endpoint'],
                'handle' => $invite['handle'],
                'state' => 'active',
                'completed_at' => time(),
            ]);
            self::flash('handshake completed with ' . $invite['handle']);
            Http::redirect('/admin/graffiti/friends#friend-' . $existing['id']);
            return;
        }

        if ($existing !== null) {
            self::flash('already have a friend at ' . $invite['blog_url']);
            Http::redirect('/admin/graffiti/friends');
            return;
        }

        $incomingToken = TokenGenerator::generate();
        $id = $friends->create([
            'handle'            => $invite['handle'],
            'blog_url'          => $invite['blog_url'],
            'graffiti_endpoint' => $invite['endpoint'],
            'incoming_token'    => $incomingToken,
            'outgoing_token'    => $invite['token'],
            'state'             => 'active',
            'completed_at'      => time(),
        ]);

        $ourBlock = InviteCodec::encode([
            'blog_url' => rtrim((string) Config::get('SITE_URL'), '/'),
            'handle'   => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'anon'),
            'endpoint' => rtrim((string) Config::get('SITE_URL'), '/') . '/graffiti/receive',
            'token'    => $incomingToken,
        ]);
        self::flash('accepted invite from ' . $invite['handle'] . ' — send the block below back to them');
        self::stashInviteBlock($ourBlock);
        Http::redirect('/admin/graffiti/friends#friend-' . $id);
    }

    private function revokeFriend(FriendStore $friends, string $id): void
    {
        Csrf::requireValid();
        if ($id === '' || $friends->find($id) === null) {
            self::flash('friend not found');
        } else {
            $friends->revoke($id);
            self::flash('friend removed');
        }
        Http::redirect('/admin/graffiti/friends');
    }

    private static function flash(string $msg): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['graffiti_flash'] = $msg;
        }
    }

    private static function popFlash(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $msg = $_SESSION['graffiti_flash'] ?? null;
        unset($_SESSION['graffiti_flash']);
        return is_string($msg) ? $msg : null;
    }

    private static function stashInviteBlock(string $block): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['graffiti_invite_block'] = $block;
        }
    }

    private static function popInviteBlock(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $block = $_SESSION['graffiti_invite_block'] ?? null;
        unset($_SESSION['graffiti_invite_block']);
        return is_string($block) ? $block : null;
    }
}
