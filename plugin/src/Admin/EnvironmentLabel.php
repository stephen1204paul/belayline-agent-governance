<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Support\ShadowMode;

/**
 * AS-7 §3.4 item 12: "the environment type is shown on the AS admin screens" —
 * one rendering helper so the label reads identically wherever it appears. It
 * now lives in the Environment card of the Settings tab
 * ({@see CapabilityPacksPage::renderEnvironmentCard()}).
 */
final class EnvironmentLabel
{
    /** Echoes the "Environment type: ..." line (the caller supplies the card and heading). */
    public static function render(ShadowMode $shadow): void
    {
        echo '<p class="aui-mono">' . esc_html(sprintf(
            /* translators: %s the WordPress environment type (production, staging, ...) */
            __('Environment type: %s', 'belayline-agent-governance'),
            $shadow->isProduction() ? __('production', 'belayline-agent-governance') : __('non-production', 'belayline-agent-governance')
        )) . '</p>';
    }
}
