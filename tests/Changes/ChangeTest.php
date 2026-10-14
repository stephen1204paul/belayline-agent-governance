<?php

declare(strict_types=1);

namespace Belayline\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;

final class ChangeTest extends TestCase
{
    public function testAnUpdateRecordsOnlyTheFieldsThatDiffer(): void
    {
        $change = Change::capture('post', '7', 'page', ChangeAction::Update, ['post_title' => 'A', 'menu_order' => 1], ['post_title' => 'B', 'menu_order' => '1']);

        $this->assertSame(['post_title'], $change->changedFields, "1 and '1' are not a change");
        $this->assertTrue($change->restorable);
        $this->assertNull($change->reason);
    }

    public function testACreationListsEveryFieldAsChangedAndHasNoBefore(): void
    {
        $change = Change::capture('post', '7', 'post', ChangeAction::Create, null, ['post_title' => 'A', 'post_status' => 'draft']);

        $this->assertNull($change->beforeJson);
        $this->assertSame(['post_title', 'post_status'], $change->changedFields);
    }

    public function testASecretIsHashedNeverStoredAndTheChangeIsNotRestorable(): void
    {
        $change = Change::capture('post', '7', 'post', ChangeAction::Update, ['post_password' => ''], ['post_password' => 'hunter2']);

        $this->assertStringNotContainsString('hunter2', (string) $change->afterJson);
        $this->assertStringContainsString('sha256:' . hash('sha256', '"hunter2"'), (string) $change->afterJson);
        $this->assertSame(['post_password'], $change->changedFields, 'a hashed secret still shows as changed');
        $this->assertFalse($change->restorable);
        $this->assertSame(Change::REASON_SECRET, $change->reason);
    }

    public function testAnEmptySecretFieldDoesNotMakeTheChangeUnrestorable(): void
    {
        $change = Change::capture('post', '7', 'post', ChangeAction::Update, ['post_password' => '', 'post_title' => 'A'], ['post_password' => '', 'post_title' => 'B']);

        $this->assertTrue($change->restorable);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretKeys(): array
    {
        return [
            'api key meta' => ['stripe_api_key'],
            'key suffix' => ['mailer_key'],
            'secret' => ['client_secret'],
            'token' => ['access_token'],
            'woo consumer key' => ['consumer_key'],
            'app password hash' => ['_application_passwords_hash'],
        ];
    }

    /** @dataProvider secretKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('secretKeys')]
    public function testSecretLookingKeysAreHashed(string $key): void
    {
        $change = Change::capture('option', $key, null, ChangeAction::Update, [$key => 'old-value'], [$key => 'new-value']);

        $this->assertStringNotContainsString('new-value', (string) $change->afterJson);
        $this->assertStringNotContainsString('old-value', (string) $change->beforeJson);
        $this->assertFalse($change->restorable);
    }

    public function testPiiIsMaskedWhenThePackRedactsAndTheChangeIsNotRestorable(): void
    {
        $change = Change::capture('post_meta', '7', 'billing', ChangeAction::Update, ['billing_email' => 'a@x.com'], ['billing_email' => 'b@x.com'], true);

        $this->assertStringNotContainsString('@x.com', (string) $change->afterJson);
        $this->assertSame(Change::REASON_REDACTED, $change->reason);
        $this->assertFalse($change->restorable);
    }

    public function testPiiIsLeftAloneWhenThePackDoesNotRedact(): void
    {
        $change = Change::capture('post_meta', '7', 'billing', ChangeAction::Update, ['billing_email' => 'a@x.com'], ['billing_email' => 'b@x.com'], false);

        $this->assertStringContainsString('b@x.com', (string) $change->afterJson);
        $this->assertTrue($change->restorable);
    }

    public function testAnOversizedBodyIsReplacedByAMarkerAndIsNotRestorable(): void
    {
        $big = str_repeat('x', Change::MAX_BODY_BYTES + 1);
        $change = Change::capture('post', '7', 'post', ChangeAction::Update, ['post_content' => 'small'], ['post_content' => $big]);

        $this->assertFalse($change->restorable);
        $this->assertSame(Change::REASON_TRUNCATED, $change->reason);
        $this->assertLessThan(1024, strlen((string) $change->afterJson));
        $decoded = json_decode((string) $change->afterJson, true);
        $this->assertTrue($decoded['_truncated']);
        $this->assertSame(['post_content'], $change->changedFields);
        $this->assertStringContainsString('small', (string) $change->beforeJson, 'the small side is kept');
    }

    public function testABodyExactlyAtTheCapIsKept(): void
    {
        // {"a":"<n x's>"} is 8 bytes of framing around the payload.
        $payload = str_repeat('x', Change::MAX_BODY_BYTES - 8);
        $change = Change::capture('option', 'a', null, ChangeAction::Update, null, ['a' => $payload]);

        $this->assertSame(Change::MAX_BODY_BYTES, strlen((string) $change->afterJson));
        $this->assertTrue($change->restorable);
    }

    public function testInvalidUtf8IsStoredLossilyAndNotRestorable(): void
    {
        $change = Change::capture('post', '7', 'post', ChangeAction::Update, ['post_content' => 'a'], ['post_content' => "bad \xB1 byte"]);

        $this->assertFalse($change->restorable);
        $this->assertSame(Change::REASON_UNENCODABLE, $change->reason);
        $this->assertNotNull($change->afterJson);
    }

    public function testAnIrreversibleActionIsNeverRestorable(): void
    {
        $change = Change::capture('order', '9', null, ChangeAction::Irreversible, ['status' => 'processing'], ['status' => 'refunded']);

        $this->assertFalse($change->restorable);
        $this->assertSame(Change::REASON_IRREVERSIBLE, $change->reason);
    }
}
