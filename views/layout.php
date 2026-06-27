<?php
/** @var string $title */
/** @var string $body */

use App\Config;
use App\Http;
use App\PostRepository;
use App\Post;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isHome = $path === '/';

$siteTitle = (string) Config::get('SITE_TITLE');
$siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
$siteDesc = (string) Config::get('SITE_DESCRIPTION', '');
$siteAuthor = (string) Config::get('DEFAULT_AUTHOR', '');
$browserTitle = $isHome || $title === $siteTitle
    ? $siteTitle
    : ($title . ' — ' . $siteTitle);

// Include the (sanitized) query string in the canonical URL so paginated pages
// are their own canonicals; otherwise SEs treat ?page=2 as duplicate of /.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$canonicalUrl = $siteUrl . $path . ($qs !== '' ? '?' . $qs : '');

// Pull $post from the render data when the post view set it.
$isPost = isset($post) && $post instanceof Post;
// `lockedView` is true on the password-unlock form for a protected post.
// Body-derived meta fallbacks (og:image via firstBodyImage, og:description
// via body excerpt) MUST stay off in that state — otherwise the social
// preview leaks the very content the password gates. Summary (typed by
// the operator) is still allowed because it's intentional public framing.
$lockedView = $isPost && $post->isProtected() && !\App\Auth::isPostUnlocked($post->slug);
$isTag = isset($tag) && is_string($tag) && $tag !== '';
// Series detail view sets $seriesSlug + $description + $coverUrl; surface
// those for SEO meta so social previews show the series card art and blurb.
$isSeries = isset($seriesSlug) && is_string($seriesSlug) && $seriesSlug !== ''
    && isset($posts) && is_array($posts);

// Page-specific description: post summary > series description > tag hint > site description.
$pageDesc = $isPost && $post->summary !== null && $post->summary !== ''
    ? $post->summary
    : ($isSeries && isset($description) && is_string($description) && $description !== ''
        ? $description
        : ($isTag ? "Posts tagged #{$tag} on {$siteTitle}." : $siteDesc));

// og:type — `article` for posts, `website` otherwise.
$ogType = $isPost ? 'article' : 'website';

// og:image — three-tier fallback:
//   1. Per-post `image:` frontmatter
//   2. First `![alt](url)` found in the body (auto-detect — best UX)
//   3. Site-wide SITE_OG_IMAGE env
// Telegram, Facebook, Slack, Twitter, Discord all need an absolute URL —
// prepend SITE_URL when the value starts with `/`. When all three are
// empty: skip the tag (platforms fall back to title-only card).
$ogImageRaw = '';
if ($isPost) {
    // Locked view: never scan body for an image fallback. Only the
    // explicitly typed `image:` frontmatter (operator-controlled) and
    // SITE_OG_IMAGE (site-wide chrome) remain in the cascade.
    if ($lockedView) {
        $ogImageRaw = !empty($post->image) ? $post->image : '';
    } else {
        $ogImageRaw = !empty($post->image) ? $post->image : ($post->firstBodyImage() ?? '');
    }
}
// Series detail page → use its dithered cover.webp as og:image when one
// exists. The /series-assets/{slug}/cover.webp route serves it with the
// long max-age the social crawlers like to cache against.
if ($ogImageRaw === '' && $isSeries && isset($coverUrl) && is_string($coverUrl) && $coverUrl !== '') {
    $ogImageRaw = $coverUrl;
}
if ($ogImageRaw === '') {
    $ogImageRaw = (string) Config::get('SITE_OG_IMAGE', '');
}
$ogImage = '';
if ($ogImageRaw !== '') {
    $ogImage = str_starts_with($ogImageRaw, 'http')
        ? $ogImageRaw
        : rtrim($siteUrl, '/') . '/' . ltrim($ogImageRaw, '/');
}
// Upgrade twitter:card when an image is available — `summary_large_image`
// gets the big-thumbnail layout instead of the compact summary card.
$twitterCard = $ogImage !== '' ? 'summary_large_image' : 'summary';

// Footer metadata. Index cache makes this cheap.
$footerRepo = new PostRepository();
// Hide the [ SERIES ] header link when no published post belongs to any
// series — keeps the nav focused for blogs that don't use the feature.
$hasSeries = $footerRepo->allSeries() !== [];
// Same idea for the [ ABOUT ] link — only show when the page exists, so
// fresh installs don't expose a broken-link nav.
$hasAbout = (new \App\AboutRepository(__DIR__ . '/../content'))->exists();

// JSON-LD structured data — BlogPosting for posts, Blog for home.
$jsonLd = null;
if ($isPost) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post->title,
        'description' => $pageDesc,
        'datePublished' => $post->date,
        'dateModified' => $post->date,
        'url' => $canonicalUrl,
        'mainEntityOfPage' => $canonicalUrl,
        'inLanguage' => 'vi',
        'keywords' => implode(', ', $post->tags),
        'author' => [
            '@type' => 'Person',
            'name' => $post->author ?? $siteAuthor ?: $siteTitle,
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $siteTitle,
            'url' => $siteUrl . '/',
        ],
    ];
} elseif ($isHome) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => $siteTitle,
        'description' => $pageDesc,
        'url' => $siteUrl . '/',
        'inLanguage' => 'vi',
        'author' => [
            '@type' => 'Person',
            'name' => $siteAuthor ?: $siteTitle,
        ],
    ];
}

// Site default theme — rendered server-side on <html data-theme>, so
// no-JS users honour the configured choice. Inline bootstrap below only
// overrides when localStorage holds a valid user pick.
//
// THEME_BG mirrors each theme palette's --bg token from base.css. Used
// to drive the <meta name="theme-color"> tint (Safari iOS URL bar,
// Chrome Android address bar, PWA splash) so the browser chrome blends
// into the active theme's background instead of reading as a foreign
// strip above the page. Single source of truth — JSON-encoded once into
// window.LB_THEME_BG so the inline bootstrap + site.js can read it
// without duplicating the table.
$THEME_BG = [
    'amber'     => '#100a04',
    'green'     => '#0a0e0a',
    'crypt'     => '#0a0303',
    'brutalist' => '#08090c',
    'p7'        => '#08081a',
    'p11'       => '#040810',
];
$defaultTheme = strtolower((string) Config::get('SITE_DEFAULT_THEME', 'amber'));
if (!isset($THEME_BG[$defaultTheme])) {
    $defaultTheme = 'amber';
}
$defaultThemeColor = $THEME_BG[$defaultTheme];

// Film grain / dust overlay toggle. Accepts "true"/"false"/"on"/"off"/1/0.
// Default ON — the texture is part of the CRT aesthetic — but admins
// who find it too busy (or care about every last paint cost) can disable
// via SITE_NOISE="false". CSS reads the data-noise attr below.
$noiseEnabled = filter_var(
    (string) Config::get('SITE_NOISE', 'true'),
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE,
);
if ($noiseEnabled === null) {
    $noiseEnabled = true;
}

// Minimal CRT-themed inline SVG favicon — avoids /favicon.ico 404 noise.
$favicon = 'data:image/svg+xml,'
    . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
        . '<rect width="32" height="32" fill="#0a0e0a"/>'
        . '<text x="4" y="24" font-family="monospace" font-size="22" fill="#39ff14">&gt;_</text>'
        . '</svg>');
?>
<!DOCTYPE html>
<html lang="vi" data-theme="<?= $defaultTheme ?>" data-noise="<?= $noiseEnabled ? 'on' : 'off' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= Http::e($defaultThemeColor) ?>">
    <meta name="color-scheme" content="dark">

    <title><?= Http::e($browserTitle) ?></title>
    <meta name="description" content="<?= Http::e($pageDesc) ?>">
    <?php if ($siteAuthor !== ''): ?>
        <meta name="author" content="<?= Http::e($isPost && $post->author ? $post->author : $siteAuthor) ?>">
    <?php endif; ?>
    <meta name="generator" content="LazyBlog <?= Http::e(\App\Version::get()) ?>">
    <meta name="robots" content="<?= str_starts_with($path, '/admin') ? 'noindex, nofollow' : 'index, follow, max-image-preview:large' ?>">

    <link rel="canonical" href="<?= Http::e($canonicalUrl) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= Http::e($title) ?>">
    <meta property="og:description" content="<?= Http::e($pageDesc) ?>">
    <meta property="og:url" content="<?= Http::e($canonicalUrl) ?>">
    <meta property="og:type" content="<?= $ogType ?>">
    <meta property="og:site_name" content="<?= Http::e($siteTitle) ?>">
    <meta property="og:locale" content="vi_VN">
    <?php if ($ogImage !== ''): ?>
        <meta property="og:image" content="<?= Http::e($ogImage) ?>">
        <meta property="og:image:alt" content="<?= Http::e($title) ?>">
    <?php endif; ?>
    <?php if ($isPost): ?>
        <meta property="article:published_time" content="<?= Http::e($post->date) ?>">
        <?php if ($post->author): ?>
            <meta property="article:author" content="<?= Http::e($post->author) ?>">
        <?php endif; ?>
        <?php foreach ($post->tags as $t): ?>
            <meta property="article:tag" content="<?= Http::e($t) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="<?= $twitterCard ?>">
    <meta name="twitter:title" content="<?= Http::e($title) ?>">
    <meta name="twitter:description" content="<?= Http::e($pageDesc) ?>">
    <?php if ($ogImage !== ''): ?>
        <meta name="twitter:image" content="<?= Http::e($ogImage) ?>">
    <?php endif; ?>
    <?php
        $twitterHandle = (string) Config::get('SITE_TWITTER_HANDLE', '');
        if ($twitterHandle !== ''):
    ?>
        <meta name="twitter:site" content="<?= Http::e($twitterHandle) ?>">
    <?php endif; ?>

    <!-- Alternates: machine-readable / AI / RSS -->
    <?php if ($isPost): ?>
        <link rel="alternate" type="text/markdown" href="<?= Http::e($siteUrl . '/posts/' . $post->slug . '.md') ?>">
    <?php endif; ?>
    <link rel="alternate" type="text/plain" title="llms.txt index" href="/llms.txt">
    <link rel="alternate" type="application/rss+xml" title="<?= Http::e($siteTitle) ?> RSS" href="/feed.xml">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?= $favicon ?>">

    <?php if ($jsonLd !== null): ?>
        <script type="application/ld+json"><?= json_encode(
            $jsonLd,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        ) ?></script>
    <?php endif; ?>

    <script>
    // Theme bootstrap — server already rendered the configured default on
    // <html data-theme> and the matching theme-color meta. Only override
    // here when the visitor has picked a different valid value previously.
    // Runs sync in <head> so no FOUC and no flash of wrong browser-chrome
    // tint on Safari iOS / Chrome Android.
    window.LB_THEME_BG = <?= json_encode($THEME_BG, JSON_UNESCAPED_SLASHES) ?>;
    (function () {
        try {
            var t = localStorage.getItem('theme');
            if (t && Object.prototype.hasOwnProperty.call(window.LB_THEME_BG, t)) {
                document.documentElement.setAttribute('data-theme', t);
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) meta.setAttribute('content', window.LB_THEME_BG[t]);
            }
        } catch (e) {}
    })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Play:wght@400;700&family=Share+Tech+Mono&family=VT323&display=swap" rel="stylesheet">

    <!-- Font Awesome 4 — used by the .post-lock badge across listings
         (fa-lock / fa-unlock-alt) and by the EasyMDE toolbar in the
         admin editor. Loaded once here so admin views don't duplicate
         the request and so cache hits carry across public ↔ admin. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css">

    <?php
    // Stylesheets split by concern, each with its own ?v= cache-bust.
    // base/effects/components/post are universal (touched by header,
    // footer, post-page-title used on /about, post-body code-block HUD
    // for any markdown body). pages.css + about.css are route-scoped —
    // see "Frontend assets" rule in .claude/rules/development-rules.md.
    // Load order matters: base defines tokens, the rest consume them.
    foreach (['assets/base.css', 'assets/effects.css', 'assets/components.css',
              'assets/post.css'] as $css):
    ?>
        <link rel="stylesheet" href="<?= Http::e(Http::asset($css)) ?>">
    <?php endforeach; ?>
    <?php
    // pages.css holds standalone-page styles only (.archive-*, .search-*,
    // .ascii-404, .series-page used on both the /series index AND each
    // /series/{slug} detail page). Skip it everywhere else so home /
    // post / tag / about don't pay for unused rules.
    $needsPages = $path === '/archive'
        || $path === '/search'
        || str_starts_with($path, '/series')
        || http_response_code() === 404;
    if ($needsPages):
    ?>
        <link rel="stylesheet" href="<?= Http::e(Http::asset('assets/pages.css')) ?>">
    <?php endif; ?>
    <?php if ($path === '/about'): ?>
        <link rel="stylesheet" href="<?= Http::e(Http::asset('assets/about.css')) ?>">
    <?php endif; ?>
    <?php if (str_starts_with($path, '/admin')): ?>
        <link rel="stylesheet" href="<?= Http::e(Http::asset('assets/admin.css')) ?>">
        <?php if (App\Auth::check()): ?>
            <meta name="csrf-token" content="<?= Http::e(App\Csrf::token()) ?>">
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Plugin-contributed CSS, route-scoped: only loads when the current
    // request path matches a prefix registered by a plugin. Empty when
    // no plugin matches or PLUGINS env is empty.
    $plugins = Http::plugins();
    $pluginAssets = $plugins !== null
        ? $plugins->assets()->forPath($path)
        : ['css' => [], 'js' => []];
    foreach ($pluginAssets['css'] as $href) {
        if (preg_match('#^/plugin-assets/([^/]+)/(.+)$#', $href, $m)) {
            $href = Http::pluginAsset($m[1], $m[2]);
        }
        echo '<link rel="stylesheet" href="' . Http::e($href) . "\">\n";
    }
    ?>
</head>
<body class="<?php
    $bodyClasses = [];
    if ($isHome) $bodyClasses[] = 'is-home';
    if (str_starts_with($path, '/admin')) $bodyClasses[] = 'is-admin';
    $isPost = str_starts_with($path, '/posts/');
    if ($isPost) $bodyClasses[] = 'is-post';
    echo implode(' ', $bodyClasses);
?>">

<?php if ($isPost): ?>
<div class="read-progress" aria-hidden="true">
    <div class="read-progress-fill"></div>
</div>
<?php endif; ?>

<header>
    <?php $callsign = (string) Config::get('CALLSIGN', ''); if ($callsign !== ''): ?>
        <div class="callsign"><?= Http::e($callsign) ?></div>
    <?php endif; ?>
    <?php /* Home is the only page where the site title IS the primary
         heading; everywhere else (posts, /about, archive, etc.) the
         content owns the H1 and the brand is decorative chrome. */ ?>
    <?php if ($isHome): ?>
        <h1><a href="/" class="brand-link"><?= Http::e($siteTitle) ?></a></h1>
    <?php else: ?>
        <p class="site-brand"><a href="/" class="brand-link"><?= Http::e($siteTitle) ?></a></p>
    <?php endif; ?>
    <?php if ($siteDesc !== ''): ?>
        <div class="subtitle">// <?= Http::e($siteDesc) ?></div>
    <?php else: ?>
        <div class="subtitle">// PHOSPHOR TERMINAL // ASIA/SAIGON</div>
    <?php endif; ?>
    <div class="header-actions">
        <?php /* Mobile drawer toggle. The button is hidden on desktop via CSS;
             nav list shows inline. On mobile, the button reveals the list as
             an overlay panel (mirrors .theme-picker pattern — same JS shape,
             same dropdown styling). Theme picker stays OUTSIDE the drawer —
             it owns its own dropdown and operators want 1-tap theme swap
             without expanding a menu. */ ?>
        <div class="header-nav-drawer" id="header-nav-drawer">
            <button class="header-btn header-nav-toggle" type="button"
                    aria-haspopup="menu" aria-expanded="false"
                    aria-controls="header-nav-list" aria-label="Toggle navigation menu">
                [ ≡ MENU ]
            </button>
            <div class="header-nav-list" id="header-nav-list" role="menu">
                <a class="header-btn" href="/" role="menuitem" aria-label="Back to home"<?= $isHome ? ' aria-current="page"' : '' ?>>[ HOME ]</a>
                <?php if ($hasSeries): ?>
                    <a class="header-btn" href="/series" role="menuitem" aria-label="Series"<?= str_starts_with($path, '/series') ? ' aria-current="page"' : '' ?>>[ SERIES ]</a>
                <?php endif; ?>
                <a class="header-btn" href="/search" role="menuitem" aria-label="Search"<?= $path === '/search' ? ' aria-current="page"' : '' ?>>[ SEARCH ]</a>
                <a class="header-btn" href="/archive" role="menuitem" aria-label="Archive"<?= $path === '/archive' ? ' aria-current="page"' : '' ?>>[ ARCHIVE ]</a>
                <?php if ($hasAbout): ?>
                    <a class="header-btn" href="/about" role="menuitem" aria-label="About"<?= $path === '/about' ? ' aria-current="page"' : '' ?>>[ ABOUT ]</a>
                <?php endif; ?>
                <?php
                // Plugin-contributed header nav. Plugins call $ctx->nav($label, $href, $placement, $auth)
                // during register(); registry exposes the merged list here. Admin-only
                // items are filtered when there is no admin session so anonymous
                // visitors never see admin-quick-access links.
                if ($plugins !== null):
                    $isAdmin = App\Auth::check();
                    foreach ($plugins->nav()->header() as $navItem):
                        if (($navItem['auth'] ?? 'public') === 'admin' && !$isAdmin) {
                            continue;
                        }
                        $navCurrent = $path === $navItem['href']
                            || str_starts_with($path, rtrim($navItem['href'], '/') . '/');
                        ?>
                        <a class="header-btn"
                           href="<?= Http::e($navItem['href']) ?>"
                           role="menuitem"
                           aria-label="<?= Http::e($navItem['label']) ?>"
                           <?= $navCurrent ? 'aria-current="page"' : '' ?>>[ <?= Http::e(strtoupper($navItem['label'])) ?> ]</a>
                        <?php
                    endforeach;
                endif;
                ?>
                <?php if (App\Auth::check()): ?>
                    <a class="header-btn" href="/admin" role="menuitem" aria-label="Admin"<?= str_starts_with($path, '/admin') ? ' aria-current="page"' : '' ?>>[ ADMIN ]</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="theme-picker" id="theme-picker">
            <button class="header-btn theme-picker-toggle" type="button"
                    aria-haspopup="menu" aria-expanded="false" aria-label="Switch theme">
                <span data-theme-label>[ THEME ]</span>
            </button>
            <div class="theme-picker-menu" role="menu" hidden>
                <button type="button" role="menuitem" data-theme-set="amber">[ AMBER ]</button>
                <button type="button" role="menuitem" data-theme-set="green">[ GREEN ]</button>
                <button type="button" role="menuitem" data-theme-set="crypt">[ CRYPT ]</button>
                <button type="button" role="menuitem" data-theme-set="brutalist">[ BRUTALIST ]</button>
                <button type="button" role="menuitem" data-theme-set="p7">[ P7 ]</button>
                <button type="button" role="menuitem" data-theme-set="p11">[ P11 ]</button>
            </div>
        </div>
    </div>
</header>

<main>
    <?= $body ?>
</main>

<footer>
    <?php
    // Plugin-contributed footer nav, only when at least one plugin opts in.
    // Admin-only items filtered same as header to keep the surface consistent.
    $pluginFooter = $plugins !== null ? $plugins->nav()->footer() : [];
    if ($pluginFooter !== []):
        $isAdminFooter = App\Auth::check();
        $pluginFooter = array_values(array_filter(
            $pluginFooter,
            static fn (array $i): bool => ($i['auth'] ?? 'public') !== 'admin' || $isAdminFooter,
        ));
    endif;
    if ($pluginFooter !== []):
    ?>
        <div class="footer-block">
            <div class="footer-label">§ EXTENSIONS</div>
            <div class="footer-tags">
                <?php foreach ($pluginFooter as $navItem): ?>
                    <a class="tag-chip" href="<?= Http::e($navItem['href']) ?>"><?= Http::e($navItem['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php
        // Footer sign-off — configurable via FOOTER_SIGNOFF env. Supports {year}
        // token for auto-update. Empty value hides the line entirely.
        $footerSignoff = (string) Config::get('FOOTER_SIGNOFF', '');
        if ($footerSignoff !== '') {
            $footerSignoff = str_replace('{year}', date('Y'), $footerSignoff);
        }
        // Project source link — defaults to upstream LazyBlog repo for
        // branding/sharing. Override SITE_GITHUB_URL to point at a fork, or
        // set it to empty to hide the line.
        $githubUrl = (string) Config::get('SITE_GITHUB_URL', 'https://github.com/hieuha/LazyBlog');
    ?>
    <?php if ($footerSignoff !== ''): ?>
        <div class="footer-copyright"><?= Http::e($footerSignoff) ?></div>
    <?php endif; ?>
    <?php if ($githubUrl !== ''): ?>
        <div class="footer-source">
            <a class="footer-source-link"
               href="<?= Http::e($githubUrl) ?>"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="LazyBlog source code on GitHub">
                [ POWERED BY LAZYBLOG ON GITHUB ↗ ]
            </a>
        </div>
    <?php endif; ?>
    <div class="footer-version">v<?= Http::e(\App\Version::get()) ?></div>
</footer>

<button id="back-to-top" class="back-to-top" aria-label="Back to top" title="Back to top">↑</button>

<script defer src="<?= Http::e(Http::asset('assets/site.js')) ?>"></script>
<?php if ($isPost): ?>
    <?php
    // Prism syntax highlighting for fenced code blocks. Loaded only on
    // post pages so other routes don't pay for the JS. Autoloader fetches
    // each language grammar (php, js, python, ...) on demand from the same
    // CDN — keeps the initial payload tiny for posts with no code.
    // Theme tokens (.token.*) are defined inline in post.css using the
    // active phosphor palette CSS vars, so highlight colors swap with theme.
    ?>
    <script defer src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-core.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
    <script defer>
        // Point the autoloader at the same CDN we loaded core from. Runs
        // before post.js so by the time copy buttons mount the tokens
        // are already in place.
        document.addEventListener('DOMContentLoaded', function () {
            if (window.Prism && Prism.plugins && Prism.plugins.autoloader) {
                Prism.plugins.autoloader.languages_path =
                    'https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/';
            }
        });
    </script>
    <script defer src="<?= Http::e(Http::asset('assets/post.js')) ?>"></script>
<?php endif; ?>
<?php
// Plugin JS, route-scoped (computed alongside plugin CSS at top of <head>).
foreach ($pluginAssets['js'] as $href) {
    if (preg_match('#^/plugin-assets/([^/]+)/(.+)$#', $href, $m)) {
        $href = Http::pluginAsset($m[1], $m[2]);
    }
    echo '<script defer src="' . Http::e($href) . "\"></script>\n";
}
?>

</body>
</html>
