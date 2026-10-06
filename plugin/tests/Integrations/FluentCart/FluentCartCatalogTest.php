<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\FluentCart;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartIntegration;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartVerbCatalog;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The fixture (tests/Fixtures/fluentcart-1.7.0-abilities.json) lists every
 * ability FluentCart 1.7.0 registers, generated from its source. When a new
 * FluentCart version adds one, regenerate the fixture: this then fails until a
 * human has classified the new ability in the catalog.
 */
final class FluentCartCatalogTest extends TestCase
{
    /** @return list<array{ability: string, source: string, readonly: bool, destructive: bool, idempotent: bool}> */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__ . '/../../Fixtures/fluentcart-1.7.0-abilities.json');
        self::assertIsString($raw);
        /** @var array{abilities: list<array{ability: string, source: string, readonly: bool, destructive: bool, idempotent: bool}>} $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $data['abilities'];
    }

    public function testFixtureHasAllThirtyThreeAbilities(): void
    {
        $this->assertCount(33, self::fixture());
    }

    public function testCatalogCoversEveryFixtureAbilityAndNothingElse(): void
    {
        $fixture = array_column(self::fixture(), 'ability');
        $catalog = array_keys(FluentCartVerbCatalog::MAP);
        sort($fixture);
        sort($catalog);

        $this->assertSame([], array_values(array_diff($fixture, $catalog)), 'fixture abilities missing from the catalog');
        $this->assertSame([], array_values(array_diff($catalog, $fixture)), 'catalog entries not in the fixture');
    }

    public function testNoWildcardEntries(): void
    {
        foreach (array_keys(FluentCartVerbCatalog::MAP) as $verb) {
            $this->assertStringStartsWith('fluent-cart/', $verb);
            $this->assertStringNotContainsString('*', $verb);
        }
    }

    public function testOurTiersAgreeWithFluentCartsOwnAnnotationsAsAFloor(): void
    {
        foreach (self::fixture() as $row) {
            $tier = FluentCartVerbCatalog::MAP[$row['ability']];
            if ($row['readonly']) {
                $this->assertSame(Tier::Reversible, $tier, $row['ability'] . ' is read-only');
            } else {
                $this->assertTrue($tier->isWrite(), $row['ability'] . ' mutates');
            }
            if ($row['destructive']) {
                $this->assertSame(Tier::Irreversible, $tier, $row['ability'] . ' is destructive');
            }
        }
    }

    /** @dataProvider baseTiers */
    public function testBaseTierPerSpec(string $verb, Tier $expected): void
    {
        $this->assertSame($expected, self::classifier()->classify($verb, []));
    }

    /** @return array<string, array{string, Tier}> */
    public static function baseTiers(): array
    {
        return [
            'read: list-orders' => ['fluent-cart/list-orders', Tier::Reversible],
            'read: get-customer' => ['fluent-cart/get-customer', Tier::Reversible],
            'read: query-sources' => ['fluent-cart/query-sources', Tier::Reversible],
            'read: get-sales-report' => ['fluent-cart/get-sales-report', Tier::Reversible],
            'apply-labels' => ['fluent-cart/apply-labels', Tier::SideEffecting],
            'add-order-note (internal)' => ['fluent-cart/add-order-note', Tier::SideEffecting],
            'upsert-customer (create)' => ['fluent-cart/upsert-customer', Tier::SideEffecting],
            'change-order-status' => ['fluent-cart/change-order-status', Tier::Irreversible],
            'refund-order' => ['fluent-cart/refund-order', Tier::Irreversible],
            'change-subscription-status' => ['fluent-cart/change-subscription-status', Tier::Irreversible],
        ];
    }

    public function testUnknownFluentCartAbilityIsRefused(): void
    {
        $classifier = self::classifier();

        $this->assertNull($classifier->classify('fluent-cart/delete-product', []));
        $this->assertNull($classifier->classify('fluent-cart/get-license', []));
        $this->assertNull($classifier->classify('fluent-cart/', []));
    }

    public function testRegisterGovernsTheNamespaceAndNothingElse(): void
    {
        $catalog = new VerbCatalog();
        $contrib = FluentCartIntegration::register($catalog, null);

        $this->assertSame(['fluent-cart/'], $contrib['governedNamespaces']);
        $this->assertSame(['fluentcart-default-agent', 'fluentcart-readonly-analyst'], array_map(static fn ($p): string => $p->name, $contrib['packs']));
        $this->assertSame([], $contrib['stateProbes'], 'no $wpdb, no probes');
        $this->assertNull($catalog->baseTier('woocommerce/orders-get'));
    }

    private static function classifier(): TierClassifier
    {
        $catalog = new VerbCatalog();
        $c = FluentCartIntegration::register($catalog, null);

        return new TierClassifier($catalog, $c['elevationRules']);
    }

    public function testIdentityChainIsUntouched(): void
    {
        // register() takes no IdentityChain: FluentCart adds no identity provider.
        $this->assertSame([], (new IdentityChain())->providers());
    }
}
