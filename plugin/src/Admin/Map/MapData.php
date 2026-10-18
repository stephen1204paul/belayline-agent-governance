<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Map;

use Belayline\Plugin\Support\Schema;
use wpdb;

/**
 * The numbers behind the Overview: the last 7 days of the audit log aggregated
 * for the map (who asked, through which plugin, under which pack, with what
 * outcome) plus the daily series for the stat cards. Read-only, display-only.
 *
 * The map query is ONE `GROUP BY` over a `ts` range (indexed since schema 8).
 * Its result is cached per site in the `belayline_map_7d` transient for five
 * minutes AND is rebuilt as soon as the newest audit row id changes, so a new
 * audit row invalidates it without touching the audit write path. The `who`
 * column is read straight out of the stored record (`actor.token_id`, the first
 * occurrence, which the canonical field order puts before any agent-supplied
 * input).
 *
 * Lane contract with the kit's `aui-map.js`: one tuple per distinct path,
 * `who,through,pack,outcome,count`, ids prefixed per lane (w-, t-, p-, o-) so
 * they are unique across lanes; at most {@see LANE_MAX} nodes per lane, the
 * tail folded into a single "+n more" node.
 */
final class MapData
{
    public const CACHE_KEY = 'belayline_map_7d';
    public const TTL = 300;
    public const DAYS = 7;
    public const LANE_MAX = 6;

    /** @var array<string, string> Known plugin namespaces and how people know them. */
    private const THROUGH = [
        'specflux-site-agent' => 'Site Agent',
        'woocommerce' => 'WooCommerce',
        'core' => 'WordPress',
        'mcp-adapter' => 'mcp-adapter',
        'surecart' => 'SureCart',
    ];

    public function __construct(private readonly wpdb $db, private readonly WhoResolver $who)
    {
    }

    /**
     * The cached aggregate, rebuilt when older than {@see TTL} or when a newer audit row exists.
     *
     * @return array<string, mixed>
     */
    public function get(int $now): array
    {
        $maxId = $this->maxId();
        $cached = get_transient(self::CACHE_KEY);
        if (
            is_array($cached) && ($cached['max_id'] ?? null) === $maxId
            && is_int($cached['built'] ?? null) && $now - $cached['built'] < self::TTL
            && is_array($cached['data'] ?? null)
        ) {
            return $cached['data'];
        }

        $data = $this->compute($now);
        set_transient(self::CACHE_KEY, ['max_id' => $maxId, 'built' => $now, 'data' => $data], self::TTL);

        return $data;
    }

    /** @return array<string, mixed> */
    public function compute(int $now): array
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', $now - self::DAYS * 86400);
        $map = self::build($this->pathRows($since), $this->who);
        $map['days'] = self::dailySeries($this->dayRows(gmdate('Y-m-d\T00:00:00\Z', $now - (self::DAYS - 1) * 86400)), $now);
        $map['since'] = $since;
        $map['built'] = $now;

        return $map;
    }

    private function maxId(): int
    {
        return (int) $this->db->get_var($this->db->prepare('SELECT MAX(id) FROM %i', Schema::auditLogTable($this->db)));
    }

    /**
     * The map query: one row per distinct (who, plugin namespace, pack, decision, result).
     *
     * @return list<array{who: string, ns: string, pack: string, decision: string, result: ?string, n: int}>
     */
    public function pathRows(string $since): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT CASE WHEN INSTR(record_json, '\"token_id\":\"') > 0 THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(record_json, '\"token_id\":\"', 2), '\"token_id\":\"', -1), '\"', 1) ELSE '' END AS who, "
                . "SUBSTRING_INDEX(ability, '/', 1) AS ns, pack, decision, result, COUNT(*) AS n "
                . "FROM %i WHERE ts >= %s AND decision IN ('allowed', 'denied', 'pending', 'approved', 'rejected') "
                . 'GROUP BY who, ns, pack, decision, result',
                Schema::auditLogTable($this->db),
                $since
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = [
                'who' => (string) ($r['who'] ?? ''),
                'ns' => (string) ($r['ns'] ?? ''),
                'pack' => (string) ($r['pack'] ?? ''),
                'decision' => (string) ($r['decision'] ?? ''),
                'result' => isset($r['result']) ? (string) $r['result'] : null,
                'n' => (int) ($r['n'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Calls per day and decision, for the stat cards.
     *
     * @return list<array{d: string, decision: string, n: int}>
     */
    public function dayRows(string $since): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT SUBSTRING(ts, 1, 10) AS d, decision, COUNT(*) AS n FROM %i WHERE ts >= %s AND decision IN ('allowed', 'denied', 'pending', 'rejected') GROUP BY d, decision",
                Schema::auditLogTable($this->db),
                $since
            ),
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = ['d' => (string) ($r['d'] ?? ''), 'decision' => (string) ($r['decision'] ?? ''), 'n' => (int) ($r['n'] ?? 0)];
        }

        return $out;
    }

    /**
     * Seven days, oldest first. `calls` counts first-contact rows (allowed,
     * denied, pending); `refused` counts denied and rejected.
     *
     * @param list<array{d: string, decision: string, n: int}> $rows
     * @return list<array{date: string, calls: int, refused: int}>
     */
    public static function dailySeries(array $rows, int $now): array
    {
        $days = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $days[gmdate('Y-m-d', $now - $i * 86400)] = ['date' => gmdate('Y-m-d', $now - $i * 86400), 'calls' => 0, 'refused' => 0];
        }
        foreach ($rows as $r) {
            if (!isset($days[$r['d']])) {
                continue;
            }
            if (in_array($r['decision'], CallOutcome::ATTEMPT_DECISIONS, true)) {
                $days[$r['d']]['calls'] += $r['n'];
            }
            if ($r['decision'] === 'denied' || $r['decision'] === 'rejected') {
                $days[$r['d']]['refused'] += $r['n'];
            }
        }

        return array_values($days);
    }

    /**
     * Fold query rows into lanes and tuples.
     *
     * @param list<array{who: string, ns: string, pack: string, decision: string, result: ?string, n: int}> $rows
     * @return array{total: int, tuples: string, lanes: array<string, list<array{id: string, label: string, sub: string, count: int, filter: ?array{key: string, value: string}}>>}
     */
    public static function build(array $rows, WhoResolver $who): array
    {
        $tokens = array_values(array_unique(array_map(static fn (array $r): string => $r['who'], $rows)));
        $groups = $who->groups($tokens);

        /** @var array<string, array{id: string, label: string, sub: string, filter: ?array{key: string, value: string}}> $meta */
        $meta = [];
        /** @var array<string, int> $paths */
        $paths = [];

        foreach ($rows as $r) {
            $outcome = CallOutcome::classify($r['decision'], $r['result']);
            if ($outcome === null || $r['n'] <= 0) {
                continue;
            }
            $g = $groups[$r['who']];
            $w = 'w-' . $g['id'];
            $ns = $r['ns'] !== '' ? $r['ns'] : 'unknown';
            $t = 't-' . WhoResolver::slug($ns);
            $packName = $r['pack'];
            $p = 'p-' . ($packName === '' ? 'none' : WhoResolver::slug($packName));

            $meta[$w] ??= ['id' => $w, 'label' => $g['label'], 'sub' => $g['sub'], 'filter' => self::whoFilter($g)];
            $meta[$t] ??= ['id' => $t, 'label' => self::THROUGH[$ns] ?? $ns, 'sub' => '', 'filter' => ['key' => 'through', 'value' => $ns]];
            $meta[$p] ??= ['id' => $p, 'label' => $packName === '' ? __('No pack', 'belayline-agent-governance') : $packName, 'sub' => '', 'filter' => ['key' => 'pack', 'value' => $packName === '' ? '__none' : $packName]];
            $meta['o-' . $outcome] ??= ['id' => 'o-' . $outcome, 'label' => CallOutcome::label($outcome), 'sub' => '', 'filter' => ['key' => 'outcome', 'value' => $outcome]];

            $key = $w . '|' . $t . '|' . $p . '|o-' . $outcome;
            $paths[$key] = ($paths[$key] ?? 0) + $r['n'];
        }

        // Fold the tail of each of the first three lanes into "+n more".
        foreach ([0 => ['w-', 'w-more'], 1 => ['t-', 't-more'], 2 => ['p-', 'p-more']] as $lane => [$prefix, $moreId]) {
            $totals = [];
            foreach ($paths as $key => $n) {
                $id = explode('|', $key)[$lane];
                $totals[$id] = ($totals[$id] ?? 0) + $n;
            }
            if (count($totals) <= self::LANE_MAX) {
                continue;
            }
            uksort($totals, static fn (string $a, string $b): int => [$totals[$b], $a] <=> [$totals[$a], $b]);
            $keep = array_slice(array_keys($totals), 0, self::LANE_MAX - 1);
            $folded = count($totals) - count($keep);

            $next = [];
            foreach ($paths as $key => $n) {
                $parts = explode('|', $key);
                if (!in_array($parts[$lane], $keep, true)) {
                    $parts[$lane] = $moreId;
                }
                $k = implode('|', $parts);
                $next[$k] = ($next[$k] ?? 0) + $n;
            }
            $paths = $next;
            /* translators: %d: how many more boxes are folded into this one */
            $meta[$moreId] = ['id' => $moreId, 'label' => sprintf(__('+%d more', 'belayline-agent-governance'), $folded), 'sub' => '', 'filter' => null];
        }

        $lanes = ['who' => [], 'through' => [], 'pack' => [], 'outcome' => []];
        $counts = [];
        foreach ($paths as $key => $n) {
            foreach (explode('|', $key) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + $n;
            }
        }
        foreach ($counts as $id => $n) {
            $lane = match (true) {
                str_starts_with($id, 'w-') => 'who',
                str_starts_with($id, 't-') => 'through',
                str_starts_with($id, 'p-') => 'pack',
                default => 'outcome',
            };
            $lanes[$lane][] = $meta[$id] + ['count' => $n];
        }
        foreach (['who', 'through', 'pack'] as $lane) {
            usort($lanes[$lane], static function (array $a, array $b): int {
                $am = str_ends_with($a['id'], '-more') ? 1 : 0;
                $bm = str_ends_with($b['id'], '-more') ? 1 : 0;

                return [$am, $b['count'], $a['label']] <=> [$bm, $a['count'], $b['label']];
            });
        }
        // The outcome lane is always all five, in a fixed order, so the map keeps its shape.
        $outcomes = [];
        foreach (CallOutcome::ALL as $o) {
            $outcomes[] = ($meta['o-' . $o] ?? ['id' => 'o-' . $o, 'label' => CallOutcome::label($o), 'sub' => '', 'filter' => ['key' => 'outcome', 'value' => $o]]) + ['count' => $counts['o-' . $o] ?? 0];
        }
        $lanes['outcome'] = $outcomes;

        ksort($paths);
        $tuples = [];
        foreach ($paths as $key => $n) {
            $tuples[] = str_replace('|', ',', $key) . ',' . $n;
        }

        return ['total' => array_sum($paths), 'tuples' => implode(';', $tuples), 'lanes' => $lanes];
    }

    /**
     * @param array{id: string, label: string, sub: string, tokens: list<string>} $g
     * @return array{key: string, value: string}
     */
    private static function whoFilter(array $g): array
    {
        if ($g['id'] === 'people') {
            return ['key' => 'token_prefix', 'value' => 'user:'];
        }
        $tokens = array_slice($g['tokens'], 0, 5);

        return ['key' => 'token', 'value' => implode(',', $tokens)];
    }
}
