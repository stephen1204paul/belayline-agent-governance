<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo\Changes;

/**
 * Late-bound access to WooCommerce's CRUD classes and functions. WooCommerce
 * is not a dependency of this repo (the classes exist only on a live site that
 * passed WooIntegration::available()), so every call goes through a string
 * name: nothing here is resolved until a Woo window is actually running.
 */
final class WooObjects
{
    /** @param mixed ...$args */
    public static function call(object $object, string $method, ...$args): mixed
    {
        $callable = [$object, $method];
        if (!is_callable($callable)) {
            throw new \BadMethodCallException($method);
        }

        return $callable(...$args);
    }

    public static function can(object $object, string $method): bool
    {
        return is_callable([$object, $method]);
    }

    /** @param mixed ...$args */
    public static function make(string $class, ...$args): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException($class . ' is not loaded');
        }

        return new $class(...$args);
    }

    /** @param mixed ...$args */
    public static function fn(string $function, ...$args): mixed
    {
        if (!is_callable($function)) {
            throw new \RuntimeException($function . '() is not available');
        }

        return $function(...$args);
    }

    /** @param mixed ...$args */
    public static function stat(string $class, string $method, ...$args): mixed
    {
        $callable = [$class, $method];
        if (!is_callable($callable)) {
            throw new \RuntimeException($class . '::' . $method . '() is not available');
        }

        return $callable(...$args);
    }

    public static function is(mixed $object, string $class): bool
    {
        return is_object($object) && is_a($object, $class);
    }
}
