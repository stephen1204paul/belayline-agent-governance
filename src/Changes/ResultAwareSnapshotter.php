<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/**
 * A snapshotter that can also name objects only the call's RESULT reveals:
 * creates on a remote platform, where the new object's id exists nowhere in
 * the input. After the call returns successfully, the host asks
 * {@see refsFromResult()} for them; each ref not already declared by
 * {@see refs()} is recorded as a creation (before = absent, after =
 * {@see snapshot()}) and claimed so hook adapters never record it too.
 *
 * Never asked when the call threw or returned a WP_Error/Throwable. A failure
 * inside it becomes a `capture_error` marker and the call's result is returned
 * unchanged.
 */
interface ResultAwareSnapshotter extends ChangeSnapshotter
{
    /**
     * @param array<string, mixed> $args
     * @return list<SnapshotRef>
     */
    public function refsFromResult(string $ability, array $args, mixed $result): array;
}
