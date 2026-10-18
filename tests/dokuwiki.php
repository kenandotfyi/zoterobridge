<?php
/** Uses only an isolated DokuWiki test installation; never supply a production root. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('DOKUWIKI_TEST_ROOT');
if (!$root || !is_file($root . '/inc/init.php') || !str_contains(realpath($root), 'zoterobridge-runtime')) {
    fwrite(STDERR, "Set DOKUWIKI_TEST_ROOT to the isolated zoterobridge-runtime installation.\n"); exit(2);
}
define('DOKU_INC', rtrim($root, '/') . '/');
define('NOSESSION', 1);
require DOKU_INC . 'inc/init.php';
$checks = 0;
function verify($value, $message) { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function ajax($action, $name) {
    $event = new Doku_Event('AJAX_CALL_UNKNOWN', $name);
    ob_start(); $action->ajax($event, null); $body = ob_get_clean();
    return [http_response_code(), json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}
$auth = plugin_load('auth', 'authplain');
$helper = plugin_load('helper', 'zoterobridge');
$action = plugin_load('action', 'zoterobridge');
$admin = plugin_load('admin', 'zoterobridge');
verify($helper && $action && $admin, 'Plugin components were not discovered');
verify($admin->forAdminOnly(), 'Admin page is not admin-only');
$configuration = new dokuwiki\plugin\config\core\Configuration();
$settings = $configuration->getSettings();
$setting = $settings['plugin____zoterobridge____api_key'] ?? null;
verify($setting instanceof dokuwiki\plugin\config\core\Setting\SettingPassword, 'API key setting must be masked');
$fakeKey = str_repeat('A', 24);
$conf['plugin']['zoterobridge']['api_key'] = $fakeKey;
verify($helper->credentials()['key'] === $fakeKey, 'Local configuration not read');
putenv('ZOTERO_API_KEY=' . str_repeat('B', 24));
verify($helper->credentials()['source'] === 'environment', 'Environment did not override local key');
putenv('ZOTERO_API_KEY');
$ID = 'start';
$INPUT->server->set('REMOTE_USER', 'admin');
$USERINFO = ['grps' => ['admin', 'user']];
auth_isadmin(null, null, true);
verify($helper->canRead(), 'Admin cannot read the index');
$index = $helper->index();
$index->publish('users/123', 1, [], [
    ['key' => 'DOC00001', 'data' => ['itemType' => 'report', 'title' => 'Fixture gate driver guide']],
    ['key' => 'PDF00001', 'data' => ['itemType' => 'attachment', 'parentItem' => 'DOC00001', 'title' => 'Fixture guide.pdf', 'contentType' => 'application/pdf']],
    ['key' => 'ANN00001', 'data' => ['itemType' => 'annotation', 'parentItem' => 'PDF00001', 'annotationType' => 'highlight', 'annotationText' => 'Short-circuit timing <script>alert(1)</script>', 'annotationComment' => 'Check prototype', 'annotationPageLabel' => 'iv', 'annotationPosition' => '{"pageIndex":5}']],
], [], true);
$INPUT->get->set('q', 'prototype');
[$code, $data] = ajax($action, 'zoterobridge_search');
verify(($code === 200 || $code === false) && count($data['results']) === 1, 'Admin AJAX search failed');
verify(strpos(json_encode($data), $fakeKey) === false, 'Search leaked credentials');
[$code, $data] = ajax($action, 'zoterobridge_status');
verify($data['counts']['document'] === 1 && $data['counts']['annotation'] === 1, 'Admin status failed');
verify($data['automaticSync'] === true && $data['freshnessHours'] === 2, 'Automatic sync defaults are missing from status');
$INPUT->get->set('kind', 'bad');
[$code, $data] = ajax($action, 'zoterobridge_search');
verify($code === 400 && isset($data['error']), 'Invalid search filter not rejected');
$INPUT->get->set('kind', 'all');
$INPUT->get->set('zotero_query', 'prototype');
ob_start(); $admin->html(); $html = ob_get_clean();
verify(strpos($html, $fakeKey) === false && strpos($html, 'value=""') !== false, 'Admin page printed a saved key');
verify(strpos($html, '<script>alert(1)</script>') === false && strpos($html, 'Check prototype') !== false, 'Preview escaping or rendering failed');
putenv('ZOTERO_API_KEY=' . str_repeat('B', 24));
ob_start(); $admin->html(); $html = ob_get_clean();
verify(strpos($html, 'Environment variable: ZOTERO_API_KEY') !== false && strpos($html, 'name="api_key"') === false, 'Deployment key did not hide local editing');
verify(strpos($html, str_repeat('B', 24)) === false, 'Environment key was displayed');
$beforeConfig = file_get_contents(DOKU_INC . 'conf/local.php');
$INPUT->post->set('zoterobridge_action', 'save');
$INPUT->post->set('api_key', str_repeat('C', 24));
$INPUT->set('sectok', getSecurityToken());
$admin->handle();
verify(file_get_contents(DOKU_INC . 'conf/local.php') === $beforeConfig, 'Deployment key was overridden by forged local save');
putenv('ZOTERO_API_KEY');
$INPUT->set('sectok', 'invalid-fixture-token');
$admin->handle();
verify(file_get_contents(DOKU_INC . 'conf/local.php') === $beforeConfig, 'Missing/invalid CSRF token allowed a key update');
$INPUT->post->remove('zoterobridge_action');
$INPUT->server->set('REQUEST_METHOD', 'GET');
[$code, $data] = ajax($action, 'zoterobridge_sync');
verify($code === 403, 'Sync accepted a GET request');
$INPUT->server->set('REQUEST_METHOD', 'POST');
[$code, $data] = ajax($action, 'zoterobridge_sync');
verify($code === 403, 'Sync accepted an invalid CSRF token');
$INPUT->set('sectok', getSecurityToken());
$INPUT->post->set('operation', 'invalid');
[$code, $data] = ajax($action, 'zoterobridge_sync');
verify($code === 400, 'Sync accepted an invalid operation');
$INPUT->post->set('operation', 'step');
$syncLock = $index->acquireLock();
try {
    [$code, $data] = ajax($action, 'zoterobridge_sync');
    verify($code === 409 && !empty($data['busy']), 'Overlapping requests were not reported as retryable lock contention');
} finally { flock($syncLock, LOCK_UN); fclose($syncLock); }
$INPUT->server->set('REMOTE_USER', 'reader');
$USERINFO = ['grps' => ['user']];
auth_isadmin(null, null, true);
verify(!$helper->canRead(), 'Normal reader can access private Zotero data');
foreach (['zoterobridge_search', 'zoterobridge_status', 'zoterobridge_sync'] as $name) {
    [$code, $data] = ajax($action, $name);
    verify($code === 403 && !isset($data['results']) && !isset($data['counts']), 'Unauthorized endpoint exposed data');
}
$INPUT->server->set('REMOTE_USER', '');
$USERINFO = [];
auth_isadmin(null, null, true);
[$code, $data] = ajax($action, 'zoterobridge_search');
verify($code === 403, 'Anonymous search was permitted');
echo "Passed $checks DokuWiki integration checks.\n";
