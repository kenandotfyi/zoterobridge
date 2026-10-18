<?php
namespace ZoteroBridge;

final class ApiException extends \RuntimeException
{
    public $retryAt;
    public function __construct($message, $retryAt = 0)
    {
        parent::__construct($message);
        $this->retryAt = $retryAt;
    }
}

/** Read-only API client. The transport seam is used for offline fixture tests. */
final class Api
{
    private $key;
    private $transport;
    private $backoff = 0;
    private $deadline;

    public function __construct($key, $transport = null, $budget = 90)
    {
        if (!Credentials::valid($key)) throw new ApiException('Enter a valid Zotero API key.');
        $this->key = $key;
        $this->transport = $transport;
        $this->deadline = microtime(true) + $budget;
    }

    public function retryAt() { return $this->backoff; }

    public function get($path, array $query = [], array $headers = [])
    {
        if ($this->backoff > time()) throw new ApiException('Zotero requested a pause. Retry after the displayed time.', $this->backoff);
        if (microtime(true) >= $this->deadline) throw new ApiException('Synchronization reached its time limit. The existing index is unchanged.');
        if (!preg_match('~\A/(?:keys/current|users/[1-9][0-9]*/(?:items|collections|deleted))\z~D', $path)) {
            throw new ApiException('Invalid Zotero API resource.');
        }
        $url = 'https://api.zotero.org' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $requestHeaders = array_merge(['Zotero-API-Version' => '3', 'Zotero-API-Key' => $this->key, 'Accept' => 'application/json'], $headers);
        $response = $this->transport ? ($this->transport)($url, $requestHeaders) : $this->request($url, $requestHeaders);
        $response['headers'] = array_change_key_case($response['headers'], CASE_LOWER);
        $status = (int)$response['status'];
        $backoff = $response['headers']['backoff'] ?? '';
        if (ctype_digit((string)$backoff) && (int)$backoff > 0) $this->backoff = time() + min((int)$backoff, 604800);
        if ($status === 429 || $status === 503) {
            $retry = $response['headers']['retry-after'] ?? '60';
            $retryAt = ctype_digit((string)$retry) ? time() + min((int)$retry, 604800) : (strtotime($retry) ?: time() + 60);
            $this->backoff = max($this->backoff, $retryAt, time() + 1);
            throw new ApiException('Zotero is temporarily unavailable or rate-limited. Retry later.', $this->backoff);
        }
        if ($status === 401 || $status === 403) throw new ApiException('Zotero rejected the API key or its library permissions.');
        if ($status !== 200 && $status !== 304) throw new ApiException('Zotero returned HTTP ' . $status . '. The existing index is unchanged.');
        try {
            $response['data'] = $status === 304 ? null : json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ApiException('Zotero returned invalid JSON. The existing index is unchanged.');
        }
        return $response;
    }

    public function identity()
    {
        $data = $this->get('/keys/current')['data'];
        $id = (string)($data['userID'] ?? '');
        if (!preg_match('/\A[1-9][0-9]*\z/D', $id) || empty($data['access']['user']['library'])) {
            throw new ApiException('This key needs read access to your personal Zotero library.');
        }
        // Deliberately omit the API key and the rest of the key response.
        return ['id' => 'users/' . $id, 'userID' => $id];
    }

    private function request($url, array $headers)
    {
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $timeout = max(0.1, min(30, $this->deadline - microtime(true)));
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $lines) . "\r\nConnection: close",
            'timeout' => $timeout,
            'ignore_errors' => true, 'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        // Limit a single response to 32 MiB. Redirects cannot forward credentials.
        $start = microtime(true);
        error_clear_last();
        $body = @file_get_contents($url, false, $context, 0, 33554433);
        $warning = error_get_last()['message'] ?? '';
        $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = 0;
        $parsed = [];
        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) { $status = (int)$match[1]; $parsed = []; }
            elseif (strpos($header, ':') !== false) { [$name, $value] = explode(':', $header, 2); $parsed[strtolower(trim($name))] = trim($value); }
        }
        if ($body === false) {
            if (microtime(true) >= $this->deadline) throw new ApiException('This synchronization step reached its time limit. Saved progress is retained; click Synchronize now to resume.');
            if (stripos($warning, 'timed out') !== false || microtime(true) - $start >= $timeout * 0.95) {
                throw new ApiException('A Zotero request timed out while fetching ' . $this->resourceLabel($url) . '. Saved progress is retained; click Synchronize now to resume.');
            }
            // Do not echo PHP's raw warning: it can contain request URLs.
            if (stripos($warning, 'certificate') !== false) throw new ApiException('PHP could not verify Zotero\'s HTTPS certificate. Check the container CA certificates.');
            if (stripos($warning, 'getaddrinfo') !== false) throw new ApiException('PHP could not resolve api.zotero.org. Check the container DNS connection.');
            throw new ApiException('The HTTPS request for ' . $this->resourceLabel($url) . ' failed. Saved progress is retained; click Synchronize now to retry.');
        }
        if (strlen($body) > 33554432) throw new ApiException('Zotero response exceeded the download limit.');
        return ['status' => $status, 'headers' => $parsed, 'body' => $body];
    }

    private function resourceLabel($url)
    {
        $type = basename(parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if ($type === 'current') return 'the connection check';
        if (($query['format'] ?? '') === 'versions') return $type === 'collections' ? 'the collection version list' : 'the item version list';
        return $type === 'deleted' ? 'deleted-item changes' : ($type === 'collections' ? 'a collection batch' : 'an item batch');
    }
}
