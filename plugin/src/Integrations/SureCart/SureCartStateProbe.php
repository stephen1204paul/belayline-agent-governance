<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

use Closure;
use Belayline\Plugin\Approval\StateProbe;
use Throwable;

/**
 * AS-6 state probe for SureCart verbs that overwrite or remove an EXISTING
 * remote object.
 *
 * DECISION: probe, with a cache-bypassing GET, rather than leave these verbs
 * unfingerprinted. SureCart data lives behind a remote API, so a probe costs one
 * HTTPS round trip (~100-400 ms) per park/approve/claim check, but only for the
 * verbs below (updates, archives, deletes, refunds, cancellations); reads and
 * creates never probe. Skipping the probe would let an approval granted against
 * one price/coupon/subscription execute against a different one after a human
 * (or another agent, or a webhook) changed it, which is the exact hole the
 * fingerprint exists to close, and these are the money-moving verbs. The cost is
 * latency and an outage dependency, and both fail closed: any failure to read
 * (WP_Error, exception, timeout, missing id, missing updated_at) returns null,
 * which the pipeline refuses as `state_unverifiable`. An unreachable SureCart
 * API therefore blocks approvals; it never waves them through.
 *
 * Cache bypass: SureCart models cache GETs per account; the request layer honours
 * `query.cached = false` (RequestService::shouldFindCache), so the default
 * fetcher calls `Model::where(['cached' => false])->find($id)`.
 *
 * The fetcher is injectable so tests never touch the network.
 */
final class SureCartStateProbe implements StateProbe
{
    /**
     * verb => [SureCart model class, argument holding the object id].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const TARGETS = [
        'surecart/update-product'                   => ['Product', 'id'],
        'surecart/archive-product'                  => ['Product', 'id'],
        'surecart/update-price'                     => ['Price', 'id'],
        'surecart/update-coupon'                    => ['Coupon', 'id'],
        'surecart/delete-coupon'                    => ['Coupon', 'id'],
        'surecart/update-customer'                  => ['Customer', 'id'],
        'surecart/delete-customer'                  => ['Customer', 'id'],
        'surecart/update-fulfillment'               => ['Fulfillment', 'id'],
        'surecart/delete-fulfillment'               => ['Fulfillment', 'id'],
        'surecart/update-promotion'                 => ['Promotion', 'id'],
        'surecart/delete-promotion'                 => ['Promotion', 'id'],
        'surecart/update-invoice'                   => ['Invoice', 'id'],
        'surecart/cancel-subscription'              => ['Subscription', 'id'],
        'surecart/update-subscription-renewal-date' => ['Subscription', 'subscription_id'],
        'surecart/create-refund'                    => ['Charge', 'charge'],
    ];

    /** @var Closure(string, string): (array<string, mixed>|null) */
    private Closure $fetcher;

    /** @param (callable(string, string): (array<string, mixed>|null))|null $fetcher Given a short model name and id, returns the fresh attributes or null. */
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : self::liveFetch(...);
    }

    /** @param array<string, mixed> $args */
    public function read(string $verb, array $args): ?array
    {
        $target = self::TARGETS[$verb] ?? null;
        $id = $this->idFor($verb, $args);
        if ($target === null || $id === null) {
            return null;
        }

        try {
            $attrs = ($this->fetcher)($target[0], $id);
        } catch (Throwable) {
            return null;
        }

        // updated_at is the change marker; without it nothing can be proven
        // unchanged, so refuse rather than fingerprint an empty fact set.
        if ($attrs === null || !isset($attrs['updated_at'])) {
            return null;
        }

        return [
            'updated_at' => $attrs['updated_at'],
            'status' => $attrs['status'] ?? null,
            'archived_at' => $attrs['archived_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $args */
    public function targetArgs(string $verb, array $args): array
    {
        $target = self::TARGETS[$verb] ?? null;
        $id = $this->idFor($verb, $args);

        return $target !== null && $id !== null ? [$target[1] => $id] : [];
    }

    /** @param array<string, mixed> $args */
    private function idFor(string $verb, array $args): ?string
    {
        $target = self::TARGETS[$verb] ?? null;
        $id = $target !== null ? ($args[$target[1]] ?? null) : null;

        // SureCart ids are opaque strings (uuids); never trust anything else.
        return is_string($id) && $id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 ? $id : null;
    }

    /**
     * The real remote GET. Only runs when SureCart is loaded; every failure
     * shape collapses to null.
     *
     * @return array<string, mixed>|null
     */
    private static function liveFetch(string $model, string $id): ?array
    {
        try {
            return SureCartRemote::get($model, $id);
        } catch (Throwable) {
            return null;
        }
    }
}
