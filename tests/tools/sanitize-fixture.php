<?php
/**
 * Removes account data from a saved ExHentai page before it is committed as a test fixture.
 *
 * Usage: php tests/tools/sanitize-fixture.php <saved.html> <tests/fixtures/exhentai/name.html>
 *
 * Replaces the API key/uid in the inline scripts and fails when the page still contains values that
 * look like account data (cookies, member id, pass hash). Check the output with git diff anyway.
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tests/tools/sanitize-fixture.php <saved.html> <fixture.html>\n");
    exit(1);
}

$html = file_get_contents($argv[1]);
if ($html === false) {
    fwrite(STDERR, "Can't read {$argv[1]}\n");
    exit(1);
}

$replacements = array(
    '/(var\s+apikey\s*=\s*")[0-9a-f]+(")/i' => '${1}0000000000000000000${2}',
    '/(var\s+apiuid\s*=\s*)\d+/i' => '${1}0',
    // "Logged in as" style user name links and the user's own profile links
    '~(showuser=)\d+~' => '${1}0',
    // one-time H@H download link of archiver.php's result page
    '~https?://[a-z0-9]+\.hath\.network/archive/\d+/[0-9a-f]+/[0-9a-z]+/\d+~i'
        => 'https://example.hath.network/archive/0/0000000000000000000000000000000000000000/xxxxxxxxxxx/0',
);
$html = preg_replace(array_keys($replacements), array_values($replacements), $html);

$suspicious = array(
    'ipb_member_id', 'ipb_pass_hash', 'igneous', 'ipb_session_id', 'hath_perks', 'sk=', 'star=',
);
$found = array();
foreach ($suspicious as $needle) {
    if (stripos($html, $needle) !== false) {
        $found[] = $needle;
    }
}

file_put_contents($argv[2], $html);

if ($found) {
    fwrite(STDERR, "Written, but check these by hand: ".implode(', ', $found)."\n");
    exit(2);
}

echo "Written {$argv[2]}\n";
