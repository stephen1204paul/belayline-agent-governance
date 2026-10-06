<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Audit\HashChain;

/** An append-only sink that links records into a real {@see HashChain}. */
final class ChainingSink implements AuditSink
{
    /** @var list<AuditRecord> */
    public array $records = [];

    /** @var list<array{prev_hash: string, canonical_json: string, entry_hash: string}> */
    public array $entries = [];

    public function append(AuditRecord $record): void
    {
        $prev = $this->entries === [] ? HashChain::GENESIS : $this->entries[count($this->entries) - 1]['entry_hash'];
        $json = $record->canonicalJson();
        $this->records[] = $record;
        $this->entries[] = ['prev_hash' => $prev, 'canonical_json' => $json, 'entry_hash' => HashChain::entryHash($prev, $json)];
    }
}
