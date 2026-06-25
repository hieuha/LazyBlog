<?php

declare(strict_types=1);

// Fast-path liveness probe — answer before composer autoload, dotenv,
// session start, or repo scan. Monitors hitting this every Ns would
// otherwise spawn a session file per probe and churn the filesystem.
// Body carries the release version so uptime tooling and deploy checks
// can verify which build is actually serving traffic without a second
// round trip. VERSION file is read inline because composer autoload is
// not loaded yet at this point.
if ((parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/') === '/healthz') {
    $version = @file_get_contents(__DIR__ . '/../VERSION');
    $version = is_string($version) ? trim($version) : '';
    if ($version === '') {
        $version = 'unknown';
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "ok {$version}\n";
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

App\Config::boot();
date_default_timezone_set(App\Config::get('TIMEZONE', 'UTC'));

// -----------------------------------------------------------------------------
// Global defense-in-depth headers — Caddy sets these in prod via Caddyfile,
// but this catches dev `php -S`, Apache via .htaccess, and any future
// nginx setups too. Cheap to set on every request.
// -----------------------------------------------------------------------------
header_remove('X-Powered-By');                                  // hide PHP version
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    // 'unsafe-inline' is required by the pre-paint theme script in layout.php
    // and the inline scrollspy/back-to-top/copy-button scripts. EasyMDE loads
    // from jsdelivr. No external script CDNs beyond that are allowed.
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
    . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:; "
    . "img-src 'self' data: https:; "
    // Same posture as img-src: own origin + any HTTPS host for direct-link
    // videos (the markdown `![](url.webm)` flow). Without it the default-src
    // fallback blocks every external video element.
    . "media-src 'self' https:; "
    . "connect-src 'self'; "
    . "frame-src https://www.youtube-nocookie.com https://www.youtube.com; "
    . "frame-ancestors 'self'; "
    . "base-uri 'self'; "
    . "form-action 'self';"
);

// Start the session BEFORE any output so layout.php's Auth::check() (used by
// the `[ ADMIN ]` header button, edit-link on posts, etc.) doesn't trigger
// "headers already sent" warnings on public routes.
App\Auth::start();

$seriesManifest = new App\SeriesManifest(__DIR__ . '/../content');
$repo = new App\PostRepository(__DIR__ . '/../content', $seriesManifest);
$renderer = new App\MarkdownRenderer();
$llms = new App\LlmsBuilder($repo, __DIR__ . '/../content');
$feed = new App\FeedBuilder($repo, $renderer, __DIR__ . '/../content');

$home = new App\Controllers\HomeController($repo);
$post = new App\Controllers\PostController($repo, $renderer);
$tag = new App\Controllers\TagController($repo);
$llmsCtl = new App\Controllers\LlmsController($llms);
$feedCtl = new App\Controllers\FeedController($feed);
$admin = new App\Controllers\AdminController($repo);
$archive = new App\Controllers\ArchiveController($repo);
$search = new App\Controllers\SearchController(new App\Searcher($repo));
$upload = new App\Controllers\UploadController(__DIR__ . '/../content');
$series = new App\Controllers\SeriesController($repo, $seriesManifest);
$seriesAssetCtl = new App\Controllers\SeriesAssetController($seriesManifest);
$seriesCoverProcessor = new App\SeriesCoverProcessor($seriesManifest);
$adminSeries = new App\Controllers\AdminSeriesController($repo, $seriesManifest, $seriesCoverProcessor);
$aboutRepo = new App\AboutRepository(__DIR__ . '/../content');
$aboutCtl = new App\Controllers\AboutController($aboutRepo, $renderer, $repo);
$adminAboutCtl = new App\Controllers\AdminAboutController($aboutRepo);

$router = new App\Router();

// Plugins boot BEFORE core route registration. Reserved-path rejection in
// PluginRegistry::canRegister() keeps core paths (`/posts/*`, `/admin/*`,
// `/feed.xml`, etc.) always-on regardless of plugin order. Each enabled
// plugin's register() may add public/admin routes, nav links, and route-
// scoped CSS/JS. Empty PLUGINS env = zero boot cost.
$pluginRegistry = new App\PluginRegistry(
    pluginsDir: __DIR__ . '/../plugins',
    enabledCsv: (string) App\Config::get('PLUGINS', ''),
    contentRoot: __DIR__ . '/../content/plugins',
);
$pluginRegistry->boot($router);
App\Http::setPluginRegistry($pluginRegistry);

$pluginAssetCtl = new App\Controllers\PluginAssetController($pluginRegistry);
$router->get('/plugin-assets/{slug}/{file}', fn (array $p) => $pluginAssetCtl->serve($p));
$router->get('/series-assets/{slug}/{file}', fn (array $p) => $seriesAssetCtl->serve($p));

// Most-specific patterns first.
// Admin (auth-gated inside the controller).
$router->get('/admin/login', fn () => $admin->loginForm());
$router->post('/admin/login', fn () => $admin->loginSubmit());
$router->post('/admin/logout', fn () => $admin->logout());
$router->get('/admin/new', fn () => $admin->newForm());
$router->get('/admin/edit/{slug}', fn (array $p) => $admin->editForm($p));
$router->post('/admin/save', fn () => $admin->save());
$router->post('/admin/delete/{slug}', fn (array $p) => $admin->delete($p));
$router->post('/admin/preview', fn () => $admin->preview());
$router->post('/admin/upload', fn () => $upload->upload());
$router->get('/admin/about', fn () => $adminAboutCtl->editForm());
$router->post('/admin/about/save', fn () => $adminAboutCtl->save());
$router->get('/admin/series', fn () => $adminSeries->index());
$router->get('/admin/series/{slug}', fn (array $p) => $adminSeries->editForm($p));
$router->post('/admin/series/{slug}', fn (array $p) => $adminSeries->save($p));
$router->post('/admin/series/{slug}/preview', fn (array $p) => $adminSeries->preview($p));
$router->post('/admin/series/{slug}/attach', fn (array $p) => $adminSeries->attach($p));
$router->post('/admin/series/{slug}/rename', fn (array $p) => $adminSeries->rename($p));
$router->post('/admin/series/{slug}/delete', fn (array $p) => $adminSeries->delete($p));
$router->get('/admin', fn () => $admin->index());

// Public.
$router->get('/posts/{slug}.md', fn (array $p) => $post->raw($p));
$router->get('/posts/{slug}', fn (array $p) => $post->show($p));
$router->get('/tags/{tag}', fn (array $p) => $tag->show($p));
$router->get('/series', fn () => $series->index());
$router->get('/series/{slug}', fn (array $p) => $series->show($p));
$router->get('/llms.txt', fn () => $llmsCtl->index());
$router->get('/llms-full.txt', fn () => $llmsCtl->full());
$router->get('/feed.xml', fn () => $feedCtl->show());
$router->get('/archive', fn () => $archive->show());
$router->get('/search', fn () => $search->show());
$router->get('/about', fn () => $aboutCtl->show());
$router->get('/', fn () => $home->index());

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
);
