<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Fakes;

use Belayline\Changes\CaptureWindow;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\ChangeCaptureAdapter;
use Belayline\Changes\ChangeRestoreAdapter;
use Belayline\Changes\StoredChange;

/** A filter-registered adapter pair for a made-up `widget` object type. */
final class WidgetAdapter implements ChangeCaptureAdapter, ChangeRestoreAdapter
{
    private ?CaptureWindow $window = null;

    public function objectTypes(): array
    {
        return ['widget'];
    }

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
    }

    public function detach(): void
    {
        $this->window = null;
    }

    /** What a real adapter's hook listener would do. */
    public function write(string $id, string $value): void
    {
        $old = $GLOBALS['widgets'][$id] ?? null;
        $GLOBALS['widgets'][$id] = $value;
        $this->window?->record(Change::capture('widget', $id, null, ChangeAction::Update, ['v' => $old], ['v' => $value]));
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_widgets';
    }

    public function canRestore(StoredChange $change): ?string
    {
        return null;
    }

    public function currentState(StoredChange $change): ?array
    {
        return ['v' => $GLOBALS['widgets'][$change->objectId] ?? null];
    }

    public function volatileFields(): array
    {
        return [];
    }

    public function restore(StoredChange $change): void
    {
        $GLOBALS['widgets'][$change->objectId] = $change->before['v'] ?? null;
    }
}
