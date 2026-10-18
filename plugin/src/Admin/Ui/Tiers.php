<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

/**
 * The one vocabulary for "what happens when an agent calls this": Runs freely,
 * Needs approval, Always asks (plus Blocked and Not reviewed for third-party
 * abilities). Badges, segmented controls and the "12 abilities - 8 run freely"
 * group summary all read from here so the words never drift.
 */
final class Tiers
{
    public const FREE = 'free';
    public const APPROVAL = 'approval';
    public const ALWAYS = 'always';
    public const BLOCKED = 'blocked';
    public const NONE = 'none';

    public static function label(string $tier): string
    {
        return match ($tier) {
            self::FREE => __('Runs freely', 'belayline-agent-governance'),
            self::APPROVAL => __('Needs approval', 'belayline-agent-governance'),
            self::ALWAYS => __('Always asks', 'belayline-agent-governance'),
            self::BLOCKED => __('Blocked', 'belayline-agent-governance'),
            default => __('Not reviewed', 'belayline-agent-governance'),
        };
    }

    public static function badge(string $tier): string
    {
        return match ($tier) {
            self::FREE => Html::badge('', self::label($tier), 'book'),
            self::APPROVAL => Html::badge('warn', self::label($tier), 'clock'),
            self::ALWAYS => Html::badge('ink', self::label($tier), 'lock'),
            self::BLOCKED => Html::badge('bad', self::label($tier), 'x'),
            default => Html::badge('', self::label($tier)),
        };
    }

    /**
     * "12 abilities · 8 run freely · 3 need approval · 1 always ask", with
     * "blocked" and "not reviewed" only when there are any.
     *
     * @param array<string, int> $counts tier key => number of abilities
     */
    public static function meta(int $total, array $counts): string
    {
        $parts = [sprintf(self::templates()[$total === 1 ? 'one' : 'many'], $total)];
        foreach ([self::FREE, self::APPROVAL, self::ALWAYS, self::BLOCKED, self::NONE] as $tier) {
            $n = $counts[$tier] ?? 0;
            if ($n > 0 || in_array($tier, [self::FREE, self::APPROVAL, self::ALWAYS], true)) {
                $parts[] = sprintf(self::templates()[$tier], $n);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * The translated sentence fragments, also handed to the browser as JSON so the
     * summary can follow a segmented control without a page reload.
     *
     * @return array<string, string>
     */
    public static function templates(): array
    {
        return [
            'one' => __('%d ability', 'belayline-agent-governance'),
            'many' => __('%d abilities', 'belayline-agent-governance'),
            self::FREE => __('%d run freely', 'belayline-agent-governance'),
            self::APPROVAL => __('%d need approval', 'belayline-agent-governance'),
            self::ALWAYS => __('%d always ask', 'belayline-agent-governance'),
            self::BLOCKED => __('%d blocked', 'belayline-agent-governance'),
            self::NONE => __('%d not reviewed', 'belayline-agent-governance'),
        ];
    }

    /**
     * The summary span for a group header, with the data the script needs to keep it current.
     *
     * @param array<string, int> $counts
     */
    public static function metaSpan(int $total, array $counts, bool $live): string
    {
        return '<span class="aui-group-meta"' . ($live ? ' data-belayline-meta="' . esc_attr((string) wp_json_encode(self::templates())) . '"' : '') . '>'
            . esc_html(self::meta($total, $counts)) . '</span>';
    }
}
