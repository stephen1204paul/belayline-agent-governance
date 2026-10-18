<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

/** Small markup builders for kit components. Every dynamic value is escaped here, once. */
final class Html
{
    private const TONES = ['ok', 'warn', 'bad', 'info', 'ink'];

    /** A kit badge. $tone is one of ok|warn|bad|info|ink or '' for neutral. Text is escaped. */
    public static function badge(string $tone, string $text, string $icon = ''): string
    {
        $class = 'aui-badge' . (in_array($tone, self::TONES, true) ? ' aui-badge--' . $tone : '');

        return '<span class="' . $class . '">' . ($icon !== '' ? Icons::svg($icon) : '') . esc_html($text) . '</span>';
    }

    /**
     * An inline notice. $title is bold, $body follows it; both are escaped.
     * Errors announce themselves (`alert`), everything else is a polite `status`.
     */
    public static function notice(string $tone, string $title, string $body = ''): string
    {
        $tone = in_array($tone, ['info', 'success', 'warning', 'error'], true) ? $tone : 'info';
        $icon = ['info' => 'info', 'success' => 'check', 'warning' => 'warning', 'error' => 'x'][$tone];

        return '<div class="aui-notice aui-notice--' . $tone . '" role="' . ($tone === 'error' ? 'alert' : 'status') . '">'
            . Icons::svg($icon) . '<div><strong>' . esc_html($title) . '</strong>' . ($body !== '' ? ' ' . esc_html($body) : '') . '</div></div>';
    }

    /** A POST form header for an admin-post action with its nonce. */
    public static function postForm(string $action, string $nonceAction, string $extra = '', string $attrs = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . wp_nonce_field($nonceAction, '_wpnonce', true, false)
            . $extra;
    }

    /** A hidden `tab` field so an admin-post handler can send the person back to where they were. */
    public static function backTo(string $tab): string
    {
        return '<input type="hidden" name="belayline_tab" value="' . esc_attr($tab) . '">';
    }

    /** A card header with a title and optional description. */
    public static function cardHeader(string $title, string $description = '', string $id = ''): string
    {
        return '<div class="aui-card-h"><div><h2 class="aui-card-t"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>' . esc_html($title) . '</h2>'
            . ($description !== '' ? '<p class="aui-card-d">' . esc_html($description) . '</p>' : '') . '</div></div>';
    }
}
