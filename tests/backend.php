<?php
/** Offline integration tests: php lib/plugins/zoterobridge/tests/backend.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/Credentials.php';
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Index.php';
require_once dirname(__DIR__) . '/src/Sync.php';
require_once dirname(__DIR__) . '/src/Pushover.php';

use ZoteroBridge\Api;
use ZoteroBridge\ApiException;
use ZoteroBridge\Credentials;
use ZoteroBridge\Index;
use ZoteroBridge\Pushover;
use ZoteroBridge\Sync;

$checks = 0;
$root = sys_get_temp_dir() . '/zoterobridge-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
function verify($value, $message) { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function fails($call, $message) { try { $call(); } catch (Throwable $error) { verify(strpos($error->getMessage(), $message) !== false, 'Wrong error: ' . $error->getMessage()); return; } throw new RuntimeException('Expected failure: ' . $message); }
function object($key, $data, $version = 1) { return ['key' => $key, 'version' => $version, 'data' => ['key' => $key, 'version' => $version] + $data]; }
function response($data, $version = 1, $status = 200, $headers = []) { return ['status' => $status, 'headers' => ['last-modified-version' => (string)$version] + $headers, 'body' => json_encode($data, JSON_THROW_ON_ERROR)]; }
function cleanup($path) { foreach (scandir($path) as $name) { if ($name === '.' || $name === '..') continue; $file = $path . '/' . $name; if (is_dir($file) && !is_link($file)) cleanup($file); else unlink($file); } rmdir($path); }

$key = str_repeat('A', 24);
$oldEnvironment = getenv('ZOTERO_API_KEY');
$oldPushoverToken = getenv('PUSHOVER_APP_TOKEN');
$oldPushoverUser = getenv('PUSHOVER_USER_KEY');
try {
    putenv('PUSHOVER_APP_TOKEN=test-app-token');
    putenv('PUSHOVER_USER_KEY=test-user-key');
    $pushoverRequest = null;
    verify(Pushover::send('Zotero sync failed', 'Fixture failure', static function ($url, $body) use (&$pushoverRequest) {
        parse_str($body, $pushoverRequest);
        return ['status' => 200, 'body' => '{"status":1}'];
    }), 'Pushover success response was not accepted');
    verify(($pushoverRequest['token'] ?? '') === 'test-app-token' && ($pushoverRequest['user'] ?? '') === 'test-user-key' && ($pushoverRequest['message'] ?? '') === 'Fixture failure', 'Pushover payload was not encoded correctly');
    verify(!Pushover::send('Zotero sync failed', 'Fixture failure', static function () { return ['status' => 401, 'body' => '{"status":0}']; }), 'Rejected Pushover response was reported as delivered');

    putenv('ZOTERO_API_KEY');
    $secret = $root . '/secret';
    verify(Credentials::resolve($key, $secret)['source'] === 'configuration', 'Local fallback not selected');
    putenv('ZOTERO_API_KEY=' . str_repeat('B', 24));
    verify(Credentials::resolve($key, $secret)['source'] === 'environment', 'Environment not selected');
    file_put_contents($secret, str_repeat('C', 24));
    verify(Credentials::resolve($key, $secret)['source'] === 'secret', 'Secret not selected');
    verify(!Credentials::valid("ABC\r\nHeader: bad"), 'Header injection accepted');
    putenv('ZOTERO_API_KEY');

    $collections = [object('COLL0001', ['name' => 'Power electronics', 'parentCollection' => false]), object('COLL0002', ['name' => 'Gate drivers', 'parentCollection' => 'COLL0001'])];
    $items = [
        object('DOC00001', ['itemType' => 'report', 'title' => 'SiC gate driver guide', 'creators' => [['name' => 'Example Semiconductor']], 'date' => '2025', 'collections' => ['COLL0002'], 'tags' => [['tag' => 'IGBT']]]),
        object('PDF00001', ['itemType' => 'attachment', 'parentItem' => 'DOC00001', 'contentType' => 'application/pdf', 'title' => 'Design guide.pdf']),
        object('ANN00001', ['itemType' => 'annotation', 'parentItem' => 'PDF00001', 'annotationText' => 'Short-circuit protection & timing: VGS<6V; VDS>400V', 'annotationComment' => 'Verify against prototype; the ohmic region sits beyond the path between separate loads', 'annotationPageLabel' => 'iv', 'annotationPosition' => '{"pageIndex":5}', 'annotationColor' => '#ffd400', 'annotationType' => 'highlight', 'dateAdded' => '2026-10-18T11:25:00Z']),
        object('SOLO0001', ['itemType' => 'attachment', 'title' => 'Standalone thermal note', 'contentType' => 'application/pdf']),
        object('ANN00002', ['itemType' => 'annotation', 'parentItem' => 'SOLO0001', 'annotationText' => '', 'annotationComment' => 'Thermal sketch: the note is between the ohmic region and the load.', 'annotationType' => 'image']),
        object('NOFILE01', ['itemType' => 'report', 'title' => 'Metadata-only magnetics']),
        object('TRASH001', ['itemType' => 'report', 'title' => 'Hidden trashed report', 'deleted' => 1]),
        object('TRASHPDF', ['itemType' => 'attachment', 'parentItem' => 'TRASH001', 'contentType' => 'application/pdf']),
        object('TRASHANN', ['itemType' => 'annotation', 'parentItem' => 'TRASHPDF', 'annotationText' => 'Hidden trashed highlight']),
        object('ORPHAN01', ['itemType' => 'annotation', 'parentItem' => 'MISSING1', 'annotationText' => 'Hidden orphan']),
    ];
    $index = new Index($root . '/index');
    $index->publish('users/123', 1, $collections, $items, [], true);
    verify($index->status()['counts'] === ['document' => 3, 'annotation' => 2], 'Wrong initial counts');
    $annotation = $index->search('short circuit', 'annotation')['results'][0];
    verify($annotation['pageLabel'] === 'iv' && $annotation['page'] === 6, 'Printed and physical pages confused');
    verify($annotation['openUrl'] === 'zotero://open-pdf/library/items/PDF00001?page=6&annotation=ANN00001', 'Wrong annotation target');
    verify($annotation['text'] === 'Short-circuit protection & timing: VGS<6V; VDS>400V', 'Engineering annotation text was altered');
    verify($annotation['dateAdded'] === '2026-10-18T11:25:00Z', 'Annotation date added was not preserved');
    verify(count($index->search('between the ohmic region', 'annotation')['results']) === 2, 'All-words mode did not match terms in any order');
    $phraseMatches = $index->search('between the ohmic region', 'annotation', 20, 0, 'phrase')['results'];
    verify(count($phraseMatches) === 1 && $phraseMatches[0]['key'] === 'ANN00002', 'Exact phrase mode did not require adjacent words in order');
    verify($annotation['collections'] === ['Power electronics / Gate drivers'], 'Nested collections lost');
    verify(count($index->search('prototype')['results']) === 1, 'Comments not indexed');
    verify(count($index->search('IGBT')['results']) === 2, 'Parent tags not indexed on annotations');
    verify(count($index->search('Semiconductor')['results']) === 2, 'Creators not indexed');
    verify(count($index->search('sketch')['results']) === 1, 'Textless annotations lost');
    $migrationDb = new PDO('sqlite:' . $root . '/index/index.sqlite');
    $migrationDb->exec('PRAGMA user_version=2');
    $migratedIndex = new Index($root . '/index');
    verify($migratedIndex->search('short', 'annotation')['results'][0]['dateAdded'] === '2026-10-18T11:25:00Z', 'Schema migration did not backfill annotation date added');
    verify((int)$migrationDb->query('PRAGMA user_version')->fetchColumn() === 3, 'Schema migration did not advance to version 3');
    verify($index->search('Metadata')['results'][0]['openUrl'] === null, 'Metadata-only document pretends to have PDF');
    verify($index->search('Standalone', 'document')['results'][0]['attachments'][0]['key'] === 'SOLO0001', 'Standalone PDF target missing');
    verify($index->search('Hidden')['results'] === [], 'Trashed/orphan data leaked');
    verify($index->search('" OR * : -')['results'] === [], 'FTS syntax was interpreted');
    fails(static function () use ($index) { $index->search('gate', 'invalid'); }, 'Invalid result kind');
    fails(static function () use ($index) { $index->search('gate', 'all', 20, 0, 'invalid'); }, 'Invalid search mode');
    fails(static function () use ($index) { $index->search(str_repeat('x', 513)); }, '512');
    verify($index->search('gate', 'all', 1)['hasMore'], 'Pagination missing');
    $index->publish('users/123', 2, [], [object('PDF00002', ['itemType' => 'attachment', 'parentItem' => 'DOC00001', 'title' => 'Second PDF', 'contentType' => 'application/pdf'], 2)], [], false);
    $doc = $index->search('SiC', 'document')['results'][0];
    verify(count($doc['attachments']) === 2 && $doc['openUrl'] === null, 'Multiple PDFs silently chose one');
    $index->publish('users/123', 3, [object('COLL0002', ['name' => 'Protection', 'parentCollection' => 'COLL0001'], 3)], [object('DOC00001', ['itemType' => 'report', 'title' => 'Renamed desaturation guide', 'collections' => ['COLL0002']], 3)], [], false);
    verify($index->search('desaturation', 'annotation')['results'][0]['title'] === 'Renamed desaturation guide', 'Parent edit did not refresh annotations');
    verify($index->search('short')['results'][0]['collections'] === ['Power electronics / Protection'], 'Collection edit did not refresh paths');
    $before = $index->status();
    fails(static function () use ($index) { $index->publish('users/123', 99, [], [['key' => 'bad', 'data' => []]], [], true); }, 'invalid object');
    verify($index->status() === $before && count($index->search('short')['results']) === 1, 'Failed publication damaged previous index');
    $index->publish('users/123', 4, [], [object('DOC00001', ['itemType' => 'report', 'title' => 'Trashed', 'deleted' => 1], 4)], [], false);
    verify($index->search('short')['results'] === [], 'Parent trash did not hide annotation');
    $index->publish('users/123', 5, [], [object('DOC00001', ['itemType' => 'report', 'title' => 'Restored desaturation guide'], 5)], [], false);
    verify(count($index->search('short')['results']) === 1, 'Restored parent did not restore annotation');
    $index->publish('users/123', 6, [], [], ['items' => ['PDF00001']], false);
    verify($index->search('short')['results'] === [], 'Deleted attachment left searchable annotation');
    $lock = $index->acquireLock();
    fails(static function () use ($index) { $index->acquireLock(); }, 'already running');
    flock($lock, LOCK_UN); fclose($lock);

    // Exercise the actual sync protocol, including the library version checks.
    $syncIndex = new Index($root . '/sync');
    $remoteVersion = 10;
    $remoteItems = $items;
    $remoteCollections = $collections;
    $deleted = ['items' => [], 'collections' => []];
    $notModified = false;
    $race = false;
    $incomplete = false;
    $requests = [];
    $transport = static function ($url, $headers) use (&$requests, &$remoteVersion, &$remoteItems, &$remoteCollections, &$deleted, &$notModified, &$race, &$incomplete, $key) {
        verify($headers['Zotero-API-Key'] === $key && strpos($url, $key) === false, 'Credentials in URL or missing authentication');
        $requests[] = $url;
        $path = parse_url($url, PHP_URL_PATH);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if ($path === '/keys/current') return response(['userID' => 123, 'access' => ['user' => ['library' => true]]]);
        $type = basename($path);
        if ($type === 'items' && $notModified && isset($headers['If-Modified-Since-Version'])) return response(null, $remoteVersion, 304);
        $version = $race && $type === 'collections' ? $remoteVersion + 1 : $remoteVersion;
        if ($type === 'deleted') return response($deleted, $version);
        $objects = $type === 'collections' ? $remoteCollections : $remoteItems;
        if (($query['format'] ?? '') === 'versions') {
            $map = [];
            foreach ($objects as $obj) if ($obj['version'] > (int)$query['since']) $map[$obj['key']] = $obj['version'];
            return response($map, $version);
        }
        $keys = explode(',', $query[$type === 'items' ? 'itemKey' : 'collectionKey']);
        $batch = array_values(array_filter($objects, static function ($obj) use ($keys) { return in_array($obj['key'], $keys, true); }));
        if ($incomplete && $type === 'items') array_pop($batch);
        return response($batch, $version);
    };
    (new Sync(new Api($key, $transport), $syncIndex))->run();
    verify($syncIndex->state('version') === 10 && $syncIndex->status()['counts']['annotation'] === 2, 'Full sync failed');
    $generation = $syncIndex->state('generation');
    $notModified = true;
    (new Sync(new Api($key, $transport), $syncIndex))->run();
    verify($syncIndex->state('generation') === $generation && $syncIndex->state('last_checked') > 0, '304 changed generation or failed freshness');
    $notModified = false;
    $remoteVersion = 11;
    $remoteItems = [object('ANN00001', ['itemType' => 'annotation', 'parentItem' => 'PDF00001', 'annotationText' => 'Updated highlight'], 11)];
    $remoteCollections = [];
    $deleted = ['items' => ['ANN00002'], 'collections' => []];
    (new Sync(new Api($key, $transport), $syncIndex))->run();
    verify(count($syncIndex->search('Updated')['results']) === 1 && $syncIndex->search('sketch')['results'] === [], 'Increment/deletion sync failed');
    $race = true;
    fails(static function () use ($key, $transport, $syncIndex) { (new Sync(new Api($key, $transport), $syncIndex))->run(); }, 'changed during');
    verify($syncIndex->state('version') === 11 && count($syncIndex->search('Updated')['results']) === 1, 'Version race damaged snapshot');
    $race = false;
    $incomplete = true;
    fails(static function () use ($key, $transport, $syncIndex) { (new Sync(new Api($key, $transport), $syncIndex))->run(true); }, 'incomplete batch');
    verify($syncIndex->state('version') === 11, 'Incomplete batch advanced version');
    $blockedApi = new Api($key, static function () { return response(null, 1, 429, ['Retry-After' => '120']); });
    fails(static function () use ($blockedApi) { $blockedApi->identity(); }, 'rate-limited');
    verify($blockedApi->retryAt() >= time() + 119, 'Retry-After not honored');
    fails(static function () use ($blockedApi) { $blockedApi->identity(); }, 'requested a pause');
    $backoffIndex = new Index($root . '/backoff');
    $backoffApi = new Api($key, static function () { return response(['userID' => 123, 'access' => ['user' => ['library' => true]]], 1, 200, ['Backoff' => '60']); });
    fails(static function () use ($backoffApi, $backoffIndex) { (new Sync($backoffApi, $backoffIndex))->run(); }, 'requested a pause');
    verify($backoffIndex->state('retry_at') >= time() + 59, 'Successful-response Backoff not persisted');
    fails(static function () use ($key) { (new Api($key, static function () { return response(['userID' => 123, 'access' => ['user' => []]]); }))->identity(); }, 'read access');
    fails(static function () use ($key) { (new Api($key, static function () { return response([], 1, 403); }))->identity(); }, 'rejected');
    // A large import resumes across distinct requests instead of sharing a
    // single request's time budget. Until verification, the old index stays live.
    $largeItems = [];
    for ($i = 1; $i <= 1201; $i++) $largeItems[] = object(sprintf('X%07d', $i), ['itemType' => 'report', 'title' => 'Large fixture document ' . $i]);
    $downloads = [];
    $changeDuringVerification = false;
    $largeTransport = static function ($url) use ($largeItems, &$downloads, &$changeDuringVerification) {
        $path = parse_url($url, PHP_URL_PATH);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if ($path === '/keys/current') return response(['userID' => 456, 'access' => ['user' => ['library' => true]]]);
        if (basename($path) === 'collections') return response([]);
        if (($query['format'] ?? '') === 'versions') {
            if ((int)$query['since'] >= 1) return response([], $changeDuringVerification ? 2 : 1);
            return response(array_fill_keys(array_column($largeItems, 'key'), 1));
        }
        $keys = explode(',', $query['itemKey']);
        foreach ($keys as $itemKey) $downloads[$itemKey] = ($downloads[$itemKey] ?? 0) + 1;
        return response(array_values(array_filter($largeItems, static function ($obj) use ($keys) { return in_array($obj['key'], $keys, true); })));
    };
    $largeDirectory = $root . '/large';
    $largeIndex = new Index($largeDirectory);
    $largeIndex->publish('users/456', 0, [], [object('OLD00001', ['itemType' => 'report', 'title' => 'Previous committed content'])], [], true);
    $beforeGeneration = $largeIndex->state('generation');
    $largeJob = (new Sync(new Api($key, $largeTransport), $largeIndex))->start(true);
    verify($largeJob['job']['totalItems'] === 1201, 'Large job did not record the work plan');
    verify(strpos(json_encode($largeJob['job']), 'X0000001') === false, 'Job status exposed private object keys');
    (new Sync(new Api($key, $largeTransport), new Index($largeDirectory)))->step();
    verify($largeIndex->jobStatus()['processedItems'] === 50, 'First batch progress was not durable');
    fails(static function () use ($key, $largeTransport, $largeDirectory) { (new Sync(new Api($key, $largeTransport, 0), new Index($largeDirectory)))->step(); }, 'time limit');
    verify($largeIndex->jobStatus()['phase'] === 'paused' && $largeIndex->jobStatus()['processedItems'] === 50, 'Timed-out job lost its checkpoint');
    verify(count($largeIndex->search('Previous')['results']) === 1 && $largeIndex->state('generation') === $beforeGeneration, 'Partial job replaced the committed index');
    (new Sync(new Api($key, $largeTransport), new Index($largeDirectory)))->start();
    while (!in_array($largeIndex->jobStatus()['phase'], ['completed', 'verify'], true)) (new Sync(new Api($key, $largeTransport), new Index($largeDirectory)))->step();
    verify($largeIndex->jobStatus()['processedItems'] === 1201 && count($downloads) === 1201 && max($downloads) === 1, 'Resume redownloaded saved batches or skipped items');
    $changeDuringVerification = true;
    fails(static function () use ($key, $largeTransport, $largeDirectory) { (new Sync(new Api($key, $largeTransport), new Index($largeDirectory)))->step(); }, 'changed during');
    verify($largeIndex->jobStatus()['phase'] === 'failed' && $largeIndex->state('generation') === $beforeGeneration, 'Concurrent remote change published a partial snapshot');
    $changeDuringVerification = false;
    (new Sync(new Api($key, $largeTransport), new Index($largeDirectory)))->run(true);
    verify($largeIndex->status()['counts']['document'] === 1201 && $largeIndex->jobStatus()['phase'] === 'completed', 'Large import did not complete');
    verify($largeIndex->search('Previous')['results'] === [] && $largeIndex->state('generation') === $beforeGeneration + 1, 'Large import publication was not atomic');
    $migrationDb = new PDO('sqlite:' . $largeDirectory . '/index.sqlite');
    $migrationDb->exec('DROP TABLE staging');
    $migrationDb->exec('PRAGMA user_version=1');
    $migrated = new Index($largeDirectory);
    verify($migrated->status()['counts']['document'] === 1201, 'Schema migration discarded existing indexed documents');
    verify((int)$migrationDb->query('PRAGMA user_version')->fetchColumn() === 3, 'Schema migration did not advance the version');
    echo "Passed $checks backend checks.\n";
} finally {
    if ($oldEnvironment === false) putenv('ZOTERO_API_KEY'); else putenv('ZOTERO_API_KEY=' . $oldEnvironment);
    if ($oldPushoverToken === false) putenv('PUSHOVER_APP_TOKEN'); else putenv('PUSHOVER_APP_TOKEN=' . $oldPushoverToken);
    if ($oldPushoverUser === false) putenv('PUSHOVER_USER_KEY'); else putenv('PUSHOVER_USER_KEY=' . $oldPushoverUser);
    cleanup($root);
}
