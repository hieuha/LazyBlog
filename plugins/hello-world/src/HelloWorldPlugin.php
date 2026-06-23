<?php

declare(strict_types=1);

namespace Plugins\HelloWorld;

use App\Csrf;
use App\Http;
use App\Plugin;
use App\PluginContext;
use App\PluginManifest;

/**
 * Reference plugin. Demonstrates every surface of the plugin API:
 *
 *   - public GET  /hello             — landing page with view + asset
 *   - public POST /hello/echo        — CSRF-protected form, writes storage
 *   - header nav                     — "Hello" link
 *   - admin GET   /admin/hello-world — auth-gated list of stored echoes
 *   - private storage                — content/plugins/hello-world/echoes.json
 *
 * Copy this folder as a starting template for your own plugin.
 */
final class HelloWorldPlugin implements Plugin
{
    private PluginContext $ctx;

    public function manifest(): PluginManifest
    {
        $raw = (string) file_get_contents(__DIR__ . '/../manifest.json');
        return PluginManifest::fromArray(json_decode($raw, true) ?: []);
    }

    public function register(PluginContext $ctx): void
    {
        $this->ctx = $ctx;

        $ctx->css('style.css');
        $ctx->js('script.js');
        $ctx->nav('Hello', '/hello', 'header');

        $ctx->get('/hello', fn () => $this->index());
        $ctx->post('/hello/echo', fn () => $this->submit());
        $ctx->adminGet('/admin/hello-world', fn () => $this->admin());
    }

    private function index(): void
    {
        $echoes = $this->readEchoes();
        $this->ctx->view('index', [
            'title' => 'Hello World',
            'recent' => array_slice(array_reverse($echoes), 0, 5),
            'csrf' => Csrf::token(),
        ]);
    }

    private function submit(): void
    {
        Csrf::requireValid();

        $text = trim((string) ($_POST['text'] ?? ''));
        if ($text !== '' && mb_strlen($text) <= 200) {
            $echoes = $this->readEchoes();
            $echoes[] = ['ts' => time(), 'text' => $text];
            file_put_contents(
                $this->storageFile(),
                (string) json_encode(
                    $echoes,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                ),
                LOCK_EX,
            );
        }
        Http::redirect('/hello');
    }

    private function admin(): void
    {
        $this->ctx->view('admin', [
            'title' => 'Hello World // Admin',
            'echoes' => array_reverse($this->readEchoes()),
        ]);
    }

    /** @return list<array{ts:int,text:string}> */
    private function readEchoes(): array
    {
        $file = $this->storageFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function storageFile(): string
    {
        return $this->ctx->storagePath() . '/echoes.json';
    }
}
