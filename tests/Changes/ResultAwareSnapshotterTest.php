<?php

declare(strict_types=1);

namespace Belayline\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Belayline\Changes\ChangeSnapshotter;
use Belayline\Changes\ResultAwareSnapshotter;

final class ResultAwareSnapshotterTest extends TestCase
{
    public function testItExtendsTheArgsBasedSeam(): void
    {
        $this->assertTrue(is_subclass_of(ResultAwareSnapshotter::class, ChangeSnapshotter::class));
        $this->assertTrue((new \ReflectionMethod(ResultAwareSnapshotter::class, 'refsFromResult'))->isPublic());
    }
}
