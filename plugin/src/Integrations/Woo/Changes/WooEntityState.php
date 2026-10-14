<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo\Changes;

/**
 * The plain-array shape of a WooCommerce object, shared by capture and
 * restore so a recorded `after` and a live `currentState` compare field for
 * field. Built from the CRUD object's own get_data(), never from raw rows.
 *
 * `meta` is a key => value map of the object's non-internal meta (duplicate
 * keys collapse to the last value). Passwords are never part of a state.
 */
final class WooEntityState
{
    public const PRODUCT = 'wc_product';
    public const COUPON = 'wc_coupon';
    public const CUSTOMER = 'wc_customer';
    public const ZONE = 'wc_shipping_zone';
    public const TAX_RATE = 'wc_tax_rate';
    public const ORDER = 'wc_order';
    public const REFUND = 'wc_order_refund';
    public const ZONE_METHOD = 'wc_shipping_zone_method';

    /** Props left out of every state: identity, or derived from other rows. */
    private const OMIT = ['id', 'meta_data', 'children', 'password'];

    private const TAX_COLUMNS = [
        'tax_rate_country', 'tax_rate_state', 'tax_rate', 'tax_rate_name', 'tax_rate_priority',
        'tax_rate_compound', 'tax_rate_shipping', 'tax_rate_order', 'tax_rate_class',
    ];

    /**
     * What is persisted now: get_data() (WooCommerce keeps pending edits apart
     * in get_changes(), so this is the stored state of an object not yet saved
     * and the saved state of one just saved) with the meta re-read from the
     * data store, since the in-memory meta already carries unsaved edits.
     *
     * @param string $type One of the object type constants above.
     * @return array<string, mixed>
     */
    public static function build(string $type, object $object): array
    {
        $data = WooObjects::call($object, 'get_data');
        $data = is_array($data) ? $data : [];

        // A variable parent's price is a consequence of its variations: it is
        // re-synced on shutdown, outside any window, so it is never recorded.
        if ($type === self::PRODUCT && WooObjects::is($object, 'WC_Product_Variable')) {
            unset($data['price']);
        }

        $state = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::OMIT, true) || str_contains(strtolower($key), 'password')) {
                continue;
            }
            $state[$key] = self::normalize($value);
        }

        if ($type !== self::ZONE) {
            $state['meta'] = self::metaMap(self::persistedMeta($object));
        }

        return $state;
    }

    /**
     * Dates become UTC ISO strings, WooCommerce value objects their get_data()
     * arrays, everything else plain arrays and scalars, so the state is
     * JSON-safe and writes back through the same setters that produced it.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp());
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize($v);
            }

            return $out;
        }
        if (is_object($value)) {
            return self::normalize(WooObjects::can($value, 'get_data') ? WooObjects::call($value, 'get_data') : get_object_vars($value));
        }

        return $value;
    }

    /** @return array<string, mixed>|null The live row of a tax rate, locations included; null when it does not exist. */
    public static function taxRate(int $id): ?array
    {
        $row = WooObjects::stat('WC_Tax', '_get_tax_rate', $id, 'ARRAY_A');
        if (!is_array($row)) {
            return null;
        }

        $state = [];
        foreach (self::TAX_COLUMNS as $column) {
            $state[$column] = $row[$column] ?? '';
        }
        $state['postcode'] = self::taxLocations($id, 'postcode');
        $state['city'] = self::taxLocations($id, 'city');

        return $state;
    }

    /** @return list<string> Tax rate columns a restore writes back. */
    public static function taxColumns(): array
    {
        return self::TAX_COLUMNS;
    }

    private static function taxLocations(int $id, string $kind): string
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!is_object($db) || !is_callable([$db, 'get_col'])) {
            return '';
        }

        $prefix = get_object_vars($db)['prefix'] ?? 'wp_';
        $table = (is_string($prefix) ? $prefix : 'wp_') . 'woocommerce_tax_rate_locations';
        // Both interpolations are fixed or integer-cast: no request input reaches the query.
        $codes = WooObjects::call($db, 'get_col', 'SELECT location_code FROM ' . $table . ' WHERE tax_rate_id = ' . $id . " AND location_type = '" . $kind . "' ORDER BY location_id");

        return is_array($codes) ? implode(';', array_map('strval', $codes)) : '';
    }

    /**
     * @return list<array{key: string, value: mixed}>
     */
    private static function persistedMeta(object $object): array
    {
        if ((int) WooObjects::call($object, 'get_id') <= 0 || !WooObjects::can($object, 'get_data_store')) {
            return [];
        }
        $store = WooObjects::call($object, 'get_data_store');
        if (!is_object($store) || !WooObjects::can($store, 'read_meta')) {
            return [];
        }

        $items = [];
        $rows = WooObjects::call($store, 'read_meta', $object);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $fields = is_object($row) ? get_object_vars($row) : [];
            if (isset($fields['meta_key'])) {
                $items[] = ['key' => (string) $fields['meta_key'], 'value' => maybe_unserialize($fields['meta_value'] ?? null)];
            }
        }

        return $items;
    }

    /**
     * @param list<array{key: string, value: mixed}> $items
     * @return array<string, mixed>
     */
    private static function metaMap(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item['key']] = self::normalize($item['value']);
        }
        ksort($map);

        return $map;
    }
}
