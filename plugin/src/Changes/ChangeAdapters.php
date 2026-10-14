<?php

declare(strict_types=1);

namespace Belayline\Plugin\Changes;

use Belayline\Changes\ChangeCaptureAdapter;
use Belayline\Changes\ChangeRestoreAdapter;
use Belayline\Changes\ChangeSnapshotter;
use Belayline\Plugin\Changes\Adapters\OptionAdapter;
use Belayline\Plugin\Changes\Adapters\PostAdapter;
use Belayline\Plugin\Changes\Adapters\PostMetaAdapter;
use Belayline\Plugin\Changes\Adapters\TermAdapter;

/**
 * The adapter registry. The built-ins (post, post meta, term, option) are
 * passed through the `belayline_change_adapters` filter, where an
 * integration appends its own objects: any mix of {@see ChangeCaptureAdapter},
 * {@see ChangeSnapshotter} and {@see ChangeRestoreAdapter} (one class may
 * implement several). Anything else in the list is dropped.
 *
 * Resolved once per instance, on first use (so the filter may be added any
 * time before the first governed call) and memoized: capture adapters
 * register and remove their hooks by object identity.
 *
 * Restore lookup is first-wins per object type, so a filter can add types but
 * never take over a built-in one.
 */
final class ChangeAdapters
{
    public const FILTER = 'belayline_change_adapters';

    /** @var list<object>|null */
    private ?array $resolved = null;

    /** @param list<object>|null $builtins Defaults to the four built-in adapters. */
    public function __construct(private readonly ?array $builtins = null)
    {
    }

    /** @return list<ChangeCaptureAdapter> */
    public function capture(): array
    {
        return array_values(array_filter($this->all(), static fn (object $a): bool => $a instanceof ChangeCaptureAdapter));
    }

    /** @return list<ChangeSnapshotter> */
    public function snapshotters(): array
    {
        return array_values(array_filter($this->all(), static fn (object $a): bool => $a instanceof ChangeSnapshotter));
    }

    public function restoreFor(string $objectType): ?ChangeRestoreAdapter
    {
        foreach ($this->all() as $adapter) {
            if ($adapter instanceof ChangeRestoreAdapter && in_array($objectType, $adapter->objectTypes(), true)) {
                return $adapter;
            }
        }

        return null;
    }

    /** @return list<object> */
    private function all(): array
    {
        if ($this->resolved === null) {
            $builtins = $this->builtins ?? [new PostAdapter(), new PostMetaAdapter(), new TermAdapter(), new OptionAdapter()];
            $filtered = apply_filters('belayline_change_adapters', $builtins);
            $this->resolved = [];
            foreach (is_array($filtered) ? $filtered : $builtins as $adapter) {
                if (is_object($adapter) && ($adapter instanceof ChangeCaptureAdapter || $adapter instanceof ChangeSnapshotter || $adapter instanceof ChangeRestoreAdapter)) {
                    $this->resolved[] = $adapter;
                }
            }
        }

        return $this->resolved;
    }
}
