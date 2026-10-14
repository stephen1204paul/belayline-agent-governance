<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Privacy;

use InMemoryAuditRowsWpdb;
use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Privacy\PersonalDataEraser;
use Belayline\Plugin\Privacy\PrivacyAuditReader;
use Belayline\Plugin\Privacy\PrivacyChangeReader;

/**
 * §3.6 item 2 / §4 row 9: the eraser must never touch the append-only audit
 * table — a matching user's rows are reported `items_retained`, and the hash
 * chain {@see AuditReader::verifyChain()} must still verify afterwards
 * (nothing was rewritten or dropped to "erase" anything). The change history
 * (0.5) sits outside the chain and IS erased for the matching user only.
 */
final class PersonalDataEraserTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_users_by_email'] = [];
    }

    public function testRetainsMatchingRowsWithoutMutatingTheChain(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
            ['wp_user' => 6, 'ability' => 'core/read-post', 'input' => []],
            ['wp_user' => 5, 'ability' => 'core/update-post', 'input' => []],
        ]));
        $GLOBALS['wpas_test_users_by_email']['alice@example.com'] = 5;

        $this->assertTrue((new AuditReader($db))->verifyChain(), 'chain must be valid before the eraser runs');

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db));
        $result = $eraser->erase('alice@example.com');

        $this->assertFalse($result['items_removed']);
        $this->assertTrue($result['items_retained']);
        $this->assertNotEmpty($result['messages']);
        $this->assertTrue($result['done']);

        $this->assertTrue(
            (new AuditReader($db))->verifyChain(),
            'chain must still verify after the eraser runs — nothing may be rewritten or dropped'
        );
    }

    public function testUnknownEmailRetainsNothing(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
        ]));

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db));
        $result = $eraser->erase('nobody@example.com');

        $this->assertSame(
            ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true],
            $result
        );
    }

    public function testPagesAcrossABatchBoundary(): void
    {
        $specs = [];
        for ($i = 0; $i < 205; $i++) {
            $specs[] = ['wp_user' => 9, 'ability' => 'core/read-post', 'input' => []];
        }

        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain($specs));
        $GLOBALS['wpas_test_users_by_email']['bob@example.com'] = 9;

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db));

        $page1 = $eraser->erase('bob@example.com', 1);
        $this->assertFalse($page1['done']);
        $this->assertTrue($page1['items_retained']);

        $page2 = $eraser->erase('bob@example.com', 2);
        $this->assertTrue($page2['done']);
        $this->assertTrue($page2['items_retained']);
    }

    public function testErasesTheUsersChangeRowsAndTheChainStillVerifies(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain([
            ['wp_user' => 5, 'ability' => 'core/update-post', 'input' => []], // evt-1
            ['wp_user' => 6, 'ability' => 'core/update-post', 'input' => []], // evt-2
            ['wp_user' => 5, 'ability' => 'core/update-post', 'input' => []], // evt-3
        ]));
        $db->seedChanges([
            self::changeRow(1, 'evt-1'),
            self::changeRow(2, 'evt-1'),
            self::changeRow(3, 'evt-2'),
            self::changeRow(4, 'evt-3'),
        ]);
        $GLOBALS['wpas_test_users_by_email']['alice@example.com'] = 5;

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db), new PrivacyChangeReader($db));
        $result = $eraser->erase('alice@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['items_retained'], 'the audit rows themselves are still retained');
        $this->assertSame([3], array_column($db->changeRows(), 'id'), "only user 6's change row survives");
        $this->assertTrue(
            (new AuditReader($db))->verifyChain(),
            'deleting change bodies must not break the audit hash chain'
        );
    }

    public function testAUserWithNoChangeRowsRemovesNothing(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
        ]));
        $db->seedChanges([self::changeRow(1, 'evt-other')]);
        $GLOBALS['wpas_test_users_by_email']['alice@example.com'] = 5;

        $result = (new PersonalDataEraser(new PrivacyAuditReader($db), new PrivacyChangeReader($db)))->erase('alice@example.com');

        $this->assertFalse($result['items_removed']);
        $this->assertCount(1, $db->changeRows());
    }

    /** @return array<string, mixed> */
    private static function changeRow(int $id, string $eventId): array
    {
        return [
            'id' => $id,
            'event_id' => $eventId,
            'ts' => '2026-09-01 00:00:00',
            'object_type' => 'post',
            'object_id' => (string) $id,
            'action' => 'update',
            'before_json' => '{"post_title":"a"}',
            'after_json' => '{"post_title":"b"}',
        ];
    }
}
