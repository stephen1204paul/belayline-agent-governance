<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Map;

/**
 * Turns the identity token stored on an audit row into something a person can
 * read, without ever surfacing a secret. Token shapes (see the identity
 * providers): `app:<uuid>` (an application password: only its NAME is shown),
 * `user:<id>`, `role:<slug>`, `wc:<key id>` (shown as "WooCommerce key ...1234"),
 * or whatever another identity provider contributes. Unknown shapes go through
 * the `belayline_map_who` filter, so a connector can name its own identities.
 */
final class WhoResolver
{
    /** @var array<string, string>|null */
    private ?array $apps = null;

    /** @var callable(): array<string, string> */
    private $appNames;

    /** @var callable(int): ?string */
    private $userName;

    /** @var callable(list<int>): array<int, string> */
    private $wcKeys;

    /**
     * @param callable(): array<string, string>      $appNames Application-password token => its name; only run when an app token shows up.
     * @param callable(int): ?string                 $userName Display name for a user id, null when deleted.
     * @param callable(list<int>): array<int,string> $wcKeys   WooCommerce key id => last characters of its key.
     */
    public function __construct(
        callable $appNames,
        callable $userName,
        callable $wcKeys,
    ) {
        $this->appNames = $appNames;
        $this->userName = $userName;
        $this->wcKeys = $wcKeys;
    }

    /** The "Logged-in people" group is only used when more users than this appear. */
    public const NAMED_USERS_MAX = 3;

    /**
     * One node per distinct display group for a set of tokens.
     *
     * @param list<string> $tokens Distinct tokens seen in the window ('' = no token).
     * @return array<string, array{id: string, label: string, sub: string, tokens: list<string>}> token => group
     */
    public function groups(array $tokens): array
    {
        $users = array_values(array_filter($tokens, static fn (string $t): bool => str_starts_with($t, 'user:')));
        $collapse = count($users) > self::NAMED_USERS_MAX;

        $wcIds = [];
        foreach ($tokens as $t) {
            if (str_starts_with($t, 'wc:')) {
                $wcIds[] = (int) substr($t, 3);
            }
        }
        $wc = $wcIds === [] ? [] : ($this->wcKeys)($wcIds);

        $out = [];
        foreach ($tokens as $token) {
            $out[$token] = $this->one($token, $collapse, $wc);
        }

        // Merge tokens that resolve to the same node (two passwords both named "Claude").
        $byId = [];
        foreach ($out as $token => $g) {
            if (!isset($byId[$g['id']])) {
                $byId[$g['id']] = $g;
            } else {
                $byId[$g['id']]['tokens'][] = $token;
            }
        }
        foreach ($out as $token => $g) {
            $out[$token] = $byId[$g['id']];
        }

        return $out;
    }

    /**
     * A single token as a label plus a short kind ("AI app", "Logged in", ...), for cards (never grouped).
     *
     * @return array{label: string, sub: string}
     */
    public function describe(string $token): array
    {
        $wc = [];
        if (str_starts_with($token, 'wc:')) {
            $wc = ($this->wcKeys)([(int) substr($token, 3)]);
        }
        $g = $this->one($token, false, $wc);

        return ['label' => $g['label'], 'sub' => $g['sub']];
    }

    /** A single token's readable label, for tables and cards (never grouped). */
    public function label(string $token): string
    {
        $wc = [];
        if (str_starts_with($token, 'wc:')) {
            $wc = ($this->wcKeys)([(int) substr($token, 3)]);
        }

        return $this->one($token, false, $wc)['label'];
    }

    /**
     * Who made a call, for a row that may carry an agent credential (`via`)
     * besides its principal: "Claude via Administrator" when the app was not
     * itself bound and rode a bound role, otherwise just the principal's label.
     */
    public function actorLabel(string $token, string $via = ''): string
    {
        if ($via === '' || $via === $token) {
            return $this->label($token);
        }

        return sprintf(
            /* translators: 1: the AI app or key that made the call, 2: the role or credential whose pack applied */
            __('%1$s via %2$s', 'belayline-agent-governance'),
            $this->label($via),
            $this->label($token)
        );
    }

    /**
     * @param array<int, string> $wc
     * @return array{id: string, label: string, sub: string, tokens: list<string>}
     */
    private function one(string $token, bool $collapseUsers, array $wc): array
    {
        if ($token === '') {
            return $this->group('unknown', __('Unknown caller', 'belayline-agent-governance'), '', $token);
        }

        if (str_starts_with($token, 'app:')) {
            $this->apps ??= ($this->appNames)();
            $name = $this->apps[$token] ?? '';
            $label = $name !== '' ? $name : __('Application password (removed)', 'belayline-agent-governance');

            return $this->group('app-' . self::slug($label), $label, __('AI app', 'belayline-agent-governance'), $token);
        }

        if (str_starts_with($token, 'user:')) {
            if ($collapseUsers) {
                return $this->group('people', __('Logged-in people', 'belayline-agent-governance'), __('Logged in', 'belayline-agent-governance'), $token);
            }
            $id = (int) substr($token, 5);
            $name = ($this->userName)($id);
            $label = $name !== null && $name !== '' ? $name : __('Deleted user', 'belayline-agent-governance');

            return $this->group('user-' . $id, $label, __('Logged in', 'belayline-agent-governance'), $token);
        }

        if (str_starts_with($token, 'role:')) {
            return $this->group('role-' . self::slug(substr($token, 5)), ucfirst(str_replace(['-', '_'], ' ', substr($token, 5))), __('Role', 'belayline-agent-governance'), $token);
        }

        if (str_starts_with($token, 'wc:')) {
            $key = $wc[(int) substr($token, 3)] ?? '';
            $label = $key !== ''
                /* translators: %s: the last four characters of a WooCommerce API key */
                ? sprintf(__('WooCommerce key ...%s', 'belayline-agent-governance'), substr($key, -4))
                : __('WooCommerce key (removed)', 'belayline-agent-governance');

            return $this->group('wc-' . (int) substr($token, 3), $label, __('API key', 'belayline-agent-governance'), $token);
        }

        /**
         * Lets a connector or integration name an identity this plugin does not
         * know. Return ['label' => string, 'sub' => string] or null. Never put a
         * secret in the label: it is printed on the Overview.
         *
         * @param array{label: string, sub: string}|null $named
         * @param string                                  $token The raw identity token.
         */
        $named = apply_filters('belayline_map_who', null, $token);
        if (is_array($named) && isset($named['label']) && is_string($named['label']) && $named['label'] !== '') {
            $sub = isset($named['sub']) && is_string($named['sub']) ? $named['sub'] : '';

            return $this->group('x-' . self::slug($named['label']), $named['label'], $sub, $token);
        }

        $prefix = explode(':', $token, 2)[0];

        return $this->group('x-' . self::slug($prefix), ucfirst($prefix), '', $token);
    }

    /** @return array{id: string, label: string, sub: string, tokens: list<string>} */
    private function group(string $id, string $label, string $sub, string $token): array
    {
        return ['id' => $id, 'label' => $label, 'sub' => $sub, 'tokens' => [$token]];
    }

    /** Lower-case a-z0-9 and dashes only: safe in an id, a data attribute and the tuple string. */
    public static function slug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');

        return $slug === '' ? 'x' : substr($slug, 0, 40);
    }
}
