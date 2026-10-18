<?php
namespace ZoteroBridge;

/** A durable sync job. Web requests advance one batch; CLI drives the same job. */
final class Sync
{
    private $api;
    private $index;
    private $version;

    public function __construct(Api $api, Index $index) { $this->api = $api; $this->index = $index; }

    public function run($full = false)
    {
        $status = $this->start($full);
        while ($status['job'] && !in_array($status['job']['phase'], ['completed', 'failed', 'paused'], true)) $status = $this->step();
        return $status;
    }

    public function start($full = false)
    {
        $lock = $this->index->acquireLock();
        try {
            $this->checkRetry();
            $job = $this->index->state('job');
            if (!$full && $job && !in_array($job['phase'], ['completed', 'failed'], true)) {
                if ($job['phase'] === 'paused') {
                    $job['phase'] = $job['resumePhase'];
                    $job['error'] = null;
                    unset($job['resumePhase']);
                    $this->index->setState('job', $job);
                    $this->index->setState('last_error', null);
                }
                return $this->index->status();
            }
            $identity = $this->api->identity();
            $library = $identity['id'];
            $full = $full || $this->index->state('library') !== $library;
            $since = $full ? 0 : $this->index->state('version', 0);
            $this->version = null;
            $response = $this->api->get('/' . $library . '/items', ['format' => 'versions', 'includeTrashed' => 1, 'since' => $since], $full ? [] : ['If-Modified-Since-Version' => (string)$since]);
            if ($response['status'] === 304) {
                $this->index->checked();
                $this->index->setState('job', null);
                $this->index->setState('retry_at', $this->api->retryAt());
                return $this->index->status();
            }
            $items = $this->versions($response);
            $collections = $this->versions($this->api->get('/' . $library . '/collections', ['format' => 'versions', 'since' => $since]));
            $this->index->beginJob([
                'phase' => 'collections', 'library' => $library, 'version' => $this->version,
                'since' => $since, 'full' => $full, 'started' => time(),
                'items' => $items, 'collections' => $collections,
                'itemOffset' => 0, 'collectionOffset' => 0,
                'deleted' => ['items' => [], 'collections' => []], 'error' => null,
            ]);
            $this->index->setState('last_error', null);
            $this->index->setState('retry_at', $this->api->retryAt());
            return $this->index->status();
        } catch (\Throwable $error) {
            $this->recordError($error);
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** At most one batch request, or a single verification/publication step. */
    public function step()
    {
        $lock = $this->index->acquireLock();
        $job = $this->index->state('job');
        try {
            $this->checkRetry();
            if (!$job || in_array($job['phase'], ['completed', 'failed', 'paused'], true)) return $this->index->status();
            $this->version = $job['version'];
            foreach (['collections' => 'collection', 'items' => 'item'] as $type => $kind) {
                if ($job['phase'] !== $type) continue;
                $offsetName = $kind . 'Offset';
                $versions = array_slice($job[$type], $job[$offsetName], 50, true);
                if (!$versions) {
                    $job['phase'] = $type === 'collections' ? 'items' : 'deleted';
                    $this->index->setState('job', $job);
                    continue;
                }
                $objects = $this->download($job['library'], $type, $versions);
                $job[$offsetName] += count($objects);
                if ($job[$offsetName] === count($job[$type])) $job['phase'] = $type === 'collections' ? 'items' : 'deleted';
                $this->index->stageBatch($kind, $objects, $job);
                $this->index->setState('retry_at', $this->api->retryAt());
                return $this->index->status();
            }
            if ($job['phase'] === 'deleted') {
                if (!$job['full']) {
                    $response = $this->api->get('/' . $job['library'] . '/deleted', ['since' => $job['since']]);
                    $this->checkVersion($response);
                    $deleted = $response['data'];
                    foreach (['items', 'collections'] as $type) {
                        if (!isset($deleted[$type]) || !is_array($deleted[$type])) throw new \RuntimeException('Zotero returned an invalid deletion list.');
                        foreach ($deleted[$type] as $key) if (!Index::validKey($key)) throw new \RuntimeException('Zotero returned an invalid deleted key.');
                    }
                    $job['deleted'] = $deleted;
                }
                $job['phase'] = 'verify';
                $this->index->setState('job', $job);
                $this->index->setState('retry_at', $this->api->retryAt());
                return $this->index->status();
            }
            if ($job['phase'] === 'verify') {
                $final = $this->api->get('/' . $job['library'] . '/items', ['format' => 'versions', 'includeTrashed' => 1, 'since' => $job['version']]);
                $this->checkVersion($final);
                $this->index->publishStaged($job);
                $this->index->setState('retry_at', $this->api->retryAt());
            }
            return $this->index->status();
        } catch (\Throwable $error) {
            // Pauses retain downloaded batches. A remote version change invalidates
            // the snapshot and needs a new run, never a partial publication.
            if ($job && !in_array($job['phase'], ['completed', 'failed', 'paused'], true)) {
                $job['resumePhase'] = $job['phase'];
                $job['phase'] = $error instanceof ApiException ? 'paused' : 'failed';
                $job['error'] = $error instanceof \PDOException ? 'Could not save Zotero synchronization progress.' : $error->getMessage();
                $this->index->setState('job', $job);
            }
            $this->recordError($error);
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function checkRetry()
    {
        if ($this->index->state('retry_at', 0) > time()) throw new ApiException('A retry pause is active. Try after the displayed time.', $this->index->state('retry_at'));
    }

    private function recordError(\Throwable $error)
    {
        $this->index->setState('last_error', $error instanceof \PDOException ? 'Could not save Zotero synchronization progress.' : $error->getMessage());
        $this->index->setState('retry_at', max($this->api->retryAt(), $error instanceof ApiException ? $error->retryAt : 0));
    }

    private function versions(array $response)
    {
        $this->checkVersion($response);
        $data = $response['data'];
        if (!is_array($data)) throw new \RuntimeException('Zotero returned an invalid version map.');
        foreach ($data as $key => $version) {
            if (!Index::validKey($key) || !is_int($version) || $version < 0) throw new \RuntimeException('Zotero returned an invalid object version.');
        }
        return $data;
    }

    private function download($library, $type, array $versions)
    {
        $objects = [];
        foreach (array_chunk(array_keys($versions), 50) as $keys) {
            $query = ['format' => 'json', 'limit' => 100, $type === 'items' ? 'itemKey' : 'collectionKey' => implode(',', $keys)];
            if ($type === 'items') $query['includeTrashed'] = 1;
            $response = $this->api->get('/' . $library . '/' . $type, $query);
            $this->checkVersion($response);
            if (!is_array($response['data']) || !array_is_list($response['data'])) throw new \RuntimeException('Zotero returned an invalid object list.');
            $received = [];
            foreach ($response['data'] as $object) {
                $key = $object['key'] ?? '';
                if (!isset($versions[$key]) || !in_array($key, $keys, true) || isset($received[$key]) || ($object['version'] ?? null) !== $versions[$key]) {
                    throw new \RuntimeException('The Zotero library changed during synchronization. Retry to get a consistent snapshot.');
                }
                $received[$key] = true;
                $objects[] = $object;
            }
            if (count($received) !== count($keys)) throw new \RuntimeException('Zotero returned an incomplete batch. The existing index is unchanged.');
        }
        return $objects;
    }

    private function checkVersion(array $response)
    {
        $version = $response['headers']['last-modified-version'] ?? '';
        if (!ctype_digit((string)$version)) throw new \RuntimeException('Zotero omitted its library version.');
        $version = (int)$version;
        if ($this->version === null) $this->version = $version;
        elseif ($version !== $this->version) throw new \RuntimeException('The Zotero library changed during synchronization. Retry to get a consistent snapshot.');
    }
}
