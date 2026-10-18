<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Map;

use Belayline\Plugin\Identity\ApplicationPasswordIdentity;
use Belayline\Plugin\Identity\IdentityChain;
use wpdb;

/** Builds the {@see WhoResolver} that reads names from WordPress. Lookups run lazily, only for tokens that appear. */
final class WhoResolverFactory
{
    public static function create(IdentityChain $identity, ?wpdb $db): WhoResolver
    {
        return new WhoResolver(
            static function () use ($identity): array {
                $names = [];
                foreach ($identity->providers() as $provider) {
                    if (!$provider instanceof ApplicationPasswordIdentity) {
                        continue;
                    }
                    foreach ($provider->bindableTokens() as $token => $label) {
                        // The provider labels each password "login — name"; only the name is shown.
                        $parts = explode(' — ', $label, 2);
                        $names[(string) $token] = trim($parts[1] ?? $parts[0]);
                    }
                }

                return $names;
            },
            static function (int $id): ?string {
                $user = function_exists('get_userdata') ? get_userdata($id) : false;

                return is_object($user) && isset($user->display_name) ? (string) $user->display_name : null;
            },
            static function (array $ids) use ($db): array {
                if ($db === null) {
                    return [];
                }
                $rows = $db->get_results($db->prepare('SELECT key_id, truncated_key FROM %i', $db->prefix . 'woocommerce_api_keys'), ARRAY_A);
                $out = [];
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $id = (int) ($row['key_id'] ?? 0);
                    if (in_array($id, $ids, true)) {
                        $out[$id] = (string) ($row['truncated_key'] ?? '');
                    }
                }

                return $out;
            }
        );
    }
}
