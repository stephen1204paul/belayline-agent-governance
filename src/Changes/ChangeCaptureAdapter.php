<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/**
 * Hook-based capture for one or more object types. The host calls
 * {@see attach()} when the outermost capture window opens and {@see detach()}
 * when it closes, so an adapter registers its WordPress listeners only while
 * a window is open. Listeners record via {@see CaptureWindow::record()} and
 * must never alter the write they observe or throw into it.
 */
interface ChangeCaptureAdapter
{
    /** @return list<string> object types this adapter produces (`post`, `term`, ...). */
    public function objectTypes(): array;

    public function attach(CaptureWindow $window): void;

    public function detach(): void;
}
