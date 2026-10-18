(function () {
    'use strict';
    function init() {
        const form = document.querySelector('[data-zoterobridge-sync]');
        const progress = document.querySelector('[data-zoterobridge-job]');
        if (!form || !progress) return;
        let active = false;
        let timer = null;
        const endpoint = DOKU_BASE + 'lib/exe/ajax.php';
        const buttons = Array.from(form.querySelectorAll('button'));
        function busy(value) {
            active = value;
            buttons.forEach(function (button) { button.disabled = value; });
        }
        function show(status) {
            const values = { library: status.library || 'Not synchronized', counts: status.counts.document + ' / ' + status.counts.annotation, checked: status.lastChecked ? new Date(status.lastChecked * 1000).toLocaleString() : 'Never', version: status.version };
            Object.keys(values).forEach(function (name) {
                const element = document.querySelector('[data-zoterobridge-' + name + ']');
                if (element) element.textContent = values[name];
            });
            const oldError = document.querySelector('[data-zoterobridge-last-error]');
            if (oldError && !status.lastError) oldError.hidden = true;
            const job = status.job;
            if (!job) { progress.textContent = 'The index is up to date.'; return; }
            if (job.phase === 'completed') {
                progress.textContent = 'Synchronization complete: ' + status.counts.document + ' documents and ' + status.counts.annotation + ' annotations.';
            } else {
                const prefix = job.phase === 'failed' || job.phase === 'paused' ? 'Synchronization paused. ' : 'Synchronizing… ';
                progress.textContent = prefix + job.processedItems + ' / ' + job.totalItems + ' items, ' + job.processedCollections + ' / ' + job.totalCollections + ' collections. ' + (job.error || '');
            }
        }
        async function request(operation) {
            const body = new URLSearchParams({ call: 'zoterobridge_sync', operation: operation, sectok: form.querySelector('[name="sectok"]').value });
            const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: body });
            const result = await response.json();
            if (response.status === 409 && result.busy) return result;
            if (!response.ok) throw new Error(result.error || 'Synchronization could not continue.');
            return result;
        }
        function advance(status) {
            show(status);
            if (!status.busy && (!status.job || ['completed', 'failed', 'paused'].includes(status.job.phase))) { busy(false); return; }
            if (status.busy) progress.textContent = 'Waiting for the current synchronization request to finish…';
            const wait = Math.max(status.busy ? 1200 : 150, ((status.retryAt || 0) * 1000) - Date.now() + 1000);
            if (wait > 1000) progress.textContent += ' Waiting for Zotero’s retry window.';
            timer = window.setTimeout(async function () {
                timer = null;
                try { advance(await request('step')); }
                catch (error) { progress.textContent = error.message + ' Click Synchronize now to resume from the saved batch.'; busy(false); }
            }, Math.min(wait, 2147483647));
        }
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (active) return;
            if (timer) window.clearTimeout(timer);
            busy(true);
            progress.textContent = 'Checking Zotero and preparing synchronization…';
            try { advance(await request(event.submitter && event.submitter.value === 'rebuild' ? 'rebuild' : 'start')); }
            catch (error) { progress.textContent = error.message; busy(false); }
        });
        fetch(endpoint + '?call=zoterobridge_status', { credentials: 'same-origin' })
            .then(function (response) { if (!response.ok) throw new Error('Could not read synchronization status.'); return response.json(); })
            .then(function (status) {
                if (active) return;
                if (status.job && !['completed', 'failed', 'paused'].includes(status.job.phase)) { busy(true); advance(status); }
                else if (status.job) show(status);
            }).catch(function (error) { if (!active) progress.textContent = error.message; });
        window.addEventListener('pagehide', function () { if (timer) window.clearTimeout(timer); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
