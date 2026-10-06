<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\AgentSafety\Changes\UndoItem;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\AgentSafety\Changes\UndoScope;
use Specflux\AgentSafety\Plugin\Changes\ChangeAdapters;
use Specflux\AgentSafety\Plugin\Changes\ChangeCapture;
use Specflux\AgentSafety\Plugin\Changes\UndoService;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartChangeAdapter;
use Specflux\AgentSafety\Plugin\Integrations\SureCart\SureCartVerbCatalog;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\ChainingSink;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/** Capture and restore of SureCart's remote objects, against an in-memory remote: no network. */
final class SureCartChangeAdapterTest extends TestCase
{
    /** @var array<string, array<string, mixed>> "Model:id" => attributes */
    private array $remote = [];
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $patches = [];
    private bool $getFails = false;
    private bool $patchFails = false;

    private InMemoryChangeLedger $ledger;
    private ChangeCapture $capture;
    private UndoService $undo;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        foreach (['wpas_test_posts', 'wpas_test_meta', 'wpas_test_options', 'wpas_test_terms', 'wpas_test_added_actions', 'wpas_test_filters'] as $k) {
            $GLOBALS[$k] = [];
        }
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true]];
        $GLOBALS['wpas_test_posts'][9] = (object) [
            'ID' => 9, 'post_title' => 'Tee', 'post_type' => 'sc_product', 'post_status' => 'publish',
            'post_password' => '', 'post_modified' => '2026-01-01 00:00:00',
        ];

        $this->remote = [
            'Price:pr1' => ['id' => 'pr1', 'amount' => 1000, 'name' => 'Std', 'product' => 'p1', 'archived_at' => null, 'updated_at' => 1, 'prices' => [['x']]],
            'Product:p1' => ['id' => 'p1', 'name' => 'Tee', 'archived_at' => null, 'updated_at' => 1],
            'Charge:ch1' => ['id' => 'ch1', 'refunded_amount' => 0, 'updated_at' => 1],
        ];
        $this->patches = [];
        $this->getFails = false;
        $this->patchFails = false;

        $adapter = new SureCartChangeAdapter(
            function (string $model, string $id): ?array {
                if ($this->getFails) {
                    throw new RuntimeException('API down');
                }

                return $this->remote[$model . ':' . $id] ?? null;
            },
            function (string $model, string $id, array $fields): void {
                if ($this->patchFails) {
                    throw new RuntimeException('PATCH refused');
                }
                $this->patches[] = [$model, $id, $fields];
                $this->remote[$model . ':' . $id] = $fields + $this->remote[$model . ':' . $id];
                $this->remote[$model . ':' . $id]['updated_at'] = 99;
                if (isset($fields['archived'])) {
                    $this->remote[$model . ':' . $id]['archived_at'] = $fields['archived'] ? 5 : null;
                }
            },
            static fn (string $productId): ?int => $productId === 'p1' ? 9 : null,
        );
        add_filter(ChangeAdapters::FILTER, static fn (array $a): array => [...$a, $adapter]);

        $catalog = new VerbCatalog();
        $catalog->register(SureCartVerbCatalog::MAP);
        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
        $this->undo = new UndoService($this->ledger, $this->capture->adapters(), $this->capture, new ChainingSink());
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    /** @param array<string, mixed> $args */
    private function call(string $ability, array $args, callable $execute): string
    {
        $this->capture->run($ability, $args, $execute);

        return (string) $this->capture->takeSettled($ability)['event_id'];
    }

    public function testAPriceAmountChangeIsRecordedAndRestoredWithAPatchOfOnlyThatField(): void
    {
        $event = $this->call('surecart/update-price', ['id' => 'pr1', 'amount' => 1500], function (): array {
            $this->remote['Price:pr1']['amount'] = 1500;
            $this->remote['Price:pr1']['updated_at'] = 2;

            return ['success' => true];
        });

        $rows = $this->ledger->rows;
        $this->assertCount(1, $rows);
        $this->assertSame('surecart_price', $rows[0]['object_type']);
        $this->assertSame('pr1', $rows[0]['object_id']);
        $this->assertContains('amount', json_decode($rows[0]['changed_fields'], true));
        $this->assertStringNotContainsString('prices', $rows[0]['before_json'], 'nested objects are not snapshotted');

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame([['Price', 'pr1', ['amount' => 1000]]], $this->patches);
    }

    public function testDriftRefusesAndPatchesNothing(): void
    {
        $event = $this->call('surecart/update-price', ['id' => 'pr1', 'amount' => 1500], function (): void {
            $this->remote['Price:pr1']['amount'] = 1500;
        });
        $this->remote['Price:pr1']['amount'] = 1700; // a human changed it since

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame(UndoItem::REASON_DRIFT, $result->items[0]->reason);
        $this->assertSame([], $this->patches);
    }

    public function testACreatedProductIsRecordedFromTheResultAndUndoneByArchiving(): void
    {
        $event = $this->call('surecart/create-product', ['name' => 'Hat'], function (): array {
            $this->remote['Product:p2'] = ['id' => 'p2', 'name' => 'Hat', 'archived_at' => null, 'updated_at' => 3];

            return ['success' => true, 'product' => ['id' => 'p2', 'name' => 'Hat']];
        });

        $this->assertCount(1, $this->ledger->rows);
        $this->assertSame('create', $this->ledger->rows[0]['action']);
        $this->assertNull($this->ledger->rows[0]['before_json']);

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame([['Product', 'p2', ['archived' => true]]], $this->patches, 'archived, never deleted');
    }

    public function testACreatedCouponCannotBeUndone(): void
    {
        $event = $this->call('surecart/create-coupon', ['name' => 'X'], function (): array {
            $this->remote['Coupon:c1'] = ['id' => 'c1', 'name' => 'X', 'updated_at' => 3];

            return ['coupon' => ['id' => 'c1']];
        });

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame(SureCartChangeAdapter::REASON_CREATE, $result->items[0]->reason);
        $this->assertSame([], $this->patches);
    }

    public function testARefundIsRecordedIrreversible(): void
    {
        $event = $this->call('surecart/create-refund', ['charge' => 'ch1', 'amount' => 500, 'reason' => 'duplicate'], function (): array {
            $this->remote['Charge:ch1']['refunded_amount'] = 500;
            $this->remote['Refund:r1'] = ['id' => 'r1', 'amount' => 500, 'updated_at' => 3];

            return ['refund' => ['id' => 'r1']];
        });

        $this->assertCount(2, $this->ledger->rows, 'the charge and the refund');
        foreach ($this->ledger->rows as $row) {
            $this->assertStringStartsWith('irreversible:', (string) $row['object_subtype']);
        }
        $result = $this->undo->undo(UndoScope::event($event), 1);
        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame('irreversible', $result->items[0]->reason);
        $this->assertSame([], $this->patches);
    }

    public function testADeleteIsRecordedButNotRestorable(): void
    {
        $this->remote['Coupon:c9'] = ['id' => 'c9', 'name' => 'Old', 'updated_at' => 1];
        $event = $this->call('surecart/delete-coupon', ['id' => 'c9'], function (): void {
            unset($this->remote['Coupon:c9']);
        });

        $this->assertSame('delete', $this->ledger->rows[0]['action']);
        $result = $this->undo->undo(UndoScope::event($event), 1);
        $this->assertSame(SureCartChangeAdapter::REASON_DELETE, $result->items[0]->reason);
    }

    public function testTheSyncedProductPostIsNotRecordedAsAnAgentChange(): void
    {
        $this->call('surecart/update-product', ['id' => 'p1', 'name' => 'Tee 2'], function (): void {
            $this->remote['Product:p1']['name'] = 'Tee 2';
            // SureCart's own sync rewrites the local sc_product post during the call.
            wp_update_post(wp_slash(['ID' => 9, 'post_title' => 'Tee 2']));
        });

        $this->assertSame(['surecart_product'], array_column($this->ledger->rows, 'object_type'));
    }

    public function testASyncedPostCreatedByACreateIsNotRecordedEither(): void
    {
        $this->call('surecart/create-product', ['name' => 'Hat'], function (): array {
            $this->remote['Product:p1']['name'] = 'Hat'; // the fake maps p1 to post 9
            wp_update_post(wp_slash(['ID' => 9, 'post_title' => 'Hat']));

            return ['product' => ['id' => 'p1']];
        });

        $this->assertNotContains('post', array_column($this->ledger->rows, 'object_type'));
    }

    public function testAFailedGetDuringCaptureIsAMarkerAndTheCallStillRuns(): void
    {
        $this->getFails = true;
        $ran = false;

        $this->call('surecart/update-price', ['id' => 'pr1', 'amount' => 1500], function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertSame(['capture_error'], array_column($this->ledger->rows, 'object_id'));
    }

    public function testAFailedPatchStopsTheUndoAndReportsIt(): void
    {
        $event = $this->call('surecart/update-price', ['id' => 'pr1', 'amount' => 1500], function (): void {
            $this->remote['Price:pr1']['amount'] = 1500;
        });
        $this->patchFails = true;

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame(UndoItem::FAILED, $result->items[0]->status);
        $this->assertSame(UndoItem::REASON_RESTORE_FAILED, $result->items[0]->reason);
        $this->assertSame([], $this->patches);
    }

    public function testAFailedGetDuringUndoIsReportedAsAFailure(): void
    {
        $event = $this->call('surecart/update-price', ['id' => 'pr1', 'amount' => 1500], function (): void {
            $this->remote['Price:pr1']['amount'] = 1500;
        });
        $this->getFails = true;

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoItem::FAILED, $result->items[0]->status);
        $this->assertSame([], $this->patches);
    }

    public function testANotifyingFulfillmentIsIrreversibleButAQuietOneIsNot(): void
    {
        $this->remote['Fulfillment:f1'] = ['id' => 'f1', 'shipment_status' => 'pending', 'updated_at' => 1];
        foreach ([true, false] as $notify) {
            $this->ledger->rows = [];
            $this->remote['Fulfillment:f1']['shipment_status'] = 'pending';
            $this->call('surecart/update-fulfillment', ['id' => 'f1', 'notifications_enabled' => $notify], function (): void {
                $this->remote['Fulfillment:f1']['shipment_status'] = 'shipped';
            });
            $subtype = $this->ledger->rows[0]['object_subtype'];
            $notify ? $this->assertSame('irreversible:surecart/update-fulfillment', $subtype) : $this->assertNull($subtype);
        }
    }
}
