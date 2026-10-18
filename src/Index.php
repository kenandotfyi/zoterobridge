<?php
namespace ZoteroBridge;

final class SyncBusyException extends \RuntimeException {}

/** Raw remote objects and their derived search index are published together. */
final class Index
{
    private $db;
    private $directory;

    public function __construct($directory)
    {
        if (!extension_loaded('pdo_sqlite')) throw new \RuntimeException('Enable the PHP pdo_sqlite extension to use Zotero indexing.');
        if (is_link($directory)) throw new \RuntimeException('Zotero storage cannot be a symbolic link.');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create the Zotero metadata directory.');
        $this->directory = $directory;
        $file = $directory . '/index.sqlite';
        if (is_link($file)) throw new \RuntimeException('Zotero database cannot be a symbolic link.');
        $this->db = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        @chmod($file, 0600);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $schema = (int)$this->db->query('PRAGMA user_version')->fetchColumn();
        if ($schema > 3) throw new \RuntimeException('This Zotero index needs a newer plugin version.');
        $this->db->exec('CREATE TABLE IF NOT EXISTS state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS objects (kind TEXT NOT NULL, key TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY(kind,key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS entries (key TEXT PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS staging (kind TEXT NOT NULL, key TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY(kind,key))');
        try {
            $this->db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search USING fts5(key UNINDEXED, kind UNINDEXED, title, text, comment, metadata, tokenize='unicode61 remove_diacritics 2')");
        } catch (\PDOException $error) {
            throw new \RuntimeException('This PHP SQLite build needs FTS5 support for Zotero search.');
        }
        if ($schema > 0 && $schema < 3) {
            // Rebuild derived payloads from the already stored Zotero objects so
            // new metadata fields become available without another remote sync.
            $this->db->beginTransaction();
            try {
                $this->rebuild();
                $this->db->exec('PRAGMA user_version=3');
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
        } else {
            $this->db->exec('PRAGMA user_version=3');
        }
    }

    public function acquireLock()
    {
        $file = $this->directory . '/sync.lock';
        if (is_link($file)) throw new \RuntimeException('Invalid Zotero synchronization lock.');
        $lock = @fopen($file, 'c');
        if (!$lock) throw new \RuntimeException('Cannot open the Zotero synchronization lock.');
        @chmod($file, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new SyncBusyException('A Zotero synchronization is already running.'); }
        return $lock;
    }

    public function state($name, $fallback = null)
    {
        $stmt = $this->db->prepare('SELECT value FROM state WHERE name=?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function setState($name, $value)
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO state (name,value) VALUES (?,?)');
        $stmt->execute([$name, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    public function status()
    {
        $counts = ['document' => 0, 'annotation' => 0];
        foreach ($this->db->query('SELECT kind, COUNT(*) AS count FROM entries GROUP BY kind') as $row) $counts[$row['kind']] = (int)$row['count'];
        return [
            'library' => $this->state('library'), 'version' => $this->state('version', 0),
            'generation' => $this->state('generation', 0), 'lastChecked' => $this->state('last_checked'),
            'lastChanged' => $this->state('last_changed'), 'lastError' => $this->state('last_error'),
            'retryAt' => $this->state('retry_at', 0), 'counts' => $counts,
            'job' => $this->jobStatus(),
        ];
    }

    /** Public job status excludes remote keys and staged library contents. */
    public function jobStatus()
    {
        $job = $this->state('job');
        if (!$job) return null;
        return [
            'phase' => $job['phase'], 'started' => $job['started'],
            'processedItems' => $job['itemOffset'], 'totalItems' => count($job['items']),
            'processedCollections' => $job['collectionOffset'], 'totalCollections' => count($job['collections']),
            'error' => $job['error'] ?? null,
        ];
    }

    public function beginJob(array $job)
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM staging');
            $this->setState('job', $job);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function stageBatch($kind, array $objects, array $job)
    {
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare('INSERT OR REPLACE INTO staging (kind,key,data) VALUES (?,?,?)');
            foreach ($objects as $object) {
                $key = $object['key'] ?? '';
                if (!self::validKey($key) || !is_array($object['data'] ?? null) || ($object['data']['key'] ?? $key) !== $key) throw new \RuntimeException('Zotero returned an invalid object.');
                $insert->execute([$kind, $key, json_encode($object['data'], JSON_THROW_ON_ERROR)]);
            }
            $this->setState('job', $job);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function publishStaged(array $job)
    {
        $this->db->beginTransaction();
        try {
            if ($job['full']) $this->db->exec('DELETE FROM objects');
            $this->db->exec('INSERT OR REPLACE INTO objects (kind,key,data) SELECT kind,key,data FROM staging');
            $this->removeDeleted($job['deleted']);
            $this->finishPublication($job['library'], $job['version']);
            $job['phase'] = 'completed';
            $job['error'] = null;
            $this->setState('job', $job);
            $this->db->exec('DELETE FROM staging');
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function publish($library, $version, array $collections, array $items, array $deleted, $full)
    {
        $this->db->beginTransaction();
        try {
            if ($full) $this->db->exec('DELETE FROM objects');
            $insert = $this->db->prepare('INSERT OR REPLACE INTO objects (kind,key,data) VALUES (?,?,?)');
            foreach (['collection' => $collections, 'item' => $items] as $kind => $objects) {
                foreach ($objects as $object) {
                    $key = $object['key'] ?? '';
                    if (!self::validKey($key) || !is_array($object['data'] ?? null) || ($object['data']['key'] ?? $key) !== $key) {
                        throw new \RuntimeException('Zotero returned an invalid object. The existing index is unchanged.');
                    }
                    $insert->execute([$kind, $key, json_encode($object['data'], JSON_THROW_ON_ERROR)]);
                }
            }
            $this->removeDeleted($deleted);
            $this->finishPublication($library, $version);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function removeDeleted(array $deleted)
    {
        $remove = $this->db->prepare('DELETE FROM objects WHERE kind=? AND key=?');
        foreach (['collections' => 'collection', 'items' => 'item'] as $type => $kind) {
            foreach ($deleted[$type] ?? [] as $key) $remove->execute([$kind, $key]);
        }
    }

    private function finishPublication($library, $version)
    {
        $this->rebuild();
        $this->setState('library', $library);
        $this->setState('version', $version);
        $this->setState('generation', $this->state('generation', 0) + 1);
        $this->setState('last_changed', time());
        $this->checked();
    }

    public function checked()
    {
        $this->setState('last_checked', time());
        $this->setState('last_error', null);
        $this->setState('retry_at', 0);
    }

    public function search($query, $kind = 'all', $limit = 20, $offset = 0, $matchMode = 'words')
    {
        // Keep result rows and their generation in the same SQLite read snapshot.
        $this->db->beginTransaction();
        try {
            $result = $this->searchSnapshot($query, $kind, $limit, $offset, $matchMode);
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function searchSnapshot($query, $kind, $limit, $offset, $matchMode)
    {
        if (!in_array($kind, ['all', 'document', 'annotation'], true)) throw new \InvalidArgumentException('Invalid result kind.');
        if (!in_array($matchMode, ['words', 'phrase'], true)) throw new \InvalidArgumentException('Invalid search mode.');
        if (strlen($query) > 512) throw new \InvalidArgumentException('Search is limited to 512 bytes.');
        preg_match_all('/[\p{L}\p{N}_]+/u', $query, $matches);
        $tokens = array_slice($matches[0], 0, 20);
        if (!$tokens) return ['results' => [], 'total' => 0, 'hasMore' => false, 'generation' => $this->state('generation', 0)];
        $match = $matchMode === 'phrase'
            ? '"' . implode(' ', $tokens) . '"'
            : implode(' AND ', array_map(static function ($word) { return '"' . $word . '"*'; }, $tokens));
        $limit = max(1, min(50, (int)$limit));
        $offset = max(0, min(1000, (int)$offset));
        $sql = 'SELECT entries.payload FROM search JOIN entries ON entries.key=search.key WHERE search MATCH ?';
        $params = [$match];
        if ($kind !== 'all') { $sql .= ' AND entries.kind=?'; $params[] = $kind; }
        $counter = $this->db->prepare(str_replace('SELECT entries.payload', 'SELECT COUNT(*)', $sql));
        $counter->execute($params);
        $total = (int)$counter->fetchColumn();
        $sql .= ' ORDER BY bm25(search,0,0,5,2,2,1), entries.key LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $more = count($rows) > $limit;
        return ['results' => array_map(static function ($row) { return json_decode($row, true, 512, JSON_THROW_ON_ERROR); }, array_slice($rows, 0, $limit)), 'total' => $total, 'hasMore' => $more, 'generation' => $this->state('generation', 0)];
    }

    public static function validKey($key) { return is_string($key) && preg_match('/\A[A-Z0-9]{8}\z/D', $key) === 1; }

    private function rebuild()
    {
        $items = [];
        $collections = [];
        foreach ($this->db->query('SELECT kind,key,data FROM objects') as $row) {
            $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['kind'] === 'item') $items[$row['key']] = $data;
            else $collections[$row['key']] = $data;
        }
        $this->db->exec('DELETE FROM entries');
        $this->db->exec('DELETE FROM search');
        $entry = $this->db->prepare('INSERT INTO entries (key,kind,payload) VALUES (?,?,?)');
        $fts = $this->db->prepare('INSERT INTO search (key,kind,title,text,comment,metadata) VALUES (?,?,?,?,?,?)');
        $attachments = [];
        foreach ($items as $key => $item) {
            if (($item['itemType'] ?? '') === 'attachment' && ($item['contentType'] ?? '') === 'application/pdf' && $this->visible($key, $items)) {
                $parent = $item['parentItem'] ?? $key;
                $attachments[$parent][] = ['key' => $key, 'title' => self::text($item['title'] ?? $item['filename'] ?? 'PDF'), 'openUrl' => 'zotero://open-pdf/library/items/' . $key];
            }
        }
        foreach ($items as $key => $item) {
            if (!$this->visible($key, $items)) continue;
            $type = $item['itemType'] ?? '';
            if ($type === 'note' || ($type === 'attachment' && !empty($item['parentItem']))) continue;
            if ($type === 'annotation') {
                $attachmentKey = $item['parentItem'] ?? '';
                $attachment = $items[$attachmentKey] ?? null;
                if (!$attachment || ($attachment['itemType'] ?? '') !== 'attachment' || ($attachment['contentType'] ?? '') !== 'application/pdf') continue;
                $parentKey = $attachment['parentItem'] ?? $attachmentKey;
                $parent = $items[$parentKey] ?? null;
                if (!$parent) continue;
                $kind = 'annotation';
            } else {
                if ($type === 'attachment' && ($item['contentType'] ?? '') !== 'application/pdf') continue;
                $kind = 'document';
                $parent = $item;
                $parentKey = $key;
                $attachmentKey = null;
            }
            $title = self::text($parent['title'] ?? $parent['filename'] ?? 'Untitled document');
            $creators = [];
            foreach ($parent['creators'] ?? [] as $creator) $creators[] = self::text($creator['name'] ?? trim(($creator['firstName'] ?? '') . ' ' . ($creator['lastName'] ?? '')));
            $paths = [];
            foreach ($parent['collections'] ?? [] as $collection) {
                $path = $this->collectionPath($collection, $collections);
                if ($path !== '') $paths[] = $path;
            }
            $tags = [];
            foreach ([$parent, $item] as $tagged) foreach ($tagged['tags'] ?? [] as $tag) $tags[] = self::text($tag['tag'] ?? '');
            $pdfs = $attachments[$parentKey] ?? [];
            usort($pdfs, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
            $payload = [
                'provider' => 'zotero', 'kind' => $kind, 'key' => $key, 'documentKey' => $parentKey,
                'title' => $title, 'creators' => $creators, 'date' => self::text($parent['date'] ?? ''),
                'itemType' => $parent['itemType'] ?? '', 'collections' => $paths, 'tags' => array_values(array_unique($tags)),
                'attachments' => $pdfs, 'selectUrl' => 'zotero://select/library/items/' . $parentKey,
                'text' => '', 'comment' => '', 'openUrl' => count($pdfs) === 1 ? $pdfs[0]['openUrl'] : null,
            ];
            if ($kind === 'annotation') {
                $position = json_decode($item['annotationPosition'] ?? '{}', true);
                $pageIndex = $position['pageIndex'] ?? null;
                $page = is_int($pageIndex) && $pageIndex >= 0 && $pageIndex < 1000000 ? $pageIndex + 1 : null;
                $color = $item['annotationColor'] ?? '';
                $payload = array_merge($payload, [
                    'attachmentKey' => $attachmentKey, 'annotationType' => self::text($item['annotationType'] ?? ''),
                    'dateAdded' => (string)($item['dateAdded'] ?? ''),
                    // Annotation fields are plain text. Preserve literal angle brackets
                    // used in equations; the UI must escape them rather than strip tags.
                    'text' => (string)($item['annotationText'] ?? ''), 'comment' => (string)($item['annotationComment'] ?? ''),
                    'pageLabel' => self::text($item['annotationPageLabel'] ?? ''), 'page' => $page,
                    'color' => preg_match('/\A#[a-fA-F0-9]{6}\z/D', $color) ? $color : '',
                    'openUrl' => 'zotero://open-pdf/library/items/' . $attachmentKey . '?' . http_build_query(array_filter(['page' => $page, 'annotation' => $key], static function ($value) { return $value !== null; }), '', '&', PHP_QUERY_RFC3986),
                ]);
            }
            $entry->execute([$key, $kind, json_encode($payload, JSON_THROW_ON_ERROR)]);
            $metadata = implode(' ', array_merge($creators, $paths, $tags, [$payload['date'], $payload['itemType']], array_column($pdfs, 'title')));
            $fts->execute([$key, $kind, $title, $payload['text'], $payload['comment'], $metadata]);
        }
    }

    private function visible($key, array $items)
    {
        $seen = [];
        while ($key !== '') {
            if (isset($seen[$key]) || !isset($items[$key]) || !empty($items[$key]['deleted'])) return false;
            $seen[$key] = true;
            $key = $items[$key]['parentItem'] ?? '';
        }
        return true;
    }

    private function collectionPath($key, array $collections)
    {
        $parts = [];
        $seen = [];
        while ($key && isset($collections[$key]) && !isset($seen[$key])) {
            $seen[$key] = true;
            array_unshift($parts, self::text($collections[$key]['name'] ?? ''));
            $key = $collections[$key]['parentCollection'] ?? null;
        }
        return implode(' / ', $parts);
    }

    private static function text($value)
    {
        return html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
