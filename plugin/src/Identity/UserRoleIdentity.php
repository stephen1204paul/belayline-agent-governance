<?php

declare(strict_types=1);

namespace Belayline\Plugin\Identity;

/**
 * Identity provider over the logged-in WP user and their roles. Gives a site
 * owner two binding granularities for free: a specific user ("user:{id}"), or
 * every user sharing a role ("role:{name}") — e.g. bind all "editor"s to one
 * pack without enumerating each user.
 */
final class UserRoleIdentity implements IdentityProvider
{
    /**
     * Whether a token id is one this provider emits, i.e. a person's own
     * logged-in session ("user:{id}" / "role:{name}"). The single definition of
     * "person session"; every other prefix (app:, wc:, integration or unknown
     * tokens) is a credential an agent can hold.
     */
    public static function isPersonToken(string $token): bool
    {
        return str_starts_with($token, 'user:') || str_starts_with($token, 'role:');
    }

    /** @return list<string> */
    public function currentTokens(): array
    {
        if (!function_exists('wp_get_current_user')) {
            return [];
        }

        $user = wp_get_current_user();
        $id = (int) $user->ID;
        if ($id <= 0) {
            return [];
        }

        $tokens = ['user:' . $id];
        foreach ($user->roles as $role) {
            if (is_string($role) && $role !== '') {
                $tokens[] = 'role:' . $role;
            }
        }

        return $tokens;
    }

    /** @return array<string, string> */
    public function bindableTokens(): array
    {
        if (!function_exists('wp_roles')) {
            return [];
        }

        $out = [];
        foreach (wp_roles()->get_names() as $role => $displayName) {
            $out['role:' . $role] = (string) $displayName;
        }

        return $out;
    }

    public function label(): string
    {
        return __('Users & Roles', 'belayline-agent-governance');
    }
}
