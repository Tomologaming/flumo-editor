/* Website-Editor - Oberflaeche. Vanilla JS, kein Build-Schritt, keine CDN-Abhaengigkeiten. */
(function () {
'use strict';

var W = window.WFX || {};
var PERMS = W.perms || [];

/* ------------------------------------------------------------------ */
/* Helfer                                                              */
/* ------------------------------------------------------------------ */

function $(sel, root) { return (root || document).querySelector(sel); }
function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

function h(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
        Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === undefined || v === null || v === false) return;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k === 'html') node.innerHTML = v;
            else if (k === 'dataset') Object.keys(v).forEach(function (d) { node.dataset[d] = v[d]; });
            else if (k === 'style') node.setAttribute('style', v);
            else if (k.slice(0, 2) === 'on' && typeof v === 'function') node.addEventListener(k.slice(2).toLowerCase(), v);
            else if (v === true) node.setAttribute(k, '');
            else node.setAttribute(k, v);
        });
    }
    [].concat(children || []).forEach(function (c) {
        if (c === null || c === undefined || c === false) return;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
}

function esc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function debounce(fn, ms) {
    var t = null;
    return function () {
        var args = arguments, self = this;
        clearTimeout(t);
        t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
}

function api(path, body, isGet) {
    var opts = { method: isGet || body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: {} };
    if (!isGet && body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-CSRF-Token'] = W.csrf;
        opts.body = JSON.stringify(body);
    }
    return fetch(path, opts).then(function (res) {
        return res.json().catch(function () { return null; }).then(function (data) {
            if (!res.ok || (data && data.ok === false)) {
                var err = new Error((data && data.error) || ('Serverfehler ' + res.status));
                err.status = res.status;
                err.data = data;
                throw err;
            }
            return data;
        });
    });
}

function toast(message, kind) {
    var root = $('#toastRoot');
    var node = h('div', { class: 'toast ' + (kind || ''), text: message });
    root.appendChild(node);
    setTimeout(function () { node.remove(); }, kind === 'err' ? 8000 : 4200);
}

function modal(opts) {
    var backdrop = h('div', { class: 'modal-backdrop' });
    var box = h('div', { class: 'modal' + (opts.wide ? ' wide' : '') });
    var closeBtn = h('button', { class: 'close', type: 'button', text: '✕', onclick: function () { done(null); } });
    var footer = h('footer');
    var bodyNode = h('div', { class: 'modal-body' });
    if (opts.body) bodyNode.appendChild(opts.body);
    (opts.actions || []).forEach(function (a) {
        footer.appendChild(h('button', { class: 'btn ' + (a.kind || ''), type: 'button', text: a.label, onclick: function () {
            if (!a.onClick) { done(a.value); return; }
            var r = a.onClick();
            if (r === false) return;
            if (r && typeof r.then === 'function') { r.then(function (v) { if (v !== false) done(v === undefined ? a.value : v); }, function () {}); return; }
            done(a.value);
        } }));
    });
    box.appendChild(h('header', null, [h('h2', { text: opts.title || '' }), closeBtn]));
    box.appendChild(bodyNode);
    if (footer.children.length) box.appendChild(footer);
    backdrop.appendChild(box);
    $('#modalRoot').appendChild(backdrop);
    var resolver;
    var promise = new Promise(function (resolve) { resolver = resolve; });
    function done(value) {
        backdrop.remove();
        document.removeEventListener('keydown', onKey);
        resolver(value);
    }
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); done(null); } }
    document.addEventListener('keydown', onKey);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) done(null); });
    return promise;
}

function confirmDialog(title, message, okLabel, kind) {
    return modal({
        title: title,
        body: h('p', { text: message }),
        actions: [
            { label: 'Abbrechen', value: false },
            { label: okLabel || 'Bestätigen', kind: kind || 'primary', value: true }
        ]
    }).then(function (v) { return v === true; });
}

/* ------------------------------------------------------------------ */
/* Zustand                                                             */
/* ------------------------------------------------------------------ */

var state = {
    file: null,
    remote: { entries: [], listedAt: null, counts: {}, warnings: [] },
    etag: null,
    info: null,
    selection: null,
    pages: [],
    components: { categories: [], items: {} },
    languages: { pages: [], snapshots: {} },
    langStatus: [],
    langs: {},
    stats: {},
    perms: PERMS,
    device: 'desktop',
    undo: [],
    redo: [],
    pending: 0,
    queue: Promise.resolve(),
    tab: 'pages',
    scope: null,
    restore: null,
    tree: null,
    sourceContent: '',
    publishSelection: {},
    lastPublish: null,
    warnings: [],
    dirty: false,
    media: { items: [], cloud: {}, count: 0 }
};

var iframe = $('#preview');
var ORIGIN = location.origin;

/* ------------------------------------------------------------------ */
/* Vorschau                                                            */
/* ------------------------------------------------------------------ */

function previewUrl(variant) {
    return '/preview?path=' + encodeURIComponent(state.file) + '&variant=' + (variant || 'draft');
}

function currentScroll() {
    try { return iframe.contentWindow.scrollY || 0; } catch (e) { return 0; }
}

function loadPreview(keepSelection) {
    if (!state.file) return;
    state.restore = keepSelection ? { sel: state.selection ? state.selection.path : null, scroll: currentScroll() } : null;
    iframe.src = previewUrl('draft') + '&t=' + Date.now();
}

function postToFrame(msg) {
    try {
        msg.__wfx = true;
        iframe.contentWindow.postMessage(msg, ORIGIN);
    } catch (e) { /* ignore */ }
}

iframe.addEventListener('load', function () {
    var restore = state.restore;
    if (restore) {
        setTimeout(function () { postToFrame({ type: 'restore', sel: restore.sel, scroll: restore.scroll }); }, 80);
        state.restore = null;
    }
});

window.addEventListener('message', function (e) {
    if (e.origin !== ORIGIN) return;
    var d = e.data || {};
    if (!d.__wfx) return;
    if (d.type === 'ready') {
        if (state.restore) {
            postToFrame({ type: 'restore', sel: state.restore.sel, scroll: state.restore.scroll });
            state.restore = null;
        }
        return;
    }
    if (d.type === 'select') {
        state.selection = d;
        state.path = d.path;
        loadInfo(d.path);
        return;
    }
    if (d.type === 'context') {
        state.selection = { path: d.path, tags: d.tags, tag: d.tag, breadcrumb: d.breadcrumb, computed: d.computed || null };
        state.info = null;
        loadInfo(d.path);
        openContextMenu(d);
        return;
    }
    if (d.type === 'cleared') {
        state.selection = null;
        state.info = null;
        renderInspector();
        renderBreadcrumb();
        $('#statusSelection').textContent = '';
        return;
    }
    if (d.type === 'notice') { toast(d.message, 'warn'); }
    if (d.type === 'localFailed') { loadPreview(true); }
});

/* ------------------------------------------------------------------ */
/* Daten laden                                                         */
/* ------------------------------------------------------------------ */

function loadState() {
    return api('/api/state', undefined, true).then(function (res) {
        state.pages = res.pages || [];
        state.components = res.components || { categories: [], items: {} };
        state.languages = res.languages || { pages: [], snapshots: {} };
        state.langStatus = res.languageStatus || [];
        state.langs = res.langs || {};
        state.stats = res.stats || {};
        state.perms = (res.user && res.user.perms) || PERMS;
        state.renderPhp = res.renderPhp;
        state.baseUrl = res.baseUrl;
        state.source = res.source;
        state.phpVersion = res.php;
        state.nodeAvailable = res.node;
        $('#sourceInfo').textContent = res.source && res.source.host ? res.source.host : 'lokale Quelle';
        if (res.stats && res.stats.drafts) {
            $('#statusQueue').textContent = res.stats.drafts + ' offene Änderung(en)';
        }
        renderPageSelect();
        renderPanel();
        loadRemoteIndex();
        loadMedia();
        return res;
    }).catch(function (err) {
        toast(err.message, 'err');
        if (err.status === 401) location.href = '/login';
        throw err;
    });
}

function loadInfo(path) {
    if (!state.file) return Promise.resolve();
    return api('/api/info', { file: state.file, path: path }).then(function (res) {
        state.info = res.info;
        state.selection = state.selection || {};
        state.selection.computed = state.selection.computed || null;
        renderInspector();
        renderBreadcrumb();
        $('#statusSelection').textContent = res.info.breadcrumb.map(function (b) { return b.tag; }).join(' › ');
    }).catch(function (err) {
        toast(err.message, 'err');
        state.info = null;
        renderInspector();
    });
}

function openFile(path) {
    if (!path) return;
    // Noch nicht geladene Datei: erst jetzt vom Server holen (mit ihren Referenzen)
    if (needsFetch(path)) {
        return fetchFromServer(path).then(function () {
            return loadState().then(function () { return openFile(path); });
        }).catch(function (err) { toast(err.message, 'err'); });
    }
    state.file = path;
    state.etag = null;
    state.info = null;
    state.selection = null;
    state.undo = [];
    state.redo = [];
    state.tree = null;
    state.warnings = [];
    updateUndoButtons();
    $('#statusFile').textContent = path;
    localStorage.setItem('wfx.lastFile', path);
    var sel = $('#pageSelect');
    if (sel.value !== path) sel.value = path;
    markDirty(false);
    return api('/api/file?path=' + encodeURIComponent(path)).then(function (res) {
        state.etag = res.etag;
        state.sourceContent = res.content;
        state.fileDraft = res.isDraft;
        markDirty(res.isDraft);
        renderInspector();
        renderBreadcrumb();
        loadPreview(false);
        if (state.tab === 'source' || state.tab === 'pages') renderPanel();
    }).catch(function (err) {
        toast(err.message, 'err');
    });
}

function markDirty(isDirty) {
    state.dirty = !!isDirty;
    $('#draftBadge').classList.toggle('hidden', !state.dirty);
}

/** Zeigt an, dass die letzte Aenderung bereits auf der Platte liegt. */
function markSaved() {
    var el = $('#statusQueue');
    if (!el) return;
    el.textContent = 'Gespeichert ' + new Date().toLocaleTimeString();
    el.classList.remove('queue-idle');
}

function updateQueue() {
    var el = $('#statusQueue');
    if (state.pending > 0) {
        el.textContent = 'Speichere … (' + state.pending + ')';
        el.className = 'queue-busy';
    } else {
        el.textContent = state.dirty ? 'Entwurf gespeichert (nicht veröffentlicht)' : 'Gespeichert';
        el.className = state.dirty ? 'queue-busy' : 'queue-idle';
    }
}

/* ------------------------------------------------------------------ */
/* Änderungen anwenden (optimistisch + serverseitig)                   */
/* ------------------------------------------------------------------ */

function applyOps(ops, label, opts) {
    opts = opts || {};
    if (!state.file) { toast('Bitte zuerst eine Seite öffnen.', 'warn'); return Promise.resolve(); }
    ops.forEach(function (op) { postToFrame({ type: 'apply', op: op }); });
    state.pending++;
    updateQueue();

    var run = function () {
        var payload = { file: state.file, ops: ops, baseEtag: state.etag };
        if (opts.scope) payload.scope = opts.scope;
        if (opts.history) payload.undo = true;
        return api('/api/edit', payload).then(function (res) {
            state.etag = res.etag;
            if (res.global) {
                toast(res.message || 'Globale Komponente aktualisiert', 'ok');
                state.undo = [];
                state.redo = [];
                return refreshAfterStructural();
            }
            if (!opts.noHistory) {
                if (opts.history) {
                    state.redo.push({ label: label, ops: res.inverse || [] });
                } else {
                    state.undo.push({ label: label, ops: ops, inverse: res.inverse || [] });
                    state.redo = [];
                }
                updateUndoButtons();
            }
            if (res.warnings && res.warnings.length) res.warnings.forEach(function (w) { toast(w, 'warn'); });
            if (res.info && !isInspectorFocused()) {
                state.info = res.info;
                renderInspector();
            }
            markDirty(true);
            markSaved();
            if (opts.structural) return refreshAfterStructural();
            return null;
        }).catch(function (err) {
            if (err.data && err.data.needsScope) {
                return askScope(err.data).then(function (scope) {
                    if (!scope) { loadPreview(true); throw new Error('Abgebrochen'); }
                    state.pending--;
                    return applyOps(ops, label, Object.assign({}, opts, { scope: scope }));
                });
            }
            loadPreview(true);
            throw err;
        }).then(function (v) {
            state.pending--;
            updateQueue();
            return v;
        }, function (e) {
            state.pending--;
            updateQueue();
            if (e.message !== 'Abgebrochen') toast(e.message, 'err');
        });
    };
    state.queue = state.queue.then(run, run);
    return state.queue;
}

function isInspectorFocused() {
    var insp = $('#inspector');
    return insp.contains(document.activeElement) && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
}

function askScope(data) {
    var impact = data.impact || { pages: [], instances: 0 };
    var pages = impact.pages.map(function (p) { return h('li', { text: p.path + ' (' + p.instances + '×)' }); });
    var body = h('div', null, [
        h('p', { text: 'Das Element gehört zur globalen Komponente "' + data.component.slug + '".' }),
        h('p', { text: 'Betroffen wären ' + impact.instances + ' Instanz(en) auf ' + impact.pages.length + ' Seite(n):' }),
        h('ul', null, pages)
    ]);
    return modal({
        title: 'Änderungsbereich wählen',
        body: body,
        actions: [
            { label: 'Abbrechen', value: null },
            { label: 'Nur diese Instanz (Kopie)', value: 'copy' },
            { label: 'Alle Instanzen (global)', kind: 'primary', value: 'global' }
        ]
    });
}

function refreshAfterStructural() {
    state.info = null;
    state.selection = null;
    state.tree = null;
    return loadState().then(function () {
        loadPreview(false);
        renderBreadcrumb();
        renderInspector();
    });
}

function undo() {
    if (!state.undo.length || !state.file) return;
    var entry = state.undo.pop();
    updateUndoButtons();
    applyOps(entry.inverse, 'Rückgängig: ' + entry.label, { history: true, noHistory: true, structural: true }).then(function () {
        state.redo.push({ label: entry.label, ops: entry.inverse });
        updateUndoButtons();
        loadPreview(true);
    });
}

function redo() {
    if (!state.redo.length || !state.file) return;
    var entry = state.redo.pop();
    updateUndoButtons();
    applyOps(entry.ops, 'Wiederholen: ' + entry.label, { history: true, noHistory: true, structural: true }).then(function () {
        state.undo.push({ label: entry.label, ops: entry.ops, inverse: [] });
        updateUndoButtons();
        loadPreview(true);
    });
}

function updateUndoButtons() {
    $('#btnUndo').disabled = !state.undo.length;
    $('#btnRedo').disabled = !state.redo.length;
}

/* ------------------------------------------------------------------ */
/* Seitenauswahl / Grundgerüst                                         */
/* ------------------------------------------------------------------ */

function renderPageSelect() {
    var sel = $('#pageSelect');
    sel.innerHTML = '';
    var pending = remoteOnlyPages();
    if (!state.pages.length && !pending.length) {
        sel.appendChild(h('option', { value: '', text: 'Keine Seiten gefunden' }));
        return;
    }
    state.pages.forEach(function (p) {
        sel.appendChild(h('option', {
            value: p.path,
            text: (p.lang !== 'und' ? '[' + p.lang + '] ' : '') + p.path + (p.draft ? ' •' : '')
        }));
    });
    pending.forEach(function (e) {
        sel.appendChild(h('option', { value: e.path, text: '⤓ ' + e.path + ' (Server)' }));
    });
    if (state.file) sel.value = state.file;
}

/* --- Objektliste des Servers (ohne Download) ---------------------- */

function loadRemoteIndex() {
    return api('/api/remote-index', undefined, true).then(function (res) {
        state.remote = {
            entries: res.entries || [],
            listedAt: res.listedAt || null,
            counts: res.counts || {},
            warnings: res.warnings || []
        };
        renderPageSelect();
        return res;
    }).catch(function () { /* Serverliste ist optional */ });
}

function remoteEntry(path) {
    var list = state.remote.entries || [];
    for (var i = 0; i < list.length; i++) {
        if (list[i].path === path) return list[i];
    }
    return null;
}

function remoteOnlyPages() {
    return (state.remote.entries || []).filter(function (e) {
        if (!e.page || e.local) return false;
        return !state.pages.some(function (p) { return p.path === e.path; });
    });
}

function firstRemotePage() {
    var list = remoteOnlyPages();
    return list.length ? list[0].path : null;
}

function needsFetch(path) {
    var e = remoteEntry(path);
    return !!(e && !e.local);
}

function fetchFromServer(path) {
    toast('Lade ' + path + ' vom Server …', '');
    return api('/api/fetch-file', { path: path }).then(function (res) {
        var data = res.data || {};
        (data.files || []).forEach(function (p) {
            var e = remoteEntry(p);
            if (e) e.local = true;
            if (state.remote.counts && typeof state.remote.counts.local === 'number') state.remote.counts.local++;
        });
        toast(path + ' geladen (' + Math.round((data.bytes || 0) / 1024) + ' KB, ' + (data.files || []).length + ' Datei(en)).', 'ok');
        if (data.failed && data.failed.length) {
            toast(data.failed.length + ' Begleitdatei(en) fehlen auf dem Server: '
                + data.failed.slice(0, 3).map(function (f) { return f.file; }).join(', '), 'warn');
        }
        return data;
    });
}

function renderBreadcrumb() {
    var bar = $('#breadcrumb');
    bar.innerHTML = '';
    var info = state.info;
    if (!info || !info.breadcrumb) {
        bar.appendChild(h('span', { class: 'crumb-empty', text: 'Kein Element ausgewählt – im Vorschaufenster klicken.' }));
        return;
    }
    info.breadcrumb.forEach(function (step, i) {
        if (i > 0) bar.appendChild(h('span', { class: 'sep', text: '›' }));
        bar.appendChild(h('button', {
            class: 'crumb' + (i === info.breadcrumb.length - 1 ? ' last' : ''),
            type: 'button',
            title: step.label,
            text: step.label.length > 34 ? step.label.slice(0, 34) + '…' : step.label,
            onclick: function () { postToFrame({ type: 'select', path: step.path }); }
        }));
    });
}

/* ------------------------------------------------------------------ */
/* Eigenschaften-Panel                                                 */
/* ------------------------------------------------------------------ */

function parseInlineStyle(raw) {
    var out = {};
    String(raw || '').split(';').forEach(function (part) {
        var i = part.indexOf(':');
        if (i > 0) out[part.slice(0, i).trim().toLowerCase()] = part.slice(i + 1).trim();
    });
    return out;
}

function computedProp(prop) {
    if (state.selection && state.selection.computed && state.selection.computed.props) {
        return state.selection.computed.props[prop] || '';
    }
    return '';
}

function rgbToHex(value) {
    if (!value) return '#000000';
    if (/^#[0-9a-f]{3,8}$/i.test(value)) return value.length === 4
        ? '#' + value[1] + value[1] + value[2] + value[2] + value[3] + value[3]
        : value.slice(0, 7);
    var m = String(value).match(/rgba?\(([^)]+)\)/i);
    if (!m) return '#000000';
    var parts = m[1].split(',').map(function (x) { return parseFloat(x); });
    return '#' + [0, 1, 2].map(function (i) {
        var v = Math.max(0, Math.min(255, Math.round(parts[i] || 0)));
        return ('0' + v.toString(16)).slice(-2);
    }).join('');
}

function styleOp(prop, value) {
    var info = state.info;
    if (!info) return;
    applyOps([{ kind: 'style', path: info.path, tags: info.tags, prop: prop, value: value }], 'Stil: ' + prop);
}

function attrOp(name, value) {
    var info = state.info;
    if (!info) return;
    applyOps([{ kind: 'attr', path: info.path, tags: info.tags, name: name, value: value }], 'Attribut: ' + name);
}

function textOp(value) {
    var info = state.info;
    if (!info) return;
    applyOps([{ kind: 'text', path: info.path, tags: info.tags, value: value }], 'Text geändert');
}

function classToggle(token, add) {
    var info = state.info;
    if (!info) return;
    var op = add ? { kind: 'class', path: info.path, tags: info.tags, add: [token], remove: [] }
                 : { kind: 'class', path: info.path, tags: info.tags, add: [], remove: [token] };
    applyOps([op], 'Klasse: ' + token);
}

function fieldRow(labelText, control) {
    return h('div', { class: 'field' }, [h('label', { text: labelText }), control]);
}

function textField(value, onChange, opts) {
    opts = opts || {};
    var input = h('input', {
        type: opts.type || 'text',
        value: value === null || value === undefined ? '' : value,
        placeholder: opts.placeholder || '',
        list: opts.list || null
    });
    var fire = debounce(function () { onChange(input.value); }, opts.immediate ? 0 : 320);
    input.addEventListener('input', fire);
    input.addEventListener('change', function () { if (opts.immediate) onChange(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') { e.preventDefault(); onChange(input.value); input.blur(); }
    });
    return input;
}

function selectField(options, value, onChange) {
    var sel = h('select');
    options.forEach(function (opt) {
        sel.appendChild(h('option', { value: opt.value, text: opt.label }));
    });
    sel.value = value === null || value === undefined ? '' : value;
    sel.addEventListener('change', function () { onChange(sel.value); });
    return sel;
}

function styleText(prop, label, opts) {
    opts = opts || {};
    var inline = parseInlineStyle(state.info && state.info.attrs ? state.info.attrs.style : '');
    var value = inline[prop] !== undefined ? inline[prop] : '';
    var placeholder = computedProp(prop);
    var input = textField(value, function (v) {
        styleOp(prop, v === '' ? null : v);
    }, { placeholder: placeholder, list: opts.list });
    return fieldRow(label, input);
}

function styleColor(prop, label) {
    var inline = parseInlineStyle(state.info && state.info.attrs ? state.info.attrs.style : '');
    var raw = inline[prop] !== undefined ? inline[prop] : '';
    var color = h('input', { type: 'color', value: rgbToHex(raw || computedProp(prop)) });
    var text = textField(raw, function (v) { styleOp(prop, v === '' ? null : v); }, { placeholder: computedProp(prop) });
    color.addEventListener('input', debounce(function () { styleOp(prop, color.value); text.value = color.value; }, 120));
    return fieldRow(label, h('div', { class: 'pair' }, [color, text]));
}

function styleSelect(prop, label, options) {
    var inline = parseInlineStyle(state.info && state.info.attrs ? state.info.attrs.style : '');
    var value = inline[prop] !== undefined ? inline[prop] : '';
    var opts = [{ value: '', label: '– automatisch (' + (computedProp(prop) || 'n/a') + ')' }].concat(options);
    return fieldRow(label, selectField(opts, value, function (v) { styleOp(prop, v === '' ? null : v); }));
}

function renderInspector() {
    var insp = $('#inspector');
    insp.innerHTML = '';
    var info = state.info;
    if (!info) {
        insp.appendChild(h('div', { class: 'inspector-empty' }, [
            h('p', null, [h('strong', { text: 'Element auswählen' })]),
            h('p', { class: 'muted', text: 'Klicken Sie ein Element in der Vorschau an. Das Panel zeigt dann nur die Eigenschaften, die für dieses Element bearbeitet werden können.' }),
            h('ul', { class: 'hints' }, [
                h('li', { html: '<kbd>Alt</kbd> + <kbd>↑</kbd> wählt das übergeordnete Element' }),
                h('li', { html: '<kbd>Alt</kbd> + <kbd>↓</kbd> wählt das erste Kindelement' }),
                h('li', { html: '<kbd>Esc</kbd> hebt die Auswahl auf' })
            ]),
            h('div', { class: 'insp-group' }, [
                h('h3', { text: 'Dokumentstruktur' }),
                h('div', { id: 'treeBox' }, [h('p', { class: 'empty-note', text: 'Struktur wird geladen …' })])
            ])
        ]));
        renderTree();
        return;
    }

    insp.appendChild(h('div', { class: 'insp-head' }, [
        h('span', { class: 'tag', text: '<' + info.tag + '>' }),
        h('span', { class: 'ctx', text: info.breadcrumb.slice(-2).map(function (b) { return b.tag; }).join(' › ') })
    ]));

    if (info.component) {
        var comp = info.component;
        var item = state.components.items[comp.slug] || { name: comp.slug, category: '' };
        var banner = h('div', { class: 'banner' }, [
            h('div', null, [
                h('strong', { text: 'Baustein: ' + item.name }),
                h('span', { class: 'muted', text: ' · ' + (comp.mode === 'global' ? 'global (alle Instanzen)' : 'unabhängige Kopie') })
            ]),
            h('div', { class: 'banner-actions' }, [
                comp.isRoot ? null : h('button', { class: 'btn small', type: 'button', text: 'Instanz auswählen', onclick: function () { postToFrame({ type: 'select', path: comp.instancePath }); } }),
                comp.mode === 'global' ? h('button', {
                    class: 'btn small', type: 'button', text: 'Als Kopie lösen',
                    onclick: function () {
                        api('/api/component-detach', { file: state.file, path: comp.instancePath }).then(function () {
                            toast('Instanz ist jetzt unabhängig.', 'ok');
                            refreshAfterStructural();
                        }).catch(function (e) { toast(e.message, 'err'); });
                    }
                }) : null,
                h('button', {
                    class: 'btn small', type: 'button', text: 'Alle Instanzen ändern',
                    onclick: function () {
                        state.forceScope = 'global';
                        toast('Nächste Änderung gilt für alle Instanzen.', 'warn');
                    }
                })
            ])
        ]);
        insp.appendChild(banner);
    }

    (info.warnings || []).forEach(function (w) {
        insp.appendChild(h('div', { class: 'banner warn', text: w }));
    });

    if (info.tid) {
        insp.appendChild(translationBox(info));
    }

    if (info.canEditText) {
        var area = h('textarea', { rows: 3 });
        area.value = info.text;
        var fire = debounce(function () { textOp(area.value); }, 320);
        area.addEventListener('input', fire);
        insp.appendChild(h('details', { class: 'insp-group', open: true }, [
            h('summary', { text: 'Inhalt' }),
            fieldRow('Text', area),
            h('p', { class: 'hint-inline', text: 'Änderungen erscheinen sofort in der Vorschau und werden automatisch als Entwurf gesichert.' })
        ]));
    } else if (info.children.length) {
        insp.appendChild(h('details', { class: 'insp-group', open: true }, [
            h('summary', { text: 'Inhalt' }),
            h('p', { class: 'empty-note', text: 'Dieses Element enthält weitere Elemente. Bitte ein inneres Element wählen:' }),
            h('div', { class: 'insp-actions' }, info.children.slice(0, 20).map(function (c) {
                return h('button', {
                    class: 'btn small', type: 'button', text: c.label,
                    onclick: function () { postToFrame({ type: 'select', path: c.path }); }
                });
            }))
        ]));
    }

    /* Link / Bild */
    var linkPanel = linkEditor(info);
    if (linkPanel) insp.appendChild(linkPanel);
    if (info.tag === 'img') {
        insp.appendChild(h('details', { class: 'insp-group', open: true }, [
            h('summary', { text: 'Bild' }),
            fieldRow('Quelle (src)', textField(info.attrs.src || '', function (v) { attrOp('src', v); }, { placeholder: 'assets/images/bild.webp' })),
            fieldRow('Alt-Text', textField(info.attrs.alt || '', function (v) { attrOp('alt', v); })),
            h('div', { class: 'insp-actions' }, [
                h('button', { class: 'btn small', type: 'button', text: 'Bild hochladen …', onclick: uploadImage }),
                h('button', { class: 'btn small', type: 'button', text: 'Aus Bibliothek …', onclick: function () { imagePicker({ target: 'src' }); } })
            ])
        ]));
    }

    /* Darstellung */
    var styleInline = parseInlineStyle(info.attrs.style || '');
    var typo = [
        styleColor('color', 'Textfarbe'),
        styleColor('background-color', 'Hintergrund'),
        styleText('font-size', 'Schriftgröße', { list: 'sizes' }),
        styleSelect('font-weight', 'Schriftstärke', [
            { value: '300', label: '300 leicht' }, { value: '400', label: '400 normal' },
            { value: '600', label: '600 halbfett' }, { value: '700', label: '700 fett' }, { value: '900', label: '900 schwarz' }
        ]),
        styleText('font-family', 'Schriftart'),
        styleText('line-height', 'Zeilenhöhe', { list: 'sizes' }),
        styleSelect('text-align', 'Ausrichtung', [
            { value: 'left', label: 'links' }, { value: 'center', label: 'zentriert' },
            { value: 'right', label: 'rechts' }, { value: 'justify', label: 'Blocksatz' }
        ]),
        styleSelect('text-transform', 'Transformation', [
            { value: 'none', label: 'keine' }, { value: 'uppercase', label: 'GROSSBUCHSTABEN' },
            { value: 'capitalize', label: 'Erster Buchstabe groß' }
        ])
    ];
    insp.appendChild(h('details', { class: 'insp-group', open: true }, [h('summary', { text: 'Typografie' })].concat(typo)));

    insp.appendChild(h('details', { class: 'insp-group' }, [
        h('summary', { text: 'Abstand & Rahmen' }),
        styleText('padding', 'Innenabstand', { list: 'sizes' }),
        styleText('margin', 'Außenabstand', { list: 'sizes' }),
        styleText('border-radius', 'Rundung', { list: 'sizes' }),
        styleText('border', 'Rahmen', { placeholder: '1px solid #cbd5e1' }),
        styleText('box-shadow', 'Schatten', { placeholder: '0 10px 30px rgba(0,0,0,.12)' }),
        styleText('max-width', 'max. Breite', { list: 'sizes' })
    ]));

    insp.appendChild(h('details', { class: 'insp-group' }, [
        h('summary', { text: 'Layout' }),
        styleSelect('display', 'Anzeige', [
            { value: 'block', label: 'block' }, { value: 'inline', label: 'inline' },
            { value: 'inline-block', label: 'inline-block' }, { value: 'flex', label: 'flex' },
            { value: 'grid', label: 'grid' }, { value: 'none', label: 'ausgeblendet (none)' }
        ]),
        styleText('width', 'Breite', { list: 'sizes' }),
        styleText('height', 'Höhe', { list: 'sizes' }),
        styleText('gap', 'Abstand (gap)', { list: 'sizes' }),
        styleSelect('flex-direction', 'Flex-Richtung', [
            { value: 'row', label: 'row' }, { value: 'column', label: 'column' },
            { value: 'row-reverse', label: 'row-reverse' }, { value: 'column-reverse', label: 'column-reverse' }
        ]),
        styleSelect('justify-content', 'Verteilung', [
            { value: 'flex-start', label: 'Anfang' }, { value: 'center', label: 'zentriert' },
            { value: 'space-between', label: 'gleichmäßig (space-between)' }, { value: 'flex-end', label: 'Ende' }
        ]),
        styleSelect('align-items', 'Ausrichtung (Kreuzachse)', [
            { value: 'stretch', label: 'stretch' }, { value: 'flex-start', label: 'Anfang' },
            { value: 'center', label: 'zentriert' }, { value: 'flex-end', label: 'Ende' }
        ]),
        styleText('opacity', 'Deckkraft', { placeholder: '1' })
    ]));

    /* Responsive */
    insp.appendChild(responsiveBox(info));

    /* Klassen */
    var classes = String(info.attrs.class || '').split(/\s+/).filter(Boolean);
    var chips = classes.map(function (c) {
        return h('span', { class: 'chip' }, [c, h('button', { type: 'button', text: '×', title: 'Klasse entfernen', onclick: function () { classToggle(c, false); } })]);
    });
    var newClass = h('input', { type: 'text', placeholder: 'neue Klasse' });
    insp.appendChild(h('details', { class: 'insp-group' }, [
        h('summary', { text: 'Klassen' }),
        h('div', null, chips),
        h('div', { class: 'row' }, [
            newClass,
            h('button', {
                class: 'btn', type: 'button', text: 'Hinzufügen',
                onclick: function () { if (newClass.value.trim()) { classToggle(newClass.value.trim(), true); newClass.value = ''; } }
            })
        ])
    ]));

    /* Attribute */
    insp.appendChild(h('details', { class: 'insp-group' }, [
        h('summary', { text: 'Attribute' }),
        fieldRow('id', textField(info.attrs.id || '', function (v) { attrOp('id', v === '' ? null : v); })),
        fieldRow('title', textField(info.attrs.title || '', function (v) { attrOp('title', v === '' ? null : v); })),
        fieldRow('role', textField(info.attrs.role || '', function (v) { attrOp('role', v === '' ? null : v); })),
        fieldRow('aria-label', textField(info.attrs['aria-label'] || '', function (v) { attrOp('aria-label', v === '' ? null : v); }))
    ]));

    /* Struktur / Aktionen */
    var canEdit = state.perms.indexOf('edit') !== -1;
    var actions = [];
    if (canEdit) {
        actions.push(h('button', { class: 'btn small', type: 'button', text: '↑ nach oben', onclick: function () { moveSibling(-1); } }));
        actions.push(h('button', { class: 'btn small', type: 'button', text: '↓ nach unten', onclick: function () { moveSibling(1); } }));
        actions.push(h('button', { class: 'btn small', type: 'button', text: 'Duplizieren', onclick: function () {
            applyOps([{ kind: 'duplicate', path: info.path, tags: info.tags }], 'Element dupliziert', { structural: true });
        } }));
        actions.push(h('button', { class: 'btn small danger', type: 'button', text: 'Löschen', onclick: function () {
            confirmDialog('Element löschen', 'Das Element <' + info.tag + '> wird aus der Seite entfernt. Die Änderung ist als Entwurf rückgängig zu machen (Strg+Z).', 'Löschen', 'danger').then(function (ok) {
                if (!ok) return;
                applyOps([{ kind: 'delete', path: info.path, tags: info.tags }], 'Element gelöscht', { structural: true });
            });
        } }));
        if (state.perms.indexOf('components') !== -1) {
            actions.push(h('button', { class: 'btn small', type: 'button', text: 'Als Baustein speichern', onclick: function () { saveAsComponent(info); } }));
        }
    }
    insp.appendChild(h('details', { class: 'insp-group', open: true }, [h('summary', { text: 'Aktionen' })].concat(actions)));

    /* Quelltext des Elements */
    var inner = h('textarea', { class: 'code-area', style: 'min-height:120px' });
    inner.value = info.innerHtml;
    insp.appendChild(h('details', { class: 'insp-group' }, [
        h('summary', { text: 'HTML dieses Elements' }),
        inner,
        h('div', { class: 'insp-actions' }, [
            h('button', {
                class: 'btn small', type: 'button', text: 'Inhalt übernehmen',
                onclick: function () {
                    confirmDialog('HTML-Inhalt ersetzen', 'Der Inhalt dieses Elements wird durch den eingegebenen HTML-Code ersetzt. PHP-Code und Skripte werden abgelehnt.', 'Übernehmen').then(function (ok) {
                        if (!ok) return;
                        applyOps([{ kind: '_inner', path: info.path, tags: info.tags, html: inner.value }], 'HTML-Inhalt geändert', { structural: true });
                    });
                }
            })
        ]),
        h('p', { class: 'hint-inline', text: 'Der äußere Rahmen des Elements bleibt unverändert; nur der Inhalt wird ersetzt.' })
    ]));

    /* Dokumentstruktur */
    insp.appendChild(h('details', { class: 'insp-group' }, [
        h('summary', { text: 'Dokumentstruktur (Drag & Drop)' }),
        h('div', { id: 'treeBox' }, [h('p', { class: 'empty-note', text: 'Struktur wird geladen …' })])
    ]));
    renderTree();
}

/* ------------------------------------------------------------------ */
/* Link-Bearbeitung (auch fuer Elemente in einem Link)                 */
/* ------------------------------------------------------------------ */

function linkEditor(info) {
    var link = info.link;
    if (!link) return null;
    var path = link.path;
    var tags = link.tags;
    var set = function (name, value) {
        applyOps([{ kind: 'attr', path: path, tags: tags, name: name, value: value }], 'Link: ' + name);
    };
    var href = link.href === null || link.href === undefined ? '' : link.href;
    var rows = [];
    rows.push(fieldRow('Ziel (href)', textField(href, function (v) {
        set('href', v.trim() === '' ? null : v.trim());
    }, { placeholder: '/de/seite.html oder https://…' })));
    if (state.pages.length) {
        rows.push(fieldRow('Seite wählen', selectField(
            [{ value: '', label: '– unverändert –' }].concat(state.pages.map(function (p) {
                return { value: p.path, label: p.path };
            })),
            state.pages.some(function (p) { return p.path === href; }) ? href : '',
            function (v) { if (v) set('href', v); }
        )));
    }
    rows.push(fieldRow('Öffnen', selectField([
        { value: '', label: 'gleiches Fenster' },
        { value: '_blank', label: 'neues Fenster / Tab' }
    ], link.target === '_blank' ? '_blank' : '', function (v) {
        set('target', v === '' ? null : v);
        if (v === '_blank' && String(link.rel || '').indexOf('noopener') === -1) set('rel', 'noopener');
    })));
    rows.push(fieldRow('Rel', textField(link.rel || '', function (v) { set('rel', v === '' ? null : v); }, { placeholder: 'noopener' })));
    rows.push(fieldRow('Titel', textField(link.title || '', function (v) { set('title', v === '' ? null : v); }, { placeholder: 'Tooltip' })));
    var actions = [
        h('button', {
            class: 'btn small', type: 'button', text: link.isSelf ? 'Link ist gewählt' : 'Link markieren',
            disabled: link.isSelf ? true : null,
            onclick: function () { postToFrame({ type: 'select', path: path }); }
        }),
        h('button', {
            class: 'btn small', type: 'button', text: 'Ziel öffnen',
            onclick: function () {
                if (!href) { toast('Der Link hat kein Ziel (href).', 'warn'); return; }
                window.open(href, '_blank', 'noopener');
            }
        }),
        h('button', {
            class: 'btn small danger', type: 'button', text: 'Link entfernen',
            onclick: function () {
                confirmDialog('Link entfernen', 'Das <a>-Element bleibt erhalten, verliert aber sein Ziel (href).', 'Entfernen', 'danger')
                    .then(function (ok) { if (ok) set('href', null); });
            }
        })
    ];
    return h('details', { class: 'insp-group', open: true }, [
        h('summary', { text: 'Link' })
    ].concat(rows).concat([
        link.isSelf ? null : h('p', { class: 'hint-inline', text: 'Dieses Element liegt im Link ' + (link.label || '<a>') + '. Die Felder ändern den Link, nicht den Text.' }),
        href === '' ? h('p', { class: 'banner warn', text: 'Kein Ziel gesetzt – dieser Link führt nirgendwohin.' }) : null,
        h('div', { class: 'insp-actions' }, actions)
    ]));
}

/* ------------------------------------------------------------------ */
/* Rechtsklick-Menü                                                    */
/* ------------------------------------------------------------------ */

var ctxMenuEl = null;

function hideContextMenu() {
    if (ctxMenuEl) {
        ctxMenuEl.remove();
        ctxMenuEl = null;
    }
}

function modalCloseAll() {
    $$('#modalRoot .modal-backdrop').forEach(function (b) { b.remove(); });
}

function copyText(text) {
    if (!text) { toast('Nichts zu kopieren.', 'warn'); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { toast('In die Zwischenablage kopiert.', 'ok'); }, function () { fallbackCopy(text); });
        return;
    }
    fallbackCopy(text);
}

function fallbackCopy(text) {
    var ta = h('textarea', { style: 'position:fixed;left:-9999px;top:0' });
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast('In die Zwischenablage kopiert.', 'ok'); }
    catch (e) { toast('Kopieren nicht möglich.', 'warn'); }
    ta.remove();
}

function withInfo(path, fn) {
    if (!state.file) { toast('Bitte zuerst eine Seite öffnen.', 'warn'); return; }
    if (state.info && state.info.path.join('/') === path.join('/')) {
        fn(state.info);
        return;
    }
    api('/api/info', { file: state.file, path: path }).then(function (res) { fn(res.info); })
        .catch(function (e) { toast(e.message, 'err'); });
}

function runStructural(ops, label) {
    applyOps(ops, label || 'Änderung', { structural: true });
}

function openContextMenu(msg) {
    hideContextMenu();
    if (!state.file) return;
    var rect = iframe.getBoundingClientRect();
    var left = rect.left + (msg.x || 0);
    var top = rect.top + (msg.y || 0);
    left = Math.max(8, Math.min(left, window.innerWidth - 250));
    top = Math.max(8, Math.min(top, window.innerHeight - 330));
    var menu = h('div', { class: 'ctx-menu', style: 'left:' + Math.round(left) + 'px;top:' + Math.round(top) + 'px' });
    var add = function (label, fn, kind) {
        menu.appendChild(h('button', {
            class: 'ctx-item' + (kind ? ' ' + kind : ''), type: 'button', text: label,
            onclick: function (e) { e.stopPropagation(); hideContextMenu(); fn(); }
        }));
    };
    var canEdit = state.perms.indexOf('edit') !== -1;
    var path = msg.path || [];
    var tags = msg.tags || [];
    var last = path.length - 1;

    add('Eigenschaften öffnen', function () {
        postToFrame({ type: 'select', path: path });
        var insp = $('#inspector');
        if (insp) insp.scrollTop = 0;
    });
    if (msg.link && !msg.link.isSelf) {
        add('Link bearbeiten', function () {
            postToFrame({ type: 'select', path: msg.link.path });
            toast('Der Link ist das übergeordnete <a> – die Link-Felder stehen rechts.', '');
        });
    }
    if (canEdit && path.length > 1) {
        menu.appendChild(h('div', { class: 'ctx-sep' }));
        add('Nach oben', function () {
            runStructural([{ kind: 'move', path: path, tags: tags, target: { parent: path.slice(0, last), index: path[last] - 1 } }], 'Element verschoben');
        });
        add('Nach unten', function () {
            runStructural([{ kind: 'move', path: path, tags: tags, target: { parent: path.slice(0, last), index: path[last] + 1 } }], 'Element verschoben');
        });
        add('Duplizieren', function () {
            runStructural([{ kind: 'duplicate', path: path, tags: tags }], 'Element dupliziert');
        });
        add('Bild aus Bibliothek …', function () {
            imagePicker({ afterPath: path });
        });
        if (state.perms.indexOf('components') !== -1) {
            add('Als Baustein speichern', function () { withInfo(path, saveAsComponent); });
        }
        add('Löschen', function () {
            confirmDialog('Element löschen', 'Das Element <' + (msg.tag || '?') + '> wird aus der Seite entfernt. Rückgängig mit Strg+Z.', 'Löschen', 'danger')
                .then(function (ok) {
                    if (!ok) return;
                    runStructural([{ kind: 'delete', path: path, tags: tags }], 'Element gelöscht');
                });
        }, 'danger');
    }
    menu.appendChild(h('div', { class: 'ctx-sep' }));
    add('HTML kopieren', function () {
        withInfo(path, function (info) { copyText(info.innerHtml); });
    });
    add('Übergeordnetes Element', function () {
        if (path.length > 1) postToFrame({ type: 'select', path: path.slice(0, last) });
    });
    document.body.appendChild(menu);
    ctxMenuEl = menu;
}

/* ------------------------------------------------------------------ */
/* Medienbibliothek                                                    */
/* ------------------------------------------------------------------ */

function loadMedia() {
    return api('/api/media-list', undefined, true).then(function (res) {
        state.media = res.data || { items: [], cloud: {}, count: 0 };
        return state.media;
    }).catch(function () { return state.media; });
}

function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
}

function mediaCard(it) {
    var usages = (it.usages || []).slice(0, 4).map(function (u) {
        return h('button', {
            class: 'chip', type: 'button', text: String(u.page).split('/').pop(),
            title: u.page + ' (' + u.count + '×)',
            onclick: function () { openFile(u.page); }
        });
    });
    var head = [
        it.type === 'video'
            ? h('div', { class: 'media-thumb video', text: '▶' })
            : h('div', { class: 'media-thumb' }, [h('img', { src: it.path, alt: it.name, loading: 'lazy' })]),
        h('div', { class: 'media-name', title: it.path, text: it.name }),
        h('div', { class: 'card-meta', text: (it.type === 'video' ? 'Video · ' : '') + formatBytes(it.size) + ' · ' + (it.pages ? it.pages + ' Seite(n)' : 'nicht eingesetzt') })
    ];
    var actions = h('div', { class: 'card-actions' }, [
        state.perms.indexOf('edit') !== -1 ? h('button', {
            class: 'btn small primary', type: 'button', text: 'Einsetzen',
            onclick: function () { insertMediaPath(it.path); }
        }) : null,
        h('button', {
            class: 'btn small', type: 'button', text: 'Pfad',
            onclick: function () { copyText(it.path); }
        })
    ]);
    return h('div', { class: 'media-card' }, head.concat(usages).concat([actions]));
}

function renderMediaPanel(panel) {
    var cloud = state.media.cloud || {};
    panel.appendChild(h('h3', { text: 'Medienbibliothek' }));
    panel.appendChild(h('div', { class: 'card' }, [
        h('div', { class: 'card-meta', text: (state.media.count || 0) + ' eigene Datei(en) in /assets/media' }),
        h('div', { class: 'card-meta', text: cloud.active
            ? 'Supabase aktiv · Bucket „' + (cloud.bucket || '') + '" · Tabelle „' + (cloud.table || '') + '"'
            : (cloud.configured ? 'Supabase konfiguriert, aber nicht aktiv (Reiter Setup).' : 'Supabase nicht konfiguriert – Bilder bleiben lokal.') })
    ]));

    var input = h('input', { type: 'file', accept: 'image/*', multiple: true, style: 'display:none' });
    input.addEventListener('change', function () {
        uploadMedia(Array.prototype.slice.call(input.files || []), input);
    });
    panel.appendChild(input);
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        state.perms.indexOf('edit') !== -1 ? h('button', {
            class: 'btn primary small', type: 'button', text: 'Bilder hochladen …',
            onclick: function () { input.click(); }
        }) : null,
        h('button', {
            class: 'btn small', type: 'button', text: 'Medien öffnen',
            onclick: openMediaDialog
        }),
        cloud.active && state.perms.indexOf('edit') !== -1 ? h('button', {
            class: 'btn small', type: 'button', text: 'Mit Supabase abgleichen',
            onclick: function () {
                toast('Gleiche Bibliothek mit Supabase ab …', '');
                api('/api/media-sync', {}).then(function (res) {
                    var d = res.data || {};
                    toast((d.uploaded || 0) + ' Bild(er) übertragen, ' + (d.rows || 0) + ' Datensätze, Tabelle: ' + (d.table || '–'), 'ok');
                    (d.errors || []).slice(0, 3).forEach(function (e) { toast(e, 'warn'); });
                }).catch(function (e) { toast(e.message, 'err'); });
            }
        }) : null
    ]));

    var search = h('input', { type: 'search', placeholder: 'Bilder filtern …' });
    var grid = h('div', { class: 'media-grid' });
    panel.appendChild(h('div', { style: 'height:8px' }));
    panel.appendChild(search);
    panel.appendChild(grid);
    panel.appendChild(h('p', { class: 'hint-inline', text: 'Nur eigene Uploads. Bilder werden beim Hochladen auf diesem PC zu WebP, Videos sind bis 5 MB erlaubt.' }));

    function paint() {
        grid.innerHTML = '';
        var q = search.value.toLowerCase();
        var items = (state.media.items || []).filter(function (it) {
            return !q || it.path.toLowerCase().indexOf(q) !== -1;
        });
        if (!items.length) {
            grid.appendChild(h('p', { class: 'empty-note', text: 'Keine Bilder gefunden. Laden Sie Bilder hoch – sie landen in /assets/media und sind sofort verfügbar.' }));
            return;
        }
        items.forEach(function (it) { grid.appendChild(mediaCard(it)); });
    }
    search.addEventListener('input', debounce(paint, 120));
    paint();
    loadMedia().then(paint);
}

function insertMediaPath(path) {
    var info = state.info;
    if (info && info.tag === 'img') {
        attrOp('src', path);
        toast('Bildquelle ersetzt.', 'ok');
        return;
    }
    if (!info || !info.path || !info.path.length) {
        toast('Bitte zuerst in der Vorschau die Stelle auswählen.', 'warn');
        return;
    }
    var target = info;
    api('/api/media-insert', {
        file: state.file, src: path, alt: '', parentPath: target.path, index: null
    }).then(function (res) {
        toast('Bild eingesetzt.', 'ok');
        if (res.data && res.data.cloud && res.data.cloud.error) toast('Supabase: ' + res.data.cloud.error, 'warn');
        return refreshAfterStructural();
    }).catch(function (e) { toast(e.message, 'err'); });
}

var MEDIA_LIMITS = { video: 5242880, image: 10485760 };

function isVideoFile(file) {
    return /^video\//.test(file.type || '') || /\.(mp4|webm)$/i.test(file.name || '');
}

/** WebP-Umwandlung im Browser (dieses PHP hat kein GD/Imagick). */
function convertToWebp(file) {
    return new Promise(function (resolve) {
        var untouched = /svg|gif|ico/i.test(file.type || '') || /\.(svg|gif|ico)$/i.test(file.name || '');
        if (!/^image\//.test(file.type || '') || untouched) {
            resolve({
                blob: file, name: file.name, converted: false,
                note: /\.gif$/i.test(file.name) ? 'GIF bleibt unverändert (Animation)' : 'Format bleibt unverändert'
            });
            return;
        }
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            try {
                var w = img.naturalWidth || img.width;
                var h = img.naturalHeight || img.height;
                var scale = Math.min(1, 2560 / Math.max(w, h));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(w * scale));
                canvas.height = Math.max(1, Math.round(h * scale));
                var ctx = canvas.getContext('2d');
                if (!ctx) throw new Error('Canvas nicht verfügbar');
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(function (blob) {
                    URL.revokeObjectURL(url);
                    if (!blob || blob.type !== 'image/webp') {
                        resolve({ blob: file, name: file.name, converted: false, note: 'Browser konnte kein WebP erzeugen – Original gespeichert' });
                        return;
                    }
                    resolve({
                        blob: blob,
                        name: String(file.name).replace(/\.[^.]+$/, '') + '.webp',
                        converted: true,
                        note: 'WebP (' + formatBytes(file.size) + ' → ' + formatBytes(blob.size) + ')'
                    });
                }, 'image/webp', 0.85);
            } catch (e) {
                URL.revokeObjectURL(url);
                resolve({ blob: file, name: file.name, converted: false, note: 'Umwandlung fehlgeschlagen – Original gespeichert' });
            }
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            resolve({ blob: file, name: file.name, converted: false, note: 'Bild konnte nicht gelesen werden – Original gespeichert' });
        };
        img.src = url;
    });
}

function uploadMedia(files, input, onDone) {
    files = (files || []).filter(function (f) { return f && f.size; });
    if (!files.length) return Promise.resolve();
    var done = 0;
    var failed = 0;
    var notes = [];
    var chain = Promise.resolve();
    files.forEach(function (file) {
        chain = chain.then(function () {
            var video = isVideoFile(file);
            var limit = video ? MEDIA_LIMITS.video : MEDIA_LIMITS.image;
            if (file.size > limit) {
                failed++;
                toast(file.name + ' ist zu groß (' + formatBytes(file.size) + '). ' + (video ? 'Videos: max. 5 MB.' : 'Bilder: max. 10 MB.'), 'err');
                return;
            }
            return convertToWebp(file).then(function (out) {
                var fd = new FormData();
                fd.append('file', out.blob, out.name);
                fd.append('dir', '/assets/media');
                return fetch('/api/media-upload', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': W.csrf },
                    body: fd,
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (!res.ok) throw new Error(res.error || 'Upload fehlgeschlagen');
                    done++;
                    if (out.note) notes.push(out.name + ': ' + out.note);
                    if (res.data && res.data.cloud && res.data.cloud.error) toast('Supabase: ' + res.data.cloud.error, 'warn');
                });
            }).catch(function (e) {
                failed++;
                toast(file.name + ': ' + e.message, 'err');
            });
        });
    });
    return chain.then(function () {
        if (input) input.value = '';
        toast(done + ' Datei(en) hinzugefügt' + (failed ? ', ' + failed + ' nicht übernommen' : '') + '.', failed ? 'warn' : 'ok');
        notes.slice(0, 3).forEach(function (n) { toast(n, ''); });
        return loadMedia().then(function () {
            if (onDone) onDone();
            else if (state.tab === 'media') renderPanel();
        });
    });
}

function openMediaDialog() {
    var drop = h('div', { class: 'media-drop' }, [
        h('strong', { text: 'Fotos hierher ziehen' }),
        h('span', { text: 'oder klicken zum Auswählen' }),
        h('small', { text: 'Bilder werden auf diesem PC zu WebP · Videos (MP4/WebM) bis 5 MB' })
    ]);
    var input = h('input', { type: 'file', accept: 'image/*,video/mp4,video/webm', multiple: true, style: 'display:none' });
    var status = h('p', { class: 'hint-inline', text: '' });
    var grid = h('div', { class: 'media-grid' });
    var body = h('div', { class: 'media-dialog' }, [
        drop, input, status, h('h3', { text: 'Bibliothek' }), grid
    ]);

    function renderGrid() {
        grid.innerHTML = '';
        var items = state.media.items || [];
        if (!items.length) {
            grid.appendChild(h('p', { class: 'empty-note', text: 'Noch keine eigenen Medien. Laden Sie oben Fotos oder Videos hoch.' }));
            return;
        }
        items.forEach(function (it) {
            grid.appendChild(h('div', { class: 'media-card' }, [
                it.type === 'video'
                    ? h('div', { class: 'media-thumb video', text: '▶' })
                    : h('div', { class: 'media-thumb' }, [h('img', { src: it.path, alt: it.name, loading: 'lazy' })]),
                h('div', { class: 'media-name', title: it.path, text: it.name }),
                h('div', { class: 'card-meta', text: (it.type === 'video' ? 'Video · ' : '') + formatBytes(it.size) + ' · ' + (it.pages ? it.pages + ' Seite(n)' : 'nicht eingesetzt') }),
                h('div', { class: 'card-actions' }, [
                    state.perms.indexOf('edit') !== -1 ? h('button', {
                        class: 'btn small primary', type: 'button', text: 'Einsetzen',
                        onclick: function () { modalCloseAll(); insertMediaPath(it.path); }
                    }) : null,
                    h('button', { class: 'btn small', type: 'button', text: 'Pfad', onclick: function () { copyText(it.path); } })
                ])
            ]));
        });
    }

    function handle(list) {
        var files = Array.prototype.slice.call(list || []);
        if (!files.length) return;
        status.textContent = 'Verarbeite ' + files.length + ' Datei(en) …';
        uploadMedia(files, input, function () { status.textContent = ''; renderGrid(); });
    }

    drop.addEventListener('click', function () { input.click(); });
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
    drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('over');
        handle(e.dataTransfer.files);
    });
    input.addEventListener('change', function () { handle(input.files); });

    modal({ title: 'Medien', wide: true, body: body, actions: [{ label: 'Schließen', value: null }] });
    loadMedia().then(function () { renderGrid(); });
}

function imagePicker(opts) {
    opts = opts || {};
    var box = h('div', null, [h('p', { class: 'empty-note', text: 'Bibliothek wird geladen …' })]);
    modal({
        title: 'Bild aus Bibliothek wählen',
        wide: true,
        body: box,
        actions: [{ label: 'Schließen', value: null }]
    });
    loadMedia().then(function () {
        box.innerHTML = '';
        var items = state.media.items || [];
        if (!items.length) {
            box.appendChild(h('p', { class: 'empty-note', text: 'Die Bibliothek ist leer. Laden Sie im Reiter „Medien" Bilder hoch.' }));
            return;
        }
        var grid = h('div', { class: 'media-grid' });
        items.forEach(function (it) {
            grid.appendChild(h('button', {
                class: 'media-pick', type: 'button', title: it.path,
                onclick: function () {
                    if (opts.target === 'src') {
                        modalCloseAll();
                        attrOp('src', it.path);
                        toast('Bildquelle gesetzt.', 'ok');
                        return;
                    }
                    api('/api/media-insert', {
                        file: state.file, src: it.path, alt: '',
                        afterPath: opts.afterPath || null,
                        parentPath: opts.info ? opts.info.path : null,
                        index: null
                    }).then(function () {
                        modalCloseAll();
                        toast('Bild eingesetzt.', 'ok');
                        return refreshAfterStructural();
                    }).catch(function (e) { toast(e.message, 'err'); });
                }
            }, [
                h('img', { src: it.path, alt: it.name, loading: 'lazy' }),
                h('span', { text: it.name })
            ]));
        });
        box.appendChild(grid);
    });
}

/* ------------------------------------------------------------------ */
/* Import / Export von Bausteinen                                      */
/* ------------------------------------------------------------------ */

function downloadJson(name, data) {
    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var a = h('a', { href: url, download: name });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
}

function exportComponents(slugs) {
    api('/api/component-export', { slugs: slugs || [] }).then(function (res) {
        var stamp = new Date().toISOString().slice(0, 10);
        var name = (slugs && slugs.length === 1) ? 'baustein-' + slugs[0] + '.json' : 'bausteine-' + stamp + '.json';
        downloadJson(name, res.data);
        toast((res.data.items || []).length + ' Baustein(e) exportiert.', 'ok');
    }).catch(function (e) { toast(e.message, 'err'); });
}

function importComponents() {
    var input = h('input', { type: 'file', accept: 'application/json,.json' });
    var overwrite = h('input', { type: 'checkbox' });
    modal({
        title: 'Bausteine importieren',
        body: h('div', null, [
            h('p', { class: 'muted', text: 'Wählen Sie eine mit „Export" erzeugte JSON-Datei. Gleichnamige Bausteine werden standardmäßig nicht überschrieben, sondern mit Suffix ergänzt.' }),
            input,
            h('label', { class: 'check', style: 'display:flex;gap:6px;align-items:center;margin-top:8px' }, [
                overwrite, h('span', { text: 'Gleichnamige Bausteine überschreiben' })
            ])
        ]),
        actions: [
            { label: 'Abbrechen', value: null },
            {
                label: 'Importieren', kind: 'primary', onClick: function () {
                    var file = input.files && input.files[0];
                    if (!file) { toast('Bitte eine JSON-Datei wählen.', 'warn'); return false; }
                    return file.text().then(function (text) {
                        var payload = null;
                        try { payload = JSON.parse(text); }
                        catch (e) { toast('Die Datei ist kein gültiges JSON.', 'err'); return false; }
                        return api('/api/component-import', { payload: payload, overwrite: overwrite.checked }).then(function (res) {
                            var d = res.data || {};
                            toast((d.saved || []).length + ' Baustein(e) importiert'
                                + ((d.skipped || []).length ? ', ' + d.skipped.length + ' übersprungen' : '') + '.', 'ok');
                            (d.skipped || []).slice(0, 3).forEach(function (s) { toast(s, 'warn'); });
                            return loadState().then(function () { renderPanel(); return true; });
                        });
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

/* ------------------------------------------------------------------ */
/* Backups: gruppiert, ansehen ohne Wiederherstellen                   */
/* ------------------------------------------------------------------ */

function formatDay(day) {
    if (!day) return 'Ohne Datum';
    var d = new Date(day + 'T12:00:00');
    if (isNaN(d.getTime())) return day;
    return d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return iso || '';
    return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

function backupCard(b) {
    return h('div', { class: 'card' }, [
        h('div', { class: 'card-title' }, [
            h('span', { text: formatTime(b.createdAt) }),
            h('span', { class: 'card-meta', text: b.type === 'publish' ? 'Veröffentlichung' : (b.type === 'manual' ? 'Quelltext' : b.type) })
        ]),
        h('div', { class: 'card-meta', text: b.message || b.id }),
        h('div', { class: 'card-meta', text: b.files + ' Datei(en)'
            + (b.remoteFiles ? ', ' + b.remoteFiles + ' Remote-Sicherung(en)' : '')
            + (b.failed ? ', ' + b.failed + ' fehlgeschlagen' : '') }),
        h('div', { class: 'card-actions' }, [
            h('button', { class: 'btn small', type: 'button', text: 'Ansehen', onclick: function () { viewBackup(b.id); } }),
            state.perms.indexOf('restore') !== -1 ? h('button', {
                class: 'btn small', type: 'button', text: 'Wiederherstellen',
                onclick: function () { restoreBackup(b); }
            }) : null
        ])
    ]);
}

function viewBackup(id) {
    var listBox = h('div', null, [h('p', { class: 'empty-note', text: 'Dateiliste wird geladen …' })]);
    var detail = h('div', { class: 'backup-detail' }, [h('p', { class: 'empty-note', text: 'Datei links auswählen – es wird nichts verändert.' })]);
    modal({
        title: 'Backup ansehen: ' + id,
        wide: true,
        body: h('div', { class: 'backup-view' }, [listBox, detail]),
        actions: [{ label: 'Schließen', value: null }]
    });
    api('/api/backup-view', { id: id }).then(function (res) {
        var d = res.data || {};
        listBox.innerHTML = '';
        listBox.appendChild(h('div', { class: 'card-meta', text: (d.meta && d.meta.message ? d.meta.message : '') + ' · ' + formatTime(d.meta && d.meta.createdAt) }));
        if (!(d.files || []).length) {
            listBox.appendChild(h('p', { class: 'empty-note', text: 'Dieses Backup enthält keine Dateien.' }));
            return;
        }
        d.files.forEach(function (f) {
            listBox.appendChild(h('button', {
                class: 'page-item', type: 'button', title: f.path,
                onclick: function () { showBackupFile(id, f.path, detail); }
            }, [
                h('span', { class: 'pname', text: f.path }),
                h('span', { class: 'lang', text: f.remote ? 'remote' : 'lokal' })
            ]));
        });
    }).catch(function (e) {
        listBox.innerHTML = '';
        listBox.appendChild(h('p', { class: 'banner err', text: e.message }));
    });
}

function showBackupFile(id, path, container) {
    container.innerHTML = '';
    container.appendChild(h('p', { class: 'empty-note', text: 'Lade ' + path + ' …' }));
    api('/api/backup-view', { id: id, file: path }).then(function (res) {
        var d = res.data || {};
        container.innerHTML = '';
        container.appendChild(h('div', { class: 'card-meta', text: path + ' · ' + formatBytes(d.size || 0)
            + (d.source === 'remote' ? ' · vom Server gesichert' : '') }));
        if (d.identical) {
            container.appendChild(h('p', { class: 'banner', text: 'Diese Datei ist im Backup identisch mit dem aktuellen Stand.' }));
        }
        if (d.isBinary) {
            container.appendChild(h('p', { class: 'empty-note', text: 'Binärdatei – Inhalt wird nicht angezeigt.' }));
            return;
        }
        var diffLines = (d.diff || []).map(function (line) {
            return h('div', { class: line.type }, [
                h('span', { class: 'n', text: line.n || '' }),
                document.createTextNode((line.type === 'add' ? '+ ' : line.type === 'del' ? '- ' : '  ') + line.text)
            ]);
        });
        container.appendChild(h('h3', { text: 'Unterschied zum aktuellen Stand' }));
        container.appendChild(h('div', { class: 'diff' }, diffLines.length ? diffLines : [h('div', { text: 'Keine Unterschiede.' })]));
        container.appendChild(h('details', null, [
            h('summary', { text: 'Gespeicherter Quelltext' }),
            h('pre', { class: 'diff', text: d.content === null ? 'Zu groß für die Anzeige.' : d.content })
        ]));
    }).catch(function (e) {
        container.innerHTML = '';
        container.appendChild(h('p', { class: 'banner err', text: e.message }));
    });
}

function restoreBackup(b) {
    modal({
        title: 'Backup wiederherstellen',
        body: h('div', null, [
            h('p', { text: 'Sicherung vom ' + formatDay(b.day) + ' um ' + formatTime(b.createdAt) + ' (' + b.files + ' Datei(en)).' }),
            h('p', { class: 'muted', text: 'Der aktuelle Zustand wird vorher automatisch als eigenes Backup gesichert.' })
        ]),
        actions: [
            { label: 'Abbrechen', value: null },
            { label: 'Nur Entwurf', value: 'working' },
            { label: 'Entwurf + Server', kind: 'primary', value: 'both' }
        ]
    }).then(function (target) {
        if (!target) return;
        api('/api/rollback', { id: b.id, target: target }).then(function (r) {
            var data = r.data || {};
            toast((data.restored || []).length + ' Datei(en) wiederhergestellt.', 'ok');
            if (data.blocked) toast(data.blocked, 'warn');
            if (data.validation && data.validation.counts && data.validation.counts.error) {
                toast(data.validation.counts.error + ' Fehler nach Wiederherstellung.', 'err');
            }
            loadState().then(function () { renderPanel(); if (state.file) openFile(state.file); });
        }).catch(function (e) { toast(e.message, 'err'); });
    });
}

function translationBox(info) {
    var box = h('div', { class: 'banner' }, [
        h('div', null, [h('strong', { text: 'Übersetzungs-ID: ' }), h('code', { text: info.tid })]),
        h('div', { id: 'partnerBox', class: 'muted', text: 'Partnertexte werden geladen …' })
    ]);
    api('/api/lang-partners', { file: state.file, tid: info.tid }).then(function (res) {
        var data = res.data;
        var target = $('#partnerBox');
        if (!target) return;
        target.innerHTML = '';
        if (!data.page) {
            target.textContent = 'Diese Seite ist keiner Sprachseite zugeordnet (Reiter "Sprachen").';
            return;
        }
        var keys = Object.keys(data.partners);
        if (!keys.length) {
            target.textContent = 'In den zugeordneten Sprachen existiert kein Partnerelement.';
            return;
        }
        keys.forEach(function (lang) {
            var p = data.partners[lang];
            target.appendChild(h('div', { style: 'margin-top:6px' }, [
                h('span', { class: 'chip', text: lang }),
                h('span', { text: p.text }),
                h('button', {
                    class: 'btn small', type: 'button', text: 'Seite öffnen', style: 'margin-left:6px',
                    onclick: function () { openFile(p.path); }
                })
            ]));
        });
    }).catch(function () {});
    return box;
}

function responsiveBox(info) {
    var bp = state.device === 'phone' ? 'phone' : (state.device === 'tablet' ? 'tablet' : null);
    var container = h('div');
    var rules = h('div', { class: 'empty-note', text: 'Regeln werden geladen …' });

    function loadRules() {
        return api('/api/responsive-rules', { file: state.file, path: info.path }).then(function (res) {
            rules.innerHTML = '';
            var data = res.data || {};
            var any = false;
            Object.keys(data).forEach(function (b) {
                var decls = data[b];
                if (!decls) return;
                any = true;
                Object.keys(decls).forEach(function (p) {
                    rules.appendChild(h('span', { class: 'chip' }, [
                        b + ' · ' + p + ': ' + decls[p],
                        h('button', { type: 'button', text: '×', onclick: function () { setRule(b, p, null); } })
                    ]));
                });
            });
            if (!any) rules.appendChild(h('span', { class: 'empty-note', text: 'Noch keine Breakpoint-Regeln für dieses Element.' }));
        }).catch(function () { rules.textContent = 'Regeln konnten nicht geladen werden.'; });
    }

    function setRule(breakpoint, prop, value) {
        api('/api/responsive', { file: state.file, path: info.path, tags: info.tags, breakpoint: breakpoint, prop: prop, value: value })
            .then(function () {
                toast('Breakpoint-Regel gespeichert.', 'ok');
                loadRules();
                loadInfo(info.path);
                loadPreview(true);
            }).catch(function (e) { toast(e.message, 'err'); });
    }

    var bpSelect = selectField([
        { value: 'tablet', label: 'Tablet (bis 834 px)' },
        { value: 'phone', label: 'Smartphone (bis 480 px)' }
    ], bp || 'tablet', function () {});
    var propInput = h('input', { type: 'text', placeholder: 'z. B. font-size' });
    var valueInput = h('input', { type: 'text', placeholder: 'z. B. 14px' });

    container.appendChild(h('p', { class: 'empty-note', text: 'Breakpoint-Regeln landen in einem eigenen <style data-wfx-responsive>-Block der Seite. Bestehende Media Queries bleiben unverändert.' }));
    container.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Breakpoint' }), bpSelect]));
    container.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Eigenschaft' }), propInput]));
    container.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Wert' }), valueInput]));
    container.appendChild(h('div', { class: 'insp-actions' }, [
        h('button', {
            class: 'btn small primary', type: 'button', text: 'Regel speichern',
            onclick: function () {
                if (!propInput.value.trim() || !valueInput.value.trim()) { toast('Eigenschaft und Wert angeben.', 'warn'); return; }
                setRule(bpSelect.value, propInput.value.trim(), valueInput.value.trim());
                propInput.value = '';
                valueInput.value = '';
            }
        })
    ]));
    container.appendChild(rules);
    loadRules();
    return h('details', { class: 'insp-group' }, [h('summary', { text: 'Responsive (Breakpoints)' }), container]);
}

function moveSibling(direction) {
    var info = state.info;
    if (!info || !info.path.length) return;
    var parentPath = info.path.slice(0, -1);
    var index = info.path[info.path.length - 1];
    var target = index + direction;
    if (target < 0) return;
    applyOps([{ kind: 'move', path: info.path, tags: info.tags, target: { parent: parentPath, index: target } }],
        'Element verschoben', { structural: true });
}

function uploadImage() {
    var input = h('input', { type: 'file', accept: 'image/*' });
    modal({
        title: 'Bild hochladen',
        body: h('div', null, [
            h('p', { class: 'empty-text', text: 'Das Bild wird in die Arbeitskopie gelegt (max. 2 MB). Nach dem Veröffentlichen ist es online erreichbar.' }),
            input,
            h('div', { class: 'field' }, [h('label', { text: 'Zielordner' }), h('input', { type: 'text', value: '/assets/images', id: 'uploadDir' })])
        ]),
        actions: [
            { label: 'Abbrechen', value: null },
            {
                label: 'Hochladen', kind: 'primary', onClick: function () {
                    var file = input.files && input.files[0];
                    if (!file) { toast('Bitte eine Datei auswählen.', 'warn'); return false; }
                    var dir = $('#uploadDir').value || '/assets/images';
                    var fd = new FormData();
                    fd.append('file', file);
                    fd.append('dir', dir);
                    return fetch('/api/upload', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': W.csrf },
                        body: fd,
                        credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res.ok) throw new Error(res.error || 'Upload fehlgeschlagen');
                        attrOp('src', res.path);
                        toast('Bild hochgeladen: ' + res.path, 'ok');
                        return true;
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

/* ------------------------------------------------------------------ */
/* Strukturbaum mit Drag & Drop                                        */
/* ------------------------------------------------------------------ */

function renderTree() {
    var box = $('#treeBox');
    if (!box) return;
    if (!state.file) { box.innerHTML = ''; box.appendChild(h('p', { class: 'empty-note', text: 'Keine Seite geöffnet.' })); return; }
    api('/api/tree', { file: state.file }).then(function (res) {
        state.tree = res.tree;
        box.innerHTML = '';
        var selPath = state.info ? state.info.path.join('/') : '';
        res.tree.forEach(function (node) { box.appendChild(treeRow(node, 0, selPath)); });
    }).catch(function (e) { box.textContent = 'Struktur nicht verfügbar: ' + e.message; });
}

function treeRow(node, depth, selPath) {
    var row = h('div', {
        class: 'tree-row' + (node.path.join('/') === selPath ? ' selected' : ''),
        draggable: 'true',
        style: 'padding-left:' + (4 + depth * 12) + 'px',
        title: node.path.join('/'),
        onclick: function (e) {
            e.stopPropagation();
            postToFrame({ type: 'select', path: node.path });
        }
    }, [
        h('span', { class: 'tree-tag', text: node.tag }),
        h('span', { class: 'tree-label', text: node.text || node.className || '' })
    ]);
    row.dataset.path = JSON.stringify(node.path);
    row.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', row.dataset.path);
        e.dataTransfer.effectAllowed = 'move';
        state.dragPath = node.path;
        state.dragTags = null;
    });
    row.addEventListener('dragover', function (e) {
        if (!state.dragPath) return;
        e.preventDefault();
        var rect = row.getBoundingClientRect();
        var before = (e.clientY - rect.top) < rect.height / 2;
        row.classList.toggle('drop-before', before);
        row.classList.toggle('drop-inside', !before);
    });
    row.addEventListener('dragleave', function () { row.classList.remove('drop-before', 'drop-inside'); });
    row.addEventListener('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var before = row.classList.contains('drop-before');
        row.classList.remove('drop-before', 'drop-inside');
        if (!state.dragPath) return;
        var dragPath = state.dragPath;
        state.dragPath = null;
        var target;
        if (before) {
            target = { parent: node.path.slice(0, -1), index: node.path[node.path.length - 1] };
        } else {
            target = { parent: node.path, index: null };
        }
        if (dragPath.join('/') === node.path.join('/')) return;
        applyOps([{ kind: 'move', path: dragPath, target: target }], 'Element verschoben', { structural: true });
    });
    var wrap = h('div', null, [row]);
    (node.children || []).forEach(function (child) { wrap.appendChild(treeRow(child, depth + 1, selPath)); });
    return wrap;
}

/* ------------------------------------------------------------------ */
/* Bausteine                                                           */
/* ------------------------------------------------------------------ */

function saveAsComponent(info) {
    var name = h('input', { type: 'text', value: '', placeholder: 'z. B. Primärer Button' });
    var category = selectField(state.components.categories.map(function (c) { return { value: c.id, label: c.label }; }), guessCategory(info), function () {});
    var desc = h('input', { type: 'text', placeholder: 'kurze Beschreibung (optional)' });
    var preview = h('pre', { class: 'diff', style: 'max-height:180px', text: info.innerHtml.slice(0, 1500) });
    modal({
        title: 'Auswahl als Baustein speichern',
        body: h('div', null, [
            h('div', { class: 'field' }, [h('label', { text: 'Name' }), name]),
            h('div', { class: 'field' }, [h('label', { text: 'Kategorie' }), category]),
            h('div', { class: 'field' }, [h('label', { text: 'Beschreibung' }), desc]),
            h('p', { class: 'hint-inline', text: 'Gespeichert wird das Element <' + info.tag + '> mit seinem Inhalt. PHP-Code wird abgelehnt.' }),
            preview
        ]),
        actions: [
            { label: 'Abbrechen', value: null },
            {
                label: 'Baustein speichern', kind: 'primary', onClick: function () {
                    if (!name.value.trim()) { toast('Bitte einen Namen angeben.', 'warn'); return false; }
                    return api('/api/component-from-selection', {
                        file: state.file, path: info.path, name: name.value.trim(),
                        category: category.value, description: desc.value
                    }).then(function (res) {
                        toast('Baustein "' + res.item.name + '" gespeichert.', 'ok');
                        return loadState().then(function () { renderPanel(); return true; });
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

function guessCategory(info) {
    if (info.tag === 'a' || info.tag === 'button') return 'buttons';
    if (/^h[1-6]$/.test(info.tag)) return 'headings';
    if (info.tag === 'img') return 'images';
    if (info.tag === 'footer') return 'footer';
    if (info.tag === 'nav') return 'nav';
    if (info.tag === 'section') return 'heroes';
    return 'containers';
}

function insertComponent(slug) {
    var item = state.components.items[slug];
    if (!item) return;
    var props = {};
    var propInputs = {};
    (item.schema || []).forEach(function (field) {
        var inp = h('input', { type: 'text', value: field.default || '' });
        propInputs[field.key] = inp;
        props[field.key] = field.default || '';
    });
    var mode = 'copy';
    var where = info2SelectionTarget();

    var bodyNode = h('div', null, [
        h('p', { class: 'muted', text: item.description || 'Baustein einfügen.' }),
        h('div', { class: 'field' }, [h('label', { text: 'Einfügen' }), h('div', {
            text: where.label
        })]),
        h('div', { class: 'field' }, [h('label', { text: 'Modus' }), selectField([
            { value: 'copy', label: 'Unabhängige Kopie' },
            { value: 'global', label: 'Globale Instanz (folgt der Definition)' }
        ], 'copy', function (v) { mode = v; })]),
        h('h3', { text: 'Eigenschaften' }),
        (item.schema || []).length ? h('div', null, (item.schema || []).map(function (field) {
            return h('div', { class: 'field' }, [h('label', { text: field.label || field.key }), propInputs[field.key]]);
        })) : h('p', { class: 'empty-note', text: 'Dieser Baustein hat keine definierten Eigenschaften. Der Inhalt kann nach dem Einfügen direkt in der Vorschau bearbeitet werden.' }),
        h('details', null, [h('summary', { text: 'HTML der Definition' }), h('pre', { class: 'diff', text: item.html })])
    ]);

    modal({
        title: item.name,
        body: bodyNode,
        actions: [
            { label: 'Abbrechen', value: null },
            {
                label: 'Einfügen', kind: 'primary', onClick: function () {
                    Object.keys(propInputs).forEach(function (k) { props[k] = propInputs[k].value; });
                    return api('/api/component-insert', {
                        file: state.file, slug: slug, mode: mode, props: props,
                        parentPath: where.parentPath, index: where.index
                    }).then(function () {
                        toast('Baustein eingefügt.', 'ok');
                        return refreshAfterStructural().then(function () { return true; });
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

function info2SelectionTarget() {
    var info = state.info;
    if (info && info.path && info.path.length) {
        return {
            parentPath: info.path.slice(0, -1),
            index: info.path[info.path.length - 1] + 1,
            label: 'Nach dem ausgewählten Element <' + info.tag + '>'
        };
    }
    return { parentPath: [0, 1], index: null, label: 'Am Ende von <body> (Standard)' };
}

function componentEditor(slug) {
    var item = slug ? state.components.items[slug] : null;
    var name = h('input', { type: 'text', value: item ? item.name : '' });
    var category = selectField(state.components.categories.map(function (c) { return { value: c.id, label: c.label }; }), item ? item.category : 'containers', function () {});
    var desc = h('input', { type: 'text', value: item ? item.description : '' });
    var htmlArea = h('textarea', { class: 'code-area', style: 'min-height:34vh' });
    htmlArea.value = item ? item.html : '<div class="box">\n  <h3>{{title}}</h3>\n  <p>{{text}}</p>\n</div>';
    htmlArea.addEventListener('input', debounce(function () {
        var tokens = {};
        htmlArea.value.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, k) { tokens[k] = true; return m; });
        schemaBox.innerHTML = '';
        Object.keys(tokens).forEach(function (k) {
            var existing = (item && (item.schema || []).find(function (s) { return s.key === k; })) || {};
            var defaultInput = h('input', { type: 'text', value: existing.default || '' });
            var labelInput = h('input', { type: 'text', value: existing.label || k });
            defaultInput.dataset.key = k;
            defaultInput.dataset.role = 'default';
            labelInput.dataset.key = k;
            labelInput.dataset.role = 'label';
            schemaBox.appendChild(h('div', { class: 'field' }, [h('label', { text: k }), h('div', { class: 'row' }, [labelInput, defaultInput])]));
        });
        if (!Object.keys(tokens).length) {
            schemaBox.appendChild(h('p', { class: 'empty-note', text: 'Keine Platzhalter. Verwenden Sie {{name}} im HTML, um Eigenschaften zu definieren.' }));
        }
    }, 200));
    var schemaBox = h('div');
    var historyBox = h('div');
    if (item) {
        (item.history || []).forEach(function (hst) {
            if (!hst.version) return;
            historyBox.appendChild(h('div', { class: 'row', style: 'margin-bottom:4px' }, [
                h('span', { text: 'Version ' + hst.version + ' (' + (hst.updatedAt || '') + ')' }),
                h('button', {
                    class: 'btn small', type: 'button', text: 'Wiederherstellen',
                    onclick: function () { restoreComponentVersion(item.slug, hst.version); }
                })
            ]));
        });
    }

    var modalBody = h('div', null, [
        h('div', { class: 'field' }, [h('label', { text: 'Name' }), name]),
        h('div', { class: 'field' }, [h('label', { text: 'Kategorie' }), category]),
        h('div', { class: 'field' }, [h('label', { text: 'Beschreibung' }), desc]),
        h('div', { class: 'field wide' }, [h('label', { text: 'HTML mit Platzhaltern {{eigenschaft}}' }), htmlArea]),
        h('h3', { text: 'Eigenschaften' }),
        schemaBox,
        slug ? h('div', null, [h('h3', { text: 'Versionsverlauf' }), historyBox]) : null,
        item ? h('div', { class: 'insp-actions' }, [
            h('button', {
                class: 'btn small danger', type: 'button', text: 'Baustein löschen',
                onclick: function () {
                    confirmDialog('Baustein löschen', 'Der Baustein "' + item.name + '" wird aus der Bibliothek entfernt. Bereits eingefügte Instanzen bleiben als HTML erhalten.', 'Löschen', 'danger')
                        .then(function (ok) {
                            if (!ok) return;
                            api('/api/component-delete', { slug: item.slug }).then(function () {
                                toast('Baustein gelöscht.', 'ok');
                                loadState().then(renderPanel);
                            }).catch(function (e) { toast(e.message, 'err'); });
                        });
                }
            })
        ]) : null
    ]);

    htmlArea.dispatchEvent(new Event('input'));

    modal({
        title: item ? 'Baustein bearbeiten: ' + item.name : 'Neuer Baustein',
        wide: true,
        body: modalBody,
        actions: [
            { label: 'Schließen', value: null },
            {
                label: 'Speichern & Instanzen aktualisieren', kind: 'primary', onClick: function () {
                    var schema = [];
                    $$('input', schemaBox).forEach(function (inp) {
                        if (inp.dataset.role === 'label') {
                            var def = $('#inspector');
                            var defInput = $$('input', schemaBox).find(function (x) { return x.dataset.key === inp.dataset.key && x.dataset.role === 'default'; });
                            schema.push({ key: inp.dataset.key, label: inp.value, default: defInput ? defInput.value : '', type: 'text' });
                        }
                    });
                    return api('/api/component-update-definition', slug ? {
                        slug: item.slug, html: htmlArea.value, schema: schema
                    } : {
                        name: name.value, category: category.value, description: desc.value, html: htmlArea.value, schema: schema
                    }).then(function (res) {
                        toast('Baustein gespeichert.', 'ok');
                        if (res.data && res.data.instances) {
                            toast(res.data.instances + ' Instanz(en) neu gerendert.', 'ok');
                        }
                        return loadState().then(function () { renderPanel(); return true; });
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

function restoreComponentVersion(slug, version) {
    confirmDialog('Version wiederherstellen', 'Version ' + version + ' des Bausteins wird als neue Version gespeichert und auf alle globalen Instanzen angewendet.', 'Wiederherstellen')
        .then(function (ok) {
            if (!ok) return;
            api('/api/component-restore', { slug: slug, version: version }).then(function (res) {
                toast('Version wiederhergestellt.', 'ok');
                loadState().then(function () { renderPanel(); refreshAfterStructural(); });
            }).catch(function (e) { toast(e.message, 'err'); });
        });
}

/* ------------------------------------------------------------------ */
/* Panels                                                              */
/* ------------------------------------------------------------------ */

function renderPanel() {
    var panel = $('#panel');
    panel.innerHTML = '';
    $$('#tabs .tab').forEach(function (t) { t.classList.toggle('active', t.dataset.tab === state.tab); });
    if (state.tab === 'pages') renderPagesPanel(panel);
    else if (state.tab === 'components') renderComponentsPanel(panel);
    else if (state.tab === 'media') renderMediaPanel(panel);
    else if (state.tab === 'languages') renderLanguagesPanel(panel);
    else if (state.tab === 'publish') renderPublishPanel(panel);
    else if (state.tab === 'backups') renderBackupsPanel(panel);
    else if (state.tab === 'source') renderSourcePanel(panel);
    else if (state.tab === 'settings') renderSettingsPanel(panel);
}

function renderPagesPanel(panel) {
    var search = h('input', { type: 'search', placeholder: 'Seiten filtern …' });
    var list = h('div', { class: 'page-list' });

    function paint() {
        var q = search.value.toLowerCase();
        list.innerHTML = '';
        var filtered = state.pages.filter(function (p) { return !q || p.path.toLowerCase().indexOf(q) !== -1; });
        var pending = remoteOnlyPages().filter(function (e) {
            return !q || e.path.toLowerCase().indexOf(q) !== -1;
        });
        if (!filtered.length && !pending.length) {
            list.appendChild(h('p', {
                class: 'empty-note',
                text: state.remote.listedAt
                    ? 'Keine Treffer für diesen Filter.'
                    : 'Noch keine Objekte bekannt. Bitte unten im Bereich „Bestand“ den Server auflisten (ohne Download).'
            }));
            return;
        }
        filtered.forEach(function (p) {
            list.appendChild(h('button', {
                class: 'page-item' + (state.file === p.path ? ' active' : ''),
                type: 'button',
                onclick: function () { openFile(p.path); }
            }, [
                h('span', { class: 'pname', text: p.path }),
                p.lang !== 'und' ? h('span', { class: 'lang', text: p.lang }) : null,
                p.draft ? h('span', { class: 'dot-draft', title: 'Entwurf' }) : null
            ]));
        });
        pending.forEach(function (e) {
            list.appendChild(h('button', {
                class: 'page-item remote-only',
                type: 'button',
                title: 'Noch nicht lokal – wird beim Öffnen heruntergeladen',
                onclick: function () { openFile(e.path); }
            }, [
                h('span', { class: 'pname', text: e.path }),
                h('span', { class: 'lang', text: '⤓ Server' })
            ]));
        });
    }
    search.addEventListener('input', debounce(paint, 120));
    var counts = state.remote.counts || {};
    panel.appendChild(h('h3', {
        text: 'Seiten (' + state.pages.length + ' lokal'
            + (state.remote.listedAt ? ' · ' + (counts.pages || 0) + ' auf dem Server' : '') + ')'
    }));
    panel.appendChild(search);
    panel.appendChild(h('div', { style: 'height:8px' }));
    panel.appendChild(list);
    paint();

    panel.appendChild(h('h3', { text: 'Bestand' }));
    panel.appendChild(h('div', { class: 'card' }, [
        h('div', { class: 'card-meta', text: state.stats.files + ' Dateien lokal, ' + state.stats.pages + ' Seiten' }),
        h('div', {
            class: 'card-meta',
            text: state.remote.listedAt
                ? 'Server: ' + (state.remote.counts.total || 0) + ' Objekte, davon ' + (state.remote.counts.local || 0)
                    + ' lokal · Liste vom ' + state.remote.listedAt
                : 'Server: noch nicht aufgelistet'
        }),
        h('div', { class: 'card-meta', text: 'Letzter Abgleich: ' + (state.stats.lastPull || '–') }),
        h('div', { class: 'card-meta', text: 'Letzte Veröffentlichung: ' + (state.stats.lastPublish || '–') }),
        h('div', { class: 'card-actions' }, [
            state.perms.indexOf('sync') !== -1 ? h('button', {
                class: 'btn small primary', type: 'button', text: 'Server auflisten (ohne Download)',
                onclick: function () { listRemote(); }
            }) : null,
            state.perms.indexOf('sync') !== -1 ? h('button', {
                class: 'btn small', type: 'button', text: 'Alles herunterladen',
                onclick: function () { pullFromSource(); }
            }) : null,
            state.perms.indexOf('sync') !== -1 ? h('button', {
                class: 'btn small', type: 'button', text: 'Lokalen Ordner importieren',
                onclick: function () { importLocalDialog(); }
            }) : null
        ])
    ]));
    if (state.stats.drafts) {
        panel.appendChild(h('div', { class: 'card' }, [
            h('div', { class: 'card-title', text: state.stats.drafts + ' offene Änderung(en)' }),
            h('div', { class: 'card-meta', text: 'Diese Änderungen sind nur lokal gespeichert. Zum Veröffentlichen den Reiter "Livegang" öffnen.' })
        ]));
    }
}

function listRemote() {
    toast('Frage Objektliste ab …', '');
    api('/api/remote-list', {}).then(function (res) {
        var data = res.data || {};
        toast((data.files || []).length + ' Objekte auf dem Server gefunden (nichts heruntergeladen).', 'ok');
        (data.warnings || []).forEach(function (w) { toast(w, 'warn'); });
        return loadRemoteIndex();
    }).then(function () {
        if (state.tab === 'pages') renderPanel();
    }).catch(function (e) { toast(e.message, 'err'); });
}

function pullFromSource() {
    confirmDialog('Bestand herunterladen', 'Alle Dateien werden erneut vom FTP-Server geladen. Lokale Entwürfe, die noch nicht veröffentlicht sind, werden dabei überschrieben. Fortfahren?', 'Herunterladen')
        .then(function (ok) {
            if (!ok) return;
            toast('Lade Dateien …', '');
            api('/api/sync-pull', {}).then(function (res) {
                var data = res.data || {};
                toast((data.files || 0) + ' Dateien geladen.', 'ok');
                if (data.failed && data.failed.length) {
                    var names = data.failed.slice(0, 3).map(function (f) { return f.file; }).join(', ');
                    toast(data.failed.length + ' Datei(en) nicht ladbar: ' + names
                        + (data.failed.length > 3 ? ' …' : '') + ' (' + data.failed[0].error + ')', 'warn');
                }
                (data.warnings || []).filter(function (w) {
                    return w.indexOf('Uebersprungen:') !== 0;
                }).slice(0, 3).forEach(function (w) { toast(w, 'warn'); });
                return loadState().then(function () {
                    renderPanel();
                    if (state.file) openFile(state.file);
                });
            }).catch(function (e) { toast(e.message, 'err'); });
        });
}

function importLocalDialog() {
    var input = h('input', { type: 'text', placeholder: 'C:\\Users\\…\\htdocs' });
    modal({
        title: 'Lokalen Ordner importieren',
        body: h('div', null, [
            h('p', { class: 'muted', text: 'Importiert alle Dateien eines lokalen Verzeichnisses (z. B. FileZilla-Download oder GitHub-Klon) in die Arbeitskopie.' }),
            h('div', { class: 'field' }, [h('label', { text: 'Pfad' }), input])
        ]),
        actions: [
            { label: 'Abbrechen', value: null },
            {
                label: 'Importieren', kind: 'primary', onClick: function () {
                    if (!input.value.trim()) return false;
                    return api('/api/import-local', { dir: input.value.trim() }).then(function (res) {
                        toast(res.data.files + ' Dateien importiert.', 'ok');
                        return loadState().then(function () { renderPanel(); return true; });
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

function renderComponentsPanel(panel) {
    var search = h('input', { type: 'search', placeholder: 'Bausteine filtern …' });
    var list = h('div');

    function paint() {
        var q = search.value.toLowerCase();
        list.innerHTML = '';
        var items = Object.keys(state.components.items).map(function (k) { return state.components.items[k]; })
            .filter(function (it) {
                return !q || (it.name + ' ' + it.category + ' ' + it.description).toLowerCase().indexOf(q) !== -1;
            });
        if (!items.length) {
            list.appendChild(h('p', { class: 'empty-note', text: 'Noch keine Bausteine. Wählen Sie ein Element in der Vorschau und speichern Sie es als Baustein.' }));
            return;
        }
        items.forEach(function (it) {
            var cat = (state.components.categories.find(function (c) { return c.id === it.category; }) || {}).label || it.category;
            list.appendChild(h('div', { class: 'card' }, [
                h('div', { class: 'card-title' }, [h('span', { text: it.name }), h('span', { class: 'card-meta', text: 'v' + it.version })]),
                h('div', { class: 'card-meta', text: cat + (it.description ? ' · ' + it.description : '') }),
                h('div', { class: 'card-actions' }, [
                    h('button', {
                        class: 'btn small primary', type: 'button', text: 'Einfügen',
                        onclick: function () { insertComponent(it.slug); }
                    }),
                    h('button', {
                        class: 'btn small', type: 'button', text: 'Export',
                        onclick: function () { exportComponents([it.slug]); }
                    }),
                    state.perms.indexOf('components') !== -1 ? h('button', {
                        class: 'btn small', type: 'button', text: 'Bearbeiten',
                        onclick: function () { componentEditor(it.slug); }
                    }) : null,
                    h('button', {
                        class: 'btn small', type: 'button', text: 'Vorkommen',
                        onclick: function () {
                            api('/api/component-impact', { slug: it.slug }).then(function (res) {
                                var impact = res.data;
                                var rows = impact.pages.map(function (p) { return h('li', { text: p.path + ' – ' + p.instances + ' Instanz(en)' }); });
                                modal({
                                    title: 'Vorkommen: ' + it.name,
                                    body: h('div', null, [
                                        h('p', { text: impact.instances + ' Instanz(en) auf ' + impact.pages.length + ' Seite(n).' }),
                                        impact.pages.length ? h('ul', null, rows) : h('p', { class: 'empty-note', text: 'Der Baustein ist derzeit nicht eingefügt.' })
                                    ]),
                                    actions: [{ label: 'Schließen', value: null }]
                                });
                            }).catch(function (e) { toast(e.message, 'err'); });
                        }
                    })
                ])
            ]));
        });
    }
    search.addEventListener('input', debounce(paint, 120));

    panel.appendChild(h('h3', { text: 'Bibliothek' }));
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        h('button', { class: 'btn small', type: 'button', text: 'Alle exportieren', onclick: function () { exportComponents([]); } }),
        state.perms.indexOf('components') !== -1 ? h('button', { class: 'btn small', type: 'button', text: 'Importieren …', onclick: importComponents }) : null
    ]));
    panel.appendChild(search);
    panel.appendChild(h('div', { style: 'height:8px' }));
    panel.appendChild(list);
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        state.perms.indexOf('components') !== -1 ? h('button', {
            class: 'btn primary block', type: 'button', text: '+ Neuer Baustein',
            onclick: function () { componentEditor(null); }
        }) : null
    ]));
    panel.appendChild(h('p', { class: 'hint-inline', text: 'Neu programmieren: "+ Neuer Baustein" (HTML mit Platzhaltern wie {{titel}}). Aus vorhandenem Code: Element in der Vorschau auswählen → "Als Baustein speichern".' }));
    panel.appendChild(h('p', { class: 'hint-inline', text: 'Export/Import als JSON – so lassen sich Bausteine zwischen Projekten austauschen.' }));
    paint();
}

function renderLanguagesPanel(panel) {
    panel.appendChild(h('h3', { text: 'Zugeordnete Seiten' }));
    if (!state.languages.pages.length) {
        panel.appendChild(h('p', { class: 'empty-note', text: 'Noch keine Sprachversionen zugeordnet.' }));
    }
    state.languages.pages.forEach(function (page) {
        var status = state.langStatus.find(function (s) { return s.id === page.id; }) || { summary: {}, problems: [] };
        var langs = Object.keys(page.versions);
        var summaryText = langs.map(function (l) { return l.toUpperCase() + ': ' + page.versions[l]; }).join(' · ');
        var statusChips = h('div', null, [
            (status.summary.missing ? h('span', { class: 'chip', text: status.summary.missing + ' fehlend' }) : null),
            (status.summary.changed ? h('span', { class: 'chip', text: status.summary.changed + ' geändert' }) : null),
            (status.summary.ok ? h('span', { class: 'chip', text: status.summary.ok + ' ok' }) : null)
        ]);
        panel.appendChild(h('div', { class: 'card' }, [
            h('div', { class: 'card-title' }, [h('span', { text: page.label })]),
            h('div', { class: 'card-meta', text: summaryText }),
            statusChips,
            h('div', { class: 'card-actions' }, [
                langs.length > 1 ? h('button', {
                    class: 'btn small primary', type: 'button', text: 'Vergleichen',
                    onclick: function () { compareDialog(page, langs[0], langs[1]); }
                }) : null,
                h('button', {
                    class: 'btn small', type: 'button', text: 'Texte anzeigen',
                    onclick: function () { translationTable(page); }
                }),
                state.perms.indexOf('edit') !== -1 ? h('button', {
                    class: 'btn small', type: 'button', text: 'Als geprüft markieren',
                    onclick: function () {
                        api('/api/lang-snapshot', { id: page.id }).then(function () {
                            toast('Aktueller Stand als geprüft markiert.', 'ok');
                            loadState().then(renderPanel);
                        }).catch(function (e) { toast(e.message, 'err'); });
                    }
                }) : null,
                state.perms.indexOf('edit') !== -1 ? h('button', {
                    class: 'btn small danger', type: 'button', text: 'Zuordnung entfernen',
                    onclick: function () {
                        confirmDialog('Zuordnung entfernen', 'Die Zuordnung der Sprachdateien wird entfernt. Die Dateien selbst bleiben unverändert.', 'Entfernen', 'danger')
                            .then(function (ok) {
                                if (!ok) return;
                                api('/api/lang-unlink', { id: page.id }).then(function () {
                                    return loadState().then(renderPanel);
                                }).catch(function (e) { toast(e.message, 'err'); });
                            });
                    }
                }) : null
            ])
        ]));
    });

    panel.appendChild(h('h3', { text: 'Neue Zuordnung' }));
    panel.appendChild(h('button', { class: 'btn primary block', type: 'button', text: '+ Seiten einander zuordnen', onclick: function () { linkDialog(); } }));

    var detected = state.pages.reduce(function (acc, p) {
        if (p.lang === 'und') return acc;
        (acc[p.slug] = acc[p.slug] || []).push(p);
        return acc;
    }, {});
    var mapped = {};
    state.languages.pages.forEach(function (pg) { Object.keys(pg.versions).forEach(function (l) { mapped[pg.versions[l]] = true; }); });
    panel.appendChild(h('h3', { text: 'Erkannt (Vorschläge)' }));
    var any = false;
    Object.keys(detected).forEach(function (slug) {
        var group = detected[slug].filter(function (p) { return !mapped[p.path]; });
        if (group.length < 2) return;
        any = true;
        panel.appendChild(h('div', { class: 'card' }, [
            h('div', { class: 'card-title', text: slug }),
            h('div', { class: 'card-meta', text: group.map(function (g) { return g.lang + ': ' + g.path; }).join(' · ') }),
            h('div', { class: 'card-actions' }, [
                h('button', {
                    class: 'btn small', type: 'button', text: 'Zuordnen …',
                    onclick: function () {
                        var versions = {};
                        group.forEach(function (g) { versions[g.lang] = g.path; });
                        linkDialog(slug, versions);
                    }
                })
            ])
        ]));
    });
    if (!any) panel.appendChild(h('p', { class: 'empty-note', text: 'Keine unzugeordneten Sprachpaare gefunden. Sprachversionen werden u. a. an Verzeichnissen wie /de/ und /en/ erkannt.' }));
}

function linkDialog(slug, preset) {
    var rows = [];
    var rowsBox = h('div');
    var label = h('input', { type: 'text', value: slug || '' });

    function addRow(lang, path) {
        var langSel = selectField(Object.keys(state.langs).map(function (k) { return { value: k, label: k + ' – ' + state.langs[k] }; }), lang || 'de', function () {});
        var pathSel = selectField(state.pages.map(function (p) { return { value: p.path, label: p.path }; }), path || (state.file || ''), function () {});
        var row = h('div', { class: 'row', style: 'margin-bottom:6px' }, [
            langSel, pathSel,
            h('button', { class: 'btn small danger', type: 'button', text: '×', onclick: function () { row.remove(); rows = rows.filter(function (r) { return r !== row; }); } })
        ]);
        row._langSel = langSel;
        row._pathSel = pathSel;
        rows.push(row);
        rowsBox.appendChild(row);
    }

    if (preset) Object.keys(preset).forEach(function (l) { addRow(l, preset[l]); });
    else { addRow('de', state.file); addRow('en', ''); }

    modal({
        title: 'Sprachversionen zuordnen',
        body: h('div', null, [
            h('p', { class: 'muted', text: 'Ordnen Sie Dateien zu, die dieselbe logische Seite in verschiedenen Sprachen darstellen. Die Zuordnung wird gespeichert und den Text-Elementen werden stabile Übersetzungs-IDs zugewiesen.' }),
            h('div', { class: 'field' }, [h('label', { text: 'Seitenname' }), label]),
            rowsBox,
            h('button', { class: 'btn small', type: 'button', text: '+ weitere Sprache', onclick: function () { addRow('fr', ''); } })
        ]),
        actions: [
            { label: 'Abbrechen', value: null },
            {
                label: 'Zuordnen', kind: 'primary', onClick: function () {
                    var versions = {};
                    rows.forEach(function (r) {
                        if (r._pathSel.value) versions[r._langSel.value] = r._pathSel.value;
                    });
                    if (Object.keys(versions).length < 1) { toast('Bitte mindestens eine Datei wählen.', 'warn'); return false; }
                    return api('/api/lang-link', { label: label.value, versions: versions }).then(function () {
                        toast('Sprachversionen zugeordnet.', 'ok');
                        return loadState().then(function () { renderPanel(); return true; });
                    }).catch(function (e) { toast(e.message, 'err'); return false; });
                }
            }
        ]
    });
}

function translationTable(page) {
    api('/api/lang-status', { id: page.id }).then(function (res) {
        var data = res.data;
        var langs = Object.keys(page.versions);
        var head = [h('th', { text: 'Text-ID' })].concat(langs.map(function (l) { return h('th', { text: l.toUpperCase() }); })).concat([h('th', { text: 'Status' })]);
        var rows = data.rows.slice(0, 400).map(function (row) {
            return h('tr', null, [h('td', { class: 'mono', text: row.tid })]
                .concat(langs.map(function (l) {
                    var cell = row.langs[l] || {};
                    return h('td', null, [
                        h('span', { class: 'state-' + (cell.state || 'unknown'), text: cell.text === null ? '(fehlt)' : cell.text })
                    ]);
                }))
                .concat([h('td', { text: row.state })]));
        });
        modal({
            title: 'Texte: ' + page.label,
            wide: true,
            body: h('div', null, [
                h('p', { class: 'muted', text: 'Fehlend = im Partner fehlt die ID. Geändert = Text weicht vom zuletzt geprüften Stand ab.' }),
                h('table', { class: 'grid' }, [h('thead', null, [h('tr', null, head)]), h('tbody', null, rows)])
            ]),
            actions: [{ label: 'Schließen', value: null }]
        });
    }).catch(function (e) { toast(e.message, 'err'); });
}

function compareDialog(page, langA, langB) {
    api('/api/lang-compare', { id: page.id, a: langA, b: langB }).then(function (res) {
        var data = res.data;
        var table = h('table', { class: 'grid' }, [
            h('thead', null, [h('tr', null, [
                h('th', { text: 'ID' }), h('th', { text: langA.toUpperCase() }),
                h('th', { text: langB.toUpperCase() }), h('th', { text: 'Zustand' })
            ])]),
            h('tbody', null, data.rows.slice(0, 500).map(function (row) {
                return h('tr', null, [
                    h('td', { class: 'mono', text: row.tid }),
                    h('td', { text: row.a === null ? '(fehlt)' : row.a }),
                    h('td', { text: row.b === null ? '(fehlt)' : row.b }),
                    h('td', { text: row.state })
                ]);
            }))
        ]);
        var meta = h('div', { class: 'card' }, [
            h('div', { class: 'card-meta', text: 'Titel A: ' + (data.meta.a.title || '–') + ' | Titel B: ' + (data.meta.b.title || '–') }),
            h('div', { class: 'card-meta', text: 'lang A: ' + (data.meta.a.lang || '–') + ' | lang B: ' + (data.meta.b.lang || '–') }),
            h('div', { class: 'card-meta', text: 'Canonical A: ' + (data.meta.a.canonical || '–') + ' | B: ' + (data.meta.b.canonical || '–') }),
            h('div', { class: 'card-meta', text: 'hreflang A: ' + JSON.stringify(data.meta.a.hreflang) + ' | B: ' + JSON.stringify(data.meta.b.hreflang) })
        ]);
        modal({
            title: 'Sprachvergleich: ' + page.label,
            wide: true,
            body: h('div', null, [
                h('div', { class: 'tabs-inline' }, [
                    h('button', {
                        class: 'active', type: 'button', text: 'Texte',
                        onclick: function () {}
                    }),
                    h('button', {
                        type: 'button', text: 'Seiten nebeneinander',
                        onclick: function () { sideBySide(page, langA, langB); }
                    })
                ]),
                meta,
                h('p', { class: 'hint-inline', text: 'Bewusst sprachspezifische Inhalte (z. B. andere Links) erscheinen hier als Unterschied und sind kein Fehler.' }),
                table
            ]),
            actions: [{ label: 'Schließen', value: null }]
        });
    }).catch(function (e) { toast(e.message, 'err'); });
}

function sideBySide(page, langA, langB) {
    var modalEl = modal({
        title: 'Sprachversionen nebeneinander',
        wide: true,
        body: h('div', { class: 'compare-grid' }, [
            h('figure', null, [
                h('figcaption', { text: langA.toUpperCase() + ' – ' + page.versions[langA] }),
                h('iframe', { src: '/preview?path=' + encodeURIComponent(page.versions[langA]) + '&variant=draft&t=' + Date.now() })
            ]),
            h('figure', null, [
                h('figcaption', { text: langB.toUpperCase() + ' – ' + page.versions[langB] }),
                h('iframe', { src: '/preview?path=' + encodeURIComponent(page.versions[langB]) + '&variant=draft&t=' + Date.now() })
            ])
        ]),
        actions: [{ label: 'Schließen', value: null }]
    });
    return modalEl;
}

function renderPublishPanel(panel) {
    var box = h('div', null, [h('p', { class: 'empty-note', text: 'Änderungen werden geladen …' })]);
    panel.appendChild(box);
    loadPublishData(box);
}

function loadPublishData(box) {
    api('/api/changes', undefined, true).then(function (res) {
        var data = res.data;
        box.innerHTML = '';
        var changes = data.changes || {};
        var keys = Object.keys(changes);
        box.appendChild(h('h3', { text: 'Änderungen (' + keys.length + ')' }));
        if (!keys.length) {
            box.appendChild(h('p', { class: 'empty-note', text: 'Keine unveröffentlichten Änderungen.' }));
        }
        var selected = {};
        keys.forEach(function (rel) {
            var info = changes[rel];
            var cb = h('input', { type: 'checkbox', checked: true });
            cb.addEventListener('change', function () { selected[rel] = cb.checked; });
            selected[rel] = true;
            var impact = data.affected.files[rel] || {};
            box.appendChild(h('div', { class: 'card' }, [
                h('label', { class: 'check', style: 'display:flex;gap:6px;align-items:flex-start' }, [
                    cb,
                    h('span', null, [
                        h('div', { class: 'mono', text: rel }),
                        h('div', { class: 'card-meta', text: info.status + ' · ' + (impact.reason || 'Datei') +
                            (impact.pages && impact.pages.length ? ' · betrifft ' + impact.pages.length + ' Seite(n)' : '') })
                    ])
                ])
            ]));
        });

        if (Object.keys(data.affected.shared).length) {
            box.appendChild(h('div', { class: 'banner warn' }, [
                h('strong', { text: 'Gemeinsame Ressourcen betroffen' }),
                h('div', { text: 'Änderungen an diesen Dateien wirken sich auf mehrere Seiten/Sprachversionen aus:' }),
                h('ul', null, Object.keys(data.affected.shared).map(function (f) {
                    var pages = data.affected.shared[f];
                    return h('li', { text: f + ' → ' + (pages.length ? pages.join(', ') : 'keine direkte Referenz gefunden') });
                }))
            ]));
        }

        var validation = data.validation || { issues: [], counts: {} };
        box.appendChild(h('h3', { text: 'Prüfung' }));
        box.appendChild(h('div', null, [
            h('span', { class: 'chip', text: (validation.counts.error || 0) + ' Fehler' }),
            h('span', { class: 'chip', text: (validation.counts.warning || 0) + ' Warnungen' }),
            h('span', { class: 'chip', text: (validation.counts.info || 0) + ' Hinweise' })
        ]));
        var issueList = h('div');
        (validation.issues || []).slice(0, 120).forEach(function (issue) {
            issueList.appendChild(h('div', { class: 'issue ' + issue.level }, [
                h('div', { text: issue.message }),
                h('div', { class: 'iloc', text: issue.file + (issue.line ? ':' + issue.line : '') + (issue.category ? ' · ' + issue.category : '') })
            ]));
        });
        if (!(validation.issues || []).length) issueList.appendChild(h('p', { class: 'empty-note', text: 'Keine Probleme gefunden.' }));
        box.appendChild(issueList);

        var message = h('input', { type: 'text', placeholder: 'Beschreibung dieser Veröffentlichung (optional)' });
        box.appendChild(h('h3', { text: 'Veröffentlichen' }));
        box.appendChild(message);
        box.appendChild(h('p', { class: 'hint-inline', text: 'Vor dem Upload werden die betroffenen Remote-Dateien gesichert und jede Übertragung wird nachgeprüft. Fehler blockieren die Veröffentlichung.' }));
        if (state.perms.indexOf('publish') !== -1) {
            box.appendChild(h('div', { class: 'insp-actions' }, [
                h('button', {
                    class: 'btn primary', type: 'button', text: 'Alles veröffentlichen', disabled: !keys.length,
                    onclick: function () {
                        doPublish(message.value, null);
                    }
                }),
                h('button', {
                    class: 'btn', type: 'button', text: 'Nur Auswahl veröffentlichen', disabled: !keys.length,
                    onclick: function () {
                        var files = Object.keys(selected).filter(function (k) { return selected[k]; });
                        if (!files.length) { toast('Keine Dateien ausgewählt.', 'warn'); return; }
                        doPublish(message.value, files);
                    }
                }),
                h('button', {
                    class: 'btn', type: 'button', text: 'Erneut prüfen',
                    onclick: function () { loadPublishData(box); }
                })
            ]));
        } else {
            box.appendChild(h('p', { class: 'banner warn', text: 'Ihre Rolle darf nicht veröffentlichen. Bitten Sie einen Eigentümer.' }));
        }
        if (state.lastPublish) {
            box.appendChild(h('h3', { text: 'Letzte Veröffentlichung' }));
            box.appendChild(renderPublishResult(state.lastPublish));
        }
    }).catch(function (e) {
        box.innerHTML = '';
        box.appendChild(h('p', { class: 'banner err', text: 'Änderungen konnten nicht geladen werden: ' + e.message }));
    });
}

function renderPublishResult(result) {
    var wrap = h('div', { class: 'card' });
    wrap.appendChild(h('div', { class: 'card-title', text: 'Backup ' + result.id }));
    wrap.appendChild(h('div', { class: 'card-meta', text: result.applied.length + ' Datei(en) veröffentlicht' + (result.failed.length ? ', ' + result.failed.length + ' fehlgeschlagen' : '') }));
    if (result.failed.length) {
        wrap.appendChild(h('div', { class: 'banner err' }, result.failed.map(function (f) { return h('div', { text: f.file + ': ' + f.error }); })));
    }
    if (result.warnings && result.warnings.length) {
        wrap.appendChild(h('div', { class: 'banner warn' }, result.warnings.map(function (w) { return h('div', { text: w }); })));
    }
    if (result.liveCheck && result.liveCheck.available) {
        wrap.appendChild(h('h3', { text: 'Live-Prüfung' }));
        (result.liveCheck.results || []).forEach(function (r) {
            wrap.appendChild(h('div', { class: 'issue ' + (r.ok ? 'info' : 'error') }, [
                h('div', { text: r.status + ' – ' + r.url }),
                r.error ? h('div', { class: 'iloc', text: r.error }) : null
            ]));
        });
    } else if (result.liveCheck) {
        wrap.appendChild(h('p', { class: 'hint-inline', text: 'Live-Prüfung nicht möglich: ' + (result.liveCheck.reason || 'unbekannt') }));
    }
    return wrap;
}

function doPublish(message, files) {
    confirmDialog('Veröffentlichen', files
        ? 'Die ausgewählten ' + files.length + ' Datei(en) werden auf den Server übertragen.'
        : 'Alle Änderungen werden auf den Server übertragen. Zuvor werden Backups angelegt.', 'Jetzt veröffentlichen')
        .then(function (ok) {
            if (!ok) return;
            toast('Veröffentliche …', '');
            api('/api/publish', { message: message, files: files || [] }).then(function (res) {
                state.lastPublish = res.data;
                toast(res.data.applied.length + ' Datei(en) veröffentlicht.', 'ok');
                return loadState().then(function () {
                    state.tab = 'publish';
                    renderPanel();
                });
            }).catch(function (e) {
                toast(e.message, 'err');
                state.tab = 'publish';
                renderPanel();
            });
        });
}

function renderBackupsPanel(panel) {
    panel.appendChild(h('h3', { text: 'Backups' }));
    var box = h('div', null, [h('p', { class: 'empty-note', text: 'Backups werden geladen …' })]);
    panel.appendChild(box);
    api('/api/backups', undefined, true).then(function (res) {
        box.innerHTML = '';
        var list = res.backups || [];
        if (!list.length) {
            box.appendChild(h('p', { class: 'empty-note', text: 'Noch keine Backups. Sie entstehen bei jeder Veröffentlichung und bei manuellen Quelltext-Änderungen.' }));
            return;
        }
        box.appendChild(h('p', { class: 'hint-inline', text: list.length + ' Sicherung(en), neueste zuerst. „Ansehen“ ändert nichts.' }));
        var groups = {};
        var order = [];
        list.forEach(function (b) {
            var day = b.day || '';
            if (!groups[day]) { groups[day] = []; order.push(day); }
            groups[day].push(b);
        });
        order.sort().reverse();
        order.forEach(function (day) {
            box.appendChild(h('div', { class: 'day-head' }, [
                h('strong', { text: formatDay(day) }),
                h('span', { class: 'card-meta', text: groups[day].length + ' Sicherung(en)' })
            ]));
            groups[day].forEach(function (b) { box.appendChild(backupCard(b)); });
        });
    }).catch(function (e) {
        box.innerHTML = '';
        box.appendChild(h('p', { class: 'banner err', text: e.message }));
    });
}

function renderSourcePanel(panel) {
    panel.appendChild(h('h3', { text: 'Quelltext' }));
    var files = state.pages.map(function (p) { return p.path; });
    var file = h('select');
    files.forEach(function (f) { file.appendChild(h('option', { value: f, text: f })); });
    if (state.file) file.value = state.file;
    var area = h('textarea', { class: 'code-area' });
    var info = h('p', { class: 'hint-inline', text: '' });
    var current = { path: file.value, etag: null };
    var autoNote = h('p', { class: 'hint-inline', text: '' });
    area.addEventListener('input', debounce(function () {
        if (current.path !== file.value) return;
        if (isStructuralPath(current.path)) return;
        saveSource(current.path, area.value, false, true);
    }, 900));

    function load() {
        info.textContent = 'Lade ' + current.path + ' …';
        api('/api/file?path=' + encodeURIComponent(current.path)).then(function (res) {
            area.value = res.content;
            current.etag = res.etag;
            info.textContent = current.path + ' · ' + res.content.length + ' Zeichen' + (res.isDraft ? ' · Entwurf' : '');
            autoNote.textContent = isStructuralPath(current.path)
                ? 'PHP-/Serverdatei: Änderungen erst mit „Speichern“ (mit Rückfrage) übernehmen.'
                : 'Änderungen werden automatisch auf der Platte gespeichert.';
        }).catch(function (e) { info.textContent = e.message; });
    }
    file.addEventListener('change', function () { current.path = file.value; load(); });
    panel.appendChild(file);
    panel.appendChild(h('div', { style: 'height:6px' }));
    panel.appendChild(area);
    panel.appendChild(info);
    panel.appendChild(autoNote);
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        h('button', {
            class: 'btn primary', type: 'button', text: 'Speichern',
            onclick: function () { saveSource(current.path, area.value, false); }
        }),
        h('button', {
            class: 'btn', type: 'button', text: 'Zurücksetzen',
            onclick: function () { load(); }
        })
    ]));
    panel.appendChild(h('p', { class: 'hint-inline', text: 'PHP- und .htaccess-Änderungen erfordern eine zusätzliche Bestätigung und werden vorher gesichert. Für normale Inhaltsänderungen bitte die visuelle Bearbeitung nutzen.' }));
    if (state.file) load();
}

function isStructuralPath(path) {
    return /\.(php|phtml|htaccess|ini)$/i.test(String(path || ''));
}

function saveSource(path, content, confirmed, auto) {
    api('/api/save-file', { path: path, content: content, confirmStructural: confirmed, autosave: !!auto }).then(function (res) {
        if (!auto) toast('Datei gespeichert.', 'ok');
        markSaved();
        if (res.validation && res.validation.counts.error) {
            toast(res.validation.counts.error + ' Prüffehler nach dem Speichern.', 'err');
        }
        if (auto) return;
        openFile(path);
    }).catch(function (e) {
        if (e.status === 428) {
            confirmDialog('Strukturelle Änderung bestätigen', 'Die Datei ' + path + ' enthält ausführbaren Code (PHP) oder Server-Konfiguration. Die Änderung wird vorher gesichert. Fortfahren?', 'Speichern')
                .then(function (ok) { if (ok) saveSource(path, content, true); });
            return;
        }
        toast(e.message, 'err');
    });
}

function renderSettingsPanel(panel) {
    panel.appendChild(h('h3', { text: 'Website' }));
    var baseUrl = h('input', { type: 'text', value: state.baseUrl || '', placeholder: 'https://…' });
    var renderPhp = h('input', { type: 'checkbox', checked: state.renderPhp !== false });
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Basis-URL' }), baseUrl]));
    panel.appendChild(h('label', { class: 'check', style: 'display:flex;gap:6px;align-items:center;margin-bottom:8px' }, [
        renderPhp, h('span', { text: 'PHP-Seiten lokal ausführen' })
    ]));
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        h('button', {
            class: 'btn primary small', type: 'button', text: 'Speichern',
            onclick: function () {
                api('/api/settings', { baseUrl: baseUrl.value, renderPhp: renderPhp.checked }).then(function () {
                    toast('Einstellungen gespeichert.', 'ok');
                    return loadState();
                }).catch(function (e) { toast(e.message, 'err'); });
            }
        })
    ]));

    panel.appendChild(h('h3', { text: 'FTP-Quelle' }));
    var src = state.source || {};
    var host = h('input', { type: 'text', value: src.host || '' });
    var port = h('input', { type: 'number', value: 21 });
    var user = h('input', { type: 'text', value: '' , placeholder: 'unverändert lassen' });
    var pass = h('input', { type: 'password', value: '', placeholder: 'unverändert lassen' });
    var root = h('input', { type: 'text', value: src.root || '/htdocs' });
    var tls = h('input', { type: 'checkbox' });
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Host' }), host]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Port' }), port]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Benutzer' }), user]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Passwort' }), pass]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Startordner' }), root]));
    panel.appendChild(h('label', { class: 'check', style: 'display:flex;gap:6px;align-items:center;margin-bottom:8px' }, [tls, h('span', { text: 'Explizites TLS (FTPS)' })]));
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        state.perms.indexOf('sync') !== -1 ? h('button', {
            class: 'btn small', type: 'button', text: 'Verbindung testen',
            onclick: function () {
                api('/api/ftp-test', { source: { host: host.value, port: parseInt(port.value, 10), user: user.value || undefined, pass: pass.value || undefined, root: root.value, tls: tls.checked } })
                    .then(function (res) {
                        toast('FTP ok: ' + res.data.pwd + ', ' + res.data.count + ' Einträge', 'ok');
                    }).catch(function (e) { toast(e.message, 'err'); });
            }
        }) : null,
        state.perms.indexOf('manage') !== -1 ? h('button', {
            class: 'btn small primary', type: 'button', text: 'Speichern',
            onclick: function () {
                var source = { host: host.value, port: parseInt(port.value, 10), root: root.value, tls: tls.checked };
                if (user.value) source.user = user.value;
                if (pass.value) source.pass = pass.value;
                api('/api/settings', { source: source }).then(function () {
                    toast('FTP-Zugang gespeichert.', 'ok');
                    pass.value = '';
                    user.value = '';
                    return loadState();
                }).catch(function (e) { toast(e.message, 'err'); });
            }
        }) : null
    ]));
    panel.appendChild(h('p', { class: 'hint-inline', text: 'Zugangsdaten liegen ausschließlich in app/config.php auf diesem PC.' }));

    /* Supabase (optional) */
    panel.appendChild(h('h3', { text: 'Supabase (Medien-Cloud, optional)' }));
    var supa = state.media.cloud || {};
    var supaUrl = h('input', { type: 'text', value: supa.url || '', placeholder: 'https://xxxx.supabase.co' });
    var supaKey = h('input', { type: 'password', value: '', placeholder: supa.configured ? 'gespeichert – leer lassen' : 'Service-Role-Key' });
    var supaBucket = h('input', { type: 'text', value: supa.bucket || 'media' });
    var supaTable = h('input', { type: 'text', value: supa.table || 'media' });
    var supaOn = h('input', { type: 'checkbox', checked: !!supa.active });
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'URL' }), supaUrl]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Service-Key' }), supaKey]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Bucket' }), supaBucket]));
    panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Tabelle' }), supaTable]));
    panel.appendChild(h('label', { class: 'check', style: 'display:flex;gap:6px;align-items:center;margin-bottom:8px' }, [
        supaOn, h('span', { text: 'Aktiv (Bilder und Fundstellen in die Cloud spiegeln)' })
    ]));
    panel.appendChild(h('div', { class: 'insp-actions' }, [
        state.perms.indexOf('manage') !== -1 ? h('button', {
            class: 'btn small', type: 'button', text: 'Verbindung testen',
            onclick: function () {
                api('/api/supabase-test', {}).then(function (res) {
                    var d = res.data || {};
                    toast(d.ok ? 'Supabase erreichbar (Tabelle und Bucket).' : ('Supabase-Problem: ' + (d.problem || 'unbekannt')), d.ok ? 'ok' : 'err');
                }).catch(function (e) { toast(e.message, 'err'); });
            }
        }) : null,
        state.perms.indexOf('manage') !== -1 ? h('button', {
            class: 'btn small primary', type: 'button', text: 'Speichern',
            onclick: function () {
                api('/api/settings', { supabase: {
                    url: supaUrl.value, key: supaKey.value,
                    bucket: supaBucket.value, table: supaTable.value,
                    enabled: supaOn.checked
                } }).then(function () {
                    toast('Supabase-Einstellungen gespeichert.', 'ok');
                    supaKey.value = '';
                    return loadMedia().then(function () { loadState().catch(function () {}); renderPanel(); });
                }).catch(function (e) { toast(e.message, 'err'); });
            }
        }) : null
    ]));
    panel.appendChild(h('p', { class: 'hint-inline', text: 'Der Schlüssel liegt nur in app/config.php (0600) und wird nie an den Browser gesendet. Anleitung und SQL: Handbuch → „Supabase verbinden“. Ohne Aktivierung bleibt alles lokal.' }));

    panel.appendChild(h('h3', { text: 'System' }));
    panel.appendChild(h('div', { class: 'card' }, [
        h('div', { class: 'card-meta', text: 'PHP ' + (state.phpVersion || '?') + ' · Node für JS-Prüfung: ' + (state.nodeAvailable ? 'verfügbar' : 'nicht gefunden') }),
        h('div', { class: 'card-meta', text: 'Rolle: ' + (W.user ? W.user.role : '?') })
    ]));

    if (state.perms.indexOf('manage') !== -1) {
        panel.appendChild(h('h3', { text: 'Benutzer' }));
        var uname = h('input', { type: 'text', placeholder: 'Benutzername' });
        var upass = h('input', { type: 'password', placeholder: 'Passwort (min. 10)' });
        var urole = selectField([
            { value: 'editor', label: 'Redakteur (bearbeiten)' },
            { value: 'owner', label: 'Eigentümer (alles)' },
            { value: 'viewer', label: 'Betrachter' }
        ], 'editor', function () {});
        panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Neu' }), uname]));
        panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Passwort' }), upass]));
        panel.appendChild(h('div', { class: 'field' }, [h('label', { text: 'Rolle' }), urole]));
        panel.appendChild(h('div', { class: 'insp-actions' }, [
            h('button', {
                class: 'btn small primary', type: 'button', text: 'Benutzer anlegen',
                onclick: function () {
                    api('/api/users', { action: 'add', username: uname.value, password: upass.value, role: urole.value })
                        .then(function () { toast('Benutzer angelegt.', 'ok'); uname.value = ''; upass.value = ''; })
                        .catch(function (e) { toast(e.message, 'err'); });
                }
            })
        ]));
    }
}

/* ------------------------------------------------------------------ */
/* Kopfzeile / Werkzeugleiste                                          */
/* ------------------------------------------------------------------ */

function flushSave() {
    state.queue.then(function () {
        toast(state.pending > 0 ? 'Speichere noch …' : 'Alle Änderungen sind als Entwurf gesichert.', state.pending > 0 ? 'warn' : 'ok');
        if (state.pending === 0) loadState().catch(function () {});
    });
}

function openCompare() {
    if (!state.file) { toast('Bitte zuerst eine Seite öffnen.', 'warn'); return; }
    api('/api/diff?path=' + encodeURIComponent(state.file)).then(function (res) {
        var diffLines = (res.diff || []).map(function (line) {
            return h('div', { class: line.type }, [
                h('span', { class: 'n', text: line.n || '' }),
                document.createTextNode((line.type === 'add' ? '+ ' : line.type === 'del' ? '- ' : '  ') + line.text)
            ]);
        });
        modal({
            title: 'Vorher / Nachher: ' + state.file,
            wide: true,
            body: h('div', null, [
                h('div', { class: 'compare-grid' }, [
                    h('figure', null, [
                        h('figcaption', { text: 'Live (veröffentlicht)' }),
                        res.isNew ? h('p', { class: 'empty-note', text: 'Datei existiert noch nicht live.' }) :
                            h('iframe', { src: '/preview?path=' + encodeURIComponent(state.file) + '&variant=live&t=' + Date.now() })
                    ]),
                    h('figure', null, [
                        h('figcaption', { text: 'Entwurf (lokal)' }),
                        h('iframe', { src: '/preview?path=' + encodeURIComponent(state.file) + '&variant=draft&t=' + Date.now() })
                    ])
                ]),
                h('h3', { text: 'Textunterschiede' }),
                h('div', { class: 'diff' }, diffLines)
            ]),
            actions: [{ label: 'Schließen', value: null }]
        });
    }).catch(function (e) { toast(e.message, 'err'); });
}

/* ------------------------------------------------------------------ */
/* Start                                                               */
/* ------------------------------------------------------------------ */

function bindUi() {
    $('#tabs').addEventListener('click', function (e) {
        var btn = e.target.closest('.tab');
        if (!btn) return;
        state.tab = btn.dataset.tab;
        renderPanel();
        if (state.tab === 'media') openMediaDialog();
    });

    $('#pageSelect').addEventListener('change', function (e) {
        openFile(e.target.value);
    });

    $$('.devices .dev').forEach(function (btn) {
        btn.addEventListener('click', function () {
            $$('.devices .dev').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            state.device = btn.dataset.device;
            var frame = $('#deviceFrame');
            frame.className = 'device-frame ' + state.device;
            if (state.info) loadInfo(state.info.path);
        });
    });

    $('#btnUndo').addEventListener('click', undo);
    document.addEventListener('click', function (e) {
        if (ctxMenuEl && !ctxMenuEl.contains(e.target)) hideContextMenu();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hideContextMenu();
    });
    window.addEventListener('resize', hideContextMenu);
    $('#btnRedo').addEventListener('click', redo);
    $('#btnReload').addEventListener('click', function () { loadPreview(true); renderTree(); });
    $('#btnCompare').addEventListener('click', openCompare);
    $('#btnSave').addEventListener('click', flushSave);
    var publishBtn = $('#btnPublish');
    if (publishBtn) {
        publishBtn.addEventListener('click', function () {
            state.tab = 'publish';
            renderPanel();
        });
    }

    document.addEventListener('keydown', function (e) {
        var mod = e.ctrlKey || e.metaKey;
        if (!mod) return;
        if (e.key.toLowerCase() === 'z') {
            e.preventDefault();
            if (e.shiftKey) redo(); else undo();
        } else if (e.key.toLowerCase() === 'y') {
            e.preventDefault();
            redo();
        } else if (e.key.toLowerCase() === 's') {
            e.preventDefault();
            flushSave();
        }
    });

    // mobil: Inspektor ein-/ausblenden
    if (window.matchMedia('(max-width: 1000px)').matches) {
        $('#inspector').classList.add('open');
        $('#app').classList.add('inspector-open');
        $('#app').addEventListener('click', function (e) {
            if (e.target === $('#app')) {
                $('#inspector').classList.remove('open');
                $('#app').classList.remove('inspector-open');
            }
        });
    }
}

function init() {
    bindUi();
    loadState().then(function () {
        return loadRemoteIndex();
    }).then(function () {
        var last = localStorage.getItem('wfx.lastFile');
        var isKnown = function (p) {
            return state.pages.some(function (x) { return x.path === p; }) || !!remoteEntry(p);
        };
        var target = (last && isKnown(last)) ? last
            : (state.pages.length ? state.pages[0].path : firstRemotePage());
        if (target) openFile(target);
        else {
            renderInspector();
            renderPanel();
            toast('Noch keine Objekte bekannt. Bitte im Reiter "Seiten" den Server auflisten.', 'warn');
        }
    }).catch(function () {});
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

})();
