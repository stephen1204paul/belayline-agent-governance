<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Changes\ChangeLedger;
use Belayline\Changes\FieldDiff;
use Belayline\Changes\StoredChange;
use Belayline\Changes\UndoItem;
use Belayline\Changes\UndoResult;
use Belayline\Changes\UndoScope;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Changes\UndoService;

/**
 * Activity > Changes: what governed agent calls wrote, grouped by run
 * (correlation id) and then audit event, with a field-level before/after diff,
 * and the human-only undo flow of the 0.5 spec section 3.2: preview the
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
    public const UNDO = 'belayline_undo';
    public const PREVIEW = 'belayline_undo_preview';
    public const RESULT = 'belayline_undo_result_';
    private const CAP = 'manage_options';
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ChangeLedger $ledger,
        private readonly UndoService $undo,
        private readonly AuditReader $audit,
        private readonly ?WhoResolver $who = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::UNDO, [$this, 'undo']);
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
        echo '<p class="aui-page-intro">' . esc_html__('What your AI agents changed on this site, run by run. "No changes recorded" does not mean nothing changed: direct database writes and some kinds of content are not captured. Undo is for people only; no agent can use it.', 'belayline-agent-governance') . '</p>';
        $this->maybeShowResult();

        $scope = $this->requestedPreviewScope();
        if ($scope !== null) {
            $this->renderPreview($scope);
        } else {
            $this->renderList();
        }
    }

    private function renderList(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $runs = $this->ledger->recentCorrelations(self::PER_PAGE + 1, ($paged - 1) * self::PER_PAGE);
        $more = count($runs) > self::PER_PAGE;
        $runs = array_slice($runs, 0, self::PER_PAGE);

        if ($runs === []) {
            echo wp_kses('<section class="aui-card"><div class="aui-empty"><span class="aui-tile">' . Icons::svg('undo', 'aui-i--lg') . '</span><h2 class="aui-card-t">'
                . esc_html__('No changes recorded yet', 'belayline-agent-governance') . '</h2><p>'
                . esc_html__('When an agent changes your content or settings, each change is listed here with a way to undo it.', 'belayline-agent-governance') . '</p></div></section>', Html::allowed());

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
            echo '<p class="aui-row-flex">';
            if ($paged > 1) {
                echo '<a class="aui-btn aui-btn--secondary" href="' . esc_url(AdminUrls::activity(AdminUrls::CHANGES, ['paged' => $paged - 1])) . '">' . esc_html__('Newer', 'belayline-agent-governance') . '</a>';
            }
            if ($more) {
                echo '<a class="aui-btn aui-btn--secondary" href="' . esc_url(AdminUrls::activity(AdminUrls::CHANGES, ['paged' => $paged + 1])) . '">' . esc_html__('Older', 'belayline-agent-governance') . '</a>';
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
        echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__('Run', 'belayline-agent-governance') . ' <code class="aui-code">' . esc_html($run) . '</code></h2></div>';
        echo wp_kses('<span class="aui-end">' . $this->undoLink(UndoScope::correlation($run), __('Undo this run', 'belayline-agent-governance'), 'secondary') . '</span></div>', Html::allowed());
        echo '<div class="aui-card-b belayline-stack">';

        foreach ($events as $eventId => $changes) {
            $row = $audit[$eventId] ?? [];
            $record = json_decode((string) ($row['record_json'] ?? '{}'), true);
            $token = is_array($record) && isset($record['actor']['token_id']) ? (string) $record['actor']['token_id'] : '';
            $via = is_array($record) && isset($record['actor']['via']) && is_string($record['actor']['via']) ? $record['actor']['via'] : '';
            $user = (string) ($row['wp_user'] ?? '');
            $actor = $token !== '' ? ($this->who?->actorLabel($token, $via) ?? $token) : ($user !== '' ? sprintf(/* translators: %s WordPress user id */ __('user #%s', 'belayline-agent-governance'), $user) : '—');

            echo '<div><div class="aui-row-flex"><div class="aui-grow"><div class="aui-item-name"><code class="aui-code">' . esc_html((string) ($row['ability'] ?? '—')) . '</code></div>';
            echo '<div class="aui-muted">' . esc_html(sprintf(/* translators: 1: actor 2: UTC time */ __('By %1$s at %2$s UTC', 'belayline-agent-governance'), $actor, str_replace(['T', 'Z'], [' ', ''], (string) ($row['ts'] ?? ($changes[0]->ts ?? '—'))))) . '</div></div>';
            echo wp_kses($this->undoLink(UndoScope::event($eventId), __('Undo this event', 'belayline-agent-governance'), 'ghost') . '</div>', Html::allowed());

            echo '<div class="aui-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr(sprintf(/* translators: %s: ability name */ __('Changes made by %s', 'belayline-agent-governance'), (string) ($row['ability'] ?? $eventId)) . ' (' . substr($eventId, -6) . ')') . '"><table class="aui-table"><thead><tr>';
            foreach ([__('Object', 'belayline-agent-governance'), __('Action', 'belayline-agent-governance'), __('What changed', 'belayline-agent-governance'), __('Can be undone', 'belayline-agent-governance'), ''] as $h) {
                echo '<th scope="col">' . ($h === '' ? '<span class="aui-sr">' . esc_html__('Actions', 'belayline-agent-governance') . '</span>' : esc_html($h)) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($changes as $change) {
                $this->renderChangeRow($change);
            }
            echo '</tbody></table></div></div>';
        }
        echo '</div></section>';
    }

    private function renderChangeRow(StoredChange $change): void
    {
        $refusal = $change->refusal();
        echo '<tr>';
        echo '<td class="aui-mono">' . esc_html($change->objectType . ' #' . $change->objectId);
        if ($change->objectSubtype !== null && $change->objectSubtype !== '') {
            echo ' <span class="aui-muted">' . esc_html($change->objectSubtype) . '</span>';
        }
        echo '</td>';
        echo '<td>' . esc_html($change->action->value) . '</td>';
        echo wp_kses('<td>' . self::diffTable(FieldDiff::for($change)) . '</td>', Html::allowed());
        if ($refusal === null) {
            echo wp_kses('<td>' . Html::badge('ok', __('Yes', 'belayline-agent-governance')) . '</td>', Html::allowed());
            echo wp_kses('<td><div class="aui-actions">' . $this->undoLink(UndoScope::change($change->id), __('Undo this change', 'belayline-agent-governance'), 'ghost') . '</div></td>', Html::allowed());
        } else {
            echo wp_kses('<td>' . Html::badge('', __('No', 'belayline-agent-governance')) . ' <span class="aui-muted">' . esc_html(ChangeReasonLabels::label($refusal)) . '</span></td>', Html::allowed());
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

        echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__('Preview undo', 'belayline-agent-governance') . '</h2></div>';
        if ($items === []) {
            echo '<div class="aui-card-b"><p>' . esc_html__('There are no recorded changes in this scope.', 'belayline-agent-governance') . '</p></div><div class="aui-card-f">';
            $this->backLink();
            echo '</div></section>';

            return;
        }

        echo '<div class="aui-card-b belayline-stack"><p>' . esc_html(sprintf(
            /* translators: 1: restorable count 2: skipped count 3: blocked-by-drift count */
            __('%1$d can be restored, %2$d will be skipped, %3$d would stop the undo.', 'belayline-agent-governance'),
            $count[UndoItem::RESTORABLE],
            $count[UndoItem::SKIPPED],
            $count[UndoItem::FAILED]
        )) . '</p>';
        if ($count[UndoItem::FAILED] > 0) {
            echo wp_kses(Html::notice('warning', __('Part of this has changed since the agent ran.', 'belayline-agent-governance'), __('Confirming will undo what it can, newest first, and stop at the first problem. There is no force option.', 'belayline-agent-governance')), Html::allowed());
        }

        echo '</div><div class="aui-table-wrap"><table class="aui-table"><thead><tr>';
        foreach ([__('Object', 'belayline-agent-governance'), __('Outcome', 'belayline-agent-governance'), __('What would change', 'belayline-agent-governance')] as $h) {
            echo '<th scope="col">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr><td class="aui-mono">' . esc_html($item->objectType . ' #' . $item->objectId) . '</td>';
            echo '<td>' . esc_html(self::outcomeLabel($item)) . '</td><td>';
            if ($item->drift !== []) {
                echo wp_kses(self::threeWay($item->drift), Html::allowed());
            } elseif ($item->status === UndoItem::RESTORABLE && isset($changes[$item->changeId])) {
                // Restoring writes the recorded "before" back over "after".
                $rows = array_map(
                    static fn (array $d): array => ['field' => $d['field'], 'before' => $d['after'], 'after' => $d['before']],
                    FieldDiff::for($changes[$item->changeId])
                );
                echo wp_kses(self::diffTable($rows), Html::allowed());
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><div class="aui-card-f">';

        if ($count[UndoItem::RESTORABLE] > 0) {
            echo wp_kses(Html::postForm(self::UNDO, self::UNDO, '<input type="hidden" name="scope" value="' . esc_attr($scope->kind) . '"><input type="hidden" name="scope_id" value="' . esc_attr($scope->id) . '">', 'class="belayline-inline"'), Html::allowed());
            echo '<button type="submit" class="aui-btn aui-btn--primary">' . esc_html__('Confirm undo', 'belayline-agent-governance') . '</button></form>';
        }
        $this->backLink();
        echo '</div></section>';
    }

    private function backLink(): void
    {
        echo '<a class="aui-btn aui-btn--ghost" href="' . esc_url(AdminUrls::activity(AdminUrls::CHANGES)) . '">' . esc_html__('Back to changes', 'belayline-agent-governance') . '</a>';
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
    private function undoLink(UndoScope $scope, string $label, string $style): string
    {
        $url = add_query_arg(
            [
                'view' => 'preview',
                'scope' => $scope->kind,
                'scope_id' => $scope->id,
                '_wpnonce' => wp_create_nonce(self::PREVIEW),
            ],
            AdminUrls::activity(AdminUrls::CHANGES)
        );

        return '<a class="aui-btn aui-btn--' . esc_attr($style) . ' aui-btn--sm" href="' . esc_url($url) . '">' . Icons::svg('undo') . esc_html($label) . '</a>';
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

        wp_safe_redirect(AdminUrls::activity(AdminUrls::CHANGES));
        exit;
    }

    /**
     * Capability and nonce first, then {@see UndoService::undo()} for the
     * signed-in user. Split from the redirect so tests can drive it.
     */
    public function process(): UndoResult
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::UNDO);

        $scope = self::parseScope(
            isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : '',
            isset($_POST['scope_id']) ? sanitize_text_field(wp_unslash($_POST['scope_id'])) : ''
        );
        if ($scope === null) {
            wp_die(esc_html__('Unknown undo scope.', 'belayline-agent-governance'));
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
        $tone = match ($status) {
            UndoResult::COMPLETE => 'success',
            UndoResult::PARTIAL => 'warning',
            default => 'error',
        };
        $icon = ['success' => 'check', 'warning' => 'warning', 'error' => 'x'][$tone];

        echo wp_kses('<div class="aui-notice aui-notice--' . esc_attr($tone) . '" role="' . ($tone === 'error' ? 'alert' : 'status') . '">' . Icons::svg($icon) . '<div><strong>' . esc_html(match ($status) {
            UndoResult::COMPLETE => __('Undo complete.', 'belayline-agent-governance'),
            UndoResult::PARTIAL => __('Undo stopped part-way.', 'belayline-agent-governance'),
            UndoResult::FORBIDDEN => __('Undo not allowed.', 'belayline-agent-governance'),
            UndoResult::EMPTY => __('Nothing to undo: no recorded changes in that scope.', 'belayline-agent-governance'),
            default => __('Nothing was restored.', 'belayline-agent-governance'),
        }) . '</strong>', Html::allowed());
        if (!empty($flash['reason'])) {
            echo ' ' . esc_html(ChangeReasonLabels::label((string) $flash['reason']));
        }

        $groups = [
            UndoItem::RESTORED => __('Restored', 'belayline-agent-governance'),
            UndoItem::SKIPPED => __('Skipped', 'belayline-agent-governance'),
            UndoItem::FAILED => __('Stopped at', 'belayline-agent-governance'),
            UndoItem::PENDING => __('Not attempted', 'belayline-agent-governance'),
        ];
        foreach ($groups as $key => $heading) {
            $rows = array_filter($items, static fn ($i): bool => is_array($i) && ($i['status'] ?? '') === $key);
            if ($rows === []) {
                continue;
            }
            echo '<p><strong>' . esc_html($heading) . ' (' . esc_html((string) count($rows)) . ')</strong></p><ul class="belayline-list">';
            foreach ($rows as $row) {
                echo '<li><code class="aui-code">' . esc_html((string) $row['type'] . ' #' . (string) $row['object']) . '</code>';
                if (!empty($row['reason'])) {
                    echo ': ' . esc_html(ChangeReasonLabels::label((string) $row['reason']));
                }
                if (!empty($row['message'])) {
                    echo ' (' . esc_html((string) $row['message']) . ')';
                }
                if (!empty($row['drift']) && is_array($row['drift'])) {
                    echo wp_kses(self::threeWay(array_values($row['drift'])), Html::allowed());
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div></div>';
    }

    private static function outcomeLabel(UndoItem $item): string
    {
        if ($item->status === UndoItem::RESTORABLE) {
            return __('Will be restored', 'belayline-agent-governance');
        }
        $prefix = $item->status === UndoItem::SKIPPED ? __('Skipped', 'belayline-agent-governance') : __('Stops the undo', 'belayline-agent-governance');

        return $item->reason === null ? $prefix : $prefix . ': ' . ChangeReasonLabels::label($item->reason);
    }

    /** @param list<array{field: string, before: mixed, after: mixed}> $diff */
    private static function diffTable(array $diff): string
    {
        if ($diff === []) {
            return '—';
        }
        $out = '<ul class="belayline-diff">';
        foreach ($diff as $d) {
            $out .= '<li><b>' . esc_html($d['field']) . '</b> <del>' . self::value($d['before']) . '</del> <ins>' . self::value($d['after']) . '</ins></li>';
        }

        return $out . '</ul>';
    }

    /** @param list<array{field: string, before: mixed, after: mixed, current: mixed}> $drift */
    private static function threeWay(array $drift): string
    {
        $out = '<div class="aui-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr__('Changes since the agent ran', 'belayline-agent-governance') . '"><table class="aui-table"><thead><tr><th scope="col">' . esc_html__('Field', 'belayline-agent-governance') . '</th><th scope="col">' . esc_html__('Before the agent', 'belayline-agent-governance') . '</th><th scope="col">' . esc_html__('Agent set', 'belayline-agent-governance') . '</th><th scope="col">' . esc_html__('Now', 'belayline-agent-governance') . '</th></tr></thead><tbody>';
        foreach ($drift as $d) {
            $out .= '<tr><th scope="row">' . esc_html((string) $d['field']) . '</th><td>' . self::value($d['before']) . '</td><td>' . self::value($d['after']) . '</td><td>' . self::value($d['current']) . '</td></tr>';
        }

        return $out . '</tbody></table></div>';
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

        return '<code class="aui-code">' . esc_html($text) . '</code>';
    }
}
