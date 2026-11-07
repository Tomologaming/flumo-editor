/* Laeuft INNERHALB der Vorschau-Seite. Uebernimmt Hervorhebung, Auswahl und
   Unterbindung von Navigation/Formularen. Alle Styles werden per JS gesetzt,
   damit sie auch bei restriktiven CSP-Regeln der Website greifen. */
(function () {
    'use strict';

    var PAGE = window.__WFX_PAGE || {};
    var ORIGIN = location.origin;
    var parentWin = window.parent;
    var LOCKED = ['HTML', 'HEAD', 'SCRIPT', 'STYLE', 'LINK', 'META', 'BASE', 'TITLE', 'TEMPLATE', 'NOSCRIPT'];
    var selectedEl = null;
    var hoverEl = null;
    var overlayOn = true;

    function post(msg) {
        try {
            msg.__wfx = true;
            parentWin.postMessage(msg, ORIGIN);
        } catch (e) { /* ignore */ }
    }

    function isLocked(el) {
        if (!el || el.nodeType !== 1) return true;
        if (LOCKED.indexOf(el.tagName) !== -1) return true;
        if (el === document.documentElement) return true;
        return false;
    }

    function pathOf(el) {
        var path = [];
        var node = el;
        while (node && node.nodeType === 1 && node !== document.documentElement) {
            var index = 0;
            var sib = node;
            while ((sib = sib.previousElementSibling)) index++;
            path.unshift(index);
            node = node.parentElement;
        }
        path.unshift(0);
        return path;
    }

    function byPath(path) {
        var node = document.documentElement;
        if (!node) return null;
        for (var i = 1; i < path.length; i++) {
            var idx = path[i];
            var children = node.children;
            if (!children || idx >= children.length) return null;
            node = children[idx];
        }
        return node;
    }

    function tagsOf(el) {
        var tags = [];
        var node = el;
        while (node && node.nodeType === 1) {
            tags.unshift(node.tagName.toLowerCase());
            node = node.parentElement;
        }
        return tags;
    }

    function labelOf(el) {
        var label = '<' + el.tagName.toLowerCase() + '>';
        if (el.id) {
            label += '#' + el.id;
        } else if (el.className && typeof el.className === 'string') {
            var first = el.className.trim().split(/\s+/)[0];
            if (first) label += '.' + first;
        }
        return label;
    }

    function breadcrumbOf(el) {
        var out = [];
        var node = el;
        while (node && node.nodeType === 1) {
            out.unshift({
                path: pathOf(node),
                tag: node.tagName.toLowerCase(),
                label: node === el ? labelOf(node) : labelOf(node)
            });
            node = node.parentElement;
        }
        return out;
    }

    function computedSnapshot(el) {
        var cs = window.getComputedStyle(el);
        var props = ['display', 'position', 'color', 'background-color', 'font-family', 'font-size',
            'font-weight', 'line-height', 'text-align', 'padding-top', 'padding-right', 'padding-bottom',
            'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border-radius',
            'border-top-width', 'border-top-style', 'border-top-color', 'opacity', 'width', 'height',
            'max-width', 'flex-direction', 'justify-content', 'align-items', 'gap', 'grid-template-columns'];
        var out = {};
        for (var i = 0; i < props.length; i++) {
            out[props[i]] = cs.getPropertyValue(props[i]);
        }
        var rect = el.getBoundingClientRect();
        return { props: out, rect: { w: Math.round(rect.width), h: Math.round(rect.height) } };
    }

    function box(id) {
        var el = document.getElementById(id);
        if (!el) {
            el = document.createElement('div');
            el.id = id;
            el.style.position = 'absolute';
            el.style.pointerEvents = 'none';
            el.style.zIndex = '2147482000';
            el.style.top = '0';
            el.style.left = '0';
            document.documentElement.appendChild(el);
        }
        return el;
    }

    function placeBox(el, color, fill, label) {
        if (!el) return;
        var rect = el.getBoundingClientRect();
        var boxEl = box(label ? 'wfx-sel-box' : 'wfx-hover-box');
        var scrollX = window.scrollX || window.pageXOffset || 0;
        var scrollY = window.scrollY || window.pageYOffset || 0;
        boxEl.style.left = (rect.left + scrollX) + 'px';
        boxEl.style.top = (rect.top + scrollY) + 'px';
        boxEl.style.width = Math.max(0, rect.width) + 'px';
        boxEl.style.height = Math.max(0, rect.height) + 'px';
        boxEl.style.outline = '2px solid ' + color;
        boxEl.style.outlineOffset = '-2px';
        boxEl.style.background = fill;
        boxEl.style.display = rect.width || rect.height ? 'block' : 'block';
        if (label) {
            boxEl.setAttribute('data-label', label);
            boxEl.style.setProperty('--wfx-label', '"' + label.replace(/"/g, '\\"') + '"');
            var tag = document.getElementById('wfx-sel-label');
            if (!tag) {
                tag = document.createElement('div');
                tag.id = 'wfx-sel-label';
                tag.style.position = 'absolute';
                tag.style.pointerEvents = 'none';
                tag.style.zIndex = '2147482001';
                tag.style.background = color;
                tag.style.color = '#fff';
                tag.style.font = '11px/1.4 ui-monospace, Consolas, monospace';
                tag.style.padding = '2px 6px';
                tag.style.borderRadius = '3px';
                tag.style.whiteSpace = 'nowrap';
                document.documentElement.appendChild(tag);
            }
            tag.textContent = label;
            tag.style.left = (rect.left + scrollX) + 'px';
            tag.style.top = Math.max(0, rect.top + scrollY - 18) + 'px';
            tag.style.display = 'block';
        }
    }

    function hideHover() {
        var b = document.getElementById('wfx-hover-box');
        if (b) b.style.display = 'none';
    }

    function clearSelection() {
        selectedEl = null;
        var b = document.getElementById('wfx-sel-box');
        if (b) b.style.display = 'none';
        var l = document.getElementById('wfx-sel-label');
        if (l) l.style.display = 'none';
        post({ type: 'cleared' });
    }

    function select(el, silent) {
        if (!el || isLocked(el)) return;
        selectedEl = el;
        placeBox(el, '#2563eb', 'rgba(37, 99, 235, .07)', labelOf(el));
        hideHover();
        if (!silent) {
            post({
                type: 'select',
                path: pathOf(el),
                tags: tagsOf(el),
                tag: el.tagName.toLowerCase(),
                breadcrumb: breadcrumbOf(el),
                computed: computedSnapshot(el),
                locked: isLocked(el)
            });
        }
    }

    function nearestSelectable(el) {
        var node = el;
        while (node && node.nodeType === 1) {
            if (!isLocked(node)) return node;
            node = node.parentElement;
        }
        return null;
    }

    /* ---------------------------------------------------------------- */
    /* Ereignisse                                                        */
    /* ---------------------------------------------------------------- */

    document.addEventListener('mousemove', function (e) {
        if (!overlayOn) return;
        var target = nearestSelectable(e.target);
        if (!target || target === selectedEl || target === hoverEl) return;
        hoverEl = target;
        placeBox(target, '#60a5fa', 'rgba(96, 165, 250, .12)', null);
    }, true);

    document.addEventListener('mouseleave', hideHover, true);

    document.addEventListener('click', function (e) {
        if (!overlayOn) return;
        e.preventDefault();
        e.stopPropagation();
        var target = nearestSelectable(e.target);
        if (target) select(target);
    }, true);

    document.addEventListener('mousedown', function (e) {
        if (!overlayOn) return;
        if (e.target && /^(INPUT|TEXTAREA|SELECT|OPTION)$/.test(e.target.tagName)) {
            e.preventDefault();
        }
    }, true);

    document.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        post({ type: 'notice', message: 'Formulare sind in der Vorschau deaktiviert.' });
    }, true);

    document.addEventListener('dragstart', function (e) {
        e.preventDefault();
    }, true);

    document.addEventListener('contextmenu', function (e) {
        if (!overlayOn) return;
        e.preventDefault();
        e.stopPropagation();
        var target = nearestSelectable(e.target);
        if (!target) return;
        select(target, true);
        var linkEl = null;
        var node = target;
        while (node && node.nodeType === 1) {
            if (node.tagName === 'A') { linkEl = node; break; }
            node = node.parentElement;
        }
        post({
            type: 'context',
            path: pathOf(target),
            tags: tagsOf(target),
            tag: target.tagName.toLowerCase(),
            breadcrumb: breadcrumbOf(target),
            computed: computedSnapshot(target),
            x: e.clientX,
            y: e.clientY,
            link: linkEl ? {
                path: pathOf(linkEl),
                isSelf: linkEl === target,
                href: linkEl.getAttribute('href') || '',
                text: (linkEl.textContent || '').trim().slice(0, 80)
            } : null
        });
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            clearSelection();
            return;
        }
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            if (!selectedEl) {
                var first = document.body.firstElementChild;
                if (first) select(nearestSelectable(first));
                return;
            }
            e.preventDefault();
            if (e.key === 'ArrowUp') {
                var parent = selectedEl.parentElement;
                while (parent && isLocked(parent) && parent !== document.documentElement) parent = parent.parentElement;
                if (parent && !isLocked(parent)) select(parent);
            } else {
                var child = selectedEl.firstElementChild;
                while (child && isLocked(child)) child = child.firstElementChild;
                if (child) select(child);
            }
        }
    }, true);

    window.addEventListener('scroll', function () {
        if (selectedEl) placeBox(selectedEl, '#2563eb', 'rgba(37, 99, 235, .07)', labelOf(selectedEl));
        if (hoverEl) placeBox(hoverEl, '#60a5fa', 'rgba(96, 165, 250, .12)', null);
    }, true);

    window.addEventListener('resize', function () {
        if (selectedEl) placeBox(selectedEl, '#2563eb', 'rgba(37, 99, 235, .07)', labelOf(selectedEl));
    });

    /* ---------------------------------------------------------------- */
    /* Sofortiges Anwenden von Aenderungen (optimistisch)                */
    /* ---------------------------------------------------------------- */

    function applyLocal(op) {
        var el = op.parentPath ? byPath(op.parentPath) : byPath(op.path);
        if (!el) return false;
        try {
            switch (op.kind) {
                case 'text':
                    el.textContent = op.value;
                    break;
                case 'attr':
                    if (op.value === null) el.removeAttribute(op.name);
                    else el.setAttribute(op.name, op.value);
                    break;
                case 'style':
                    if (op.value === null || op.value === '') el.style.removeProperty(op.prop);
                    else el.style.setProperty(op.prop, op.value);
                    break;
                case 'class':
                    (op.remove || []).forEach(function (c) { el.classList.remove(c); });
                    (op.add || []).forEach(function (c) { if (c) el.classList.add(c); });
                    break;
                case 'insert': {
                    var index = op.index;
                    var temp = document.createElement('div');
                    temp.innerHTML = op.html;
                    var ref = (index === null || index === undefined || index >= el.children.length)
                        ? null : el.children[index];
                    while (temp.firstElementChild) {
                        if (ref) el.insertBefore(temp.firstElementChild, ref);
                        else el.appendChild(temp.firstElementChild);
                    }
                    break;
                }
                case 'delete':
                    el.remove();
                    break;
                case 'duplicate':
                    el.insertAdjacentHTML('afterend', el.outerHTML);
                    break;
                case 'move': {
                    var target = byPath(op.target.parent);
                    if (!target) return false;
                    var idx = op.target.index;
                    var reference = idx >= target.children.length ? null : target.children[idx];
                    if (reference) target.insertBefore(el, reference);
                    else target.appendChild(el);
                    break;
                }
                case '_inner':
                    el.innerHTML = op.html;
                    break;
                default:
                    return false;
            }
        } catch (e) {
            return false;
        }
        return true;
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== ORIGIN) return;
        var msg = e.data || {};
        if (!msg || msg.__wfx !== true) return;
        switch (msg.type) {
            case 'restore':
                if (msg.sel && msg.sel.length) {
                    var el = byPath(msg.sel);
                    if (el) select(el, true);
                }
                if (typeof msg.scroll === 'number') {
                    window.scrollTo(0, msg.scroll);
                }
                setTimeout(function () {
                    if (selectedEl) placeBox(selectedEl, '#2563eb', 'rgba(37, 99, 235, .07)', labelOf(selectedEl));
                }, 60);
                break;
            case 'apply':
                if (msg.op) {
                    var okLocal = applyLocal(msg.op);
                    if (selectedEl) {
                        setTimeout(function () {
                            if (selectedEl && document.contains(selectedEl)) {
                                placeBox(selectedEl, '#2563eb', 'rgba(37, 99, 235, .07)', labelOf(selectedEl));
                            }
                        }, 0);
                    }
                    if (!okLocal) post({ type: 'localFailed' });
                }
                break;
            case 'select':
                if (msg.path) {
                    var node = byPath(msg.path);
                    if (node) select(node);
                }
                break;
            case 'clear':
                clearSelection();
                break;
            case 'pause':
                overlayOn = false;
                hideHover();
                break;
            case 'resume':
                overlayOn = true;
                break;
        }
    });

    /* Startmeldung */
    function ready() {
        document.documentElement.style.setProperty('--wfx-active', '1');
        post({ type: 'ready', file: PAGE.file, scrollHeight: document.body ? document.body.scrollHeight : 0 });
    }
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(ready, 30);
    } else {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(ready, 30); });
    }
})();
