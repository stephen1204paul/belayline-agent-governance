<?php

declare(strict_types=1);

namespace Belayline\Plugin\Privacy;

use wpdb;

/**
 * Wires the WP core privacy-tools seams (§3.6 item 2) onto the audit log: the
 * personal-data exporter and eraser filters, plus the suggested
 * privacy-policy text. Uninstall (§3.6 item 3) is a separate concern, wired
 * elsewhere.
 */
final class PrivacyIntegration
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function register(): void
    {
        $reader = new PrivacyAuditReader($this->db);
        $exporter = new PersonalDataExporter($reader);
        $eraser = new PersonalDataEraser($reader);

        add_filter('wp_privacy_personal_data_exporters', static function (array $exporters) use ($exporter): array {
            $exporters['belayline-audit-log'] = [
                'exporter_friendly_name' => __('Belayline Agent Governance Audit Log', 'belayline-agent-governance'),
                'callback' => [$exporter, 'export'],
            ];

            return $exporters;
        });

        add_filter('wp_privacy_personal_data_erasers', static function (array $erasers) use ($eraser): array {
            $erasers['belayline-audit-log'] = [
                'eraser_friendly_name' => __('Belayline Agent Governance Audit Log', 'belayline-agent-governance'),
                'callback' => [$eraser, 'erase'],
            ];

            return $erasers;
        });

        (new PrivacyPolicyContent())->register();
    }
}
