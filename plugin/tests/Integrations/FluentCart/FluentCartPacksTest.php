<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Integrations\FluentCart;

use PHPUnit\Framework\TestCase;
use Belayline\Packs\Pack;
use Belayline\Plugin\Integrations\FluentCart\FluentCartPacks;
use Belayline\Plugin\Integrations\FluentCart\FluentCartVerbCatalog;
use Belayline\Policy\Tier;

final class FluentCartPacksTest extends TestCase
{
    private function pack(string $name): Pack
    {
        foreach (FluentCartPacks::all() as $p) {
            if ($p->name === $name) {
                return $p;
            }
        }
        $this->fail($name);
    }

    public function testReadonlyAnalystAllowsEveryReadAndDeniesEveryWrite(): void
    {
        $pack = $this->pack('fluentcart-readonly-analyst');

        $this->assertTrue($pack->allows('fluent-cart/list-orders'));
        $this->assertTrue($pack->allows('fluent-cart/get-sales-report'));
        foreach (FluentCartVerbCatalog::MAP as $verb => $tier) {
            $this->assertSame($tier === Tier::Reversible, $pack->allows($verb), $verb);
        }
        $this->assertFalse($pack->allows('fluent-cart/manage-coupon'));
        $this->assertFalse($pack->allows('fluent-cart/refund-order'));
        $this->assertTrue($pack->deniesClass(Tier::SideEffecting));
        $this->assertTrue($pack->deniesClass(Tier::Irreversible));
    }

    public function testDefaultAgentReachesEverythingButTierTwoNeedsApproval(): void
    {
        $pack = $this->pack('fluentcart-default-agent');

        $this->assertTrue($pack->allows('fluent-cart/manage-coupon'));
        $this->assertTrue($pack->allows('fluent-cart/refund-order'));
        $this->assertFalse($pack->allows('woocommerce/orders-list'));
        $this->assertTrue($pack->requiresApproval(Tier::Irreversible));
        $this->assertFalse($pack->requiresApproval(Tier::SideEffecting));
        $this->assertFalse($pack->deniesClass(Tier::Irreversible));
    }
}
