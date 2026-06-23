<?php

declare(strict_types=1);

namespace App;

/**
 * Plugin contract.
 *
 * A plugin lives in `plugins/{slug}/`. Its `plugin.php` MUST return an
 * instance of an implementer of this interface. PluginRegistry loads
 * enabled plugins (via PLUGINS env CSV), calls register() with a
 * PluginContext, and the plugin uses the context to declare its routes,
 * nav links, assets, and admin pages.
 *
 * See `docs/plugin-development.md` for the author guide.
 */
interface Plugin
{
    public function manifest(): PluginManifest;

    public function register(PluginContext $ctx): void;
}
