<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Belayline\Plugin\Integrations\SureCart\SureCartStateProbe;
use SureCart\Models\FoundModel;
use SureCart\Models\Price;

final class SureCartStateProbeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../stubs/surecart-price.php';
        require_once __DIR__ . '/../../stubs/surecart-found-model.php';
    }

    protected function setUp(): void
    {
        Price::$queries = [];
        Price::$result = null;
    }

    public function testReturnsFingerprintFacts(): void
    {
        $probe = new SureCartStateProbe(static fn (string $m, string $id): array => [
            'id' => $id, 'updated_at' => 1700000000, 'archived_at' => null, 'status' => 'active', 'amount' => 5,
        ]);

        $this->assertSame(
            ['updated_at' => 1700000000, 'status' => 'active', 'archived_at' => null],
            $probe->read('surecart/update-price', ['id' => 'abc-123', 'amount' => 9]),
        );
    }

    public function testChangedRemoteStateChangesTheFacts(): void
    {
        $ts = 1;
        $probe = new SureCartStateProbe(static function () use (&$ts): array {
            return ['updated_at' => $ts];
        });
        $before = $probe->read('surecart/update-price', ['id' => 'p1']);
        $ts = 2;
        $this->assertNotSame($before, $probe->read('surecart/update-price', ['id' => 'p1']));
    }

    public function testFetcherReceivesModelAndIdFromTheVerbTable(): void
    {
        $seen = [];
        $probe = new SureCartStateProbe(static function (string $m, string $id) use (&$seen): array {
            $seen[] = [$m, $id];

            return ['updated_at' => 1];
        });
        $probe->read('surecart/cancel-subscription', ['id' => 'sub_1']);
        $probe->read('surecart/update-subscription-renewal-date', ['subscription_id' => 'sub_2']);
        $probe->read('surecart/create-refund', ['charge' => 'ch_1']);

        $this->assertSame([['Subscription', 'sub_1'], ['Subscription', 'sub_2'], ['Charge', 'ch_1']], $seen);
    }

    public function testFailedFetchFailsClosed(): void
    {
        $err = new SureCartStateProbe(static fn (): ?array => null);
        $this->assertNull($err->read('surecart/update-price', ['id' => 'p1']));

        $boom = new SureCartStateProbe(static function (): array {
            throw new RuntimeException('timeout');
        });
        $this->assertNull($boom->read('surecart/update-price', ['id' => 'p1']));

        $noStamp = new SureCartStateProbe(static fn (): array => ['status' => 'active']);
        $this->assertNull($noStamp->read('surecart/update-price', ['id' => 'p1']));
    }

    /** @dataProvider badIds */
    public function testBadOrMissingIdNeverFetches(mixed $id): void
    {
        $called = false;
        $probe = new SureCartStateProbe(static function () use (&$called): array {
            $called = true;

            return ['updated_at' => 1];
        });
        $args = $id === null ? [] : ['id' => $id];

        $this->assertNull($probe->read('surecart/update-price', $args));
        $this->assertFalse($called);
        $this->assertSame([], $probe->targetArgs('surecart/update-price', $args));
    }

    /** @return list<list<mixed>> */
    public static function badIds(): array
    {
        return [[null], [''], [42], [['a']], ['a/../b'], ['a b']];
    }

    public function testUnprobedVerbReturnsNull(): void
    {
        $probe = new SureCartStateProbe(static fn (): array => ['updated_at' => 1]);
        $this->assertNull($probe->read('surecart/create-product', ['id' => 'x']));
    }

    public function testTargetArgsOnlyContainsTheIdentifyingKey(): void
    {
        $probe = new SureCartStateProbe();
        $this->assertSame(['id' => 'p1'], $probe->targetArgs('surecart/update-price', ['id' => 'p1', 'amount' => 5]));
        $this->assertSame(['subscription_id' => 's1'], $probe->targetArgs('surecart/update-subscription-renewal-date', ['subscription_id' => 's1', 'confirm' => true]));
    }

    public function testLiveFetchBypassesCacheThroughTheModelLayer(): void
    {
        Price::$result = new FoundModel(['updated_at' => 99, 'status' => 'x']);
        $probe = new SureCartStateProbe();

        $facts = $probe->read('surecart/update-price', ['id' => 'p1']);

        $this->assertSame(99, $facts['updated_at'] ?? null);
        $this->assertSame([['cached' => false], ['find' => 'p1']], Price::$queries);
    }

    public function testLiveFetchErrorResultFailsClosed(): void
    {
        Price::$result = false; // SureCart find() can return false when a finding listener cancels
        $this->assertNull((new SureCartStateProbe())->read('surecart/update-price', ['id' => 'p1']));

        Price::$result = new \stdClass(); // no toArray: unusable
        $this->assertNull((new SureCartStateProbe())->read('surecart/update-price', ['id' => 'p1']));
    }

    public function testLiveFetchWithMissingModelClassFailsClosed(): void
    {
        // Product has no stub: class_exists is false.
        $this->assertNull((new SureCartStateProbe())->read('surecart/update-product', ['id' => 'p1']));
    }
}
