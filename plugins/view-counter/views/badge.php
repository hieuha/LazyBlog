<?php
/**
 * Inline view-count fragment. Returns plain text like "42 View".
 *
 * Rendered inside the post's `.section-tag` transmission line; receives
 * `$views` (int) from the listener. Plain text only — no wrapping span,
 * no inline style, no script — the transmission line owns the styling.
 *
 * Label is hard-coded Vietnamese. To localise, edit this file.
 */
/** @var int $views */
echo htmlspecialchars(number_format($views, 0, '.', ',') . ' View', ENT_QUOTES);
