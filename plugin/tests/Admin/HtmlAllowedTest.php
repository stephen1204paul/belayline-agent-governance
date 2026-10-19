<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Tiers;
use PHPUnit\Framework\TestCase;

/**
 * Html::allowed() is the one wp_kses() allow-list for admin markup. Every helper's output must
 * survive it with the same tags, attributes and values, or a screen would silently lose markup.
 *
 * In this suite wp_kses() is the bootstrap shim (core is not loaded). The same inputs were also
 * replayed through core's real kses; see the commit message for how.
 */
final class HtmlAllowedTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function helperOutput(): array
    {
        $cases = [
            'icon' => Icons::svg('check'),
            'icon with class' => Icons::svg('caret-down', 'aui-i--lg'),
            'sprite' => Icons::sprite(),
            'badge' => Html::badge('warn', 'Waiting', 'clock'),
            'plain badge' => Html::badge('', 'Shadow'),
            'notice' => Html::notice('error', 'Nope', 'Because.'),
            'form' => Html::postForm('act', 'act', Html::backTo('overview'), 'class="aui-row-flex"') . '</form>',
            'card header' => Html::cardHeader('Title', 'Desc', 'the-id'),
            'tier badge' => Tiers::badge(Tiers::ALWAYS),
            'meta span' => Tiers::metaSpan(3, [Tiers::FREE => 2, Tiers::APPROVAL => 1], false),
            'live meta span' => Tiers::metaSpan(3, [Tiers::FREE => 2, Tiers::APPROVAL => 1], true),
            'kit attributes' => '<div class="aui-group-b" id="x" hidden><button type="button" class="aui-node" aria-expanded="false" aria-controls="y" data-node="n" data-count="2" data-aui-open="d">b</button>'
                . '<select class="aui-select" id="s" name="n"><option value="a" selected="selected">A</option></select>'
                . '<input class="aui-input" type="number" min="0" max="9" value="1" name="n" autocomplete="off" placeholder="p">'
                . '<table><thead><tr><th scope="col">h</th></tr></thead><tbody><tr><td>c</td></tr></tbody></table></div>',
        ];

        return array_map(static fn (string $html): array => [$html], $cases);
    }

    /** @dataProvider helperOutput */
    public function testHelperOutputPassesTheAllowListUnchanged(string $html): void
    {
        $this->assertNotSame('', $html);
        $this->assertSame(self::shape($html), self::shape(wp_kses($html, Html::allowed())));
    }

    public function testTheAllowListStaysStrict(): void
    {
        $out = wp_kses('<div class="a" onclick="x()"><script>alert(1)</script><iframe src="//e"></iframe><a href="javascript:x()" class="b">l</a></div>', Html::allowed());

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testTheAllowListIsBuiltOnce(): void
    {
        $this->assertSame(Html::allowed(), Html::allowed());
    }

    /**
     * Tags, attribute names (case-folded, in order) and values, ignoring quote style,
     * self-closing spacing and comments, which differ between kses implementations.
     *
     * @return list<string>
     */
    private static function shape(string $html): array
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        preg_match_all('/<(\/?[a-zA-Z0-9]+)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $html, $tags, PREG_SET_ORDER);

        $shape = [];
        foreach ($tags as $tag) {
            $shape[] = strtolower($tag[1]);
            preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>\/]+))?/', $tag[2], $attrs, PREG_SET_ORDER);
            foreach ($attrs as $attr) {
                $shape[] = strtolower($attr[1]) . '=' . trim($attr[2] ?? '', '"\'');
            }
        }

        return $shape;
    }
}
