<?php

declare(strict_types=1);

namespace Belayline\Plugin;

use Belayline\Plugin\Api\AbilityReviews;
use Belayline\Plugin\Api\Approvals;
use Belayline\Plugin\Api\Changes;
use Belayline\Plugin\Api\Grants;
use Belayline\Plugin\Support\PauseSwitch;

/**
 * The plugin's service locator, built ONCE by the bootstrap after every seam
 * is wired, and reached from anywhere (other plugins, wp-admin, site code)
 * through the global {@see belayline()} helper.
 *
 * Deliberately minimal: it exposes only the services with an external
 * contract. Internal collaborators (gate, classifier, recorder…) stay
 * constructor-injected in the bootstrap closure — handing those out would
 * invite bypassing the seams.
 */
final class Container
{
    private static ?self $instance = null;

    private function __construct(
        private readonly ?Approvals $approvals,
        private readonly ?Grants $grants = null,
        private readonly ?PauseSwitch $pause = null,
        private readonly ?Changes $changes = null,
        private readonly ?AbilityReviews $abilityReviews = null,
    ) {
    }

    /** Called once by the plugin bootstrap on plugins_loaded. Later calls overwrite. */
    public static function init(?Approvals $approvals, ?Grants $grants = null, ?PauseSwitch $pause = null, ?Changes $changes = null, ?AbilityReviews $abilityReviews = null): void
    {
        self::$instance = new self($approvals, $grants, $pause, $changes, $abilityReviews);
    }

    /**
     * The container, or null before `plugins_loaded` (or when the core lib is
     * missing/broken and the bootstrap bailed early). Callers MUST null-check:
     * null means "Belayline isn't wired yet", never "throw".
     */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * The programmatic approvals API (AS-10), or null on the pathological
     * no-database path. Feature-detect THIS rather than assuming: a consumer
     * must handle null by falling back to the wp-admin Pending tab.
     */
    public function approvals(): ?Approvals
    {
        return $this->approvals;
    }

    /**
     * The pre-approval grants API (AS-12), or null on the pathological
     * no-database path. Feature-detect THIS the same way as approvals(): null
     * means the service is not available, and a consumer must fall back to
     * ordinary per-action approvals rather than assuming grants exist.
     *
     * Note that a non-null service is NOT the same as the feature being on —
     * `belayline_enable_grants` defaults to false, and {@see Grants::issue()}
     * refuses while it is off. Check {@see Grants::enabled()} if you need to
     * tell a user why nothing was issued.
     */
    public function grants(): ?Grants
    {
        return $this->grants;
    }

    /**
     * The emergency stop: pause or resume every governed call from site code
     * or a WP-CLI command, with the same audit rows the Packs screen writes.
     * Null only when the bootstrap has not wired it; feature-detect like the
     * other services.
     */
    public function pause(): ?PauseSwitch
    {
        return $this->pause;
    }

    /**
     * Change history and undo (0.5): list what an event or run changed,
     * preview an undo, and undo as the signed-in human. Null only on the
     * no-database path; feature-detect like the other services. Undo is
     * enforced inside the service (capability, no agent contexts, no force);
     * the nonce check belongs to the caller.
     *
     *   belayline()?->changes()?->forCorrelation($id);
     *   belayline()?->changes()?->undo($set->scope(), get_current_user_id());
     */
    public function changes(): ?Changes
    {
        return $this->changes;
    }

    /**
     * Ability review (0.5): what an administrator decided about a third-party
     * ability. Never null, since it needs no database; feature-detect the
     * METHOD on older copies:
     *
     *   function_exists('belayline') && method_exists(belayline(), 'abilityReviews')
     *   belayline()->abilityReviews()->status('acme/export'); // 'reviewed' | 'unreviewed' | 'blocked'
     */
    public function abilityReviews(): AbilityReviews
    {
        return $this->abilityReviews ?? new AbilityReviews();
    }

    /** @internal tests only. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
