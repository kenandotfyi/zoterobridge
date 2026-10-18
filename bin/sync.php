<?php
/** php lib/plugins/zoterobridge/bin/sync.php [--full] [--scheduled] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/Pushover.php';
$scheduled = in_array('--scheduled', $argv, true);
function failSync($message, $code = 1)
{
    global $scheduled;
    fwrite(STDERR, $message . "\n");
    if ($scheduled) {
        $notice = 'The scheduled incremental Zotero sync failed at ' . date('Y-m-d H:i:s T') . ' on ' . gethostname() . ".\nSee the Zotero Bridge admin page and server task logs for details.";
        if (!\ZoteroBridge\Pushover::configured()) fwrite(STDERR, "Pushover alert skipped; set PUSHOVER_APP_TOKEN and PUSHOVER_USER_KEY.\n");
        elseif (!\ZoteroBridge\Pushover::send('Zotero sync failed', $notice)) fwrite(STDERR, "Pushover delivery failed. Check the Pushover environment variables and HTTPS access.\n");
    }
    exit($code);
}
define('DOKU_INC', dirname(__DIR__, 4) . '/');
if (!is_file(DOKU_INC . 'inc/init.php')) failSync("Run this command from an installed DokuWiki instance.", 2);
define('NOSESSION', 1);
require DOKU_INC . 'inc/init.php';
$helper = plugin_load('helper', 'zoterobridge');
if (!$helper) failSync("Enable the Zotero Bridge plugin first.", 2);
try {
    $status = $helper->sync(in_array('--full', $argv, true), 900);
    if (($status['job']['phase'] ?? '') === 'paused' || ($status['job']['phase'] ?? '') === 'failed' || !empty($status['lastError'])) {
        failSync('Zotero sync did not complete. Check the Zotero Bridge admin page for details.');
    }
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    failSync('Zotero sync failed: ' . ($error instanceof \ZoteroBridge\ApiException ? $error->getMessage() : 'Check Zotero administration for details.'));
}
