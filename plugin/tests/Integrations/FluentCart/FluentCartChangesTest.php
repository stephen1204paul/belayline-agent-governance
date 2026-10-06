<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\FluentCart;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\Change;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\StoredChange;
use Specflux\AgentSafety\Changes\UndoItem;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\AgentSafety\Changes\UndoScope;
use Specflux\AgentSafety\Plugin\Changes\ChangeCapture;
use Specflux\AgentSafety\Plugin\Changes\UndoService;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartIntegration;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartRestoreAdapter;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartSnapshotter;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\ChainingSink;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeFluentCartDb;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/** Capture through the real window + snapshotter, restore through the real undo engine, over an in-memory fct_* store. */
final class FluentCartChangesTest extends TestCase
{
    private FakeFluentCartDb $db;
    private InMemoryChangeLedger $ledger;
    private ChangeCapture $capture;
    private UndoService $undo;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true]];

        $this->db = new FakeFluentCartDb();
        $this->db->tables['fct_coupons'][5] = self::coupon(5, 'SAVE10', 10, 'active');
        $this->db->tables['fct_customers'][3] = ['id' => 3, 'user_id' => 9, 'email' => 'a@example.com', 'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active', 'notes' => '', 'country' => 'US', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301'];
        $this->db->tables['fct_orders'][42] = ['id' => 42, 'status' => 'processing', 'payment_status' => 'paid', 'shipping_status' => '', 'total_amount' => 5000, 'total_paid' => 5000, 'total_refund' => 0, 'note' => '', 'completed_at' => null, 'refunded_at' => null];
        $this->db->tables['fct_subscriptions'][8] = ['id' => 8, 'status' => 'active', 'canceled_at' => null, 'restored_at' => null, 'next_billing_date' => '2026-11-01', 'bill_count' => 2];

        $catalog = new VerbCatalog();
        FluentCartIntegration::register($catalog, $this->db); // adds the adapters through the filter
        // Same object the integration wired for the store, swapped for the in-memory one.
        $snap = new FluentCartSnapshotter($this->db);
        $GLOBALS['wpas_test_filters'] = [];
        add_filter('agent_safety_change_adapters', fn (array $a): array => [...$a, $snap, new FluentCartRestoreAdapter($snap, $this->db)]);
        $catalog->register(\Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartVerbCatalog::MAP);

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

    /** @return array<string, mixed> */
    private static function coupon(int $id, string $code, int $amount, string $status, int $uses = 0): array
    {
        return ['id' => $id, 'title' => $code, 'code' => $code, 'priority' => 1, 'type' => 'percentage', 'conditions' => null, 'amount' => $amount,
            'status' => $status, 'notes' => '', 'stackable' => 'no', 'show_on_checkout' => 'yes', 'start_date' => null, 'end_date' => null, 'use_count' => $uses];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function call(string $ability, array $args, callable $writes): array
    {
        $before = count($this->ledger->rows);
        $this->capture->run($ability, $args, $writes);
        $event = (string) ($this->capture->takeSettled($ability)['event_id'] ?? '');

        return [array_slice($this->ledger->rows, $before), $event];
    }

    private function undoEvent(string $event): UndoResult
    {
        return $this->undo->undo(UndoScope::event($event), 1);
    }

    public function testACouponUpdateRecordsTheDiffWithoutUseCount(): void
    {
        [$rows] = $this->call('fluent-cart/manage-coupon', ['action' => 'update', 'coupon_id' => 5, 'amount' => 25], function (): void {
            $this->db->tables['fct_coupons'][5]['amount'] = 25;
            $this->db->tables['fct_coupons'][5]['use_count'] = 4; // a checkout racing the call
        });

        $this->assertCount(1, $rows);
        $this->assertSame('fct_coupon', $rows[0]['object_type']);
        $this->assertSame('5', $rows[0]['object_id']);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame('["amount"]', $rows[0]['changed_fields']);
        $this->assertStringNotContainsString('use_count', (string) $rows[0]['before_json']);
    }

    public function testAnUnchangedCouponRecordsNothing(): void
    {
        [$rows] = $this->call('fluent-cart/manage-coupon', ['action' => 'update', 'coupon_id' => 5, 'amount' => 10], static function (): void {
        });

        $this->assertSame([], $rows);
    }

    public function testCouponRoundTripRestoresFieldsAndNeverWritesUseCountBack(): void
    {
        [, $event] = $this->call('fluent-cart/manage-coupon', ['action' => 'update', 'coupon_id' => 5, 'amount' => 25, 'status' => 'inactive'], function (): void {
            $this->db->tables['fct_coupons'][5]['amount'] = 25;
            $this->db->tables['fct_coupons'][5]['status'] = 'inactive';
        });
        $this->db->tables['fct_coupons'][5]['use_count'] = 7; // sales since, not drift

        $result = $this->undoEvent($event);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame(10, $this->db->tables['fct_coupons'][5]['amount']);
        $this->assertSame('active', $this->db->tables['fct_coupons'][5]['status']);
        $this->assertSame(7, $this->db->tables['fct_coupons'][5]['use_count']);
        $this->assertSame(['coupon:5:amount,status'], $this->db->writes);
    }

    public function testEditedSinceIsDriftAndWritesNothing(): void
    {
        [, $event] = $this->call('fluent-cart/manage-coupon', ['action' => 'update', 'coupon_id' => 5, 'amount' => 25], function (): void {
            $this->db->tables['fct_coupons'][5]['amount'] = 25;
        });
        $this->db->tables['fct_coupons'][5]['amount'] = 30;

        $result = $this->undoEvent($event);

        $this->assertSame(UndoItem::REASON_DRIFT, $result->items[0]->reason);
        $this->assertSame([], $this->db->writes);
    }

    public function testACreatedCouponIsDeletedOnlyWhileUnused(): void
    {
        $create = ['action' => 'create', 'code' => 'NEW20', 'type' => 'percentage', 'amount' => 20];
        $make = function (): void {
            $this->db->tables['fct_coupons'][6] = self::coupon(6, 'NEW20', 20, 'active');
        };

        [$rows, $event] = $this->call('fluent-cart/manage-coupon', $create, $make);
        $this->assertCount(1, $rows);
        $this->assertSame('code:NEW20', $rows[0]['object_id']);
        $this->assertSame('create', $rows[0]['action']);
        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertArrayNotHasKey(6, $this->db->tables['fct_coupons']);
        $this->assertSame(['delete-coupon:6'], $this->db->writes);
    }

    public function testACreatedCouponWithUsesIsRefused(): void
    {
        [, $event] = $this->call('fluent-cart/manage-coupon', ['action' => 'create', 'code' => 'NEW20', 'type' => 'percentage', 'amount' => 20], function (): void {
            $this->db->tables['fct_coupons'][6] = self::coupon(6, 'NEW20', 20, 'active');
        });
        $this->db->tables['fct_coupons'][6]['use_count'] = 2;

        $result = $this->undoEvent($event);

        $this->assertSame(FluentCartRestoreAdapter::REASON_COUPON_USED, $result->items[0]->reason);
        $this->assertArrayHasKey(6, $this->db->tables['fct_coupons']);
        $this->assertSame([], $this->db->writes);
    }

    public function testCustomerChangesAreCapturedByIdAndRedactedUnderThePiiPack(): void
    {
        // The default pack redacts PII, and a customer row is PII: the change is recorded
        // but, fail closed, never restorable ("redacted").
        [$rows, $event] = $this->call('fluent-cart/upsert-customer', ['customer_id' => 3, 'city' => 'Dallas'], function (): void {
            $this->db->tables['fct_customers'][3]['city'] = 'Dallas';
        });

        $this->assertSame('fct_customer', $rows[0]['object_type']);
        $this->assertSame('3', $rows[0]['object_id']);
        $this->assertSame('["city"]', $rows[0]['changed_fields']);
        $this->assertSame('redacted', $this->undoEvent($event)->items[0]->reason);
        $this->assertSame([], $this->db->writes);
    }

    public function testACustomerEmailChangeResolvesToOneObjectByItsEmailFirst(): void
    {
        [$rows] = $this->call('fluent-cart/upsert-customer', ['email' => 'a@example.com', 'new_email' => 'b@example.com'], function (): void {
            $this->db->tables['fct_customers'][3]['email'] = 'b@example.com';
        });

        $this->assertSame('3', $rows[0]['object_id']);
        $this->assertSame('["email"]', $rows[0]['changed_fields']);
    }

    public function testACustomerCreateIsRecordedUnderItsEmail(): void
    {
        [$rows] = $this->call('fluent-cart/upsert-customer', ['email' => 'new@example.com', 'first_name' => 'N'], function (): void {
            $this->db->tables['fct_customers'][4] = ['id' => 4, 'user_id' => null, 'email' => 'new@example.com', 'first_name' => 'N', 'last_name' => '', 'status' => 'active', 'notes' => '', 'country' => null, 'city' => null, 'state' => null, 'postcode' => null];
        });

        $this->assertSame('email:new@example.com', $rows[0]['object_id']);
        $this->assertSame('create', $rows[0]['action']);
    }

    public function testRestoreRulesForACustomerAdapterLevel(): void
    {
        $adapter = new FluentCartRestoreAdapter(new FluentCartSnapshotter($this->db), $this->db);
        $row = ['id' => 3, 'email' => 'a@example.com', 'city' => 'Austin'];
        $mk = static fn (ChangeAction $a, array $fields, ?array $before, ?array $after): StoredChange => new StoredChange(1, 'e', 'c', 1, 'fct_customer', '3', null, $a, $before, $after, $fields, true, null, null);

        $this->assertSame(FluentCartRestoreAdapter::REASON_EMAIL, $adapter->canRestore($mk(ChangeAction::Update, ['email'], $row, [...$row, 'email' => 'b@example.com'])));
        $this->assertSame(FluentCartRestoreAdapter::REASON_EMAIL, $adapter->canRestore($mk(ChangeAction::Update, ['email', 'city'], $row, $row)));
        $this->assertSame(FluentCartRestoreAdapter::REASON_CUSTOMER_LIFECYCLE, $adapter->canRestore($mk(ChangeAction::Create, ['id'], null, $row)));
        $this->assertNull($adapter->canRestore($mk(ChangeAction::Update, ['city'], $row, [...$row, 'city' => 'Dallas'])));

        // A city-only change restores through the store, and never writes email.
        $adapter->restore($mk(ChangeAction::Update, ['city'], $row, [...$row, 'city' => 'Dallas']));
        $this->assertSame(['customer:3:city'], $this->db->writes);
        $this->assertSame('Austin', $this->db->tables['fct_customers'][3]['city']);
    }

    public function testLabelsRoundTrip(): void
    {
        $this->db->tables['fct_label_relationships'] = [['label_id' => 1, 'labelable_id' => 42, 'labelable_type' => 'FluentCart\\App\\Models\\Order']];
        [$rows, $event] = $this->call('fluent-cart/apply-labels', ['entity_type' => 'order', 'entity_id' => 42, 'add_label_ids' => [2], 'remove_label_ids' => [1]], function (): void {
            $this->db->addLabel('order', 42, 2);
            $this->db->removeLabel('order', 42, 1);
            $this->db->writes = [];
        });

        $this->assertSame('order:42', $rows[0]['object_id']);
        $this->assertSame('{"label_ids":[1]}', $rows[0]['before_json']);
        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertEqualsCanonicalizing(['add-label:order:42:1', 'remove-label:order:42:2'], $this->db->writes);
    }

    public function testOrderAndSubscriptionChangesAreRecordedAndNeverRestored(): void
    {
        [$orderRows, $e1] = $this->call('fluent-cart/change-order-status', ['order_id' => 42, 'status' => 'completed'], function (): void {
            $this->db->tables['fct_orders'][42]['status'] = 'completed';
        });
        [$refundRows, $e2] = $this->call('fluent-cart/refund-order', ['order_id' => 42, 'amount' => 10], function (): void {
            $this->db->tables['fct_orders'][42]['total_refund'] = 1000;
        });
        [$subRows, $e3] = $this->call('fluent-cart/change-subscription-status', ['subscription_id' => 8, 'action' => 'cancel'], function (): void {
            $this->db->tables['fct_subscriptions'][8]['status'] = 'canceled';
        });

        $this->assertSame('fct_order', $orderRows[0]['object_type']);
        $this->assertSame('["total_refund"]', $refundRows[0]['changed_fields']);
        $this->assertSame('fct_subscription', $subRows[0]['object_type']);
        foreach ([$e1, $e2, $e3] as $event) {
            $item = $this->undoEvent($event)->items[0];
            $this->assertSame(UndoItem::SKIPPED, $item->status);
            $this->assertSame(Change::REASON_IRREVERSIBLE, $item->reason);
        }
        $this->assertSame('completed', $this->db->tables['fct_orders'][42]['status']);
        $this->assertSame([], $this->db->writes);
    }

    public function testAnOrderNoteLeavesTheOrderRowAloneSoRecordsNothing(): void
    {
        // The note lives in the activity log (OrderTools.php addOrderNote), not on fct_orders.
        [$rows] = $this->call('fluent-cart/add-order-note', ['order_id' => 42, 'note' => 'hi'], static function (): void {
        });

        $this->assertSame([], $rows);
    }

    public function testRestoreNeedsManageOptions(): void
    {
        $snap = new FluentCartSnapshotter($this->db);
        $stored = (new FluentCartRestoreAdapter($snap, $this->db))->capability($this->makeChange());

        $this->assertSame('manage_options', $stored);
    }

    private function makeChange(): StoredChange
    {
        return new StoredChange(1, 'e', 'c', 1, 'fct_coupon', '5', null, ChangeAction::Update, [], [], [], true, null, null);
    }
}
