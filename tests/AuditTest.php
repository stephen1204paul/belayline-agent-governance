<?php

declare(strict_types=1);

namespace Belayline\Tests;

use PHPUnit\Framework\TestCase;
use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Audit\HashChain;
use Belayline\Audit\Redactor;
use Belayline\Gate\Outcome;

final class AuditTest extends TestCase
{
    private function record(string $id, string $ability, AuditDecision $decision): AuditRecord
    {
        return AuditRecord::decision(
            id: $id,
            ts: '2026-06-25T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => 'ck_abc', 'wp_user' => 7],
            ability: $ability,
            tier: 2,
            input: ['order' => 123],
            decision: $decision,
        );
    }

    /** @return list<array{prev_hash: string, canonical_json: string, entry_hash: string}> */
    private function chainFrom(AuditRecord ...$records): array
    {
        $entries = [];
        $prev = HashChain::GENESIS;
        foreach ($records as $r) {
            $json = $r->canonicalJson();
            $hash = HashChain::entryHash($prev, $json);
            $entries[] = ['prev_hash' => $prev, 'canonical_json' => $json, 'entry_hash' => $hash];
            $prev = $hash;
        }

        return $entries;
    }

    public function testRecordShapeMatchesSpec(): void
    {
        $arr = $this->record('evt_1', 'woocommerce/orders-update', AuditDecision::Pending)->toArray();

        // The canonical audit-record field set, in order.
        $this->assertSame(
            ['id', 'ts', 'correlation_id', 'pack', 'actor', 'ability', 'tier', 'input', 'dry_run', 'decision', 'reason', 'approval', 'result', 'external_effects', 'ip'],
            array_keys($arr),
        );
        $this->assertSame('pending', $arr['decision']);
        $this->assertNull($arr['result']);
        $this->assertFalse($arr['dry_run']);
    }

    public function testDecisionRecordCarriesTheGateReasonCode(): void
    {
        $rec = AuditRecord::decision(
            id: 'evt_r',
            ts: '2026-07-14T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => 'ck_abc', 'wp_user' => 7],
            ability: 'woocommerce/orders-refund',
            tier: 2,
            input: ['amount' => 600],
            decision: AuditDecision::Denied,
            reason: 'argument_cap_refund_amount_max_per_call',
        );

        $this->assertSame('argument_cap_refund_amount_max_per_call', $rec->toArray()['reason']);
        // The reason is part of the hash-chain payload, not display-only.
        $this->assertStringContainsString('"reason":"argument_cap_refund_amount_max_per_call"', $rec->canonicalJson());
    }

    public function testReasonDefaultsToNullAndExecutionRecordsNeverCarryOne(): void
    {
        $this->assertNull($this->record('evt_1', 'woocommerce/orders-update', AuditDecision::Denied)->reason);

        $exec = AuditRecord::execution(
            id: 'evt_x',
            ts: '2026-07-14T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => 'ck_abc', 'wp_user' => 7],
            ability: 'woocommerce/orders-update',
            tier: 1,
            input: [],
            result: 'success',
        );
        $this->assertNull($exec->reason);
    }

    public function testExecutionRecordCarriesResult(): void
    {
        $rec = AuditRecord::execution(
            id: 'evt_2',
            ts: '2026-06-25T10:00:01Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => null, 'wp_user' => 7],
            ability: 'woocommerce/products-list',
            tier: 0,
            input: [],
            result: 'success',
        );

        $this->assertSame('allowed', $rec->toArray()['decision']);
        $this->assertSame('success', $rec->toArray()['result']);
    }

    public function testDecisionMapsFromOutcome(): void
    {
        $this->assertSame(AuditDecision::Allowed, AuditDecision::fromOutcome(Outcome::Allow));
        $this->assertSame(AuditDecision::Denied, AuditDecision::fromOutcome(Outcome::Deny));
        $this->assertSame(AuditDecision::Pending, AuditDecision::fromOutcome(Outcome::ApprovalRequired));
    }

    public function testValidChainVerifies(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
            $this->record('evt_3', 'woocommerce/orders-update', AuditDecision::Approved),
        );

        $this->assertTrue(HashChain::verify($entries));
    }

    public function testAlteredRecordBreaksChain(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
        );

        // Tamper: rewrite the second record's stored JSON (hide the refund).
        $entries[1]['canonical_json'] = str_replace('orders-update', 'products-list', $entries[1]['canonical_json']);

        $this->assertFalse(HashChain::verify($entries));
    }

    public function testDeletedRecordBreaksChain(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
            $this->record('evt_3', 'woocommerce/orders-update', AuditDecision::Approved),
        );

        // Remove the middle entry; the third's prev_hash no longer lines up.
        unset($entries[1]);
        $entries = array_values($entries);

        $this->assertFalse(HashChain::verify($entries));
    }

    public function testChainVerifiesFromACheckpointAnchorAfterThePrefixIsPruned(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
            $this->record('evt_3', 'woocommerce/orders-update', AuditDecision::Approved),
        );
        $anchor = $entries[0]['entry_hash'];
        $remaining = array_slice($entries, 1);

        $this->assertTrue(HashChain::verify($remaining, $anchor));
        $this->assertFalse(HashChain::verify($remaining), 'without the anchor the pruned chain no longer starts at GENESIS');
        $this->assertFalse(HashChain::verify($remaining, str_repeat('a', 64)), 'a wrong anchor must fail');

        $remaining[1]['canonical_json'] = str_replace('orders-update', 'products-list', $remaining[1]['canonical_json']);
        $this->assertFalse(HashChain::verify($remaining, $anchor), 'tampering after the checkpoint is still detected');
    }

    private function execution(?string $changesSha256): AuditRecord
    {
        return AuditRecord::execution(
            id: 'evt_1',
            ts: '2026-06-25T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => 'ck_abc', 'wp_user' => 7],
            ability: 'core/update-post',
            tier: 1,
            input: [],
            result: 'success',
            changesSha256: $changesSha256,
        );
    }

    public function testRecordsWithoutViaKeepTheirExactCanonicalBytesAndHash(): void
    {
        $rec = AuditRecord::execution(
            id: 'evt_1',
            ts: '2026-06-25T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => 'role:administrator', 'wp_user' => 7, 'env_type' => 'production', 'env_mismatch' => false],
            ability: 'core/update-post',
            tier: 1,
            input: [],
            result: 'success',
        );

        $json = $rec->canonicalJson();
        $this->assertSame('{"id":"evt_1","ts":"2026-06-25T10:00:00Z","correlation_id":"sess_test","pack":"default-agent","actor":{"token_id":"role:administrator","wp_user":7,"env_type":"production","env_mismatch":false},"ability":"core/update-post","tier":1,"input":[],"dry_run":false,"decision":"allowed","reason":null,"approval":null,"result":"success","external_effects":[],"ip":null}', $json);
        $this->assertSame('dbd957255aed041fc3e4474448d924a300d0f537728c5af7db602735c839910a', HashChain::entryHash(HashChain::GENESIS, $json));
    }

    public function testARecordWithViaVerifiesAlongsideOlderRecords(): void
    {
        $actor = ['token_id' => 'role:administrator', 'wp_user' => 7, 'env_type' => 'production', 'env_mismatch' => false];
        $mk = static fn (string $id, array $a): AuditRecord => AuditRecord::execution(
            id: $id,
            ts: '2026-06-25T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: $a,
            ability: 'core/update-post',
            tier: 1,
            input: [],
            result: 'success',
        );
        $old = $mk('evt_1', $actor);
        $new = $mk('evt_2', $actor + ['via' => 'oauth:d7acc']);

        $this->assertStringContainsString('"env_mismatch":false,"via":"oauth:d7acc"},"ability"', $new->canonicalJson());
        $this->assertStringNotContainsString('via', $old->canonicalJson());

        $entries = [];
        $prev = HashChain::GENESIS;
        foreach ([$old, $new] as $r) {
            $json = $r->canonicalJson();
            $hash = HashChain::entryHash($prev, $json);
            $entries[] = ['prev_hash' => $prev, 'canonical_json' => $json, 'entry_hash' => $hash];
            $prev = $hash;
        }
        $this->assertTrue(HashChain::verify($entries));

        $entries[1]['canonical_json'] = str_replace('oauth:d7acc', 'oauth:other', $entries[1]['canonical_json']);
        $this->assertFalse(HashChain::verify($entries), 'via is covered by the hash');
    }

    public function testChangesHashIsPartOfTheCanonicalRecordOnlyWhenPresent(): void
    {
        $without = $this->execution(null);
        $with = $this->execution(str_repeat('a', 64));

        $this->assertArrayNotHasKey('changes_sha256', $without->toArray());
        $this->assertSame(str_repeat('a', 64), $with->toArray()['changes_sha256']);
        $this->assertNotSame($without->canonicalJson(), $with->canonicalJson());
    }

    public function testApprovalIdIsATopLevelKeyOnlyWhenPresentAndNeverInsideInput(): void
    {
        $base = ['id' => 'evt_1', 'ts' => '2026-01-01T00:00:00Z', 'correlationId' => 's', 'pack' => 'p', 'actor' => ['token_id' => null, 'wp_user' => null], 'ability' => 'core/x', 'tier' => 1, 'result' => 'success'];
        $without = AuditRecord::execution(...$base, input: ['approval_id' => 'apr_FORGED']);
        $with = AuditRecord::execution(...$base, input: ['approval_id' => 'apr_FORGED'], approvalId: 'apr_1');

        $this->assertArrayNotHasKey('approval_id', $without->toArray(), 'agent input never becomes the link');
        $this->assertSame('apr_1', $with->toArray()['approval_id']);
        $this->assertSame('apr_FORGED', $with->toArray()['input']['approval_id']);
        $this->assertNotSame($without->canonicalJson(), $with->canonicalJson());
        $this->assertTrue(HashChain::verify($this->chainFrom($with)));
        $this->assertArrayNotHasKey('approval_id', AuditRecord::execution(...$base, input: [], approvalId: '')->toArray(), 'an empty id is no id');
    }

    public function testTamperingWithTheChangesHashBreaksTheChain(): void
    {
        $entries = $this->chainFrom($this->execution(str_repeat('a', 64)));
        $this->assertTrue(HashChain::verify($entries));

        $entries[0]['canonical_json'] = str_replace(str_repeat('a', 64), str_repeat('b', 64), $entries[0]['canonical_json']);

        $this->assertFalse(HashChain::verify($entries));
    }

    public function testRedactorMasksPiiWhenEnabled(): void
    {
        $input = [
            'order' => 123,
            'billing' => ['email' => 'a@b.com', 'first_name' => 'Ada', 'city' => 'Ipoh'],
        ];

        $out = Redactor::apply($input, true);

        $this->assertSame('«redacted»', $out['billing']['email']);
        $this->assertSame('«redacted»', $out['billing']['first_name']);
        $this->assertSame('Ipoh', $out['billing']['city']); // not on the denylist
        $this->assertSame(123, $out['order']);
    }

    public function testRedactorNoOpWhenDisabled(): void
    {
        $input = ['billing' => ['email' => 'a@b.com']];
        $this->assertSame($input, Redactor::apply($input, false));
    }
}
