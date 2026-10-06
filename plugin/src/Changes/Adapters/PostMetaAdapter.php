<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes\Adapters;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeCaptureAdapter;
use Specflux\AgentSafety\Changes\ChangeRestoreAdapter;
use Specflux\AgentSafety\Changes\StoredChange;

/**
 * Post meta via the `update_post_metadata` filter (reads the old value, returns
 * the check value untouched). The recorded object is the post id, the subtype
 * the meta key. Only single-value meta is captured faithfully: the old value
 * is read with `single = true`.
 */
final class PostMetaAdapter implements ChangeCaptureAdapter, ChangeRestoreAdapter
{
    use RecordsChanges;

    /** Post meta written on every editor load. */
    private const IGNORED_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_encloseme', '_pingme'];

    public function objectTypes(): array
    {
        return ['post_meta'];
    }

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
        if ($this->attached) {
            return;
        }
        $this->attached = true;

        // Late, so a filter that short-circuits the write (returns non-null)
        // is seen first and nothing is recorded for a write that won't happen.
        add_filter('update_post_metadata', [$this, 'onUpdatePostMeta'], 9999, 5);
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }
        $this->attached = false;

        remove_filter('update_post_metadata', [$this, 'onUpdatePostMeta'], 9999);
    }

    /**
     * @param mixed $check
     * @param mixed $metaValue
     * @param mixed $prevValue
     * @return mixed
     */
    public function onUpdatePostMeta($check, int $objectId, string $metaKey, $metaValue, $prevValue = '')
    {
        unset($prevValue);

        if ($check !== null || in_array($metaKey, self::IGNORED_META, true)) {
            return $check;
        }

        $this->guard('post_meta', function () use ($objectId, $metaKey, $metaValue): void {
            $exists = metadata_exists('post', $objectId, $metaKey);
            $old = $exists ? get_post_meta($objectId, $metaKey, true) : null;

            $this->record(
                'post_meta',
                (string) $objectId,
                $metaKey,
                $exists ? ChangeAction::Update : ChangeAction::Create,
                $exists ? [$metaKey => $old] : null,
                [$metaKey => $metaValue],
            );
        });

        return $check;
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_options';
    }

    public function canRestore(StoredChange $change): ?string
    {
        return $change->objectSubtype === null || $change->objectSubtype === '' ? 'no_meta_key' : null;
    }

    public function currentState(StoredChange $change): array
    {
        $key = (string) $change->objectSubtype;
        $id = (int) $change->objectId;

        return metadata_exists('post', $id, $key) ? [$key => get_post_meta($id, $key, true)] : [];
    }

    public function volatileFields(): array
    {
        return [];
    }

    public function restore(StoredChange $change): void
    {
        $key = (string) $change->objectSubtype;
        $id = (int) $change->objectId;

        if ($change->before === null) {
            delete_post_meta($id, $key); // the meta did not exist before the call

            return;
        }

        update_post_meta($id, $key, wp_slash($change->before[$key] ?? ''));
    }
}
