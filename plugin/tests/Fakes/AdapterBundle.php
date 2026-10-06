<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

/**
 * Test helper: drives a set of capture adapters as one listener surface, so a
 * test can call `onPostUpdated()` / `onUpdateOption()` without caring which
 * adapter owns the hook.
 */
final class AdapterBundle
{
    /** @param list<object> $adapters */
    public function __construct(private readonly array $adapters)
    {
    }

    /**
     * @param list<mixed> $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        foreach ($this->adapters as $adapter) {
            if (method_exists($adapter, $method)) {
                return $adapter->{$method}(...$args);
            }
        }

        throw new \BadMethodCallException($method);
    }
}
