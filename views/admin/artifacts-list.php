<?php
/** @var string $title */
/** @var list<array{id:string,title:string,size:int,mtime:int,usedIn:list<array{title:string,url:string,editUrl:string,draft:bool}>,block:string}> $artifacts */
/** @var int $totalCount */
/** @var int $unusedCount */
/** @var string $filter */
/** @var int|float $maxMb */
/** @var ?string $flash */

use App\Csrf;
use App\Http;

$csrf = Csrf::token();
$fmtSize = static fn (int $b): string => $b >= 1048576
    ? number_format($b / 1048576, 1) . ' MB'
    : max(1, (int) round($b / 1024)) . ' KB';
?>

<section>
    <div class="admin-header-row">
        <?php
        $activeTab = 'artifacts';
        $artifactCount = $totalCount;
        include __DIR__ . '/_tabs.php';
        ?>
    </div>

    <?php if ($flash !== null): ?>
        <p class="admin-flash">// <?= Http::e($flash) ?></p>
    <?php endif; ?>

    <div class="admin-artifacts-bar">
        <form method="post" action="/admin/artifacts/upload" enctype="multipart/form-data" class="admin-artifacts-upload">
            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
            <?php /* The label is the visible trigger; choosing a file submits. */ ?>
            <label class="admin-btn admin-btn-primary">
                [ UPLOAD .HTML ]
                <input type="file" name="file" accept=".html,.htm,text/html" hidden data-autosubmit>
            </label>
            <span class="admin-artifacts-hint">// self-contained page, max <?= Http::e((string) $maxMb) ?> MB</span>
        </form>
        <nav class="admin-artifacts-filter" aria-label="Filter artifacts">
            <a href="/admin/artifacts" <?= $filter === 'all' ? 'aria-current="page"' : '' ?>>ALL (<?= $totalCount ?>)</a>
            <a href="/admin/artifacts?filter=unused" <?= $filter === 'unused' ? 'aria-current="page"' : '' ?>>UNUSED (<?= $unusedCount ?>)</a>
        </nav>
    </div>

    <?php if ($artifacts === []): ?>
        <p style="color: var(--text-dim);">
            <?php if ($filter === 'unused'): ?>
                // Every artifact is embedded somewhere.
            <?php else: ?>
                // No artifacts yet. Upload one here or with the cube button in the post editor,
                then embed it with <code>::: artifact id="…"</code>.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <table class="admin-table admin-artifacts-table">
            <thead>
                <tr>
                    <th>TITLE</th>
                    <th>USED IN</th>
                    <th>SIZE</th>
                    <th>UPDATED</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($artifacts as $a): ?>
                    <?php
                    $id = $a['id'];
                    $used = $a['usedIn'];
                    $confirm = $used === []
                        ? 'Delete artifact "' . $a['title'] . '"? No post embeds it.'
                        : 'Delete artifact "' . $a['title'] . '"? Still embedded in: '
                            . implode(', ', array_map(static fn (array $u): string => $u['title'], $used))
                            . ' — those embeds will show "artifact not found".';
                    ?>
                    <tr>
                        <td class="admin-title-cell">
                            <a href="/artifacts/<?= Http::e($id) ?>" target="_blank" rel="noopener" title="<?= Http::e($a['title']) ?>">
                                <?= Http::e($a['title']) ?>
                            </a>
                            <div class="admin-artifact-id"><?= Http::e($id) ?></div>
                        </td>
                        <td class="admin-mono admin-artifact-usage">
                            <?php if ($used === []): ?>
                                <span class="admin-artifact-unused">UNUSED</span>
                            <?php else: ?>
                                <?php foreach ($used as $u): ?>
                                    <a href="<?= Http::e($u['editUrl']) ?>" title="Edit <?= Http::e($u['title']) ?>"><?= Http::e($u['title']) ?></a><?= $u['draft'] ? ' <span class="admin-artifact-draft">DRAFT</span>' : '' ?><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="admin-mono admin-col-mobile-hide"><?= Http::e($fmtSize($a['size'])) ?></td>
                        <td class="admin-mono"><?= Http::e(date('Y-m-d', $a['mtime'])) ?></td>
                        <td class="admin-row-actions">
                            <a class="admin-btn admin-btn-sm" href="/artifacts/<?= Http::e($id) ?>" target="_blank" rel="noopener" title="Open in a new tab (sandboxed)">VIEW</a>
                            <button type="button" class="admin-btn admin-btn-sm" data-copy="<?= Http::e($a['block']) ?>" title="Copy the ::: artifact block">COPY</button>
                            <form method="post" action="/admin/artifacts/<?= Http::e($id) ?>/replace" enctype="multipart/form-data">
                                <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                                <label class="admin-btn admin-btn-sm" title="Upload a new version — same ID, every embed updates">
                                    REPLACE
                                    <input type="file" name="file" accept=".html,.htm,text/html" hidden data-autosubmit>
                                </label>
                            </form>
                            <form method="post" action="/admin/artifacts/<?= Http::e($id) ?>/delete"
                                  data-confirm="<?= Http::e($confirm) ?>"
                                  data-confirm-title="Delete artifact"
                                  data-confirm-label="[ DELETE ]"
                                  data-confirm-danger="1">
                                <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                                <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">DEL</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<script>
/* File pickers submit their form as soon as a file is chosen (UPLOAD and
   per-row REPLACE); COPY puts the ready `::: artifact` block on the
   clipboard and flashes the button label. */
(function () {
    document.querySelectorAll('input[data-autosubmit]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (input.files && input.files.length) input.form.submit();
        });
    });
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var done = function (label) {
                btn.textContent = label;
                setTimeout(function () { btn.textContent = 'COPY'; }, 1400);
            };
            if (!navigator.clipboard) { window.prompt('Copy:', btn.dataset.copy); return; }
            navigator.clipboard.writeText(btn.dataset.copy).then(
                function () { done('COPIED'); },
                function () { window.prompt('Copy:', btn.dataset.copy); }
            );
        });
    });
})();
</script>
