<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/bootstrap.php';

use dokuwiki\plugin\config\core\Configuration;

class admin_plugin_zoterobridge extends DokuWiki_Admin_Plugin
{
    private $notice = '';
    private $error = '';

    public function forAdminOnly() { return true; }
    public function getMenuText($language) { return 'Zotero Bridge'; }
    public function getMenuSort() { return 145; }

    private function url()
    {
        global $ID;
        return wl($ID, ['do' => 'admin', 'page' => 'zoterobridge'], true, '&');
    }

    private function setting(Configuration $configuration)
    {
        $settings = $configuration->getSettings();
        return $settings['plugin____zoterobridge____api_key'] ?? null;
    }

    public function handle()
    {
        global $INPUT;
        if (!auth_isadmin() || !$INPUT->post->has('zoterobridge_action')) return;
        if (!checkSecurityToken()) { $this->error = 'The security token expired. Reload and try again.'; return; }
        $helper = plugin_load('helper', 'zoterobridge');
        $credentials = $helper->credentials();
        $action = $INPUT->post->str('zoterobridge_action');
        try {
            if ($action === 'save') {
                if (in_array($credentials['source'], ['secret', 'environment'], true)) throw new RuntimeException('The deployment manages your key. Change it in the secret or ZOTERO_API_KEY environment variable.');
                $key = trim($INPUT->post->str('api_key'));
                $clear = $INPUT->post->bool('clear_api_key');
                if ($clear && $key !== '') throw new RuntimeException('Choose either a replacement key or Remove saved key.');
                if ($key !== '' && !\ZoteroBridge\Credentials::valid($key)) throw new RuntimeException('Enter a valid Zotero API key (16–128 letters and digits).');
                $configuration = new Configuration();
                $setting = $this->setting($configuration);
                if ($configuration->isLocked() || !$setting || $setting->isProtected()) throw new RuntimeException('The Zotero API key setting is locked or unavailable.');
                if ($key === '' && !$clear) { $this->notice = 'No changes. Your saved key is unchanged.'; return; }
                // DokuWiki password settings ignore empty update() values. Reset through
                // their public initialization API after the protection check above.
                if ($clear) { $setting->initialize('', '', null); $changed = true; }
                else $changed = $setting->update($key);
                if ($setting->hasError()) throw new RuntimeException('The API key setting could not be saved.');
                if ($changed) $configuration->save();
                msg($clear ? 'Saved Zotero key removed.' : 'Zotero API key saved.', 1);
                send_redirect($this->url());
            } elseif ($action === 'save_sync_settings') {
                $configuration = new Configuration();
                $settings = $configuration->getSettings();
                $automatic = $settings['plugin____zoterobridge____automatic_sync'] ?? null;
                $freshness = $settings['plugin____zoterobridge____freshness_hours'] ?? null;
                $hours = $INPUT->post->int('freshness_hours');
                if ($configuration->isLocked() || !$automatic || !$freshness || $automatic->isProtected() || $freshness->isProtected()) throw new RuntimeException('The automatic synchronization settings are locked or unavailable.');
                if ($hours < 1 || $hours > 168) throw new RuntimeException('Choose a freshness threshold from 1 to 168 hours.');
                $automatic->update($INPUT->post->bool('automatic_sync') ? '1' : '0');
                $freshness->update((string)$hours);
                if ($automatic->hasError() || $freshness->hasError()) throw new RuntimeException('The automatic synchronization settings could not be saved.');
                $configuration->save();
                msg('Automatic synchronization settings saved.', 1);
                send_redirect($this->url());
            } elseif ($action === 'test') {
                $key = $credentials['key'];
                // As with Todoist, test a replacement before saving it locally.
                if (!in_array($credentials['source'], ['secret', 'environment'], true)) {
                    $candidate = trim($INPUT->post->str('api_key'));
                    if ($candidate !== '') $key = $candidate;
                }
                $identity = $helper->api($key)->identity();
                $this->notice = 'Connection successful. Personal library user ID: ' . $identity['userID'] . '. The key was not changed.';
            } elseif ($action === 'sync' || $action === 'rebuild') {
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                @set_time_limit(60);
                $status = $helper->syncService()->start($action === 'rebuild');
                $this->notice = $status['job'] ? 'Synchronization is ready to continue. Keep this page open to process the remaining batches.' : 'Your Zotero index is already up to date.';
            } else {
                throw new RuntimeException('Unknown Zotero action.');
            }
        } catch (Throwable $error) {
            // Network exceptions never contain the API key. Keep internal SQL/path errors private.
            $this->error = $error instanceof \ZoteroBridge\ApiException || ($error instanceof RuntimeException && !($error instanceof PDOException)) ? $error->getMessage() : 'Zotero operation failed. Check PHP SQLite support and metadata-directory permissions.';
        }
    }

    public function html()
    {
        if (!auth_isadmin()) { echo '<p>Administrator access required.</p>'; return; }
        global $INPUT;
        $helper = plugin_load('helper', 'zoterobridge');
        $credentials = $helper->credentials();
        $deployment = in_array($credentials['source'], ['secret', 'environment'], true);
        $configuration = new Configuration();
        $setting = $this->setting($configuration);
        $locked = $configuration->isLocked() || !$setting || $setting->isProtected();
        echo '<div class="zoterobridge-admin"><h1>Zotero Bridge</h1><p>Search a local copy of your Zotero documents and PDF annotations. Zotero remains the source of truth.</p>';
        foreach ([['error', $this->error], ['success', $this->notice]] as [$class, $message]) if ($message !== '') echo '<p class="' . $class . '" role="status">' . hsc($message) . '</p>';
        echo '<h2>Connection</h2>';
        $sources = ['secret' => 'Docker secret: /run/secrets/zotero_api_key', 'environment' => 'Environment variable: ZOTERO_API_KEY', 'configuration' => 'Saved admin configuration', 'missing' => 'No API key configured'];
        echo '<p>' . hsc($sources[$credentials['source']]) . '</p><p>Create a dedicated API key with read access to your personal library in <a href="https://www.zotero.org/settings/keys">Zotero API key settings</a>. Write access and file access are unnecessary. Your library user ID is detected automatically.</p>';
        $this->formStart();
        if ($deployment) {
            echo '<p>Your deployment supplies the API key. Update it through your server environment variables or the Docker secret.</p>';
        } else {
            echo '<p><label for="zoterobridge-api-key">API key</label><br><input id="zoterobridge-api-key" type="password" name="api_key" value="" maxlength="128" autocomplete="new-password" spellcheck="false"' . ($locked ? ' disabled' : '') . '></p><p>Leave blank to keep the saved key. A configured key is never displayed.</p>';
            if ($credentials['source'] === 'configuration') echo '<p><label><input type="checkbox" name="clear_api_key" value="1"' . ($locked ? ' disabled' : '') . '> Remove saved key</label></p>';
            echo '<button type="submit" class="button" name="zoterobridge_action" value="save"' . ($locked ? ' disabled' : '') . '>Save key</button> ';
        }
        echo '<button type="submit" class="button" name="zoterobridge_action" value="test">Test connection</button></form>';
        echo '<h2>Local index</h2><p>PHP SQLite: ' . (extension_loaded('pdo_sqlite') ? 'available' : 'missing — enable pdo_sqlite') . '. Search currently requires wiki administrator access.</p>';
        if ($credentials['key'] !== '') {
            try {
                $index = $helper->index();
                $status = $index->status();
                $automaticSync = (bool)$helper->getConf('automatic_sync', 1);
                $freshnessHours = max(1, min(168, (int)$helper->getConf('freshness_hours', 2)));
                echo '<dl><dt>Library</dt><dd data-zoterobridge-library>' . hsc($status['library'] ?? 'Not synchronized') . '</dd><dt>Documents / annotations</dt><dd data-zoterobridge-counts>' . $status['counts']['document'] . ' / ' . $status['counts']['annotation'] . '</dd><dt>Last successful check</dt><dd data-zoterobridge-checked>' . hsc($status['lastChecked'] ? date('Y-m-d H:i:s T', $status['lastChecked']) : 'Never') . '</dd><dt>Library version</dt><dd data-zoterobridge-version>' . (int)$status['version'] . '</dd></dl>';
                if ($status['lastError']) echo '<p class="error" data-zoterobridge-last-error>' . hsc($status['lastError']) . '</p>';
                if ($status['retryAt'] > time()) echo '<p>Retry after ' . hsc(date('Y-m-d H:i:s T', $status['retryAt'])) . '.</p>';
                echo '<div data-zoterobridge-job role="status" aria-live="polite"></div>';
                $this->formStart('data-zoterobridge-sync');
                echo '<p><button type="submit" class="button" name="zoterobridge_action" value="sync">Synchronize now</button> <button type="submit" class="button" name="zoterobridge_action" value="rebuild">Rebuild from Zotero</button></p></form><p>Keep this page open while synchronizing. Each batch is saved, and reopening resumes it. The searchable index updates after all batches are verified.</p>';
                $settings = $configuration->getSettings();
                $autoSetting = $settings['plugin____zoterobridge____automatic_sync'] ?? null;
                $hoursSetting = $settings['plugin____zoterobridge____freshness_hours'] ?? null;
                $syncSettingsLocked = $configuration->isLocked() || !$autoSetting || !$hoursSetting || $autoSetting->isProtected() || $hoursSetting->isProtected();
                echo '<h2>Automatic synchronization</h2><form method="post" action="' . hsc($this->url()) . '">';
                formSecurityToken();
                echo '<input type="hidden" name="zoterobridge_action" value="save_sync_settings"><p><label><input type="checkbox" name="automatic_sync" value="1"' . ($automaticSync ? ' checked' : '') . ($syncSettingsLocked ? ' disabled' : '') . '> Check Zotero automatically when the index is stale</label></p><p><label for="zoterobridge-freshness">Check again after</label> <input id="zoterobridge-freshness" type="number" name="freshness_hours" min="1" max="168" value="' . $freshnessHours . '"' . ($syncSettingsLocked ? ' disabled' : '') . '> hours</p><p><button type="submit" class="button"' . ($syncSettingsLocked ? ' disabled' : '') . '>Save sync settings</button></p></form>';
                echo '<p>When the Fokus search palette opens, a stale local index is checked in the background. Search keeps using the current SQLite index, and new results appear after a changed library is published.</p><p>For a daily check at 03:00, add a server scheduled task that runs from the DokuWiki root: <code>php lib/plugins/zoterobridge/bin/sync.php --scheduled</code>. Set its schedule to <code>0 3 * * *</code> and its timezone to your desired local timezone. This runs an incremental Zotero check and sends a Pushover alert if it fails. Set <code>PUSHOVER_APP_TOKEN</code> and <code>PUSHOVER_USER_KEY</code> on the scheduled task to enable alerts. Use Rebuild from Zotero only when you need a full import.</p>';
                $this->preview($index);
            } catch (Throwable $error) {
                echo '<p class="error">Zotero indexing is unavailable. Check the key, PHP SQLite/FTS5 support, and metadata-directory permissions.</p>';
            }
        }
        echo '</div>';
    }

    private function formStart($attribute = '')
    {
        echo '<form method="post" action="' . hsc($this->url()) . '" ' . $attribute . '>';
        formSecurityToken();
    }

    private function preview(\ZoteroBridge\Index $index)
    {
        global $ID, $INPUT;
        $query = $INPUT->get->str('zotero_query');
        echo '<h2>Try local search</h2><form method="get" action="' . hsc(script()) . '"><input type="hidden" name="id" value="' . hsc($ID) . '"><input type="hidden" name="do" value="admin"><input type="hidden" name="page" value="zoterobridge"><p><label for="zoterobridge-query">Search documents, highlights, and comments</label><br><input id="zoterobridge-query" type="search" name="zotero_query" maxlength="512" value="' . hsc($query) . '"> <button class="button" type="submit">Search local index</button></p></form>';
        if (trim($query) === '') return;
        $result = $index->search($query);
        if (!$result['results']) echo '<p>No matching documents or annotations.</p>';
        foreach ($result['results'] as $row) {
            echo '<article><h3><a href="' . hsc($row['openUrl'] ?? $row['selectUrl']) . '">' . hsc($row['title']) . '</a></h3><p>' . hsc(ucfirst($row['kind']) . ' · ' . implode(', ', $row['creators']) . ' · ' . $row['date']) . '</p>';
            if ($row['kind'] === 'annotation') echo '<blockquote>' . nl2br(hsc($row['text'])) . '</blockquote><p>Page ' . hsc($row['pageLabel']) . '</p>' . ($row['comment'] !== '' ? '<p><strong>Comment:</strong> ' . nl2br(hsc($row['comment'])) . '</p>' : '');
            if ($row['collections']) echo '<p>' . hsc(implode(' · ', $row['collections'])) . '</p>';
            if (count($row['attachments']) > 1) {
                echo '<ul>';
                foreach ($row['attachments'] as $pdf) echo '<li><a href="' . hsc($pdf['openUrl']) . '">' . hsc($pdf['title']) . '</a></li>';
                echo '</ul>';
            }
            echo '</article>';
        }
        if ($result['hasMore']) echo '<p>Showing the first 20 matches. Refine your query for more specific results.</p>';
    }
}
