<?php

/**
 * app/actions/auth/recovery_phrase_new.php
 *
 * POST /api/auth/recovery/phrase/new
 * Auth    : none (public endpoint, behind the recovery guard)
 * Body    : {}
 * Returns : {phrase}
 *
 * Generate the replacement phrase and keep its hash in the session. Nothing
 * is stored in the database: recovery_execute.php commits it, and an
 * abandoned attempt leaves the current phrase untouched.
 *
 * Minted in its own request, not returned by execute: a phrase delivered only
 * in the response that activates it is lost for good if that response never
 * arrives, and nothing recovers a lost phrase. The page only enables the
 * confirm button once it has been copied or downloaded (as app/setup/index.php
 * does).
 *
 * Generated server-side. The browser sends it back at commit time, and only
 * the phrase whose hash is in the session is accepted, so a direct POST
 * cannot choose a weak one. The session file never holds the phrase itself
 * (the installer does the same with setup_secret_hash()).
 */

declare(strict_types=1);

defined("AESBIN_APP") || exit;

recovery_require_gate();

$phrase = $crypto->generateRecoveryPhrase();
$_SESSION["recovery_new_phrase_hash"] = recovery_phrase_hash($phrase);

json_response(["phrase" => $phrase]);
