<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Fakes;

use RuntimeException;

/** Thrown by the wp_safe_redirect() shim instead of redirecting and exiting, so a test can read the target. */
final class RedirectException extends RuntimeException
{
    public function __construct(public readonly string $location, public readonly int $status)
    {
        parent::__construct('redirect: ' . $location);
    }
}
