<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartIntegration;

/**
 * Class stubs cannot be undefined, so the absent case runs first (and in
 * isolation if an earlier test already defined the stub). Version is driven
 * through a mutable static on the stub.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SureCartDetectionTest extends TestCase
{
    public function testUnavailableWhenSureCartAbsent(): void
    {
        $this->assertFalse(SureCartIntegration::available());
        $this->assertNull(SureCartIntegration::detectVersion());
    }

    public function testMinVersionConstantIsSet(): void
    {
        $this->assertSame('4.9.3', SureCartIntegration::MIN_VERSION);
    }

    /** @dataProvider versions */
    public function testVersionGate(?string $version, bool $expected): void
    {
        self::defineStub($version);
        $this->assertSame($expected, SureCartIntegration::available());
    }

    /** @return array<string, array{?string, bool}> */
    public static function versions(): array
    {
        return [
            'exact minimum' => ['4.9.3', true],
            'newer patch' => ['4.9.10', true],
            'newer major' => ['5.0.0', true],
            'too old' => ['4.9.2', false],
            'much older' => ['3.0.0', false],
            'unreadable version fails closed' => [null, false],
            'garbage fails closed' => ['dev-main', false],
        ];
    }

    public function testNoPluginMethodFailsClosed(): void
    {
        eval('class SureCart {}');
        $this->assertFalse(SureCartIntegration::available());
    }

    private static function defineStub(?string $version): void
    {
        $v = var_export($version, true);
        eval(<<<PHP
        class SureCart {
            public static function plugin() {
                return new class { public function version() { return {$v}; } };
            }
        }
        PHP);
    }
}
