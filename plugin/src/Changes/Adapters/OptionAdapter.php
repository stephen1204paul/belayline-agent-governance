<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes\Adapters;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeCaptureAdapter;
use Specflux\AgentSafety\Changes\ChangeRestoreAdapter;
use Specflux\AgentSafety\Changes\StoredChange;

/** Options via the `update_option` action (old and new). The object id is the option name. */
final class OptionAdapter implements ChangeCaptureAdapter, ChangeRestoreAdapter
{
    use RecordsChanges;

    /**
     * Options never captured: caches and cron, and this plugin's own state
     * (pause switch, shadow list, site binding, retention). An undo must never
     * be able to restore a control SenroGate itself owns.
     */
    private const IGNORED_PREFIXES = ['_transient', '_site_transient', 'agsafe_', 'agent_safety'];
    private const IGNORED = ['cron', 'rewrite_rules', 'recently_activated'];

    public function objectTypes(): array
    {
        return ['option'];
    }

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
        if ($this->attached) {
            return;
        }
        $this->attached = true;

        add_action('update_option', [$this, 'onUpdateOption'], 10, 3);
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }
        $this->attached = false;

        remove_action('update_option', [$this, 'onUpdateOption'], 10);
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public function onUpdateOption(string $option, $old, $new): void
    {
        if (self::ignored($option)) {
            return;
        }

        $this->guard('option', function () use ($option, $old, $new): void {
            $this->record('option', $option, null, ChangeAction::Update, [$option => $old], [$option => $new]);
        });
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_options';
    }

    public function canRestore(StoredChange $change): ?string
    {
        // Defence in depth: a row for a control option must never be written back.
        return self::ignored($change->objectId) ? 'protected_option' : null;
    }

    public function currentState(StoredChange $change): array
    {
        return [$change->objectId => get_option($change->objectId)];
    }

    public function volatileFields(): array
    {
        return [];
    }

    public function restore(StoredChange $change): void
    {
        update_option($change->objectId, $change->before[$change->objectId] ?? false);
    }

    private static function ignored(string $option): bool
    {
        if (in_array($option, self::IGNORED, true)) {
            return true;
        }
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($option, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
