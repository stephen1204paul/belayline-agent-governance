<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartIntegration;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartVerbCatalog;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

final class SureCartCatalogTest extends TestCase
{
    /** @return array<string, array{annotations: array<string, bool>, input_keys: list<string>}> */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__ . '/../../Fixtures/surecart-4.9.3-abilities.json');
        self::assertIsString($raw);

        /** @var array<string, array{annotations: array<string, bool>, input_keys: list<string>}> $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private static function classifier(): TierClassifier
    {
        $catalog = new VerbCatalog();
        $c = SureCartIntegration::register($catalog);

        return new TierClassifier($catalog, $c['elevationRules']);
    }

    public function testFixtureHasFiftyFourAbilities(): void
    {
        $this->assertCount(54, self::fixture());
    }

    /** Fails when SureCart adds an ability the catalog does not cover, or the catalog keeps a removed one. */
    public function testCatalogMatchesFixtureExactly(): void
    {
        $fixture = array_keys(self::fixture());
        $catalog = array_keys(SureCartVerbCatalog::MAP);
        sort($fixture);
        sort($catalog);

        $this->assertSame($fixture, $catalog);
    }

    public function testNoWildcards(): void
    {
        foreach (array_keys(SureCartVerbCatalog::MAP) as $verb) {
            $this->assertStringNotContainsString('*', $verb);
            $this->assertStringStartsWith('surecart/', $verb);
        }
    }

    public function testReadonlyAnnotatedAbilitiesAreTierZero(): void
    {
        foreach (self::fixture() as $name => $meta) {
            if ($meta['annotations']['readonly']) {
                $this->assertSame(Tier::Reversible, SureCartVerbCatalog::MAP[$name], $name);
            } else {
                $this->assertNotSame(Tier::Reversible, SureCartVerbCatalog::MAP[$name], $name . ' writes');
            }
        }
    }

    /** Annotations only tighten: every self-reported destructive ability is Irreversible, and some non-destructive ones are tightened too. */
    public function testDestructiveAnnotationNeverMapsBelowIrreversible(): void
    {
        foreach (self::fixture() as $name => $meta) {
            if ($meta['annotations']['destructive']) {
                $this->assertSame(Tier::Irreversible, SureCartVerbCatalog::MAP[$name], $name);
            }
        }
        // SureCart marks these non-destructive; we map them tighter.
        $this->assertFalse(self::fixture()['surecart/archive-product']['annotations']['destructive']);
        $this->assertSame(Tier::Irreversible, SureCartVerbCatalog::MAP['surecart/archive-product']);
        $this->assertFalse(self::fixture()['surecart/update-price']['annotations']['destructive']);
    }

    /** @return list<array{string, Tier}> */
    public static function baseTiers(): array
    {
        return [
            ['surecart/get-order', Tier::Reversible],
            ['surecart/list-products', Tier::Reversible],
            ['surecart/filter-orders', Tier::Reversible],
            ['surecart/create-product', Tier::SideEffecting],
            ['surecart/update-product', Tier::SideEffecting],
            ['surecart/create-customer', Tier::SideEffecting],
            ['surecart/update-customer', Tier::SideEffecting],
            ['surecart/create-coupon', Tier::SideEffecting],
            ['surecart/update-promotion', Tier::SideEffecting],
            ['surecart/create-fulfillment', Tier::SideEffecting],
            ['surecart/update-price', Tier::SideEffecting],
            ['surecart/archive-product', Tier::Irreversible],
            ['surecart/create-price', Tier::Irreversible],
            ['surecart/create-invoice', Tier::Irreversible],
            ['surecart/update-invoice', Tier::Irreversible],
            ['surecart/update-subscription-renewal-date', Tier::Irreversible],
            ['surecart/create-refund', Tier::Irreversible],
            ['surecart/cancel-subscription', Tier::Irreversible],
        ];
    }

    /** @dataProvider baseTiers */
    public function testTierWithBenignArgs(string $verb, Tier $expected): void
    {
        $this->assertSame($expected, self::classifier()->classify($verb, []));
    }

    public function testEveryDeleteIsIrreversibleAndUngrantable(): void
    {
        $deletes = array_values(array_filter(array_keys(SureCartVerbCatalog::MAP), static fn (string $v): bool => str_starts_with($v, 'surecart/delete-')));
        $this->assertCount(4, $deletes);
        foreach ([...$deletes, 'surecart/create-refund', 'surecart/cancel-subscription'] as $verb) {
            $this->assertSame(Tier::Irreversible, self::classifier()->classify($verb, ['id' => 'x']), $verb);
            $this->assertContains($verb, SureCartVerbCatalog::UNGRANTABLE, $verb);
        }
        $this->assertCount(6, SureCartVerbCatalog::UNGRANTABLE);
    }

    public function testUngrantableVerbsAreRefusedByGrantEligibilityFilterEvenIfAnotherCallbackAllows(): void
    {
        $GLOBALS['wpas_test_filters'] = [];
        SureCartIntegration::register(new VerbCatalog());
        add_filter('agent_safety_grant_eligible', static fn () => true, 20, 3);

        $this->assertFalse(apply_filters('agent_safety_grant_eligible', false, null, 'surecart/delete-coupon', []));
        $this->assertFalse(apply_filters('agent_safety_grant_eligible', false, null, 'surecart/create-refund', []));
        // A grantable Tier 2 verb is left to the other callbacks.
        $this->assertTrue(apply_filters('agent_safety_grant_eligible', false, null, 'surecart/create-invoice', []));
        $GLOBALS['wpas_test_filters'] = [];
    }

    /** @dataProvider unknownVerbs */
    public function testUnknownVerbIsUnclassified(string $verb): void
    {
        $catalog = new VerbCatalog();
        SureCartIntegration::register($catalog);
        $this->assertNull($catalog->baseTier($verb));
    }

    /** @return list<list<string>> */
    public static function unknownVerbs(): array
    {
        return [['surecart/create-shipping-zone'], ['surecart/delete-product'], ['surecart/'], ['surecart/list-products-v2'], ['surecartx/get-order']];
    }

    public function testRegisterContributesNamespaceAndProbes(): void
    {
        $c = SureCartIntegration::register(new VerbCatalog());
        $this->assertSame(['surecart/'], $c['governedNamespaces']);
        $this->assertCount(3, $c['elevationRules']);
        $this->assertArrayHasKey('surecart/update-price', $c['stateProbes']);
        $this->assertArrayNotHasKey('surecart/create-product', $c['stateProbes']);
    }
}
