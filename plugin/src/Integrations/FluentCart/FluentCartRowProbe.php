<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\FluentCart;

use Specflux\AgentSafety\Plugin\Approval\StateProbe;
use wpdb;

/**
 * Shared shape of the FluentCart staleness probes: read a handful of columns
 * of one `fct_*` row by primary key straight from `$wpdb` (FluentCart's models
 * are a vendored ORM with no stable public contract, and a probe must be cheap
 * and side-effect free). A failed query or a missing row is `null`, which the
 * pipeline turns into `state_unverifiable` -- never "unchanged".
 *
 * Money columns are integer cents (fct_orders, per the 1.7.0 migrations); they
 * are compared as stored, never converted.
 */
abstract class FluentCartRowProbe implements StateProbe
{
    public function __construct(protected readonly wpdb $db)
    {
    }

    /** Table name without the WordPress prefix, e.g. `fct_orders`. */
    abstract protected function table(): string;

    /**
     * Static SQL (literal, so `$wpdb->prepare()` is statically safe): selects the
     * probe's columns from `%i` (the prefixed table) where `id` = `%d`.
     *
     * @return literal-string
     */
    abstract protected function selectByIdSql(): string;

    /** The numeric id argument that names the target row. */
    abstract protected function idArg(string $verb): string;

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    protected function row(string $verb, array $args): ?array
    {
        $id = self::positiveInt($args[$this->idArg($verb)] ?? null);
        if ($id === null) {
            return null;
        }

        $sql = $this->db->prepare($this->selectByIdSql(), $this->db->prefix . $this->table(), $id);
        $row = $this->db->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $args */
    public function read(string $verb, array $args): ?array
    {
        return $this->row($verb, $args);
    }

    /** @param array<string, mixed> $args */
    public function targetArgs(string $verb, array $args): array
    {
        $key = $this->idArg($verb);
        $id = self::positiveInt($args[$key] ?? null);

        return $id === null ? [] : [$key => $id];
    }

    protected static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
