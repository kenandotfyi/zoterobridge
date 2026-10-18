<?php
namespace ZoteroBridge;

final class Credentials
{
    public static function valid($key)
    {
        return is_string($key) && preg_match('/\A[a-zA-Z0-9]{16,128}\z/D', $key) === 1;
    }

    /** Never return this value to the browser or include it in an exception. */
    public static function resolve($fallback = '', $secretPath = '/run/secrets/zotero_api_key')
    {
        if (is_readable($secretPath)) {
            $secret = @file_get_contents($secretPath);
            if (is_string($secret) && trim($secret) !== '') return ['key' => trim($secret), 'source' => 'secret'];
        }
        $environment = getenv('ZOTERO_API_KEY');
        if (is_string($environment) && trim($environment) !== '') return ['key' => trim($environment), 'source' => 'environment'];
        return ['key' => trim((string)$fallback), 'source' => trim((string)$fallback) === '' ? 'missing' : 'configuration'];
    }
}
