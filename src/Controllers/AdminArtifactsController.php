<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ArtifactStore;
use App\ArtifactUsageScanner;
use App\Auth;
use App\Csrf;
use App\Http;

/**
 * Admin library for uploaded HTML artifacts.
 *
 *   GET  /admin/artifacts                — list (+ `?filter=unused`)
 *   POST /admin/artifacts/upload         — add a new artifact
 *   POST /admin/artifacts/{id}/replace   — overwrite in place, ID kept
 *   POST /admin/artifacts/{id}/delete    — remove the file
 *
 * Plain form posts + PRG with a session flash, same shape as the series
 * admin. Every endpoint is auth-gated; mutations also require CSRF.
 */
final class AdminArtifactsController
{
    public function __construct(
        private readonly ArtifactStore $store,
        private readonly ArtifactUsageScanner $usage,
    ) {
    }

    public function index(): void
    {
        Auth::requireAuth();

        $usage = $this->usage->usageById();
        $artifacts = [];
        foreach ($this->store->all() as $a) {
            $a['usedIn'] = $usage[$a['id']] ?? [];
            $a['block'] = ArtifactController::embedBlock($a['id'], $a['title']);
            $artifacts[] = $a;
        }
        $totalCount = count($artifacts);
        $unusedCount = count(array_filter($artifacts, static fn (array $a): bool => $a['usedIn'] === []));

        $filter = ($_GET['filter'] ?? '') === 'unused' ? 'unused' : 'all';
        if ($filter === 'unused') {
            $artifacts = array_values(array_filter($artifacts, static fn (array $a): bool => $a['usedIn'] === []));
        }

        Http::render('admin/artifacts-list', [
            'title' => 'Artifacts // ADMIN',
            'artifacts' => $artifacts,
            'totalCount' => $totalCount,
            'unusedCount' => $unusedCount,
            'filter' => $filter,
            'maxMb' => ArtifactStore::MAX_BYTES / 1024 / 1024,
            'flash' => $this->consumeFlash(),
        ]);
    }

    public function upload(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $file = $this->uploadedFile();
        if ($file !== null) {
            try {
                $saved = $this->store->save($file['tmp_name'], $file['name']);
                $this->flash('Uploaded: ' . $saved['title'] . ' (' . $saved['id'] . ')');
            } catch (\RuntimeException $e) {
                $this->flash('Upload failed: ' . $e->getMessage());
            }
        }
        Http::redirect('/admin/artifacts');
    }

    /** @param array<string,string> $params */
    public function replace(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $id = $params['id'] ?? '';
        $file = $this->uploadedFile();
        if ($file !== null) {
            try {
                $saved = $this->store->replace($id, $file['tmp_name'], $file['name']);
                // /artifacts/{id} is served with max-age=300, so browsers that
                // already loaded it keep the old copy for up to five minutes.
                $this->flash('Replaced: ' . $saved['id'] . ' — embeds pick up the new version within 5 min (browser cache).');
            } catch (\RuntimeException $e) {
                $this->flash('Replace failed: ' . $e->getMessage());
            }
        }
        Http::redirect('/admin/artifacts');
    }

    /** @param array<string,string> $params */
    public function delete(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $id = $params['id'] ?? '';
        $this->flash($this->store->delete($id) ? 'Deleted: ' . $id : 'Artifact not found.');
        Http::redirect('/admin/artifacts');
    }

    /**
     * The posted `file`, or null after flashing why it is unusable.
     *
     * @return array{tmp_name:string,name:string}|null
     */
    private function uploadedFile(): ?array
    {
        $file = $_FILES['file'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_OK) {
            return ['tmp_name' => (string) $file['tmp_name'], 'name' => (string) ($file['name'] ?? '')];
        }
        $this->flash(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload failed: file too large.',
            UPLOAD_ERR_NO_FILE => 'Upload failed: no file chosen.',
            default => 'Upload failed (PHP upload error ' . $error . ').',
        });
        return null;
    }

    private function flash(string $msg): void
    {
        Auth::start();
        $_SESSION['_flash'] = $msg;
    }

    private function consumeFlash(): ?string
    {
        Auth::start();
        if (!empty($_SESSION['_flash'])) {
            $msg = (string) $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $msg;
        }
        return null;
    }
}
