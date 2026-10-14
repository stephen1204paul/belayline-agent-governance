<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Integrations\FluentCart;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Integrations\FluentCart\FluentCartIntegration;

final class FluentCartDetectionTest extends TestCase
{
    public function testUnavailableWhenFluentCartIsAbsent(): void
    {
        if (defined('FLUENTCART_VERSION')) {
            $this->markTestSkipped('FLUENTCART_VERSION already defined in this process.');
        }

        $this->assertFalse(FluentCartIntegration::available());
    }

    /** @dataProvider versions */
    public function testVersionGate(mixed $version, bool $expected): void
    {
        $this->assertSame($expected, FluentCartIntegration::isSupportedVersion($version));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function versions(): array
    {
        return [
            'null (absent)' => [null, false],
            'empty' => ['', false],
            'non-string' => [1.7, false],
            'too old 1.6.9' => ['1.6.9', false],
            'too old 1.0.0' => ['1.0.0', false],
            'minimum' => ['1.7.0', true],
            'newer patch' => ['1.7.3', true],
            'newer minor' => ['1.10.0', true],
            'pre-release of min' => ['1.7.0-beta1', false],
        ];
    }

    public function testMinVersionIsTheVerifiedRelease(): void
    {
        $this->assertSame('1.7.0', FluentCartIntegration::MIN_VERSION);
    }
}
