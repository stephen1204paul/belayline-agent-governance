<?php

declare(strict_types=1);

namespace Belayline\Changes;

/**
 * The drift check: an undo applies only while the object's current state
 * still equals the recorded `after` on the fields the change touched. Pure.
 */
final class Drift
{
    /**
     * @param array<string, mixed> $current  Live state (never null: a missing object is handled by the caller).
     * @param list<string>         $volatile Fields excluded from the check.
     * @return list<array{field: string, before: mixed, after: mixed, current: mixed}> Three-way diff; empty = no drift.
     */
    public static function compare(StoredChange $change, array $current, array $volatile = []): array
    {
        $drift = [];
        foreach ($change->changedFields as $field) {
            if (in_array($field, $volatile, true)) {
                continue;
            }
            $after = $change->after[$field] ?? null;
            $now = $current[$field] ?? null;
            if (!Change::valuesEqual($after, $now)) {
                $drift[] = [
                    'field' => $field,
                    'before' => $change->before[$field] ?? null,
                    'after' => $after,
                    'current' => $now,
                ];
            }
        }

        return $drift;
    }
}
