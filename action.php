<?php
if (!defined('DOKU_INC')) die();

class action_plugin_zoterobridge extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'ajax');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'adminScript');
    }

    public function adminScript(Doku_Event $event, $param)
    {
        global $ACT, $INPUT;
        if ($ACT !== 'admin' || $INPUT->str('page') !== 'zoterobridge' || !auth_isadmin()) return;
        $event->data['script'][] = ['type' => 'text/javascript', 'src' => DOKU_BASE . 'lib/plugins/zoterobridge/admin.js', 'defer' => 'defer'];
    }

    public function ajax(Doku_Event $event, $param)
    {
        if (!in_array($event->data, ['zoterobridge_search', 'zoterobridge_status', 'zoterobridge_sync'], true)) return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        $helper = plugin_load('helper', 'zoterobridge');
        if (!$helper || !$helper->canRead()) {
            http_response_code(403);
            echo json_encode(['error' => 'Administrator access required.']);
            return;
        }
        global $INPUT;
        try {
            if ($event->data === 'zoterobridge_sync') {
                if ($INPUT->server->str('REQUEST_METHOD') !== 'POST' || !checkSecurityToken()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'A POST request and valid security token are required.']);
                    return;
                }
                $operation = $INPUT->post->str('operation');
                if (!in_array($operation, ['start', 'rebuild', 'step'], true)) throw new InvalidArgumentException('Invalid synchronization operation.');
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                @set_time_limit(60);
                $sync = $helper->syncService();
                $data = $operation === 'step' ? $sync->step() : $sync->start($operation === 'rebuild');
                echo json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG);
                return;
            }
            $index = $helper->index();
            if ($event->data === 'zoterobridge_status') {
                $data = $index->status();
                $data['automaticSync'] = (bool)$helper->getConf('automatic_sync', 1);
                $data['freshnessHours'] = max(1, min(168, (int)$helper->getConf('freshness_hours', 2)));
            } else {
                $data = $index->search($INPUT->get->str('q'), $INPUT->get->str('kind', 'all'), $INPUT->get->int('limit', 20), $INPUT->get->int('offset', 0), $INPUT->get->str('match', 'words'));
            }
            echo json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
        } catch (\ZoteroBridge\SyncBusyException $error) {
            http_response_code(409);
            echo json_encode($helper->index()->status() + ['busy' => true], JSON_HEX_TAG);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error' => $error->getMessage()]);
        } catch (Throwable $error) {
            http_response_code(503);
            $message = $error instanceof \ZoteroBridge\ApiException || ($event->data === 'zoterobridge_sync' && $error instanceof RuntimeException && !$error instanceof PDOException) ? $error->getMessage() : 'Zotero is unavailable. Check Zotero Bridge administration.';
            echo json_encode(['error' => $message]);
        }
    }
}
