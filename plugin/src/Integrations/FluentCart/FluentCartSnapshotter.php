<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

use Belayline\Changes\ChangeSnapshotter;
use Belayline\Changes\SnapshotRef;
use wpdb;

/**
 * Args-based capture for FluentCart's writes (it has no usable hooks for them,
 * SPEC-COMMERCE-INTEGRATIONS.md section 3.2). {@see refs()} names the `fct_*`
 * rows a call touches from its input; {@see snapshot()} reads one straight from
 * `$wpdb`, before and after.
 *
 * Object types and ids:
 *   fct_coupon       `{id}`, or `code:{code}` for a create (the id exists only in
 *                    the result; `code` is UNIQUE, CouponsMigrator.php)
 *   fct_customer     `{id}`, or `email:{email}` for a create (a customer_id, or an
 *                    email that already matches a row, resolves to the id up front
 *                    so a later `new_email` rename stays one object)
 *   fct_labels       `{order|customer|subscription}:{id}` - the sorted label id set
 *   fct_order        `{id}`           (recorded, never restorable)
 *   fct_subscription `{id}`           (recorded, never restorable)
 *
 * `use_count`, `updated_at` and the customer's purchase aggregates move on every
 * sale or save, so they are left out of every body: a checkout racing a call can
 * never register as a change. A create that has no unique natural key in its
 * args records nothing (none of the seven write abilities is in that position).
 */
final class FluentCartSnapshotter implements ChangeSnapshotter
{
    public const COUPON = 'fct_coupon';
    public const CUSTOMER = 'fct_customer';
    public const LABELS = 'fct_labels';
    public const ORDER = 'fct_order';
    public const SUBSCRIPTION = 'fct_subscription';

    private const LABEL_ENTITIES = ['order' => 'Order', 'customer' => 'Customer', 'subscription' => 'Subscription'];

    private const ABILITIES = [
        'fluent-cart/manage-coupon',
        'fluent-cart/upsert-customer',
        'fluent-cart/apply-labels',
        'fluent-cart/add-order-note',
        'fluent-cart/change-order-status',
        'fluent-cart/refund-order',
        'fluent-cart/change-subscription-status',
    ];

    public function __construct(private readonly wpdb $db)
    {
    }

    public function handles(string $ability): bool
    {
        return in_array($ability, self::ABILITIES, true);
    }

    public function refs(string $ability, array $args): array
    {
        switch ($ability) {
            case 'fluent-cart/manage-coupon':
                $id = self::posInt($args['coupon_id'] ?? null);
                if ($id !== null) {
                    return [new SnapshotRef(self::COUPON, (string) $id)];
                }
                $code = $args['code'] ?? null;

                return ($args['action'] ?? null) === 'create' && is_string($code) && $code !== ''
                    ? [new SnapshotRef(self::COUPON, 'code:' . sanitize_text_field($code))]
                    : [];

            case 'fluent-cart/upsert-customer':
                $id = self::posInt($args['customer_id'] ?? null);
                if ($id === null && is_string($args['email'] ?? null) && $args['email'] !== '') {
                    $email = sanitize_email($args['email']);
                    if ($email === '') {
                        return [];
                    }
                    $id = $this->customerIdByEmail($email);
                    if ($id === null) {
                        return [new SnapshotRef(self::CUSTOMER, 'email:' . $email)];
                    }
                }

                return $id === null ? [] : [new SnapshotRef(self::CUSTOMER, (string) $id)];

            case 'fluent-cart/apply-labels':
                $type = $args['entity_type'] ?? null;
                $id = self::posInt($args['entity_id'] ?? null);

                return is_string($type) && isset(self::LABEL_ENTITIES[$type]) && $id !== null
                    ? [new SnapshotRef(self::LABELS, $type . ':' . $id)]
                    : [];

            case 'fluent-cart/change-subscription-status':
                $id = self::posInt($args['subscription_id'] ?? null);

                return $id === null ? [] : [new SnapshotRef(self::SUBSCRIPTION, (string) $id)];

            default: // add-order-note, change-order-status, refund-order
                $id = self::posInt($args['order_id'] ?? null);

                return $id === null ? [] : [new SnapshotRef(self::ORDER, (string) $id)];
        }
    }

    public function snapshot(SnapshotRef $ref): ?array
    {
        return $this->read($ref->objectType, $ref->objectId);
    }

    /** @return array<string, mixed>|null */
    public function read(string $type, string $id): ?array
    {
        return match ($type) {
            self::COUPON => $this->coupon($id),
            self::CUSTOMER => $this->customer($id),
            self::LABELS => $this->labels($id),
            self::ORDER => $this->order($id),
            self::SUBSCRIPTION => $this->subscription($id),
            default => null,
        };
    }

    /** Live `use_count` of a coupon (kept out of the snapshot body); null when the row is gone. */
    public function couponUseCount(int $id): ?int
    {
        // fct_coupons.use_count: CouponsMigrator.php (INT DEFAULT 0).
        $v = $this->db->get_var($this->db->prepare('SELECT `use_count` FROM %i WHERE `id` = %d LIMIT 1', $this->table('fct_coupons'), $id));

        return $v === null ? null : (int) $v;
    }

    /** @return array<string, mixed>|null */
    private function coupon(string $id): ?array
    {
        // fct_coupons columns: CouponsMigrator.php:14-30. No use_count, no updated_at.
        $cols = '`id`, `title`, `code`, `priority`, `type`, `conditions`, `amount`, `status`, `notes`, `stackable`, `show_on_checkout`, `start_date`, `end_date`';
        $row = str_starts_with($id, 'code:')
            ? $this->db->get_row($this->db->prepare('SELECT ' . $cols . ' FROM %i WHERE `code` = %s LIMIT 1', $this->table('fct_coupons'), substr($id, 5)), ARRAY_A)
            : $this->db->get_row($this->db->prepare('SELECT ' . $cols . ' FROM %i WHERE `id` = %d LIMIT 1', $this->table('fct_coupons'), (int) $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) ($row['id'] ?? 0);
        if (is_string($row['conditions'] ?? null) && $row['conditions'] !== '') {
            $decoded = json_decode($row['conditions'], true);
            $row['conditions'] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['conditions'];
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function customer(string $id): ?array
    {
        // fct_customers columns: CustomersMigrator.php:15-34. Aggregates (ltv, aov,
        // purchase_*) and updated_at are left out: every order moves them.
        $cols = '`id`, `user_id`, `email`, `first_name`, `last_name`, `status`, `notes`, `country`, `city`, `state`, `postcode`';
        $row = str_starts_with($id, 'email:')
            ? $this->db->get_row($this->db->prepare('SELECT ' . $cols . ' FROM %i WHERE `email` = %s LIMIT 1', $this->table('fct_customers'), substr($id, 6)), ARRAY_A)
            : $this->db->get_row($this->db->prepare('SELECT ' . $cols . ' FROM %i WHERE `id` = %d LIMIT 1', $this->table('fct_customers'), (int) $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) ($row['id'] ?? 0);

        return $row;
    }

    private function customerIdByEmail(string $email): ?int
    {
        $v = $this->db->get_var($this->db->prepare('SELECT `id` FROM %i WHERE `email` = %s LIMIT 1', $this->table('fct_customers'), $email));

        return $v === null ? null : (int) $v;
    }

    /** @return array<string, mixed>|null */
    private function labels(string $id): ?array
    {
        [$type, $entityId] = array_pad(explode(':', $id, 2), 2, '');
        if (!isset(self::LABEL_ENTITIES[$type]) || (int) $entityId <= 0) {
            return null;
        }
        // fct_label_relationships(label_id, labelable_id, labelable_type): LabelRelationshipsMigrator.php:17-20.
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT `label_id` FROM %i WHERE `labelable_id` = %d AND `labelable_type` = %s',
            $this->table('fct_label_relationships'),
            (int) $entityId,
            'FluentCart\\App\\Models\\' . self::LABEL_ENTITIES[$type],
        ), ARRAY_A);
        $ids = array_map(static fn (array $r): int => (int) $r['label_id'], $rows ?? []);
        sort($ids);

        return ['label_ids' => array_values(array_unique($ids))];
    }

    /** @return array<string, mixed>|null */
    private function order(string $id): ?array
    {
        // fct_orders columns: OrdersMigrator.php:16-48.
        return $this->byId('fct_orders', '`id`, `status`, `payment_status`, `shipping_status`, `total_amount`, `total_paid`, `total_refund`, `note`, `completed_at`, `refunded_at`', $id);
    }

    /** @return array<string, mixed>|null */
    private function subscription(string $id): ?array
    {
        // fct_subscriptions columns: SubscriptionsMigrator.php:18-49.
        return $this->byId('fct_subscriptions', '`id`, `status`, `canceled_at`, `restored_at`, `next_billing_date`, `bill_count`', $id);
    }

    /**
     * @param literal-string $cols
     * @return array<string, mixed>|null
     */
    private function byId(string $table, string $cols, string $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT ' . $cols . ' FROM %i WHERE `id` = %d LIMIT 1', $this->table($table), (int) $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) ($row['id'] ?? 0);

        return $row;
    }

    private function table(string $name): string
    {
        return $this->db->prefix . $name;
    }

    private static function posInt(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }
}
