<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes\Adapters;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeCaptureAdapter;
use Specflux\AgentSafety\Changes\ChangeRestoreAdapter;
use Specflux\AgentSafety\Changes\StoredChange;

/**
 * Posts, pages and `wp_navigation` (a post type): post_updated,
 * wp_insert_post, wp_trash_post, before_delete_post.
 *
 * Restore: updates write the recorded `before` values of the changed fields
 * back through wp_update_post; a creation is moved to the trash (never
 * deleted); a trash is reversed with wp_untrash_post. A permanent delete is
 * never restored: the post row alone is recorded, not its meta, terms or
 * comments, so a "restore" would be a lossy re-creation.
 */
final class PostAdapter implements ChangeCaptureAdapter, ChangeRestoreAdapter
{
    use RecordsChanges;

    public const REASON_PERMANENT_DELETE = 'permanent_delete';

    /** Post types that are bookkeeping, not content an admin would want undone. */
    private const IGNORED_POST_TYPES = ['revision', 'oembed_cache', 'customize_changeset'];

    /** Never written back (identity, derived, or set by WordPress itself). */
    private const VOLATILE = ['ID', 'post_modified', 'post_modified_gmt', 'comment_count', 'guid'];

    /** @var array<int, true> Post ids already recorded as trashed by wp_trash_post. */
    private array $trashed = [];

    public function objectTypes(): array
    {
        return ['post'];
    }

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
        if ($this->attached) {
            return;
        }
        $this->attached = true;
        $this->trashed = [];

        add_action('post_updated', [$this, 'onPostUpdated'], 10, 3);
        add_action('wp_insert_post', [$this, 'onInsertPost'], 10, 3);
        add_action('wp_trash_post', [$this, 'onTrashPost'], 10, 2);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 2);
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }
        $this->attached = false;

        remove_action('post_updated', [$this, 'onPostUpdated'], 10);
        remove_action('wp_insert_post', [$this, 'onInsertPost'], 10);
        remove_action('wp_trash_post', [$this, 'onTrashPost'], 10);
        remove_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10);
    }

    /**
     * @param mixed $after  WP_Post
     * @param mixed $before WP_Post
     */
    public function onPostUpdated(int $postId, $after, $before): void
    {
        $this->guard('post', function () use ($postId, $after, $before): void {
            $a = WpObjects::post($after);
            $b = WpObjects::post($before);
            if ($a === null || $b === null || self::ignoredPost($a)) {
                return;
            }

            $id = (string) $postId;
            $action = ChangeAction::Update;
            if (($b['post_status'] ?? '') === 'auto-draft') {
                // The first real save of a fresh post: a creation, not an update
                // (undoing it must trash the post, not revert it to an auto-draft).
                $action = ChangeAction::Create;
                $b = null;
            } elseif (($a['post_status'] ?? '') === 'trash' && ($b['post_status'] ?? '') !== 'trash') {
                if (isset($this->trashed[$postId])) {
                    unset($this->trashed[$postId]);

                    return; // wp_trash_post already recorded this one.
                }
                $action = ChangeAction::Trash;
            }

            $this->record('post', $id, (string) ($a['post_type'] ?? ''), $action, $b, $a);
        });
    }

    /** @param mixed $post WP_Post */
    public function onInsertPost(int $postId, $post, bool $update): void
    {
        if ($update) {
            return; // post_updated records updates.
        }

        $this->guard('post', function () use ($postId, $post): void {
            $a = WpObjects::post($post);
            if ($a === null || self::ignoredPost($a) || ($a['post_status'] ?? '') === 'auto-draft') {
                return;
            }

            $this->record('post', (string) $postId, (string) ($a['post_type'] ?? ''), ChangeAction::Create, null, $a);
        });
    }

    public function onTrashPost(int $postId, string $previousStatus = ''): void
    {
        unset($previousStatus);

        $this->guard('post', function () use ($postId): void {
            $before = WpObjects::post(get_post($postId));
            if ($before === null || self::ignoredPost($before) || ($before['post_status'] ?? '') === 'trash') {
                return;
            }

            $this->trashed[$postId] = true;
            $this->record(
                'post',
                (string) $postId,
                (string) ($before['post_type'] ?? ''),
                ChangeAction::Trash,
                $before,
                ['post_status' => 'trash'] + $before,
            );
        });
    }

    /** @param mixed $post WP_Post|null */
    public function onBeforeDeletePost(int $postId, $post = null): void
    {
        $this->guard('post', function () use ($postId, $post): void {
            $before = WpObjects::post($post ?? get_post($postId));
            if ($before === null || self::ignoredPost($before)) {
                return;
            }

            $this->record('post', (string) $postId, (string) ($before['post_type'] ?? ''), ChangeAction::Delete, $before, null);
        });
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_options';
    }

    public function canRestore(StoredChange $change): ?string
    {
        return $change->action === ChangeAction::Delete ? self::REASON_PERMANENT_DELETE : null;
    }

    public function currentState(StoredChange $change): ?array
    {
        return WpObjects::post(get_post((int) $change->objectId));
    }

    public function volatileFields(): array
    {
        return self::VOLATILE;
    }

    public function restore(StoredChange $change): void
    {
        $id = (int) $change->objectId;

        switch ($change->action) {
            case ChangeAction::Create:
                // Moved to the trash, never deleted.
                if (!wp_trash_post($id)) {
                    throw new \RuntimeException('wp_trash_post refused');
                }

                return;

            case ChangeAction::Trash:
                wp_untrash_post($id);
                $status = (string) ($change->before['post_status'] ?? '');
                $now = WpObjects::post(get_post($id));
                if ($status !== '' && $now !== null && ($now['post_status'] ?? '') !== $status) {
                    self::update(['ID' => $id, 'post_status' => $status]);
                }

                return;

            case ChangeAction::Update:
                $data = ['ID' => $id];
                foreach ($change->changedFields as $field) {
                    if (!in_array($field, self::VOLATILE, true)) {
                        $data[$field] = $change->before[$field] ?? '';
                    }
                }
                self::update($data);

                return;

            default:
                throw new \RuntimeException('This change cannot be restored.');
        }
    }

    /** @param array<string, mixed> $data */
    private static function update(array $data): void
    {
        $result = wp_update_post(wp_slash($data), true);
        if (is_wp_error($result) || $result === 0) {
            throw new \RuntimeException('wp_update_post failed');
        }
    }

    /** @param array<string, mixed> $post */
    private static function ignoredPost(array $post): bool
    {
        return in_array($post['post_type'] ?? '', self::IGNORED_POST_TYPES, true);
    }
}
