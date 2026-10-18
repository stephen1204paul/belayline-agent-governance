<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The vendored admin UI kit must be exactly what `admin-ui-kit/bin/sync` wrote:
 * every file hashes to its MANIFEST.sha256 entry, and the manifest names exactly
 * the files that are there. A hand edit in plugin/assets/vendor (or a stale copy)
 * fails here instead of shipping.
 */
final class VendoredKitTest extends TestCase
{
    private const DIR = __DIR__ . '/../../assets/vendor/admin-ui-kit';

    /** @return array<string, string> file => sha256 */
    private static function manifest(): array
    {
        $lines = file(self::DIR . '/MANIFEST.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, 'MANIFEST.sha256 is missing: run admin-ui-kit/bin/sync plugin');
        $out = [];
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}  [A-Za-z0-9._-]+$/', $line);
            $out[substr($line, 66)] = substr($line, 0, 64);
        }

        return $out;
    }

    public function testEveryVendoredFileMatchesItsManifestHash(): void
    {
        foreach (self::manifest() as $file => $hash) {
            $this->assertFileExists(self::DIR . '/' . $file);
            $this->assertSame($hash, hash_file('sha256', self::DIR . '/' . $file), $file . ' differs from the kit it was synced from');
        }
    }

    public function testTheManifestListsTheFilesTheShellLoadsAndNothingIsUnlisted(): void
    {
        $listed = array_keys(self::manifest());
        sort($listed);
        $this->assertSame(['VERSION', 'aui-map.js', 'aui.js', 'components.css', 'icons.svg', 'tokens.css'], $listed);

        $onDisk = array_values(array_diff((array) scandir(self::DIR), ['.', '..', 'MANIFEST.sha256']));
        sort($onDisk);
        $this->assertSame($listed, $onDisk, 'a file in the vendored directory that the manifest does not cover');
    }

    public function testTheVendoredVersionIsRecorded(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', trim((string) file_get_contents(self::DIR . '/VERSION')));
    }

    public function testNothingInTheKitReachesForTheNetwork(): void
    {
        foreach (['tokens.css', 'components.css', 'aui.js', 'aui-map.js', 'icons.svg'] as $file) {
            $this->assertDoesNotMatchRegularExpression('#(https?:)?//(?!www\.w3\.org/)[a-z0-9.-]+\.[a-z]{2,}/#i', (string) file_get_contents(self::DIR . '/' . $file), $file . ' references a remote host (wp.org guideline 8)');
        }
    }
}
