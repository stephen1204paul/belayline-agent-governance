<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Admin\Map\CallOutcome;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\Map\PackLabel;
use Belayline\Plugin\Admin\Ui\AbilityLabel;
use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Time;
use Belayline\Plugin\Audit\AuditExporter;
use Belayline\Plugin\Audit\AuditFilter;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\ChainStatus;
use Belayline\Plugin\Support\AuditRetention;

/**
 * Activity > Calls: a read-only wp-admin viewer for the append-only audit trail.
 * Shows whether the hash chain still verifies (checked from the latest retention
 * checkpoint when the log has been pruned, and remembered for five minutes), a
 * filterable, paged table of events, a CSV export of the full log and a JSON
 * Lines export for a date range. The retention setting renders from here too
 * ({@see renderRetentionCard()}) but lives on the Settings tab.
 */
final class AuditLogPage
{
    private const PER_PAGE = 50;
    private const CAP = 'manage_options';
    private const EXPORT_ACTION = 'belayline_export_audit';
    private const EXPORT_JSONL_ACTION = 'belayline_export_audit_jsonl';
    private const RETENTION_ACTION = 'belayline_save_audit_retention';
    private const VERIFY = 'belayline_verify_chain';

    public function __construct(
        private readonly AuditReader $reader,
        private readonly ?ChainStatus $chain = null,
        private readonly ?WhoResolver $who = null,
        private readonly ?int $now = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'export']);
        add_action('admin_post_' . self::EXPORT_JSONL_ACTION, [$this, 'exportJsonl']);
        add_action('admin_post_' . self::RETENTION_ACTION, [$this, 'saveRetention']);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $this->renderMain();
    }

    public function renderMain(): void
    {
        $now = $this->now ?? time();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and pagination, no state change; the page's own links build these values.
        $get = wp_unslash($_GET);
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $filter = AuditFilter::fromRequest($get);

        $total = $this->reader->countFiltered($filter, $now);
        $rows = $this->reader->filtered($filter, self::PER_PAGE, ($paged - 1) * self::PER_PAGE, $now);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        echo '<p class="aui-page-intro">' . esc_html__('Everything agents did, tried or were refused, newest first. Each entry is chained to the one before it, so an edit or a deletion shows.', 'belayline-agent-governance') . '</p>';
        $this->renderChain($now);

        echo '<section class="aui-card" aria-labelledby="belayline-calls-t">';
        echo '<div class="aui-card-h"><div><h2 class="aui-card-t" id="belayline-calls-t">' . esc_html__('Calls', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html(sprintf(
                /* translators: %d: number of matching log entries */
                _n('%d entry', '%d entries', $total, 'belayline-agent-governance'),
                $total
            )) . '</p></div></div>';
        $this->renderFilters($filter);

        if (!$rows) {
            echo wp_kses('<div class="aui-empty"><span class="aui-tile">' . Icons::svg('file-text', 'aui-i--lg') . '</span><h3 class="aui-card-t">'
                . esc_html($filter->isEmpty() ? __('No activity yet', 'belayline-agent-governance') : __('Nothing matches these filters', 'belayline-agent-governance')) . '</h3><p>'
                . esc_html($filter->isEmpty()
                    ? __('Every call an agent makes will be listed here.', 'belayline-agent-governance')
                    : __('Try a wider period or clear the filters.', 'belayline-agent-governance'))
                . '</p></div></section>', Html::allowed());
            $this->renderExport();

            return;
        }

        echo '<div class="aui-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr__('Agent calls', 'belayline-agent-governance') . '"><table class="aui-table"><caption class="aui-sr">' . esc_html__('Agent calls', 'belayline-agent-governance') . '</caption><thead><tr>';
        foreach (self::columnHeaders() as $col) {
            echo '<th scope="col">' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $this->renderRow($r);
        }
        echo '</tbody></table></div>';
        $this->renderPager($paged, $pages, $get);
        echo '</section>';
        $this->renderExport();
    }

    /** The chain card: cached verification, with a way to check right now. */
    private function renderChain(int $now): void
    {
        $status = $this->chain !== null
            ? $this->chain->get($now, $this->verifyRequested())
            : ['ok' => $this->reader->verifyChain(), 'at' => $now, 'total' => $this->reader->total()];

        if ($status['ok']) {
            $when = $now - $status['at'] < 60 ? __('just now', 'belayline-agent-governance') : sprintf(
                /* translators: %s: a duration such as "3 min" */
                __('%s ago', 'belayline-agent-governance'),
                Time::span($now - $status['at'])
            );
            echo wp_kses('<div class="aui-notice aui-notice--success" role="status">' . Icons::svg('check') . '<div><strong>' . esc_html__('Log intact.', 'belayline-agent-governance') . '</strong> '
                . esc_html(sprintf(
                    /* translators: 1: number of entries, 2: when it was last checked ("3 min ago") */
                    _n('%1$d entry, no tampering found. Checked %2$s.', '%1$d entries, no tampering found. Checked %2$s.', $status['total'], 'belayline-agent-governance'),
                    $status['total'],
                    $when
                )), Html::allowed());
            if ($this->chain !== null) {
                echo ' <a href="' . esc_url(wp_nonce_url(AdminUrls::activity(AdminUrls::CALLS, ['belayline_verify' => '1']), self::VERIFY)) . '">'
                    . esc_html__('Check now', 'belayline-agent-governance') . '</a>';
            }
            echo '</div></div>';
        } else {
            echo wp_kses('<div class="aui-notice aui-notice--error" role="alert">' . Icons::svg('x') . '<div><strong>' . esc_html__('The log was altered.', 'belayline-agent-governance') . '</strong> '
                . esc_html__('A record was changed or deleted, so the chain no longer checks out. Export it now and investigate.', 'belayline-agent-governance') . '</div></div>', Html::allowed());
        }

        $checkpoint = $this->reader->checkpoint();
        if ($checkpoint !== null) {
            echo '<p class="aui-muted">' . esc_html(sprintf(
                /* translators: 1: number of pruned events 2: UTC time of the latest prune */
                __('%1$d older entries were removed by your retention setting (latest on %2$s UTC). The chain is checked from that point.', 'belayline-agent-governance'),
                $checkpoint['pruned_count'],
                $checkpoint['created_ts']
            )) . '</p>';
        }
    }

    private function verifyRequested(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below, before anything is re-verified.
        if (!isset($_GET['belayline_verify'])) {
            return false;
        }
        check_admin_referer(self::VERIFY);

        return true;
    }

    private function renderFilters(AuditFilter $f): void
    {
        $packs = $this->reader->packs();
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="aui-toolbar belayline-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(AdminUrls::SLUGS[AdminUrls::ACTIVITY]) . '"><input type="hidden" name="section" value="' . esc_attr(AdminUrls::CALLS) . '">';
        if ($f->tokens !== []) {
            echo '<input type="hidden" name="token" value="' . esc_attr(implode(',', $f->tokens)) . '">';
        }
        foreach (['token_prefix' => $f->tokenPrefix, 'through' => $f->through] as $name => $value) {
            if ($value !== '') {
                echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
            }
        }

        echo '<div class="aui-field"><label class="aui-label" for="belayline-f-outcome">' . esc_html__('Outcome', 'belayline-agent-governance') . '</label><select class="aui-select" id="belayline-f-outcome" name="outcome">';
        echo '<option value="">' . esc_html__('All outcomes', 'belayline-agent-governance') . '</option>';
        foreach (CallOutcome::ALL as $o) {
            echo '<option value="' . esc_attr($o) . '"' . selected($f->outcome, $o, false) . '>' . esc_html(CallOutcome::label($o)) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="aui-field"><label class="aui-label" for="belayline-f-pack">' . esc_html__('Pack', 'belayline-agent-governance') . '</label><select class="aui-select" id="belayline-f-pack" name="pack">';
        echo '<option value="">' . esc_html__('All packs', 'belayline-agent-governance') . '</option>';
        foreach ($packs as $pack) {
            echo '<option value="' . esc_attr($pack) . '"' . selected($f->pack, $pack, false) . '>' . esc_html(PackLabel::of($pack)) . '</option>';
        }
        echo '<option value="__none"' . selected($f->pack, '__none', false) . '>' . esc_html__('No pack', 'belayline-agent-governance') . '</option></select></div>';

        echo '<div class="aui-field"><label class="aui-label" for="belayline-f-days">' . esc_html__('Period', 'belayline-agent-governance') . '</label><select class="aui-select" id="belayline-f-days" name="days">';
        foreach ([0 => __('Any time', 'belayline-agent-governance'), 1 => __('Last 24 hours', 'belayline-agent-governance'), 7 => __('Last 7 days', 'belayline-agent-governance'), 30 => __('Last 30 days', 'belayline-agent-governance')] as $days => $label) {
            echo '<option value="' . esc_attr((string) $days) . '"' . selected($f->days, $days, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';

        echo '<button type="submit" class="aui-btn aui-btn--secondary">' . esc_html__('Filter', 'belayline-agent-governance') . '</button>';
        if (!$f->isEmpty()) {
            echo '<a class="aui-btn aui-btn--ghost" href="' . esc_url(AdminUrls::activity(AdminUrls::CALLS)) . '">' . esc_html__('Clear filters', 'belayline-agent-governance') . '</a>';
        }
        if ($f->tokens !== [] || $f->tokenPrefix !== '' || $f->through !== '') {
            echo '<span class="aui-end belayline-chips">';
            if ($f->tokens !== []) {
                $names = array_map(fn (string $t): string => $this->who?->label($t) ?? $t, $f->tokens);
                echo wp_kses(Html::badge('info', sprintf(/* translators: %s: who */ __('Who: %s', 'belayline-agent-governance'), implode(', ', array_unique($names)))), Html::allowed());
            }
            if ($f->tokenPrefix !== '') {
                echo wp_kses(Html::badge('info', __('Who: logged-in people', 'belayline-agent-governance')), Html::allowed());
            }
            if ($f->through !== '') {
                echo wp_kses(Html::badge('info', sprintf(/* translators: %s: plugin namespace */ __('Through: %s', 'belayline-agent-governance'), $f->through)), Html::allowed());
            }
            echo '</span>';
        }
        echo '</form>';
    }

    /** @param array<string, mixed> $r */
    private function renderRow(array $r): void
    {
        $decoded = json_decode((string) ($r['record_json'] ?? '{}'), true);
        $token = is_array($decoded) && isset($decoded['actor']['token_id']) ? (string) $decoded['actor']['token_id'] : '';
        $input = is_array($decoded) && isset($decoded['input']) ? (string) wp_json_encode($decoded['input']) : '';
        $reason = is_array($decoded) && isset($decoded['reason']) ? (string) $decoded['reason'] : '';
        $dryRun = is_array($decoded) && !empty($decoded['dry_run']);
        $who = $this->who !== null ? $this->who->label($token) : ($token !== '' ? $token : '—');

        echo '<tr>';
        $stamp = str_replace(['T', 'Z'], [' ', ''], (string) $r['ts']);
        echo '<td class="aui-mono belayline-nowrap" title="' . esc_attr($stamp . ' UTC') . '">' . esc_html(substr($stamp, 5)) . '</td>';
        echo '<td>' . esc_html($who) . '</td>';
        echo wp_kses('<td>' . AbilityLabel::html((string) $r['ability'])
            . ''
            . ($input !== '' && $input !== '[]' ? '<div class="aui-mono belayline-input" title="' . esc_attr(self::truncate($input, 400)) . '">' . esc_html(self::truncate($input, 70)) . '</div>' : '') . '</td>', Html::allowed());
        echo wp_kses('<td>' . self::badge((string) $r['decision'], isset($r['result']) ? (string) $r['result'] : null) . ($dryRun ? ' ' . Html::badge('', __('Shadow', 'belayline-agent-governance')) : '') . '</td>', Html::allowed());
        echo '<td>';
        if ($reason === '') {
            echo '—';
        } else {
            $label = ReasonLabels::label($reason);
            echo esc_html($label);
            if ($label !== $reason) {
                echo ' <code class="aui-code aui-muted">' . esc_html($reason) . '</code>';
            }
        }
        echo '</td>';
        echo '<td class="aui-mono">' . esc_html((string) ($r['ip'] ?? '—')) . '</td>';
        echo '</tr>';
    }

    /** @param array<string, mixed> $get */
    private function renderPager(int $paged, int $pages, array $get): void
    {
        if ($pages <= 1) {
            return;
        }
        $keep = [];
        foreach (['outcome', 'pack', 'through', 'token', 'token_prefix', 'days'] as $k) {
            if (isset($get[$k]) && is_scalar($get[$k]) && (string) $get[$k] !== '') {
                $keep[$k] = (string) $get[$k];
            }
        }
        echo '<div class="aui-card-f belayline-pager"><span class="aui-muted">' . esc_html(sprintf(
            /* translators: 1: current page, 2: number of pages */
            __('Page %1$d of %2$d', 'belayline-agent-governance'),
            $paged,
            $pages
        )) . '</span><span class="aui-end aui-row-flex">';
        if ($paged > 1) {
            echo '<a class="aui-btn aui-btn--secondary aui-btn--sm" href="' . esc_url(AdminUrls::activity(AdminUrls::CALLS, $keep + ['paged' => $paged - 1])) . '">' . esc_html__('Newer', 'belayline-agent-governance') . '</a>';
        }
        if ($paged < $pages) {
            echo '<a class="aui-btn aui-btn--secondary aui-btn--sm" href="' . esc_url(AdminUrls::activity(AdminUrls::CALLS, $keep + ['paged' => $paged + 1])) . '">' . esc_html__('Older', 'belayline-agent-governance') . '</a>';
        }
        echo '</span></div>';
    }

    /** Both exports in one card: the whole log as CSV, or a date range as JSON Lines. */
    private function renderExport(): void
    {
        echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__('Export', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('Take a copy of the log, for your records or another system. Export before you shorten the retention period in Settings.', 'belayline-agent-governance') . '</p></div></div><div class="aui-card-b belayline-stack">';

        echo wp_kses(Html::postForm(self::EXPORT_ACTION, self::EXPORT_ACTION, '', 'class="aui-row-flex"'), Html::allowed());
        echo wp_kses('<button type="submit" class="aui-btn aui-btn--secondary">' . Icons::svg('file-text') . esc_html__('Export everything as CSV', 'belayline-agent-governance') . '</button></form>', Html::allowed());

        echo wp_kses(Html::postForm(self::EXPORT_JSONL_ACTION, self::EXPORT_JSONL_ACTION, '', 'class="aui-toolbar belayline-range"'), Html::allowed());
        printf(
            '<div class="aui-field"><label class="aui-label" for="belayline_export_from">%s</label><input class="aui-input" type="date" id="belayline_export_from" name="from"></div>'
            . '<div class="aui-field"><label class="aui-label" for="belayline_export_to">%s</label><input class="aui-input" type="date" id="belayline_export_to" name="to"></div>',
            esc_html__('From (UTC)', 'belayline-agent-governance'),
            esc_html__('To (UTC)', 'belayline-agent-governance'),
        );
        echo wp_kses('<button type="submit" class="aui-btn aui-btn--secondary">' . Icons::svg('file-text') . esc_html__('Download JSON Lines', 'belayline-agent-governance') . '</button></form>', Html::allowed());
        echo '<p class="aui-help">' . esc_html__('One JSON entry per line, oldest first. Leave a date empty for no limit.', 'belayline-agent-governance') . '</p>';
        echo '</div></section>';
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

    /** Settings card: how many days of activity to keep, 0 = forever. */
    public function renderRetentionCard(): void
    {
        echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__('How long to keep activity', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('A daily job deletes older entries, oldest first, and leaves a marker so the tamper check still works. Deleted entries cannot be recovered.', 'belayline-agent-governance') . '</p></div></div>';
        echo wp_kses(Html::postForm(self::RETENTION_ACTION, self::RETENTION_ACTION, Html::backTo(AdminUrls::SETTINGS)), Html::allowed());
        printf(
            '<div class="aui-card-b"><div class="aui-field"><label class="aui-label" for="belayline_audit_retention_days">%s</label><input class="aui-input belayline-narrow" type="number" min="0" max="%d" id="belayline_audit_retention_days" name="belayline_audit_retention_days" value="%d"><p class="aui-help">%s <a href="%s">%s</a></p></div></div>',
            esc_html__('Keep activity for this many days (0 keeps everything)', 'belayline-agent-governance'),
            absint(AuditRetention::MAX_DAYS),
            absint(AuditRetention::days()),
            esc_html__('Export first if you may need older entries.', 'belayline-agent-governance'),
            esc_url(AdminUrls::activity(AdminUrls::CALLS)),
            esc_html__('Go to Export', 'belayline-agent-governance'),
        );
        echo '<div class="aui-card-f"><button type="submit" class="aui-btn aui-btn--secondary">' . esc_html__('Save retention', 'belayline-agent-governance') . '</button></div></form></section>';
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

        $days = AuditRetention::sanitizeDays(isset($_POST['belayline_audit_retention_days']) ? sanitize_text_field(wp_unslash((string) $_POST['belayline_audit_retention_days'])) : 0);
        update_option(AuditRetention::OPTION, $days, false);
    }

    public function saveRetention(): void
    {
        $this->applyRetentionSubmission();
        wp_safe_redirect(AdminUrls::tab(AdminUrls::SETTINGS, ['belayline_saved' => 'retention']));
        exit;
    }

    /** @return list<string> Translated column headers, in display order. */
    private static function columnHeaders(): array
    {
        return [
            __('Time (UTC)', 'belayline-agent-governance'),
            __('Who', 'belayline-agent-governance'),
            __('Ability', 'belayline-agent-governance'),
            __('Decision', 'belayline-agent-governance'),
            __('Reason', 'belayline-agent-governance'),
            __('IP', 'belayline-agent-governance'),
        ];
    }

    /**
     * The decision as a kit badge, in plain words. The raw value stays in the
     * tooltip (and in the CSV export), so nothing is lost.
     */
    private static function badge(string $decision, ?string $result): string
    {
        [$tone, $text] = match ($decision) {
            'allowed' => ($result === null || $result === '' || $result === 'success')
                ? ['ok', __('Ran', 'belayline-agent-governance')]
                : ['', __('Failed', 'belayline-agent-governance')],
            'denied' => ['bad', __('Refused', 'belayline-agent-governance')],
            'rejected' => ['bad', __('Denied by a person', 'belayline-agent-governance')],
            'pending' => ['warn', __('Waiting', 'belayline-agent-governance')],
            'approved' => ['info', __('Approved', 'belayline-agent-governance')],
            // A grant-lifecycle row (AS-12): issued/revoked/exhausted, told apart by the reason column.
            'grant' => ['', __('Grant', 'belayline-agent-governance')],
            // A person changed Belayline's own settings; the reason column says which.
            'admin' => ['', __('Setting changed', 'belayline-agent-governance')],
            // A person undid recorded changes (0.5 undo engine).
            'restored' => ['info', __('Undone', 'belayline-agent-governance')],
            'stale' => ['warn', __('Out of date', 'belayline-agent-governance')],
            default => ['', $decision],
        };

        return '<span title="' . esc_attr($decision . ($result !== null && $result !== '' ? ': ' . $result : '')) . '">' . Html::badge($tone, $text) . '</span>';
    }

    private static function truncate(string $s, int $len): string
    {
        return strlen($s) > $len ? substr($s, 0, $len) . '…' : $s;
    }
}
