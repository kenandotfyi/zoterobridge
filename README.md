# Zotero Bridge

I created this plugin to integrate my Zotero library into my Dokuwiki instance and give the ability to search Zotero and paste the results in a formatted way, for stuff like annotations and documents. I use Zotero as more of a document manager than a reference manager so bibliography or citation creation stuff are not implemented in this plugin. It's pure document and annotation index search for quickly referencing my Zotero entries in my DokuWiki articles. 

This is inpired by other Zotero integrations in other PKM-style tools like Obsidian or Logseq. 

It synchronizes the library to my DokuWiki during the initial setup as well as between timed triggers which can be set in the Admin menu. Since the library is quite big, I used SQLite to store the index. System creates it under the data/meta/zoterobridge.

It uses the official Zotero API of course with an API Key, where either you can enter it in the Admin menu (which I don't recommend) or in your server's environment variables, which is much better in terms of securing the key. Key permissions do not require Write access, since this is just a one-way sync.

Credentials use the same deployment pattern as my Todoist integration (bundled in Fokus Butler), in this order:

1. `/run/secrets/zotero_api_key`
2. `ZOTERO_API_KEY`
3. `$conf['plugin']['zoterobridge']['api_key']`, saved through Zotero Bridge administration

Since this is only the backend sort of part of the integration and do not add a valuable user experience alone, I recommend checking out my template [Fokus](https://github.com/kenandotfyi/dokuwiki-template-fokus) and its companion plugin [Fokus Butler](https://github.com/kenandotfyi/fokusbutler). *Fokus* adds the nice search UI, where the Zotero sqlite index results as well as normal dokuwiki results are shown and give you the actions to interact. Fokus Butler is a companion plugin which adds more integrations and useful stuff and checks the integration and dependent plugins.

_DISCLAIMER: This plugin is built with the help of AI. It works quite well, since it is also quite simple, but there might be some unnecessary stuff built because of it. For my 1000+ item Zotero library, the generated SQLite database is returning the search results blazingly fast, so I have been daily driving this._ 

## Requirements and setup

Requires PHP 8.2+, `pdo_sqlite` with SQLite FTS5, and PHP HTTPS streams with a trusted CA store.

Create a dedicated key at [Zotero API key settings](https://www.zotero.org/settings/keys), allowing read access to the personal library. Write permission and attachment-file access are unnecessary. The plugin detects the personal library's numeric user ID from the key. Group libraries are outside this initial implementation.

For services like Coolify, add `ZOTERO_API_KEY` as a runtime environment variable for the DokuWiki service and redeploy. For a Compose-based deployment, also ensure its service `environment` section passes the variable into PHP.

The admin page shows the active source and never echoes a stored key. A deployment key takes precedence over the local value and hides the local-edit controls. Leave the field blank to preserve the saved value, or select **Remove saved key** to clear it. **Test connection** can test an entered replacement before it is saved. **Synchronize now** always uses the saved/deployment key.

The local configuration fallback is stored in DokuWiki's server-side PHP configuration. So be careful about local configuration backups, since DokuWiki backs up the conf/ folder. For production environment, I recommend doing it over the env. variables in your server/deployment service, as I mentioned above.

<details>

<summary>Usage and development</summary>


## Use the index

After saving the key, click **Test connection**, then **Synchronize now** for the initial import. Use **Try local search** on the same page to search titles, creators, collection paths, tags, annotation text, or annotation comments. Results open documents/annotations directly in Zotero. Printed page labels are displayed separately from the physical PDF page used in deep links. The unified Fokus popup shows the annotation highlight color and preserves it as a Color plugin background when inserting a quote. Multiple PDF attachments are listed explicitly; metadata-only items offer Zotero item selection.

Only wiki administrators can search, read status/counts, or manage the index in this version. Private library access is independent of page-read permissions.

Synchronization downloads item metadata and native annotations through the Zotero Web API, never through WebDAV. Only changes already synced from the Zotero client to Zotero's server are visible. It does not download PDFs, extract embedded external annotations, or modify Zotero or wiki pages.

Remote changes, trash, deleted objects, collections, and derived search entries are published atomically after version-consistency checks. A failed fetch or a concurrent remote library change preserves the previous index/version. API rate-limit pauses are persisted and shown in administration. A nonblocking file lock rejects overlapping manual jobs. The read endpoints never contact Zotero.

Synchronization runs as a durable job, advancing one batch of at most 50 objects per request. The admin page and Fokus search palette continue the job in the background; search always reads the last published SQLite index while a check is running. The palette starts an incremental Zotero check when its configured freshness threshold expires (2 hours by default; configurable from 1 to 168 hours in the DokuWiki Configuration Manager). A check with no remote changes updates the successful-check time without rebuilding the index. Changed data is published as one verified generation, after which open search results refresh. Transient failures preserve the current index and are shown in administration. **Synchronize now** resumes a saved job, and **Rebuild from Zotero** explicitly starts a new full job.

Each web step has a 45-second retrieval budget, with individual HTTP requests limited to 30 seconds. The initial import is no longer limited to one 90-second web request. Without JavaScript, a form submission prepares the job but does not run subsequent batches; use the CLI entry point, which drives the same saved job for up to 15 minutes:

```sh
php lib/plugins/zoterobridge/bin/sync.php
php lib/plugins/zoterobridge/bin/sync.php --full
```

For a nightly incremental check, add `php lib/plugins/zoterobridge/bin/sync.php --scheduled` as a server scheduled task from the DokuWiki root and set its schedule to `0 3 * * *` (03:00 daily). Run it inside the actual DokuWiki installation/container so credentials, PHP extensions, configuration, and filesystem ownership match the web process. The CLI resolves the wiki root from its installed location. Set the task timezone to the timezone you want; displayed timestamps use PHP's configured timezone. The default command is incremental and only downloads changes since the saved library version. Reserve `--full` for an intentional rebuild, not the nightly task.

The `--scheduled` flag sends a Pushover notification only when the run fails; successful checks and “no changes” checks stay quiet. Set `PUSHOVER_APP_TOKEN` and `PUSHOVER_USER_KEY` as environment variables available to the Coolify scheduled task. These credentials are never saved in DokuWiki configuration or SQLite. If either variable is missing, the sync still runs and reports the missing notification setup to the task log.

## API and storage

Authenticated admin-only GET endpoints at `lib/exe/ajax.php`:

- `call=zoterobridge_search&q=gate&kind=all&limit=20&offset=0&match=words` — normalized `results`, filtered `total`, `hasMore`, and index `generation`. Set `match=phrase` to require the query words, in order, as one phrase; `words` is the default and matches all query words in any order.
- `call=zoterobridge_status` — library identity/version, generation, successful check/publication times, error/retry state, document/annotation counts, and sanitized job progress.

The admin page advances jobs with `call=zoterobridge_sync` using token-protected POST requests and `operation=start`, `rebuild`, or `step`. There is no state-changing GET endpoint. The PHP session is released before remote requests so normal wiki requests can continue during synchronization.

Result kinds are `all`, `document`, and `annotation`. Limits are bounded to 50 results, offsets to 1000, and queries to 512 bytes. Query text is tokenized into literal FTS terms rather than executed as raw FTS syntax. Responses use `Cache-Control: no-store, private`.

Runtime data is under `<metadir>/zoterobridge/<SHA-256 of API key>/`: `index.sqlite` and `sync.lock`. DokuWiki's runtime-resolved metadata path is used, including container overrides. Changing the key selects a separate index, preventing old-library results from appearing under a new credential. Existing indexes are retained; configuration and keys are separate. Keep this metadata area protected from direct HTTP access, as required by DokuWiki. Source/plugin updates do not replace runtime data.

The SQLite schema stores raw remote objects, normalized result payloads, full-text entries, durable job state, and staging rows. Schema version 2 upgrades the original index in place and preserves committed results. Each batch and its checkpoint are written together; publication and job completion share a single transaction. Parent or collection changes rebuild derived fields so annotation results inherit current document metadata. Missing/trashed parent chains are excluded. Annotation text/comments stay plain text, preserving engineering expressions containing angle brackets; renderers must escape them.

## Verification

```sh
php lib/plugins/zoterobridge/tests/backend.php
DOKUWIKI_TEST_ROOT=/private/tmp/zoterobridge-runtime php lib/plugins/zoterobridge/tests/dokuwiki.php
```

Backend tests are offline and use fabricated keys and library fixtures. DokuWiki integration tests require an isolated installation named `zoterobridge-runtime` and write fixture metadata there; never point them at a real library/wiki. Browser validation covers key save/masking/removal, invalid-key rejection, annotation-comment search, and Butler's integration link. The existing Butler integration suite remains unchanged.

Large-library verification on localhost completed a 2,985-object/47-collection import, publishing 1,146 searchable documents and 619 annotations. Browser verification also exercises page reload/resume and retryable lock contention. Automated fixtures include a 1,201-document import, interrupted/resumed requests without duplicate downloads, remote version conflicts, and schema migration preserving the previous index.

API behavior follows [Zotero API reads](https://www.zotero.org/support/dev/web_api/v3/basics) and [version-based synchronization](https://www.zotero.org/support/dev/web_api/v3/syncing).


</details>
