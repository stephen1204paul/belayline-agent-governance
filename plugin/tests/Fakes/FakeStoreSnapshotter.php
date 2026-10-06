<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use RuntimeException;
use Specflux\AgentSafety\Changes\ChangeSnapshotter;
use Specflux\AgentSafety\Changes\SnapshotRef;

final class FakeStoreSnapshotter implements ChangeSnapshotter
{
    /** @var list<SnapshotRef> */
    public array $extraRefs = [];
    public string $failOn = '';
    private int $calls = 0;

    public function handles(string $ability): bool
    {
        return $ability === 'shop/set-price';
    }

    public function refs(string $ability, array $args): array
    {
        $refs = [];
        foreach ($args['ids'] ?? [] as $id) {
            $refs[] = new SnapshotRef('price', (string) $id);
        }

        return [...$refs, ...$this->extraRefs];
    }

    public function snapshot(SnapshotRef $ref): ?array
    {
        $this->calls++;
        if (($this->failOn === 'before' && $this->calls === 1) || ($this->failOn === 'after' && $this->calls === 2)) {
            throw new RuntimeException('remote API down');
        }

        return $GLOBALS['store'][$ref->objectType . ':' . $ref->objectId] ?? null;
    }
}
