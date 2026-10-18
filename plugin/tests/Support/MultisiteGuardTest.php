<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Belayline\Plugin\Support\MultisiteGuard;

final class MultisiteGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('belayline_allow_multisite');
        unset(
            $GLOBALS['wpas_test_multisite'],
            $GLOBALS['wpas_test_deactivated_plugins'],
            $GLOBALS['wpas_test_added_actions']
        );
        $GLOBALS['wpas_test_user_caps'] = [];
    }

    public function testSingleSiteIsNotRefused(): void
    {
        $GLOBALS['wpas_test_multisite'] = false;

        $this->assertFalse(MultisiteGuard::refused());

        MultisiteGuard::refuseActivation('/plugins/belayline-agent-governance/belayline-agent-governance.php');
        MultisiteGuard::refuseRuntime('/plugins/belayline-agent-governance/belayline-agent-governance.php');

        $this->assertSame([], $GLOBALS['wpas_test_deactivated_plugins'] ?? []);
    }

    public function testMultisiteIsAllowedByDefault(): void
    {
        $GLOBALS['wpas_test_multisite'] = true;

        $this->assertTrue(MultisiteGuard::allowed());
        $this->assertFalse(MultisiteGuard::refused());

        MultisiteGuard::refuseActivation('/plugins/belayline-agent-governance/belayline-agent-governance.php');
        MultisiteGuard::refuseRuntime('/plugins/belayline-agent-governance/belayline-agent-governance.php');

        $this->assertSame([], $GLOBALS['wpas_test_deactivated_plugins'] ?? []);
        $this->assertEmpty($GLOBALS['wpas_test_added_actions']['admin_notices'] ?? []);
    }

    public function testMultisiteActivationDeactivatesAndDiesWithTheRefusal(): void
    {
        $GLOBALS['wpas_test_multisite'] = true;
        add_filter('belayline_allow_multisite', static fn (): bool => false);

        $this->assertTrue(MultisiteGuard::refused());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support WordPress multisite');

        try {
            MultisiteGuard::refuseActivation('/plugins/belayline-agent-governance/belayline-agent-governance.php');
        } finally {
            $this->assertSame(
                ['belayline-agent-governance/belayline-agent-governance.php'],
                $GLOBALS['wpas_test_deactivated_plugins'] ?? []
            );
        }
    }

    public function testMultisiteActivationRefusesNetworkActivationTheSameWay(): void
    {
        // Network-wide activation carries no distinct signal here: is_multisite()
        // is already true the moment a network exists, per-site or network-wide,
        // so one code path covers both.
        $GLOBALS['wpas_test_multisite'] = true;
        add_filter('belayline_allow_multisite', static fn (): bool => false);

        $this->expectException(RuntimeException::class);

        MultisiteGuard::refuseActivation('/plugins/belayline-agent-governance/belayline-agent-governance.php');
    }

    public function testMultisiteRuntimeGuardDeactivatesAndShowsAnAdminNotice(): void
    {
        $GLOBALS['wpas_test_multisite'] = true;
        add_filter('belayline_allow_multisite', static fn (): bool => false);

        MultisiteGuard::refuseRuntime('/plugins/belayline-agent-governance/belayline-agent-governance.php');

        $this->assertSame(
            ['belayline-agent-governance/belayline-agent-governance.php'],
            $GLOBALS['wpas_test_deactivated_plugins'] ?? []
        );
        $this->assertNotEmpty($GLOBALS['wpas_test_added_actions']['admin_notices'] ?? []);
    }

    public function testRuntimeNoticeNamesMultisite(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;

        ob_start();
        MultisiteGuard::renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('multisite is not supported', $html);
    }

    public function testRuntimeNoticeIsHiddenFromUsersWithoutManageOptions(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = false;

        ob_start();
        MultisiteGuard::renderNotice();

        $this->assertSame('', ob_get_clean());
    }
}
