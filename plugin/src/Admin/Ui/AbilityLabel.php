<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

/**
 * An ability as a person reads it: the label the ability registered with
 * (`wp_get_ability( $name )->get_label()`), the mono name as secondary text,
 * and the name alone when the ability is no longer registered or has no label.
 */
final class AbilityLabel
{
    /** @var array<string, string> */
    private static array $cache = [];

    /** The registered label, or '' when there is none to show. */
    public static function label(string $name): string
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }
        $label = '';
        if (function_exists('wp_get_ability')) {
            $ability = wp_get_ability($name);
            if ($ability !== null) {
                $label = trim((string) $ability->get_label());
            }
        }

        return self::$cache[$name] = $label;
    }

    /** The label if there is one, else the name. */
    public static function text(string $name): string
    {
        $label = self::label($name);

        return $label !== '' ? $label : $name;
    }

    /** "Update page" with the mono name beneath, as markup (escaped). */
    public static function html(string $name, string $nameClass = 'aui-mono aui-muted'): string
    {
        $label = self::label($name);
        if ($label === '' || $label === $name) {
            return '<span class="aui-mono">' . esc_html($name) . '</span>';
        }

        return esc_html($label) . '<div class="' . esc_attr($nameClass) . '">' . esc_html($name) . '</div>';
    }

    /** Forget cached labels (tests, and abilities registered mid-request). */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
