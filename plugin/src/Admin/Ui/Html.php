<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

/** Small markup builders for kit components. Every dynamic value is escaped here, once. */
final class Html
{
    private const TONES = ['ok', 'warn', 'bad', 'info', 'ink'];

    /** @var array<string, array<string, bool>>|null */
    private static ?array $allowed = null;

    /**
     * The one allow-list for admin-screen markup, for `wp_kses( $html, Html::allowed() )`.
     *
     * Every echo of kit markup goes through it, so it names exactly the tags and
     * attributes the screens and the icon sprite use and nothing else (no script,
     * style, iframe or event-handler attributes). Attribute keys are lower case,
     * which is how core's kses matches them; it keeps `viewBox` as written.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowed(): array
    {
        if (self::$allowed !== null) {
            return self::$allowed;
        }

        $common = ['class', 'id', 'role', 'hidden', 'title', 'tabindex', 'style',
            'aria-hidden', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-live', 'aria-modal',
            'aria-expanded', 'aria-controls', 'aria-pressed', 'aria-current',
            'data-activity-url', 'data-aui-open', 'data-aui-views', 'data-belayline-meta', 'data-brand', 'data-count',
            'data-filter-key', 'data-filter-value', 'data-kind', 'data-labels', 'data-name', 'data-node',
            'data-off', 'data-on', 'data-paths', 'data-period', 'data-table', 'data-value'];

        $with = static function (array $extra) use ($common): array {
            return array_fill_keys(array_merge($common, $extra), true);
        };

        $allowed = [];
        foreach (
            ['div', 'span', 'p', 'section', 'header', 'nav', 'aside', 'article', 'ul', 'li', 'dl', 'dt', 'dd',
            'h1', 'h2', 'h3', 'strong', 'b', 'i', 'code', 'small', 'ins', 'del', 'br', 'hr', 'thead', 'tbody', 'caption'] as $tag
        ) {
            $allowed[$tag] = $with([]);
        }
        $allowed['a'] = $with(['href', 'target', 'rel']);
        $allowed['time'] = $with(['datetime']);
        $allowed['table'] = $with([]);
        $allowed['tr'] = $with([]);
        $allowed['td'] = $with(['colspan']);
        $allowed['th'] = $with(['scope', 'colspan']);
        $allowed['label'] = $with(['for']);
        $allowed['button'] = $with(['type', 'name', 'value', 'disabled', 'form']);
        $allowed['form'] = $with(['method', 'action']);
        $allowed['input'] = $with(['type', 'name', 'value', 'min', 'max', 'placeholder', 'checked', 'disabled', 'autocomplete', 'form', 'step', 'required']);
        $allowed['select'] = $with(['name', 'disabled', 'form']);
        $allowed['option'] = $with(['value', 'selected', 'disabled']);
        $allowed['textarea'] = $with(['name', 'rows', 'cols', 'placeholder', 'disabled', 'form']);

        // Icons::svg() and the vendored sprite.
        $svg = ['xmlns', 'viewbox', 'width', 'height', 'focusable', 'fill', 'stroke', 'x', 'y', 'rx', 'ry', 'cx', 'cy', 'r', 'd', 'points'];
        $allowed['svg'] = $with($svg);
        $allowed['symbol'] = $with($svg);
        $allowed['path'] = $with($svg);
        $allowed['circle'] = $with($svg);
        $allowed['rect'] = $with($svg);
        $allowed['polyline'] = $with($svg);
        $allowed['use'] = $with(['href', 'xlink:href']);

        self::$allowed = $allowed;

        return $allowed;
    }

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
