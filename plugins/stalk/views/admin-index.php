<?php

declare(strict_types=1);

use App\Http;

/**
 * @var list<array<string,mixed>> $friends
 * @var array{refresh_interval:string,max_friends:int,max_items_per_friend:int,last_refresh_at:int} $config
 * @var list<string> $allowed_intervals
 * @var int $max_friends_ceiling
 * @var int $max_items_ceiling
 * @var ?array{type:string,msg:string} $flash
 * @var string $csrf
 */

$lastRefresh      = (int) ($config['last_refresh_at'] ?? 0);
// date() honors core's TIMEZONE; `T` token prints the configured TZ abbrev.
$lastRefreshHuman = $lastRefresh > 0 ? date('Y-m-d H:i T', $lastRefresh) : 'never';
?>
<section>
    <div class="admin-header-row">
        <h2>&gt; STALK // admin</h2>
        <div class="admin-actions">
            <a class="admin-btn" href="/admin">[ ← BACK ]</a>
            <a class="admin-btn" href="/stalk" target="_blank">[ VIEW STALK ]</a>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <p class="admin-flash <?= $flash['type'] === 'err' ? 'admin-flash-error' : '' ?>">
            // <?= Http::e((string) $flash['msg']) ?>
        </p>
    <?php endif; ?>

    <div class="admin-series-edit-grid stalk-admin-grid">
        <div class="admin-series-edit-left">

            <h3 class="stalk-admin-section-heading">// ADD FRIEND</h3>
            <form method="post" action="/admin/stalk/add" class="admin-series-edit-form stalk-add-form">
                <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">

                <div class="stalk-add-row">
                    <div class="stalk-add-field stalk-add-field-url">
                        <label class="admin-label" for="stalk-blog-url">Blog URL</label>
                        <input type="url" name="blog_url" id="stalk-blog-url"
                               class="admin-input"
                               placeholder="https://friend.example"
                               required autocomplete="off">
                    </div>
                    <div class="stalk-add-field stalk-add-field-handle">
                        <label class="admin-label" for="stalk-handle">Handle <small>(opt.)</small></label>
                        <input type="text" name="handle" id="stalk-handle"
                               class="admin-input"
                               maxlength="60" autocomplete="off">
                    </div>
                    <div class="stalk-add-field stalk-add-field-cap">
                        <label class="admin-label" for="stalk-max-items">Max posts</label>
                        <input type="number" name="max_items" id="stalk-max-items"
                               class="admin-input"
                               min="1" max="<?= Http::e((string) $max_items_ceiling) ?>"
                               value="<?= Http::e((string) $config['max_items_per_friend']) ?>">
                    </div>
                    <div class="stalk-add-field stalk-add-field-submit">
                        <button type="submit" class="admin-btn admin-btn-primary">[ + ADD FRIEND ]</button>
                    </div>
                </div>
            </form>

            <hr class="stalk-admin-hr">

            <h3 class="stalk-admin-section-heading">// FRIENDS (<?= Http::e((string) count($friends)) ?> / <?= Http::e((string) $config['max_friends']) ?>)</h3>
            <p class="stalk-admin-meta">Last batch refresh: <code><?= Http::e($lastRefreshHuman) ?></code></p>

            <?php if ($friends === []): ?>
                <p style="color: var(--text-dim);">// none yet — add one in the form above.</p>
            <?php else: ?>
                <ul class="stalk-admin-friend-list">
                    <?php foreach ($friends as $i => $f):
                        $id      = (string) ($f['id']    ?? '');
                        $hdl     = (string) ($f['handle'] ?? '');
                        $url     = rtrim((string) ($f['blog_url'] ?? ''), '/');
                        $host    = $url !== '' ? (parse_url($url, PHP_URL_HOST) ?: $url) : '';
                        $last    = (int)    ($f['last_fetched_at'] ?? 0);
                        $lastHum = $last > 0 ? date('Y-m-d H:i', $last) : 'never';
                        $status  = (string) ($f['last_status'] ?? '');
                        $err     = (string) ($f['last_error'] ?? '');
                        $perCap  = $f['max_items'] ?? null;
                        $capLbl  = (string) (is_int($perCap) ? $perCap : (int) $config['max_items_per_friend']);
                        $statusLbl = $status === '' ? 'pending' : $status;
                        $httpCode = (int) ($f['last_http_code'] ?? 0);
                        // [HTTP 200] / [HTTP 404] / [ERR] (transport-level / pre-cURL) / [—] (no attempt)
                        if ($status === '') {
                            $httpLbl = '—';
                        } elseif ($httpCode > 0) {
                            $httpLbl = 'HTTP ' . $httpCode;
                        } else {
                            $httpLbl = 'ERR';
                        }
                    ?>
                        <li class="stalk-admin-friend">
                            <span class="stalk-admin-index"><?= Http::e(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                            <span class="stalk-admin-handle">@<?= Http::e($hdl) ?></span>
                            <a class="stalk-admin-url"
                               href="<?= Http::e($url) ?>"
                               target="_blank" rel="noopener noreferrer"
                               title="<?= Http::e($url) ?>"><?= Http::e($host) ?></a>
                            <span class="stalk-admin-friend-meta">
                                <code class="stalk-admin-tag">max <?= Http::e($capLbl) ?></code>
                                <code class="stalk-admin-status stalk-admin-status-<?= Http::e($statusLbl) ?>"
                                      <?= $err !== '' ? 'title="' . Http::e($err) . '"' : ($status === 'ok' && $last > 0 ? 'title="last good fetch ' . Http::e($lastHum) . '"' : '') ?>>
                                    <?= Http::e($httpLbl) ?>
                                </code>
                            </span>
                            <form method="post"
                                  action="/admin/stalk/remove/<?= Http::e($id) ?>"
                                  class="stalk-admin-remove-form"
                                  onsubmit="return confirm('Remove @<?= Http::e($hdl) ?>? Cached posts will be wiped from /stalk.');">
                                <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                                <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">[ REMOVE ]</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <form method="post" action="/admin/stalk/refresh-now" class="stalk-admin-refresh-form">
                    <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                    <button type="submit" class="admin-btn admin-btn-primary">[ REFRESH NOW ]</button>
                </form>
            <?php endif; ?>
        </div>

        <aside class="admin-series-edit-aside">
            <h3 class="stalk-admin-section-heading">// CONFIG</h3>
            <form method="post" action="/admin/stalk/config" class="admin-series-edit-form">
                <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">

                <label class="admin-label" for="stalk-interval">Refresh interval</label>
                <select name="refresh_interval" id="stalk-interval" class="admin-input">
                    <?php foreach ($allowed_intervals as $opt): ?>
                        <option value="<?= Http::e($opt) ?>"
                                <?= $opt === $config['refresh_interval'] ? 'selected' : '' ?>>
                            <?= Http::e($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="admin-label" for="stalk-max-friends">Max friends
                    <span class="admin-label-hint">(1–<?= Http::e((string) $max_friends_ceiling) ?>)</span>
                </label>
                <input type="number" name="max_friends" id="stalk-max-friends"
                       class="admin-input"
                       min="1" max="<?= Http::e((string) $max_friends_ceiling) ?>"
                       value="<?= Http::e((string) $config['max_friends']) ?>">

                <label class="admin-label" for="stalk-default-items">Default max posts</label>
                <input type="number" name="max_items_per_friend" id="stalk-default-items"
                       class="admin-input"
                       min="1" max="<?= Http::e((string) $max_items_ceiling) ?>"
                       value="<?= Http::e((string) $config['max_items_per_friend']) ?>">

                <div class="admin-form-actions">
                    <button type="submit" class="admin-btn admin-btn-primary">[ SAVE CONFIG ]</button>
                </div>
            </form>
        </aside>
    </div>
</section>
