<?php

declare(strict_types=1);

use App\Http;

/**
 * Shared admin shell — tab strip rendered above each Graffiti admin view.
 * Uses the same `.admin-tabs / .admin-tab` classes as core `/admin` so the
 * chrome stays visually consistent with the rest of the admin UI.
 *
 * Send tab removed — the in-page spray button on /posts/* handles the
 * composer flow now. The /admin/graffiti/send route still resolves for any
 * operator who bookmarked it, but it's no longer in the chrome.
 *
 * @var string             $activeTab   one of: received|friends|stickers|energy
 * @var array<string,int>  $tabCounts   optional counts shown as `(N)` suffix
 */
$tabCounts = $tabCounts ?? [];

$tabs = [
    'received' => ['label' => 'Received', 'href' => '/admin/graffiti'],
    'friends'  => ['label' => 'Friends',  'href' => '/admin/graffiti/friends'],
    'stickers' => ['label' => 'Stickers', 'href' => '/admin/graffiti/stickers'],
    'energy'   => ['label' => 'Energy',   'href' => '/admin/graffiti/energy'],
];
?>
<div class="admin-tabs" role="tablist" aria-label="Graffiti sections">
    <?php foreach ($tabs as $key => $tab):
        $label = strtoupper($tab['label']);
        if (array_key_exists($key, $tabCounts)) {
            $label .= ' (' . (int) $tabCounts[$key] . ')';
        }
    ?>
        <a class="admin-tab"
           href="<?= Http::e($tab['href']) ?>"
           <?= $key === $activeTab ? 'aria-current="page" aria-selected="true"' : 'aria-selected="false"' ?>>
            [ <?= Http::e($label) ?> ]
        </a>
    <?php endforeach; ?>
</div>
