<?php

declare(strict_types=1);

// Loaded by PHPStan only (bin/tools/phpstan.neon → bootstrapFiles), never by AESBin.
//
// app/includes/bootstrap.php defines these from values known only at runtime
// (the install path, the storage path, the URL base), so PHPStan cannot
// evaluate them and reports every use as "constant not found". Declaring
// them here with placeholder values of the right type fixes that. The values
// themselves are never used: dynamicConstantNames in phpstan.neon makes
// PHPStan treat each one as an unknown string.
define("AESBIN_ROOT", dirname(__DIR__, 2));
define("AESBIN_APP_DIR", dirname(__DIR__, 2) . "/app");
define("AESBIN_BASE", "");
define("AESBIN_FILES", dirname(__DIR__, 2) . "/_storage");
