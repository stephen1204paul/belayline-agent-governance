<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\FluentCart;

use Specflux\AgentSafety\Changes\Change;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeRestoreAdapter;
use Specflux\AgentSafety\Changes\StoredChange;

/**
 * Restores what {@see FluentCartSnapshotter} recorded, writing back through
 * {@see FluentCartStore} (FluentCart's models and resource layer, never raw SQL).
 *
 * Restorable: coupon updates, coupon creates (delete only while `use_count` is
 * 0), customer profile updates, label sets. Never restorable, by reason code:
 * a customer email change (`email_change_not_restorable`: it is the login and
 * billing identity), a customer create or delete, a coupon delete, and every
 * order and subscription change (`irreversible`: emails, stock, gateway money
 * and cancels have already happened).
 *
 * Capability: `manage_options`. FluentCart's own admin gate is
 * `manage_options`, or `PermissionManager::ADMIN_CAP` (`fluent_cart_admin`) for a
 * shop-role user once Pro is active (Hooks/Handlers/MenuHandler.php:226-231;
 * PermissionManager.php:14,173). Its per-ability permissions (`coupons/manage`)
 * are not WordPress capabilities, so the stricter and always-real
 * `manage_options` is the one a human must hold to undo.
 */
final class FluentCartRestoreAdapter implements ChangeRestoreAdapter
{
    public const CAPABILITY = 'manage_options';
    public const REASON_EMAIL = 'email_change_not_restorable';
    public const REASON_IDENTITY_LINK = 'identity_link_not_restorable';
    public const REASON_CUSTOMER_LIFECYCLE = 'customer_create_or_delete_not_restorable';
    public const REASON_COUPON_USED = 'coupon_has_uses';
    public const REASON_COUPON_DELETE = 'coupon_delete_not_restorable';

    private const COUPON_FIELDS = ['title', 'code', 'priority', 'type', 'conditions', 'amount', 'status', 'notes', 'stackable', 'show_on_checkout', 'start_date', 'end_date'];
    private const CUSTOMER_FIELDS = ['first_name', 'last_name', 'status', 'notes', 'country', 'city', 'state', 'postcode'];

    public function __construct(
        private readonly FluentCartSnapshotter $reader,
        private readonly FluentCartStore $store,
    ) {
    }

    public function objectTypes(): array
    {
        return [
            FluentCartSnapshotter::COUPON,
            FluentCartSnapshotter::CUSTOMER,
            FluentCartSnapshotter::LABELS,
            FluentCartSnapshotter::ORDER,
            FluentCartSnapshotter::SUBSCRIPTION,
        ];
    }

    public function capability(StoredChange $change): string
    {
        return self::CAPABILITY;
    }

    public function canRestore(StoredChange $change): ?string
    {
        switch ($change->objectType) {
            case FluentCartSnapshotter::COUPON:
                if ($change->action === ChangeAction::Delete) {
                    return self::REASON_COUPON_DELETE;
                }
                if ($change->action === ChangeAction::Create) {
                    $uses = $this->couponUses($change);

                    return $uses !== null && $uses > 0 ? self::REASON_COUPON_USED : null;
                }

                return null;

            case FluentCartSnapshotter::CUSTOMER:
                if ($change->action !== ChangeAction::Update) {
                    return self::REASON_CUSTOMER_LIFECYCLE;
                }
                if (in_array('email', $change->changedFields, true)) {
                    return self::REASON_EMAIL;
                }

                return in_array('user_id', $change->changedFields, true) ? self::REASON_IDENTITY_LINK : null;

            case FluentCartSnapshotter::LABELS:
                return null;

            default: // orders, subscriptions
                return Change::REASON_IRREVERSIBLE;
        }
    }

    public function currentState(StoredChange $change): ?array
    {
        return $this->reader->read($change->objectType, $change->objectId);
    }

    public function volatileFields(): array
    {
        return ['use_count', 'updated_at', 'ltv', 'aov', 'purchase_count', 'purchase_value', 'first_purchase_date', 'last_purchase_date'];
    }

    public function restore(StoredChange $change): void
    {
        match ($change->objectType) {
            FluentCartSnapshotter::COUPON => $this->restoreCoupon($change),
            FluentCartSnapshotter::CUSTOMER => $this->restoreCustomer($change),
            FluentCartSnapshotter::LABELS => $this->restoreLabels($change),
            default => throw new \LogicException('FluentCart orders and subscriptions are never restored.'),
        };
    }

    private function restoreCoupon(StoredChange $change): void
    {
        $id = (int) ($change->action === ChangeAction::Create ? ($change->after['id'] ?? 0) : ($change->before['id'] ?? $change->objectId));
        if ($id <= 0) {
            throw new \RuntimeException('The recorded coupon has no id.');
        }

        if ($change->action === ChangeAction::Create) {
            // Re-read at the last moment: a checkout may have used it since canRestore().
            if (($this->reader->couponUseCount($id) ?? 0) > 0) {
                throw new \RuntimeException('The coupon has been used and is not deleted.');
            }
            $this->store->deleteCoupon($id);

            return;
        }

        $this->store->updateCoupon($id, $this->fields($change, self::COUPON_FIELDS));
    }

    private function restoreCustomer(StoredChange $change): void
    {
        $this->store->updateCustomer((int) ($change->before['id'] ?? $change->objectId), $this->fields($change, self::CUSTOMER_FIELDS));
    }

    private function restoreLabels(StoredChange $change): void
    {
        [$type, $entityId] = array_pad(explode(':', $change->objectId, 2), 2, '');
        if (!in_array($type, ['order', 'customer', 'subscription'], true) || (int) $entityId <= 0) {
            throw new \RuntimeException('Unrecognised label target.');
        }
        $want = self::ints($change->before['label_ids'] ?? []);
        $now = self::ints($this->currentState($change)['label_ids'] ?? []);
        foreach (array_diff($want, $now) as $labelId) {
            $this->store->addLabel($type, (int) $entityId, $labelId);
        }
        foreach (array_diff($now, $want) as $labelId) {
            $this->store->removeLabel($type, (int) $entityId, $labelId);
        }
    }

    /**
     * The recorded-before value of each field the call changed, limited to the
     * writable allow list (never id, use_count, email or timestamps).
     *
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function fields(StoredChange $change, array $allowed): array
    {
        $out = [];
        foreach ($change->changedFields as $field) {
            if (in_array($field, $allowed, true) && array_key_exists($field, $change->before ?? [])) {
                $out[$field] = $change->before[$field];
            }
        }
        if ($out === []) {
            throw new \RuntimeException('Nothing restorable was recorded.');
        }

        return $out;
    }

    private function couponUses(StoredChange $change): ?int
    {
        $id = (int) ($change->after['id'] ?? 0);

        return $id > 0 ? $this->reader->couponUseCount($id) : null;
    }

    /** @return list<int> */
    private static function ints(mixed $v): array
    {
        return is_array($v) ? array_values(array_map('intval', $v)) : [];
    }
}
