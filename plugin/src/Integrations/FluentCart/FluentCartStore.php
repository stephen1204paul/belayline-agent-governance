<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

/**
 * The write side of FluentCart restores. One seam so the restore adapter holds
 * the rules (what may be written back) and this holds the platform calls.
 */
interface FluentCartStore
{
    /** @param array<string, mixed> $fields */
    public function updateCoupon(int $id, array $fields): void;

    public function deleteCoupon(int $id): void;

    /** @param array<string, mixed> $fields */
    public function updateCustomer(int $id, array $fields): void;

    /** @param 'order'|'customer'|'subscription' $entityType */
    public function addLabel(string $entityType, int $entityId, int $labelId): void;

    /** @param 'order'|'customer'|'subscription' $entityType */
    public function removeLabel(string $entityType, int $entityId, int $labelId): void;
}
