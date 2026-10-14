<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

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
                return __('Irreversible by nature (refund, status change, sent mail)', 'belayline-agent-governance');
            case 'secret':
                return __('Holds a secret, stored as a hash', 'belayline-agent-governance');
            case 'redacted':
                return __('Personal data was masked when it was recorded', 'belayline-agent-governance');
            case 'truncated':
                return __('Too large, the snapshot was cut short', 'belayline-agent-governance');
            case 'unencodable':
                return __('The snapshot could not be stored', 'belayline-agent-governance');
            case 'already_restored':
                return __('Already undone', 'belayline-agent-governance');
            case 'not_restorable':
                return __('Not restorable', 'belayline-agent-governance');
            case 'permanent_delete':
                return __('Permanently deleted, nothing left to restore', 'belayline-agent-governance');
            case 'term_creation':
                return __('A created term cannot be removed safely', 'belayline-agent-governance');
            case 'drift':
                return __('Changed since the agent ran, so undoing would overwrite newer edits', 'belayline-agent-governance');
            case 'object_missing':
                return __('The object no longer exists', 'belayline-agent-governance');
            case 'restore_failed':
                return __('WordPress refused the restore', 'belayline-agent-governance');
            case 'no_adapter':
                return __('No restore support for this object type', 'belayline-agent-governance');
            case 'user_mismatch':
                return __('The undo was not requested by the signed-in user', 'belayline-agent-governance');
            case 'agent_context':
                return __('Agents cannot undo changes', 'belayline-agent-governance');
            case 'inside_governed_call':
                return __('An undo cannot run inside an agent call', 'belayline-agent-governance');
            case 'capability':
                return __('You lack the capability to restore part of this scope', 'belayline-agent-governance');
        }

        return $reason;
    }
}
