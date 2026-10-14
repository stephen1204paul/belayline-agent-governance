<?php

declare(strict_types=1);

namespace Belayline\Plugin\Changes\Adapters;

/** WordPress object to plain-array helpers shared by capture and restore, so both see the same shape. */
final class WpObjects
{
    /**
     * @param mixed $post
     * @return array<string, mixed>|null
     */
    public static function post($post): ?array
    {
        $data = self::toArray($post);
        if ($data !== null) {
            unset($data['filter']);
        }

        return $data;
    }

    /**
     * @param mixed $term
     * @return array<string, mixed>|null
     */
    public static function term($term): ?array
    {
        return self::post($term);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function toArray($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && !is_wp_error($value)) {
            $data = method_exists($value, 'to_array') ? $value->to_array() : get_object_vars($value);

            return is_array($data) ? $data : null;
        }

        return null;
    }
}
