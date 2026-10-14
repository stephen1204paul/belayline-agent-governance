<?php

declare(strict_types=1);

namespace Belayline\Changes;

/** The field-level before/after view of one recorded change, shared by the admin screen and the public API. */
final class FieldDiff
{
    /**
     * One entry per changed field, in recorded order. A field absent from a
     * side (the whole object was created or deleted) reads as null there.
     *
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    public static function for(StoredChange $change): array
    {
        $diff = [];
        foreach ($change->changedFields as $field) {
            $diff[] = [
                'field' => $field,
                'before' => $change->before[$field] ?? null,
                'after' => $change->after[$field] ?? null,
            ];
        }

        return $diff;
    }
}
