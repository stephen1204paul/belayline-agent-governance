<?php

declare(strict_types=1);

namespace Belayline\Audit;

/**
 * Tamper-evident hash chain for the append-only audit log. Each entry
 * commits to the previous entry's hash, so altering or deleting any record
 * breaks every link after it.
 *
 * Pure and storage-agnostic: the {@see AuditSink} supplies the previous hash and
 * persists the (prev_hash, canonical_json, entry_hash) triple; this class only
 * computes and verifies. Verification re-hashes the bytes that were stored, so it
 * does not depend on JSON key order being reproducible across processes.
 */
final class HashChain
{
    /** First link's predecessor. 64 hex zeros = "no prior entry". */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** entry_hash = sha256(prev_hash . "\n" . canonical_json). */
    public static function entryHash(string $prevHash, string $canonicalJson): string
    {
        return hash('sha256', $prevHash . "\n" . $canonicalJson);
    }

    /**
     * Verify an ordered list of entries forms an unbroken chain from $anchor.
     *
     * $anchor defaults to GENESIS (a log that has never been pruned). After a
     * retention prune it is the checkpoint's `pruned_through_entry_hash`: the
     * hash of the last deleted entry, which the first remaining entry must name
     * as its prev_hash. Pass the last entry_hash of one batch as the next
     * batch's anchor to verify a long log without holding it in memory.
     *
     * @param list<array{prev_hash: string, canonical_json: string, entry_hash: string}> $entries
     */
    public static function verify(array $entries, string $anchor = self::GENESIS): bool
    {
        $prev = $anchor;
        foreach ($entries as $entry) {
            if ($entry['prev_hash'] !== $prev) {
                return false; // a record was deleted or reordered
            }
            if (self::entryHash($entry['prev_hash'], $entry['canonical_json']) !== $entry['entry_hash']) {
                return false; // a record's contents were altered
            }
            $prev = $entry['entry_hash'];
        }

        return true;
    }
}
