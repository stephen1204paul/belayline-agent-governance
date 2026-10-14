<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Changes;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Belayline\Changes\CaptureWindow;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\ChangeSet;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Changes\WpdbChangeStore;
use Belayline\Plugin\Hooks\AbilityAuditLog;
use Belayline\Plugin\Hooks\AbilityPermissionGate;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\AdapterBundle;
use Belayline\Plugin\Tests\Fakes\InMemoryAuditSink;
use Belayline\Plugin\Verdict\VerdictPipeline;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;
use WP_Error;
use wpdb;

/**
 * The capture window end to end: which calls open one, that it closes on every
 * outcome (the after-execute hook never fires on failure), nesting, the stored
 * rows, and the `changes_sha256` the audit record carries.
 */
final class ChangeCaptureTest extends TestCase
{
    private wpdb $db;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_meta'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
        $this->db = new class () extends wpdb {
            /** @var list<array<string, mixed>> */
            public array $rows = [];

            public function insert(string $table, array $data, ?array $format = null): int
            {
                $this->rows[] = $data;

                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        ChangeCapture::configure(null);
    }

    private function classifier(): TierClassifier
    {
        $catalog = new VerbCatalog();
        $catalog->register(['t/read' => Tier::Reversible, 't/write' => Tier::SideEffecting, 't/nuke' => Tier::Irreversible]);

        return new TierClassifier($catalog);
    }

    private function capture(): ChangeCapture
    {
        return new ChangeCapture($this->classifier(), new PackResolver(), new WpdbChangeStore($this->db));
    }

    /** @return array<string, mixed> */
    private static function post(string $title): array
    {
        return ['ID' => 7, 'post_title' => $title, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''];
    }

    private function listenersOf(ChangeCapture $capture): AdapterBundle
    {
        return new AdapterBundle($capture->adapters()->capture());
    }

    private function windowOf(ChangeCapture $capture): CaptureWindow
    {
        /** @var CaptureWindow $window */
        $window = (new ReflectionProperty($capture, 'window'))->getValue($capture);

        return $window;
    }

    /** @return int count of listeners still registered for a hook */
    private static function registered(string $hook): int
    {
        return count($GLOBALS['wpas_test_added_actions'][$hook] ?? []);
    }

    private function gate(?ChangeCapture $capture): AbilityPermissionGate
    {
        return new AbilityPermissionGate(
            (new \ReflectionClass(VerdictPipeline::class))->newInstanceWithoutConstructor(),
            new PackResolver(),
            null,
            ['t/'],
            null,
            $capture,
        );
    }

    public function testATierOneCallOpensAWindowAndStoresWhatTheListenersSaw(): void
    {
        $capture = $this->capture();
        $listeners = $this->listenersOf($capture);

        $result = $capture->run('t/write', [], function () use ($listeners): string {
            $listeners->onPostUpdated(7, (object) self::post('B'), (object) self::post('A'));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertCount(1, $this->db->rows);
        $row = $this->db->rows[0];
        $this->assertSame('post', $row['object_type']);
        $this->assertSame('7', $row['object_id']);
        $this->assertSame('update', $row['action']);
        $this->assertSame(1, $row['seq']);
        $this->assertSame('["post_title"]', $row['changed_fields']);

        $settled = $capture->takeSettled('t/write');
        $this->assertNotNull($settled);
        $this->assertSame($row['event_id'], $settled['event_id']);
        $this->assertNull($capture->takeSettled('t/write'), 'handed over once');
    }

    public function testATierZeroCallOpensNoWindowAndListenersStayDetached(): void
    {
        $capture = $this->capture();

        $capture->run('t/read', [], function (): void {
            $this->assertSame(0, self::registered('post_updated'));
        });

        $this->assertSame([], $this->db->rows);
        $this->assertNull($capture->takeSettled('t/read'));
    }

    public function testAnUnknownVerbOpensNoWindow(): void
    {
        $capture = $this->capture();
        $capture->run('t/mystery', [], static fn () => null);

        $this->assertNull($capture->takeSettled('t/mystery'));
    }

    public function testListenersAreAttachedOnlyWhileTheWindowIsOpen(): void
    {
        $capture = $this->capture();

        $capture->run('t/write', [], function (): void {
            $this->assertSame(1, self::registered('post_updated'));
            $this->assertNotEmpty($GLOBALS['wpas_test_filters']['update_post_metadata'][9999]);
        });

        $this->assertSame(0, self::registered('post_updated'));
        $this->assertEmpty($GLOBALS['wpas_test_filters']['update_post_metadata'][9999]);
    }

    public function testAThrowingCallClosesTheWindowAndStillStoresWhatWasWritten(): void
    {
        $capture = $this->capture();
        $listeners = $this->listenersOf($capture);

        try {
            $capture->run('t/write', [], function () use ($listeners): void {
                $listeners->onPostUpdated(7, (object) self::post('B'), (object) self::post('A'));

                throw new RuntimeException('boom');
            });
            $this->fail('the exception must propagate unchanged');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, self::registered('post_updated'), 'listeners detached after a failure');
        $this->assertFalse($this->windowOf($capture)->isOpen());
        $this->assertCount(1, $this->db->rows, 'the write that happened before the failure is still recorded');

        // A later write outside any call is not captured: the window really closed.
        $listeners->onPostUpdated(8, (object) self::post('Y'), (object) self::post('X'));
        $this->assertCount(1, $this->db->rows);
        $this->assertNotNull($capture->takeSettled('t/write'));
    }

    public function testAWpErrorReturnClosesTheWindowToo(): void
    {
        $capture = $this->capture();

        $result = $capture->run('t/write', [], static fn () => new WP_Error('x', 'failed'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertFalse($this->windowOf($capture)->isOpen());
        $this->assertSame(0, self::registered('post_updated'));
        $this->assertNotNull($capture->takeSettled('t/write'));
    }

    public function testANestedGovernedCallRecordsUnderTheOuterEventId(): void
    {
        $capture = $this->capture();
        $listeners = $this->listenersOf($capture);

        $capture->run('t/write', [], function () use ($capture, $listeners): void {
            $listeners->onPostUpdated(1, (object) self::post('B'), (object) self::post('A'));
            $capture->run('t/nuke', [], function () use ($listeners): void {
                $listeners->onPostUpdated(2, (object) self::post('D'), (object) self::post('C'));
            });
            $this->assertSame(1, self::registered('post_updated'), 'the inner return must not close the outer window');
            $listeners->onPostUpdated(3, (object) self::post('F'), (object) self::post('E'));
        });

        $this->assertCount(3, $this->db->rows);
        $this->assertCount(1, array_unique(array_column($this->db->rows, 'event_id')));
        $this->assertSame([1, 2, 3], array_column($this->db->rows, 'seq'));
        $this->assertNotNull($capture->takeSettled('t/write'));
        $this->assertNull($capture->takeSettled('t/nuke'), 'the inner call has no window of its own');
    }

    public function testAWindowThatSawNothingStoresZeroRowsButStillSettlesAHash(): void
    {
        $capture = $this->capture();
        $capture->run('t/write', [], static fn () => null);

        $this->assertSame([], $this->db->rows);
        $settled = $capture->takeSettled('t/write');
        $this->assertSame(hash('sha256', '[]'), $settled['changes_sha256'] ?? null);
    }

    public function testCloseAllSettlesAWindowLeftOpenByAFatal(): void
    {
        $capture = $this->capture();
        $window = $this->windowOf($capture);
        $window->open('evt-left-open', 'corr', false);
        (new ReflectionProperty($capture, 'outer'))->setValue($capture, 't/write');
        foreach ($capture->adapters()->capture() as $adapter) {
            $adapter->attach($window);
        }

        $capture->closeAll();

        $this->assertFalse($window->isOpen());
        $this->assertSame('evt-left-open', $capture->takeSettled('t/write')['event_id'] ?? null);
        $this->assertSame(0, self::registered('post_updated'));
    }

    public function testTheAuditRecordUsesTheWindowsEventIdAndCarriesAMatchingChangesHash(): void
    {
        $capture = $this->capture();
        ChangeCapture::configure($capture);
        $listeners = $this->listenersOf($capture);
        $sink = new InMemoryAuditSink();
        $log = new AbilityAuditLog($sink, $this->classifier(), new PackResolver(), ['t/'], $capture);

        $log->before('t/write', []);
        $capture->run('t/write', [], function () use ($listeners): void {
            $listeners->onPostUpdated(7, (object) self::post('B'), (object) self::post('A'));
        });
        $log->after('t/write', [], ['ok' => true]);

        $record = $sink->records[0]->toArray();
        $this->assertSame($this->db->rows[0]['event_id'], $record['id'], 'audit row joins its change rows by event id');

        $expected = ChangeSet::hash([Change::capture('post', '7', 'post', ChangeAction::Update, self::post('A'), self::post('B'))]);
        $this->assertSame($expected, $record['changes_sha256']);
    }

    public function testAnAuditRecordForACallWithoutAWindowHasNoChangesHash(): void
    {
        $capture = $this->capture();
        $sink = new InMemoryAuditSink();
        $log = new AbilityAuditLog($sink, $this->classifier(), new PackResolver(), ['t/'], $capture);

        $log->before('t/read', []);
        $log->after('t/read', [], ['ok' => true]);

        $this->assertArrayNotHasKey('changes_sha256', $sink->records[0]->toArray());
    }

    public function testTheGateWrapsExecuteCallbackSoAFailureClosesTheWindow(): void
    {
        $capture = $this->capture();
        $listeners = $this->listenersOf($capture);

        $args = $this->gate($capture)->wrap([
            'permission_callback' => static fn () => true,
            'execute_callback' => static function ($input = null) use ($listeners): void {
                $listeners->onPostUpdated(7, (object) self::post('B'), (object) self::post('A'));

                throw new RuntimeException('ability failed');
            },
        ], 't/write');

        try {
            $args['execute_callback']([]);
            $this->fail('expected the ability exception');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, self::registered('post_updated'));
        $this->assertFalse($this->windowOf($capture)->isOpen());
        $this->assertCount(1, $this->db->rows);
    }

    public function testTheWrappedCallbackKeepsCoresNoArgumentCallingConvention(): void
    {
        $seen = null;
        $args = $this->gate($this->capture())->wrap([
            'execute_callback' => static function (...$callArgs) use (&$seen): string {
                $seen = $callArgs;

                return 'ok';
            },
        ], 't/write');

        $this->assertSame('ok', $args['execute_callback']());
        $this->assertSame([], $seen, 'no input schema => core passes nothing, and so must the wrapper');
    }

    public function testTheGateLeavesAnUngovernedAbilitysExecuteCallbackAlone(): void
    {
        $execute = static fn () => 1;

        $args = $this->gate($this->capture())->wrap(['execute_callback' => $execute], 'other/thing');

        $this->assertSame($execute, $args['execute_callback']);
    }

    private function settleWindow(ChangeCapture $capture, string $ability): void
    {
        $catalog = new VerbCatalog();
        $catalog->register([$ability => Tier::SideEffecting]);
        $c = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), new WpdbChangeStore($this->db));
        $c->run($ability, [], static fn () => null);
        $settled = (new ReflectionProperty($c, 'settled'))->getValue($c);
        $existing = (new ReflectionProperty($capture, 'settled'))->getValue($capture);
        (new ReflectionProperty($capture, 'settled'))->setValue($capture, $existing + $settled);
    }

    public function testTheMcpToolNameFormAttachesTheSameEventId(): void
    {
        $capture = $this->capture();
        $this->settleWindow($capture, 'my-plugin/update-thing');
        $eventId = (new ReflectionProperty($capture, 'settled'))->getValue($capture)['my-plugin/update-thing'][0]['event_id'];

        $settled = $capture->takeSettled('my-plugin-update-thing');

        $this->assertSame($eventId, $settled['event_id'] ?? null);
        $this->assertNull($capture->takeSettled('my-plugin-update-thing'), 'consumed once');
    }

    public function testCollidingToolNamesAttachNothing(): void
    {
        $capture = $this->capture();
        $this->settleWindow($capture, 'a-b/c');
        $this->settleWindow($capture, 'a/b-c');

        $this->assertNull($capture->takeSettled('a-b-c'), 'two abilities map to one tool name: never guess');
        $this->assertNotNull($capture->takeSettled('a-b/c'), 'the exact id still resolves');
    }

    public function testExactIdIsPreferredOverAToolNameMatch(): void
    {
        $capture = $this->capture();
        $this->settleWindow($capture, 'x/y');
        $this->settleWindow($capture, 'x-y');
        $ids = (new ReflectionProperty($capture, 'settled'))->getValue($capture);

        $settled = $capture->takeSettled('x-y');

        $this->assertSame($ids['x-y'][0]['event_id'], $settled['event_id'] ?? null);
    }

    public function testAToolNameForADifferentAbilityAttachesNothing(): void
    {
        $capture = $this->capture();
        $this->settleWindow($capture, 'x/y');

        $this->assertNull($capture->takeSettled('x-z'));
        $this->assertNotNull($capture->takeSettled('x/y'));
    }
}
