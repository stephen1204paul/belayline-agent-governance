<?php

declare(strict_types=1);

namespace Belayline\Plugin\Privacy;

use WP_User;

/**
 * `wp_privacy_personal_data_erasers` callback (§3.6 item 2): never touches the
 * audit log. The audit log is an append-only hash chain — {@see
 * \Belayline\Audit\HashChain} links each row's `entry_hash` from
 * the one before it, so rewriting or dropping a row to satisfy an erasure
 * request would break that chain for every row recorded after it. A matching
 * user's rows are instead reported `items_retained`, with the reason stated
 * back to the requester, per the same paging contract as {@see
 * PersonalDataExporter}.
 *
 * The change history ({@see PrivacyChangeReader}) IS erased: its snapshot
 * bodies can hold customer data, and they sit outside the chain — each audit
 * row commits only to their `changes_sha256`, which survives the deletion.
 * Each page deletes the change rows of that page's audit events; the audit
 * rows themselves are never removed, so offset paging stays stable.
 */
final class PersonalDataEraser
{
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly PrivacyAuditReader $reader,
        private readonly ?PrivacyChangeReader $changes = null,
    ) {
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        if (!$user instanceof WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $page = max(1, $page);
        $rows = $this->reader->pageForUser($user->ID, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        if ($rows === []) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $removed = $this->changes?->deleteForEvents(PrivacyChangeReader::eventIds($rows)) ?? 0;

        $messages = [
            __(
                'Belayline Agent Governance audit records for this user are kept as a tamper-evident security record: each entry is chained to the one before it by hash, so altering or removing one would break that chain for every entry recorded after it.',
                'belayline-agent-governance'
            ),
        ];
        if ($removed > 0) {
            $messages[] = __(
                'Belayline Agent Governance change history (the before and after snapshots recorded for this user\'s calls) was deleted. The audit records keep only a hash of each change set.',
                'belayline-agent-governance'
            );
        }

        return [
            'items_removed' => $removed > 0,
            'items_retained' => true,
            'messages' => $messages,
            'done' => count($rows) < self::PAGE_SIZE,
        ];
    }
}
