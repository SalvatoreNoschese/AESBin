<?php

/**
 * app/actions/auth/passkey_remove.php
 *
 * POST /api/auth/passkey/remove
 * Auth    : requireElevated()
 * Body    : {credential_id}  base64 or base64url, as returned by
 *           GET /api/system/settings
 * Returns : {ok, count, removed_credential_id, rp_id}
 *
 * Remove a registered passkey. Elevation-gated like registration: from a
 * hijacked session it would allow lockout or downgrade.
 *
 * Identified by credential id, not list index: an index shifts when another
 * tab adds or removes a passkey, and would delete the wrong one. A stale id
 * is a clean 404.
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

$auth->requireElevated();

$body = json_body();
require_fields($body, ["credential_id"]);
$credentialId = $body["credential_id"];

// Normalise to the stored standard base64: browsers hand out base64url ids.
$raw = b64_decode_any($credentialId);
if ($raw === false || $raw === "") {
    json_response(["error" => __("err_credential_encoding")], 400);
}
$credentialId = base64_encode($raw);

// Locked read-modify-write; see webauthn_lock() in helpers.php.
webauthn_lock($db);

$data = webauthn_data($db);
if ($data["credentials"] === []) {
    json_response(["error" => __("err_no_passkeys")], 404);
}

$index = null;
foreach ($data["credentials"] as $i => $c) {
    if (hash_equals((string) ($c["id"] ?? ""), $credentialId)) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    json_response(["error" => __("err_passkey_not_found")], 404);
}

// Forwarded by the client to PublicKeyCredential.signalUnknownCredential() so
// the password manager can drop the orphaned entry. Best effort: WebAuthn
// lets a site signal, not delete.
$removedId = (string) $data["credentials"][$index]["id"];

// array_splice() renumbers, so the stored JSON stays a list.
array_splice($data["credentials"], (int) $index, 1);
webauthn_data_save($db, $data);

log_activity($db, "passkey.remove", null, null, [
    "remaining" => count($data["credentials"]),
]);

json_response([
    "ok" => true,
    "count" => count($data["credentials"]),
    "removed_credential_id" => $removedId,
    // For signalUnknownCredential: differs from location.hostname when
    // config.php pins a parent domain.
    "rp_id" => webauthn_rp_id($config),
]);
