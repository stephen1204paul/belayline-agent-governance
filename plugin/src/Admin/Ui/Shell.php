<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Ui;

use Belayline\Plugin\Admin\AdminUrls;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\ShadowMode;

/**
 * The frame every Belayline tab renders inside: the kit's app header (mark,
 * tabs with counts, status pill, version), a main column, and a right rail that
 * always opens with the emergency stop. Pages hand in what goes in the main
 * column and any tab-specific rail widgets; the shell does the rest.
 */
final class Shell
{
    public const STOP_DIALOG = 'belayline-stop';

    public function __construct(
        private readonly PauseSwitch $pause,
        private readonly ShadowMode $shadow,
        private readonly ?WpdbApprovalStore $approvals,
        private readonly string $version,
    ) {
    }

    /**
     * @param callable(): void      $main
     * @param (callable(): void)|null $rail Tab-specific rail widgets, shown under the emergency stop.
     */
    public function render(string $tab, callable $main, ?callable $rail = null): void
    {
        echo '<div class="aui belayline-aui" data-brand="belayline">';
        echo wp_kses(Icons::sprite(), Html::allowed());
        $this->header($tab);
        echo '<hr class="wp-header-end">';
        echo '<div class="aui-body"><div class="aui-layout"><div class="aui-stack">';
        $main();
        echo '</div><aside class="aui-rail" aria-label="' . esc_attr__('Sidebar', 'belayline-agent-governance') . '">';
        $this->emergencyStop($tab);
        if ($rail !== null) {
            $rail();
        }
        echo '</aside></div></div>';
        $this->stopDialog($tab);
        echo '</div>';
    }

    /**
     * Tab => translated name.
     *
     * @return array<string, string>
     */
    public static function tabLabels(): array
    {
        return [
            AdminUrls::OVERVIEW => __('Overview', 'belayline-agent-governance'),
            AdminUrls::PENDING => __('Pending', 'belayline-agent-governance'),
            AdminUrls::ACTIVITY => __('Activity', 'belayline-agent-governance'),
            AdminUrls::PACKS => __('Packs', 'belayline-agent-governance'),
            AdminUrls::SETTINGS => __('Settings', 'belayline-agent-governance'),
        ];
    }

    /** 'paused', 'shadow' or 'guarding': what the header pill says. */
    public function status(): string
    {
        if ($this->pause->isPaused()) {
            return 'paused';
        }

        return $this->shadow->packs() !== [] ? 'shadow' : 'guarding';
    }

    private function header(string $tab): void
    {
        echo wp_kses('<header class="aui-header"><div class="aui-brand"><span class="aui-mark">' . Icons::svg('shield', 'aui-i--lg') . '</span><h1>'
            . esc_html__('Belayline', 'belayline-agent-governance') . '</h1></div>', Html::allowed());
        echo '<nav class="aui-nav" aria-label="' . esc_attr__('Belayline', 'belayline-agent-governance') . '">';
        $pending = $this->approvals?->pendingCount() ?? 0;
        foreach (self::tabLabels() as $key => $label) {
            echo '<a href="' . esc_url(AdminUrls::tab($key)) . '"' . ($key === $tab ? ' aria-current="page"' : '') . '>' . esc_html($label);
            if ($key === AdminUrls::PENDING && $pending > 0) {
                echo ' <span class="aui-count aui-count--warn">' . esc_html((string) $pending) . '</span>';
            }
            echo '</a>';
        }
        echo '</nav><div class="aui-header-end">';
        [$tone, $text] = match ($this->status()) {
            'paused' => ['bad', __('Paused', 'belayline-agent-governance')],
            'shadow' => ['warn', __('Shadow mode', 'belayline-agent-governance')],
            default => ['ok', __('Guarding', 'belayline-agent-governance')],
        };
        echo '<span class="aui-pill aui-pill--' . esc_attr($tone) . '">' . esc_html($text) . '</span>';
        echo '<span class="aui-chip">v' . esc_html($this->version) . '</span></div></header>';
    }

    /** The stop control (or, while paused, the way back), first in every rail. */
    private function emergencyStop(string $tab): void
    {
        echo '<section class="aui-card"><div class="aui-card-b belayline-stack">';
        if (!$this->pause->isPaused()) {
            echo '<div><h2 class="aui-card-t">' . esc_html__('Emergency stop', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
                . esc_html__('Pause every agent on this site right now. Waiting actions stay in the queue and nothing is deleted.', 'belayline-agent-governance') . '</p></div>';
            echo wp_kses('<button type="button" class="aui-btn aui-btn--danger" data-aui-open="' . esc_attr(self::STOP_DIALOG) . '">' . Icons::svg('pause')
                . esc_html__('Pause all agent actions', 'belayline-agent-governance') . '</button>', Html::allowed());
            echo '</div></section>';

            return;
        }

        $state = $this->pause->state();
        echo '<div><h2 class="aui-card-t">' . esc_html__('All agent actions are paused', 'belayline-agent-governance') . '</h2><p class="aui-card-d">';
        if ($state === null) {
            echo esc_html(sprintf(
                /* translators: %s: a filter hook name */
                __('A site setting (the %s filter) forces this pause. It cannot be lifted from this screen.', 'belayline-agent-governance'),
                PauseSwitch::FILTER
            ));
        } else {
            echo esc_html(sprintf(
                /* translators: 1: date and time (UTC), 2: user id, 3: reason */
                __('Since %1$s by user #%2$s. Reason: %3$s', 'belayline-agent-governance'),
                $state['since'] === null ? '?' : gmdate('Y-m-d H:i', $state['since']) . ' UTC',
                $state['by'] === null ? '?' : (string) $state['by'],
                $state['reason'] === '' ? '-' : $state['reason']
            ));
        }
        echo '</p></div>';
        if ($state !== null) {
            echo wp_kses(Html::postForm(CapabilityPacksPage::RESUME, CapabilityPacksPage::RESUME, Html::backTo($tab)), Html::allowed());
            echo wp_kses('<button type="submit" class="aui-btn aui-btn--primary">' . Icons::svg('play') . esc_html__('Resume agent actions', 'belayline-agent-governance') . '</button></form>', Html::allowed());
        }
        echo '</div></section>';
    }

    /** The confirm dialog behind the stop button. The reason is optional: stopping must never be made harder. */
    private function stopDialog(string $tab): void
    {
        if ($this->pause->isPaused()) {
            return;
        }
        echo '<div class="aui-backdrop" id="' . esc_attr(self::STOP_DIALOG) . '" hidden><div class="aui-dialog" role="dialog" aria-modal="true" aria-labelledby="belayline-stop-t">';
        echo '<h2 id="belayline-stop-t">' . esc_html__('Pause all agent actions?', 'belayline-agent-governance') . '</h2>';
        echo '<p>' . esc_html__('Every agent call is refused right away, whatever its pack. Each refusal is still written to the activity log. You can resume from this page.', 'belayline-agent-governance') . '</p>';
        echo wp_kses(Html::postForm(CapabilityPacksPage::PAUSE, CapabilityPacksPage::PAUSE, Html::backTo($tab)), Html::allowed());
        echo '<div class="aui-field"><label class="aui-label" for="belayline-stop-reason">' . esc_html__('Reason (optional)', 'belayline-agent-governance') . '</label>';
        echo '<input class="aui-input" type="text" id="belayline-stop-reason" name="reason" autocomplete="off"></div>';
        echo '<div class="aui-dialog-f"><button type="button" class="aui-btn aui-btn--secondary" data-aui-close>' . esc_html__('Cancel', 'belayline-agent-governance') . '</button>';
        echo '<button type="submit" class="aui-btn aui-btn--danger">' . esc_html__('Pause everything', 'belayline-agent-governance') . '</button></div>';
        echo '</form></div></div>';
    }
}
