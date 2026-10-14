<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use Belayline\Packs\Pack;
use Belayline\Plugin\Integrations\SureCart\SureCartIntegration;
use Belayline\Plugin\Integrations\SureCart\SureCartPacks;
use Belayline\Plugin\Integrations\SureCart\SureCartVerbCatalog;
use Belayline\Policy\Tier;
use Belayline\Policy\VerbCatalog;

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

    public function testRegisterReturnsThePacksAndTheBootstrapMergesThem(): void
    {
        $registered = SureCartIntegration::register(new VerbCatalog());

        $this->assertSame(
            ['surecart-default-agent', 'surecart-readonly-analyst'],
            array_map(static fn (Pack $p): string => $p->name, $registered['packs']),
        );

        // The bootstrap is procedural and not loadable in unit tests, so pin the
        // wiring itself: it must merge the module's packs like Woo's.
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 3) . '/belayline-agent-governance.php');
        $this->assertStringContainsString(
            '$belayline_extra_packs = [...$belayline_extra_packs, ...$belayline_surecart[\'packs\']];',
            $bootstrap,
        );
    }
}
