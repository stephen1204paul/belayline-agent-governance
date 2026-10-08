<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Changes\ChangeLedger;
use Specflux\AgentSafety\Changes\FieldDiff;
use Specflux\AgentSafety\Changes\StoredChange;
use Specflux\AgentSafety\Changes\UndoItem;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\AgentSafety\Changes\UndoScope;
use Specflux\AgentSafety\Plugin\Audit\AuditReader;
use Specflux\AgentSafety\Plugin\Changes\UndoService;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;

/**
 * Tools -> "Agent Changes": what governed agent calls wrote, grouped by run
 * (correlation id) and then audit event, with a field-level before/after diff,
 * and the human-only undo flow of SPEC-SENROGATE-0.5 section 3.2: preview the
 * scope, confirm, apply. Server-rendered like the other admin screens.
 *
 * The page gates on `manage_options`; the per-object capability (for example
 * `manage_woocommerce`) and the agent-context refusal are enforced by
 * {@see UndoService} itself. Every state-changing request carries a nonce.
 * "No changes recorded" is never presented as "nothing changed": raw SQL and
 * unlisted object types are not captured.
 */
final class ChangesPage
{
    public const SLUG = 'agent-safety-changes';
    public const UNDO = 'agsafe_undo';
    public const PREVIEW = 'agsafe_undo_preview';
    public const RESULT = 'agsafe_undo_result_';
    private const CAP = 'manage_options';
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ChangeLedger $ledger,
        private readonly UndoService $undo,
        private readonly AuditReader $audit,
        private readonly ShadowMode $shadow = new ShadowMode(),
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::UNDO, [$this, 'undo']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Agent Changes', 'senrogate'),
            __('Agent Changes', 'senrogate'),
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

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agent Changes', 'senrogate') . '</h1>';
        echo '<p>' . esc_html__('What governed agent calls changed on this site. "No changes recorded" does not mean nothing changed: direct database writes and unsupported object types are not captured. Undo is for people only; no agent can run it.', 'senrogate') . '</p>';
        EnvironmentLabel::render($this->shadow);
        $this->maybeShowResult();

        $scope = $this->requestedPreviewScope();
        if ($scope !== null) {
            $this->renderPreview($scope);
        } else {
            $this->renderList();
        }
        echo '</div>';
    }

    private function renderList(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $runs = $this->ledger->recentCorrelations(self::PER_PAGE + 1, ($paged - 1) * self::PER_PAGE);
        $more = count($runs) > self::PER_PAGE;
        $runs = array_slice($runs, 0, self::PER_PAGE);

        if ($runs === []) {
            echo '<p>' . esc_html__('No changes recorded yet.', 'senrogate') . '</p>';

            return;
        }

        $byRun = [];
        $eventIds = [];
        foreach ($runs as $run) {
            foreach ($this->ledger->forCorrelation($run) as $change) {
                $byRun[$run][$change->eventId][] = $change;
                $eventIds[$change->eventId] = true;
            }
        }
        $events = $this->audit->forEvents(array_keys($eventIds));

        foreach ($runs as $run) {
            $this->renderRun($run, $byRun[$run] ?? [], $events);
        }

        if ($paged > 1 || $more) {
            echo '<p>';
            if ($paged > 1) {
                echo '<a class="button" href="' . esc_url(add_query_arg(['page' => self::SLUG, 'paged' => $paged - 1], admin_url('tools.php'))) . '">' . esc_html__('Newer', 'senrogate') . '</a> ';
            }
            if ($more) {
                echo '<a class="button" href="' . esc_url(add_query_arg(['page' => self::SLUG, 'paged' => $paged + 1], admin_url('tools.php'))) . '">' . esc_html__('Older', 'senrogate') . '</a>';
            }
            echo '</p>';
        }
    }

    /**
     * @param array<string, list<StoredChange>>     $events
     * @param array<string, array<string, mixed>>   $audit
     */
    private function renderRun(string $run, array $events, array $audit): void
    {
        echo '<div class="card" style="max-width:none;margin-top:16px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Run', 'senrogate') . ' <code>' . esc_html($run) . '</code> ';
        echo $this->undoLink(UndoScope::correlation($run), __('Undo this run', 'senrogate')); // phpcs:ignore WordPress.Security.EscapeOutput -- undoLink() escapes.
        echo '</h2>';

        foreach ($events as $eventId => $changes) {
            $row = $audit[$eventId] ?? [];
            $record = json_decode((string) ($row['record_json'] ?? '{}'), true);
            $token = is_array($record) && isset($record['actor']['token_id']) ? (string) $record['actor']['token_id'] : '';
            $user = (string) ($row['wp_user'] ?? '');
            $actor = $token !== '' ? $token : ($user !== '' ? sprintf(/* translators: %s WordPress user id */ __('user #%s', 'senrogate'), $user) : '—');

            echo '<h3>' . esc_html((string) ($row['ability'] ?? '—')) . '</h3>';
            echo '<p>';
            echo esc_html(sprintf(/* translators: 1: actor 2: UTC time */ __('By %1$s at %2$s (UTC).', 'senrogate'), $actor, (string) ($row['ts'] ?? ($changes[0]->ts ?? '—'))));
            echo ' <code style="font-size:11px;">' . esc_html($eventId) . '</code> ';
            echo $this->undoLink(UndoScope::event($eventId), __('Undo this event', 'senrogate')); // phpcs:ignore WordPress.Security.EscapeOutput -- undoLink() escapes.
            echo '</p>';

            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Object', 'senrogate'), __('Action', 'senrogate'), __('Changed fields', 'senrogate'), __('Restorable', 'senrogate'), ''] as $h) {
                echo '<th>' . esc_html($h) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($changes as $change) {
                $this->renderChangeRow($change);
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private function renderChangeRow(StoredChange $change): void
    {
        $refusal = $change->refusal();
        echo '<tr>';
        echo '<td><code>' . esc_html($change->objectType . ' #' . $change->objectId) . '</code>';
        if ($change->objectSubtype !== null && $change->objectSubtype !== '') {
            echo ' ' . esc_html($change->objectSubtype);
        }
        echo '</td>';
        echo '<td>' . esc_html($change->action->value) . '</td>';
        echo '<td>' . self::diffTable(FieldDiff::for($change)) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- diffTable() escapes every value.
        if ($refusal === null) {
            echo '<td>' . esc_html__('Yes', 'senrogate') . '</td>';
            echo '<td>' . $this->undoLink(UndoScope::change($change->id), __('Undo this change', 'senrogate')) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- undoLink() escapes.
        } else {
            echo '<td>' . esc_html__('No', 'senrogate') . ': ' . esc_html(ChangeReasonLabels::label($refusal)) . '</td>';
            echo '<td></td>';
        }
        echo '</tr>';
    }

    private function renderPreview(UndoScope $scope): void
    {
        $items = $this->undo->preview($scope);
        $count = [UndoItem::RESTORABLE => 0, UndoItem::SKIPPED => 0, UndoItem::FAILED => 0];
        foreach ($items as $item) {
            if (isset($count[$item->status])) {
                $count[$item->status]++;
            }
        }
        $changes = $this->changesIn($scope);

        echo '<h2>' . esc_html__('Preview undo', 'senrogate') . '</h2>';
        if ($items === []) {
            echo '<p>' . esc_html__('No recorded changes in this scope.', 'senrogate') . '</p>';
            $this->backLink();

            return;
        }

        echo '<p>' . esc_html(sprintf(
            /* translators: 1: restorable count 2: skipped count 3: blocked-by-drift count */
            __('%1$d can be restored, %2$d will be skipped, %3$d would stop the undo.', 'senrogate'),
            $count[UndoItem::RESTORABLE],
            $count[UndoItem::SKIPPED],
            $count[UndoItem::FAILED]
        )) . '</p>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Object', 'senrogate'), __('Outcome', 'senrogate'), __('What would change', 'senrogate')] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr><td><code>' . esc_html($item->objectType . ' #' . $item->objectId) . '</code></td>';
            echo '<td>' . esc_html(self::outcomeLabel($item)) . '</td><td>';
            if ($item->drift !== []) {
                echo self::threeWay($item->drift); // phpcs:ignore WordPress.Security.EscapeOutput -- threeWay() escapes every value.
            } elseif ($item->status === UndoItem::RESTORABLE && isset($changes[$item->changeId])) {
                // Restoring writes the recorded "before" back over "after".
                $rows = array_map(
                    static fn (array $d): array => ['field' => $d['field'], 'before' => $d['after'], 'after' => $d['before']],
                    FieldDiff::for($changes[$item->changeId])
                );
                echo self::diffTable($rows); // phpcs:ignore WordPress.Security.EscapeOutput -- diffTable() escapes every value.
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        if ($count[UndoItem::FAILED] > 0) {
            echo '<p><strong>' . esc_html__('Part of this scope has drifted or is missing. Confirming will undo what it can, in reverse order, and stop at the first problem. There is no force option.', 'senrogate') . '</strong></p>';
        }

        if ($count[UndoItem::RESTORABLE] > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::UNDO) . '">';
            echo '<input type="hidden" name="scope" value="' . esc_attr($scope->kind) . '">';
            echo '<input type="hidden" name="scope_id" value="' . esc_attr($scope->id) . '">';
            echo wp_nonce_field(self::UNDO, '_wpnonce', true, false); // phpcs:ignore WordPress.Security.EscapeOutput -- core-built hidden fields.
            echo '<button type="submit" class="button button-primary">' . esc_html__('Confirm undo', 'senrogate') . '</button>';
            echo '</form>';
        }
        $this->backLink();
    }

    private function backLink(): void
    {
        echo '<a class="button" href="' . esc_url(add_query_arg(['page' => self::SLUG], admin_url('tools.php'))) . '">' . esc_html__('Back to changes', 'senrogate') . '</a>';
    }

    /** @return array<int, StoredChange> */
    private function changesIn(UndoScope $scope): array
    {
        $stored = match ($scope->kind) {
            UndoScope::CHANGE => array_filter([$this->ledger->find((int) $scope->id)]),
            UndoScope::EVENT => $this->ledger->forEvent($scope->id),
            default => $this->ledger->forCorrelation($scope->id),
        };
        $byId = [];
        foreach ($stored as $change) {
            $byId[$change->id] = $change;
        }

        return $byId;
    }

    /** The preview link, a nonce'd GET: only reads, but still bound to the user's session. */
    private function undoLink(UndoScope $scope, string $label): string
    {
        $url = add_query_arg(
            [
                'page' => self::SLUG,
                'view' => 'preview',
                'scope' => $scope->kind,
                'scope_id' => $scope->id,
                '_wpnonce' => wp_create_nonce(self::PREVIEW),
            ],
            admin_url('tools.php')
        );

        return '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /** The preview scope in the query string, once its nonce checks out. */
    private function requestedPreviewScope(): ?UndoScope
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below with check_admin_referer().
        if (sanitize_key(wp_unslash($_GET['view'] ?? '')) !== 'preview') {
            return null;
        }
        check_admin_referer(self::PREVIEW);

        return self::parseScope(
            isset($_GET['scope']) ? sanitize_text_field(wp_unslash($_GET['scope'])) : '',
            isset($_GET['scope_id']) ? sanitize_text_field(wp_unslash($_GET['scope_id'])) : ''
        );
        // phpcs:enable
    }

    private static function parseScope(string $kind, string $id): ?UndoScope
    {
        if ($id === '') {
            return null;
        }

        return match ($kind) {
            UndoScope::CHANGE => absint($id) > 0 ? UndoScope::change(absint($id)) : null,
            UndoScope::EVENT => UndoScope::event($id),
            UndoScope::CORRELATION => UndoScope::correlation($id),
            default => null,
        };
    }

    /** admin-post handler: apply, flash the result, go back. */
    public function undo(): void
    {
        $this->process();

        wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('tools.php')));
        exit;
    }

    /**
     * Capability and nonce first, then {@see UndoService::undo()} for the
     * signed-in user. Split from the redirect so tests can drive it.
     */
    public function process(): UndoResult
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'senrogate'));
        }
        check_admin_referer(self::UNDO);

        $scope = self::parseScope(
            isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : '',
            isset($_POST['scope_id']) ? sanitize_text_field(wp_unslash($_POST['scope_id'])) : ''
        );
        if ($scope === null) {
            wp_die(esc_html__('Unknown undo scope.', 'senrogate'));
        }

        $user = get_current_user_id();
        $result = $this->undo->undo($scope, $user);
        set_transient(self::RESULT . $user, [
            'status' => $result->status,
            'reason' => $result->reason,
            'event' => $result->eventId,
            'items' => array_map(static fn (UndoItem $i): array => [
                'id' => $i->changeId, 'type' => $i->objectType, 'object' => $i->objectId,
                'status' => $i->status, 'reason' => $i->reason, 'drift' => $i->drift, 'message' => $i->message,
            ], $result->items),
        ], 300);

        return $result;
    }

    private function maybeShowResult(): void
    {
        $user = get_current_user_id();
        $flash = get_transient(self::RESULT . $user);
        if (!is_array($flash)) {
            return;
        }
        delete_transient(self::RESULT . $user);

        $status = (string) ($flash['status'] ?? '');
        $items = is_array($flash['items'] ?? null) ? $flash['items'] : [];
        $class = match ($status) {
            UndoResult::COMPLETE => 'notice-success',
            UndoResult::PARTIAL => 'notice-warning',
            default => 'notice-error',
        };

        echo '<div class="notice ' . esc_attr($class) . '"><p><strong>' . esc_html(match ($status) {
            UndoResult::COMPLETE => __('Undo complete.', 'senrogate'),
            UndoResult::PARTIAL => __('Undo stopped part-way.', 'senrogate'),
            UndoResult::FORBIDDEN => __('Undo not allowed.', 'senrogate'),
            UndoResult::EMPTY => __('Nothing to undo: no recorded changes in that scope.', 'senrogate'),
            default => __('Nothing was restored.', 'senrogate'),
        }) . '</strong>';
        if (!empty($flash['reason'])) {
            echo ' ' . esc_html(ChangeReasonLabels::label((string) $flash['reason']));
        }
        echo '</p>';

        $groups = [
            UndoItem::RESTORED => __('Restored', 'senrogate'),
            UndoItem::SKIPPED => __('Skipped', 'senrogate'),
            UndoItem::FAILED => __('Stopped at', 'senrogate'),
            UndoItem::PENDING => __('Not attempted', 'senrogate'),
        ];
        foreach ($groups as $key => $heading) {
            $rows = array_filter($items, static fn ($i): bool => is_array($i) && ($i['status'] ?? '') === $key);
            if ($rows === []) {
                continue;
            }
            echo '<p><strong>' . esc_html($heading) . ' (' . esc_html((string) count($rows)) . ')</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ($rows as $row) {
                echo '<li><code>' . esc_html((string) $row['type'] . ' #' . (string) $row['object']) . '</code>';
                if (!empty($row['reason'])) {
                    echo ': ' . esc_html(ChangeReasonLabels::label((string) $row['reason']));
                }
                if (!empty($row['message'])) {
                    echo ' (' . esc_html((string) $row['message']) . ')';
                }
                if (!empty($row['drift']) && is_array($row['drift'])) {
                    echo self::threeWay(array_values($row['drift'])); // phpcs:ignore WordPress.Security.EscapeOutput -- threeWay() escapes every value.
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function outcomeLabel(UndoItem $item): string
    {
        if ($item->status === UndoItem::RESTORABLE) {
            return __('Will be restored', 'senrogate');
        }
        $prefix = $item->status === UndoItem::SKIPPED ? __('Skipped', 'senrogate') : __('Stops the undo', 'senrogate');

        return $item->reason === null ? $prefix : $prefix . ': ' . ChangeReasonLabels::label($item->reason);
    }

    /** @param list<array{field: string, before: mixed, after: mixed}> $diff */
    private static function diffTable(array $diff): string
    {
        if ($diff === []) {
            return '—';
        }
        $out = '<table class="widefat"><tbody>';
        foreach ($diff as $d) {
            $out .= '<tr><th scope="row">' . esc_html($d['field']) . '</th><td><del>' . self::value($d['before']) . '</del></td><td><ins>' . self::value($d['after']) . '</ins></td></tr>';
        }

        return $out . '</tbody></table>';
    }

    /** @param list<array{field: string, before: mixed, after: mixed, current: mixed}> $drift */
    private static function threeWay(array $drift): string
    {
        $out = '<table class="widefat"><thead><tr><th>' . esc_html__('Field', 'senrogate') . '</th><th>' . esc_html__('Before the agent', 'senrogate') . '</th><th>' . esc_html__('Agent set', 'senrogate') . '</th><th>' . esc_html__('Now', 'senrogate') . '</th></tr></thead><tbody>';
        foreach ($drift as $d) {
            $out .= '<tr><th scope="row">' . esc_html((string) $d['field']) . '</th><td>' . self::value($d['before']) . '</td><td>' . self::value($d['after']) . '</td><td>' . self::value($d['current']) . '</td></tr>';
        }

        return $out . '</tbody></table>';
    }

    /** @param mixed $value */
    private static function value($value): string
    {
        $text = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
        if ($value === null) {
            $text = 'null';
        }
        if (strlen($text) > 200) {
            $text = substr($text, 0, 200) . '…';
        }

        return '<code>' . esc_html($text) . '</code>';
    }
}
