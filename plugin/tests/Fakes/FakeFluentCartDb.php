<?php

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides wpdb's snake_case API

namespace Belayline\Plugin\Tests\Fakes;

use Belayline\Plugin\Integrations\FluentCart\FluentCartStore;
use wpdb;

/**
 * An in-memory `fct_*` store behind the wpdb stub: answers the snapshotter's
 * SELECTs (by id, code, email, label entity) from `$tables`, and doubles as the
 * {@see FluentCartStore} the restore adapter writes through.
 */
final class FakeFluentCartDb extends wpdb implements FluentCartStore
{
    /** @var array<string, array<int, array<string, mixed>>> table (no prefix) => id => row */
    public array $tables = ['fct_coupons' => [], 'fct_customers' => [], 'fct_orders' => [], 'fct_subscriptions' => [], 'fct_label_relationships' => []];

    /** @var list<string> */
    public array $writes = [];

    public function get_row(?string $query = null, string $output = 'ARRAY_A'): ?array
    {
        $rows = $this->select((string) $query);

        return $rows[0] ?? null;
    }

    public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
    {
        return $this->select((string) $query);
    }

    public function get_var(?string $query = null): mixed
    {
        $rows = $this->select((string) $query);

        return $rows === [] ? null : array_values($rows[0])[0];
    }

    /** @return list<array<string, mixed>> */
    private function select(string $sql): array
    {
        $this->queries[] = $sql;
        preg_match('/^SELECT (.+?) FROM `wp_(\w+)`(?: WHERE (.+?))?(?: LIMIT 1)?$/s', $sql, $m);
        $cols = array_map(static fn (string $c): string => trim($c, " `"), explode(',', $m[1]));
        $where = $m[3] ?? '';
        $out = [];
        foreach ($this->tables[$m[2]] ?? [] as $row) {
            $ok = true;
            foreach (explode(' AND ', $where) as $cond) {
                if (!preg_match("/^`(\w+)` = '?(.*?)'?$/s", trim($cond), $c)) {
                    continue;
                }
                $ok = $ok && (string) ($row[$c[1]] ?? '') === $c[2];
            }
            if ($ok) {
                $out[] = array_intersect_key($row, array_flip($cols));
            }
        }

        return $out;
    }

    public function updateCoupon(int $id, array $fields): void
    {
        $this->writes[] = 'coupon:' . $id . ':' . implode(',', array_keys($fields));
        $this->tables['fct_coupons'][$id] = [...$this->tables['fct_coupons'][$id], ...$fields];
    }

    public function deleteCoupon(int $id): void
    {
        $this->writes[] = 'delete-coupon:' . $id;
        unset($this->tables['fct_coupons'][$id]);
    }

    public function updateCustomer(int $id, array $fields): void
    {
        $this->writes[] = 'customer:' . $id . ':' . implode(',', array_keys($fields));
        $this->tables['fct_customers'][$id] = [...$this->tables['fct_customers'][$id], ...$fields];
    }

    public function addLabel(string $entityType, int $entityId, int $labelId): void
    {
        $this->writes[] = "add-label:$entityType:$entityId:$labelId";
        $this->tables['fct_label_relationships'][] = ['label_id' => $labelId, 'labelable_id' => $entityId, 'labelable_type' => 'FluentCart\\App\\Models\\' . ucfirst($entityType)];
    }

    public function removeLabel(string $entityType, int $entityId, int $labelId): void
    {
        $this->writes[] = "remove-label:$entityType:$entityId:$labelId";
        foreach ($this->tables['fct_label_relationships'] as $k => $r) {
            if ((int) $r['label_id'] === $labelId && (int) $r['labelable_id'] === $entityId && str_ends_with((string) $r['labelable_type'], ucfirst($entityType))) {
                unset($this->tables['fct_label_relationships'][$k]);
            }
        }
    }
}
