<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\ChangeSnapshotter;
use Specflux\AgentSafety\Changes\ResultAwareSnapshotter;

final class ResultAwareSnapshotterTest extends TestCase
{
    public function testItExtendsTheArgsBasedSeam(): void
    {
        $this->assertTrue(is_subclass_of(ResultAwareSnapshotter::class, ChangeSnapshotter::class));
        $this->assertTrue((new \ReflectionMethod(ResultAwareSnapshotter::class, 'refsFromResult'))->isPublic());
    }
}
