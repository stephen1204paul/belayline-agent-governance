<?php

declare(strict_types=1);

namespace Belayline\Plugin\Identity;

/**
 * Where providers contributed through the public `belayline_identity_providers`
 * filter sit in an {@see IdentityChain}. The filter runs lazily on first read
 * (not at bootstrap) so a plugin that registers its callback after Belayline
 * has loaded is still honoured. Entries that are not {@see IdentityProvider}
 * instances are dropped; each survivor is wrapped in {@see NamespacedIdentity}.
 */
final class ExternalIdentitySlot
{
    public const FILTER = 'belayline_identity_providers';

    /** @var list<IdentityProvider>|null */
    private ?array $resolved = null;

    /** @return list<IdentityProvider> */
    public function providers(): array
    {
        if ($this->resolved === null) {
            $list = function_exists('apply_filters') ? apply_filters(self::FILTER, []) : [];
            $this->resolved = [];
            foreach (is_array($list) ? $list : [] as $provider) {
                if ($provider instanceof IdentityProvider) {
                    $this->resolved[] = new NamespacedIdentity($provider);
                }
            }
        }

        return $this->resolved;
    }
}
