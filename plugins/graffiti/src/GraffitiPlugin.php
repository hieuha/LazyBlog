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
require_once __DIR__ . '/GraffitiSession.php';

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

        // Friend "WAS HERE" badge — only renders when the current visitor
        // has a valid magic-link cookie. Visible only in that visitor's
        // own browser (cookie scope). Click clears the session.
        $sessionFid = GraffitiSession::current();
        if ($sessionFid !== null) {
            $sessFriend = (new FriendStore($ctx->storagePath()))->find($sessionFid);
            if ($sessFriend !== null) {
                $sessHandle = (string) ($sessFriend['handle'] ?? 'friend');
                $ctx->nav("{$sessHandle} WAS HERE", '/graffiti/leave', 'header', 'public');
            }
        }

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

        // Magic-link entry: friend's blog presents A's outgoing_token in URL,
        // we verify it matches a friend row, set a signed cookie, then redirect
        // to the target post. Cookie keeps the friend session alive across
        // pageviews so the spray button stays available without the URL token.
        $ctx->get('/graffiti/visit', fn () => $this->magicLinkVisit($friends));

        // Leave the magic-link session: clear cookie + redirect home.
        // Bound to a GET so the navbar badge link works without JS/forms.
        $ctx->get('/graffiti/leave', function (): void {
            GraffitiSession::clear();
            Http::redirect('/');
        });

        // Friend-side cross-blog spray: cookie-authed POST that stores the
        // graffiti directly + fires a webhook back to the sender's blog so
        // their energy ledger debits the cost. No admin auth required;
        // signed cookie IS the auth.
        $ctx->post('/graffiti/cross-spray', fn () => $this->crossSpray($friends, $store, $catalogue));

        // Sender-side webhook: receives "we stored your spray, please debit
        // X energy" callbacks from blogs we have sprayed. Token auth same as
        // /graffiti/receive (incoming_token we issued).
        $ctx->post('/graffiti/notify-debit', fn () => $this->notifyDebit($friends, $ledger));

        // Auto-handshake completion: when the OTHER side accepts our invite,
        // they POST here with the token we already issued them (auth) plus
        // the new reciprocal token they generated for us. Updates our pending
        // row to active in one round-trip — operator only pastes one block.
        $ctx->post('/graffiti/handshake-complete', fn () => $this->handshakeComplete($friends));

        // Symmetric revoke: when the OTHER side removes us as a friend, they
        // POST here with the token we issued them (= their outgoing_token).
        // We hard-delete our matching row so both sides forget at once.
        $ctx->post('/graffiti/revoke-notify', fn () => $this->notifyRevoke($friends));

        // Balance probe: friend's blog asks "how much energy does the visitor
        // sitting in front of you have?" before letting them cross-spray.
        // Token-auth same as the other webhooks. Allows the receiver to
        // enforce a pre-flight check so cross-spray never drives us negative.
        $ctx->post('/graffiti/balance', fn () => $this->reportBalance($friends, $ledger));

        // Post-page overlay: subscribe new core slot rendered inside </article>.
        // When admin is logged in, also emits the spray-can button + modal +
        // data island so the operator can decorate from the post page itself.
        //
        // The asset-prefix system only auto-loads our CSS on `/admin/graffiti/*`
        // (our registered route prefixes). Post pages are outside that. We
        // emit a direct `<link>` here so the overlay layer + spray button get
        // their absolute-positioning rules on every post that needs them.
        $renderer = new OverlayRenderer($store, $friends, $catalogue);
        $ctx->onPostArticleEnd(static function (array $context) use ($renderer, $catalogue, $store, $friends): ?string {
            $slug = (string) ($context['slug'] ?? '');
            if ($slug === '') {
                return null;
            }
            $overlay = $renderer->render($slug);
            $admin = Auth::check();
            $friendId = GraffitiSession::current();
            $hasSpray = $admin || $friendId !== null;

            // Skip the whole slot when no overlay items AND no one can spray —
            // anonymous visitors get zero added markup in the common case.
            if ($overlay === '' && !$hasSpray) {
                return null;
            }

            // Resolve who's about to spray so the modal header can say it out
            // loud. Without this it's easy to forget you're carrying a friend
            // cookie from a magic link visit and accidentally spray as them.
            $identityHandle = '';
            $identityRole   = '';
            if ($admin) {
                $identityRole = 'OWNER';
                $identityHandle = (string) (Config::get('DEFAULT_AUTHOR')
                    ?? Config::get('SITE_TITLE') ?? 'admin');
            } elseif ($friendId !== null) {
                $identityRole = 'VISITOR';
                $sessFriend = $friends->find($friendId);
                $identityHandle = (string) ($sessFriend['handle'] ?? 'friend');
            }

            $cssUrl = Http::pluginAsset('graffiti', 'graffiti.css');
            $jsUrl  = Http::pluginAsset('graffiti', 'graffiti.js');
            $html = '<link rel="stylesheet" href="' . Http::e($cssUrl) . '">';
            $html .= $overlay;
            if ($hasSpray) {
                $html .= self::sprayControlsHtml(
                    $slug, $catalogue, $admin, $friendId, $identityRole, $identityHandle,
                );
            }
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
     * Receive an auto-handshake completion call from a friend who just
     * accepted OUR invite. They authenticate with the incoming_token we
     * issued (which they pasted from our block) and supply the reciprocal
     * token we should use as outgoing_token. Flips our pending row to
     * active in one round-trip — no second paste required from us.
     */
    private function handshakeComplete(FriendStore $friends): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $raw = (string) (file_get_contents('php://input', false, null, 0, 4096) ?: '');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_json']);
            return;
        }
        $token    = (string) ($body['token']             ?? '');
        $reciprocal = (string) ($body['reciprocal_token'] ?? '');
        $handle   = (string) ($body['handle']            ?? '');
        $endpoint = (string) ($body['endpoint']          ?? '');

        if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $reciprocal)) {
            http_response_code(422);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_reciprocal']);
            return;
        }

        $friend = $friends->findByIncomingToken($token);
        if ($friend === null) {
            http_response_code(403);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_token']);
            return;
        }
        if (($friend['state'] ?? '') === 'active' && !empty($friend['outgoing_token'])) {
            // Idempotent: already complete from a previous attempt.
            echo json_encode(['status' => 'accepted', 'note' => 'already_active']);
            return;
        }

        $patch = [
            'outgoing_token' => $reciprocal,
            'state'          => 'active',
            'completed_at'   => time(),
        ];
        if ($handle !== '')   $patch['handle'] = $handle;
        if ($endpoint !== '') $patch['graffiti_endpoint'] = $endpoint;
        $friends->update((string) $friend['id'], $patch);

        echo json_encode(['status' => 'accepted']);
    }

    /**
     * Magic-link visit handler. Friend A clicks "Visit & Spray" on their
     * blog → browser hits us with `?token=<A's outgoing_token to us>&to=/post`.
     * We verify the token matches a friend row, set a signed session cookie,
     * then redirect to a sanitized destination (relative paths only — no
     * open redirect).
     */
    private function magicLinkVisit(FriendStore $friends): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $to    = (string) ($_GET['to'] ?? '/');
        // Sanitize redirect: only allow same-origin relative paths.
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }
        if ($token !== '') {
            $friend = $friends->findByIncomingToken($token);
            if ($friend !== null && ($friend['state'] ?? '') === 'active') {
                GraffitiSession::set((string) $friend['id']);
            }
        }
        Http::redirect($to);
    }

    /**
     * Cookie-authed cross-blog spray. Friend A spray on our blog while
     * carrying the friend session cookie. We validate cookie → friend row,
     * validate payload, store directly to graffiti.json, then fire a
     * one-shot webhook back to A's blog telling them how much energy to
     * debit (price comes from OUR catalogue — receiver sets price).
     */
    private function crossSpray(FriendStore $friends, GraffitiStore $store, StickerCatalogue $catalogue): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $friendId = GraffitiSession::current();
        if ($friendId === null) {
            http_response_code(403);
            echo json_encode(['status' => 'rejected', 'reason' => 'no_session']);
            return;
        }
        $friend = $friends->find($friendId);
        if ($friend === null || ($friend['state'] ?? '') !== 'active') {
            http_response_code(403);
            echo json_encode(['status' => 'rejected', 'reason' => 'friend_inactive']);
            return;
        }

        $slug = trim((string) ($_POST['post_slug'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'sticker');

        if ($slug === '' || (new PostRepository())->bySlug($slug) === null) {
            http_response_code(404);
            echo json_encode(['status' => 'rejected', 'reason' => 'post_not_found']);
            return;
        }

        $payloadInner = ['position' => [
            'x' => max(0, min(1, (float) ($_POST['x'] ?? 0.5))),
            'y' => max(0, min(1, (float) ($_POST['y'] ?? 0.5))),
            'rotation' => max(-180, min(180, (float) ($_POST['rotation'] ?? 0))),
        ]];
        $price = 1;

        if ($type === 'text') {
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > PayloadValidator::TEXT_MAX_CHARS) {
                http_response_code(422);
                echo json_encode(['status' => 'rejected', 'reason' => 'invalid_payload']);
                return;
            }
            $payloadInner['text'] = $text;
            $font  = (string) ($_POST['font']  ?? '');
            $color = (string) ($_POST['color'] ?? '');
            if (in_array($font,  PayloadValidator::TEXT_FONTS,  true)) $payloadInner['font']  = $font;
            if (in_array($color, PayloadValidator::TEXT_COLORS, true)) $payloadInner['color'] = $color;
        } else {
            $stickerId = trim((string) ($_POST['sticker_id'] ?? ''));
            $row = $catalogue->find($stickerId);
            if ($row === null || !(bool) ($row['enabled'] ?? false)) {
                http_response_code(422);
                echo json_encode(['status' => 'rejected', 'reason' => 'sticker_unavailable']);
                return;
            }
            $key = $type === 'spray' ? 'spray_id' : 'sticker_id';
            $payloadInner[$key] = $stickerId;
            $price = max(1, (int) ($row['default_price'] ?? 1));
        }

        // Pre-flight: ask the visitor's home blog for current balance BEFORE
        // we store anything. If their blog is offline or balance is below
        // price, refuse the spray — keeping a "graffiti was painted but
        // sender can't pay" state asymmetric across blogs is exactly the
        // negative-balance trap we're avoiding.
        $balance = self::fetchFriendBalance($friend);
        if ($balance === null) {
            http_response_code(502);
            echo json_encode(['status' => 'rejected', 'reason' => 'balance_unreachable']);
            return;
        }
        if ($balance < $price) {
            http_response_code(402);
            echo json_encode([
                'status'  => 'rejected',
                'reason'  => 'insufficient_energy',
                'balance' => $balance,
                'price'   => $price,
            ]);
            return;
        }

        $id = $store->append([
            'from_friend_id' => $friendId,
            // Snapshot identity at write time so a future revoke doesn't
            // turn this row into "unknown" attribution.
            'from_handle'    => (string) ($friend['handle'] ?? ''),
            'from_blog_url'  => (string) ($friend['blog_url'] ?? ''),
            'post_slug'      => $slug,
            'type'           => $type,
            'payload'        => $payloadInner,
            'nonce'          => 'xs-' . bin2hex(random_bytes(4)),
        ]);

        // Pre-flight passed → debit can still race with concurrent self-spends
        // on the sender, but that's rare and converges. Fire-and-forget; if
        // the friend's blog drops between pre-flight and debit, the spray
        // stands locally (acceptable, the receiver is authoritative).
        // Pass enough context that the sender's ledger row is human-readable
        // ("@us : <sticker> on <post>") instead of just "graffiti:xs:g_xxx".
        self::sendDebitNotice($friend, $price, "graffiti:xs:{$id}", [
            'target_blog' => rtrim((string) Config::get('SITE_URL'), '/'),
            'post_slug'   => $slug,
            'type'        => $type,
            'sticker_id'  => $type === 'text' ? '' : (string) ($payloadInner['sticker_id'] ?? $payloadInner['spray_id'] ?? ''),
            'text'        => $type === 'text' ? mb_substr((string) ($payloadInner['text'] ?? ''), 0, 60) : '',
            'graffiti_id' => $id,
        ]);

        echo json_encode(['status' => 'accepted', 'id' => $id, 'price' => $price]);
    }

    /**
     * Receive a "debit my energy" callback from a blog we just sprayed.
     * Token must match a friend's incoming_token (the secret we issued).
     * Amount + reason are recorded in the local ledger.
     */
    private function notifyDebit(FriendStore $friends, EnergyLedger $ledger): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $raw = (string) (file_get_contents('php://input', false, null, 0, 8192) ?: '');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_json']);
            return;
        }
        $token  = (string) ($body['token']  ?? '');
        $amount = (int)    ($body['amount'] ?? 0);
        $reason = (string) ($body['reason'] ?? 'cross-spray');
        $friend = $friends->findByIncomingToken($token);
        if ($friend === null) {
            http_response_code(403);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_token']);
            return;
        }
        if ($amount < 1 || $amount > 999) {
            http_response_code(422);
            echo json_encode(['status' => 'rejected', 'reason' => 'amount_out_of_range']);
            return;
        }
        // Receiver-authoritative: append the debit regardless of current
        // balance. Owner can clean up the ledger by hand if a buggy friend
        // over-charges; revoke ends future debits immediately. Snapshot the
        // sender's identity + the action context so the ledger row is
        // self-describing (which blog billed us, for what sticker, on which
        // post) without needing to keep the friend row around.
        $details = [
            'friend_handle' => (string) ($friend['handle']   ?? ''),
            'friend_blog'   => (string) ($friend['blog_url'] ?? ''),
            'target_blog'   => (string) ($body['target_blog'] ?? $friend['blog_url'] ?? ''),
            'post_slug'     => (string) ($body['post_slug']   ?? ''),
            'type'          => (string) ($body['type']        ?? ''),
            'sticker_id'    => (string) ($body['sticker_id']  ?? ''),
            'text'          => (string) ($body['text']        ?? ''),
        ];
        $ledger->debit($amount, $reason, $details);
        echo json_encode(['status' => 'accepted']);
    }

    /**
     * Server-to-server webhook back to the sender's blog asking them to
     * debit `$amount` energy. Best-effort: failure is logged but does not
     * roll back the local graffiti we already stored.
     */
    /** @param array<string,mixed> $context */
    private static function sendDebitNotice(array $friend, int $amount, string $reason, array $context = []): void
    {
        $endpoint = rtrim((string) ($friend['blog_url'] ?? ''), '/') . '/graffiti/notify-debit';
        $body = array_merge([
            'token'  => (string) ($friend['outgoing_token'] ?? ''),
            'amount' => $amount,
            'reason' => $reason,
        ], $context);
        HttpSender::postJson($endpoint, $body);
    }

    /**
     * Synchronous balance probe used by the cross-spray pre-flight. Returns
     * the friend's current balance, or null if the remote is unreachable /
     * returns an unparseable / non-success response. Caller treats null as
     * "abort the spray" — we explicitly refuse to authorize a spray we
     * cannot price-check against a live ledger.
     *
     * @param array<string,mixed> $friend
     */
    private static function fetchFriendBalance(array $friend): ?int
    {
        $token = (string) ($friend['outgoing_token'] ?? '');
        $blog  = rtrim((string) ($friend['blog_url'] ?? ''), '/');
        if ($token === '' || $blog === '') {
            return null;
        }
        $res = HttpSender::postJson($blog . '/graffiti/balance', ['token' => $token]);
        if ($res['transport_failed'] || $res['status'] !== 200) {
            return null;
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'accepted') {
            return null;
        }
        return isset($data['balance']) ? (int) $data['balance'] : null;
    }

    /**
     * Receive a balance probe from a friend's blog. Token-auth same as the
     * other webhooks. Returns local ledger balance — used by the friend to
     * decide whether to accept a pending cross-spray from a visitor of ours.
     */
    private function reportBalance(FriendStore $friends, EnergyLedger $ledger): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $raw = (string) (file_get_contents('php://input', false, null, 0, 4096) ?: '');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_json']);
            return;
        }
        $token = (string) ($body['token'] ?? '');
        if ($friends->findByIncomingToken($token) === null) {
            http_response_code(403);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_token']);
            return;
        }
        echo json_encode(['status' => 'accepted', 'balance' => $ledger->balance()]);
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

        // Pagination — reuses POSTS_PER_PAGE so the whole site shares one
        // density dial. Slice happens BEFORE the friend/sticker resolve
        // pass so we only do lookups for visible rows.
        $perPage = max(1, (int) Config::get('POSTS_PER_PAGE', '10'));
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
        $items = array_slice($items, ($page - 1) * $perPage, $perPage);

        // Pre-resolve friend handles + sticker names so the view stays dumb.
        // 'self' is a sentinel id — synthesize the owner attribution from env.
        // Attribution priority: row-level snapshot (from_handle/from_blog_url
        // stamped at append time) > live friend lookup > generic fallback.
        // The snapshot keeps history readable even after the friend is
        // revoked + hard-deleted from friends.json.
        $friendCache = ['self' => self::selfFriendStub()];
        foreach ($items as &$row) {
            $fid = (string) ($row['from_friend_id'] ?? '');
            $friendCache[$fid] ??= $friends->find($fid);
            $live = (array) ($friendCache[$fid] ?? []);
            $snapHandle = (string) ($row['from_handle']   ?? '');
            $snapBlog   = (string) ($row['from_blog_url'] ?? '');
            $row['_friend'] = [
                'handle'   => $snapHandle !== '' ? $snapHandle : (string) ($live['handle']   ?? 'unknown'),
                'blog_url' => $snapBlog   !== '' ? $snapBlog   : (string) ($live['blog_url'] ?? ''),
            ];

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
            'items'       => $items,
            'unseenIds'   => $unseenIds,
            'csrf'        => Csrf::token(),
            'flash'       => self::popFlash(),
            'tabCounts'   => $tabCounts,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
            'pageBaseUrl' => '/admin/graffiti',
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
    private static function sprayControlsHtml(
        string $slug,
        StickerCatalogue $catalogue,
        bool $admin,
        ?string $friendId,
        string $identityRole = '',
        string $identityHandle = '',
    ): string {
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
        // Two modes:
        //   admin (`mode=self`): the operator decorating own post. Submit
        //     goes to the local admin handler that debits own energy.
        //   friend (`mode=friend`): cookie-authed visitor from a magic
        //     link. Submit goes to /graffiti/cross-spray which stores
        //     directly + fires a debit webhook to their blog.
        $mode = $admin ? 'self' : 'friend';
        $endpoint = $admin ? '/admin/graffiti/send/submit' : '/graffiti/cross-spray';
        $ctxJson = (string) json_encode([
            'mode'      => $mode,
            'endpoint'  => $endpoint,
            'csrf'      => $admin ? Csrf::token() : '',
            'friend_id' => $mode === 'self' ? 'self' : ($friendId ?? ''),
            'slug'      => $slug,
            'catalogue' => $items,
        ], JSON_UNESCAPED_SLASHES);

        // graffiti.js itself is loaded by the parent onPostArticleEnd closure
        // (so it ships for all visitors — needed for the per-item dismiss).
        // Here we only emit the admin-specific UI + the JSON context island
        // the script will pick up if present.

        // Identity badge pinned to the modal's bottom-right corner — OWNER
        // (admin) vs VISITOR (magic-link friend session). Helps avoid the
        // "I forgot I had a friend cookie" trap where the operator sprays
        // as the wrong identity. Empty string when neither role applies.
        $identityBadge = '';
        if ($identityRole !== '' && $identityHandle !== '') {
            $roleColor = $identityRole === 'OWNER' ? '#39ff14' : '#ffd700';
            $identityBadge = '<div class="graffiti-modal-identity" '
                . 'style="position:absolute;right:14px;bottom:10px;'
                . 'font-size:11px;letter-spacing:0.08em;opacity:0.85;'
                . 'color:' . $roleColor . ';pointer-events:none;'
                . 'text-shadow:none;">'
                . Http::e($identityRole) . ' &middot; @' . Http::e($identityHandle)
                . '</div>';
        }

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
    <div class="graffiti-modal-card" style="position:relative;">
        <header>
            <h2 id="graffiti-modal-title">// GRAFFITI</h2>
            <button type="button" class="graffiti-modal-close" aria-label="Close">×</button>
        </header>
        <div class="graffiti-modal-stickers" data-grid></div>
        <div class="graffiti-modal-textrow">
            <input type="text" maxlength="140" data-text
                   placeholder="or type up to 140 chars…">
            <!-- Custom dropdowns instead of native <select> so Safari's
                 white popup doesn't leak through. data-value on the root
                 holds the chosen token; graffiti.js reads it directly. -->
            <div class="graffiti-dd" data-font data-value="marker">
                <button type="button" class="graffiti-dd-trigger">
                    <span class="graffiti-dd-label" style="font-family:'Caveat',cursive">Marker</span>
                    <span class="graffiti-dd-caret" aria-hidden="true">▾</span>
                </button>
                <ul class="graffiti-dd-menu" role="listbox" hidden>
                    <li role="option" data-value="marker" style="font-family:'Caveat',cursive">Marker</li>
                    <li role="option" data-value="spray"  style="font-family:'Bangers',cursive">Spray</li>
                    <li role="option" data-value="tag"    style="font-family:'Russo One',sans-serif">Tag</li>
                    <li role="option" data-value="block"  style="font-family:'Bungee Spice',cursive">Block</li>
                </ul>
            </div>
            <div class="graffiti-dd" data-color data-value="green">
                <button type="button" class="graffiti-dd-trigger">
                    <span class="graffiti-dd-label" style="color:#39ff14">Green</span>
                    <span class="graffiti-dd-caret" aria-hidden="true">▾</span>
                </button>
                <ul class="graffiti-dd-menu" role="listbox" hidden>
                    <li role="option" data-value="green"  style="color:#39ff14">Green</li>
                    <li role="option" data-value="white"  style="color:#f5f5f5">White</li>
                    <li role="option" data-value="pink"   style="color:#ff3399">Pink</li>
                    <li role="option" data-value="yellow" style="color:#ffd700">Yellow</li>
                    <li role="option" data-value="orange" style="color:#ff7700">Orange</li>
                    <li role="option" data-value="red"    style="color:#ff3344">Red</li>
                    <li role="option" data-value="blue"   style="color:#00b3ff">Blue</li>
                    <li role="option" data-value="purple" style="color:#a855f7">Purple</li>
                </ul>
            </div>
            <button type="button" data-text-go>DRAW TEXT</button>
        </div>
        {$identityBadge}
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
            $selfStub = self::selfFriendStub();
            $ledger->spend($price, "graffiti:self", [
                'target_blog' => (string) $selfStub['blog_url'],
                'post_slug'   => $postSlug,
                'type'        => $type,
                'sticker_id'  => $type === 'text' ? '' : (string) ($payloadInner['sticker_id'] ?? $payloadInner['spray_id'] ?? ''),
                'text'        => $type === 'text' ? mb_substr((string) ($payloadInner['text'] ?? ''), 0, 60) : '',
            ]);
            $id = $store->append([
                'from_friend_id' => 'self',
                'from_handle'    => (string) $selfStub['handle'],
                'from_blog_url'  => (string) $selfStub['blog_url'],
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
        $ledger->spend($price, "graffiti:pending", [
            'friend_handle' => (string) ($friend['handle']   ?? ''),
            'friend_blog'   => (string) ($friend['blog_url'] ?? ''),
            'target_blog'   => (string) ($friend['blog_url'] ?? ''),
            'post_slug'     => $postSlug,
            'type'          => $type,
            'sticker_id'    => $type === 'text' ? '' : (string) ($payloadInner['sticker_id'] ?? $payloadInner['spray_id'] ?? ''),
            'text'          => $type === 'text' ? mb_substr((string) ($payloadInner['text'] ?? ''), 0, 60) : '',
        ]);
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

        $rows = $ledger->ledger(); // already newest-first, capped 200
        $perPage = max(1, (int) Config::get('POSTS_PER_PAGE', '10'));
        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $ctx->view('admin-energy', [
            'balance'     => $ledger->balance(),
            'ledger'      => $rows,
            'mintPerPost' => EnergyLedger::MINT_PER_POST,
            'tabCounts'   => self::tabCounts($store, $friends, $catalogue, $ledger),
            'page'        => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
            'pageBaseUrl' => '/admin/graffiti/energy',
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

        // Auto-complete the OTHER side: tell their blog the reciprocal token
        // they need to use as outgoing_token. Authenticated with the token
        // they just gave us in the invite (i.e. the one they'll verify as
        // valid friend token). Fire-and-forget — if the call fails, the
        // operator can still fall back to manual reciprocal block paste.
        $remoteOk = self::sendHandshakeComplete(
            endpoint: $invite['endpoint'],
            authToken: $invite['token'],
            reciprocalToken: $incomingToken,
        );

        if ($remoteOk) {
            self::flash('friend added — both sides now active');
        } else {
            // Fallback: show reciprocal block for manual paste if auto-call
            // didn't reach the friend's blog (offline, blocked, old version).
            $ourBlock = InviteCodec::encode([
                'blog_url' => rtrim((string) Config::get('SITE_URL'), '/'),
                'handle'   => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'anon'),
                'endpoint' => rtrim((string) Config::get('SITE_URL'), '/') . '/graffiti/receive',
                'token'    => $incomingToken,
            ]);
            self::flash('accepted invite — friend\'s blog unreachable, copy block below and ask them to paste it');
            self::stashInviteBlock($ourBlock);
        }
        Http::redirect('/admin/graffiti/friends#friend-' . $id);
    }

    /**
     * Server-to-server call telling the inviter's blog the reciprocal token
     * we just generated. Returns true on 2xx, false on any failure (caller
     * falls back to manual block exchange).
     */
    private static function sendHandshakeComplete(string $endpoint, string $authToken, string $reciprocalToken): bool
    {
        // The endpoint stored in the invite is the friend's /graffiti/receive.
        // Their /graffiti/handshake-complete lives on the same blog root.
        $root = (string) preg_replace('#/graffiti/receive$#', '', rtrim($endpoint, '/'));
        if ($root === '') {
            return false;
        }
        $url = $root . '/graffiti/handshake-complete';
        $body = [
            'token'             => $authToken,
            'reciprocal_token'  => $reciprocalToken,
            'handle'            => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'anon'),
            'endpoint'          => rtrim((string) Config::get('SITE_URL'), '/') . '/graffiti/receive',
        ];
        $res = HttpSender::postJson($url, $body);
        return !$res['transport_failed'] && $res['status'] === 200;
    }

    private function revokeFriend(FriendStore $friends, string $id): void
    {
        Csrf::requireValid();
        $friend = $id === '' ? null : $friends->find($id);
        if ($friend === null) {
            self::flash('friend not found');
            Http::redirect('/admin/graffiti/friends');
            return;
        }
        // Capture remote auth + endpoint BEFORE local delete so we can still
        // tell the other side to forget us after our row is gone. Fire-and-
        // forget: if the friend's blog is offline, local delete still stands.
        self::sendRevokeNotice($friend);
        $friends->revoke($id);
        self::flash('friend removed');
        Http::redirect('/admin/graffiti/friends');
    }

    /**
     * Tell the friend's blog to drop their row referencing us. Symmetric to
     * sendDebitNotice: token = our outgoing_token = secret THEY issued, so
     * their findByIncomingToken lookup matches the right row.
     *
     * We also pass `from_blog` so the receiver can sanity-check the claimed
     * sender against the blog_url stored in their friend row — guards against
     * a leaked token being replayed by an attacker who doesn't know which
     * blog originally held it (URLs are public, so this is defense-in-depth,
     * not a primary auth boundary — the token itself is).
     *
     * @param array<string,mixed> $friend
     */
    private static function sendRevokeNotice(array $friend): void
    {
        $token = (string) ($friend['outgoing_token'] ?? '');
        $blog  = rtrim((string) ($friend['blog_url'] ?? ''), '/');
        if ($token === '' || $blog === '') {
            // No reciprocal token yet (pending invite we created but they
            // never accepted) — nothing for the other side to clean up.
            return;
        }
        HttpSender::postJson($blog . '/graffiti/revoke-notify', [
            'token'     => $token,
            'from_blog' => rtrim((string) Config::get('SITE_URL'), '/'),
        ]);
    }

    /**
     * Receive a "I'm unfriending you" webhook from a friend. Two-step auth:
     *   1. Token must match a row in our friends.json (= secret WE issued).
     *   2. Body's `from_blog` must equal that row's stored `blog_url` — i.e.
     *      the caller's claimed identity matches the friend we issued the
     *      token to. Catches a replay where someone holds the token but
     *      forgot (or doesn't know) which blog it was minted for.
     *
     * Hard-delete on success. Idempotent: missing row counts as already-
     * cleaned so retries don't 4xx. Origin mismatch is a real 403 though —
     * we want to surface that as an explicit reject, not silently absorb it.
     */
    private function notifyRevoke(FriendStore $friends): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $raw = (string) (file_get_contents('php://input', false, null, 0, 4096) ?: '');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['status' => 'rejected', 'reason' => 'invalid_json']);
            return;
        }
        $token = (string) ($body['token'] ?? '');
        $claimedBlog = rtrim((string) ($body['from_blog'] ?? ''), '/');

        $friend = $friends->findByIncomingToken($token);
        if ($friend === null) {
            // No row matches → already gone, or token never existed here.
            // Treat as idempotent success (same as legacy behavior).
            echo json_encode(['status' => 'accepted', 'note' => 'no_match']);
            return;
        }

        $storedBlog = rtrim((string) ($friend['blog_url'] ?? ''), '/');
        if ($claimedBlog === '' || strcasecmp($claimedBlog, $storedBlog) !== 0) {
            http_response_code(403);
            echo json_encode(['status' => 'rejected', 'reason' => 'origin_mismatch']);
            return;
        }

        $friends->revoke((string) $friend['id']);
        echo json_encode(['status' => 'accepted']);
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
