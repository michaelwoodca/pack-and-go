(function () {
    'use strict';

    var PAG = window.PAG || {};
    var t = (PAG.sync && PAG.sync.i18n) || {};

    function s(key, fallback) {
        return typeof t[key] === 'string' ? t[key] : fallback;
    }

    function format(str, a, b) {
        return String(str).replace('%1', a).replace('%2', b);
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function initPreview() {
        var maps = document.querySelectorAll('[data-pag-map]');
        if (!maps.length) { return; }

        maps.forEach(function (sel) {
            sel.addEventListener('change', function () {
                var row = sel.closest('tr');
                var cell = row && row.querySelector('[data-pag-preview-cell]');
                if (!cell) { return; }

                var sample = PAG.sample || {};
                var value = (sel.value && sample[sel.value]) ? sample[sel.value] : '';
                var opt = sel.selectedOptions[0];
                var isImage = opt && opt.dataset.media === '1';

                if (isImage) {
                    cell.innerHTML = value
                        ? '<img class="pag-preview-img" src="' + encodeURI(value) + '" alt="" />'
                        : '<span class="pag-preview-empty">' + s('noImage', '(no image)') + '</span>';
                } else {
                    cell.innerHTML = '<code class="pag-preview-code">' + escapeHtml(value) + '</code>';
                }
            });
        });
    }

    function initPush() {
        var cfg = PAG.sync || {};
        var btn = document.getElementById('pag-push');
        if (!btn) { return; }

        var panel = document.getElementById('pag-progress');
        var titleEl = document.getElementById('pag-title');
        var track = document.getElementById('pag-track');
        var bar = document.getElementById('pag-bar');
        var statusEl = document.getElementById('pag-status');
        var errorsEl = document.getElementById('pag-errors');
        var cancelBtn = document.getElementById('pag-cancel');
        var spinner = panel && panel.querySelector('.pag-progress__title .dashicons');

        var canceled = false;
        var stallTimer = null;
        var lastProcessed = -1;

        function setPanelState(state) {
            panel.classList.remove('is-success', 'is-warning', 'is-error');
            if (state) { panel.classList.add(state); }
        }

        function setIcon(name, spin) {
            if (!spinner) { return; }
            spinner.className = 'dashicons dashicons-' + name + (spin ? ' pag-spin' : '');
        }

        function setBar(pct, indeterminate) {
            if (indeterminate) {
                track.classList.add('is-indeterminate');
                bar.removeAttribute('aria-valuenow');
                return;
            }
            track.classList.remove('is-indeterminate');
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', String(pct));
        }

        function clearStall() {
            if (stallTimer) { window.clearTimeout(stallTimer); stallTimer = null; }
        }

        function armStall() {
            clearStall();
            stallTimer = window.setTimeout(function () {
                var hint = document.getElementById('pag-hint');
                if (hint) { hint.textContent = s('stillWorking', 'Still working — large items and images can take a moment…'); }
            }, 8000);
        }

        function summary(p) {
            var moved = (p.created || 0) + (p.updated || 0);
            var parts = [format(moved === 1 ? s('itemMoved', '%1 item moved') : s('itemsMoved', '%1 items moved'), moved)];
            if (p.skipped) { parts.push(format(s('skipped', '%1 already up to date'), p.skipped)); }
            if (p.failed) { parts.push(format(s('failed', '%1 need attention'), p.failed)); }
            return parts.join(' · ') + '.';
        }

        function renderErrors(p) {
            if (!p.errors || !p.errors.length) { return; }
            errorsEl.innerHTML = '<p><strong>' + s('someNeedAttention', 'Some items need attention:') + '</strong></p>';
            var ul = document.createElement('ul');
            p.errors.forEach(function (e) {
                var li = document.createElement('li');
                li.textContent = e.title + ' — ' + e.message;
                ul.appendChild(li);
            });
            errorsEl.appendChild(ul);
        }

        function render(p) {
            var pct = p.total > 0 ? Math.round((p.processed / p.total) * 100) : (p.done ? 100 : 0);

            if (p.processed !== lastProcessed) { lastProcessed = p.processed; if (!p.done) { armStall(); } }

            if (!p.done) {
                setBar(pct, p.total === 0);
                setIcon('update', true);
                titleEl.textContent = s('importing', 'Moving your content…');
                statusEl.innerHTML = escapeHtml(format(s('movedOf', 'Moved %1 of %2…'), p.processed, p.total)) +
                    '<span class="pag-progress__hint" id="pag-hint"></span>';
                return;
            }

            clearStall();
            setBar(100, false);

            if (p.canceled) {
                setPanelState('is-warning');
                setIcon('dismiss', false);
                titleEl.textContent = s('canceled', 'Import canceled');
                statusEl.textContent = summary(p) + ' ' + s('canceledTail', 'Anything already moved is safe in NoTrouble.');
            } else if (p.failed > 0) {
                setPanelState('is-warning');
                setIcon('warning', false);
                titleEl.textContent = s('doneWithIssues', 'Done — a few items need a look');
                statusEl.textContent = summary(p) + ' ' + s('draftsTail', "They're waiting as drafts in NoTrouble — review and publish when you're ready.");
            } else {
                setPanelState('is-success');
                setIcon('yes-alt', false);
                titleEl.textContent = s('complete', 'All moved!');
                statusEl.textContent = summary(p) + ' ' + s('draftsTail', "They're waiting as drafts in NoTrouble — review and publish when you're ready.");
            }

            renderErrors(p);
        }

        function post(action, extra) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('_wpnonce', cfg.nonce);
            body.set('wp_type', cfg.wpType);
            Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
            return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); });
        }

        function fail(message) {
            clearStall();
            panel.style.display = 'block';
            setPanelState('is-error');
            setBar(100, false);
            setIcon('no', false);
            titleEl.textContent = s('stopped', 'Import stopped');
            statusEl.textContent = message || s('couldNotComplete', 'The import could not be completed.');
            enableButton(s('tryAgain', 'Try again'));
        }

        function enableButton(label) {
            btn.disabled = false;
            btn.textContent = label;
            if (cancelBtn) { cancelBtn.style.display = 'none'; }
        }

        function loop() {
            if (canceled) { return; }
            post('pack_and_go_sync_batch').then(function (res) {
                if (!res || !res.success) { return fail(res && res.data ? res.data.message : ''); }
                render(res.data);
                if (res.data.done) { enableButton(s('pushAgain', 'Push again')); return; }
                loop();
            }).catch(function () { fail(s('interrupted', 'The connection was interrupted. Please try again.')); });
        }

        function begin() {
            canceled = false;
            lastProcessed = -1;
            btn.disabled = true;
            btn.textContent = s('importing', 'Moving your content…');
            errorsEl.innerHTML = '';
            setPanelState('');
            setIcon('update', true);
            titleEl.textContent = s('preparing', 'Preparing…');
            setBar(0, true);
            panel.style.display = 'block';
            statusEl.innerHTML = '<span id="pag-hint"></span>';
            if (cancelBtn) { cancelBtn.style.display = ''; cancelBtn.disabled = false; }

            var form = document.querySelector('form[data-pag-mapping-form]');

            var saved = form
                ? fetch(cfg.saveUrl, { method: 'POST', credentials: 'same-origin', body: new FormData(form) })
                : Promise.resolve();

            saved
                .then(function () { return post('pack_and_go_sync_start'); })
                .then(function (res) {
                    if (canceled) { return; }
                    if (!res || !res.success) { return fail(res && res.data ? res.data.message : ''); }
                    render(res.data);
                    loop();
                })
                .catch(function () { fail(s('couldNotStart', 'We could not start the import. Please try again.')); });
        }

        btn.addEventListener('click', begin);

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                canceled = true;
                cancelBtn.disabled = true;
                cancelBtn.textContent = s('canceling', 'Canceling…');
                clearStall();
                post('pack_and_go_sync_cancel').then(function (res) {
                    if (res && res.success) { render(res.data); }
                    enableButton(s('pushAgain', 'Push again'));
                }).catch(function () { enableButton(s('pushAgain', 'Push again')); });
            });
        }

        if (cfg.resumable) {
            var resume = document.getElementById('pag-resume');
            if (resume) {
                resume.addEventListener('click', function (e) {
                    e.preventDefault();
                    panel.style.display = 'block';
                    canceled = false;
                    btn.disabled = true;
                    if (cancelBtn) { cancelBtn.style.display = ''; cancelBtn.disabled = false; }
                    setIcon('update', true);
                    titleEl.textContent = s('importing', 'Moving your content…');
                    loop();
                });
            }
        }
    }

    function initSelect() {
        var form = document.querySelector('form[data-pag-select-form]');
        if (!form) { return; }

        var buttons = document.querySelectorAll('[data-pag-select]');
        if (!buttons.length) { return; }

        function boxes() {
            return form.querySelectorAll('input[type="checkbox"][name="items[]"]');
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () {
                var mode = b.getAttribute('data-pag-select');
                boxes().forEach(function (cb) {
                    if (mode === 'all') { cb.checked = true; }
                    else if (mode === 'none') { cb.checked = false; }
                    else { cb.checked = (cb.getAttribute('data-status') === mode); }
                });
            });
        });
    }

    function initPushAll() {
        var cfg = PAG.pushAll || {};
        var btn = document.getElementById('pag-push-all');
        var types = cfg.types || [];
        if (!btn || !types.length) { return; }

        var panel = document.getElementById('pag-progress');
        var titleEl = document.getElementById('pag-title');
        var track = document.getElementById('pag-track');
        var bar = document.getElementById('pag-bar');
        var statusEl = document.getElementById('pag-status');
        var errorsEl = document.getElementById('pag-errors');
        var cancelBtn = document.getElementById('pag-cancel');
        var spinner = panel && panel.querySelector('.pag-progress__title .dashicons');

        var canceled = false;
        var agg;

        function setPanelState(state) {
            panel.classList.remove('is-success', 'is-warning', 'is-error');
            if (state) { panel.classList.add(state); }
        }
        function setIcon(name, spin) {
            if (spinner) { spinner.className = 'dashicons dashicons-' + name + (spin ? ' pag-spin' : ''); }
        }
        function setBar(pct, indeterminate) {
            if (indeterminate) { track.classList.add('is-indeterminate'); bar.removeAttribute('aria-valuenow'); return; }
            track.classList.remove('is-indeterminate');
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', String(pct));
        }
        function post(action, type, extra) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('_wpnonce', cfg.nonce);
            body.set('wp_type', type);
            Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
            return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
        }
        function note(type, message) {
            agg.errors.push({ title: type.label, message: message || s('interrupted', 'The connection was interrupted. Please try again.') });
        }

        function finish() {
            setBar(100, false);
            if (cancelBtn) { cancelBtn.style.display = 'none'; }
            var moved = agg.created + agg.updated;
            var parts = [format(moved === 1 ? s('itemMoved', '%1 item moved') : s('itemsMoved', '%1 items moved'), moved)];
            if (agg.skipped) { parts.push(format(s('skipped', '%1 already up to date'), agg.skipped)); }
            if (agg.failed) { parts.push(format(s('failed', '%1 need attention'), agg.failed)); }
            var summary = parts.join(' · ') + '.';

            if (canceled) {
                setPanelState('is-warning'); setIcon('dismiss', false);
                titleEl.textContent = s('canceled', 'Import canceled');
                statusEl.textContent = summary + ' ' + s('canceledTail', 'Anything already moved is safe in NoTrouble.');
            } else if (agg.failed > 0) {
                setPanelState('is-warning'); setIcon('warning', false);
                titleEl.textContent = s('doneWithIssues', 'Done — a few items need a look');
                statusEl.textContent = summary + ' ' + s('draftsTail', "They're waiting as drafts in NoTrouble — review and publish when you're ready.");
            } else if (moved === 0) {
                setPanelState('is-success'); setIcon('yes-alt', false);
                titleEl.textContent = s('complete', 'All moved!');
                statusEl.textContent = s('nothingToMove', 'Everything was already up to date — nothing new to move.');
            } else {
                setPanelState('is-success'); setIcon('yes-alt', false);
                titleEl.textContent = s('complete', 'All moved!');
                statusEl.textContent = summary + ' ' + s('draftsTail', "They're waiting as drafts in NoTrouble — review and publish when you're ready.");
            }

            if (agg.errors.length) {
                errorsEl.innerHTML = '<p><strong>' + s('someNeedAttention', 'Some items need attention:') + '</strong></p>';
                var ul = document.createElement('ul');
                agg.errors.forEach(function (e) {
                    var li = document.createElement('li');
                    li.textContent = e.title + ' — ' + e.message;
                    ul.appendChild(li);
                });
                errorsEl.appendChild(ul);
            }

            btn.disabled = false;
            btn.textContent = s('pushAgain', 'Push again');
        }

        function runType(i) {
            if (canceled) { return finish(); }
            if (i >= types.length) { return finish(); }
            var type = types[i];

            setIcon('update', true);
            titleEl.textContent = s('preparing', 'Preparing…');
            setBar(0, true);

            post('pack_and_go_sync_start', type.name, { all: '1' }).then(function (res) {
                if (!res || !res.success) { note(type, res && res.data ? res.data.message : ''); return runType(i + 1); }
                (function batch() {
                    if (canceled) { return finish(); }
                    post('pack_and_go_sync_batch', type.name).then(function (r) {
                        if (!r || !r.success) { note(type, r && r.data ? r.data.message : ''); return runType(i + 1); }
                        var p = r.data;
                        var pct = p.total > 0 ? Math.round((p.processed / p.total) * 100) : 0;
                        setBar(pct, p.total === 0);
                        setIcon('update', true);
                        titleEl.textContent = format(s('movingSet', 'Moving %1 — set %2 of %3…'), type.label, i + 1, types.length);
                        statusEl.textContent = format(s('movedOf', 'Moved %1 of %2…'), p.processed, p.total);
                        if (p.done) {
                            agg.created += p.created || 0; agg.updated += p.updated || 0;
                            agg.skipped += p.skipped || 0; agg.failed += p.failed || 0;
                            if (p.errors && p.errors.length) { agg.errors = agg.errors.concat(p.errors); }
                            return runType(i + 1);
                        }
                        batch();
                    }).catch(function () { note(type); runType(i + 1); });
                }());
            }).catch(function () { note(type); runType(i + 1); });
        }

        btn.addEventListener('click', function () {
            canceled = false;
            agg = { created: 0, updated: 0, skipped: 0, failed: 0, errors: [] };
            btn.disabled = true;
            btn.textContent = s('importing', 'Moving your content…');
            errorsEl.innerHTML = '';
            setPanelState('');
            panel.style.display = 'block';
            statusEl.textContent = '';
            if (cancelBtn) { cancelBtn.style.display = ''; cancelBtn.disabled = false; }
            runType(0);
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                canceled = true;
                cancelBtn.disabled = true;
                cancelBtn.textContent = s('canceling', 'Canceling…');
            });
        }
    }

    function initConfirm() {
        document.querySelectorAll('form[data-pag-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var message = form.getAttribute('data-pag-confirm');
                if (message && !window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPreview();
        initPush();
        initPushAll();
        initSelect();
        initConfirm();
    });
}());
