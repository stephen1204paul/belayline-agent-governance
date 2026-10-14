<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

/**
 * `upsert-customer` identifies its target by `customer_id` or by `email`
 * (CustomerTools.php:170-178). Reads `{prefix}fct_customers`: `email`, `status`,
 * `user_id` (the WordPress identity link) and `updated_at`. With only an email,
 * the row is looked up by `email`; no match means the call would create, which
 * is a constant `['target' => 'new']` (not a refusal). A `customer_id` that does
 * not exist is null (`state_unverifiable`; the ability would error anyway).
 */
final class FluentCartCustomerStateProbe extends FluentCartRowProbe
{
    protected function table(): string
    {
        return 'fct_customers';
    }

    protected function selectByIdSql(): string
    {
        return 'SELECT `email`, `status`, `user_id`, `updated_at` FROM %i WHERE `id` = %d LIMIT 1';
    }

    protected function idArg(string $verb): string
    {
        return 'customer_id';
    }

    public function read(string $verb, array $args): ?array
    {
        if (self::positiveInt($args['customer_id'] ?? null) !== null) {
            return $this->row($verb, $args);
        }

        $email = $this->emailArg($args);
        if ($email === null) {
            return null;
        }

        $sql = $this->db->prepare(
            'SELECT `email`, `status`, `user_id`, `updated_at` FROM %i WHERE `email` = %s LIMIT 1',
            $this->db->prefix . $this->table(),
            $email,
        );
        $row = $this->db->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : ['target' => 'new'];
    }

    public function targetArgs(string $verb, array $args): array
    {
        $id = self::positiveInt($args['customer_id'] ?? null);
        if ($id !== null) {
            return ['customer_id' => $id];
        }
        $email = $this->emailArg($args);

        return $email === null ? [] : ['email' => $email];
    }

    /** @param array<string, mixed> $args */
    private function emailArg(array $args): ?string
    {
        $email = $args['email'] ?? null;

        return is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;
    }
}
