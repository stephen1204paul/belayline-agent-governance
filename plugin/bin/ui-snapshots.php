<?php

/**
 * Dev-only visual snapshots (never shipped: build-zip.sh copies an allowlist and
 * this directory is not on it).
 *
 * Boots the plugin test shims, seeds realistic fixture data (packs, abilities,
 * ~150 audit rows over 7 days, two waiting approvals, a run with changes), renders
 * each Belayline tab's REAL PHP output through AdminApp and wraps it in a
 * wp-admin-like chrome that loads the vendored UI kit. Output: plugin/build/ui-snapshots/*.html
 * (gitignored). Then screenshot and axe-check them:
 *
 *   php plugin/bin/ui-snapshots.php
 *   node ../admin-ui-kit/bin/check.mjs --dir plugin/build/ui-snapshots --root plugin
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../tests/bootstrap.php';

use Belayline\Changes\CaptureWindow;
use Belayline\Packs\Pack;
use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Plugin\Admin\AbilitiesPage;
use Belayline\Plugin\Admin\AdminApp;
use Belayline\Plugin\Admin\AuditLogPage;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Admin\ChangesPage;
use Belayline\Plugin\Admin\Map\MapData;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\OverviewPage;
use Belayline\Plugin\Admin\PackOverview;
use Belayline\Plugin\Admin\PendingActionsPage;
use Belayline\Plugin\Admin\SettingsPage;
use Belayline\Plugin\Api\AbilityReviews;
use Belayline\Plugin\Api\Approvals;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\ChainStatus;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Changes\UndoService;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Integrations\Core\CorePacks;
use Belayline\Plugin\Integrations\Core\CoreVerbCatalog;
use Belayline\Plugin\Integrations\Woo\WooPacks;
use Belayline\Plugin\Integrations\Woo\WooVerbCatalog;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use Belayline\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

$outDir = dirname(__DIR__) . '/build/ui-snapshots';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}
foreach (glob($outDir . '/*.html') ?: [] as $old) {
    unlink($old);
}

$now = time();
$GLOBALS['wpas_test_time'] = $now;
$GLOBALS['wpas_test_environment_type'] = 'production';
$GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
$GLOBALS['wpas_test_current_user_id'] = 1;
$GLOBALS['wpas_test_user_names'] = [1 => 'Stephen'];
$GLOBALS['wpas_test_added_actions'] = [];

// ---------------------------------------------------------------------------
// Registered abilities, the verb catalog and the packs.
// ---------------------------------------------------------------------------
$siteAgent = [
    'list-pages' => Tier::Reversible, 'get-page' => Tier::Reversible, 'search-content' => Tier::Reversible, 'list-posts' => Tier::Reversible,
    'get-post' => Tier::Reversible, 'list-media' => Tier::Reversible, 'list-comments' => Tier::Reversible, 'list-categories' => Tier::Reversible,
    'update-page' => Tier::SideEffecting, 'update-post' => Tier::SideEffecting, 'moderate-comment' => Tier::SideEffecting,
    'publish-page' => Tier::SideEffecting, 'delete-post' => Tier::Irreversible, 'switch-theme' => Tier::Irreversible, 'activate-plugin' => Tier::Irreversible,
];
$catalog = new VerbCatalog();
$catalog->register(WooVerbCatalog::MAP);
$catalog->register(CoreVerbCatalog::MAP);
$catalog->register(array_combine(array_map(static fn (string $v): string => 'specflux-site-agent/' . $v, array_keys($siteAgent)), array_values($siteAgent)));
$classifier = new TierClassifier($catalog);

$ability = static fn (string $name, string $label = '', string $description = '', array $annotations = []): array => [
    'name' => $name, 'label' => $label !== '' ? $label : ucfirst(str_replace('-', ' ', substr((string) strrchr('/' . $name, '/'), 1))),
    'description' => $description, 'annotations' => $annotations, 'schema' => ['type' => 'object'],
];
$registered = [];
foreach (array_keys($siteAgent) as $v) {
    $registered[] = $ability('specflux-site-agent/' . $v);
}
foreach (array_keys(WooVerbCatalog::MAP) as $v) {
    $registered[] = $ability($v);
}
foreach (array_keys(CoreVerbCatalog::MAP) as $v) {
    $registered[] = $ability($v);
}
$thirdParty = [
    $ability('yoast/meta-get', 'Read SEO title and description', 'Returns the SEO title and meta description of a post.', ['readonly' => true]),
    $ability('yoast/meta-set', 'Update SEO title and description', 'Changes the SEO title and meta description of a post.'),
    $ability('yoast/redirect-add', 'Add a redirect', 'Creates a redirect from one URL to another.'),
    $ability('jetpack/stats', 'Site stats', 'Visits and top posts for a period.', ['readonly' => true]),
    $ability('jetpack/subscriber-add', 'Add a subscriber', 'Subscribes an email address to the newsletter.'),
    $ability('jetpack/newsletter-send', 'Send a newsletter', 'Emails every subscriber. This cannot be undone.'),
];
$lister = static fn (): array => [...$registered, ...$thirdParty];

$packs = [
    new Pack('content-editor', ['specflux-site-agent/*', 'core/*'], denyClass: [], approvalByClass: ['tier1' => false, 'tier2' => true]),
    ...WooPacks::all(),
    ...CorePacks::all(),
];

// ---------------------------------------------------------------------------
// The data: realistic, deterministic.
// ---------------------------------------------------------------------------
mt_srand(2026);

/** @return array{db: SqliteWpdb, sink: WpdbAuditSink} */
function snapshotDatabase(): array
{
    $db = new SqliteWpdb();

    return ['db' => $db, 'sink' => new WpdbAuditSink($db)];
}

function snapshotRow(WpdbAuditSink $sink, int $ts, string $decision, ?string $result, string $ability, ?string $token, string $pack, int &$seq, ?int $tier = 0, ?string $reason = null, string $event = ''): string
{
    $id = $event !== '' ? $event : 'evt_' . ++$seq;
    $stamp = gmdate('Y-m-d\TH:i:s\Z', $ts);
    $actor = ['token_id' => $token, 'wp_user' => $token !== null && str_starts_with($token, 'user:') ? (int) substr($token, 5) : null];
    $sink->append($result !== null
        ? AuditRecord::execution($id, $stamp, 'corr_' . $seq, $pack, $actor, $ability, $tier, ['id' => mt_rand(10, 99)], $result, ip: '203.0.113.' . mt_rand(2, 40))
        : AuditRecord::decision($id, $stamp, 'corr_' . $seq, $pack, $actor, $ability, $tier, ['id' => mt_rand(10, 99)], AuditDecision::from($decision), ip: '203.0.113.' . mt_rand(2, 40), reason: $reason));

    return $id;
}

function snapshotSeed(WpdbAuditSink $sink, int $now): void
{
    $seq = 0;
    // [token, pack, abilities...] personas with a weight each.
    $personas = [
        ['app:claude-1', 'content-editor', ['specflux-site-agent/list-pages', 'specflux-site-agent/get-page', 'specflux-site-agent/update-page', 'specflux-site-agent/search-content', 'specflux-site-agent/update-post'], 46],
        ['app:chatgpt-1', 'content-editor', ['specflux-site-agent/list-posts', 'specflux-site-agent/get-post', 'specflux-site-agent/update-post', 'woocommerce/orders-list'], 31],
        ['user:1', 'woo-default-agent', ['woocommerce/products-list', 'woocommerce/orders-get', 'woocommerce/products-update', 'core/get-site-info'], 38],
        ['wc:3', 'readonly-analyst', ['woocommerce/orders-list', 'woocommerce/products-list', 'woocommerce/orders-query'], 22],
    ];
    $total = 150;
    $day = 86400;
    for ($i = 0; $i < $total; $i++) {
        // More traffic on recent days.
        $daysAgo = (int) floor(7 * (mt_rand(0, 1000) / 1000) ** 1.6);
        $ts = $now - $daysAgo * $day - mt_rand(60, $day - 60);
        $weights = array_column($personas, 3);
        $pick = mt_rand(1, array_sum($weights));
        foreach ($personas as $p) {
            $pick -= $p[3];
            if ($pick <= 0) {
                break;
            }
        }
        [$token, $pack, $abilities] = $p;
        $name = $abilities[array_rand($abilities)];
        $roll = mt_rand(1, 100);
        $tier = str_contains($name, 'update') ? 1 : 0;
        if ($roll <= 82) {
            snapshotRow($sink, $ts, 'allowed', 'success', $name, $token, $pack, $seq, $tier);
        } elseif ($roll <= 88) {
            snapshotRow($sink, $ts, 'allowed', 'failure', $name, $token, $pack, $seq, $tier);
        } elseif ($roll <= 94) {
            snapshotRow($sink, $ts, 'denied', null, $name, $token, $pack, $seq, $tier, $roll % 2 ? 'not_in_pack' : 'denied_by_class');
        } elseif ($roll <= 97) {
            snapshotRow($sink, $ts, 'pending', null, $name, $token, $pack, $seq, 1, 'approval_required');
        } else {
            snapshotRow($sink, $ts, 'approved', null, $name, $token, $pack, $seq, 1);
        }
    }
    // Settings changes and a grant are in the log too, but are not agent calls.
    snapshotRow($sink, $now - 3 * $day, 'admin', null, 'belayline_pack_bindings', 'user:1', 'admin', $seq, null, 'binding.changed');
}

/** Two waiting approvals, with the audit rows that filed them. */
function snapshotPending(SqliteWpdb $db, WpdbAuditSink $sink, int $now): void
{
    $seq = 500;
    $rows = [
        ['apr_1043', 'woocommerce/order-refund', 'Refund order #1043 ($48.00)', 'app:chatgpt-1', 'woo-default-agent', 2, 'evt_apr_a', $now - 7200, $now + 6 * 86400 + 21 * 3600],
        ['apr_pg42', 'specflux-site-agent/publish-page', 'Publish "Spring sale" (page 42)', 'app:claude-1', 'content-editor', 1, 'evt_apr_b', $now - 3 * 3600, $now + 6 * 86400 + 23 * 3600],
    ];
    foreach ($rows as [$id, $verb, $summary, $token, $pack, $tier, $event, $created, $expires]) {
        snapshotRow($sink, $created, 'pending', null, $verb, $token, $pack, $seq, $tier, 'approval_required', $event);
        $db->insert('wp_belayline_approvals', [
            'approval_id' => $id, 'verb' => $verb, 'args_hash' => md5($id), 'summary' => $summary, 'correlation_id' => 'corr_' . $id,
            'audit_event_id' => $event, 'key_id' => $token, 'status' => 'pending', 'fingerprint' => null, 'fingerprint_kind' => $tier === 2 ? 'probe' : 'none',
            'probe_args' => null, 'created_ts' => gmdate('Y-m-d H:i:s', $created), 'pending_expires_ts' => gmdate('Y-m-d H:i:s', $expires),
            'expires_ts' => gmdate('Y-m-d H:i:s', $expires),
        ]);
    }
}

// ---------------------------------------------------------------------------
// Build the app for one scenario and render a tab of it.
// ---------------------------------------------------------------------------
/**
 * @param array<string, mixed> $get
 */
function snapshotRender(string $tab, bool $full, bool $pending, array $get, bool $paused, string $name, string $outDir, int $now, array $env): void
{
    $GLOBALS['wpas_test_options'] = [];
    $GLOBALS['wpas_test_transients'] = [];
    $GLOBALS['wpas_test_menu_pages'] = [];
    $GLOBALS['wpas_test_submenu_pages'] = [];
    $_GET = $get;
    RequestContext::reset();

    ['db' => $db, 'sink' => $sink] = snapshotDatabase();
    if ($full) {
        snapshotSeed($sink, $now);
    }
    if ($pending) {
        snapshotPending($db, $sink, $now);
    }

    $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['role:administrator' => 'owner', 'app:claude-1' => 'content-editor', 'app:chatgpt-1' => 'woo-default-agent', 'wc:3' => 'readonly-analyst'];
    $packResolver = new PackResolver($env['packs']);
    $identity = new IdentityChain([
        new FakeIdentityProvider([], ['role:administrator' => 'Administrator', 'role:shop_manager' => 'Shop manager', 'role:editor' => 'Editor'], 'Users & Roles'),
        new FakeIdentityProvider([], ['app:claude-1' => 'stephen — Claude', 'app:chatgpt-1' => 'stephen — ChatGPT', 'app:cursor-1' => 'dana — Cursor'], 'Application Passwords'),
        new FakeIdentityProvider([], ['wc:3' => 'Fulfilment dashboard (read)'], 'WooCommerce REST API keys'),
    ]);
    $changes = new AdminChangeRecorder($sink);
    $pause = new PauseSwitch($changes);
    if ($paused) {
        $pause->pause(1, 'Claude kept retrying a refund');
    }
    $shadow = new ShadowMode();
    $approvals = new WpdbApprovalStore($db);
    $reader = new AuditReader($db);
    $who = new WhoResolver(
        static fn (): array => ['app:claude-1' => 'Claude', 'app:chatgpt-1' => 'ChatGPT', 'app:cursor-1' => 'Cursor'],
        static fn (int $id): ?string => [1 => 'Stephen'][$id] ?? null,
        static fn (array $ids): array => array_fill_keys($ids, 'ck_8e2a4f2a')
    );

    // A run with changes, through the real capture window and ledger.
    $ledger = new InMemoryChangeLedger();
    $capture = new ChangeCapture($env['classifier'], $packResolver, $ledger);
    $GLOBALS['wpas_test_posts'] = [42 => (object) ['ID' => 42, 'post_title' => 'Spring sale', 'post_type' => 'page', 'post_status' => 'draft', 'post_password' => '', 'post_modified' => '2026-01-01 00:00:00']];
    if ($full) {
        foreach ([['post_title' => 'Spring sale 2026'], ['post_status' => 'publish']] as $patch) {
            $capture->run('specflux-site-agent/update-page', [], static fn () => wp_update_post(wp_slash(['ID' => 42] + $patch)));
            $event = (string) $capture->takeSettled('specflux-site-agent/update-page')['event_id'];
            $sink->append(AuditRecord::execution($event, gmdate('Y-m-d\TH:i:s\Z', $now - 600), RequestContext::correlation(), 'content-editor', ['token_id' => 'app:claude-1', 'wp_user' => null], 'specflux-site-agent/update-page', 1, ['id' => 42], 'success'));
        }
    }
    $undo = new UndoService($ledger, $capture->adapters(), $capture);

    $chain = new ChainStatus($reader);
    $audit = new AuditLogPage($reader, $chain, $who, $now);
    $pendingPage = new PendingActionsPage($approvals, new Approvals($approvals, $sink, $packResolver), $who, $reader, $now);
    $changesPage = new ChangesPage($ledger, $undo, $reader, $who);
    $abilities = new AbilitiesPage(new AbilityReviews($catalog = new VerbCatalog()), ['woocommerce/', 'core/', 'specflux-site-agent/'], $changes, $env['lister']);
    $packsPage = new CapabilityPacksPage($packResolver, $identity, $shadow, $changes, $pause, null, overview: new PackOverview($env['classifier'], $env['lister']));
    $overview = new OverviewPage(new MapData($db, $who), $chain, $reader, $approvals, $who, $now);
    $app = new AdminApp(dirname(__DIR__) . '/belayline-agent-governance.php', $pause, $shadow, $approvals, $packsPage, new SettingsPage($packsPage, $audit), $overview, $pendingPage, $audit, $changesPage, $abilities);
    $app->menu();

    ob_start();
    $app->renderTab($tab);
    $body = (string) ob_get_clean();

    $items = ['Dashboard', 'Posts', 'Media', 'Pages', 'Comments', 'WooCommerce', 'Products', 'Appearance', 'Plugins', 'Users', 'Tools', 'Settings'];
    $menu = '';
    foreach ($items as $i => $label) {
        if ($i === 10) {
            $menu .= '<li class="cur"><span class="ico ico-belayline"></span>Belayline</li>';
            foreach ($GLOBALS['wpas_test_submenu_pages']['belayline'] as $sub) {
                $menu .= '<li class="sub">' . $sub['menu'] . '</li>';
            }
        }
        $menu .= '<li><span class="ico"></span>' . $label . '</li>';
    }
    $pendingCount = $approvals->pendingCount();
    $menu = str_replace('Pending</li>', 'Pending</li>', $menu);

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Belayline - ' . htmlspecialchars($name) . '</title>'
        . '<link rel="stylesheet" href="../../assets/vendor/admin-ui-kit/tokens.css"><link rel="stylesheet" href="../../assets/vendor/admin-ui-kit/components.css"><link rel="stylesheet" href="../../assets/belayline-admin.css">'
        . '<style>' . snapshotChromeCss() . '</style></head><body>'
        . '<div class="wp"><div class="wp-adminbar" aria-hidden="true"><span class="logo"></span><span>Greenleaf Goods</span><span>' . $pendingCount . ' waiting</span><span>+ New</span><span class="r">Howdy, Stephen</span></div>'
        . '<ul class="wp-menu" aria-hidden="true">' . $menu . '</ul>'
        . '<div class="wp-content" id="wpbody-content"><main>' . $body . '</main></div></div>'
        . '<script src="../../assets/vendor/admin-ui-kit/aui.js"></script><script src="../../assets/vendor/admin-ui-kit/aui-map.js"></script><script src="../../assets/belayline-admin.js"></script>'
        . '</body></html>';

    file_put_contents($outDir . '/' . $name . '.html', $html);
    echo "  wrote {$name}.html\n";
}

function snapshotChromeCss(): string
{
    return <<<'CSS'
html { background: #f0f0f1; }
body { margin: 0; font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; }
.wp { display: grid; grid-template-columns: 160px minmax(0, 1fr); grid-template-rows: 32px 1fr; width: 100%; max-width: 1440px; min-height: 900px; background: #f0f0f1; }
.wp-adminbar { grid-column: 1 / 3; display: flex; align-items: center; gap: 20px; padding: 0 12px; background: #1d2327; color: #f0f0f1; font-size: 13px; }
.wp-adminbar .logo { width: 16px; height: 16px; border-radius: 50%; background: #a7aaad; }
.wp-adminbar .r { margin-left: auto; }
.wp-menu { margin: 0; padding: 8px 0 0; list-style: none; background: #1d2327; }
.wp-menu li { display: flex; align-items: center; gap: 8px; height: 34px; padding: 0 12px; color: #f0f0f1; font-size: 14px; }
.wp-menu li.sub { height: 28px; padding-left: 36px; font-size: 13px; color: #c3c4c7; background: #2c3338; }
.wp-menu li.cur { background: #2271b1; color: #fff; font-weight: 600; }
.wp-menu .ico { width: 16px; height: 16px; border-radius: 4px; background: #8c8f94; }
.wp-menu li.cur .ico { background: #fff; }
.wp-menu .awaiting-mod { display: inline-block; min-width: 18px; height: 18px; margin-left: 4px; border-radius: 9px; background: #d63638; color: #fff; font-size: 9px; line-height: 18px; text-align: center; }
.wp-menu .screen-reader-text { position: absolute; left: -9999px; }
.wp-content { display: flex; flex-direction: column; min-width: 0; padding-left: 20px; }
.wp-content > main { display: flex; flex-direction: column; flex: 1 1 auto; }
.wp-content .aui { flex: 1 1 auto; }
@media (max-width: 782px) {
  .wp { grid-template-columns: minmax(0, 1fr); grid-template-rows: 46px 1fr; }
  .wp-menu { display: none; }
  .wp-adminbar { grid-column: 1; }
  .wp-content { padding-left: 10px; }
}
CSS;
}

$env = ['packs' => $packs, 'classifier' => $classifier, 'lister' => $lister];
$scenarios = [
    // name, tab, seeded log, pending approvals, query string, paused
    ['overview', 'overview', true, true, [], false],
    ['overview-empty', 'overview', false, false, [], false],
    ['overview-paused', 'overview', true, true, [], true],
    ['pending', 'pending', true, true, [], false],
    ['pending-empty', 'pending', true, false, [], false],
    ['activity-calls', 'activity', true, true, ['section' => 'calls'], false],
    ['activity-calls-filtered', 'activity', true, true, ['section' => 'calls', 'outcome' => 'refused', 'days' => '7'], false],
    ['activity-changes', 'activity', true, true, ['section' => 'changes'], false],
    ['activity-changes-preview', 'activity', true, true, ['section' => 'changes', 'view' => 'preview', 'scope' => 'correlation', 'scope_id' => '', '_wpnonce' => 'test-nonce'], false],
    ['packs', 'packs', true, true, [], false],
    ['packs-prefill', 'packs', true, true, ['belayline_bind_token' => 'app:cursor-1', 'belayline_bind_pack' => 'readonly-analyst'], false],
    ['settings', 'settings', true, true, [], false],
];

echo "Rendering Belayline tabs to {$outDir}\n";
foreach ($scenarios as [$name, $tab, $full, $pending, $get, $paused]) {
    snapshotRender($tab, $full, $pending, $get, $paused, $name, $outDir, $now, $env);
}
echo "done\n";
