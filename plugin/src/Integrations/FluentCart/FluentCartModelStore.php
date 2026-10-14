<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

/**
 * Restores through FluentCart's own layer: the `Coupon` and `Customer` models
 * (the same ones the MCP tools write with, CouponTools.php:176-199,
 * CustomerTools.php:198-201), `CouponResource::delete()` for coupon removal
 * (api/Resource/CouponResource.php:176) and the `LabelRelationship` model the
 * apply-labels tool uses (LabelTools.php:115-143). Class names are resolved at
 * call time: FluentCart is not a dependency, and any failure throws so the undo
 * engine reports it instead of pretending.
 */
final class FluentCartModelStore implements FluentCartStore
{
    private const NS = 'FluentCart\\App\\Models\\';

    public function updateCoupon(int $id, array $fields): void
    {
        $this->update('Coupon', $id, $fields);
    }

    public function deleteCoupon(int $id): void
    {
        $resource = 'FluentCart\\Api\\Resource\\CouponResource';
        if (!class_exists($resource)) {
            throw new \RuntimeException('FluentCart CouponResource is unavailable.');
        }
        $result = $resource::delete($id);
        if (is_wp_error($result) || (is_array($result) && ($result['code'] ?? 200) >= 400)) {
            throw new \RuntimeException('FluentCart refused to delete the coupon.');
        }
    }

    public function updateCustomer(int $id, array $fields): void
    {
        $this->update('Customer', $id, $fields);
    }

    public function addLabel(string $entityType, int $entityId, int $labelId): void
    {
        $rel = self::NS . 'LabelRelationship';
        $this->need($rel);
        $rel::query()->create(['label_id' => $labelId, 'labelable_id' => $entityId, 'labelable_type' => self::NS . ucfirst($entityType)]);
    }

    public function removeLabel(string $entityType, int $entityId, int $labelId): void
    {
        $rel = self::NS . 'LabelRelationship';
        $this->need($rel);
        $rel::query()->where('label_id', $labelId)->where('labelable_id', $entityId)->where('labelable_type', self::NS . ucfirst($entityType))->delete();
    }

    /** @param array<string, mixed> $fields */
    private function update(string $model, int $id, array $fields): void
    {
        $class = self::NS . $model;
        $this->need($class);
        $row = $class::query()->find($id);
        if (!is_object($row) || !is_callable([$row, 'update'])) {
            throw new \RuntimeException(esc_html($model . ' ' . $id) . ' no longer exists.');
        }
        $row->update($fields);
    }

    private function need(string $class): void
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(esc_html($class) . ' is unavailable.');
        }
    }
}
