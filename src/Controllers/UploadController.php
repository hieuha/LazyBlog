<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\ImageProcessor;
use App\PostImageDitherer;

/**
 * POST /admin/upload — multipart form upload of a single image.
 *
 * Auth + CSRF required (token via `X-CSRF-Token` header to match the
 * preview endpoint pattern). Source image is decoded, downscaled if
 * wider than ImageProcessor::MAX_WIDTH, and re-encoded as WebP — that
 * strips ALL metadata (EXIF, GPS, color profile, etc.) as a side effect.
 *
 * If the multipart form carries `dither=1`, the source runs through the
 * 4×4 ordered-Bayer pipeline (PostImageDitherer) instead — same retina
 * cap, but the output is a 1-bit B&W dot-grid WebP for the album-cover
 * aesthetic. Routed by the second toolbar button in admin-editor.js.
 *
 * Files land under content/uploads/YYYY/MM/{rand}.webp so the existing
 * backup-content.sh cron picks them up automatically.
 *
 * Response on success: HTTP 200 + JSON {"url": "/uploads/YYYY/MM/...webp"}
 * Response on failure: HTTP 400/413/415 + JSON {"error": "..."}
 */
final class UploadController
{
    private const MAX_BYTES = 10 * 1024 * 1024;  // 10 MB raw input cap
    private const ACCEPTED_MIME = [
        'image/jpeg' => true,
        'image/png'  => true,
        'image/webp' => true,
    ];

    public function __construct(private readonly string $contentDir)
    {
    }

    public function upload(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        header('Content-Type: application/json; charset=utf-8');

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail(400, $this->uploadErrMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            $this->fail(413, 'File too large (max ' . (self::MAX_BYTES / 1024 / 1024) . ' MB).');
        }

        // Use finfo to read the magic bytes; don't trust the client-sent type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $file['tmp_name']);

        // `kind=social` narrows the accept-set to jpeg/png only — the
        // social card field deliberately excludes webp source uploads.
        // Any other value (including absent) keeps the full set so body
        // editor + dither + avatar uploads still take webp.
        $kind = (string) ($_POST['kind'] ?? '');
        $accepted = self::ACCEPTED_MIME;
        if ($kind === 'social') {
            unset($accepted['image/webp']);
        }
        if (!isset($accepted[$mime])) {
            $allowed = implode(', ', array_map(
                static fn(string $m): string => explode('/', $m)[1],
                array_keys($accepted),
            ));
            $this->fail(415, "Unsupported image type: {$mime}. Allowed: {$allowed}.");
        }

        $relDir = '/uploads/' . date('Y/m');
        $absDir = $this->contentDir . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            $this->fail(500, 'Cannot create upload directory: ' . $absDir
                . '. Check that the parent (' . $this->contentDir
                . '/uploads) exists and is writable by php-fpm user.');
        }
        if (!is_writable($absDir)) {
            $this->fail(500, 'Upload directory not writable: ' . $absDir
                . '. chown to the php-fpm user (lazyblog) and chmod 755.');
        }

        // Random filename — short enough for a clean URL, long enough to
        // be unguessable for accidentally-public uploads.
        $filename = bin2hex(random_bytes(8)) . '.webp';
        $absPath = $absDir . '/' . $filename;

        // `dither=1` opts the upload into the Bayer pipeline. Anything else
        // (absent, '0', empty) keeps the existing plain re-encode path so
        // drag/drop + paste + the legacy cloud button are unchanged.
        $wantDither = (string) ($_POST['dither'] ?? '') === '1';
        if ($wantDither && !PostImageDitherer::isAvailable()) {
            $this->fail(
                500,
                'Dithered uploads require ext-imagick. Install php8.2-imagick '
                . '(Debian/Ubuntu) or rebuild the container with the pecl recipe.',
            );
        }

        try {
            $dims = $wantDither
                ? PostImageDitherer::processToWebp((string) $file['tmp_name'], $absPath)
                : ImageProcessor::processToWebp((string) $file['tmp_name'], $absPath);
        } catch (\Throwable $e) {
            $this->fail(400, $e->getMessage());
        }

        $url = $relDir . '/' . $filename;
        echo json_encode([
            'url' => $url,
            'width' => $dims['width'],
            'height' => $dims['height'],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return never
     */
    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }

    private function uploadErrMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
            UPLOAD_ERR_PARTIAL    => 'Upload was interrupted.',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write upload to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            default               => 'Unknown upload error.',
        };
    }
}
