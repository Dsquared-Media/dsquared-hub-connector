(function () {
    'use strict';

    const root = document.getElementById('dhc-alt-app');
    if (!root || typeof dhcAltText === 'undefined') return;

    const state = {
        page: 1,
        totalPages: 1,
        filter: 'missing',
        search: '',
        loading: false,
        quote: { unit_credits: 1, max_batch: 20, available: false },
        items: new Map(),
        selected: new Set(),
        request: 0
    };

    const el = id => document.getElementById(id);
    const grid = el('dhc-alt-grid');
    const empty = el('dhc-alt-empty');
    const notice = el('dhc-alt-notice');
    const loadMore = el('dhc-alt-load-more');
    const generate = el('dhc-alt-generate');
    const save = el('dhc-alt-save');
    const clear = el('dhc-alt-clear');
    let searchTimer = null;

    function post(action, payload) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', dhcAltText.nonce);
        Object.entries(payload || {}).forEach(([key, value]) => body.append(key, value));
        return fetch(dhcAltText.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
            .then(async response => {
                const json = await response.json().catch(() => null);
                if (!response.ok || !json || json.success !== true) {
                    throw new Error(json?.data?.message || dhcAltText.messages.requestFailed);
                }
                return json.data;
            });
    }

    function showNotice(message, type) {
        notice.hidden = false;
        notice.className = 'dhc-alt-inline-notice ' + (type ? 'is-' + type : '');
        notice.textContent = message;
        notice.focus({ preventScroll: true });
    }

    function hideNotice() {
        notice.hidden = true;
        notice.textContent = '';
    }

    function updateSummary() {
        const count = state.selected.size;
        const priced = state.quote.available === true;
        const unlimited = priced && state.quote.unlimited === true;
        const unit = unlimited ? 0 : Math.max(1, Number(state.quote.unit_credits) || 1);
        el('dhc-alt-selected').textContent = String(count);
        el('dhc-alt-cost').textContent = priced ? String(state.quote.total_credits ?? count * unit) : '—';
        el('dhc-alt-action-summary').textContent = count
            ? `${count} image${count === 1 ? '' : 's'} selected`
            : dhcAltText.messages.selectImages;
        el('dhc-alt-action-detail').textContent = count
            ? (!priced ? 'Your exact website credit quote appears before generation.' : unlimited ? 'AI generation is included for this website; saving reviewed drafts is free.' : `Exact quote: ${state.quote.total_credits} credit${state.quote.total_credits === 1 ? '' : 's'}; saving reviewed drafts is free.`)
            : dhcAltText.messages.noAutomaticSave;
        generate.textContent = count
            ? `Generate ${count} draft${count === 1 ? '' : 's'}${priced ? ` · ${unlimited ? 'included' : `${state.quote.total_credits} credit${state.quote.total_credits === 1 ? '' : 's'}`}` : ''}`
            : dhcAltText.messages.generate;
        const overLimit = count > Math.min(20, Number(state.quote.max_batch) || 20);
        generate.disabled = state.loading || !count || overLimit || !dhcAltText.connected;
        save.disabled = state.loading || !selectedSavable().length;
        clear.disabled = state.loading || !count;
        if (overLimit) showNotice(`Select no more than ${state.quote.max_batch} images for one generation batch.`, 'warning');
    }

    function selectedSavable() {
        return Array.from(state.selected).map(id => state.items.get(id)).filter(item => {
            if (!item || !item.classification) return false;
            return item.classification === 'decorative' || String(item.draft || '').trim().length > 0;
        });
    }

    function setBusy(busy) {
        state.loading = busy;
        root.classList.toggle('is-busy', busy);
        grid.setAttribute('aria-busy', busy ? 'true' : 'false');
        updateSummary();
    }

    function create(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function renderCard(item) {
        const article = create('article', 'dhc-alt-card');
        article.dataset.mediaId = String(item.id);

        const selectLabel = create('label', 'dhc-alt-card-select');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = state.selected.has(item.id);
        checkbox.setAttribute('aria-label', `Select ${item.filename || item.title || 'image'}`);
        checkbox.addEventListener('change', () => {
            checkbox.checked ? state.selected.add(item.id) : state.selected.delete(item.id);
            article.classList.toggle('is-selected', checkbox.checked);
            updateSummary();
        });
        selectLabel.append(checkbox, create('span', '', 'Select'));

        const imageWrap = create('div', 'dhc-alt-thumb');
        const image = document.createElement('img');
        image.src = item.thumbnail || item.url;
        image.alt = '';
        image.loading = 'lazy';
        imageWrap.appendChild(image);

        const body = create('div', 'dhc-alt-card-body');
        const filename = create('strong', 'dhc-alt-filename', item.filename || item.title || `Media ${item.id}`);
        filename.title = item.filename || item.title || '';
        const existing = create('p', 'dhc-alt-existing');
        existing.append(create('span', '', 'Current: '), document.createTextNode(item.alt_text || 'No alt text'));

        const modeLabel = create('label', 'dhc-alt-field-label', 'Treatment');
        const mode = document.createElement('select');
        mode.className = 'dhc-input dhc-alt-classification';
        mode.innerHTML = '<option value="">Choose after review</option><option value="usable">Descriptive alt text</option><option value="decorative">Decorative image (empty alt)</option>';
        mode.value = item.classification || '';
        mode.addEventListener('change', () => {
            item.classification = mode.value;
            item.dirty = true;
            textarea.disabled = mode.value === 'decorative';
            if (mode.value === 'decorative') textarea.value = '';
            item.draft = textarea.value;
            state.selected.add(item.id);
            checkbox.checked = true;
            article.classList.add('is-selected');
            updateCount();
            updateSummary();
        });
        modeLabel.appendChild(mode);

        const textLabel = create('label', 'dhc-alt-field-label', 'Reviewed alt text');
        const textarea = document.createElement('textarea');
        textarea.className = 'dhc-input dhc-alt-edit';
        textarea.rows = 3;
        textarea.maxLength = 125;
        textarea.placeholder = 'Generated draft appears here. You can edit it before saving.';
        textarea.value = item.draft || '';
        textarea.disabled = item.classification === 'decorative';
        const count = create('span', 'dhc-alt-char-count', `${textarea.value.length}/125`);
        function updateCount() {
            item.draft = textarea.value;
            count.textContent = `${textarea.value.length}/125`;
            count.classList.toggle('is-near-limit', textarea.value.length > 110);
        }
        textarea.addEventListener('input', () => {
            updateCount();
            item.dirty = true;
            if (!item.classification) {
                item.classification = 'usable';
                mode.value = 'usable';
            }
            state.selected.add(item.id);
            checkbox.checked = true;
            article.classList.add('is-selected');
            updateSummary();
        });
        textLabel.append(textarea, count);

        const status = create('div', 'dhc-alt-card-status');
        status.dataset.role = 'status';
        if (item.status) {
            status.className += ` is-${item.status.type || 'info'}`;
            status.textContent = item.status.text || '';
        }

        body.append(filename, existing, modeLabel, textLabel, status);
        article.append(selectLabel, imageWrap, body);
        article.classList.toggle('is-selected', state.selected.has(item.id));
        return article;
    }

    function renderAll() {
        grid.textContent = '';
        state.items.forEach(item => grid.appendChild(renderCard(item)));
        empty.hidden = state.items.size > 0 || state.loading;
        if (!state.items.size && !state.loading) {
            empty.textContent = state.filter === 'missing'
                ? dhcAltText.messages.noMissing
                : dhcAltText.messages.noImages;
        }
        loadMore.hidden = state.page >= state.totalPages || state.loading;
        updateSummary();
    }

    async function loadInventory(append) {
        const request = ++state.request;
        if (!append) {
            state.page = 1;
            state.items.clear();
            state.selected.clear();
            grid.textContent = '';
        }
        hideNotice();
        setBusy(true);
        if (!append) grid.appendChild(create('div', 'dhc-alt-loading', dhcAltText.messages.loading));
        try {
            const data = await post('dhc_alt_inventory', {
                page: state.page,
                filter: state.filter,
                search: state.search
            });
            if (request !== state.request) return;
            state.totalPages = Number(data.total_pages) || 1;
            state.quote = data.quote || state.quote;
            el('dhc-alt-missing').textContent = String(data.missing_total || 0);
            (data.images || []).forEach(image => state.items.set(Number(image.id), {
                ...image,
                id: Number(image.id),
                draft: '',
                classification: '',
                dirty: false,
                status: null
            }));
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            if (request === state.request) {
                setBusy(false);
                renderAll();
            }
        }
    }

    async function generateDrafts() {
        const ids = Array.from(state.selected);
        if (!ids.length || state.loading) return;
        hideNotice();
        setBusy(true);
        try {
            const images = ids.map(id => state.items.get(id)).filter(Boolean).map(item => ({ media_id: item.id }));
            const quote = await post('dhc_alt_quote', { images: JSON.stringify(images) });
            state.quote = { ...quote, available: true };
            updateSummary();
            const charge = quote.unlimited ? 'This generation is included for this website.' : `The exact charge is ${quote.total_credits} Hub credit${quote.total_credits === 1 ? '' : 's'}.`;
            const confirmed = window.confirm(`Generate drafts for ${ids.length} image${ids.length === 1 ? '' : 's'}? ${charge}`);
            if (!confirmed) return;
            const data = await post('dhc_alt_generate', {
                images: JSON.stringify(images),
                quote_token: quote.quote_token,
                request_id: quote.request_id
            });
            if (['claimed', 'charged', 'recovering'].includes(data.status)) {
                showNotice('This exact request is still processing. Retry shortly; it will not be charged twice.', 'warning');
                return;
            }
            (data.results || []).forEach(result => {
                const item = state.items.get(Number(result.media_id));
                if (!item) return;
                if (result.success && result.alt_text) {
                    item.draft = result.alt_text;
                    item.classification = 'usable';
                    item.dirty = true;
                    item.status = { type: 'success', text: 'Draft generated — review before saving.' };
                } else if (result.skipped) {
                    item.status = { type: 'warning', text: 'Manual review needed: people and headshots are not auto-described.' };
                    item.classification = '';
                    item.draft = '';
                    item.dirty = false;
                    state.selected.delete(item.id);
                } else {
                    item.status = { type: 'error', text: result.error || 'Draft could not be generated.' };
                }
            });
            const summary = data.summary || {};
            const pending = Number(summary.refund_pending_credits || 0);
            const billing = `${summary.charged_credits || 0} charged, ${summary.refunded_credits || 0} refunded${pending ? `, ${pending} pending recovery` : ''}, ${summary.net_credits || 0} net.`;
            showNotice(`${summary.generated || 0} draft${summary.generated === 1 ? '' : 's'} generated. ${summary.skipped || 0} sent to manual review. ${billing}`, pending || summary.failed ? 'warning' : 'success');
            renderAll();
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function saveReviewed() {
        const rows = selectedSavable();
        if (!rows.length || state.loading) return;
        hideNotice();
        setBusy(true);
        try {
            const updates = rows.map(item => ({
                media_id: item.id,
                classification: item.classification,
                alt_text: item.classification === 'decorative' ? '' : item.draft.trim()
            }));
            const data = await post('dhc_alt_save', { updates: JSON.stringify(updates) });
            const failed = (data.results || []).filter(row => !row.success);
            if (failed.length) {
                failed.forEach(row => {
                    const item = state.items.get(Number(row.media_id));
                    if (item) item.status = { type: 'error', text: row.error || 'Could not save.' };
                });
                showNotice(`${data.summary.saved || 0} saved; ${failed.length} need attention.`, 'warning');
                renderAll();
            } else {
                showNotice(`${data.summary.saved || rows.length} reviewed alt text change${rows.length === 1 ? '' : 's'} saved to WordPress.`, 'success');
                await loadInventory(false);
            }
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    root.querySelectorAll('.dhc-alt-filter').forEach(button => button.addEventListener('click', () => {
        root.querySelectorAll('.dhc-alt-filter').forEach(other => {
            const active = other === button;
            other.classList.toggle('active', active);
            other.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        state.filter = button.dataset.filter;
        loadInventory(false);
    }));

    el('dhc-alt-search').addEventListener('input', event => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = event.target.value.trim();
            loadInventory(false);
        }, 300);
    });

    el('dhc-alt-select-visible').addEventListener('click', () => {
        const limit = Math.min(20, Number(state.quote.max_batch) || 20);
        state.selected.clear();
        Array.from(state.items.keys()).slice(0, limit).forEach(id => state.selected.add(id));
        renderAll();
    });
    loadMore.addEventListener('click', () => { state.page += 1; loadInventory(true); });
    clear.addEventListener('click', () => { state.selected.clear(); renderAll(); });
    generate.addEventListener('click', generateDrafts);
    save.addEventListener('click', saveReviewed);

    loadInventory(false);
})();
