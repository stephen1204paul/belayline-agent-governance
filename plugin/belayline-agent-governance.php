<?php

/**
 * Plugin Name:       Belayline Agent Governance
 * Plugin URI:        https://github.com/stephen1204paul/belayline-agent-governance
 * Description:       Governs other plugins' agent tool calls: verb-tier gating, capability packs, human approval with a state-fingerprint check, and a tamper-evident audit log. Ships a WordPress-core module plus a WooCommerce integration module.
 * Version:           0.5.0
 * Requires PHP:      8.1
 * Requires at least: 7.0
 * Author:            Stephen Paul Samynathan
 * Author URI:        https://profiles.wordpress.org/stephen1204paul/
 * License:           GPL-2.0-or-later
 * Text Domain:       belayline-agent-governance
 *
 * Thin host: wires the security core (stephen1204paul/belayline-core) into
 * WordPress hooks. All decision logic lives in the package under ../src. The
 * plugin itself is WordPress-general; integrations are capability-pack
 * modules registered from Integrations\ — the WordPress-core module
 * (Integrations\Core) unconditionally on every site, WooCommerce-specific
 * wiring (Integrations\Woo) ONLY when WooCommerce is active.
 */

declare(strict_types=1);

namespace Belayline\Plugin;

// Kept immediately after the namespace declaration (not after the `use` block
// below): Plugin Check's direct-file-access scanner falls back to a
// first-50-line regex when its AST pass doesn't recognise the pattern, and
// this file's `use` list alone is longer than that.
if (!defined('ABSPATH')) {
    exit;
}

use Belayline\Gate\Gate;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;
use Belayline\Plugin\Admin\AuditLogPage;
use Belayline\Plugin\Admin\AbilitiesPage;
use Belayline\Plugin\Admin\ChangesPage;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Admin\PendingActionsPage;
use Belayline\Plugin\Api\Approvals;
use Belayline\Plugin\Api\Grants;
use Belayline\Plugin\Approval\WpdbGrantStore;
use Belayline\Plugin\Audit\AuditPruner;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Api\AbilityReviews;
use Belayline\Plugin\Api\Changes as ChangesApi;
use Belayline\Plugin\Reviews\ReviewedAbilitiesPack;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Changes\UndoService;
use Belayline\Plugin\Changes\WpdbChangeStore;
use Belayline\Plugin\Hooks\AbilityAuditLog;
use Belayline\Plugin\Hooks\AbilityPermissionGate;
use Belayline\Plugin\Hooks\McpRequestAuditHandler;
use Belayline\Plugin\Hooks\PreToolCallGate;
use Belayline\Plugin\Verdict\GrantGate;
use Belayline\Plugin\Verdict\VerdictPipeline;
use Belayline\Plugin\Hooks\ToolCallResultRedactor;
use Belayline\Plugin\Identity\ApplicationPasswordIdentity;
use Belayline\Plugin\Privacy\PrivacyIntegration;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Identity\UserRoleIdentity;
use Belayline\Plugin\Integrations\Core\CoreIntegration;
use Belayline\Plugin\Integrations\FluentCart\FluentCartIntegration;
use Belayline\Plugin\Integrations\Self\CheckApprovalAbility;
use Belayline\Plugin\Integrations\Self\SelfIntegration;
use Belayline\Plugin\Integrations\SureCart\SureCartIntegration;
use Belayline\Plugin\Integrations\Woo\VerbMapper;
use Belayline\Plugin\Integrations\Woo\Changes\WooChangeAdapters;
use Belayline\Plugin\Integrations\Woo\WooIntegration;
use Belayline\Plugin\Support\ApprovalNotifier;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\ApprovalSweep;
use Belayline\Plugin\Support\ArgumentCapGate;
use Belayline\Plugin\Support\AuditRetention;
use Belayline\Plugin\Support\ChangePurge;
use Belayline\Plugin\Support\DecisionRecorder;
use Belayline\Plugin\Support\ElevationRules;
use Belayline\Plugin\Support\EnvironmentGuard;
use Belayline\Plugin\Support\GrantRecorder;
use Belayline\Plugin\Support\MultisiteGuard;
use Belayline\Plugin\Support\NetworkLifecycle;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\RateLimitGate;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\Schema;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Support\StateProbes;
use Belayline\Plugin\Support\Tripwires;
use Belayline\Plugin\Support\WindowCounter;

// Bundled autoloader: `composer install` in this dir copies the core package
// into vendor/ and wires PSR-4 for both the core and the plugin's own classes.
$belayline_autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($belayline_autoload)) {
    require_once $belayline_autoload;
}

// The global belayline() locator (function_exists-guarded): defined
// unconditionally so feature-detecting consumers can rely on it existing the
// moment this file has loaded — even on a load where the bootstrap closure
// below bails (missing core lib) and the container stays null.
require_once __DIR__ . '/src/api.php';

// Registered UNCONDITIONALLY (not inside the class_exists(Gate::class) guard
// below): WordPress calls activation/deactivation hooks by including this
// file fresh and checking what got registered THAT load. If registration
// itself depended on the autoloader having succeeded, activating on a site
// where `composer install` was never run in plugin/ would silently register
// nothing and no table/cron would ever be created — the callbacks below
// guard internally instead, so a broken autoloader fails safe, not silent.
register_activation_hook(__FILE__, __NAMESPACE__ . '\\belayline_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\belayline_deactivate');

/**
 * Create/upgrade the tables and schedule the crons for the current site,
 * unless the `belayline_allow_multisite` filter vetoed a multisite install
 * (S2, the pre-multisite refusal, kept as an opt-out). Network activation
 * installs only the site it runs on; every other site installs lazily on its
 * first admin or cron request (see NetworkLifecycle).
 */
function belayline_activate(): void
{
    $belayline_autoload = __DIR__ . '/vendor/autoload.php';
    if (is_readable($belayline_autoload)) {
        require_once $belayline_autoload;
    }

    if (class_exists(MultisiteGuard::class) && MultisiteGuard::refused()) {
        MultisiteGuard::refuseActivation(__FILE__);

        return;
    }

    if (!class_exists(MultisiteGuard::class) && function_exists('is_multisite') && is_multisite() && !apply_filters('belayline_allow_multisite', true)) {
        // Defensive fallback if the autoloader failed to bring in the guard
        // class above: still refuse rather than activate half-wired.
        wp_die(esc_html__('Belayline Agent Governance does not support WordPress multisite. It was not activated.', 'belayline-agent-governance'));
    }

    if (!class_exists(Schema::class) || !class_exists(ApprovalSweep::class)) {
        return;
    }

    global $wpdb;
    if (isset($wpdb)) {
        Schema::install($wpdb);
    }

    ApprovalSweep::activate();
    ChangePurge::activate();
    AuditRetention::activate();
}

/**
 * Stop the crons. Table data is intentionally kept — see uninstall.php. A
 * network-wide deactivation unschedules them on every site.
 */
function belayline_deactivate(bool $network_wide = false): void
{
    if (!class_exists(NetworkLifecycle::class)) {
        return;
    }

    if ($network_wide && function_exists('is_multisite') && is_multisite()) {
        NetworkLifecycle::deactivateNetwork();

        return;
    }

    NetworkLifecycle::unscheduleCrons();
}

// Wired on plugins_loaded (priority 0), NOT at include time: WordPress keeps
// active_plugins sorted, so this plugin's directory loads BEFORE woocommerce/
// and an include-time class_exists('WooCommerce') is always false — the whole
// Woo integration would silently never register (found by live smoke test
// 2026-07-14). By plugins_loaded every plugin file is included, and the seams
// are still wired long before any ability exists: the Abilities API registry
// fires wp_abilities_api_init lazily, no earlier than init.
add_action('plugins_loaded', static function (): void {
    // Runtime refusal (S2, only when belayline_allow_multisite is false):
    // covers a copy somehow active on a multisite
    // install (e.g. a site converted to multisite after activation), since
    // register_activation_hook only ever fires on activation, not update.
    // Wires nothing else below when refused.
    if (class_exists(MultisiteGuard::class) && MultisiteGuard::refused()) {
        MultisiteGuard::refuseRuntime(__FILE__);

        return;
    }

    if (!class_exists(Gate::class)) {
        return;
    }

    global $wpdb;

    // Multisite runtime (no-op on a single site): per-site lazy install,
    // site create/delete handling, per-site principal cache reset.
    if (isset($wpdb)) {
        NetworkLifecycle::register($wpdb, __FILE__);
    }

    // Cheap version check on every wp-admin load: an admin visiting after a
    // plugin UPDATE (not a fresh activation) still gets the table upgraded,
    // since register_activation_hook only fires on activate, never on update.
    if (isset($wpdb)) {
        add_action('admin_init', static function () use ($wpdb): void {
            Schema::maybeUpgrade($wpdb);
        });
    }

    // Unconditional (option-gated internally, like CapabilityPacksPage's
    // pausedNotice()): shows only while a crashed grants-table rename has
    // left both the old and new tables in place. See Schema::install().
    add_action('admin_notices', [Schema::class, 'renderGrantsRenameConflictNotice']);

    // Identity chain: application passwords and users/roles apply
    // on ANY WordPress site; an integration appends its own provider below.
    $belayline_identity = new IdentityChain([
        new ApplicationPasswordIdentity(),
        new UserRoleIdentity(),
    ]);

    // Verb catalog + elevation rules and the pack/namespace
    // contributions start empty, then each integration module MERGES its
    // contributions in — a Woo-less site still governs the core/ namespace
    // (D23), while a Woo-less install of an older-style module order can
    // never clobber another module's contributions (PackRegistry and
    // VerbCatalog both overwrite silently by key).
    $belayline_catalog = new VerbCatalog();
    $belayline_elevation_rules = [];
    $belayline_extra_packs = [];
    $belayline_governed_namespaces = [];
    $belayline_state_probes = [];

    // Core first (definitionally available on every site), then the plugin's
    // own AS-8 check-approval ability, then Woo.
    $belayline_core = CoreIntegration::register($belayline_catalog, $belayline_identity, isset($wpdb) ? $wpdb : null);
    $belayline_elevation_rules = [...$belayline_core['elevationRules']];
    $belayline_extra_packs = [...$belayline_core['packs']];
    $belayline_governed_namespaces = [...$belayline_core['governedNamespaces']];
    $belayline_state_probes = [...$belayline_core['stateProbes']];

    $belayline_self = SelfIntegration::register($belayline_catalog);
    $belayline_governed_namespaces = [...$belayline_governed_namespaces, ...$belayline_self['governedNamespaces']];

    if (WooIntegration::available()) {
        $belayline_woo = WooIntegration::register($belayline_catalog, $belayline_identity, isset($wpdb) ? $wpdb : null);
        // Merge, never assign: Woo's contributions must ADD to the core
        // module's, not replace them.
        $belayline_elevation_rules = [...$belayline_elevation_rules, ...$belayline_woo['elevationRules']];
        $belayline_extra_packs = [...$belayline_extra_packs, ...$belayline_woo['packs']];
        $belayline_governed_namespaces = array_values(array_unique([...$belayline_governed_namespaces, ...$belayline_woo['governedNamespaces']]));
        $belayline_state_probes = [...$belayline_state_probes, ...$belayline_woo['stateProbes']];
        // Capture and undo for products, coupons, customers, zones and tax rates.
        WooChangeAdapters::register();
    }

    if (FluentCartIntegration::available()) {
        $belayline_fluentcart = FluentCartIntegration::register($belayline_catalog, isset($wpdb) ? $wpdb : null);
        $belayline_elevation_rules = [...$belayline_elevation_rules, ...$belayline_fluentcart['elevationRules']];
        $belayline_extra_packs = [...$belayline_extra_packs, ...$belayline_fluentcart['packs']];
        $belayline_governed_namespaces = array_values(array_unique([...$belayline_governed_namespaces, ...$belayline_fluentcart['governedNamespaces']]));
        $belayline_state_probes = [...$belayline_state_probes, ...$belayline_fluentcart['stateProbes']];
        // Refund and subscription cancel never run on a standing Grant.
        add_filter('belayline_grant_eligible', [FluentCartIntegration::class, 'grantEligible'], PHP_INT_MAX, 4);
    }

    if (SureCartIntegration::available()) {
        $belayline_surecart = SureCartIntegration::register($belayline_catalog);
        $belayline_elevation_rules = [...$belayline_elevation_rules, ...$belayline_surecart['elevationRules']];
        $belayline_extra_packs = [...$belayline_extra_packs, ...$belayline_surecart['packs']];
        $belayline_governed_namespaces = array_values(array_unique([...$belayline_governed_namespaces, ...$belayline_surecart['governedNamespaces']]));
        $belayline_state_probes = [...$belayline_state_probes, ...$belayline_surecart['stateProbes']];
    }

    // Ability review (0.5): abilities an administrator reviewed on Tools, Agent
    // Abilities. Their namespaces join the governed list (so the unreviewed
    // siblings fail closed), their verbs are mapped into the catalog at
    // registration while the input schema still matches, and one generic pack
    // admits them. The pre-review list is kept for the screen, which must not
    // offer abilities an integration already governs.
    $belayline_integration_namespaces = $belayline_governed_namespaces;
    $belayline_reviews = new AbilityReviews($belayline_catalog);
    $belayline_reviews->register();
    $belayline_governed_namespaces = array_values(array_unique([...$belayline_governed_namespaces, ...$belayline_reviews->namespaces()]));
    $belayline_extra_packs[] = ReviewedAbilitiesPack::build($belayline_reviews);

    // Site owners (or other plugins) can widen the governed namespace list
    // without an integration module of their own.
    $belayline_governed_namespaces = function_exists('apply_filters')
        ? apply_filters('belayline_governed_namespaces', $belayline_governed_namespaces)
        : $belayline_governed_namespaces;

    // Companion seam to the namespace filter: governing a namespace without
    // mapping its verbs would deny every call in it as unknown_verb (the Gate
    // fails closed on unclassified verbs by design). Anyone widening
    // belayline_governed_namespaces MUST also map those verbs to tiers here.
    // Values may be Tier instances or their backing ints (0 = Reversible,
    // 1 = SideEffecting, 2 = Irreversible); anything else is dropped, keeping
    // the fail-closed default for that verb. Example:
    //   ['my-plugin/thing-list' => 0, 'my-plugin/thing-delete' => 2]
    // Found by live smoke test 2026-07-07: without this seam the namespace
    // filter's advertised extensibility was unreachable outside WooIntegration.
    if (function_exists('apply_filters')) {
        $belayline_extra_verbs = [];
        foreach ((array) apply_filters('belayline_verb_map', []) as $belayline_verb => $belayline_tier) {
            if ($belayline_tier instanceof Tier) {
                $belayline_extra_verbs[$belayline_verb] = $belayline_tier;
            } elseif (is_int($belayline_tier) && Tier::tryFrom($belayline_tier) !== null) {
                $belayline_extra_verbs[$belayline_verb] = Tier::from($belayline_tier);
            }
        }
        if ($belayline_extra_verbs !== []) {
            $belayline_catalog->register($belayline_extra_verbs);
        }
    }

    // Companion seam to the two filters above: a site that governs its own
    // namespace and maps its verbs to base tiers can also contribute arg-aware
    // elevation rules, for a verb whose blast radius depends on its arguments
    // (publish vs draft, bulk vs single). A rule can only ever ELEVATE, so this
    // narrows and never widens. Non-rule entries are dropped, not trusted.
    $belayline_elevation_rules = ElevationRules::filtered($belayline_elevation_rules);

    // Companion seam for state probes (AS-6): a site can add a probe for a
    // verb no module already declared one for, mirroring the elevation-rules
    // seam's add-only posture exactly.
    $belayline_state_probes = StateProbes::filtered($belayline_state_probes);

    RequestContext::configure($belayline_identity);

    $belayline_classifier = new TierClassifier($belayline_catalog, $belayline_elevation_rules);
    $belayline_gate = new Gate($belayline_classifier);
    $belayline_packs = new PackResolver($belayline_extra_packs);
    // The audit actor / rate-limit / approval subject must name the token
    // that WON the pack binding, not merely the first token the identity
    // chain lists (see PackResolver::principal()).
    RequestContext::configurePrincipalResolver([$belayline_packs, 'principal']);
    $belayline_sink = isset($wpdb) ? new WpdbAuditSink($wpdb) : null;
    $belayline_approvals = isset($wpdb) ? new WpdbApprovalStore($wpdb) : null;

    // Shared decision-recorder: BOTH gate seams audit verdicts + persist pending
    // approvals through this one object, so they can never diverge.
    $belayline_recorder = new DecisionRecorder($belayline_sink, $belayline_approvals);

    // Shared rate-limit gate (backlog #16): BOTH seams enforce a pack's
    // calls_per_minute/calls_per_hour caps through this one object, for the same
    // reason $belayline_recorder is shared — identical enforcement no matter which
    // seam intercepts a call first.
    $belayline_rate_limits = new RateLimitGate();

    // Shared argument-cap gate (roadmap 0.2 "spend limits"): shared for the same
    // reason as $belayline_rate_limits — and because its per-request memo is what
    // stops one call being accumulated into the day totals once per seam.
    $belayline_argument_caps = new ArgumentCapGate();

    // Shadow mode (roadmap 0.2): per-pack log-only observation, read from the
    // belayline_shadow_packs option + belayline_shadow_packs filter. Shared so
    // both seams and the admin toggle agree on which packs are shadowed.
    $belayline_shadow = new ShadowMode();

    // Configuration changes (shadow toggles and expiries, binding changes) go
    // into the same audit chain as the calls they govern. Shared by the admin
    // page and the hourly sweep so both write identical rows.
    $belayline_changes = new AdminChangeRecorder($belayline_sink);

    // The emergency stop and the tripwires. One notifier instance serves both
    // the approval emails below and the lockout alert, so a site's configured
    // recipient is read in exactly one place.
    $belayline_pause = new PauseSwitch($belayline_changes);
    $belayline_notifier = new ApprovalNotifier();
    $belayline_tripwires = new Tripwires(new WindowCounter(), $belayline_changes, $belayline_notifier);

    // AS-8 (§3.5): the self-service check-approval ability, always
    // registered while the plugin is active (no disabling filter). Poll
    // logic is null-safe without a database — every id then reads as
    // not_found, the fail-closed answer on the pathological no-$wpdb path.
    SelfIntegration::registerAbility($belayline_approvals, $belayline_sink, $belayline_pause);

    // Pre-approval grants (AS-12), behind the default-false
    // `belayline_enable_grants` filter. Constructed whenever there is a
    // database — the objects are inert while the feature switch is off — so a
    // host can feature-detect the service without the site having opted in yet.
    $belayline_grant_store = isset($wpdb) ? new WpdbGrantStore($wpdb) : null;
    $belayline_grant_gate = $belayline_grant_store !== null
        ? new GrantGate($belayline_grant_store, $belayline_approvals, $belayline_recorder, new GrantRecorder($belayline_sink))
        : null;

    // Site binding (AS-7): every governed call and admin_init check the bound
    // host against the current one, voiding every Relaxation (shadow, grants,
    // approved-but-unclaimed approvals) exactly once on a mismatch. Inert
    // (null) only on the pathological no-database path.
    $belayline_environment = isset($wpdb)
        ? new EnvironmentGuard($belayline_shadow, $belayline_changes, $belayline_approvals, $belayline_grant_store)
        : null;

    // The ONE verdict pipeline both gate seams adapt (docs/adr/0001): the ordered
    // evaluation, the approval claim and its re-entrancy memo, the caps, shadow
    // mode, and the audit/pending-approval obligations of a blocked call all
    // live here, so a call intercepted by either seam is judged identically.
    $belayline_pipeline = new VerdictPipeline(
        $belayline_gate,
        $belayline_recorder,
        $belayline_approvals,
        $belayline_rate_limits,
        $belayline_argument_caps,
        $belayline_shadow,
        $belayline_grant_gate,
        $belayline_pause,
        $belayline_tripwires,
        $belayline_state_probes,
        $belayline_environment,
    );

    // AS-7 §3.4 item 4: the admin_init half of the environment check (the
    // governed-call half lives inside VerdictPipeline::judge() above), and
    // the banner that shows until an administrator rebinds.
    if ($belayline_environment !== null) {
        add_action('admin_init', static function () use ($belayline_environment): void {
            $belayline_environment->ensureCurrent();
        });
        add_action('admin_notices', [$belayline_environment, 'renderMismatchNotice']);
    }

    // Primary seam on the shipping stack (WP core Abilities API; adapter-version-independent).
    // Claim-mode adapter: owns the finalize/rollback of grants the pipeline
    // reserved. Inert no-op for any ability outside $belayline_governed_namespaces.
    // Also owns releasing a pre-approval grant's reservation whenever it rolls
    // the approval back, so a grant's count is only spent on an action that ran.
    //
    // Change capture (0.5): a window around each governed call at Tier >= 1
    // that records what the call wrote (posts, meta, terms, options). The gate
    // wraps each ability's execute_callback with it; the audit writers pick up
    // its event id and change hash. Null only on the no-database path.
    $belayline_capture = isset($wpdb) ? new ChangeCapture($belayline_classifier, $belayline_packs, new WpdbChangeStore($wpdb)) : null;
    ChangeCapture::configure($belayline_capture);
    $belayline_capture?->register();

    // Change history + undo (0.5): one undo engine behind the Changes screen
    // and the public belayline()->changes() API.
    $belayline_change_store = isset($wpdb) ? new WpdbChangeStore($wpdb) : null;
    $belayline_undo = $belayline_capture !== null && $belayline_change_store !== null
        ? new UndoService($belayline_change_store, $belayline_capture->adapters(), $belayline_capture, $belayline_sink)
        : null;

    (new AbilityPermissionGate($belayline_pipeline, $belayline_packs, $belayline_approvals, $belayline_governed_namespaces, $belayline_grant_gate, $belayline_capture))->register();

    // Forward-compat seam: fires only if a mcp-adapter >= 0.5.0 is the loaded copy.
    // Shares the same PackResolver + DecisionRecorder as the live seam so both honour
    // identical bindings and record decisions identically. The verb mapper assumes
    // WooCommerce's "namespace-resource-action" tool-naming convention, so this seam
    // is only meaningful (and only wired up) when WooCommerce is the active integration.
    if (WooIntegration::available() || SureCartIntegration::available()) {
        (new PreToolCallGate($belayline_pipeline, new VerbMapper(), $belayline_packs, $belayline_governed_namespaces))->register();
    }

    // Read-path PII redaction (backlog #11): masks the payload RETURNED TO THE
    // AGENT for a governed, executed tool call whose pack redacts PII. Unlike
    // PreToolCallGate, this has no Woo-specific tool-naming dependency (it reads
    // the real ability id off the adapter's own $mcp_tool observability context),
    // so it is wired for ANY governed namespace, not gated on WooIntegration.
    // Dormant no-op unless a real mcp-adapter carrying this filter is loaded
    // (fires only >= 0.5.0) and $belayline_governed_namespaces is non-empty.
    (new ToolCallResultRedactor($belayline_packs, $belayline_governed_namespaces))->register();

    // Audit every ability that actually executes — successes and failures.
    if ($belayline_sink !== null) {
        (new AbilityAuditLog($belayline_sink, $belayline_classifier, $belayline_packs, $belayline_governed_namespaces, $belayline_capture))->register();

        // wp-admin viewer + CSV export (Tools → Agent Audit Log).
        (new AuditLogPage(new AuditReader($wpdb), $belayline_shadow))->register();

        // wp-admin change history + human-only undo (Tools → Agent Changes).
        if ($belayline_undo !== null && $belayline_change_store !== null) {
            (new ChangesPage($belayline_change_store, $belayline_undo, new AuditReader($wpdb), $belayline_shadow))->register();
        }

        // Privacy tools (§3.6 item 2): personal-data export/erase for Tools →
        // Export/Erase Personal Data, plus suggested privacy-policy text.
        // Uninstall (§3.6 item 3) is a separate stage.
        (new PrivacyIntegration($wpdb))->register();

        // Forward-compat observability-based audit consumer: proves a plugin can
        // build the audit trail on mcp-adapter's public `McpObservabilityHandlerInterface`
        // alone (upstream issue #176 discussion), independent of and in addition
        // to the Abilities-API seams above. Dormant no-op unless an mcp-adapter
        // carrying that interface is the loaded copy — `interface_exists()` here
        // both checks AND (via its default $autoload) resolves that without ever
        // risking a fatal on sites without the adapter, exactly like the
        // `class_exists(Gate::class)` guard this whole block already lives in.
        if (interface_exists(\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface::class)) {
            McpRequestAuditHandler::configure($belayline_sink, $belayline_packs, $belayline_classifier);
            (new McpRequestAuditHandler())->register();

            add_filter('mcp_adapter_default_server_config', static function (array $config): array {
                $config['observability_handler'] = McpRequestAuditHandler::class;

                return $config;
            });
        }
    }

    // Human approval queue (Tools → Pending Agent Actions): approve/reject blocked
    // irreversible actions, minting single-use tokens.
    if ($belayline_approvals !== null) {
        // ONE approvals service for every caller (AS-10): the wp-admin page,
        // the programmatic API below, and any consumer (e.g. Site Agent inline
        // approve) all resolve approvals through it, so capability checks,
        // audit reconciliation and lifecycle actions can never diverge.
        $belayline_api_approvals = new Approvals($belayline_approvals, $belayline_sink, $belayline_packs, $belayline_state_probes);

        (new PendingActionsPage($belayline_approvals, $belayline_api_approvals, $belayline_shadow))->register();

        // Backlog control for the table above: hourly sweep of expired/orphaned
        // approval rows (see WpdbApprovalStore::deleteExpired()). The schedule
        // itself is set up on activation; this just wires the callback. The
        // same tick retires lapsed shadow-mode entries, each one audited.
        add_action(ApprovalSweep::HOOK, static function () use (
            $belayline_approvals,
            $belayline_grant_store,
            $belayline_shadow,
            $belayline_changes
        ): void {
            ApprovalSweep::run($belayline_approvals, $belayline_grant_store);
            $belayline_shadow->sweep($belayline_changes);
        });

        // Daily retention purge of the change history (see ChangePurge).
        add_action(ChangePurge::HOOK, static function () use ($wpdb): void {
            ChangePurge::run(new WpdbChangeStore($wpdb));
        });

        // Daily audit-log retention (see AuditRetention). Off (0 days) unless
        // the operator sets the retention option on the Agent Audit Log screen.
        if ($belayline_sink !== null) {
            add_action(AuditRetention::HOOK, static function () use ($wpdb, $belayline_sink): void {
                AuditRetention::run(new AuditPruner($wpdb, $belayline_sink));
            });
        }

        // Route each NEW pending approval to the humans who must clear it:
        // email (+ optional webhook), on the belayline_approval_requested
        // action the store fires from its fresh-insert path only.
        $belayline_notifier->register();
    } else {
        $belayline_api_approvals = null;
    }

    // Expose the external service surface: reachable from anywhere via the
    // global belayline() locator. approvals() and grants() are null only on
    // the pathological no-database path; consumers feature-detect on that.
    // A non-null grants() still does nothing until the site turns
    // `belayline_enable_grants` on. pause() is the emergency stop, for a
    // host or a WP-CLI command.
    Container::init(
        $belayline_api_approvals ?? null,
        $belayline_grant_store !== null ? new Grants($belayline_grant_store, new GrantRecorder($belayline_sink)) : null,
        $belayline_pause,
        $belayline_undo !== null && $belayline_change_store !== null ? new ChangesApi($belayline_change_store, $belayline_undo) : null,
        $belayline_reviews,
    );

    // Ability review screen (Tools → Agent Abilities, slug belayline-abilities).
    (new AbilitiesPage($belayline_reviews, $belayline_integration_namespaces, $belayline_changes))->register();

    // Capability-pack admin (Tools → Agent Capability Packs): the emergency
    // stop, then bind each identity the configured providers expose to a pack
    // from the catalog.
    (new CapabilityPacksPage($belayline_packs, $belayline_identity, $belayline_shadow, $belayline_changes, $belayline_pause, $belayline_environment))->register();
}, 0);
