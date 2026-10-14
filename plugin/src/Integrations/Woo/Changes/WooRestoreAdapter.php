<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo\Changes;

use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\ChangeRestoreAdapter;
use Belayline\Changes\StoredChange;

/**
 * Restores WooCommerce changes through WooCommerce's own CRUD objects
 * (`set_props` + `save()`, `delete()` for a created item), never raw post meta
 * or SQL, so the data store recomputes what it derives (the `_price` meta, the
 * product lookup tables, caches). The one non-CRUD path is a tax rate, which
 * has no CRUD object: it goes through WC_Tax's own update and delete methods.
 *
 * What is never written back:
 * - Stock (`stock_quantity`, `stock_status`) and counters change with live
 *   sales; they are volatile (left out of the drift check) and the undo screen
 *   shows the recorded delta to apply by hand. A change that touched nothing
 *   else is refused as `volatile_only` rather than "restored" as a no-op.
 * - A customer's password (never recorded), role and username.
 * - A created customer or shipping zone; a permanent delete; a deleted tax
 *   rate (existing orders reference its id).
 * - Orders, refunds, customer notes and zone methods: recorded irreversible.
 *
 * A created product or coupon is moved to the trash. A trashed one gets its
 * previous status back. `price` is derived: it is rewritten only when no
 * price prop changed, so the data store's recompute (which runs when
 * regular_price, sale_price or the sale dates are in the save) stays the
 * single writer of `_price`.
 */
final class WooRestoreAdapter implements ChangeRestoreAdapter
{
    public const REASON_PERMANENT_DELETE = 'permanent_delete';
    public const REASON_VOLATILE_ONLY = 'volatile_only';
    public const REASON_FIELD = 'field_not_restorable';
    public const REASON_CREATE = 'create_not_undoable';
    public const REASON_TAX_DELETE = 'tax_rate_delete';

    private const VOLATILE = [
        // product
        'date_modified', 'total_sales', 'rating_counts', 'average_rating', 'review_count', 'stock_quantity', 'stock_status',
        // coupon
        'usage_count', 'used_by',
        // customer
        'display_name', 'is_paying_customer',
    ];

    /** Fields this adapter cannot write back: a change touching one is refused whole. */
    private const UNRESTORABLE = ['downloads', 'role', 'username'];

    private const PRICE_PROPS = ['regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to'];

    public function objectTypes(): array
    {
        return [
            WooEntityState::PRODUCT, WooEntityState::COUPON, WooEntityState::CUSTOMER, WooEntityState::ZONE,
            WooEntityState::TAX_RATE, WooEntityState::ORDER, WooEntityState::REFUND, WooEntityState::ZONE_METHOD,
        ];
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_woocommerce';
    }

    public function canRestore(StoredChange $change): ?string
    {
        $type = $change->objectType;
        if (in_array($type, [WooEntityState::ORDER, WooEntityState::REFUND, WooEntityState::ZONE_METHOD], true)) {
            return Change::REASON_IRREVERSIBLE;
        }

        switch ($change->action) {
            case ChangeAction::Delete:
                return $type === WooEntityState::TAX_RATE ? self::REASON_TAX_DELETE : self::REASON_PERMANENT_DELETE;

            case ChangeAction::Create:
                return in_array($type, [WooEntityState::CUSTOMER, WooEntityState::ZONE], true) ? self::REASON_CREATE : null;

            case ChangeAction::Update:
            case ChangeAction::Trash:
                if (array_intersect($change->changedFields, self::UNRESTORABLE) !== []) {
                    return self::REASON_FIELD;
                }

                return array_diff($change->changedFields, self::VOLATILE) === [] ? self::REASON_VOLATILE_ONLY : null;

            default:
                return Change::REASON_IRREVERSIBLE;
        }
    }

    public function currentState(StoredChange $change): ?array
    {
        $id = (int) $change->objectId;
        if ($change->objectType === WooEntityState::TAX_RATE) {
            return WooEntityState::taxRate($id);
        }

        $object = self::load($change->objectType, $id);

        // Always a fresh read, so a variable parent's shutdown-time sync is
        // already in the state the drift check compares.
        return $object === null ? null : WooEntityState::build($change->objectType, $object);
    }

    public function volatileFields(): array
    {
        return self::VOLATILE;
    }

    public function restore(StoredChange $change): void
    {
        $type = $change->objectType;
        $id = (int) $change->objectId;

        if ($type === WooEntityState::TAX_RATE) {
            $this->restoreTaxRate($change, $id);

            return;
        }

        $object = self::load($type, $id);
        if ($object === null) {
            throw new \RuntimeException('The WooCommerce object no longer exists.');
        }

        switch ($change->action) {
            case ChangeAction::Create:
                // Moved to the trash, never deleted for good.
                if (!in_array($type, [WooEntityState::PRODUCT, WooEntityState::COUPON], true) || WooObjects::call($object, 'delete', false) !== true) {
                    throw new \RuntimeException('WooCommerce refused to trash the created item.');
                }

                return;

            case ChangeAction::Update:
            case ChangeAction::Trash:
                $this->apply($object, $change);
                WooObjects::call($object, 'save');

                return;

            default:
                throw new \RuntimeException('This change cannot be restored.');
        }
    }

    private function restoreTaxRate(StoredChange $change, int $id): void
    {
        if ($change->action === ChangeAction::Create) {
            WooObjects::stat('WC_Tax', '_delete_tax_rate', $id);

            return;
        }
        if ($change->action !== ChangeAction::Update) {
            throw new \RuntimeException('This change cannot be restored.');
        }

        $before = $change->before ?? [];
        $row = [];
        foreach (WooEntityState::taxColumns() as $column) {
            if (array_key_exists($column, $before)) {
                $row[$column] = $before[$column];
            }
        }
        WooObjects::stat('WC_Tax', '_update_tax_rate', $id, $row);
        if (array_key_exists('postcode', $before)) {
            WooObjects::stat('WC_Tax', '_update_tax_rate_postcodes', $id, (string) $before['postcode']);
        }
        if (array_key_exists('city', $before)) {
            WooObjects::stat('WC_Tax', '_update_tax_rate_cities', $id, (string) $before['city']);
        }
    }

    /** Write the recorded `before` values of the changed fields onto the object (not yet saved). */
    private function apply(object $object, StoredChange $change): void
    {
        $before = $change->before ?? [];
        $fields = $change->changedFields;
        $priceDriven = array_intersect(self::PRICE_PROPS, $fields) !== [];

        $props = [];
        $blank = [];
        foreach ($fields as $field) {
            if (in_array($field, self::VOLATILE, true) || $field === 'meta' || !array_key_exists($field, $before)) {
                continue;
            }
            if ($field === 'price' && $priceDriven) {
                continue; // recomputed by the data store from the price props
            }

            $value = $before[$field];
            if (($field === 'billing' || $field === 'shipping') && is_array($value)) {
                foreach ($value as $key => $part) {
                    self::set($object, $field . '_' . $key, $part);
                }
                continue;
            }
            if ($field === 'attributes' && is_array($value)) {
                $value = self::attributes($value);
            } elseif ($field === 'zone_locations' && is_array($value)) {
                $value = array_map(static fn ($location): object => (object) (is_array($location) ? $location : []), $value);
            }

            if ($value === null) {
                $blank[] = $field; // set_props() skips null: clear these through their setter
            } else {
                $props[$field] = $value;
            }
        }

        $result = WooObjects::call($object, 'set_props', $props);
        if (is_wp_error($result)) {
            throw new \RuntimeException(esc_html($result->get_error_message()));
        }
        foreach ($blank as $field) {
            self::set($object, $field, '');
        }

        if (in_array('meta', $fields, true)) {
            $this->applyMeta($object, $change);
        }
    }

    private function applyMeta(object $object, StoredChange $change): void
    {
        $before = is_array($change->before['meta'] ?? null) ? $change->before['meta'] : [];
        $after = is_array($change->after['meta'] ?? null) ? $change->after['meta'] : [];

        foreach ($before as $key => $value) {
            WooObjects::call($object, 'update_meta_data', (string) $key, $value);
        }
        foreach (array_keys($after) as $key) {
            if (!array_key_exists($key, $before)) {
                WooObjects::call($object, 'delete_meta_data', (string) $key);
            }
        }
    }

    private static function set(object $object, string $prop, mixed $value): void
    {
        if (WooObjects::can($object, 'set_' . $prop)) {
            WooObjects::call($object, 'set_' . $prop, $value);
        }
    }

    /**
     * @param array<array-key, mixed> $stored
     * @return array<array-key, object>
     */
    private static function attributes(array $stored): array
    {
        $attributes = [];
        foreach ($stored as $key => $data) {
            $data = is_array($data) ? $data : [];
            $attribute = WooObjects::make('WC_Product_Attribute');
            WooObjects::call($attribute, 'set_id', (int) ($data['id'] ?? 0));
            WooObjects::call($attribute, 'set_name', (string) ($data['name'] ?? ''));
            WooObjects::call($attribute, 'set_options', is_array($data['options'] ?? null) ? $data['options'] : []);
            WooObjects::call($attribute, 'set_position', (int) ($data['position'] ?? 0));
            WooObjects::call($attribute, 'set_visible', (bool) ($data['visible'] ?? false));
            WooObjects::call($attribute, 'set_variation', (bool) ($data['variation'] ?? false));
            $attributes[$key] = $attribute;
        }

        return $attributes;
    }

    private static function load(string $type, int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $object = match ($type) {
                WooEntityState::PRODUCT => WooObjects::fn('wc_get_product', $id),
                WooEntityState::COUPON => WooObjects::make('WC_Coupon', $id),
                WooEntityState::CUSTOMER => WooObjects::make('WC_Customer', $id),
                WooEntityState::ZONE => WooObjects::make('WC_Shipping_Zone', $id),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }

        return is_object($object) && (int) WooObjects::call($object, 'get_id') > 0 ? $object : null;
    }
}
