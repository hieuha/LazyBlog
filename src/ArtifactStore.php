<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Disk store for interactive HTML artifacts embedded in posts.
 *
 * An artifact is a single self-contained `.html` file (typically generated
 * by Claude / ChatGPT / Codex to illustrate a post). Files live under
 * `content/artifacts/{id}.html` — outside the web root, so they are only
 * ever reachable through ArtifactController, which wraps every response
 * in a CSP sandbox. The bytes are stored verbatim: no sanitising, because
 * the whole point is to run the artifact's own scripts — isolation comes
 * from the sandbox, not from filtering.
 *
 * IDs are `{slug-of-original-filename}-{6 hex}`: readable in the markdown
 * source, unguessable enough that two uploads of `index.html` never collide.
 */
final class ArtifactStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const ID_PATTERN = '[a-z0-9][a-z0-9-]{0,79}';

    /**
     * Sandbox flags shared by the serving CSP (ArtifactController) and the
     * embedding iframe (MarkdownRenderer). No allow-same-origin, so the
     * artifact always runs in an opaque origin; no allow-top-navigation, so
     * it can never redirect the reader away from the post.
     */
    public const SANDBOX_FLAGS = 'allow-scripts allow-forms allow-modals allow-popups '
        . 'allow-popups-to-escape-sandbox allow-downloads';

    private const MAX_TITLE_CHARS = 120;
    private const ACCEPTED_EXT = ['html' => true, 'htm' => true];

    public function __construct(private readonly string $contentDir)
    {
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^' . self::ID_PATTERN . '$/', $id) === 1;
    }

    public function dir(): string
    {
        return $this->contentDir . '/artifacts';
    }

    /** Absolute path of an existing artifact, or null for bad / missing IDs. */
    public function path(string $id): ?string
    {
        if (!self::validId($id)) {
            return null;
        }
        $path = $this->dir() . '/' . $id . '.html';
        return is_file($path) ? $path : null;
    }

    /**
     * Validate + persist an uploaded artifact.
     *
     * @return array{id:string,title:string}
     * @throws RuntimeException with a user-facing message on rejection
     */
    public function save(string $tmpPath, string $originalName): array
    {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ACCEPTED_EXT[$ext])) {
            throw new RuntimeException('Artifacts must be a single .html file.');
        }

        $size = (int) @filesize($tmpPath);
        if ($size === 0) {
            throw new RuntimeException('Artifact file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Artifact too large (max ' . (self::MAX_BYTES / 1024 / 1024) . ' MB).');
        }

        // Magic-byte sniff: anything text-ish is fine (finfo reports some
        // generated HTML as text/plain when it opens with a comment), but
        // binary payloads renamed to .html are refused.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!str_starts_with($mime, 'text/')) {
            throw new RuntimeException("Unsupported artifact type: {$mime}.");
        }

        $html = (string) file_get_contents($tmpPath);
        $baseName = (string) pathinfo($originalName, PATHINFO_FILENAME);
        $title = self::extractTitle($html) ?? trim($baseName);

        $slug = SlugUtil::fromTitle($baseName);
        $slug = substr($slug !== '' ? $slug : 'artifact', 0, 60);
        $slug = rtrim($slug, '-');

        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create artifact directory. Check server logs.');
        }

        do {
            $id = $slug . '-' . bin2hex(random_bytes(3));
        } while (is_file($dir . '/' . $id . '.html'));

        FileWriter::writeAtomic($dir . '/' . $id . '.html', $html);

        return ['id' => $id, 'title' => $title];
    }

    /** First `<title>` of the document, whitespace-collapsed and length-capped. */
    public static function extractTitle(string $html): ?string
    {
        if (!preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return null;
        }
        $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            return null;
        }
        return mb_substr($title, 0, self::MAX_TITLE_CHARS);
    }
}
