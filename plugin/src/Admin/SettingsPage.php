<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Admin\Ui\Html;

/**
 * The Settings tab: the environment, the address allowlist and the activity
 * retention period, each a card with its own Save button (they post to three
 * different handlers, so one sticky save bar could not serve them all). The
 * cards themselves, and the handlers behind them, live with the classes that
 * own the settings.
 */
final class SettingsPage
{
    private const CAP = 'manage_options';

    public function __construct(
        private readonly CapabilityPacksPage $packs,
        private readonly ?AuditLogPage $audit = null,
    ) {
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $this->renderMain();
    }

    public function renderMain(): void
    {
        echo '<p class="aui-page-intro">' . esc_html__('Where this site runs, which addresses AI apps may call from, and how long activity is kept.', 'belayline-agent-governance') . '</p>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
        if (isset($_GET['belayline_saved']) && $_GET['belayline_saved'] === 'retention') {
            echo Html::notice('success', __('Saved.', 'belayline-agent-governance'), __('The retention period is updated. The next daily cleanup uses it.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        }

        $this->packs->renderEnvironmentCard();
        $this->packs->renderIpAllowlistCard();
        $this->audit?->renderRetentionCard();
    }
}
