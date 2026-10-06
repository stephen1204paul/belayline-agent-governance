<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\ClosedWindow;
use Specflux\AgentSafety\Plugin\Changes\Adapters\OptionAdapter;
use Specflux\AgentSafety\Plugin\Changes\Adapters\PostAdapter;
use Specflux\AgentSafety\Plugin\Changes\Adapters\PostMetaAdapter;
use Specflux\AgentSafety\Plugin\Changes\Adapters\TermAdapter;
use Specflux\AgentSafety\Plugin\Tests\Fakes\AdapterBundle;

/** One test per listener, each driven the way WordPress would call it. */
final class WpChangeListenersTest extends TestCase
{
    private CaptureWindow $window;
    private AdapterBundle $listeners;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_terms'] = [];
        $GLOBALS['wpas_test_meta'] = [];
        $this->window = new CaptureWindow();
        $adapters = [new PostAdapter(), new PostMetaAdapter(), new TermAdapter(), new OptionAdapter()];
        foreach ($adapters as $adapter) {
            $adapter->attach($this->window);
        }
        $this->listeners = new AdapterBundle($adapters);
        $this->window->open('evt-1', 'corr-1', false);
    }

    private function closed(): ClosedWindow
    {
        $closed = $this->window->close();
        $this->assertNotNull($closed);

        return $closed;
    }

    /** @return array<string, mixed> */
    private static function post(string $title, string $status = 'publish', string $type = 'post'): array
    {
        return ['ID' => 7, 'post_title' => $title, 'post_type' => $type, 'post_status' => $status, 'post_password' => ''];
    }

    public function testPostUpdatedRecordsBeforeAndAfter(): void
    {
        $this->listeners->onPostUpdated(7, (object) self::post('B'), (object) self::post('A'));

        $change = $this->closed()->changes[0];
        $this->assertSame('update', $change->action->value);
        $this->assertSame(['post_title'], $change->changedFields);
        $this->assertStringContainsString('"post_title":"A"', (string) $change->beforeJson);
        $this->assertStringContainsString('"post_title":"B"', (string) $change->afterJson);
        $this->assertTrue($change->restorable);
    }

    public function testNavigationPostsAreCoveredByThePostListeners(): void
    {
        $this->listeners->onPostUpdated(7, (object) self::post('B', 'publish', 'wp_navigation'), (object) self::post('A', 'publish', 'wp_navigation'));

        $this->assertSame('wp_navigation', $this->closed()->changes[0]->objectSubtype);
    }

    public function testTheFirstSaveOfAnAutoDraftIsACreation(): void
    {
        $this->listeners->onPostUpdated(7, (object) self::post('T', 'draft'), (object) self::post('', 'auto-draft'));

        $change = $this->closed()->changes[0];
        $this->assertSame('create', $change->action->value);
        $this->assertNull($change->beforeJson);
    }

    public function testInsertPostRecordsACreationAndIgnoresUpdatesRevisionsAndAutoDrafts(): void
    {
        $this->listeners->onInsertPost(7, (object) self::post('New'), false);
        $this->listeners->onInsertPost(8, (object) self::post('Upd'), true);
        $this->listeners->onInsertPost(9, (object) self::post('Rev', 'inherit', 'revision'), false);
        $this->listeners->onInsertPost(10, (object) self::post('', 'auto-draft'), false);

        $changes = $this->closed()->changes;
        $this->assertCount(1, $changes);
        $this->assertSame('create', $changes[0]->action->value);
        $this->assertSame('7', $changes[0]->objectId);
    }

    public function testTrashPostRecordsOnceEvenWhenPostUpdatedFollows(): void
    {
        $GLOBALS['wpas_test_posts'][7] = (object) self::post('A');

        $this->listeners->onTrashPost(7, 'publish');
        $this->listeners->onPostUpdated(7, (object) self::post('A', 'trash'), (object) self::post('A'));

        $changes = $this->closed()->changes;
        $this->assertCount(1, $changes);
        $this->assertSame('trash', $changes[0]->action->value);
        $this->assertSame(['post_status'], $changes[0]->changedFields);
        $this->assertTrue($changes[0]->restorable);
    }

    public function testATrashViaPostUpdatedAloneIsStillRecordedAsATrash(): void
    {
        $this->listeners->onPostUpdated(7, (object) self::post('A', 'trash'), (object) self::post('A'));

        $this->assertSame('trash', $this->closed()->changes[0]->action->value);
    }

    public function testBeforeDeletePostRecordsTheFullPost(): void
    {
        $this->listeners->onBeforeDeletePost(7, (object) self::post('Gone'));

        $change = $this->closed()->changes[0];
        $this->assertSame('delete', $change->action->value);
        $this->assertNull($change->afterJson);
        $this->assertStringContainsString('Gone', (string) $change->beforeJson);
    }

    public function testPostMetaUpdateReadsTheOldValueAndReturnsTheCheckUntouched(): void
    {
        $GLOBALS['wpas_test_meta'][7]['color'] = 'red';

        $returned = $this->listeners->onUpdatePostMeta(null, 7, 'color', 'blue');

        $this->assertNull($returned, 'the write must proceed');
        $change = $this->closed()->changes[0];
        $this->assertSame('post_meta', $change->objectType);
        $this->assertSame('color', $change->objectSubtype);
        $this->assertSame('{"color":"red"}', $change->beforeJson);
        $this->assertSame('{"color":"blue"}', $change->afterJson);
    }

    public function testPostMetaForANewKeyIsACreationAndAShortCircuitedWriteIsNotRecorded(): void
    {
        $this->listeners->onUpdatePostMeta(null, 7, 'fresh', 'v');
        $this->assertTrue($this->listeners->onUpdatePostMeta(true, 7, 'other', 'v'));
        $this->listeners->onUpdatePostMeta(null, 7, '_edit_lock', '1:2');

        $changes = $this->closed()->changes;
        $this->assertCount(1, $changes);
        $this->assertSame('create', $changes[0]->action->value);
    }

    public function testASecretMetaKeyIsHashedAndNotRestorable(): void
    {
        $GLOBALS['wpas_test_meta'][7]['stripe_api_key'] = 'sk_old';

        $this->listeners->onUpdatePostMeta(null, 7, 'stripe_api_key', 'sk_new');

        $change = $this->closed()->changes[0];
        $this->assertStringNotContainsString('sk_new', (string) $change->afterJson);
        $this->assertStringNotContainsString('sk_old', (string) $change->beforeJson);
        $this->assertFalse($change->restorable);
    }

    public function testTermEditRecordsTheSnapshotTakenBeforeTheUpdate(): void
    {
        $GLOBALS['wpas_test_terms'][3] = (object) ['term_id' => 3, 'name' => 'Old', 'taxonomy' => 'category'];
        $this->listeners->onEditTerms(3, 'category');
        $GLOBALS['wpas_test_terms'][3] = (object) ['term_id' => 3, 'name' => 'New', 'taxonomy' => 'category'];
        $this->listeners->onEditedTerms(3, 'category');

        $change = $this->closed()->changes[0];
        $this->assertSame('term', $change->objectType);
        $this->assertSame(['name'], $change->changedFields);
        $this->assertStringContainsString('Old', (string) $change->beforeJson);
        $this->assertStringContainsString('New', (string) $change->afterJson);
    }

    public function testCreatedTermIsACreation(): void
    {
        $GLOBALS['wpas_test_terms'][4] = (object) ['term_id' => 4, 'name' => 'Fresh', 'taxonomy' => 'post_tag'];

        $this->listeners->onCreatedTerm(4, 4, 'post_tag');

        $change = $this->closed()->changes[0];
        $this->assertSame('create', $change->action->value);
        $this->assertSame('post_tag', $change->objectSubtype);
    }

    public function testUpdateOptionRecordsOldAndNewAndSkipsNoiseAndOwnState(): void
    {
        $this->listeners->onUpdateOption('blogname', 'Old', 'New');
        $this->listeners->onUpdateOption('_transient_foo', 'a', 'b');
        $this->listeners->onUpdateOption('agsafe_pause', 'a', 'b');
        $this->listeners->onUpdateOption('cron', 'a', 'b');

        $changes = $this->closed()->changes;
        $this->assertCount(1, $changes);
        $this->assertSame('option', $changes[0]->objectType);
        $this->assertSame('blogname', $changes[0]->objectId);
        $this->assertSame('{"blogname":"Old"}', $changes[0]->beforeJson);
    }

    public function testNothingIsRecordedOnceTheWindowIsClosed(): void
    {
        $this->window->close();

        $this->listeners->onUpdateOption('blogname', 'Old', 'New');
        $this->listeners->onPostUpdated(7, (object) self::post('B'), (object) self::post('A'));
        $this->window->open('evt-2', 'corr', false);

        $this->assertSame([], $this->closed()->changes);
    }

    public function testAListenerFailureBecomesAMarkerNeverAnException(): void
    {
        $GLOBALS['wpas_test_posts'][7] = new class () {
            /** @return array<string, mixed> */
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- mirrors WP_Post::to_array().
            public function to_array(): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->listeners->onTrashPost(7);

        $change = $this->closed()->changes[0];
        $this->assertSame('capture_error', $change->objectId);
        $this->assertFalse($change->restorable);
    }

    public function testRedactionFollowsTheWindowsPackPolicy(): void
    {
        $this->window->close();
        $this->window->open('evt-2', 'corr', true);

        $this->listeners->onUpdateOption('admin_email', 'a@x.com', 'b@x.com');

        $change = $this->closed()->changes[0];
        $this->assertStringNotContainsString('@x.com', (string) $change->afterJson);
        $this->assertFalse($change->restorable);
    }
}
