<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Audit\AuditExporter;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Support\AuditRetention;
use Belayline\Plugin\Support\ShadowMode;

/**
 * Tools → "Agent Audit Log": a read-only wp-admin viewer for the append-only audit
 * trail. Shows a tamper-evidence banner (re-verifies the hash chain on
 * every load, from the latest retention checkpoint when the log has been pruned),
 * a paged table of recent events, a CSV export of the full log, a JSON Lines
 * export for a date range, and the audit retention setting.
 */
final class AuditLogPage
{
    private const SLUG = 'belayline-audit';
    private const PER_PAGE = 100;
    private const CAP = 'manage_options';
    private const EXPORT_ACTION = 'belayline_export_audit';
    private const EXPORT_JSONL_ACTION = 'belayline_export_audit_jsonl';
    private const RETENTION_ACTION = 'belayline_save_audit_retention';

    public function __construct(
        private readonly AuditReader $reader,
        private readonly ShadowMode $shadow = new ShadowMode(),
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'export']);
        add_action('admin_post_' . self::EXPORT_JSONL_ACTION, [$this, 'exportJsonl']);
        add_action('admin_post_' . self::RETENTION_ACTION, [$this, 'saveRetention']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Agent Audit Log', 'belayline-agent-governance'),
            __('Agent Audit Log', 'belayline-agent-governance'),
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change; the page's own links build this value.
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $total = $this->reader->total();
        $rows = $this->reader->latest(self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $intact = $this->reader->verifyChain();
        $pages = (int) ceil($total / self::PER_PAGE);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agent Audit Log', 'belayline-agent-governance') . '</h1>';

        EnvironmentLabel::render($this->shadow);

        // Tamper-evidence banner.
        if ($intact) {
            printf(
                '<div class="notice notice-success inline"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Chain intact.', 'belayline-agent-governance'),
                esc_html(sprintf(/* translators: %d event count */ __('%d hash-chained events; no tampering detected.', 'belayline-agent-governance'), $total))
            );
        } else {
            printf(
                '<div class="notice notice-error inline"><p><strong>%s</strong> %s</p></div>',
                esc_html__('TAMPER DETECTED.', 'belayline-agent-governance'),
                esc_html__('The audit chain failed verification — a record was altered or deleted.', 'belayline-agent-governance')
            );
        }

        $checkpoint = $this->reader->checkpoint();
        if ($checkpoint !== null) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: 1: number of pruned events 2: UTC time of the latest prune */
                    __('%1$d older events were pruned by the retention setting (latest prune %2$s UTC). The chain is verified from that checkpoint.', 'belayline-agent-governance'),
                    $checkpoint['pruned_count'],
                    $checkpoint['created_ts']
                ))
            );
        }

        // Export.
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '">';
        wp_nonce_field(self::EXPORT_ACTION);
        submit_button(__('Export CSV', 'belayline-agent-governance'), 'secondary', 'submit', false);
        echo '</form>';

        $this->renderJsonlExport();
        $this->renderRetentionSettings();

        // Table.
        echo '<table class="widefat striped"><thead><tr>';
        foreach (self::columnHeaders() as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="11">' . esc_html__('No events yet.', 'belayline-agent-governance') . '</td></tr>';
        }

        foreach ($rows as $r) {
            $decoded = json_decode((string) ($r['record_json'] ?? '{}'), true);
            $token = is_array($decoded) && isset($decoded['actor']['token_id']) ? (string) $decoded['actor']['token_id'] : '';
            $input = is_array($decoded) && isset($decoded['input']) ? (string) wp_json_encode($decoded['input']) : '';
            $reason = is_array($decoded) && isset($decoded['reason']) ? (string) $decoded['reason'] : '';

            echo '<tr>';
            echo '<td>' . esc_html((string) $r['id']) . '</td>';
            echo '<td>' . esc_html((string) $r['ts']) . '</td>';
            echo '<td><code>' . esc_html((string) $r['correlation_id']) . '</code></td>';
            echo '<td><code>' . esc_html((string) $r['ability']) . '</code></td>';
            echo '<td>' . esc_html($r['tier'] === null ? '—' : (string) $r['tier']) . '</td>';
            echo '<td>' . self::badge((string) $r['decision']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes internally (esc_attr()/esc_html()); see its docblock.
            echo '<td><code>' . esc_html($reason === '' ? '—' : ReasonLabels::label($reason)) . '</code></td>';
            echo '<td>' . esc_html((string) ($r['result'] ?? '—')) . '</td>';
            echo '<td>' . esc_html($token ?: '—') . '</td>';
            echo '<td>' . esc_html((string) ($r['ip'] ?? '—')) . '</td>';
            echo '<td><code style="font-size:11px;">' . esc_html(self::truncate($input, 120)) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Pagination.
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($p = 1; $p <= $pages; $p++) {
                $url = esc_url(add_query_arg(['page' => self::SLUG, 'paged' => $p], admin_url('tools.php')));
                $label = $p === $paged ? '<strong>' . $p . '</strong>' : '<a href="' . $url . '">' . $p . '</a>';
                echo '<span style="margin:0 4px;">' . $label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo '</div></div>';
        }

        echo '</div>';
    }

    /** Streams the full log as CSV and exits. */
    public function export(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::EXPORT_ACTION);

        $rows = $this->reader->all();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=agent-audit-log.csv');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to the response body, not touching the filesystem; WP_Filesystem has no notion of php://output.
        if ($out === false) {
            wp_die(esc_html__('Unable to open output stream.', 'belayline-agent-governance'));
        }
        $cols = ['id', 'event_id', 'ts', 'correlation_id', 'pack', 'ability', 'tier', 'decision', 'result', 'wp_user', 'ip', 'record_json', 'prev_hash', 'entry_hash'];
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn ($c) => $r[$c] ?? '', $cols));
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the same php://output stream opened above, not a filesystem handle.
        exit;
    }

    /** Date-range JSON Lines export form. */
    private function renderJsonlExport(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_JSONL_ACTION) . '">';
        wp_nonce_field(self::EXPORT_JSONL_ACTION);
        printf(
            '<label for="belayline_export_from">%s</label> <input type="date" id="belayline_export_from" name="from"> '
            . '<label for="belayline_export_to">%s</label> <input type="date" id="belayline_export_to" name="to"> ',
            esc_html__('From (UTC)', 'belayline-agent-governance'),
            esc_html__('To (UTC)', 'belayline-agent-governance'),
        );
        submit_button(__('Download JSON Lines', 'belayline-agent-governance'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('One JSON object per line, oldest first, starting with the retention checkpoint when the log has been pruned. Leave a date empty for no limit. Export before shortening the retention window.', 'belayline-agent-governance') . '</p>';
        echo '</form>';
    }

    /** Retention setting: days of audit history to keep, 0 = forever. */
    private function renderRetentionSettings(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::RETENTION_ACTION) . '">';
        wp_nonce_field(self::RETENTION_ACTION);
        printf(
            '<label for="belayline_audit_retention_days">%s</label> <input type="number" min="0" max="%d" id="belayline_audit_retention_days" name="belayline_audit_retention_days" value="%d" class="small-text"> ',
            esc_html__('Keep audit events for (days, 0 = forever)', 'belayline-agent-governance'),
            AuditRetention::MAX_DAYS,
            AuditRetention::days(),
        );
        submit_button(__('Save retention', 'belayline-agent-governance'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('A daily job deletes older events, oldest first, and records a checkpoint so the tamper check still works. Deleted events cannot be recovered.', 'belayline-agent-governance') . '</p>';
        echo '</form>';
    }

    /**
     * Streams a date range of the log as JSON Lines and exits. Refuses without
     * manage_options or a valid nonce.
     */
    public function exportJsonl(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::EXPORT_JSONL_ACTION);

        try {
            $from = AuditExporter::normalizeDate(isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '');
            $to = AuditExporter::normalizeDate(isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '');
        } catch (\InvalidArgumentException $e) {
            wp_die(esc_html($e->getMessage()));
        }

        nocache_headers();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename=agent-audit-log.jsonl');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download to the response body, not touching the filesystem.
        if ($out === false) {
            wp_die(esc_html__('Unable to open output stream.', 'belayline-agent-governance'));
        }
        (new AuditExporter($this->reader))->stream($from, $to, static function (string $line) use ($out): void {
            fwrite($out, $line); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- same php://output stream.
        });
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the php://output stream opened above.
        exit;
    }

    /** Validates and stores the retention submission; no redirect, so it is testable. */
    public function applyRetentionSubmission(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::RETENTION_ACTION);

        $days = AuditRetention::sanitizeDays(isset($_POST['belayline_audit_retention_days']) ? wp_unslash($_POST['belayline_audit_retention_days']) : 0);
        update_option(AuditRetention::OPTION, $days, false);
    }

    public function saveRetention(): void
    {
        $this->applyRetentionSubmission();
        wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('tools.php')));
        exit;
    }

    /** @return list<string> Translated column headers, in display order. */
    private static function columnHeaders(): array
    {
        return [
            __('ID', 'belayline-agent-governance'),
            __('Time (UTC)', 'belayline-agent-governance'),
            __('Correlation', 'belayline-agent-governance'),
            __('Ability', 'belayline-agent-governance'),
            __('Tier', 'belayline-agent-governance'),
            __('Decision', 'belayline-agent-governance'),
            __('Reason', 'belayline-agent-governance'),
            __('Result', 'belayline-agent-governance'),
            __('Token', 'belayline-agent-governance'),
            __('IP', 'belayline-agent-governance'),
            __('Input', 'belayline-agent-governance'),
        ];
    }

    private static function badge(string $decision): string
    {
        $colors = [
            'allowed'  => '#46b450',
            'denied'   => '#dc3232',
            'pending'  => '#ffb900',
            'approved' => '#46b450',
            'rejected' => '#dc3232',
            // A grant-lifecycle row (AS-12): issued/revoked/exhausted, told
            // apart by the reason column beside it.
            'grant'    => '#2271b1',
            // A human changed the plugin's configuration: shadow toggles and
            // binding changes, again told apart by the reason column.
            'admin'    => '#50575e',
            // A human undid recorded changes (0.5 undo engine).
            'restored' => '#8c5cb0',
        ];
        $color = $colors[$decision] ?? '#888';

        return '<span style="color:#fff;background:' . esc_attr($color) . ';padding:1px 6px;border-radius:3px;font-size:11px;">'
            . esc_html($decision) . '</span>';
    }

    private static function truncate(string $s, int $len): string
    {
        return strlen($s) > $len ? substr($s, 0, $len) . '…' : $s;
    }
}
