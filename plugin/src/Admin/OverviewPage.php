<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Admin\Map\CallOutcome;
use Belayline\Plugin\Admin\Map\MapData;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Time;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\ChainStatus;
use Belayline\Plugin\Audit\WpdbApprovalStore;

/**
 * The Overview tab: four stat cards, the map of where agent calls went in the
 * last 7 days, and a short list of what is waiting for a person. Read-only; the
 * heavy numbers come from {@see MapData} and {@see ChainStatus}, both cached.
 */
final class OverviewPage
{
    private const CAP = 'manage_options';
    private const PREVIEW = 3;

    public function __construct(
        private readonly MapData $map,
        private readonly ChainStatus $chain,
        private readonly AuditReader $reader,
        private readonly ?WpdbApprovalStore $approvals,
        private readonly WhoResolver $who,
        private readonly ?int $now = null,
    ) {
    }

    private function now(): int
    {
        return $this->now ?? time();
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
        $now = $this->now();
        $data = $this->map->get($now);
        $pending = $this->approvals !== null ? $this->approvals->pending() : [];
        $chain = $this->chain->get($now);

        /** @var list<array{date: string, calls: int, refused: int}> $days */
        $days = is_array($data['days'] ?? null) ? $data['days'] : [];

        echo '<div class="aui-stats">';
        $this->statWaiting($pending, $now);
        $this->statCalls($days);
        $this->statRefused($days);
        $this->statChain($chain, $now);
        echo '</div>';

        $this->renderMap($data);
        $this->renderWaiting($pending, $now);
    }

    /** @param list<array<string, mixed>> $pending */
    private function statWaiting(array $pending, int $now): void
    {
        $count = count($pending);
        $oldest = null;
        foreach ($pending as $r) {
            $t = Time::parse((string) ($r['created_ts'] ?? ''));
            if ($t !== null && ($oldest === null || $t < $oldest)) {
                $oldest = $t;
            }
        }
        echo '<div class="aui-stat aui-stat--figure"><div class="aui-stat-top"><span class="aui-dot aui-dot--' . ($count > 0 ? 'warn' : 'ok') . '"></span><span class="aui-label-caps">'
            . esc_html__('Waiting for you', 'belayline-agent-governance') . '</span></div>';
        echo '<div class="aui-stat-v">' . esc_html((string) $count) . '</div><div class="aui-stat-l">';
        if ($count > 0 && $oldest !== null) {
            echo esc_html(sprintf(
                /* translators: %s: a duration such as "3 h" */
                __('Oldest waiting %s', 'belayline-agent-governance'),
                Time::span($now - $oldest)
            )) . ' &middot; <a href="' . esc_url(AdminUrls::tab(AdminUrls::PENDING)) . '">' . esc_html__('Review', 'belayline-agent-governance') . '</a>';
        } else {
            echo esc_html__('Nothing needs your approval', 'belayline-agent-governance');
        }
        echo '</div></div>';
    }

    /** @param list<array{date: string, calls: int, refused: int}> $days */
    private function statCalls(array $days): void
    {
        $series = array_map(static fn (array $d): int => $d['calls'], $days);
        $today = $series === [] ? 0 : $series[count($series) - 1];
        $yesterday = count($series) > 1 ? $series[count($series) - 2] : 0;

        echo '<div class="aui-stat aui-stat--spark"><span class="aui-label-caps">' . esc_html__('Calls today', 'belayline-agent-governance') . '</span>';
        echo '<div class="aui-stat-row"><div class="aui-stat-v">' . esc_html((string) $today) . '</div>' . self::sparkline($series) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- numbers only, built in sparkline().
        if ($yesterday > 0) {
            $pct = (int) round(($today - $yesterday) / $yesterday * 100);
            echo '<span class="aui-delta' . ($pct < 0 ? ' aui-delta--down' : '') . '">' . esc_html(sprintf(
                /* translators: %s: a signed percentage such as +12% */
                __('%s vs yesterday', 'belayline-agent-governance'),
                ($pct > 0 ? '+' : '') . $pct . '%'
            )) . '</span>';
        } else {
            echo '<span class="aui-stat-l">' . esc_html(sprintf(
                /* translators: %d: number of calls in the last 7 days */
                _n('%d call in the last 7 days', '%d calls in the last 7 days', array_sum($series), 'belayline-agent-governance'),
                array_sum($series)
            )) . '</span>';
        }
        echo '</div>';
    }

    /** @param list<array{date: string, calls: int, refused: int}> $days */
    private function statRefused(array $days): void
    {
        $refused = $days === [] ? 0 : $days[count($days) - 1]['refused'];
        echo '<div class="aui-stat aui-stat--inline"><span class="aui-tile aui-tile--bad">' . Icons::svg('x', 'aui-i--lg') . '</span><div><div class="aui-stat-v">'
            . esc_html((string) $refused) . '</div><div class="aui-stat-l">' . esc_html__('Refused today', 'belayline-agent-governance') . '</div></div></div>';
    }

    /** @param array{ok: bool, at: int, total: int} $chain */
    private function statChain(array $chain, int $now): void
    {
        $ok = $chain['ok'];
        echo '<div class="aui-stat aui-stat--status' . ($ok ? '' : ' is-bad') . '"><div class="aui-stat-head">' . Icons::svg('shield', 'aui-i--lg')
            . esc_html($ok ? __('Log intact', 'belayline-agent-governance') : __('Log altered', 'belayline-agent-governance')) . '</div>';
        echo '<div class="aui-stat-l">';
        if ($ok) {
            echo esc_html__('Verified', 'belayline-agent-governance') . ' <span class="aui-mono">' . esc_html($this->agoText($now - $chain['at'])) . '</span>';
        } else {
            echo '<a href="' . esc_url(AdminUrls::activity(AdminUrls::CALLS)) . '">' . esc_html__('A record was changed or deleted. See Activity.', 'belayline-agent-governance') . '</a>';
        }
        echo '</div></div>';
    }

    private function agoText(int $seconds): string
    {
        return $seconds < 60 ? __('just now', 'belayline-agent-governance') : sprintf(
            /* translators: %s: a duration such as "3 min" */
            __('%s ago', 'belayline-agent-governance'),
            Time::span($seconds)
        );
    }

    /** @param list<int> $series */
    private static function sparkline(array $series): string
    {
        if (count($series) < 2) {
            return '';
        }
        $max = max($series);
        $min = min($series);
        $pts = [];
        $last = [0.0, 0.0];
        foreach ($series as $i => $v) {
            $x = 2 + $i * (92 / (count($series) - 1));
            $y = 28 - ($v - $min) / max(1, $max - $min) * 24;
            $pts[] = round($x, 1) . ',' . round($y, 1);
            $last = [round($x, 1), round($y, 1)];
        }

        return '<svg class="aui-spark" viewBox="0 0 96 32" aria-hidden="true"><polyline points="' . esc_attr(implode(' ', $pts)) . '"/><circle cx="'
            . esc_attr((string) $last[0]) . '" cy="' . esc_attr((string) $last[1]) . '" r="2.5"/></svg>';
    }

    /** @param array<string, mixed> $data */
    private function renderMap(array $data): void
    {
        $total = (int) ($data['total'] ?? 0);
        /** @var array<string, list<array{id: string, label: string, sub: string, count: int, filter: ?array{key: string, value: string}}>> $lanes */
        $lanes = is_array($data['lanes'] ?? null) ? $data['lanes'] : [];

        echo '<section class="aui-card" aria-labelledby="belayline-map-t"><div class="aui-card-h"><div><h2 class="aui-card-t" id="belayline-map-t">'
            . esc_html__('Where agent calls go', 'belayline-agent-governance') . '</h2><p class="aui-card-d">';
        echo esc_html(sprintf(
            /* translators: 1: number of calls, 2: label of the period, such as "Last 7 days" */
            _n('%2$s, %1$d call. Hover or focus a box to see only its paths.', '%2$s, %1$d calls. Hover or focus a box to see only its paths.', $total, 'belayline-agent-governance'),
            $total,
            __('Last 7 days', 'belayline-agent-governance')
        ));
        echo '</p></div>';
        if ($total > 0) {
            echo '<button type="button" class="aui-switch aui-end" aria-pressed="false" data-aui-ui data-aui-views="belayline-map-table-view,belayline-map-view"><span class="aui-switch-track"></span>'
                . Icons::svg('table') . esc_html__('Show as table', 'belayline-agent-governance')
                . '<span class="aui-switch-state" data-on="' . esc_attr__('On', 'belayline-agent-governance') . '" data-off="' . esc_attr__('Off', 'belayline-agent-governance') . '">'
                . esc_html__('Off', 'belayline-agent-governance') . '</span></button>';
        }
        echo '</div><div class="aui-card-b">';

        if ($total === 0) {
            echo '<div class="aui-empty aui-empty--dashed"><span class="aui-tile">' . Icons::svg('flow', 'aui-i--lg') . '</span><h3 class="aui-card-t">'
                . esc_html__('No agent calls yet', 'belayline-agent-governance') . '</h3><p>'
                . esc_html__('Connect an AI app or start a Site Agent run to see traffic here.', 'belayline-agent-governance') . '</p></div></div></section>';

            return;
        }

        $laneMeta = [
            'who' => [__("Who's asking", 'belayline-agent-governance'), __('AI apps, people and API keys', 'belayline-agent-governance')],
            'through' => [__('Through', 'belayline-agent-governance'), __('The plugin that offered the ability', 'belayline-agent-governance')],
            'pack' => [__('Pack', 'belayline-agent-governance'), __('The rules that decided', 'belayline-agent-governance')],
            'outcome' => [__('Outcome', 'belayline-agent-governance'), __('What happened to the call', 'belayline-agent-governance')],
        ];

        echo '<div id="belayline-map-view"><div class="aui-map" id="belayline-map" data-table="belayline-map-table" data-period="' . esc_attr__('Last 7 days', 'belayline-agent-governance')
            . '" data-labels="' . esc_attr(implode('|', [
                __('Lane', 'belayline-agent-governance'),
                __('Box', 'belayline-agent-governance'),
                __('Calls', 'belayline-agent-governance'),
                __('Totals by box', 'belayline-agent-governance'),
                __('Every path', 'belayline-agent-governance'),
            ])) . '" data-activity-url="' . esc_url(AdminUrls::activity(AdminUrls::CALLS)) . '" data-paths="' . esc_attr((string) ($data['tuples'] ?? '')) . '">';
        echo '<svg class="aui-map-svg" aria-hidden="true" focusable="false"></svg>';
        foreach ($laneMeta as $lane => [$title, $desc]) {
            echo '<div class="aui-lane"><div class="aui-lane-h"><div class="aui-lane-t">' . esc_html($title) . '</div><div class="aui-lane-d">' . esc_html($desc) . '</div></div><div class="aui-lane-nodes">';
            foreach ($lanes[$lane] ?? [] as $node) {
                $this->renderNode($lane, $node);
            }
            echo '</div></div>';
        }
        echo '</div><p class="aui-map-note" role="status" aria-live="polite">' . esc_html__('Click a box to open Activity filtered to it.', 'belayline-agent-governance') . '</p>';
        echo '<p class="aui-map-note">' . esc_html__('A call that needed approval shows up as waiting, approved and ran, one count each. In shadow mode a call that would have been refused counts as refused even though it ran.', 'belayline-agent-governance') . '</p></div>';
        echo '<div id="belayline-map-table-view" hidden><div id="belayline-map-table"></div></div>';
        echo '</div></section>';
    }

    /** @param array{id: string, label: string, sub: string, count: int, filter: ?array{key: string, value: string}} $node */
    private function renderNode(string $lane, array $node): void
    {
        $dot = '';
        if ($lane === 'outcome') {
            $tone = CallOutcome::tone(substr($node['id'], 2));
            $dot = '<i class="aui-dot' . ($tone !== '' ? ' aui-dot--' . $tone : '') . '"></i>';
        }
        $label = '<b>' . esc_html($node['label']) . '</b>' . ($node['sub'] !== '' ? '<small>' . esc_html($node['sub']) . '</small>' : '');
        $filter = $node['filter'] !== null
            ? ' data-filter-key="' . esc_attr($node['filter']['key']) . '" data-filter-value="' . esc_attr($node['filter']['value']) . '"'
            : '';
        echo '<button type="button" class="aui-node" data-node="' . esc_attr($node['id']) . '" data-count="' . esc_attr((string) $node['count']) . '"' . $filter . '>' // phpcs:ignore WordPress.Security.EscapeOutput -- every value escaped above.
            . $dot . ($node['sub'] !== '' ? '<span>' . $label . '</span>' : $label) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
            . '<span class="aui-num">' . esc_html((string) $node['count']) . '</span></button>';
    }

    /** @param list<array<string, mixed>> $pending */
    private function renderWaiting(array $pending, int $now): void
    {
        $count = count($pending);
        echo '<section class="aui-card" aria-labelledby="belayline-wait-t"><div class="aui-card-h"><h2 class="aui-card-t" id="belayline-wait-t">'
            . esc_html__('Waiting for you', 'belayline-agent-governance') . '</h2>';
        if ($count > 0) {
            echo '<span class="aui-count aui-count--warn">' . esc_html((string) $count) . '</span><a class="aui-btn aui-btn--ghost aui-btn--sm aui-end" href="'
                . esc_url(AdminUrls::tab(AdminUrls::PENDING)) . '">' . esc_html__('Open Pending', 'belayline-agent-governance') . '</a>';
        }
        echo '</div>';
        if ($count === 0) {
            echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html__('Nothing is waiting. When an agent asks to do something that needs your approval, it shows up here.', 'belayline-agent-governance') . '</p></div></section>';

            return;
        }
        echo '<ul class="aui-card-b aui-card-b--flush">';
        $shown = array_slice($pending, 0, self::PREVIEW);
        $events = $this->reader->forEvents(array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['audit_event_id'] ?? ''), $shown))));
        foreach ($shown as $r) {
            $who = $this->who->describe((string) ($r['key_id'] ?? ''));
            echo '<li class="aui-item belayline-flush"><span class="aui-tile aui-tile--sm">' . Icons::svg('robot') . '</span><div class="aui-grow"><div class="aui-item-name">'
                . esc_html($who['label']) . ': ' . PendingActionsPage::summaryHtml((string) ($r['summary'] ?? '')) . '</div><div class="aui-muted">' // phpcs:ignore WordPress.Security.EscapeOutput -- summaryHtml() escapes by provenance.
                . esc_html(PendingActionsPage::heldReason($events[(string) ($r['audit_event_id'] ?? '')] ?? null)) . '</div></div><span class="aui-mono aui-muted">'
                . esc_html(sprintf(
                    /* translators: %s: "in 6 d 23 h" */
                    __('expires %s', 'belayline-agent-governance'),
                    Time::until((string) ($r['pending_expires_ts'] ?? ''), $now)
                )) . '</span><a class="aui-btn aui-btn--secondary aui-btn--sm" href="'
                . esc_url(AdminUrls::tab(AdminUrls::PENDING, [], 'approval-' . (string) ($r['approval_id'] ?? ''))) . '">' . esc_html__('Review', 'belayline-agent-governance') . '</a></li>';
        }
        echo '</ul></section>';
    }

    /** Rail: the last few things that happened, in a sentence each. */
    public function renderRail(): void
    {
        $now = $this->now();
        $rows = $this->reader->latest(30);
        $feed = [];
        foreach ($rows as $r) {
            $decision = (string) ($r['decision'] ?? '');
            if (!in_array($decision, CallOutcome::CALL_DECISIONS, true)) {
                continue;
            }
            $feed[] = $r;
            if (count($feed) === 5) {
                break;
            }
        }

        echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__('Recent activity', 'belayline-agent-governance') . '</h2></div><div class="aui-card-b">';
        if ($feed === []) {
            echo '<p class="aui-muted">' . esc_html__('No agent calls yet.', 'belayline-agent-governance') . '</p></div></section>';

            return;
        }
        echo '<ul class="aui-feed">';
        foreach ($feed as $r) {
            $decoded = json_decode((string) ($r['record_json'] ?? '{}'), true);
            $token = is_array($decoded) && isset($decoded['actor']['token_id']) && is_string($decoded['actor']['token_id']) ? $decoded['actor']['token_id'] : '';
            $outcome = CallOutcome::classify((string) $r['decision'], isset($r['result']) ? (string) $r['result'] : null) ?? CallOutcome::RAN;
            $verb = match ($outcome) {
                CallOutcome::RAN => __('ran', 'belayline-agent-governance'),
                CallOutcome::FAILED => __('tried and failed:', 'belayline-agent-governance'),
                CallOutcome::WAITING => __('is waiting to run', 'belayline-agent-governance'),
                CallOutcome::APPROVED => __('was approved for', 'belayline-agent-governance'),
                default => __('was refused:', 'belayline-agent-governance'),
            };
            $tone = CallOutcome::tone($outcome);
            echo '<li><span class="aui-dot' . ($tone !== '' ? ' aui-dot--' . esc_attr($tone) : '') . '"></span><div>'
                . esc_html($this->who->describe($token)['label'] . ' ' . $verb) . ' <code class="aui-code">' . esc_html((string) $r['ability']) . '</code><time datetime="'
                . esc_attr((string) $r['ts']) . '">' . esc_html(Time::ago((string) $r['ts'], $now)) . '</time></div></li>';
        }
        echo '</ul><p class="belayline-more"><a href="' . esc_url(AdminUrls::activity(AdminUrls::CALLS)) . '">' . esc_html__('View all activity', 'belayline-agent-governance') . '</a></p></div></section>';
    }
}
