<?php

/**
 * bin/tools/filename-props.php — property test of the filename rules, run by
 * bin/lint.sh (5j). Builds random names from the characters that cause
 * trouble (controls, invisible and bidirectional characters, Windows'
 * reserved characters and device names, dots, spaces, long runs, bytes that
 * are not UTF-8) and checks that every name filename_clean(),
 * filename_for_upload() and a "_01" candidate return holds every rule
 * docs/features.md promises, and that cleaning it again changes nothing.
 * Seeded, so a failure reproduces: php bin/tools/filename-props.php [seed] [count]
 *
 * Prints one line per broken rule (at most 10) and exits 1, or exits 0
 * silently.
 */

declare(strict_types=1);

define("AESBIN_APP", true);
require __DIR__ . "/../../app/includes/helpers.php";

mt_srand((int) ($argv[1] ?? 1));
$count = (int) ($argv[2] ?? 5000);

$atoms = [
    "a", "Z", "é", "e\u{0301}", "😀", "👨\u{200D}👩", "中", " ", ".", "..", "_", "-",
    "<", ">", ":", "\"", "/", "\\", "|", "?", "*", "\t", "\n", "\x00", "\x7F", "\u{0085}",
    "\u{00A0}", "\u{3000}", "\u{200B}", "\u{FEFF}", "\u{00AD}", "\u{202E}", "\u{2066}",
    "\u{2060}", "\u{3164}", "nul", "CON", "com1", "LPT¹", "aux", ".txt", ".tar.gz", ".jpg",
    "\xE9", "\xFF", str_repeat("x", 60), str_repeat("é", 40), str_repeat("😀", 20),
    ".bbbbbbbbbbbbbbbbbbbbbbbb",
];
$device = '/^(?:CON|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³])$/iu';
$json = static fn(string $s): string => (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$broken = [];

$check = static function (string $what, string $in, string $out, bool $upload) use (&$broken, $device, $json): void {
    $rules = [
        "valid UTF-8" => mb_check_encoding($out, "UTF-8"),
        "NFC" => !class_exists("Normalizer") || Normalizer::isNormalized($out, Normalizer::FORM_C),
        "no control or invisible character" => preg_match(NAME_FORBIDDEN_CHARS, $out) === 0,
        "no character Windows refuses" => preg_match('/[<>:"\/\\\\|?*]/', $out) === 0,
        "no run of dots" => !str_contains($out, ".."),
        "no leading space" => preg_match('/^[\s\x{00A0}\x{3000}]/u', $out) === 0,
        "no trailing space or dot" => preg_match('/[\s\x{00A0}\x{3000}.]$/u', $out) === 0,
        "no Windows device name" => preg_match($device, rtrim(explode(".", $out, 2)[0], " ")) === 0,
        "within the length limits" => mb_strlen($out) <= FILENAME_MAX_CHARS && strlen($out) <= FILENAME_MAX_BYTES,
        "not empty" => $out !== "",
        "no leading dot on upload" => !$upload || !str_starts_with($out, "."),
        "cleaning again changes nothing" => filename_clean($out) === [$out, ""],
    ];
    foreach ($rules as $rule => $holds) {
        if (!$holds) {
            $broken[] = "$what {$json($in)} -> {$json($out)}: $rule";
        }
    }
};

for ($i = 0; $i < $count; $i++) {
    $name = mt_rand(0, 9) === 0 ? str_repeat("a", mt_rand(150, 260)) : "";
    for ($j = mt_rand(1, 9); $j > 0; $j--) {
        $name .= $atoms[mt_rand(0, count($atoms) - 1)];
    }
    $check("filename_clean", $name, filename_clean($name)[0], false);
    [$upload] = filename_for_upload($name);
    $check("filename_for_upload", $name, $upload, true);
    [$base, $ext] = filename_split_ext($upload);
    $check("_01 candidate of", $name, filename_fit_base($base, $ext, 3) . "_01" . $ext, true);
}

foreach (array_slice(array_values(array_unique($broken)), 0, 10) as $line) {
    echo $line, "\n";
}
exit($broken === [] ? 0 : 1);
