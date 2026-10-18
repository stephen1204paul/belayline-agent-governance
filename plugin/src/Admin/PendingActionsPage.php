<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Time;
use Belayline\Plugin\Api\Approvals;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Support\ApprovalNotifier;
use Belayline\Plugin\Support\SummaryMarkup;

/**
 * The Pending tab: the human side of the approval flow. Lists the agent actions
 * a pack held for review, as cards. Approving mints a single-use, verb+args-bound
 * token (shown ONCE) and appends an `approved` event to the audit chain; denying
 * appends a `rejected` event.
 *
 * Reconciliation is append-only: the audit log is hash-chained and immutable, so a
 * verdict change is a NEW linked row referencing the same approval id — never a
 * mutation of the original `pending` row. The page OWNS the queue UX but NOT the
 * resolution semantics: mutations delegate to {@see Approvals} (AS-10), the same
 * service every programmatic caller uses, so the audit rows and lifecycle actions
 * here are identical to any other approver's by construction. This page keeps the
 * two things only wp-admin needs: nonce verification and the show-once token flash.
 */
final class PendingActionsPage
{
    private const CAP = 'manage_options';
    private const APPROVE = 'belayline_approve_action';
    private const REJECT = 'belayline_reject_action';
    private const NOTIFY = 'belayline_save_notifications';
    // Public: named by {@see \Belayline\Plugin\Support\UninstallManifest}
    // as the transient-key prefixes an opted-in uninstall must sweep — these
    // transients are keyed per-approver, so no fixed list of full names exists.
    public const FLASH = 'belayline_minted_token_';
    public const STALE_FLASH = 'belayline_stale_notice_';

    public function __construct(
        private readonly WpdbApprovalStore $store,
        private readonly Approvals $approvals,
        private readonly ?WhoResolver $who = null,
        private readonly ?AuditReader $audit = null,
        private readonly ?int $now = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::APPROVE, [$this, 'approve']);
        add_action('admin_post_' . self::REJECT, [$this, 'reject']);
        add_action('admin_post_' . self::NOTIFY, [$this, 'saveNotifications']);
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
        $rows = $this->store->pending();

        echo '<p class="aui-page-intro">' . esc_html__('Agents asked to do these things. Nothing happens until you say yes. Anything you ignore expires and is refused.', 'belayline-agent-governance') . '</p>';

        $this->maybeShowMintedToken();
        $this->maybeShowStaleNotice();

        if (!$rows) {
            echo '<section class="aui-card"><div class="aui-empty"><span class="aui-tile aui-tile--ok">' . Icons::svg('check', 'aui-i--lg') . '</span><h2 class="aui-card-t">'
                . esc_html__('Nothing is waiting', 'belayline-agent-governance') . '</h2><p>'
                . esc_html__('When an agent asks to do something that needs your approval, it shows up here and we email you.', 'belayline-agent-governance')
                . '</p><a class="aui-btn aui-btn--secondary" href="' . esc_url(AdminUrls::tab(AdminUrls::PACKS)) . '">'
                . esc_html__('Choose what needs approval', 'belayline-agent-governance') . '</a></div></section>';

            return;
        }

        $events = $this->audit !== null
            ? $this->audit->forEvents(array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['audit_event_id'] ?? ''), $rows))))
            : [];

        foreach ($rows as $r) {
            $this->renderCard($r, $events[(string) ($r['audit_event_id'] ?? '')] ?? null, $now);
        }
    }

    /**
     * @param array<string, mixed>      $r     The pending approval row.
     * @param array<string, mixed>|null $event The audit row that filed it, when known.
     */
    private function renderCard(array $r, ?array $event, int $now): void
    {
        $approvalId = (string) ($r['approval_id'] ?? '');
        $who = $this->who?->describe((string) ($r['key_id'] ?? '')) ?? ['label' => (string) ($r['key_id'] ?? ''), 'sub' => ''];
        // AS-6 §3.3 item 4: a row with no probe declared for its verb has no
        // state check at all, so the queue says so rather than implying every
        // row is equally protected.
        $kind = $r['fingerprint_kind'] ?? null;
        $unchecked = $kind === null || $kind === 'none' || $kind === '';
        $titleId = 'approval-' . $approvalId;

        echo '<article class="aui-card aui-approval" id="' . esc_attr($titleId) . '" data-aui-item aria-labelledby="' . esc_attr($titleId . '-s') . '">';
        echo '<div class="aui-approval-h"><span class="aui-tile aui-tile--sm">' . Icons::svg('robot') . '</span><div class="aui-grow"><div class="aui-item-name">'
            . esc_html($who['label']) . ($who['sub'] !== '' ? ' <span class="aui-muted">&middot; ' . esc_html($who['sub']) . '</span>' : '') . '</div></div>'
            . Html::badge('warn', __('Waiting', 'belayline-agent-governance'), 'clock') . '</div>';
        // phpcs:ignore WordPress.Security.EscapeOutput -- summaryHtml() escapes or wp_kses-es by provenance.
        echo '<h2 class="aui-approval-sum" id="' . esc_attr($titleId . '-s') . '">' . self::summaryHtml((string) ($r['summary'] ?? '')) . '</h2>';
        echo $this->why($event);

        echo '<dl class="aui-kv"><dt>' . esc_html__('Ability', 'belayline-agent-governance') . '</dt><dd class="aui-mono">' . esc_html((string) ($r['verb'] ?? '')) . '</dd>';
        echo '<dt>' . esc_html__('Asked at', 'belayline-agent-governance') . '</dt><dd class="aui-mono">' . esc_html((string) ($r['created_ts'] ?? '')) . ' UTC</dd>';
        echo '<dt>' . esc_html__('Expires', 'belayline-agent-governance') . '</dt><dd class="aui-mono">' . esc_html(Time::until((string) ($r['pending_expires_ts'] ?? ''), $now)) . '</dd>';
        if ($unchecked) {
            echo '<dt>' . esc_html__('Safety check', 'belayline-agent-governance') . '</dt><dd>' . esc_html__('Not available for this action: nothing compares the current state before it runs.', 'belayline-agent-governance') . '</dd>';
        }
        echo '<dt>' . esc_html__('Request id', 'belayline-agent-governance') . '</dt><dd class="aui-mono">' . esc_html($approvalId) . '</dd></dl>';

        echo '<div class="aui-approval-f">' . $this->actionButton($approvalId, self::APPROVE, 'primary', __('Approve', 'belayline-agent-governance')) // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers below.
            . $this->actionButton($approvalId, self::REJECT, 'secondary', __('Deny', 'belayline-agent-governance')) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers below.
        echo '</article>';
    }

    /**
     * Why an action was held, in a sentence, from the tier and pack of the audit row that filed it.
     *
     * @param array<string, mixed>|null $event
     */
    public static function heldReason(?array $event): string
    {
        $tier = isset($event['tier']) ? (int) $event['tier'] : null;
        $pack = isset($event['pack']) ? (string) $event['pack'] : '';
        if ($tier === 2) {
            return $pack !== ''
                /* translators: %s: pack name */
                ? sprintf(__('This kind of action always asks first, in the %s pack, even if you approved something similar before.', 'belayline-agent-governance'), $pack)
                : __('This kind of action always asks first, even if you approved something similar before.', 'belayline-agent-governance');
        }

        return $pack !== ''
            /* translators: %s: pack name */
            ? sprintf(__('This needs approval in the %s pack.', 'belayline-agent-governance'), $pack)
            : __('This needs approval.', 'belayline-agent-governance');
    }

    /** @param array<string, mixed>|null $event */
    private function why(?array $event): string
    {
        $icon = isset($event['tier']) && (int) $event['tier'] === 2 ? 'lock' : 'info';

        return '<div class="aui-why">' . Icons::svg($icon) . '<span>' . esc_html(self::heldReason($event)) . '</span></div>';
    }

    /** Rail: where new waiting actions get routed. */
    public function renderRail(): void
    {
        echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__('Notifications', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('Each new waiting action sends an email with a link to this tab. If you add a webhook address, we also send a short message with identifiers only. No call details leave your site.', 'belayline-agent-governance') . '</p></div></div>';
        echo Html::postForm(self::NOTIFY, self::NOTIFY, Html::backTo(AdminUrls::PENDING)); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
        echo '<div class="aui-card-b belayline-stack">';
        printf(
            '<div class="aui-field"><label class="aui-label" for="belayline_notify_email">%s</label><input class="aui-input" type="email" id="belayline_notify_email" name="belayline_notify_email" value="%s" placeholder="%s"></div>',
            esc_html__('Send emails to', 'belayline-agent-governance'),
            esc_attr((string) get_option(ApprovalNotifier::EMAIL_OPTION, '')),
            esc_attr__('Your site admin email', 'belayline-agent-governance'),
        );
        printf(
            '<div class="aui-field"><label class="aui-label" for="belayline_webhook_url">%s</label><input class="aui-input" type="url" id="belayline_webhook_url" name="belayline_webhook_url" value="%s" placeholder="https://"></div>',
            esc_html__('Webhook address (optional)', 'belayline-agent-governance'),
            esc_attr((string) get_option(ApprovalNotifier::WEBHOOK_OPTION, '')),
        );
        echo '</div><div class="aui-card-f"><button type="submit" class="aui-btn aui-btn--secondary">' . esc_html__('Save notification settings', 'belayline-agent-governance') . '</button></div></form></section>';
    }

    public function saveNotifications(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::NOTIFY);

        $email = isset($_POST['belayline_notify_email'])
            ? sanitize_email(wp_unslash($_POST['belayline_notify_email']))
            : '';
        $webhook = isset($_POST['belayline_webhook_url'])
            ? esc_url_raw(wp_unslash($_POST['belayline_webhook_url']), ['https', 'http'])
            : '';

        update_option(ApprovalNotifier::EMAIL_OPTION, $email);
        update_option(ApprovalNotifier::WEBHOOK_OPTION, $webhook);

        $this->redirectBack();
    }

    public function approve(): void
    {
        $approvalId = $this->guard(self::APPROVE);
        $approver = get_current_user_id();

        $token = $this->approvals->approveReturningToken($approvalId, $approver);
        if ($token !== null) {
            set_transient(self::FLASH . $approver, ['approval_id' => $approvalId, 'token' => $token], 120);
        } else {
            // AS-6 §3.3 item 8: approveReturningToken() also returns null for
            // an ordinary reason (unauthorized, already resolved) — only flag
            // it as "changed since filed" when the row itself now reads back
            // as `stale`, so we don't misattribute an unrelated failure.
            $summary = $this->approvals->find($approvalId);
            if ($summary !== null && $summary->status === 'stale') {
                set_transient(self::STALE_FLASH . $approver, true, 120);
            }
        }

        $this->redirectBack();
    }

    public function reject(): void
    {
        $approvalId = $this->guard(self::REJECT);
        $approver = get_current_user_id();

        $this->approvals->reject($approvalId, $approver);

        $this->redirectBack();
    }

    /** AS-6 §3.3 item 8: the approve attempt that just redirected here found the target had changed. */
    private function maybeShowStaleNotice(): void
    {
        $user = get_current_user_id();
        if (!get_transient(self::STALE_FLASH . $user)) {
            return;
        }
        delete_transient(self::STALE_FLASH . $user);

        echo Html::notice('warning', __('This request is out of date.', 'belayline-agent-governance'), __('What it targets changed after it was filed, so it cannot be approved as it is. If it is still needed, ask the agent to try again.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
    }

    private function maybeShowMintedToken(): void
    {
        $user = get_current_user_id();
        $flash = get_transient(self::FLASH . $user);
        if (!is_array($flash) || empty($flash['token'])) {
            return;
        }
        delete_transient(self::FLASH . $user);

        echo '<div class="aui-notice aui-notice--success" role="status">' . Icons::svg('check') . '<div><strong>' . esc_html__('Approved.', 'belayline-agent-governance') . '</strong> '
            . esc_html(sprintf(/* translators: %s approval id */ __('Request %s is now approved. The same AI app can simply try the exact same call again and it will run, no code needed.', 'belayline-agent-governance'), (string) $flash['approval_id']))
            . '<p>' . esc_html__('To let someone else run it, give them this one-time code as the _approval argument:', 'belayline-agent-governance')
            . '</p><p><code class="aui-code belayline-select">' . esc_html((string) $flash['token']) . '</code></p><p>'
            . esc_html__('It works once, for 15 minutes, for that exact action only. It is shown now and never stored.', 'belayline-agent-governance') . '</p></div></div>';
    }

    /**
     * The Summary cell, escaped by provenance.
     *
     * A summary is built from RAW AGENT ARGUMENTS, so by default it is text and
     * nothing but text: `esc_html()`, meaning an agent that plants
     * `<a href="javascript:...">` or `<script>` in an argument value gets it shown
     * back as literal characters rather than as live markup in the queue an
     * administrator is about to act on.
     *
     * Only a summary a site integration actually rewrote through the
     * `belayline_approval_summary` filter is host-authored — the recorder tags
     * exactly those ({@see SummaryMarkup}) — and only those are rendered as markup,
     * through wp_kses with an allow-list of exactly one element (`<a href="...">`).
     * Fail-closed: an untagged, legacy or unrecognised value is escaped.
     *
     * @param string $summary The persisted summary (as stored by DecisionRecorder::requestApproval()).
     */
    public static function summaryHtml(string $summary): string
    {
        if (!SummaryMarkup::isHostAuthored($summary)) {
            return esc_html(SummaryMarkup::unwrap($summary));
        }

        return wp_kses(SummaryMarkup::unwrap($summary), ['a' => ['href' => true]]);
    }

    /**
     * One Approve/Deny form. Plugin Check flags a translation function
     * called with a variable label, so each caller passes its own LITERAL
     * `__()` call rather than this method building one from a lookup table.
     */
    private function actionButton(string $approvalId, string $action, string $style, string $label): string
    {
        $out = '<form method="post" class="belayline-inline" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $out .= '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        $out .= '<input type="hidden" name="approval_id" value="' . esc_attr($approvalId) . '">';
        $out .= wp_nonce_field($action . $approvalId, '_wpnonce', true, false);
        $out .= '<button type="submit" class="aui-btn aui-btn--' . esc_attr($style) . '">' . esc_html($label) . '</button>';
        $out .= '</form>';

        return $out;
    }

    /** Verify cap + nonce, return the sanitised approval id, or wp_die. */
    private function guard(string $action): string
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        $approvalId = isset($_POST['approval_id']) ? sanitize_text_field(wp_unslash($_POST['approval_id'])) : '';
        check_admin_referer($action . $approvalId);

        return $approvalId;
    }

    private function redirectBack(): void
    {
        wp_safe_redirect(AdminUrls::tab(AdminUrls::PENDING));
        exit;
    }
}
