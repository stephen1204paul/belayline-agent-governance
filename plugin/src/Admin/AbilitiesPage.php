<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Plugin\Api\AbilityReviews;
use Specflux\AgentSafety\Plugin\Reviews\ReviewedAbilitiesPack;
use Specflux\AgentSafety\Plugin\Reviews\ReviewTier;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;

/**
 * Tools, "Agent Abilities": review third-party abilities without code.
 *
 * Lists every registered ability that no integration already governs, grouped
 * by namespace, with its label, description and annotations, and lets an
 * administrator give each one a tier: Read, Write (approval needed),
 * Irreversible, or Blocked. One form per namespace, with a bulk selector.
 *
 * Annotations suggest, the administrator decides: an ability that declares
 * `readonly: true` is PRE-SELECTED as Read, but the choice only counts once the
 * form is saved. Saving reviews the whole namespace's governance too: the
 * namespace becomes governed, and its unreviewed abilities are then refused
 * `unknown_verb`. The form says so and, for a namespace with no review yet,
 * refuses to save until the administrator ticks that they understand.
 *
 * Page slug `agent-safety-abilities`: SenroFlux's setup check links to it.
 */
final class AbilitiesPage
{
    public const SLUG = 'agent-safety-abilities';
    private const CAP = 'manage_options';
    private const SAVE = 'agsafe_save_ability_reviews';

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
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::SAVE, [$this, 'save']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Agent Abilities', 'senrogate'),
            __('Agent Abilities', 'senrogate'),
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );
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

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agent Abilities', 'senrogate') . '</h1>';
        echo '<p>' . esc_html__('Abilities added by other plugins are not governed until you review them here. Each one gets a tier; a call is then judged like any other governed call. What an ability says about itself (its annotations) is a suggestion only: nothing applies until you save.', 'senrogate') . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: pack name */
            __('A reviewed ability is only callable by a credential bound to the "%s" pack (Tools, Agent Capability Packs), or to a pack that allows it.', 'senrogate'),
            ReviewedAbilitiesPack::NAME
        )) . '</p>';
        $this->notices();

        $groups = $this->reviewable();
        if ($groups === []) {
            echo '<p>' . esc_html__('No abilities to review. Abilities in namespaces SenroGate already governs are not listed.', 'senrogate') . '</p></div>';

            return;
        }

        $entries = $this->reviews->entries();
        foreach ($groups as $ns => $abilities) {
            $this->renderNamespace($ns, $abilities, $entries);
        }
        echo '</div>';
    }

    private function notices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash flags.
        if (isset($_GET['agsafe_saved'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Review saved.', 'senrogate') . '</p></div>';
        }
        if (isset($_GET['agsafe_confirm'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Nothing was saved. Tick the box to confirm that reviewing one ability governs its whole namespace.', 'senrogate') . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * @param list<array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed}> $abilities
     * @param array<string, array{tier: ReviewTier, schema: string, reviewed_by: int, reviewed_at: string}> $entries
     */
    private function renderNamespace(string $ns, array $abilities, array $entries): void
    {
        $governed = in_array($ns, $this->reviews->namespaces(), true);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="card" style="max-width:none;margin-top:16px;">';
        echo '<h2 style="margin-top:0;"><code>' . esc_html($ns) . '</code> ';
        echo $governed ? '<small>' . esc_html__('(governed)', 'senrogate') . '</small>' : '';
        echo '</h2>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '" />';
        echo '<input type="hidden" name="namespace" value="' . esc_attr($ns) . '" />';
        wp_nonce_field(self::SAVE);

        echo '<p><label>' . esc_html__('Set every ability in this namespace to:', 'senrogate') . ' ';
        echo '<select name="bulk"><option value="">' . esc_html__('(keep the choices below)', 'senrogate') . '</option>';
        foreach (ReviewTier::cases() as $case) {
            echo '<option value="' . esc_attr($case->value) . '">' . esc_html($case->label()) . '</option>';
        }
        echo '<option value="' . esc_attr(self::CLEAR) . '">' . esc_html__('Not reviewed', 'senrogate') . '</option>';
        echo '</select></label></p>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Ability', 'senrogate'), __('Description', 'senrogate'), __('Annotations', 'senrogate'), __('Review', 'senrogate')] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($abilities as $ability) {
            $this->renderRow($ability, $entries[$ability['name']] ?? null);
        }
        echo '</tbody></table>';

        echo '<p><strong>' . esc_html__('Warning:', 'senrogate') . '</strong> ';
        echo esc_html__('Reviewing one ability governs the whole namespace. Every ability in it that you leave unreviewed will then be refused (unknown_verb) instead of running ungoverned.', 'senrogate') . '</p>';
        if (!$governed) {
            echo '<p><label><input type="checkbox" name="confirm" value="1" /> ';
            echo esc_html__('I understand: this namespace becomes governed and its unreviewed abilities are refused.', 'senrogate') . '</label></p>';
        }
        submit_button(__('Save review', 'senrogate'), 'primary', 'submit', false);
        echo '</form>';
    }

    /**
     * @param array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed} $ability
     * @param array{tier: ReviewTier, schema: string, reviewed_by: int, reviewed_at: string}|null $entry
     */
    private function renderRow(array $ability, ?array $entry): void
    {
        $drifted = $entry !== null && $entry['schema'] !== AbilityReviews::schemaHash($ability['schema']);
        // A current review wins; otherwise the annotation SUGGESTS (and only
        // pre-selects: it is stored when, and only when, the form is saved).
        $selected = $entry !== null && !$drifted ? $entry['tier'] : ($entry === null ? self::suggestion($ability['annotations']) : null);

        echo '<tr><td><strong>' . esc_html($ability['label'] !== '' ? $ability['label'] : $ability['name']) . '</strong><br /><code>' . esc_html($ability['name']) . '</code></td>';
        echo '<td>' . esc_html($ability['description']) . '</td>';
        $notes = [];
        foreach ($ability['annotations'] as $key => $value) {
            if (is_bool($value)) {
                $notes[] = (string) $key . ': ' . ($value ? 'true' : 'false');
            }
        }
        echo '<td>' . esc_html($notes === [] ? '-' : implode(', ', $notes)) . '</td>';

        echo '<td><select name="tier[' . esc_attr($ability['name']) . ']">';
        echo '<option value="">' . esc_html__('Not reviewed', 'senrogate') . '</option>';
        foreach (ReviewTier::cases() as $case) {
            echo '<option value="' . esc_attr($case->value) . '"' . selected($selected === $case, true, false) . '>' . esc_html($case->label()) . '</option>';
        }
        echo '</select>';
        if ($drifted) {
            echo '<br /><em>' . esc_html__('Its input schema changed since the last review. Calls are refused until you save again.', 'senrogate') . '</em>';
        } elseif ($entry === null && $selected !== null) {
            echo '<br /><em>' . esc_html__('Suggested from its annotations, not applied yet.', 'senrogate') . '</em>';
        }
        echo '</td></tr>';
    }

    private const CLEAR = 'none';

    public function save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'senrogate'));
        }
        check_admin_referer(self::SAVE);

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- applyNamespace() below sanitises and allow-list-validates every value.
        $ns = isset($_POST['namespace']) && is_string($_POST['namespace']) ? wp_unslash($_POST['namespace']) : '';
        $tiers = isset($_POST['tier']) && is_array($_POST['tier']) ? wp_unslash($_POST['tier']) : [];
        $bulk = isset($_POST['bulk']) && is_string($_POST['bulk']) ? wp_unslash($_POST['bulk']) : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $confirmed = isset($_POST['confirm']) && '1' === $_POST['confirm'];

        $saved = $this->applyNamespace((string) $ns, (array) $tiers, (string) $bulk, $confirmed, get_current_user_id());

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, $saved ? 'agsafe_saved' : 'agsafe_confirm' => '1'],
            admin_url('tools.php')
        ));
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
