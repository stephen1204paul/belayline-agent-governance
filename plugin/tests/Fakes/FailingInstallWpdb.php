<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Fakes;

use RuntimeException;
use wpdb;

/** A wpdb whose schema install blows up, as when the DB user cannot CREATE TABLE. */
final class FailingInstallWpdb extends wpdb
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides wpdb::get_charset_collate().
    public function get_charset_collate(): string
    {
        throw new RuntimeException('cannot create tables');
    }
}
