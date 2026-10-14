<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

/**
 * §3.6.6: the core library's reason codes (`unknown_verb`, `state_unverifiable`,
 * `approval_required`, `rate_limited_*`, ...) are plain machine strings —
 * WordPress-free, so untranslated by construction. This is the one place the
 * plugin maps them to a translated label for a human reading an admin screen
 * (the audit log, the pending-actions queue). Each case passes a LITERAL
 * string to `__()` (a variable there is a Plugin Check error), so an unknown
 * or future code falls through to the raw code rather than being silently
 * mistranslated.
 */
final class ReasonLabels
{
    public static function label(string $reason): string
    {
        switch ($reason) {
            case 'unknown_verb':
                return __('Unknown verb', 'belayline-agent-governance');
            case 'readonly_but_writes':
                return __('Read-only pack attempted a write', 'belayline-agent-governance');
            case 'not_in_pack':
                return __('Not included in the bound pack', 'belayline-agent-governance');
            case 'denied_by_class':
                return __('Denied by tier class', 'belayline-agent-governance');
            case 'denied_by_class_destructive_hint':
                return __('Denied — destructive hint on a denied tier class', 'belayline-agent-governance');
            case 'state_unverifiable':
                return __('Target state could not be verified', 'belayline-agent-governance');
            case 'approval_required':
                return __('Approval required', 'belayline-agent-governance');
            case 'site_paused':
                return __('Site paused (emergency stop)', 'belayline-agent-governance');
            case 'ip_not_allowed':
                return __('IP address not allowed', 'belayline-agent-governance');
            case 'denial_lockout':
                return __('Denial lockout', 'belayline-agent-governance');
            case 'repeat_call':
                return __('Repeated identical call', 'belayline-agent-governance');
            case 'calls_per_minute':
                return __('Rate limit: calls per minute', 'belayline-agent-governance');
            case 'calls_per_hour':
                return __('Rate limit: calls per hour', 'belayline-agent-governance');
            case 'forbidden_argument':
                return __('Forbidden argument', 'belayline-agent-governance');
            case 'not_allowed_value':
                return __('Argument value not allowed', 'belayline-agent-governance');
            case 'unreadable_argument':
                return __('Argument unreadable', 'belayline-agent-governance');
            case 'max_items_per_call':
                return __('Too many items in one call', 'belayline-agent-governance');
            case 'max_per_call':
                return __('Argument cap exceeded (per call)', 'belayline-agent-governance');
            case 'max_total_per_day':
                return __('Argument cap exceeded (per day)', 'belayline-agent-governance');
            case 'stale':
                return __('Stale — the target changed before this could be claimed', 'belayline-agent-governance');
            case 'void_environment':
                return __('Voided — the site address changed', 'belayline-agent-governance');
        }

        if (str_starts_with($reason, 'rate_limited_')) {
            return sprintf(
                /* translators: %s the specific rate-limit rule that tripped */
                __('Rate limited (%s)', 'belayline-agent-governance'),
                substr($reason, strlen('rate_limited_'))
            );
        }

        if (str_starts_with($reason, 'argument_cap_')) {
            return sprintf(
                /* translators: %s the specific argument cap that tripped */
                __('Argument cap (%s)', 'belayline-agent-governance'),
                substr($reason, strlen('argument_cap_'))
            );
        }

        // Unmapped: a code this map doesn't (yet) know, or a decision/audit
        // outcome rather than a gate reason. Shown as-is rather than guessed at.
        return $reason;
    }
}
