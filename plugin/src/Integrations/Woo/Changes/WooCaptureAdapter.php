<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo\Changes;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeCaptureAdapter;
use Specflux\AgentSafety\Plugin\Changes\Adapters\RecordsChanges;

/**
 * Hook capture for WooCommerce. Products and variations, coupons, customers
 * and shipping zones are WC_Data objects: the `woocommerce_before_{type}_
 * object_save` ACTION (coupons fire the generic `data` type, filtered on the
 * class) hands over the persisted get_data() as the before state; the matching
 * `after` action, where the id of a new object is known, reads the saved object
 * back as the after state and records one change. Deletes are seen on the `woocommerce_pre_delete_{type}`
 * filter, whose value is returned untouched.
 *
 * Products, variations, coupons and orders are also WordPress posts: this
 * adapter claims their post and post meta ids at priority 1 so the core
 * Post and PostMeta adapters never record them a second time.
 *
 * Recorded as `irreversible` (shown in the history, never undone): orders
 * (created, status transitions, deleted), refunds, customer notes and shipping
 * zone method add/delete/toggle. A product's stock is recorded inside the
 * product change and treated as volatile by the restore adapter.
 *
 * Not seen: stock decremented by an order (wc_update_product_stock writes
 * through the data store, no save hook), customer delete_and_reassign() (no
 * delete hook), and a variable parent's re-sync on `shutdown` (after the
 * window closed; skipped while a variation save is in flight).
 */
final class WooCaptureAdapter implements ChangeCaptureAdapter
{
    use RecordsChanges;

    /** Post types owned by this adapter: the core post adapters must not record them. */
    private const OWNED_POST_TYPES = ['product', 'product_variation', 'shop_coupon', 'shop_order', 'shop_order_refund', 'shop_order_placehold'];

    private const REASONS = [
        'order_created' => 'An order can not be undone: it may have taken payment and sent emails.',
        'order_status' => 'Order status changes trigger payments, stock and customer emails that can not be undone.',
        'order_deleted' => 'A deleted order can not be recreated.',
        'refund' => 'A refund moves money and restocks items; it can not be undone from here.',
        'customer_note' => 'The customer was emailed this note; it can not be recalled.',
        'zone_method' => 'Shipping zone methods are recorded for the history only; undo them in WooCommerce.',
    ];

    /** @var array<int, array{type: string, subtype: ?string, before: ?array<string, mixed>, parent: int}> */
    private array $pending = [];

    /** @var array<int, int> Variable parent id => variation saves in flight. */
    private array $parents = [];

    public function objectTypes(): array
    {
        return [
            WooEntityState::PRODUCT, WooEntityState::COUPON, WooEntityState::CUSTOMER, WooEntityState::ZONE,
            WooEntityState::TAX_RATE, WooEntityState::ORDER, WooEntityState::REFUND, WooEntityState::ZONE_METHOD,
        ];
    }

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
        if ($this->attached) {
            return;
        }
        $this->attached = true;
        $this->pending = [];
        $this->parents = [];

        foreach (['product', 'data', 'customer', 'shipping_zone'] as $kind) {
            add_action('woocommerce_before_' . $kind . '_object_save', [$this, 'onBeforeSave'], 10, 2);
            add_action('woocommerce_after_' . $kind . '_object_save', [$this, 'onAfterSave'], 10, 2);
        }
        foreach (['product', 'data', 'customer', 'shipping_zone', 'order', 'order_refund'] as $kind) {
            add_filter('woocommerce_pre_delete_' . $kind, [$this, 'onPreDelete'], 9999, 3);
        }
        foreach (['wp_insert_post', 'post_updated', 'wp_trash_post', 'before_delete_post'] as $hook) {
            add_action($hook, [$this, 'onPostEvent'], 1, 1);
        }

        add_action('woocommerce_new_order', [$this, 'onNewOrder'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 10, 3);
        add_action('woocommerce_order_refunded', [$this, 'onOrderRefunded'], 10, 2);
        add_action('woocommerce_new_customer_note', [$this, 'onCustomerNote'], 10, 1);
        add_action('woocommerce_shipping_zone_method_added', [$this, 'onZoneMethodAdded'], 10, 3);
        add_action('woocommerce_shipping_zone_method_deleted', [$this, 'onZoneMethodDeleted'], 10, 3);
        add_action('woocommerce_shipping_zone_method_status_toggled', [$this, 'onZoneMethodToggled'], 10, 4);
        add_action('woocommerce_tax_rate_added', [$this, 'onTaxRateAdded'], 10, 2);
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }
        $this->attached = false;
        $this->pending = [];
        $this->parents = [];

        foreach (['product', 'data', 'customer', 'shipping_zone'] as $kind) {
            remove_action('woocommerce_before_' . $kind . '_object_save', [$this, 'onBeforeSave'], 10);
            remove_action('woocommerce_after_' . $kind . '_object_save', [$this, 'onAfterSave'], 10);
        }
        foreach (['product', 'data', 'customer', 'shipping_zone', 'order', 'order_refund'] as $kind) {
            remove_filter('woocommerce_pre_delete_' . $kind, [$this, 'onPreDelete'], 9999);
        }
        foreach (['wp_insert_post', 'post_updated', 'wp_trash_post', 'before_delete_post'] as $hook) {
            remove_action($hook, [$this, 'onPostEvent'], 1);
        }

        remove_action('woocommerce_new_order', [$this, 'onNewOrder'], 10);
        remove_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 10);
        remove_action('woocommerce_order_refunded', [$this, 'onOrderRefunded'], 10);
        remove_action('woocommerce_new_customer_note', [$this, 'onCustomerNote'], 10);
        remove_action('woocommerce_shipping_zone_method_added', [$this, 'onZoneMethodAdded'], 10);
        remove_action('woocommerce_shipping_zone_method_deleted', [$this, 'onZoneMethodDeleted'], 10);
        remove_action('woocommerce_shipping_zone_method_status_toggled', [$this, 'onZoneMethodToggled'], 10);
        remove_action('woocommerce_tax_rate_added', [$this, 'onTaxRateAdded'], 10);
    }

    /** Claim the post and post meta of an owned post type before the core adapters (priority 10) see it. */
    public function onPostEvent(int $postId): void
    {
        $this->guard('woo', function () use ($postId): void {
            $post = get_post($postId);
            $fields = is_object($post) ? get_object_vars($post) : (is_array($post) ? $post : []);
            if (in_array($fields['post_type'] ?? '', self::OWNED_POST_TYPES, true)) {
                $this->claim($postId);
            }
        });
    }

    /** @param mixed $store */
    public function onBeforeSave(object $object, $store = null): void
    {
        unset($store);

        $this->guard('woo', function () use ($object): void {
            $type = self::typeOf($object);
            if ($type === null) {
                return;
            }

            $id = (int) WooObjects::call($object, 'get_id');
            $variation = WooObjects::is($object, 'WC_Product_Variation');
            $parent = $variation ? (int) WooObjects::call($object, 'get_parent_id') : 0;
            if ($id > 0 && in_array($type, [WooEntityState::PRODUCT, WooEntityState::COUPON], true)) {
                $this->claim($id);
            }
            if ($type === WooEntityState::PRODUCT && !$variation && $id > 0 && ($this->parents[$id] ?? 0) > 0) {
                return; // the parent's derived sync of a variation save in flight: a consequence, not a change
            }

            $this->pending[spl_object_id($object)] = [
                'type' => $type,
                'subtype' => $type === WooEntityState::PRODUCT ? (string) WooObjects::call($object, 'get_type') : null,
                'before' => $id > 0 ? WooEntityState::build($type, $object) : null,
                'parent' => $parent,
            ];
            if ($parent > 0) {
                $this->parents[$parent] = ($this->parents[$parent] ?? 0) + 1;
            }
        });
    }

    /** @param mixed $store */
    public function onAfterSave(object $object, $store = null): void
    {
        unset($store);

        $key = spl_object_id($object);
        $pending = $this->pending[$key] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->pending[$key]);
        if ($pending['parent'] > 0) {
            $this->parents[$pending['parent']] = max(0, ($this->parents[$pending['parent']] ?? 1) - 1);
        }

        $this->guard('woo', function () use ($object, $pending): void {
            $id = (int) WooObjects::call($object, 'get_id');
            if ($id <= 0) {
                return;
            }
            $this->record(
                $pending['type'],
                (string) $id,
                $pending['subtype'],
                $pending['before'] === null ? ChangeAction::Create : ChangeAction::Update,
                $pending['before'],
                // Read back after the save, not predicted from the pending changes: it then
                // carries what the data store derived (`price`, the generated slug, final meta).
                WooEntityState::build($pending['type'], $object),
            );
        });
    }

    /**
     * @param mixed $check
     * @param mixed $object
     * @param mixed $force
     * @return mixed The incoming value, untouched.
     */
    public function onPreDelete($check, $object = null, $force = false)
    {
        if ($check !== null || !is_object($object)) {
            return $check;
        }

        $this->guard('woo', function () use ($object, $force): void {
            $id = (int) WooObjects::call($object, 'get_id');
            if ($id <= 0) {
                return;
            }
            if (WooObjects::is($object, 'WC_Abstract_Order')) {
                $this->irreversible(WooObjects::is($object, 'WC_Order_Refund') ? WooEntityState::REFUND : WooEntityState::ORDER, (string) $id, 'deleted', 'order_deleted', ['status' => WooObjects::call($object, 'get_status')], null);

                return;
            }

            $type = self::typeOf($object);
            if ($type === null) {
                return;
            }
            $before = WooEntityState::build($type, $object);
            $subtype = $type === WooEntityState::PRODUCT ? (string) WooObjects::call($object, 'get_type') : null;
            if ((bool) $force || !isset($before['status'])) {
                $this->record($type, (string) $id, $subtype, ChangeAction::Delete, $before, null);
            } else {
                $this->record($type, (string) $id, $subtype, ChangeAction::Trash, $before, ['status' => 'trash'] + $before);
            }
        });

        return $check;
    }

    /** @param mixed $order */
    public function onNewOrder(int $orderId, $order = null): void
    {
        $this->guard('woo', function () use ($orderId, $order): void {
            $after = is_object($order) ? ['status' => WooObjects::call($order, 'get_status'), 'total' => WooObjects::call($order, 'get_total')] : [];
            $this->irreversible(WooEntityState::ORDER, (string) $orderId, 'created', 'order_created', null, $after);
        });
    }

    /** @param mixed $order */
    public function onOrderStatusChanged(int $orderId, string $from, string $to, $order = null): void
    {
        unset($order);

        $this->guard('woo', function () use ($orderId, $from, $to): void {
            $this->irreversible(WooEntityState::ORDER, (string) $orderId, 'status', 'order_status', ['status' => $from], ['status' => $to]);
        });
    }

    public function onOrderRefunded(int $orderId, int $refundId): void
    {
        $this->guard('woo', function () use ($orderId, $refundId): void {
            $refund = WooObjects::fn('wc_get_order', $refundId);
            $amount = is_object($refund) && WooObjects::can($refund, 'get_amount') ? WooObjects::call($refund, 'get_amount') : null;
            $this->irreversible(WooEntityState::REFUND, (string) $refundId, null, 'refund', null, ['order_id' => $orderId, 'amount' => $amount]);
        });
    }

    /** @param mixed $note array{order_id?: int, customer_note?: string} */
    public function onCustomerNote($note): void
    {
        $this->guard('woo', function () use ($note): void {
            $note = is_array($note) ? $note : [];
            $this->irreversible(WooEntityState::ORDER, (string) ($note['order_id'] ?? 0), 'customer_note', 'customer_note', null, ['customer_note' => (string) ($note['customer_note'] ?? '')]);
        });
    }

    public function onZoneMethodAdded(int $instanceId, string $type = '', int $zoneId = 0): void
    {
        $this->guard('woo', function () use ($instanceId, $type, $zoneId): void {
            $this->irreversible(WooEntityState::ZONE_METHOD, (string) $instanceId, 'added', 'zone_method', null, ['method_id' => $type, 'zone_id' => $zoneId]);
        });
    }

    public function onZoneMethodDeleted(int $instanceId, string $methodId = '', int $zoneId = 0): void
    {
        $this->guard('woo', function () use ($instanceId, $methodId, $zoneId): void {
            $this->irreversible(WooEntityState::ZONE_METHOD, (string) $instanceId, 'deleted', 'zone_method', ['method_id' => $methodId, 'zone_id' => $zoneId], null);
        });
    }

    /** @param mixed $enabled */
    public function onZoneMethodToggled(int $instanceId, string $methodId = '', int $zoneId = 0, $enabled = null): void
    {
        $this->guard('woo', function () use ($instanceId, $methodId, $zoneId, $enabled): void {
            $this->irreversible(WooEntityState::ZONE_METHOD, (string) $instanceId, 'toggled', 'zone_method', null, ['method_id' => $methodId, 'zone_id' => $zoneId, 'enabled' => (bool) $enabled]);
        });
    }

    /**
     * A tax rate created through WC_Tax has no id until after the insert, so
     * the snapshotter cannot name it; the creation is seen here instead.
     *
     * @param mixed $row
     */
    public function onTaxRateAdded(int $rateId, $row = null): void
    {
        unset($row);

        $this->guard('woo', function () use ($rateId): void {
            $this->record(WooEntityState::TAX_RATE, (string) $rateId, null, ChangeAction::Create, null, WooEntityState::taxRate($rateId));
        });
    }

    private function claim(int $postId): void
    {
        $this->window?->claim('post', (string) $postId);
        $this->window?->claim('post_meta', (string) $postId);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function irreversible(string $type, string $id, ?string $subtype, string $reason, ?array $before, ?array $after): void
    {
        // The reason sits in both bodies, so it never shows up as a changed field.
        $note = ['reason' => self::REASONS[$reason] ?? $reason];
        $this->record($type, $id, $subtype, ChangeAction::Irreversible, $before === null ? null : $before + $note, $after === null ? null : $after + $note);
    }

    private static function typeOf(object $object): ?string
    {
        return match (true) {
            WooObjects::is($object, 'WC_Product') => WooEntityState::PRODUCT,
            WooObjects::is($object, 'WC_Coupon') => WooEntityState::COUPON,
            WooObjects::is($object, 'WC_Customer') => WooEntityState::CUSTOMER,
            WooObjects::is($object, 'WC_Shipping_Zone') => WooEntityState::ZONE,
            default => null,
        };
    }
}
