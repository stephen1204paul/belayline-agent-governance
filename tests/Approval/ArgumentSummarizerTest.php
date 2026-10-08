<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Approval;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Approval\ArgumentSummarizer;

final class ArgumentSummarizerTest extends TestCase
{
    public function testScalarArgsShowKeyAndValue(): void
    {
        $this->assertSame(
            'acme/do { name: "Ann", count: 3, on: true, none: null }',
            ArgumentSummarizer::summarize('acme/do', ['name' => 'Ann', 'count' => 3, 'on' => true, 'none' => null]),
        );
    }

    public function testNoArgsOrOnlyTheApprovalTokenIsJustTheVerb(): void
    {
        $this->assertSame('acme/do', ArgumentSummarizer::summarize('acme/do', [ApprovalBinding::TOKEN_ARG => 't']));
    }

    public function testNestedKeysFlattenWithDotsAndListsShorten(): void
    {
        $out = ArgumentSummarizer::summarize('senroflux/update-settings', [
            'settings' => ['blogdescription' => 'Hello from Claude Code', 'deep' => ['a' => 1]],
            'ids' => [1, 2, 3],
            'one' => ['x'],
        ]);

        $this->assertSame(
            'senroflux/update-settings { settings.blogdescription: "Hello from Claude Code", settings.deep.a: 1, ids: 3 items, one: 1 item }',
            $out,
        );
    }

    public function testLongValuesAreTruncatedWithAnEllipsis(): void
    {
        $out = ArgumentSummarizer::summarize('a/b', ['v' => str_repeat('x', 200)]);

        $this->assertSame('a/b { v: "' . str_repeat('x', 79) . '…" }', $out);
    }

    public function testTotalLengthIsCapped(): void
    {
        $args = [];
        for ($i = 0; $i < 40; $i++) {
            $args["key{$i}"] = str_repeat('y', 60);
        }

        $out = ArgumentSummarizer::summarize('a/b', $args);

        $this->assertLessThanOrEqual(strlen('a/b {  }') + ArgumentSummarizer::MAX_TOTAL + 3, mb_strlen($out));
        $this->assertStringContainsString('…', $out);
        $this->assertStringContainsString('key0:', $out);
        $this->assertStringNotContainsString('key39', $out);
    }

    public function testSecretAndPiiKeysAreMaskedAtAnyDepth(): void
    {
        $out = ArgumentSummarizer::summarize('a/b', [
            'password' => 'hunter2',
            'api_key' => 'sk-live-123',
            'cfg' => ['Access_Token' => 'tok-abc', 'billing_email' => 'a@example.test', 'ok' => 'fine'],
            'auth' => ['user' => 'bob'],
        ]);

        foreach (['hunter2', 'sk-live-123', 'tok-abc', 'a@example.test', 'bob'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
        $this->assertStringContainsString('password: «redacted»', $out);
        $this->assertStringContainsString('cfg.ok: "fine"', $out);
    }

    public function testValuesStayOnOneLineAndAreNotEscapedHere(): void
    {
        $out = ArgumentSummarizer::summarize('a/b', ['t' => "<b>x</b>\n\x02evil"]);

        $this->assertSame('a/b { t: "<b>x</b> evil" }', $out);
    }
}
