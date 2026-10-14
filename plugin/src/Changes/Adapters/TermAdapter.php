<?php

declare(strict_types=1);

namespace Belayline\Plugin\Changes\Adapters;

use Belayline\Changes\CaptureWindow;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\ChangeCaptureAdapter;
use Belayline\Changes\ChangeRestoreAdapter;
use Belayline\Changes\StoredChange;

/**
 * Terms: edit_terms / edited_terms, created_term. The subtype is the taxonomy.
 *
 * Restore covers edits (name, slug, description, parent). A CREATED term is
 * refused: terms have no trash, and deleting one is not recorded by the
 * capture hooks, so the delete would be unrecorded and un-undoable. The
 * reason code is {@see REASON_TERM_CREATION}.
 */
final class TermAdapter implements ChangeCaptureAdapter, ChangeRestoreAdapter
{
    use RecordsChanges;

    public const REASON_TERM_CREATION = 'term_creation';

    private const WRITABLE = ['name', 'slug', 'description', 'parent'];
    private const VOLATILE = ['term_id', 'term_taxonomy_id', 'term_group', 'taxonomy', 'count'];

    /** @var array<string, array<string, mixed>> Term snapshots taken at edit_terms. */
    private array $pendingTerms = [];

    public function objectTypes(): array
    {
        return ['term'];
    }

    public function attach(CaptureWindow $window): void
    {
        $this->window = $window;
        if ($this->attached) {
            return;
        }
        $this->attached = true;
        $this->pendingTerms = [];

        add_action('edit_terms', [$this, 'onEditTerms'], 10, 2);
        add_action('edited_terms', [$this, 'onEditedTerms'], 10, 2);
        add_action('created_term', [$this, 'onCreatedTerm'], 10, 3);
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }
        $this->attached = false;

        remove_action('edit_terms', [$this, 'onEditTerms'], 10);
        remove_action('edited_terms', [$this, 'onEditedTerms'], 10);
        remove_action('created_term', [$this, 'onCreatedTerm'], 10);
    }

    public function onEditTerms(int $termId, string $taxonomy = ''): void
    {
        $this->guard('term', function () use ($termId, $taxonomy): void {
            $term = WpObjects::term(get_term($termId, $taxonomy));
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
            $after = WpObjects::term(get_term($termId, $taxonomy));
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
            $after = WpObjects::term(get_term($termId, $taxonomy));
            if ($after !== null) {
                $this->record('term', (string) $termId, $taxonomy, ChangeAction::Create, null, $after);
            }
        });
    }

    public function capability(StoredChange $change): string
    {
        return 'manage_options';
    }

    public function canRestore(StoredChange $change): ?string
    {
        return $change->action === ChangeAction::Update ? null : self::REASON_TERM_CREATION;
    }

    public function currentState(StoredChange $change): ?array
    {
        return WpObjects::term(get_term((int) $change->objectId, (string) $change->objectSubtype));
    }

    public function volatileFields(): array
    {
        return self::VOLATILE;
    }

    public function restore(StoredChange $change): void
    {
        $args = [];
        foreach ($change->changedFields as $field) {
            if (in_array($field, self::WRITABLE, true)) {
                $args[$field] = $change->before[$field] ?? '';
            }
        }
        if ($args === []) {
            throw new \RuntimeException('No writable term field to restore.');
        }

        $result = wp_update_term((int) $change->objectId, (string) $change->objectSubtype, wp_slash($args));
        if (is_wp_error($result)) {
            throw new \RuntimeException('wp_update_term failed');
        }
    }
}
