<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Self;

use Belayline\Audit\AuditSink;
use Belayline\Policy\Tier;
use Belayline\Policy\VerbCatalog;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Verdict\VerdictPipeline;

/**
 * AS-8 (§3.5): the plugin's OWN built-in integration — not a third-party
 * capability pack module like Core/Woo, but the same registration shape, so
 * the bootstrap can treat it uniformly. Registered UNCONDITIONALLY, like
 * {@see \Belayline\Plugin\Integrations\Core\CoreIntegration}: the
 * `belayline/check-approval` ability exists on every site this plugin runs
 * on, independent of WooCommerce.
 *
 * Contributes the `belayline/` namespace as governed (so the ability goes
 * through the ordinary permission-gate seam) and catalogues its one verb at a
 * FIXED tier 0 — fixed in the sense that nothing in this file makes the tier
 * unconditional; the actual "no pack can change it" guarantee is the named
 * exemption inside {@see VerdictPipeline::judge()}, not this catalog entry.
 * Any OTHER `belayline/*` verb is deliberately left unclassified, so it
 * denies `unknown_verb` like any ungoverned write — the exemption is this one
 * verb, never the namespace.
 */
final class SelfIntegration
{
    /** The ability category this module registers on `wp_abilities_api_categories_init`. */
    public const CATEGORY = 'belayline';

    public static function available(): bool
    {
        return true;
    }

    /**
     * @return array{elevationRules: list<never>, packs: list<never>, governedNamespaces: list<string>, stateProbes: array<string, never>}
     */
    public static function register(VerbCatalog $catalog): array
    {
        $catalog->register([VerdictPipeline::CHECK_APPROVAL_VERB => Tier::Reversible]);

        return [
            'elevationRules' => [],
            'packs' => [],
            'governedNamespaces' => ['belayline/'],
            'stateProbes' => [],
        ];
    }

    /**
     * Wire the ability + its category onto the real Abilities API hooks
     * (§3.5 item 8: always registered while the plugin is active, no
     * disabling filter — unconditional `add_action`, exactly like
     * {@see \Belayline\Plugin\Hooks\AbilityAuditLog::register()}
     * being wired regardless of feature flags).
     */
    public static function registerAbility(?WpdbApprovalStore $approvals, ?AuditSink $sink, ?PauseSwitch $pause): void
    {
        $ability = new CheckApprovalAbility($approvals, $sink, $pause);

        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category(self::CATEGORY, [
                'label' => __('Belayline Agent Governance', 'belayline-agent-governance'),
                'description' => __('Abilities added by the Belayline Agent Governance plugin.', 'belayline-agent-governance'),
            ]);
        });

        add_action('wp_abilities_api_init', static function () use ($ability): void {
            wp_register_ability(VerdictPipeline::CHECK_APPROVAL_VERB, [
                'label' => __('Check Approval', 'belayline-agent-governance'),
                'description' => __(
                    'Check the status of an approval_required request by its approval_id. Returns the current status, what to do next, and (once resolved) whether a human or a standing grant resolved it.',
                    'belayline-agent-governance'
                ),
                'category' => self::CATEGORY,
                'permission_callback' => [$ability, 'permissionCallback'],
                'execute_callback' => [$ability, 'execute'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'approval_id' => [
                            'type' => 'string',
                            'description' => __('The approval_id from an earlier approval_required response.', 'belayline-agent-governance'),
                        ],
                    ],
                    'required' => ['approval_id'],
                ],
                'meta' => [
                    'mcp' => ['public' => true],
                    'annotations' => [
                        'readonly' => true,
                        'instructions' => __(
                            'Poll no faster than every 30 seconds; back off to every 5 minutes; stop polling at pending_expires_at. Only status "approved" means retry the original call, with exactly the original arguments. Do not tell the user the action was approved before that.',
                            'belayline-agent-governance'
                        ),
                    ],
                ],
            ]);
        });
    }
}
