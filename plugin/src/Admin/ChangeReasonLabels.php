<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

/**
 * Human labels for the machine reason codes of the change history: why a
 * change cannot be restored, and why an undo skipped, refused or stopped. Each
 * case passes a LITERAL string to `__()`; an unknown code falls through as-is.
 */
final class ChangeReasonLabels
{
    public static function label(string $reason): string
    {
        switch ($reason) {
            case 'irreversible':
                return __('Irreversible by nature (refund, status change, sent mail)', 'senrogate');
            case 'secret':
                return __('Holds a secret, stored as a hash', 'senrogate');
            case 'redacted':
                return __('Personal data was masked when it was recorded', 'senrogate');
            case 'truncated':
                return __('Too large, the snapshot was cut short', 'senrogate');
            case 'unencodable':
                return __('The snapshot could not be stored', 'senrogate');
            case 'already_restored':
                return __('Already undone', 'senrogate');
            case 'not_restorable':
                return __('Not restorable', 'senrogate');
            case 'permanent_delete':
                return __('Permanently deleted, nothing left to restore', 'senrogate');
            case 'term_creation':
                return __('A created term cannot be removed safely', 'senrogate');
            case 'drift':
                return __('Changed since the agent ran, so undoing would overwrite newer edits', 'senrogate');
            case 'object_missing':
                return __('The object no longer exists', 'senrogate');
            case 'restore_failed':
                return __('WordPress refused the restore', 'senrogate');
            case 'no_adapter':
                return __('No restore support for this object type', 'senrogate');
            case 'user_mismatch':
                return __('The undo was not requested by the signed-in user', 'senrogate');
            case 'agent_context':
                return __('Agents cannot undo changes', 'senrogate');
            case 'inside_governed_call':
                return __('An undo cannot run inside an agent call', 'senrogate');
            case 'capability':
                return __('You lack the capability to restore part of this scope', 'senrogate');
        }

        return $reason;
    }
}
