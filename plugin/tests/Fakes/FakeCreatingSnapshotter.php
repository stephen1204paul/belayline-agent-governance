<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use RuntimeException;
use Specflux\AgentSafety\Changes\ResultAwareSnapshotter;
use Specflux\AgentSafety\Changes\SnapshotRef;

/** Handles `shop/create-price`; the new id exists only in the call's result (`['id' => ...]`). */
final class FakeCreatingSnapshotter implements ResultAwareSnapshotter
{
    /** @var list<SnapshotRef> */
    public array $argRefs = [];
    public bool $failRefsFromResult = false;

    public function handles(string $ability): bool
    {
        return $ability === 'shop/create-price';
    }

    public function refs(string $ability, array $args): array
    {
        return $this->argRefs;
    }

    public function refsFromResult(string $ability, array $args, mixed $result): array
    {
        if ($this->failRefsFromResult) {
            throw new RuntimeException('bad result');
        }

        return is_array($result) && isset($result['id']) ? [new SnapshotRef('price', (string) $result['id'])] : [];
    }

    public function snapshot(SnapshotRef $ref): ?array
    {
        return $GLOBALS['store'][$ref->objectType . ':' . $ref->objectId] ?? null;
    }
}
