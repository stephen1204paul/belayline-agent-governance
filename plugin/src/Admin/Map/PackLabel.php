<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Map;

/**
 * How a pack's name reads to a person. A pack has no label of its own (just the
 * slug it is registered and bound under), so the slug is humanised
 * ("content-editor" becomes "Content editor"), and a site or integration can
 * override it with the `belayline_pack_label` filter. The slug stays the value
 * in forms, URLs and the audit log.
 */
final class PackLabel
{
    public static function of(string $slug): string
    {
        $label = ucfirst(trim(str_replace(['-', '_'], ' ', $slug)));
        $label = $label === '' ? $slug : $label;

        /**
         * @param string $label The humanised name.
         * @param string $slug  The pack's registered name.
         */
        $filtered = apply_filters('belayline_pack_label', $label, $slug);

        return is_string($filtered) && $filtered !== '' ? $filtered : $label;
    }
}
