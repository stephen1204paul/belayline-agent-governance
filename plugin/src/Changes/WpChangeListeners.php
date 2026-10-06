<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\Change;
use Specflux\AgentSafety\Changes\ChangeAction;

/**
 * Listeners on WordPress's own change hooks, attached ONLY while a
 * {@see CaptureWindow} is open ({@see attach()} on the outermost open,
 * {@see detach()} on its close), so ordinary admin traffic pays nothing.
 *
 *   posts / pages / wp_navigation   post_updated, wp_insert_post, wp_trash_post, before_delete_post
 *   post meta                       update_post_metadata (reads the old value, returns null)
 *   terms                           edit_terms / edited_terms, created_term
 *   options                         update_option
 *
 * Raw SQL is not seen by any of these, and is not captured. WooCommerce
 * objects are not covered here.
 *
 * A listener must never alter the write it observes: it returns filter values
 * untouched, and any failure while recording is swallowed into a
 * `capture_error` marker change (never restorable) instead of propagating
 * into the agent's call.
 */
final class WpChangeListeners
{
    /** Post types that are bookkeeping, not content an admin would want undone. */
    private const IGNORED_POST_TYPES = ['revision', 'oembed_cache', 'customize_changeset'];

    /** Post meta written on every editor load. */
    private const IGNORED_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_encloseme', '_pingme'];

    /**
     * Options never captured: caches and cron, and this plugin's own state
     * (pause switch, shadow list, site binding, retention). An undo must never
     * be able to restore a control SenroGate itself owns.
     */
    private const IGNORED_OPTION_PREFIXES = ['_transient', '_site_transient', 'agsafe_', 'agent_safety'];
    private const IGNORED_OPTIONS = ['cron', 'rewrite_rules', 'recently_activated'];

    /** @var array<int, true> Post ids already recorded as trashed by wp_trash_post. */
    private array $trashed = [];

    /** @var array<string, array<string, mixed>> Term snapshots taken at edit_terms. */
    private array $pendingTerms = [];

    private bool $attached = false;

    public function __construct(private readonly CaptureWindow $window)
    {
    }

    public function attach(): void
    {
        if ($this->attached) {
            return;
        }
        $this->attached = true;
        $this->trashed = [];
        $this->pendingTerms = [];

        add_action('post_updated', [$this, 'onPostUpdated'], 10, 3);
        add_action('wp_insert_post', [$this, 'onInsertPost'], 10, 3);
        add_action('wp_trash_post', [$this, 'onTrashPost'], 10, 2);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 2);
        // Late, so a filter that short-circuits the write (returns non-null)
        // is seen first and nothing is recorded for a write that won't happen.
        add_filter('update_post_metadata', [$this, 'onUpdatePostMeta'], 9999, 5);
        add_action('edit_terms', [$this, 'onEditTerms'], 10, 2);
        add_action('edited_terms', [$this, 'onEditedTerms'], 10, 2);
        add_action('created_term', [$this, 'onCreatedTerm'], 10, 3);
        add_action('update_option', [$this, 'onUpdateOption'], 10, 3);
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
        remove_filter('update_post_metadata', [$this, 'onUpdatePostMeta'], 9999);
        remove_action('edit_terms', [$this, 'onEditTerms'], 10);
        remove_action('edited_terms', [$this, 'onEditedTerms'], 10);
        remove_action('created_term', [$this, 'onCreatedTerm'], 10);
        remove_action('update_option', [$this, 'onUpdateOption'], 10);
    }

    /**
     * @param mixed $after  WP_Post
     * @param mixed $before WP_Post
     */
    public function onPostUpdated(int $postId, $after, $before): void
    {
        $this->guard('post', function () use ($postId, $after, $before): void {
            $a = self::postArray($after);
            $b = self::postArray($before);
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
            $a = self::postArray($post);
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
            $before = self::postArray(get_post($postId));
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
            $before = self::postArray($post ?? get_post($postId));
            if ($before === null || self::ignoredPost($before)) {
                return;
            }

            $this->record('post', (string) $postId, (string) ($before['post_type'] ?? ''), ChangeAction::Delete, $before, null);
        });
    }

    /**
     * `update_post_metadata` filter: record, then return the check value
     * untouched so the write proceeds exactly as it would have.
     *
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

    public function onEditTerms(int $termId, string $taxonomy = ''): void
    {
        $this->guard('term', function () use ($termId, $taxonomy): void {
            $term = self::termArray(get_term($termId, $taxonomy));
            if ($term !== null) {
                $this->pendingTerms[$termId . ':' . $taxonomy] = $term;
            }
        });
    }

    public function onEditedTerms(int $termId, string $taxonomy = ''): void
    {
        $this->guard('term', function () use ($termId, $taxonomy): void {
            $key = $termId . ':' . $taxonomy;
            $before = $this->pendingTerms[$key] ?? null;
            unset($this->pendingTerms[$key]);
            $after = self::termArray(get_term($termId, $taxonomy));
            if ($before === null || $after === null) {
                return;
            }

            $this->record('term', (string) $termId, $taxonomy, ChangeAction::Update, $before, $after);
        });
    }

    public function onCreatedTerm(int $termId, int $ttId = 0, string $taxonomy = ''): void
    {
        unset($ttId);

        $this->guard('term', function () use ($termId, $taxonomy): void {
            $after = self::termArray(get_term($termId, $taxonomy));
            if ($after !== null) {
                $this->record('term', (string) $termId, $taxonomy, ChangeAction::Create, null, $after);
            }
        });
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public function onUpdateOption(string $option, $old, $new): void
    {
        if (in_array($option, self::IGNORED_OPTIONS, true)) {
            return;
        }
        foreach (self::IGNORED_OPTION_PREFIXES as $prefix) {
            if (str_starts_with($option, $prefix)) {
                return;
            }
        }

        $this->guard('option', function () use ($option, $old, $new): void {
            $this->record('option', $option, null, ChangeAction::Update, [$option => $old], [$option => $new]);
        });
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function record(string $type, string $id, ?string $subtype, ChangeAction $action, ?array $before, ?array $after): void
    {
        $change = Change::capture($type, $id, $subtype, $action, $before, $after, $this->window->redactsPii());
        if ($action === ChangeAction::Update && $change->changedFields === []) {
            return; // a write that changed nothing
        }

        $this->window->record($change);
    }

    /** Run a listener body only while a window is open, never letting it throw into the caller's write. */
    private function guard(string $kind, callable $body): void
    {
        if (!$this->window->isOpen()) {
            return;
        }

        try {
            $body();
        } catch (\Throwable) {
            // The change could not be recorded; say so in the history instead
            // of leaving a silent gap.
            $this->window->record(Change::capture('window', 'capture_error', $kind, ChangeAction::Irreversible, null, ['listener' => $kind]));
        }
    }

    /** @param array<string, mixed> $post */
    private static function ignoredPost(array $post): bool
    {
        return in_array($post['post_type'] ?? '', self::IGNORED_POST_TYPES, true);
    }

    /**
     * @param mixed $post
     * @return array<string, mixed>|null
     */
    private static function postArray($post): ?array
    {
        $data = self::objectArray($post);
        if ($data !== null) {
            unset($data['filter']);
        }

        return $data;
    }

    /**
     * @param mixed $term
     * @return array<string, mixed>|null
     */
    private static function termArray($term): ?array
    {
        $data = self::objectArray($term);
        if ($data !== null) {
            unset($data['filter']);
        }

        return $data;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function objectArray($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && !is_wp_error($value)) {
            $data = method_exists($value, 'to_array') ? $value->to_array() : get_object_vars($value);

            return is_array($data) ? $data : null;
        }

        return null;
    }
}
