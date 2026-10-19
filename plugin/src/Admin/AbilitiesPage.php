<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Tiers;
use Belayline\Plugin\Api\AbilityReviews;
use Belayline\Plugin\Reviews\ReviewedAbilitiesPack;
use Belayline\Plugin\Reviews\ReviewTier;
use Belayline\Plugin\Support\AdminChangeRecorder;

/**
 * Packs tab, "Abilities from other plugins": review third-party abilities
 * without code.
 *
 * Lists every registered ability that no integration already governs, grouped
 * by namespace, with its label, description and annotations, and lets an
 * administrator give each one a tier: Runs freely (Read), Needs approval
 * (Write), Always asks (Irreversible) or Blocked. One form per namespace, with a
 * bulk selector.
 *
 * Annotations suggest, the administrator decides: an ability that declares
 * `readonly: true` is PRE-SELECTED as Runs freely, but the choice only counts
 * once the form is saved. Saving reviews the whole namespace's governance too:
 * the namespace becomes governed, and its unreviewed abilities are then refused
 * `unknown_verb`. The form says so and, for a namespace with no review yet,
 * refuses to save until the administrator ticks that they understand.
 *
 * The old `tools.php?page=belayline-abilities` URL (which Site Agent's setup
 * check links to) redirects to this section, see {@see AdminUrls::LEGACY}.
 */
final class AbilitiesPage
{
    private const CAP = 'manage_options';
    private const SAVE = 'belayline_save_ability_reviews';

    /** @var callable(): list<array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed}> */
    private $lister;

    /**
     * @param list<string>  $integrationNamespaces Namespaces something other than a review already governs; never listed.
     * @param callable|null $lister                Returns the registered abilities; defaults to wp_get_abilities().
     */
    public function __construct(
        private readonly AbilityReviews $reviews,
        private readonly array $integrationNamespaces = [],
        private readonly AdminChangeRecorder $changes = new AdminChangeRecorder(),
        ?callable $lister = null,
    ) {
        $this->lister = $lister ?? [self::class, 'registeredAbilities'];
    }

    public function register(): void
    {
        add_action('admin_post_' . self::SAVE, [$this, 'save']);
    }

    /**
     * The tier an ability's own annotations SUGGEST. Never applied by itself.
     *
     * @param array<string, mixed> $annotations
     */
    public static function suggestion(array $annotations): ?ReviewTier
    {
        return true === ($annotations['readonly'] ?? null) ? ReviewTier::Read : null;
    }

    /**
     * Default lister: the Abilities API registry, when this WordPress has one.
     *
     * @return list<array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed}>
     */
    public static function registeredAbilities(): array
    {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $out = [];
        foreach ((array) wp_get_abilities() as $ability) {
            if (!is_object($ability) || !method_exists($ability, 'get_name')) {
                continue;
            }
            $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : [];
            $annotations = is_array($meta) && isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];
            $out[] = [
                'name' => (string) $ability->get_name(),
                'label' => method_exists($ability, 'get_label') ? (string) $ability->get_label() : '',
                'description' => method_exists($ability, 'get_description') ? (string) $ability->get_description() : '',
                'annotations' => $annotations,
                'schema' => method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null,
            ];
        }

        return $out;
    }

    /**
     * Reviewable abilities by namespace prefix: everything registered that an
     * integration does not already govern.
     *
     * @return array<string, list<array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed}>>
     */
    public function reviewable(): array
    {
        $by = [];
        foreach (($this->lister)() as $ability) {
            $ns = AbilityReviews::namespaceOf($ability['name']);
            if ($ns === '' || $this->governedByIntegration($ability['name'])) {
                continue;
            }
            $by[$ns][] = $ability;
        }
        ksort($by);

        return $by;
    }

    private function governedByIntegration(string $name): bool
    {
        foreach ($this->integrationNamespaces as $ns) {
            if ($ns !== '' && str_starts_with($name, $ns)) {
                return true;
            }
        }

        return false;
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $this->renderMain();
    }

    /** The "Abilities from other plugins" card for the Packs tab. Renders nothing when there is nothing to review. */
    public function renderMain(): void
    {
        $groups = $this->reviewable();
        $this->notices();

        echo '<section class="aui-card" id="abilities" aria-labelledby="belayline-abilities-t"><div class="aui-card-h"><div><h2 class="aui-card-t" id="belayline-abilities-t">'
            . esc_html__('Abilities from other plugins', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('Other plugins can offer abilities to AI agents. Until you review them here, Belayline does not govern them. Pick what should happen when an agent calls each one. What an ability says about itself is only a suggestion: nothing applies until you save.', 'belayline-agent-governance')
            . '</p></div></div>';

        if ($groups === []) {
            echo '<div class="aui-empty"><span class="aui-tile">' . Icons::svg('plug', 'aui-i--lg') . '</span><h3 class="aui-card-t">' . esc_html__('Nothing to review', 'belayline-agent-governance') . '</h3><p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.
                . esc_html__('No other plugin offers abilities that Belayline does not already cover.', 'belayline-agent-governance') . '</p></div></section>';

            return;
        }

        echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html(sprintf(
            /* translators: %s: pack name */
            __('An agent can only call a reviewed ability if its credential is bound to the "%s" pack, or to a pack that allows it.', 'belayline-agent-governance'),
            ReviewedAbilitiesPack::NAME
        )) . '</p></div>';

        $entries = $this->reviews->entries();
        echo '<div class="aui-groups">';
        $first = true;
        foreach ($groups as $ns => $abilities) {
            $this->renderNamespace($ns, $abilities, $entries, $first);
            $first = false;
        }
        echo '</div></section>';
    }

    private function notices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash flags.
        if (isset($_GET['belayline_saved']) && $_GET['belayline_saved'] === 'abilities') {
            echo Html::notice('success', __('Review saved.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        }
        if (isset($_GET['belayline_confirm'])) {
            echo Html::notice('error', __('Nothing was saved.', 'belayline-agent-governance'), __('Tick the box to confirm that reviewing one ability puts its whole group under review.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * @param list<array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed}> $abilities
     * @param array<string, array{tier: ReviewTier, schema: string, reviewed_by: int, reviewed_at: string}> $entries
     */
    private function renderNamespace(string $ns, array $abilities, array $entries, bool $open): void
    {
        $governed = in_array($ns, $this->reviews->namespaces(), true);
        $id = 'belayline-ns-' . sanitize_key($ns);
        $counts = [];
        $rows = '';
        foreach ($abilities as $ability) {
            $selected = $this->selectedTier($ability, $entries[$ability['name']] ?? null);
            $key = $selected === null ? Tiers::NONE : self::tierKey($selected);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $rows .= $this->renderRow($ability, $entries[$ability['name']] ?? null, $selected);
        }

        echo Html::postForm(self::SAVE, self::SAVE, '<input type="hidden" name="namespace" value="' . esc_attr($ns) . '">', 'class="belayline-groupform"'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
        echo '<div class="aui-group"><div class="aui-group-h"><button type="button" class="aui-group-toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . esc_attr($id) . '">'
            . '<span class="aui-group-name">' . esc_html($ns) . '</span>' . ($governed ? ' ' . Html::badge('ok', __('Under review', 'belayline-agent-governance')) : '') // phpcs:ignore WordPress.Security.EscapeOutput -- Html::badge() escapes.
            . '<span class="aui-leader"></span>' . Tiers::metaSpan(count($abilities), $counts, true) // phpcs:ignore WordPress.Security.EscapeOutput -- metaSpan() escapes.
            . Icons::svg('caret-down') . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.
        echo '<div class="aui-group-b" id="' . esc_attr($id) . '"' . ($open ? '' : ' hidden') . '>';

        echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput -- each row is built from esc_* helpers in renderRow().

        echo '<div class="aui-card-b belayline-stack"><div class="aui-field"><label class="aui-label" for="' . esc_attr($id) . '-bulk">' . esc_html__('Or set every ability in this group to', 'belayline-agent-governance') . '</label>';
        echo '<select class="aui-select belayline-narrow" id="' . esc_attr($id) . '-bulk" name="bulk"><option value="">' . esc_html__('Keep the choices above', 'belayline-agent-governance') . '</option>';
        foreach (ReviewTier::cases() as $case) {
            echo '<option value="' . esc_attr($case->value) . '">' . esc_html(Tiers::label(self::tierKey($case))) . '</option>';
        }
        echo '<option value="' . esc_attr(self::CLEAR) . '">' . esc_html(Tiers::label(Tiers::NONE)) . '</option></select></div>';

        echo Html::notice('warning', __('Reviewing one ability puts the whole group under review.', 'belayline-agent-governance'), __('Anything in it you leave as Not reviewed will then be refused instead of running unchecked.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        if (!$governed) {
            echo '<label class="belayline-check"><input type="checkbox" name="confirm" value="1"> ' . esc_html__('I understand: this group goes under review and anything not reviewed is refused.', 'belayline-agent-governance') . '</label>';
        }
        echo '</div><div class="aui-card-f"><button type="submit" class="aui-btn aui-btn--primary">' . esc_html__('Save review', 'belayline-agent-governance') . '</button></div>';
        echo '</div></div></form>';
    }

    /** Tier key (see {@see Tiers}) for a review tier. */
    private static function tierKey(ReviewTier $tier): string
    {
        return match ($tier) {
            ReviewTier::Read => Tiers::FREE,
            ReviewTier::Write => Tiers::APPROVAL,
            ReviewTier::Irreversible => Tiers::ALWAYS,
            ReviewTier::Blocked => Tiers::BLOCKED,
        };
    }

    /**
     * The tier a review row shows as selected: a current review wins; otherwise
     * the annotation SUGGESTS (and only pre-selects: it is stored when, and only
     * when, the form is saved).
     *
     * @param array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed} $ability
     * @param array{tier: ReviewTier, schema: string, reviewed_by: int, reviewed_at: string}|null $entry
     */
    private function selectedTier(array $ability, ?array $entry): ?ReviewTier
    {
        $drifted = $entry !== null && $entry['schema'] !== AbilityReviews::schemaHash($ability['schema']);

        return $entry !== null && !$drifted ? $entry['tier'] : ($entry === null ? self::suggestion($ability['annotations']) : null);
    }

    /**
     * @param array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed} $ability
     * @param array{tier: ReviewTier, schema: string, reviewed_by: int, reviewed_at: string}|null $entry
     */
    private function renderRow(array $ability, ?array $entry, ?ReviewTier $selected): string
    {
        $drifted = $entry !== null && $entry['schema'] !== AbilityReviews::schemaHash($ability['schema']);
        $current = $selected === null ? '' : $selected->value;

        $out = '<div class="aui-item belayline-ability"><div class="aui-grow"><div class="aui-item-name">' . esc_html($ability['label'] !== '' ? $ability['label'] : $ability['name'])
            . ' <code>' . esc_html($ability['name']) . '</code></div>';
        if ($ability['description'] !== '') {
            $out .= '<div class="aui-muted">' . esc_html($ability['description']) . '</div>';
        }
        if ($drifted) {
            $out .= '<div class="belayline-warn">' . esc_html__('Its inputs changed since you last reviewed it. Calls are refused until you save again.', 'belayline-agent-governance') . '</div>';
        } elseif ($entry === null && $selected !== null) {
            $out .= '<div class="aui-muted">' . esc_html__('Suggested because the plugin says it only reads. Not applied until you save.', 'belayline-agent-governance') . '</div>';
        }
        $out .= '</div><div class="aui-seg aui-seg--sm" role="group" data-name="' . esc_attr('tier[' . $ability['name'] . ']') . '" aria-label="'
            . esc_attr(sprintf(/* translators: %s: ability name */ __('What happens when an agent calls %s', 'belayline-agent-governance'), $ability['name'])) . '">';
        $choices = [['', Tiers::NONE]];
        foreach (ReviewTier::cases() as $case) {
            $choices[] = [$case->value, self::tierKey($case)];
        }
        foreach ($choices as [$value, $key]) {
            $out .= '<button type="button" data-value="' . esc_attr($value) . '" aria-pressed="' . ($value === $current ? 'true' : 'false') . '">' . esc_html(Tiers::label($key)) . '</button>';
        }
        $out .= '</div></div>';

        return $out;
    }

    private const CLEAR = 'none';

    public function save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::SAVE);

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- applyNamespace() below sanitises and allow-list-validates every value.
        $ns = isset($_POST['namespace']) && is_string($_POST['namespace']) ? wp_unslash($_POST['namespace']) : '';
        $tiers = isset($_POST['tier']) && is_array($_POST['tier']) ? wp_unslash($_POST['tier']) : [];
        $bulk = isset($_POST['bulk']) && is_string($_POST['bulk']) ? wp_unslash($_POST['bulk']) : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $confirmed = isset($_POST['confirm']) && '1' === $_POST['confirm'];

        $saved = $this->applyNamespace((string) $ns, (array) $tiers, (string) $bulk, $confirmed, get_current_user_id());

        wp_safe_redirect(AdminUrls::tab(AdminUrls::PACKS, [$saved ? 'belayline_saved' : 'belayline_confirm' => $saved ? 'abilities' : '1'], 'abilities'));
        exit;
    }

    /**
     * Persist one namespace's form. Split from {@see save()} so it can be
     * exercised without a nonce, a redirect or an exit.
     *
     * A namespace with no review yet needs $confirmed: adding the first review
     * governs every sibling. Returns false, writing nothing, when it is missing.
     *
     * @param array<mixed> $tiers Raw `tier[ability]` values.
     */
    public function applyNamespace(string $ns, array $tiers, string $bulk, bool $confirmed, int $userId): bool
    {
        $ns = sanitize_text_field($ns);
        $abilities = $this->reviewable()[$ns] ?? [];

        $choices = [];
        $schemas = [];
        foreach ($abilities as $ability) {
            $name = $ability['name'];
            $raw = $bulk !== '' ? $bulk : (is_string($tiers[$name] ?? null) ? $tiers[$name] : '');
            $raw = sanitize_text_field($raw);
            $choices[$name] = $raw === self::CLEAR ? '' : $raw;
            $schemas[$name] = AbilityReviews::schemaHash($ability['schema']);
        }

        $adds = array_filter($choices, static fn (string $v): bool => ReviewTier::tryFrom($v) !== null);
        if ($adds !== [] && !$confirmed && !in_array($ns, $this->reviews->namespaces(), true)) {
            return false;
        }

        $this->reviews->apply($choices, $schemas, $userId, $this->changes);

        return true;
    }
}
