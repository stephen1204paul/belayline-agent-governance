<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Admin\Map\PackLabel;
use Belayline\Plugin\Admin\Ui\AbilityLabel;
use PHPUnit\Framework\TestCase;

/** Pack and ability names as people read them. */
final class LabelsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_abilities'] = [];
        AbilityLabel::reset();
        remove_all_filters('belayline_pack_label');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_abilities'] = [];
        AbilityLabel::reset();
        remove_all_filters('belayline_pack_label');
    }

    public function testPackSlugsAreHumanisedAndAFilterCanOverride(): void
    {
        $this->assertSame('Content editor', PackLabel::of('content-editor'));
        $this->assertSame('Woo default agent', PackLabel::of('woo-default-agent'));
        $this->assertSame('Owner', PackLabel::of('owner'));

        add_filter('belayline_pack_label', static fn (string $label, string $slug): string => $slug === 'owner' ? 'Full access' : $label, 10, 2);
        $this->assertSame('Full access', PackLabel::of('owner'));
    }

    public function testAbilitiesShowTheirRegisteredLabelWithTheNameBeneath(): void
    {
        $GLOBALS['wpas_test_abilities']['shop/update-page'] = ['label' => 'Update page'];

        $this->assertSame('Update page', AbilityLabel::text('shop/update-page'));
        $this->assertSame('Update page<div class="aui-mono aui-muted">shop/update-page</div>', AbilityLabel::html('shop/update-page'));
    }

    public function testAnUnregisteredOrUnlabelledAbilityFallsBackToItsName(): void
    {
        $GLOBALS['wpas_test_abilities']['shop/blank'] = ['label' => ''];

        $this->assertSame('shop/gone', AbilityLabel::text('shop/gone'));
        $this->assertSame('shop/blank', AbilityLabel::text('shop/blank'));
        $this->assertSame('<span class="aui-mono">shop/gone</span>', AbilityLabel::html('shop/gone'));
    }

    public function testLabelsAreEscaped(): void
    {
        $GLOBALS['wpas_test_abilities']['shop/x'] = ['label' => '<script>x</script>'];

        $this->assertStringNotContainsString('<script>', AbilityLabel::html('shop/x'));
    }
}
