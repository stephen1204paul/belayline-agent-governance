<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartPacks;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartVerbCatalog;
use Specflux\AgentSafety\Policy\Tier;

final class SureCartPacksTest extends TestCase
{
    private function pack(string $name): Pack
    {
        foreach (SureCartPacks::all() as $pack) {
            if ($pack->name === $name) {
                return $pack;
            }
        }
        $this->fail('missing pack ' . $name);
    }

    public function testDefaultAgentReachesEverythingButTier2NeedsApproval(): void
    {
        $pack = $this->pack('surecart-default-agent');

        $this->assertTrue($pack->allows('surecart/list-products'));
        $this->assertTrue($pack->allows('surecart/update-price'));
        $this->assertTrue($pack->requiresApproval(Tier::Irreversible));
        $this->assertFalse($pack->requiresApproval(Tier::SideEffecting));
    }

    public function testReadonlyAnalystPassesEveryReadAndDeniesEveryWrite(): void
    {
        $pack = $this->pack('surecart-readonly-analyst');

        foreach (SureCartVerbCatalog::MAP as $verb => $tier) {
            $this->assertSame($tier === Tier::Reversible, $pack->allows($verb), $verb);
        }
        $this->assertTrue($pack->deniesClass(Tier::SideEffecting));
        $this->assertTrue($pack->deniesClass(Tier::Irreversible));
    }
}
