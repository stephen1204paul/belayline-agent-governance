<?php
/**
 * `wp eval-file` helper for P2 (upgrade) seeding, run against the 0.3
 * install BEFORE the 0.4.0 zip is installed over it. Uses only the plugin's
 * own PHP APIs to issue a grant (never a raw table insert), because a real
 * API call also produces the correct audit trail the upgrade assertions
 * check for.
 *
 * Env in:
 *   BELAYLINE_GRANT_VERB          e.g. woocommerce/products-delete
 *   BELAYLINE_GRANT_SUBJECT       e.g. wc:<key_id>
 *   BELAYLINE_GRANT_CORRELATION   e.g. belayline-p2-e2e
 *   BELAYLINE_GRANT_COUNT         e.g. 3
 *   BELAYLINE_GRANT_BY_USER_ID    the granting administrator's user id
 *   BELAYLINE_SHADOW_PACK         e.g. woo-default-agent
 *   BELAYLINE_SHADOW_TTL_SECONDS  e.g. 3600
 *
 * Prints json_encode(['grant_id' => $grantId]) on success.
 */

defined('ABSPATH') || exit;

if (!function_exists('belayline') || belayline() === null) {
    fwrite(STDERR, "belayline() is unavailable — is Belayline active?\n");
    exit(1);
}

$grants = belayline()->grants();
if ($grants === null) {
    fwrite(STDERR, "belayline()->grants() returned null.\n");
    exit(1);
}

$verb          = (string) getenv('BELAYLINE_GRANT_VERB');
$subject       = (string) getenv('BELAYLINE_GRANT_SUBJECT');
$correlationId = (string) getenv('BELAYLINE_GRANT_CORRELATION');
$count         = (int) getenv('BELAYLINE_GRANT_COUNT');
$grantedBy     = (int) getenv('BELAYLINE_GRANT_BY_USER_ID');

$grantId = $grants->issue($verb, $count, $subject, $correlationId, $grantedBy);
if ($grantId === null) {
    fwrite(STDERR, "grant issue failed\n");
    exit(1);
}

// Seed the shadow window directly against the option ShadowMode itself
// documents (plugin/src/Support/ShadowMode.php: "The option maps pack name
// => unix expiry timestamp"). This is a deliberate direct-option-write
// fallback, not reverse-engineering an internal implementation detail: there
// is no host-callable API to "enable shadow" outside the wp-admin form
// (ShadowMode::apply() requires a nonce-protected admin request context),
// and the option's shape is part of that class's own public docblock
// contract, not something this script had to infer.
$pack = (string) getenv('BELAYLINE_SHADOW_PACK');
$ttl  = (int) getenv('BELAYLINE_SHADOW_TTL_SECONDS');
if ($pack !== '') {
    update_option(
        'belayline_shadow_packs',
        array_merge((array) get_option('belayline_shadow_packs', []), [$pack => time() + $ttl]),
        false
    );
}

echo json_encode(['grant_id' => $grantId]) . "\n";
