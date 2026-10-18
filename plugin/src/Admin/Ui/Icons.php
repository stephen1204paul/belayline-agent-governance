<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

/**
 * Inline icons from the vendored UI kit sprite. The sprite is printed once per
 * screen (references are then same-document, so no CORS, caching or file://
 * surprises) and every icon is decorative, hence `aria-hidden`.
 */
final class Icons
{
    /** Absolute path of the vendored kit directory. */
    public static function kitDir(): string
    {
        return dirname(__DIR__, 3) . '/assets/vendor/admin-ui-kit';
    }

    public static function svg(string $name, string $class = ''): string
    {
        return '<svg class="aui-i' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" aria-hidden="true" focusable="false"><use href="#aui-i-'
            . esc_attr($name) . '"/></svg>';
    }

    /** The sprite markup (a hidden `<svg>` of `<symbol>`s), or '' if the kit is missing. */
    public static function sprite(): string
    {
        $file = self::kitDir() . '/icons.svg';
        $svg = is_readable($file) ? file_get_contents($file) : false;

        return is_string($svg) ? $svg : '';
    }
}
