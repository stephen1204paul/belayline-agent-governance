<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

/** Short, plain-language durations for the admin screens ("3 h", "6 d 23 h"). */
final class Time
{
    /** Whole seconds as "45 s", "12 min", "3 h" or "6 d 23 h". */
    public static function span(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            /* translators: %d: seconds */
            return sprintf(__('%d s', 'belayline-agent-governance'), $seconds);
        }
        if ($seconds < 3600) {
            /* translators: %d: minutes */
            return sprintf(__('%d min', 'belayline-agent-governance'), intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            /* translators: %d: hours */
            return sprintf(__('%d h', 'belayline-agent-governance'), intdiv($seconds, 3600));
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        /* translators: 1: days, 2: hours */
        return sprintf(__('%1$d d %2$d h', 'belayline-agent-governance'), $days, $hours);
    }

    /** "3 h ago" for a UTC timestamp string (`Y-m-d H:i:s` or ISO-8601), or '' when unreadable. */
    public static function ago(string $utc, int $now): string
    {
        $t = self::parse($utc);

        /* translators: %s: a duration such as "3 h" */
        return $t === null ? '' : sprintf(__('%s ago', 'belayline-agent-governance'), self::span($now - $t));
    }

    /** "in 6 d 23 h", or "expired" once the moment has passed. */
    public static function until(string $utc, int $now): string
    {
        $t = self::parse($utc);
        if ($t === null) {
            return '';
        }
        if ($t <= $now) {
            return __('expired', 'belayline-agent-governance');
        }

        /* translators: %s: a duration such as "6 d 23 h" */
        return sprintf(__('in %s', 'belayline-agent-governance'), self::span($t - $now));
    }

    public static function parse(string $utc): ?int
    {
        if ($utc === '') {
            return null;
        }
        $t = strtotime(str_ends_with($utc, 'Z') || str_contains($utc, '+') ? $utc : $utc . ' UTC');

        return $t === false ? null : $t;
    }
}
