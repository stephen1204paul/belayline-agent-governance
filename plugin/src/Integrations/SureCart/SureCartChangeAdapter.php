<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

use Closure;
use Belayline\Changes\CaptureWindow;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\ChangeCaptureAdapter;
use Belayline\Changes\ChangeRestoreAdapter;
use Belayline\Changes\ResultAwareSnapshotter;
use Belayline\Changes\SnapshotRef;
use Belayline\Changes\StoredChange;

/**
 * Change capture and restore for SureCart's REMOTE objects (the data lives in
 * SureCart's API; WordPress only holds synced copies, so no hook sees it).
 *
 *  - Capture is snapshot based: a cache-bypassing GET before and after each
 *    Tier >= 1 write, normalised to a flat scalar field array. Creates are
 *    named from the call's result ({@see ResultAwareSnapshotter}). A failed GET
 *    throws, so the host records a `capture_error` marker and the call still
 *    runs; "not found" (null) is the only thing treated as "absent".
 *  - The synced `sc_product` post (and its meta) is CLAIMED on the capture
 *    window so the core post adapters never record SureCart's own sync as an
 *    agent change. It is never restored separately: SureCart re-syncs it from
 *    the restored remote object.
 *  - Restore PATCHes only the changed, allow-listed fields through the SureCart
 *    model, after a drift check against a fresh GET. A created product or price
 *    is ARCHIVED, never deleted. Deletes, refunds, cancellations, invoices and
 *    fulfillments that notify customers are recorded but never restorable.
 *
 * Both remote calls are injectable; tests never touch the network.
 */
final class SureCartChangeAdapter implements ResultAwareSnapshotter, ChangeCaptureAdapter, ChangeRestoreAdapter
{
    public const TYPE_PREFIX = 'surecart_';
    public const IRREVERSIBLE_SUBTYPE = 'irreversible:';

    public const REASON_DELETE = 'remote_delete_not_restorable';
    public const REASON_CREATE = 'created_not_undoable';
    public const REASON_FIELD = 'field_not_restorable';

    /** Verbs whose effect leaves SureCart and cannot be pulled back by a PATCH. */
    public const IRREVERSIBLE_VERBS = [
        'surecart/create-refund',
        'surecart/cancel-subscription',
        'surecart/create-invoice',
        'surecart/update-invoice',
    ];

    /** Verbs that email the customer when `notifications_enabled` is on. */
    private const NOTIFYING_VERBS = ['surecart/create-fulfillment', 'surecart/update-fulfillment'];

    /** @var array<string, array{0: string, 1: string}> verb => [model, result key]; the new id is in the result. */
    public const CREATES = [
        'surecart/create-product'     => ['Product', 'product'],
        'surecart/duplicate-product'  => ['Product', 'product'],
        'surecart/create-price'       => ['Price', 'price'],
        'surecart/create-coupon'      => ['Coupon', 'coupon'],
        'surecart/create-promotion'   => ['Promotion', 'promotion'],
        'surecart/create-customer'    => ['Customer', 'customer'],
        'surecart/create-fulfillment' => ['Fulfillment', 'fulfillment'],
        'surecart/create-invoice'     => ['Invoice', 'invoice'],
        'surecart/create-refund'      => ['Refund', 'refund'],
    ];

    /**
     * Fields a restore may write, per model. Anything else that changed (and is
     * not volatile) makes the change not restorable: fail closed rather than
     * PATCH an attribute nobody has read the semantics of. `archived` is
     * derived from `archived_at`.
     *
     * @var array<string, list<string>>
     */
    private const WRITABLE = [
        'Product'     => ['name', 'description', 'archived'],
        'Price'       => ['amount', 'name', 'archived'],
        'Coupon'      => ['name', 'percent_off', 'amount_off', 'duration', 'duration_in_months', 'max_redemptions', 'max_redemptions_per_customer', 'min_subtotal_amount', 'max_subtotal_amount', 'redeem_by', 'archived'],
        'Customer'    => ['email', 'first_name', 'last_name', 'name', 'phone', 'tax_enabled', 'unsubscribed'],
        'Fulfillment' => ['shipment_status'],
        'Promotion'   => ['code'],
    ];

    /** Models a created object of which can be undone by archiving it. */
    private const ARCHIVABLE = ['Product', 'Price'];

    private const VOLATILE = ['updated_at'];

    private const MODELS = ['Product', 'Price', 'Coupon', 'Promotion', 'Customer', 'Fulfillment', 'Invoice', 'Subscription', 'Charge', 'Refund'];

    /** @var Closure(string, string): (array<string, mixed>|null) */
    private Closure $fetcher;

    /** @var Closure(string, string, array<string, mixed>): void */
    private Closure $updater;

    /** @var Closure(string): ?int */
    private Closure $postLookup;

    private ?CaptureWindow $window = null;

    /**
     * @param (callable(string, string): (array<string, mixed>|null))|null $fetcher    model, id => attributes, null when absent, throws on failure.
     * @param (callable(string, string, array<string, mixed>): void)|null  $updater    model, id, fields; throws on failure.
     * @param (callable(string): ?int)|null                                $postLookup SureCart product id => synced `sc_product` post id.
     */
    public function __construct(?callable $fetcher = null, ?callable $updater = null, ?callable $postLookup = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : SureCartRemote::get(...);
        $this->updater = $updater !== null ? Closure::fromCallable($updater) : SureCartRemote::update(...);
        $this->postLookup = $postLookup !== null ? Closure::fromCallable($postLookup) : self::livePostLookup(...);
    }

    // ---- capture: claim window --------------------------------------------

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
    }

    public function detach(): void
    {
        $this->window = null;
    }

    // ---- capture: snapshots -----------------------------------------------

    public function handles(string $ability): bool
    {
        return isset(SureCartStateProbe::TARGETS[$ability]) || isset(self::CREATES[$ability]);
    }

    public function refs(string $ability, array $args): array
    {
        $target = SureCartStateProbe::TARGETS[$ability] ?? null;
        $id = $target !== null ? self::cleanId($args[$target[1]] ?? null) : null;
        if ($target === null || $id === null) {
            return [];
        }

        return [new SnapshotRef(self::typeFor($target[0]), $id, self::subtype($ability, $args))];
    }

    public function refsFromResult(string $ability, array $args, mixed $result): array
    {
        $create = self::CREATES[$ability] ?? null;
        if ($create === null || !is_array($result)) {
            return [];
        }

        $created = $result[$create[1]] ?? null;
        $id = self::cleanId(is_array($created) ? ($created['id'] ?? null) : (is_object($created) ? ($created->id ?? null) : null));

        return $id === null ? [] : [new SnapshotRef(self::typeFor($create[0]), $id, self::subtype($ability, $args))];
    }

    public function snapshot(SnapshotRef $ref): ?array
    {
        $model = self::modelFor($ref->objectType);
        if ($model === null) {
            return null;
        }

        $attrs = ($this->fetcher)($model, $ref->objectId);
        if ($attrs === null) {
            return null;
        }

        $this->claimSyncedPost($model, $ref->objectId, $attrs);

        return self::normalise($attrs);
    }

    /**
     * Claim the local `sc_product` post a product (or one of its prices) syncs
     * into, so the post adapters do not record SureCart's sync as an agent change.
     *
     * @param array<string, mixed> $attrs
     */
    private function claimSyncedPost(string $model, string $id, array $attrs): void
    {
        $window = $this->window;
        if ($window === null) {
            return;
        }

        $productId = match ($model) {
            'Product' => $id,
            'Price' => self::cleanId(is_array($attrs['product'] ?? null) ? ($attrs['product']['id'] ?? null) : ($attrs['product'] ?? null)),
            default => null,
        };
        if ($productId === null) {
            return;
        }

        try {
            $postId = ($this->postLookup)($productId);
        } catch (\Throwable) {
            return;
        }
        if ($postId !== null && $postId > 0) {
            $window->claim('post', (string) $postId);
            $window->claim('post_meta', (string) $postId);
        }
    }

    // ---- restore -----------------------------------------------------------

    public function objectTypes(): array
    {
        return array_map(static fn (string $m): string => self::typeFor($m), self::MODELS);
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_options';
    }

    public function canRestore(StoredChange $change): ?string
    {
        if (str_starts_with((string) $change->objectSubtype, self::IRREVERSIBLE_SUBTYPE)) {
            return Change::REASON_IRREVERSIBLE;
        }

        $model = self::modelFor($change->objectType);
        if ($model === null) {
            return StoredChange::REASON_NOT_RESTORABLE;
        }

        switch ($change->action) {
            case ChangeAction::Delete:
                return self::REASON_DELETE;
            case ChangeAction::Create:
                return in_array($model, self::ARCHIVABLE, true) ? null : self::REASON_CREATE;
            case ChangeAction::Update:
                $writable = self::WRITABLE[$model] ?? [];
                foreach ($change->changedFields as $field) {
                    $mapped = $field === 'archived_at' ? 'archived' : $field;
                    if (!in_array($field, self::VOLATILE, true) && !in_array($mapped, $writable, true)) {
                        return self::REASON_FIELD;
                    }
                }

                return null;
            default:
                return StoredChange::REASON_NOT_RESTORABLE;
        }
    }

    public function currentState(StoredChange $change): ?array
    {
        $model = self::modelFor($change->objectType);
        if ($model === null) {
            return null;
        }

        $attrs = ($this->fetcher)($model, $change->objectId); // a failed GET throws: the engine reports it
        if ($attrs === null) {
            return null;
        }

        return self::normalise($attrs);
    }

    public function volatileFields(): array
    {
        return self::VOLATILE;
    }

    public function restore(StoredChange $change): void
    {
        $model = self::modelFor($change->objectType);
        if ($model === null) {
            throw new \RuntimeException('Unknown SureCart object type: ' . esc_html($change->objectType));
        }

        if ($change->action === ChangeAction::Create) {
            // Warning: a created product/price cannot be deleted by an undo. It
            // is archived (hidden from the storefront), which keeps purchase history.
            ($this->updater)($model, $change->objectId, ['archived' => true]);
            if (function_exists('do_action')) {
                do_action('belayline_surecart_archived_on_undo', $model, $change->objectId);
            }

            return;
        }

        $writable = self::WRITABLE[$model] ?? [];
        $fields = [];
        foreach ($change->changedFields as $field) {
            if (in_array($field, self::VOLATILE, true)) {
                continue;
            }
            if ($field === 'archived_at') {
                $fields['archived'] = ($change->before['archived_at'] ?? null) !== null;
                continue;
            }
            if (!in_array($field, $writable, true)) {
                throw new \RuntimeException('Field not restorable: ' . esc_html($field));
            }
            $fields[$field] = $change->before[$field] ?? null;
        }
        if ($fields === []) {
            return;
        }

        ($this->updater)($model, $change->objectId, $fields);
    }

    // ---- helpers -----------------------------------------------------------

    private static function typeFor(string $model): string
    {
        return self::TYPE_PREFIX . strtolower($model);
    }

    private static function modelFor(string $type): ?string
    {
        foreach (self::MODELS as $model) {
            if (self::typeFor($model) === $type) {
                return $model;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $args */
    private static function subtype(string $ability, array $args): ?string
    {
        $irreversible = in_array($ability, self::IRREVERSIBLE_VERBS, true)
            || (in_array($ability, self::NOTIFYING_VERBS, true) && SureCartTruthy::flagOn($args['notifications_enabled'] ?? null));

        return $irreversible ? self::IRREVERSIBLE_SUBTYPE . $ability : null;
    }

    private static function cleanId(mixed $id): ?string
    {
        return is_string($id) && $id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 ? $id : null;
    }

    /**
     * Flat scalar view of a model: nested objects and expanded lists are
     * dropped (they are other objects, captured through their own refs).
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private static function normalise(array $attrs): array
    {
        $flat = [];
        foreach ($attrs as $key => $value) {
            if ($value === null || is_scalar($value)) {
                $flat[(string) $key] = $value;
            }
        }
        ksort($flat);

        return $flat;
    }

    private static function livePostLookup(string $productId): ?int
    {
        if (!function_exists('get_posts')) {
            return null;
        }

        /** @var mixed $ids */
        $ids = get_posts([
            'post_type' => 'sc_product',
            'post_status' => 'any',
            'meta_key' => 'sc_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-row lookup by SureCart id (numberposts 1).
            'meta_value' => $productId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- single-row lookup by SureCart id (numberposts 1).
            'fields' => 'ids',
            'numberposts' => 1,
        ]);

        return is_array($ids) && isset($ids[0]) ? (int) $ids[0] : null;
    }
}
