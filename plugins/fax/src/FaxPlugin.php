<?php

declare(strict_types=1);

namespace Plugins\Fax;

use App\Config;
use App\Csrf;
use App\Http;
use App\Plugin;
use App\PluginContext;
use App\PluginManifest;
use App\PostRepository;

require_once __DIR__ . '/FaxSettings.php';
require_once __DIR__ . '/FaxSender.php';

/**
 * Fax — readers highlight a passage on a post and fax it straight to the
 * blog owner's real fax machine via the FaxxMe inbound webhook.
 *
 * Surfaces:
 *   - reader UI      : a "Fax this" button injected on post pages (only once
 *                      a token is configured) that appears next to a text
 *                      selection; posts to the public endpoint below.
 *   - public POST    : /fax/send — proxies to the webhook with the operator's
 *                      secret token (never exposed to the browser).
 *   - admin          : /admin/fax — set token + endpoint, send a test fax.
 *
 * The webhook owns rate limiting (per author + per calling-site IP). A 429
 * from it is treated as "out of faxes" and surfaces a light-hearted message
 * nudging the reader to do literally anything else.
 */
final class FaxPlugin implements Plugin
{
    public function manifest(): PluginManifest
    {
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/../manifest.json'), true);
        return PluginManifest::fromArray($data);
    }

    public function register(PluginContext $ctx): void
    {
        $settings = new FaxSettings($ctx->storagePath());

        $ctx->nav('Fax', '/admin/fax', 'header', 'admin');

        // Public proxy — no CSRF: readers have no admin session. Abuse is
        // bounded by the webhook's own per-IP rate limit, not a local token.
        $ctx->post('/fax/send', fn () => $this->send($settings));

        $ctx->adminGet('/admin/fax', fn () => $this->admin($ctx, $settings));
        $ctx->adminPost('/admin/fax/save', fn () => $this->save($settings));
        $ctx->adminPost('/admin/fax/test', fn () => $this->test($settings));

        // Only wire the reader-facing selection UI once there's a token to
        // send with — an unconfigured plugin adds zero markup to post pages.
        if ($settings->isReady()) {
            $ctx->onPostArticleEnd(fn (array $context): ?string => $this->injectUi($context));
        }
    }

    /**
     * Emit the CSS + JS + a small JSON island carrying the send endpoint and
     * this post's slug (the server re-resolves the title/url from the slug at
     * send time so the reader can't spoof attribution).
     */
    private function injectUi(array $context): ?string
    {
        $slug = (string) ($context['slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        $cssUrl = Http::pluginAsset('fax', 'fax.css');
        $jsUrl  = Http::pluginAsset('fax', 'fax.js');
        $island = (string) json_encode(
            ['endpoint' => '/fax/send', 'slug' => $slug],
            JSON_UNESCAPED_SLASHES,
        );

        return '<link rel="stylesheet" href="' . Http::e($cssUrl) . '">'
            . '<script type="application/json" id="fax-ctx">' . $island . '</script>'
            . '<script src="' . Http::e($jsUrl) . '" defer></script>';
    }

    /**
     * Public send handler. Validates + clamps the reader's input, resolves the
     * post context from the slug server-side, forwards to the webhook and maps
     * the HTTP status onto a friendly JSON `{ok, message}` the browser shows
     * as a toast.
     */
    private function send(FaxSettings $settings): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!$settings->isReady()) {
            http_response_code(503);
            echo self::json(false, "📠 The fax machine isn't plugged in yet — the blog owner hasn't set it up.");
            return;
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            http_response_code(400);
            echo self::json(false, 'Highlight some text first — the fax machine needs something to print.');
            return;
        }

        // Clamp to the webhook's documented maxima so we never get a 400 back
        // for length (body 500, name 40, post 120, url 200).
        $body = mb_substr($body, 0, 500);
        $name = trim((string) ($_POST['name'] ?? ''));
        $name = $name === '' ? 'A reader' : mb_substr($name, 0, 40);

        [$post, $url] = $this->resolvePost(trim((string) ($_POST['slug'] ?? '')));

        $res = (new FaxSender())->send($settings->endpoint(), $settings->apiToken(), [
            'body' => $body,
            'name' => $name,
            'post' => $post,
            'url'  => $url,
        ]);

        [$code, $ok, $message] = $this->interpret($res);
        http_response_code($code);
        echo self::json($ok, $message);
    }

    /**
     * Map a FaxSender result onto `[http_status, ok, reader_message]`. The 429
     * branch is the "out of faxes" moment — the webhook is telling us the
     * sender/IP has hit its window, so we lean into it with a funny nudge.
     *
     * @param array{status:int,body:string,error:?string,transport_failed:bool} $res
     * @return array{0:int,1:bool,2:string}
     */
    private function interpret(array $res): array
    {
        if ($res['transport_failed']) {
            return [502, false, '📠 The fax machine coughed, jammed, and ate the paper. Give it a moment and try again.'];
        }
        return match ($res['status']) {
            200     => [200, true, self::sentMessage()],
            429     => [429, false, self::outOfFaxMessage()],
            400     => [400, false, 'The fax machine refused that one — too long or too empty to print.'],
            401     => $this->tokenRejected(),
            default => [502, false, '📠 The fax machine made a concerning noise and gave up. Try again later.'],
        };
    }

    /** @return array{0:int,1:bool,2:string} */
    private function tokenRejected(): array
    {
        // Don't leak config problems to visitors; log for the operator instead.
        error_log('[plugin:fax] webhook rejected the bearer token (401) — check /admin/fax');
        return [502, false, '📠 The fax machine hung up on us. (The owner needs to check its settings.)'];
    }

    /**
     * Resolve `[postTitle, canonicalUrl]` from a slug. Both are optional on the
     * webhook, so an unknown slug just sends blanks rather than failing.
     *
     * @return array{0:string,1:string}
     */
    private function resolvePost(string $slug): array
    {
        if ($slug === '') {
            return ['', ''];
        }
        $post = (new PostRepository())->bySlug($slug);
        if ($post === null) {
            return ['', ''];
        }
        $title = mb_substr($post->title, 0, 120);
        $url   = mb_substr(rtrim((string) Config::get('SITE_URL'), '/') . '/posts/' . $post->slug, 0, 200);
        return [$title, $url];
    }

    private function admin(PluginContext $ctx, FaxSettings $settings): void
    {
        $ctx->view('admin', [
            'title'    => 'Fax // Admin',
            'ready'    => $settings->isReady(),
            'token'    => $settings->apiToken(),
            'endpoint' => $settings->endpoint(),
            'csrf'     => Csrf::token(),
            'flash'    => self::popFlash(),
        ]);
    }

    private function save(FaxSettings $settings): void
    {
        Csrf::requireValid();
        $settings->save(
            (string) ($_POST['api_token'] ?? ''),
            (string) ($_POST['endpoint'] ?? ''),
        );
        self::flash($settings->isReady()
            ? 'saved — the fax button is now live on your posts'
            : 'saved — add a token to switch the fax button on');
        Http::redirect('/admin/fax');
    }

    /**
     * Admin "send a test fax" — hits the real webhook with a canned message so
     * the operator can confirm the token works without highlighting text on a
     * live post. Uses the site title as attribution.
     */
    private function test(FaxSettings $settings): void
    {
        Csrf::requireValid();
        if (!$settings->isReady()) {
            self::flash('add a token first, then test');
            Http::redirect('/admin/fax');
            return;
        }

        $siteTitle = (string) (Config::get('SITE_TITLE') ?? 'LazyBlog');
        $res = (new FaxSender())->send($settings->endpoint(), $settings->apiToken(), [
            'body' => 'Test fax from the LazyBlog admin panel — if you can read this, the wiring works. 📠',
            'name' => (string) (Config::get('DEFAULT_AUTHOR') ?? $siteTitle),
            'post' => mb_substr($siteTitle, 0, 120),
            'url'  => mb_substr(rtrim((string) Config::get('SITE_URL'), '/'), 0, 200),
        ]);

        if ($res['transport_failed']) {
            self::flash('test failed: could not reach the webhook (' . ($res['error'] ?? 'transport error') . ')');
        } elseif ($res['status'] === 200) {
            self::flash('test fax sent — check your machine 📠');
        } elseif ($res['status'] === 401) {
            self::flash('test rejected: the webhook did not accept this token (401)');
        } elseif ($res['status'] === 429) {
            self::flash('test rate-limited by the webhook (429) — wait a few minutes and retry');
        } else {
            self::flash('test failed: webhook returned HTTP ' . $res['status']);
        }
        Http::redirect('/admin/fax');
    }

    /** A cheerful confirmation, varied so repeat senders get a little delight. */
    private static function sentMessage(): string
    {
        $lines = [
            '📠 Fax sent! Somewhere a machine is whirring your words into existence.',
            '📠 Off it goes! Your highlight is now warm ink on real paper.',
            '📠 Transmitted! The blog owner will find your note by the fax machine.',
        ];
        return $lines[array_rand($lines)];
    }

    /** The "out of paper / out of ink" nudge shown when the webhook returns 429. */
    private static function outOfFaxMessage(): string
    {
        $lines = [
            "📠💤 Whoa there, speed-dialer. The machine is out of paper and out of patience. Maybe just... enjoy the post? Touch some grass? Fax yourself a reminder to relax.",
            "📠🔥 Out of ink! The cartridge printed enough hot takes for now and needs a lie-down. Give it a few minutes — or, radical idea, leave a comment like it's 2024.",
            "📠🚫 Out of paper AND ink — a double whammy. The machine has lain down for a nap. It suggests you go outside, pet a dog, or reread the post more slowly. It'll be back shortly.",
        ];
        return $lines[array_rand($lines)];
    }

    /** @return string */
    private static function json(bool $ok, string $message): string
    {
        return (string) json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_SLASHES);
    }

    private static function flash(string $msg): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['fax_flash'] = $msg;
        }
    }

    private static function popFlash(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $msg = $_SESSION['fax_flash'] ?? null;
        unset($_SESSION['fax_flash']);
        return is_string($msg) ? $msg : null;
    }
}
