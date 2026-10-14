<?php

declare(strict_types=1);

namespace Belayline\Changes;

use Belayline\Audit\Redactor;

/**
 * One recorded change to one object, with its before/after snapshots already
 * made safe to store: secrets hashed, PII masked per pack policy, oversized
 * bodies replaced by a marker. Built only through {@see capture()}, so a
 * snapshot can never reach storage unprocessed.
 *
 * `restorable` is a bool plus a reason code. Anything that was altered on its
 * way into storage (hashed, masked, truncated, unencodable) or that cannot be
 * undone by nature is NOT restorable: an undo must never write back a value
 * this class had to replace. Pure by design (no WordPress calls, no clock), so
 * the same inputs always produce the same {@see toRow()} and therefore the same
 * {@see ChangeSet::hash()}.
 */
final class Change
{
    /** Per-body cap in bytes of encoded JSON (256 KB). */
    public const MAX_BODY_BYTES = 262144;

    public const REASON_IRREVERSIBLE = 'irreversible';
    public const REASON_SECRET = 'secret';
    public const REASON_REDACTED = 'redacted';
    public const REASON_TRUNCATED = 'truncated';
    public const REASON_UNENCODABLE = 'unencodable';

    /** Key fragments (lower-cased) whose non-empty values are hashed, never stored. */
    private const SECRET_KEYS = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'hash'];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @param list<string> $changedFields */
    private function __construct(
        public readonly string $objectType,
        public readonly string $objectId,
        public readonly ?string $objectSubtype,
        public readonly ChangeAction $action,
        public readonly ?string $beforeJson,
        public readonly ?string $afterJson,
        public readonly array $changedFields,
        public readonly bool $restorable,
        public readonly ?string $reason,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before Null for a creation.
     * @param array<string, mixed>|null $after  Null for a deletion.
     */
    public static function capture(
        string $objectType,
        string $objectId,
        ?string $objectSubtype,
        ChangeAction $action,
        ?array $before,
        ?array $after,
        bool $redactPii = false,
    ): self {
        // Changed fields are computed on the RAW values: a hashed secret must
        // still show as changed, and masked PII must not hide a real edit.
        $fields = self::changedFields($before, $after);

        $reason = $action === ChangeAction::Irreversible ? self::REASON_IRREVERSIBLE : null;

        $secret = false;
        $before = $before === null ? null : self::hashSecrets($before, $secret);
        $after = $after === null ? null : self::hashSecrets($after, $secret);
        $reason ??= $secret ? self::REASON_SECRET : null;

        if ($redactPii) {
            $maskedBefore = $before === null ? null : Redactor::apply($before, true);
            $maskedAfter = $after === null ? null : Redactor::apply($after, true);
            if ($maskedBefore !== $before || $maskedAfter !== $after) {
                $reason ??= self::REASON_REDACTED;
            }
            $before = $maskedBefore;
            $after = $maskedAfter;
        }

        $beforeJson = self::encode($before, $reason);
        $afterJson = self::encode($after, $reason);

        // Clamped to the column widths so the stored row and its hash agree.
        $objectType = substr($objectType, 0, 40);
        $objectId = substr($objectId, 0, 191);
        $objectSubtype = $objectSubtype === null ? null : substr($objectSubtype, 0, 191);

        return new self($objectType, $objectId, $objectSubtype, $action, $beforeJson, $afterJson, $fields, $reason === null, $reason);
    }

    /**
     * The storable shape of this change at position `$seq` (1-based) in its
     * event. This exact array is what {@see ChangeSet::hash()} commits to.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $seq): array
    {
        return [
            'seq' => $seq,
            'object_type' => $this->objectType,
            'object_id' => $this->objectId,
            'object_subtype' => $this->objectSubtype,
            'action' => $this->action->value,
            'before_json' => $this->beforeJson,
            'after_json' => $this->afterJson,
            'changed_fields' => (string) json_encode($this->changedFields, self::JSON_FLAGS),
            'restorable' => $this->restorable ? 1 : 0,
            'restorable_reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    private static function changedFields(?array $before, ?array $after): array
    {
        $keys = array_keys(($before ?? []) + ($after ?? []));
        $fields = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (!array_key_exists($key, $before ?? []) || !array_key_exists($key, $after ?? [])) {
                $fields[] = $key;
                continue;
            }
            if (!self::same($before[$key], $after[$key])) {
                $fields[] = $key;
            }
        }

        return $fields;
    }

    /**
     * Scalars compare as strings (a DB row hands back '5' where code passes
     * 5; that is not a change); anything structured compares by its JSON.
     *
     * @param mixed $a
     * @param mixed $b
     */
    private static function same($a, $b): bool
    {
        return self::valuesEqual($a, $b);
    }

    /**
     * The comparison used both to decide a field changed and to decide it
     * drifted: scalars as strings, structures by their JSON.
     *
     * @param mixed $a
     * @param mixed $b
     */
    public static function valuesEqual($a, $b): bool
    {
        if ((is_scalar($a) || $a === null) && (is_scalar($b) || $b === null)) {
            return (string) $a === (string) $b;
        }

        return json_encode($a, self::JSON_FLAGS) === json_encode($b, self::JSON_FLAGS);
    }

    /**
     * Replace every non-empty value under a secret-looking key with its
     * sha256. Empty values are left alone: a post with no password has
     * nothing to protect, and flagging it would make every post change
     * unrestorable.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function hashSecrets(array $data, bool &$found): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::hashSecrets($value, $found);
                continue;
            }
            if (is_string($key) && self::isSecretKey($key) && $value !== '' && $value !== null && $value !== 0 && $value !== false) {
                $data[$key] = 'sha256:' . hash('sha256', (string) json_encode($value, self::JSON_FLAGS));
                $found = true;
            }
        }

        return $data;
    }

    private static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        if ($key === 'key' || str_ends_with($key, '_key')) {
            return true;
        }
        foreach (self::SECRET_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $data
     * @param-out string|null $reason
     */
    private static function encode(?array $data, ?string &$reason): ?string
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, self::JSON_FLAGS);
        if ($json === false) {
            // Invalid UTF-8 and the like: store a lossy copy for the history,
            // but it can never be written back.
            $json = json_encode($data, self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $reason ??= self::REASON_UNENCODABLE;
            if ($json === false) {
                return '{}';
            }
        }

        if (strlen($json) > self::MAX_BODY_BYTES) {
            $reason ??= self::REASON_TRUNCATED;

            return (string) json_encode(
                ['_truncated' => true, 'bytes' => strlen($json), 'sha256' => hash('sha256', $json)],
                self::JSON_FLAGS,
            );
        }

        return $json;
    }
}
