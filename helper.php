<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/bootstrap.php';

class helper_plugin_zoterobridge extends DokuWiki_Plugin
{
    public function credentials() { return \ZoteroBridge\Credentials::resolve($this->getConf('api_key')); }

    public function api($key = null, $budget = 90)
    {
        return new \ZoteroBridge\Api($key ?? $this->credentials()['key'], null, $budget);
    }

    public function index()
    {
        global $conf;
        $key = $this->credentials()['key'];
        if (!\ZoteroBridge\Credentials::valid($key)) throw new RuntimeException('Configure a Zotero API key first.');
        // A changed key cannot expose the previous key's library, even before re-sync.
        $directory = rtrim($conf['metadir'], '/') . '/zoterobridge/' . hash('sha256', $key);
        return new \ZoteroBridge\Index($directory);
    }

    public function sync($full = false, $budget = 90)
    {
        return (new \ZoteroBridge\Sync($this->api(null, $budget), $this->index()))->run($full);
    }

    public function syncService($budget = 45)
    {
        return new \ZoteroBridge\Sync($this->api(null, $budget), $this->index());
    }

    /** Private Zotero libraries are admin-only in this first implementation. */
    public function canRead() { return auth_isadmin(); }
}
