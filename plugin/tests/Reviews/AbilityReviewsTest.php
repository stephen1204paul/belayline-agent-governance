<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Reviews;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Plugin\Admin\AbilitiesPage;
use Specflux\AgentSafety\Plugin\Api\AbilityReviews;
use Specflux\AgentSafety\Plugin\Container;
use Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Reviews\ReviewedAbilitiesPack;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use WP_Error;

/**
 * Ability review end to end: the stored review, the registration-time mapping,
 * the namespace joining the governed list, the generic pack, and the gate's
 * verdict on a call, plus the public status() contract.
 */
final class AbilityReviewsTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    private InMemoryAuditSink $sink;

    protected function setUp(): void
    {
        RequestContext::reset();
        remove_all_filters();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $_POST = [];
        $_GET = [];
        \CounterTableWpdb::install();
        $this->sink = new InMemoryAuditSink();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        remove_all_filters();
        Container::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_user_caps'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    /**
     * Re-run the bootstrap's wiring from the stored option, then register the
     * abilities the way core does: through wp_register_ability_args.
     *
     * @param array<string, array<string, mixed>> $abilities name => registration args
     * @return array{AbilityReviews, array<string, callable>}
     */
    private function boot(array $abilities): array
    {
        remove_all_filters('wp_register_ability_args');
        remove_all_filters('agent_safety_grant_eligible');
        $catalog = new VerbCatalog();
        $reviews = new AbilityReviews($catalog);
        $reviews->register();
        $packs = new PackResolver([ReviewedAbilitiesPack::build($reviews)]);
        $approvals = new FakeApprovalStore();
        RequestContext::configure(new IdentityChain([new FakeIdentityProvider(currentTokens: ['test:agent'])]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:agent' => ReviewedAbilitiesPack::NAME];

        $gate = new AbilityPermissionGate(
            new VerdictPipeline(new Gate(new TierClassifier($catalog)), new DecisionRecorder($this->sink, $approvals), $approvals),
            $packs,
            $approvals,
            $reviews->namespaces(),
        );

        $callbacks = [];
        foreach ($abilities as $name => $args) {
            $args = apply_filters('wp_register_ability_args', $args + ['permission_callback' => static fn () => true], $name);
            $callbacks[$name] = $gate->wrap($args, $name)['permission_callback'];
        }

        return [$reviews, $callbacks];
    }

    /** @return array<string, mixed> */
    private static function ability(array $annotations = [], array $schema = self::SCHEMA): array
    {
        return ['input_schema' => $schema, 'meta' => ['annotations' => $annotations]];
    }

    /** @param array<string, string> $choices */
    private function review(array $choices, array $schemas = []): void
    {
        $reviews = new AbilityReviews();
        $hashes = [];
        foreach ($choices as $name => $_) {
            $hashes[$name] = AbilityReviews::schemaHash($schemas[$name] ?? self::SCHEMA);
        }
        $reviews->apply($choices, $hashes, 7);
    }

    private static function codeOf(mixed $result): string
    {
        if (!$result instanceof WP_Error) {
            return 'allowed';
        }
        // A deny carries its reason after the pack name: "Blocked by SenroGate (pack): reason".
        $code = (string) $result->get_error_code();

        return $code === 'agent_safety_denied' ? substr((string) strrchr($result->get_error_message(), ' '), 1) : $code;
    }

    public function testUnreviewedAbilityInAnUngovernedNamespaceIsUntouchedAndReportsUnreviewed(): void
    {
        [$reviews, $cb] = $this->boot(['acme/export' => self::ability()]);

        $this->assertSame('unreviewed', $reviews->status('acme/export'));
        $this->assertSame([], $reviews->namespaces());
        $this->assertTrue($cb['acme/export']([]));
    }

    public function testReadonlyAnnotationOnlySuggestsAndNothingAppliesUntilSaved(): void
    {
        $abilities = [
            ['name' => 'acme/list', 'label' => 'List', 'description' => '', 'annotations' => ['readonly' => true], 'schema' => self::SCHEMA],
            ['name' => 'acme/wipe', 'label' => 'Wipe', 'description' => '', 'annotations' => [], 'schema' => self::SCHEMA],
        ];
        $reviews = new AbilityReviews();
        $page = new AbilitiesPage($reviews, [], new \Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder(), static fn () => $abilities);

        $this->assertSame('read', AbilitiesPage::suggestion(['readonly' => true])?->value);
        $this->assertNull(AbilitiesPage::suggestion(['readonly' => 'yes']));

        // Render: Read is pre-selected for the readonly ability only...
        ob_start();
        $page->render();
        $html = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('#name="tier\[acme/list\]">.*?value="read" selected#s', $html);
        $this->assertDoesNotMatchRegularExpression('#name="tier\[acme/wipe\]">(?:(?!</select>).)*selected#s', $html);

        // ...but nothing is stored, governed or callable before a save.
        $this->assertSame('unreviewed', $reviews->status('acme/list'));
        $this->assertSame([], $reviews->namespaces());
        [, $cb] = $this->boot(['acme/list' => self::ability(['readonly' => true])]);
        $this->assertTrue($cb['acme/list']([]), 'still ungoverned: the suggestion applied nothing');

        // Saving applies it, and only then.
        $this->assertTrue($page->applyNamespace('acme/', ['acme/list' => 'read'], '', true, 7));
        $this->assertSame('reviewed', $reviews->status('acme/list'));
    }

    public function testReviewingOneAbilityGovernsTheNamespaceAndSiblingsFailClosed(): void
    {
        $this->review(['acme/list' => 'read']);
        [$reviews, $cb] = $this->boot(['acme/list' => self::ability(), 'acme/wipe' => self::ability()]);

        $this->assertSame(['acme/'], $reviews->namespaces());
        $this->assertTrue($cb['acme/list']([]), 'reviewed Read runs for a credential on the reviewed-abilities pack');
        $this->assertSame('unknown_verb', self::codeOf($cb['acme/wipe']([])));
        $this->assertSame('unreviewed', $reviews->status('acme/wipe'));
    }

    public function testUnboundCredentialCannotCallAReviewedAbility(): void
    {
        $this->review(['acme/list' => 'read']);
        [, $cb] = $this->boot(['acme/list' => self::ability()]);
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [];

        $this->assertSame('not_in_pack', self::codeOf($cb['acme/list']([])));
    }

    public function testWriteReviewParksForApproval(): void
    {
        $this->review(['acme/edit' => 'write']);
        [, $cb] = $this->boot(['acme/edit' => self::ability()]);

        $this->assertSame('approval_required', self::codeOf($cb['acme/edit'](['id' => 1])));
    }

    public function testDestructiveAnnotationEscalatesAReviewedRead(): void
    {
        $this->review(['acme/list' => 'read']);
        [, $cb] = $this->boot(['acme/list' => self::ability(['destructive' => true])]);

        $this->assertSame('approval_required', self::codeOf($cb['acme/list']([])));
    }

    public function testBlockedIsRefusedEvenForTheOwnerPack(): void
    {
        $this->review(['acme/nuke' => 'blocked']);
        [$reviews, $cb] = $this->boot(['acme/nuke' => self::ability()]);

        $this->assertSame('blocked', $reviews->status('acme/nuke'));
        $this->assertSame('unknown_verb', self::codeOf($cb['acme/nuke']([])));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:agent' => 'owner'];
        $this->assertSame('unknown_verb', self::codeOf($cb['acme/nuke']([])), 'owner allows * but a blocked verb has no tier');
    }

    public function testIrreversibleParksAndIsNeverGrantable(): void
    {
        $this->review(['acme/purge' => 'irreversible', 'acme/edit' => 'write']);
        [, $cb] = $this->boot(['acme/purge' => self::ability(), 'acme/edit' => self::ability()]);

        $this->assertSame('approval_required', self::codeOf($cb['acme/purge']([])));

        // A site that turns grants on and says "everything is eligible" still cannot grant it.
        add_filter('agent_safety_grant_eligible', static fn () => true, 10, 4);
        $this->assertFalse(apply_filters('agent_safety_grant_eligible', false, null, 'acme/purge', []));
        $this->assertFalse(apply_filters('agent_safety_grant_eligible', true, null, 'acme/purge', []));
        $this->assertTrue(apply_filters('agent_safety_grant_eligible', false, null, 'acme/edit', []), 'other verbs keep the site\'s answer');
    }

    public function testSchemaDriftReturnsToUnreviewedAndCallsAreRefused(): void
    {
        $this->review(['acme/list' => 'read']);
        $changed = ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'extra' => ['type' => 'string']]];
        [$reviews, $cb] = $this->boot(['acme/list' => self::ability([], $changed)]);

        $this->assertSame('unreviewed', $reviews->status('acme/list'));
        $this->assertNull($reviews->tierOf('acme/list'));
        $this->assertSame('unknown_verb', self::codeOf($cb['acme/list']([])));
        $this->assertSame(['acme/'], $reviews->namespaces(), 'the namespace stays governed');

        // Re-reviewing against the new schema restores it.
        $this->review(['acme/list' => 'read'], ['acme/list' => $changed]);
        [$reviews, $cb] = $this->boot(['acme/list' => self::ability([], $changed)]);
        $this->assertSame('reviewed', $reviews->status('acme/list'));
        $this->assertTrue($cb['acme/list']([]));
    }

    public function testSchemaHashIgnoresKeyOrderButNotListOrder(): void
    {
        $a = ['type' => 'object', 'required' => ['a', 'b'], 'properties' => ['x' => 1, 'y' => 2]];
        $b = ['properties' => ['y' => 2, 'x' => 1], 'required' => ['a', 'b'], 'type' => 'object'];
        $c = ['type' => 'object', 'required' => ['b', 'a'], 'properties' => ['x' => 1, 'y' => 2]];

        $this->assertSame(AbilityReviews::schemaHash($a), AbilityReviews::schemaHash($b));
        $this->assertNotSame(AbilityReviews::schemaHash($a), AbilityReviews::schemaHash($c));
        $this->assertSame(AbilityReviews::schemaHash(null), AbilityReviews::schemaHash([]));
    }

    public function testFirstReviewOfANamespaceNeedsTheConfirmation(): void
    {
        $abilities = [['name' => 'acme/list', 'label' => '', 'description' => '', 'annotations' => [], 'schema' => self::SCHEMA]];
        $reviews = new AbilityReviews();
        $page = new AbilitiesPage($reviews, [], new \Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder($this->sink), static fn () => $abilities);

        $this->assertFalse($page->applyNamespace('acme/', ['acme/list' => 'read'], '', false, 7));
        $this->assertSame('unreviewed', $reviews->status('acme/list'));

        $this->assertTrue($page->applyNamespace('acme/', ['acme/list' => 'read'], '', true, 7));
        $row = $reviews->entries()['acme/list'];
        $this->assertSame(7, $row['reviewed_by']);
        $this->assertSame(AbilityReviews::schemaHash(self::SCHEMA), $row['schema']);
        $this->assertNotSame('', $row['reviewed_at']);

        // Bulk sets everything; clearing removes the review again, no confirmation needed.
        $this->assertTrue($page->applyNamespace('acme/', [], 'blocked', false, 7));
        $this->assertSame('blocked', $reviews->status('acme/list'));
        $this->assertTrue($page->applyNamespace('acme/', [], 'none', false, 7));
        $this->assertSame('unreviewed', $reviews->status('acme/list'));
        $this->assertNotSame([], $this->sink->records, 'every change is audited');
    }

    public function testIntegrationNamespacesAreNeverListedOrReviewable(): void
    {
        $abilities = [['name' => 'woocommerce/orders-get', 'label' => '', 'description' => '', 'annotations' => [], 'schema' => null]];
        $reviews = new AbilityReviews();
        $page = new AbilitiesPage($reviews, ['woocommerce/'], new \Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder(), static fn () => $abilities);

        $this->assertSame([], $page->reviewable());
        $this->assertTrue($page->applyNamespace('woocommerce/', [], 'read', true, 7));
        $this->assertSame([], $reviews->entries());
    }

    public function testSaveRequiresCapabilityAndNonce(): void
    {
        $page = new AbilitiesPage(new AbilityReviews(), [], new \Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder(), static fn () => []);

        $GLOBALS['wpas_test_user_caps'] = [];
        try {
            $page->save();
            $this->fail('expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        try {
            $page->save();
            $this->fail('expected wp_die on the nonce');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }

    public function testStatusContractIsCallableTheWayTheSenroFluxProbeCallsIt(): void
    {
        $this->review(['acme/list' => 'read', 'acme/nuke' => 'blocked']);
        [$reviews] = $this->boot(['acme/list' => self::ability(), 'acme/nuke' => self::ability()]);
        Container::init(null, null, null, null, $reviews);

        // The probe: feature-detect, then ask. A missing method means "not exposed".
        $this->assertTrue(function_exists('agent_safety') && method_exists(agent_safety(), 'abilityReviews'));
        $service = agent_safety()->abilityReviews();

        $this->assertSame('reviewed', $service->status('acme/list'));
        $this->assertSame('blocked', $service->status('acme/nuke'));
        $this->assertSame('unreviewed', $service->status('acme/other'));
        $this->assertSame('unreviewed', $service->status(''));
        foreach (['acme/list', 'acme/nuke', 'acme/other', 'x'] as $name) {
            $this->assertContains($service->status($name), ['reviewed', 'unreviewed', 'blocked']);
        }
    }

    public function testMalformedStoredRowsAreDropped(): void
    {
        $GLOBALS['wpas_test_options'][AbilityReviews::OPTION] = [
            'acme/ok' => ['tier' => 'read', 'schema' => 'abc', 'reviewed_by' => 1, 'reviewed_at' => 'x'],
            'acme/bad-tier' => ['tier' => 'god', 'schema' => 'abc'],
            'acme/no-schema' => ['tier' => 'read'],
            42 => ['tier' => 'read', 'schema' => 'abc'],
            'acme/scalar' => 'read',
        ];

        $this->assertSame(['acme/ok'], array_keys((new AbilityReviews())->entries()));
        $this->assertSame('unreviewed', (new AbilityReviews())->status('acme/bad-tier'));
    }
}
