<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\StoredChange;
use Specflux\AgentSafety\Changes\UndoItem;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\AgentSafety\Changes\UndoScope;
use Specflux\AgentSafety\Plugin\Changes\ChangeAdapters;
use Specflux\AgentSafety\Plugin\Changes\ChangeCapture;
use Specflux\AgentSafety\Plugin\Changes\UndoService;
use Specflux\AgentSafety\Plugin\Integrations\Woo\Changes\WooCaptureAdapter;
use Specflux\AgentSafety\Plugin\Integrations\Woo\Changes\WooChangeAdapters;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\ChainingSink;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Packs\PackRegistry;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * WooCommerce capture and restore through the real undo engine, against
 * hand-rolled CRUD classes (tests/stubs/woocommerce-crud.php). The stubs encode
 * the WooCommerce contract the adapters rely on; only a live site proves the
 * contract itself.
 */
final class WooChangesTest extends TestCase
{
    private InMemoryChangeLedger $ledger;
    private ChangeCapture $capture;
    private UndoService $undo;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/woocommerce-crud.php';
    }

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        foreach (['wpas_test_posts', 'wpas_test_meta', 'wpas_test_options', 'wpas_test_added_actions', 'wpas_test_filters', 'wc_fake_db', 'wc_fake_tax', 'wc_fake_tax_loc', 'wc_fake_passwords'] as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['wc_fake_price_recomputes'] = 0;
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_woocommerce' => true]];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /**
             * wpdb::get_col(), reached through __call so the method keeps wpdb's own name.
             *
             * @param list<string> $args
             * @return list<string>
             */
            public function __call(string $name, array $args): array
            {
                preg_match('/tax_rate_id = (\d+) AND location_type = \'(\w+)\'/', $args[0], $m);
                $value = $GLOBALS['wc_fake_tax_loc'][(int) ($m[1] ?? 0)][$m[2] ?? ''] ?? '';

                return $value === '' ? [] : explode(';', (string) $value);
            }
        };

        // Woo adapters first: real WordPress runs their claim listeners at priority 1, but the
        // test action shim ignores priorities and fires in registration order.
        add_filter(ChangeAdapters::FILTER, static fn (array $adapters): array => [...WooChangeAdapters::all(), ...$adapters]);
        // The default pack redacts PII (customer and tax rows would be recorded unrestorable): use full.
        add_filter('agent_safety_pack_registry', static function (PackRegistry $registry): PackRegistry {
            $registry->register(new Pack(name: 'default-agent', allow: [], pii: 'full'));

            return $registry;
        });
        $catalog = new VerbCatalog();
        $catalog->register(['t/write' => Tier::SideEffecting, 'woocommerce/taxes-update' => Tier::SideEffecting, 'woocommerce/taxes-create' => Tier::SideEffecting]);
        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
        $this->undo = new UndoService($this->ledger, $this->capture->adapters(), $this->capture, new ChainingSink());
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        unset($GLOBALS['wpdb']);
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $meta */
    private static function seed(string $group, int $id, string $class, array $data, array $meta = []): void
    {
        $object = new $class();
        $defaults = $object->get_data();
        unset($defaults['id'], $defaults['meta_data']);
        $GLOBALS['wc_fake_db'][$group][$id] = ['data' => array_merge($defaults, $data), 'meta' => $meta, 'class' => $class];
        if (in_array($group, ['product', 'coupon'], true)) {
            $GLOBALS['wpas_test_posts'][$id] = (object) ['ID' => $id, 'post_title' => 'x', 'post_type' => $group === 'coupon' ? 'shop_coupon' : 'product', 'post_status' => 'publish', 'post_password' => ''];
        }
    }

    private function call(callable $writes, string $ability = 't/write', array $args = []): string
    {
        $this->capture->run($ability, $args, $writes);

        return (string) $this->capture->takeSettled($ability)['event_id'];
    }

    /** @return list<StoredChange> */
    private function rows(string $event): array
    {
        return $this->ledger->forEvent($event);
    }

    private function undoEvent(string $event): UndoResult
    {
        return $this->undo->undo(UndoScope::event($event), 1);
    }

    private static function product(int $id): \WC_Product
    {
        $product = wc_get_product($id);
        self::assertInstanceOf(\WC_Product::class, $product);

        return $product;
    }

    /** @return array<string, mixed> */
    private static function stored(string $group, int $id): array
    {
        return $GLOBALS['wc_fake_db'][$group][$id]['data'];
    }

    private function seedPricedProduct(): void
    {
        self::seed('product', 7, \WC_Product::class, ['name' => 'Mug', 'regular_price' => '10', 'sale_price' => '', 'price' => '10', 'stock_quantity' => 10, 'manage_stock' => true]);
    }

    public function testProductPriceIsRecordedOnceWithBeforeAndAfterAndRoundTripsThroughThePriceRecompute(): void
    {
        $this->seedPricedProduct();

        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->set_regular_price('15');
            $p->set_sale_price('12');
            $p->save();
        });

        $rows = $this->rows($event);
        $this->assertCount(1, $rows, 'one wc_product change; the core post and post meta adapters are claimed off');
        $this->assertSame('wc_product', $rows[0]->objectType);
        $this->assertSame('7', $rows[0]->objectId);
        $this->assertSame(ChangeAction::Update, $rows[0]->action);
        $this->assertSame('10', $rows[0]->before['regular_price']);
        $this->assertSame('15', $rows[0]->after['regular_price']);
        $this->assertSame('12', $rows[0]->after['price'], 'the after state is read back, so it carries the derived price');
        $this->assertTrue($rows[0]->restorable);

        $recomputes = $GLOBALS['wc_fake_price_recomputes'];
        $result = $this->undoEvent($event);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame('10', self::stored('product', 7)['regular_price']);
        $this->assertSame('', self::stored('product', 7)['sale_price']);
        $this->assertSame('10', self::stored('product', 7)['price'], '_price recomputed by the save, not written directly');
        $this->assertSame($recomputes + 1, $GLOBALS['wc_fake_price_recomputes']);
    }

    public function testDriftRefusesAndWritesNothing(): void
    {
        $this->seedPricedProduct();
        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->set_regular_price('15');
            $p->save();
        });
        $GLOBALS['wc_fake_db']['product'][7]['data']['regular_price'] = '99'; // a human edit since

        $result = $this->undoEvent($event);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame(UndoItem::REASON_DRIFT, $result->items[0]->reason);
        $this->assertSame('99', self::stored('product', 7)['regular_price']);
    }

    public function testACreatedProductIsTrashedNeverDeleted(): void
    {
        $event = $this->call(static function (): void {
            $p = new \WC_Product();
            $p->set_name('New');
            $p->set_regular_price('5');
            $p->save();
        });

        $rows = $this->rows($event);
        $this->assertCount(1, $rows);
        $this->assertSame(ChangeAction::Create, $rows[0]->action);
        $this->assertNull($rows[0]->before);
        $this->assertSame('New', $rows[0]->after['name']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('trash', self::stored('product', (int) $rows[0]->objectId)['status']);
    }

    public function testATrashedProductGetsItsStatusBackAndAPermanentDeleteIsRefused(): void
    {
        $this->seedPricedProduct();
        $trashed = $this->call(static fn () => self::product(7)->delete(false));
        $this->assertSame(ChangeAction::Trash, $this->rows($trashed)[0]->action);
        $this->assertSame('trash', self::stored('product', 7)['status']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($trashed)->status);
        $this->assertSame('publish', self::stored('product', 7)['status']);

        $deleted = $this->call(static fn () => self::product(7)->delete(true));
        $this->assertSame(ChangeAction::Delete, $this->rows($deleted)[0]->action);
        $result = $this->undoEvent($deleted);
        $this->assertSame('permanent_delete', $result->items[0]->reason);
        $this->assertArrayNotHasKey(7, $GLOBALS['wc_fake_db']['product']);
    }

    public function testStockIsRecordedForDisplayButNeverWrittenBack(): void
    {
        $this->seedPricedProduct();
        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->set_name('Mug v2');
            $p->set_stock_quantity(8);
            $p->save();
        });
        $row = $this->rows($event)[0];
        $this->assertSame(10, $row->before['stock_quantity']);
        $this->assertSame(8, $row->after['stock_quantity']);
        $this->assertContains('stock_quantity', $row->changedFields);

        $GLOBALS['wc_fake_db']['product'][7]['data']['stock_quantity'] = 5; // sales since: volatile, so not drift
        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('Mug', self::stored('product', 7)['name']);
        $this->assertSame(5, self::stored('product', 7)['stock_quantity'], 'live stock is never overwritten');
    }

    public function testAStockOnlyChangeIsRefusedNotRestoredAsANoOp(): void
    {
        $this->seedPricedProduct();
        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->set_stock_quantity(3);
            $p->save();
        });

        $result = $this->undoEvent($event);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame('volatile_only', $result->items[0]->reason);
        $this->assertSame(3, self::stored('product', 7)['stock_quantity']);
    }

    public function testMetaIsRecordedFromThePersistedStateAndRestoredIncludingAnAddedKey(): void
    {
        self::seed('product', 7, \WC_Product::class, ['name' => 'Mug'], ['origin' => 'DE']);
        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->update_meta_data('origin', 'FR');
            $p->update_meta_data('batch', 'b1');
            $p->save();
        });
        $row = $this->rows($event)[0];
        $this->assertSame(['origin' => 'DE'], $row->before['meta'], 'before is re-read from the store, not the edited in-memory meta');
        $this->assertSame(['batch' => 'b1', 'origin' => 'FR'], $row->after['meta']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame(['origin' => 'DE'], $GLOBALS['wc_fake_db']['product'][7]['meta']);
    }

    public function testASaleDateIsClearedThroughItsSetterWhenItWasNullBefore(): void
    {
        $this->seedPricedProduct();
        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->set_sale_price('8');
            $p->set_date_on_sale_to('2030-01-01T00:00:00Z');
            $p->save();
        });
        $this->assertSame('2030-01-01T00:00:00Z', $this->rows($event)[0]->after['date_on_sale_to']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertNull(self::stored('product', 7)['date_on_sale_to']);
        $this->assertSame('', self::stored('product', 7)['sale_price']);
    }

    public function testAVariationIsRecordedWithItsParentAndTheParentsDerivedSaveIsNot(): void
    {
        self::seed('product', 20, \WC_Product_Variable::class, ['name' => 'Tee', 'stock_status' => 'instock']);
        self::seed('product', 21, \WC_Product_Variation::class, ['name' => 'Tee S', 'parent_id' => 20, 'regular_price' => '10', 'price' => '10']);
        $adapter = $this->captureAdapter();

        $event = $this->call(static function () use ($adapter): void {
            $variation = self::product(21);
            $variation->set_regular_price('12');
            $adapter->onBeforeSave($variation);          // the variation save begins
            $parent = self::product(20);                  // the parent's re-sync happens inside it
            $parent->set_name('Tee (synced)');
            $parent->save();
            $variation->save();
            $adapter->onAfterSave($variation);
        });

        $rows = $this->rows($event);
        $this->assertCount(1, $rows);
        $this->assertSame('21', $rows[0]->objectId);
        $this->assertSame('variation', $rows[0]->objectSubtype);
        $this->assertSame(20, $rows[0]->before['parent_id']);
    }

    public function testCouponIsCapturedFromTheGenericDataHookAndUsageCountIsNeverWrittenBack(): void
    {
        self::seed('coupon', 3, \WC_Coupon::class, ['code' => 'SAVE5', 'amount' => '5', 'usage_count' => 1]);
        $event = $this->call(static function (): void {
            $c = new \WC_Coupon(3);
            $c->set_amount('20');
            $c->set_usage_count(2);
            $c->save();
        });

        $rows = $this->rows($event);
        $this->assertCount(1, $rows, 'the shop_coupon post is claimed off the core adapters');
        $this->assertSame('wc_coupon', $rows[0]->objectType);
        $this->assertSame('5', $rows[0]->before['amount']);
        $this->assertSame('20', $rows[0]->after['amount']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('5', self::stored('coupon', 3)['amount']);
        $this->assertSame(2, self::stored('coupon', 3)['usage_count']);
    }

    public function testACreatedCouponIsTrashed(): void
    {
        $event = $this->call(static function (): void {
            $c = new \WC_Coupon();
            $c->set_code('NEW');
            $c->save();
        });
        $row = $this->rows($event)[0];
        $this->assertSame(ChangeAction::Create, $row->action);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('trash', self::stored('coupon', (int) $row->objectId)['status']);
    }

    public function testCustomerFieldsRoundTripAndThePasswordNeverEntersTheHistory(): void
    {
        self::seed('customer', 12, \WC_Customer::class, ['email' => 'a@example.test', 'first_name' => 'Ann', 'billing' => ['first_name' => 'Ann', 'city' => 'Paris']]);
        $event = $this->call(static function (): void {
            $c = new \WC_Customer(12);
            $c->set_first_name('Bea');
            $c->set_billing_city('Rome');
            $c->set_password('s3cret-pass');
            $c->save();
        });

        $row = $this->rows($event)[0];
        $this->assertSame('wc_customer', $row->objectType);
        $this->assertSame('Ann', $row->before['first_name']);
        $this->assertSame('Rome', $row->after['billing']['city']);
        $this->assertStringNotContainsString('s3cret', (string) json_encode([$row->before, $row->after]));
        $this->assertTrue($row->restorable);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('Ann', self::stored('customer', 12)['first_name']);
        $this->assertSame('Paris', self::stored('customer', 12)['billing']['city']);
        $this->assertSame('s3cret-pass', $GLOBALS['wc_fake_passwords'][12], 'the password is left exactly as it is');
    }

    public function testACustomerChangeUnderAPiiRedactingPackIsRecordedButNotRestorable(): void
    {
        $GLOBALS['wpas_test_filters']['agent_safety_pack_registry'] = [];
        self::seed('customer', 12, \WC_Customer::class, ['email' => 'a@example.test', 'first_name' => 'Ann']);
        $event = $this->call(static function (): void {
            $c = new \WC_Customer(12);
            $c->set_first_name('Bea');
            $c->save();
        });

        $result = $this->undoEvent($event);

        $this->assertSame('redacted', $result->items[0]->reason);
        $this->assertSame('Bea', self::stored('customer', 12)['first_name']);
    }

    public function testACustomerRoleChangeAndACreatedCustomerAreRefused(): void
    {
        self::seed('customer', 12, \WC_Customer::class, ['email' => 'a@example.test']);
        $role = $this->call(static function (): void {
            $c = new \WC_Customer(12);
            $c->set_role('administrator');
            $c->save();
        });
        $this->assertSame('field_not_restorable', $this->undoEvent($role)->items[0]->reason);
        $this->assertSame('administrator', self::stored('customer', 12)['role']);

        $created = $this->call(static function (): void {
            $c = new \WC_Customer();
            $c->set_email('b@example.test');
            $c->save();
        });
        $this->assertSame('create_not_undoable', $this->undoEvent($created)->items[0]->reason);
    }

    public function testShippingZoneRoundTripsItsNameAndLocations(): void
    {
        self::seed('zone', 5, \WC_Shipping_Zone::class, ['zone_name' => 'EU', 'zone_locations' => [(object) ['code' => 'DE', 'type' => 'country']]]);
        $event = $this->call(static function (): void {
            $z = new \WC_Shipping_Zone(5);
            $z->set_zone_name('Europe');
            $z->set_zone_locations([(object) ['code' => 'FR', 'type' => 'country']]);
            $z->save();
        });
        $row = $this->rows($event)[0];
        $this->assertSame('wc_shipping_zone', $row->objectType);
        $this->assertSame([['code' => 'DE', 'type' => 'country']], $row->before['zone_locations']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('EU', self::stored('zone', 5)['zone_name']);
        $this->assertEquals([(object) ['code' => 'DE', 'type' => 'country']], self::stored('zone', 5)['zone_locations']);
    }

    public function testOrderEventsAreRecordedIrreversibleWithAReasonAndNeverRestored(): void
    {
        $event = $this->call(static function (): void {
            wpas_test_fire('woocommerce_new_order', 9, new \WC_Abstract_Order('pending'));
            wpas_test_fire('woocommerce_order_status_changed', 9, 'pending', 'completed', new \WC_Abstract_Order('completed'));
            wpas_test_fire('woocommerce_order_refunded', 9, 31);
            wpas_test_fire('woocommerce_new_customer_note', ['order_id' => 9, 'customer_note' => 'Shipped']);
            wpas_test_fire('woocommerce_shipping_zone_method_added', 4, 'flat_rate', 5);
        });

        $rows = $this->rows($event);
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame(ChangeAction::Irreversible, $row->action);
            $this->assertFalse($row->restorable);
            $this->assertNotEmpty($row->after['reason'] ?? $row->before['reason'] ?? '');
        }
        $status = $rows[1];
        $this->assertSame('status', $status->objectSubtype);
        $this->assertSame('pending', $status->before['status']);
        $this->assertSame('completed', $status->after['status']);
        $this->assertSame(['status'], $status->changedFields);
        $this->assertSame('4.00', $rows[2]->after['amount']);

        $result = $this->undoEvent($event);
        $this->assertSame(UndoResult::REFUSED, $result->status);
        foreach ($result->items as $item) {
            $this->assertSame(UndoItem::SKIPPED, $item->status);
            $this->assertSame('irreversible', $item->reason);
        }
    }

    public function testAnOrderDeleteIsRecordedIrreversibleAndTheFilterValueIsReturnedUntouched(): void
    {
        $order = new \WC_Abstract_Order('processing', 9);
        $returned = 'unset';
        $event = $this->call(static function () use ($order, &$returned): void {
            $returned = apply_filters('woocommerce_pre_delete_order', null, $order, true);
        });

        $this->assertNull($returned);
        $rows = $this->rows($event);
        $this->assertCount(1, $rows);
        $this->assertSame(ChangeAction::Irreversible, $rows[0]->action);
        $this->assertSame('deleted', $rows[0]->objectSubtype);
        $this->assertSame('processing', $rows[0]->before['status']);
    }

    public function testACoreWordPressPostIsStillRecordedByTheCoreAdapter(): void
    {
        $GLOBALS['wpas_test_posts'][50] = (object) ['ID' => 50, 'post_title' => 'A', 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''];

        $event = $this->call(static fn () => wp_update_post(wp_slash(['ID' => 50, 'post_title' => 'B'])));

        $this->assertSame(['post'], array_map(static fn (StoredChange $c): string => $c->objectType, $this->rows($event)));
    }

    public function testTheTaxRateSnapshotterRecordsTheRowDiffAndUndoRestoresIt(): void
    {
        $GLOBALS['wc_fake_tax'][9] = ['tax_rate_id' => 9, 'tax_rate_country' => 'DE', 'tax_rate_state' => '', 'tax_rate' => '5.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => '1', 'tax_rate_compound' => '0', 'tax_rate_shipping' => '1', 'tax_rate_order' => '0', 'tax_rate_class' => ''];
        $GLOBALS['wc_fake_tax_loc'][9] = ['postcode' => '10115;10117', 'city' => ''];

        $event = $this->call(static function (): void {
            \WC_Tax::_update_tax_rate(9, ['tax_rate' => '8.0000', 'tax_rate_name' => 'VAT+']);
            \WC_Tax::_update_tax_rate_postcodes(9, '10115');
        }, 'woocommerce/taxes-update', ['id' => 9]);

        $rows = $this->rows($event);
        $this->assertCount(1, $rows);
        $this->assertSame('wc_tax_rate', $rows[0]->objectType);
        $this->assertSame('5.0000', $rows[0]->before['tax_rate']);
        $this->assertSame('8.0000', $rows[0]->after['tax_rate']);
        $this->assertEqualsCanonicalizing(['tax_rate', 'tax_rate_name', 'postcode'], $rows[0]->changedFields);
        $this->assertSame('10115;10117', $rows[0]->before['postcode']);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertSame('5.0000', $GLOBALS['wc_fake_tax'][9]['tax_rate']);
        $this->assertSame('VAT', $GLOBALS['wc_fake_tax'][9]['tax_rate_name']);
        $this->assertSame('10115;10117', $GLOBALS['wc_fake_tax_loc'][9]['postcode']);
    }

    public function testAnUnchangedTaxRateRecordsNothingAndACreatedOneIsSeenThroughItsAddedHook(): void
    {
        $GLOBALS['wc_fake_tax'][9] = ['tax_rate_id' => 9, 'tax_rate' => '5.0000'];
        $unchanged = $this->call(static function (): void {
        }, 'woocommerce/taxes-update', ['id' => 9]);
        $this->assertSame([], $this->rows($unchanged));

        $created = 0;
        $event = $this->call(static function () use (&$created): void {
            $created = \WC_Tax::_insert_tax_rate(['tax_rate' => '7.0000']);
        }, 'woocommerce/taxes-create', []);
        $rows = $this->rows($event);
        $this->assertCount(1, $rows);
        $this->assertSame(ChangeAction::Create, $rows[0]->action);
        $this->assertSame((string) $created, $rows[0]->objectId);

        $this->assertSame(UndoResult::COMPLETE, $this->undoEvent($event)->status);
        $this->assertArrayNotHasKey($created, $GLOBALS['wc_fake_tax']);
    }

    public function testAnUndoNeedsManageWoocommerceNotManageOptions(): void
    {
        $this->seedPricedProduct();
        $event = $this->call(static function (): void {
            $p = self::product(7);
            $p->set_regular_price('15');
            $p->save();
        });
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true]];

        $this->assertSame(UndoResult::FORBIDDEN, $this->undoEvent($event)->status);
        $this->assertSame('15', self::stored('product', 7)['regular_price']);
    }

    public function testTheAdaptersAreOnlyPresentOnceTheWooIntegrationRegistersThem(): void
    {
        $GLOBALS['wpas_test_filters'] = [];
        $this->assertNull((new ChangeAdapters())->restoreFor('wc_product'));

        WooChangeAdapters::register();
        $adapters = new ChangeAdapters();
        $this->assertNotNull($adapters->restoreFor('wc_product'));
        $this->assertNotNull($adapters->restoreFor('post'), 'the built-ins stay');
        $this->assertCount(1, $adapters->snapshotters());
    }

    private function captureAdapter(): WooCaptureAdapter
    {
        foreach ($this->capture->adapters()->capture() as $adapter) {
            if ($adapter instanceof WooCaptureAdapter) {
                return $adapter;
            }
        }
        $this->fail('Woo capture adapter not registered');
    }
}
