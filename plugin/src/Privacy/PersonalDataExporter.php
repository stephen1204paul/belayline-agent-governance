<?php

declare(strict_types=1);

namespace Belayline\Plugin\Privacy;

use WP_User;

/**
 * `wp_privacy_personal_data_exporters` callback (§3.6 item 2): resolves the
 * requested email to a WordPress user and exports THEIR audit rows only,
 * paging through {@see PrivacyAuditReader} per WordPress's exporter paging
 * contract — keep returning `done => false` until a page comes back short of
 * {@see self::PAGE_SIZE}. No user for the email → no data, done immediately.
 */
final class PersonalDataExporter
{
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly PrivacyAuditReader $reader,
        private readonly ?PrivacyChangeReader $changes = null,
    ) {
    }

    /**
     * @return array{
     *     data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>,
     *     done: bool
     * }
     */
    public function export(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        if (!$user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }

        $page = max(1, $page);
        $rows = $this->reader->pageForUser($user->ID, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $data = [];
        foreach ($rows as $row) {
            $data[] = self::group($row);
        }

        if ($this->changes !== null) {
            foreach ($this->changes->forEvents(PrivacyChangeReader::eventIds($rows)) as $change) {
                $data[] = self::changeGroup($change);
            }
        }

        return ['data' => $data, 'done' => count($rows) < self::PAGE_SIZE];
    }

    /**
     * One export group per recorded change.
     *
     * @param array<string, mixed> $row
     * @return array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}
     */
    private static function changeGroup(array $row): array
    {
        return [
            'group_id' => 'belayline-changes',
            'group_label' => __('Belayline Agent Governance Change History', 'belayline-agent-governance'),
            'item_id' => 'belayline-changes-' . (string) ($row['id'] ?? ''),
            'data' => [
                ['name' => __('Time', 'belayline-agent-governance'), 'value' => (string) ($row['ts'] ?? '')],
                ['name' => __('Event', 'belayline-agent-governance'), 'value' => (string) ($row['event_id'] ?? '')],
                ['name' => __('Object', 'belayline-agent-governance'), 'value' => (string) ($row['object_type'] ?? '') . ' ' . (string) ($row['object_id'] ?? '')],
                ['name' => __('Action', 'belayline-agent-governance'), 'value' => (string) ($row['action'] ?? '')],
                ['name' => __('Before', 'belayline-agent-governance'), 'value' => (string) ($row['before_json'] ?? '')],
                ['name' => __('After', 'belayline-agent-governance'), 'value' => (string) ($row['after_json'] ?? '')],
            ],
        ];
    }

    /**
     * One export group per audit row: the fields useful to the requester,
     * decoded straight from `record_json` (the same source {@see
     * \Belayline\Plugin\Admin\AuditLogPage} reads for its table).
     *
     * @param array<string, mixed> $row
     * @return array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}
     */
    private static function group(array $row): array
    {
        $decoded = json_decode((string) ($row['record_json'] ?? '{}'), true);
        $record = is_array($decoded) ? $decoded : [];

        return [
            'group_id' => 'belayline-audit-log',
            'group_label' => __('Belayline Agent Governance Audit Log', 'belayline-agent-governance'),
            'item_id' => 'belayline-audit-log-' . (string) ($row['id'] ?? ''),
            'data' => [
                ['name' => __('Time', 'belayline-agent-governance'), 'value' => (string) ($record['ts'] ?? '')],
                ['name' => __('Ability', 'belayline-agent-governance'), 'value' => (string) ($record['ability'] ?? '')],
                ['name' => __('Tier', 'belayline-agent-governance'), 'value' => isset($record['tier']) ? (string) $record['tier'] : ''],
                ['name' => __('Decision', 'belayline-agent-governance'), 'value' => (string) ($record['decision'] ?? '')],
                ['name' => __('Reason', 'belayline-agent-governance'), 'value' => (string) ($record['reason'] ?? '')],
                ['name' => __('IP Address', 'belayline-agent-governance'), 'value' => (string) ($record['ip'] ?? '')],
                ['name' => __('Recorded Input', 'belayline-agent-governance'), 'value' => (string) wp_json_encode($record['input'] ?? [])],
            ],
        ];
    }
}
