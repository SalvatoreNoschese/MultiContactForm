/*
 * MultiContactForm — frontend controller.
 *
 * Configuration (projects, reasons, fields, strings) is provided by the server
 * in <script type="application/json" id="contact-config">. Nothing is ever
 * built from the URL hash (#project or #project:reason): it only selects
 * known ids.
 */
(() => {
    'use strict';

    const cfgNode = document.getElementById('contact-config');
    let CFG;
    try {
        CFG = JSON.parse(cfgNode.textContent);
    } catch (e) {
        return;
    }

    const ID_RE = /^[a-z0-9_]{1,32}$/;
    const projects = new Map(CFG.projects.map((p) => [p.id, p]));
    const has = (obj, key) => Object.prototype.hasOwnProperty.call(obj, key);
    const $ = (id) => document.getElementById(id);

    const form = $('contact-form');
    const projectSel = $('project');
    const reasonSel = $('reason');
    const noticeBox = $('reason-notice');
    const condBox = $('conditional-fields');
    const messageEl = $('message');
    const hintEl = $('message-hint');
    const submitBtn = $('submit-btn');
    const summary = $('error-summary');
    const summaryText = $('error-summary-text');
    const summaryList = $('error-summary-list');
    const formView = $('form-view');
    const successView = $('success-view');
    const cancelBtn = $('cancel-btn');
    const returnBtn = $('return-btn');
    const autoBox = $('auto-return');
    const autoText = $('auto-return-text');

    // ── Language ───────────────────────────────────────────────────────────

    // Resolved by the server: the language in the address (/it/), which comes
    // from the saved cookie or the browser's Accept-Language when the link had
    // none. Changed live from the drop-down.
    const FALLBACK = CFG.fallback || 'en';
    const isLang = (code) => typeof code === 'string' && has(CFG.languages, code);
    let lang = isLang(CFG.lang) ? CFG.lang : FALLBACK;

    function t(key, vars) {
        const table = CFG.strings[lang] || {};
        let text = has(table, key) ? table[key] : ((CFG.strings[FALLBACK] || {})[key] || key);
        if (vars) {
            for (const [k, v] of Object.entries(vars)) {
                text = text.split('{' + k + '}').join(String(v));
            }
        }
        return text;
    }

    /** Picks the current language from an {it, en} label object. */
    const L = (obj) => (obj && (obj[lang] || obj[FALLBACK])) || '';

    function applyStaticTranslations() {
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-i18n]').forEach((el) => {
            let vars;
            if (el.dataset.i18nVars) {
                try { vars = JSON.parse(el.dataset.i18nVars); } catch (e) { vars = undefined; }
            }
            el.textContent = t(el.dataset.i18n, vars);
        });
        // "I have read the [privacy notice]": the bracketed words are the
        // link already inside the element (I18n::linkParts() in PHP).
        document.querySelectorAll('[data-i18n-link]').forEach((el) => {
            const link = el.querySelector('a');
            const m = /^([\s\S]*?)\[([\s\S]+?)\]([\s\S]*)$/.exec(t(el.dataset.i18nLink));
            if (!link) return;
            link.textContent = m ? m[2] : t(el.dataset.i18nLink);
            el.replaceChildren(m ? m[1] : '', link, m ? m[3] : '');
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
            el.placeholder = t(el.dataset.i18nPlaceholder);
        });
        document.querySelectorAll('[data-i18n-title]').forEach((el) => {
            el.title = t(el.dataset.i18nTitle);
        });
        document.querySelectorAll('[data-i18n-label]').forEach((el) => {
            const text = t(el.dataset.i18nLabel);
            el.setAttribute('aria-label', text);
            el.title = text;
        });
        form.elements.lang.value = lang;
    }

    // ── Project / reason / conditional fields ──────────────────────────────

    /** "#project" or "#project:reason" → known ids only, else ''. */
    function fromHash() {
        let key = '';
        try {
            key = decodeURIComponent(location.hash.replace(/^#\/?/, '')).trim().toLowerCase();
        } catch (e) {
            key = '';
        }
        const [pid = '', rid = ''] = key.split(':');
        const p = ID_RE.test(pid) ? projects.get(pid) : null;
        if (!p) return { project: '', reason: '' };
        return { project: pid, reason: ID_RE.test(rid) && p.reasons.some((r) => r.id === rid) ? rid : '' };
    }

    function option(value, text, i18nKey) {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = text;
        if (i18nKey) {
            opt.dataset.i18n = i18nKey;
        }
        return opt;
    }

    function currentProject() {
        return projects.get(projectSel.value) || null;
    }

    function currentReason() {
        const p = currentProject();
        return p ? p.reasons.find((r) => r.id === reasonSel.value) || null : null;
    }

    function renderProjects(selected) {
        projectSel.replaceChildren(option('', t('project_ph'), 'project_ph'));
        for (const p of CFG.projects) {
            projectSel.append(option(p.id, L(p.label)));
        }
        projectSel.value = projects.has(selected) ? selected : '';
    }

    function renderReasons(selected) {
        const p = currentProject();
        if (!p) {
            reasonSel.replaceChildren(option('', t('reason_need_project'), 'reason_need_project'));
            reasonSel.disabled = true;
            return;
        }
        reasonSel.replaceChildren(option('', t('reason_ph'), 'reason_ph'));
        for (const r of p.reasons) {
            reasonSel.append(option(r.id, L(r.label)));
        }
        reasonSel.disabled = false;
        reasonSel.value = p.reasons.some((r) => r.id === selected) ? selected : '';
    }

    /** Rebuilds conditional fields; fields that no longer apply are removed from the DOM. */
    function renderConditional() {
        const previous = {};
        condBox.querySelectorAll('input, select, textarea').forEach((el) => {
            previous[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        });
        condBox.replaceChildren();

        const r = currentReason();
        if (r && r.notice) {
            noticeBox.textContent = L(r.notice);
            noticeBox.hidden = false;
        } else {
            noticeBox.textContent = '';
            noticeBox.hidden = true;
        }
        if (!r) {
            return;
        }

        for (const f of r.fields) {
            const id = 'cf-' + f.name;
            if (f.type === 'consent') {
                condBox.append(consentField(f, id, previous[f.name] === true));
                continue;
            }
            const wrap = document.createElement('div');
            wrap.className = 'field';

            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = L(f.label);
            // Extra fields are always optional.
            const mark = document.createElement('span');
            mark.className = 'optional';
            mark.textContent = ' ' + t('optional');
            label.append(mark);
            wrap.append(label);

            let control;
            if (f.type === 'select') {
                control = document.createElement('select');
                control.append(option('', L(f.placeholder) || '—'));
                for (const o of f.options) {
                    control.append(option(o.value, L(o.label)));
                }
            } else {
                control = document.createElement('input');
                control.type = f.type === 'url' ? 'url' : 'text';
                if (f.type === 'url') {
                    control.inputMode = 'url';
                    control.spellcheck = false;
                    control.autocomplete = 'off';
                    if (f.hosts && f.hosts[0]) {
                        control.placeholder = 'https://' + f.hosts[0] + '/…';
                    }
                    // Never keep the fragment: it may hold a private key or token.
                    control.addEventListener('change', () => { stripFragment(control); });
                }
                if (f.maxlength) {
                    control.maxLength = f.maxlength;
                }
                if (f.type === 'text' && f.placeholder) {
                    control.placeholder = L(f.placeholder);
                }
            }
            control.id = id;
            control.name = f.name;
            // Error right under the control, then the help text.
            const err = document.createElement('p');
            err.className = 'field-error';
            err.id = f.name + '-error';
            err.hidden = true;
            const describedBy = [err.id];
            wrap.append(control, err);
            if (f.help) {
                const help = document.createElement('p');
                help.className = 'hint';
                help.id = id + '-hint';
                help.textContent = L(f.help);
                describedBy.push(help.id);
                wrap.append(help);
            }
            control.setAttribute('aria-describedby', describedBy.join(' '));

            if (has(previous, f.name)) {
                control.value = previous[f.name];
            }
            condBox.append(wrap);
        }
    }

    /** An opt-in checkbox (consent to publish): never ticked in advance. */
    function consentField(f, id, checked) {
        const wrap = document.createElement('div');
        wrap.className = 'field field-check';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.id = id;
        box.name = f.name;
        box.value = '1';
        box.checked = checked;
        const label = document.createElement('label');
        label.htmlFor = id;
        label.textContent = L(f.label);
        wrap.append(box, label);
        if (f.help) {
            const help = document.createElement('p');
            help.className = 'hint';
            help.id = id + '-hint';
            help.textContent = L(f.help);
            box.setAttribute('aria-describedby', help.id);
            wrap.append(help);
        }
        return wrap;
    }

    function stripFragment(input) {
        const i = input.value.indexOf('#');
        if (i !== -1) {
            input.value = input.value.slice(0, i);
        }
    }

    function syncHash() {
        const id = projectSel.value;
        const hash = id ? '#' + id + (reasonSel.value ? ':' + reasonSel.value : '') : '';
        const url = location.pathname + location.search + hash;
        // history.state kept: ui.js stores the way back in it.
        try { history.replaceState(history.state, '', url); } catch (e) { /* ignore */ }
    }

    // ── Message counter ────────────────────────────────────────────────────

    /** "From 20 to 5000 characters (N left)": one line, kept in data-i18n-vars for language switches. */
    function updateCounter() {
        const vars = {
            min: CFG.limits.message_min,
            max: CFG.limits.message_max,
            n: Math.max(0, CFG.limits.message_max - messageEl.value.length),
        };
        hintEl.dataset.i18nVars = JSON.stringify(vars);
        hintEl.textContent = t('message_hint', vars);
    }

    // ── Errors ─────────────────────────────────────────────────────────────

    let lastErrors = null; // { fields, key } — kept for re-translation
    let liveCheck = false; // after a failed attempt, errors follow every change

    function fieldControl(name) {
        // Attachments: the visible control is our button, not the hidden native input.
        const el = name === 'attachments' ? $('attachments-btn') : form.elements.namedItem(name);
        return el instanceof Element ? el : null;
    }

    function fieldLabel(name) {
        const el = fieldControl(name);
        const label = el && el.id ? form.querySelector('label[for="' + el.id + '"]') : null;
        return label ? label.firstChild.textContent.trim() : name;
    }

    function clearErrors() {
        lastErrors = null;
        summary.hidden = true;
        summaryList.replaceChildren();
        form.querySelectorAll('.field-error').forEach((p) => { p.hidden = true; p.textContent = ''; });
        form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    }

    function showErrors(fields, key, focus = true) {
        clearErrors();
        lastErrors = { fields, key };
        summaryText.textContent = key ? t(key) : '';
        for (const [name, err] of Object.entries(fields)) {
            if (!ID_RE.test(name) || !err || typeof err.code !== 'string') {
                continue;
            }
            const text = errorText(err);
            const p = $(name + '-error');
            const control = fieldControl(name);
            // "Required" needs no text under the field: red border + asterisk
            // already say it, and the summary above lists it in words. Every
            // other error explains WHAT is wrong, so it stays under the field.
            if (p && err.code !== 'required') {
                p.textContent = text;
                p.hidden = false;
            }
            if (control) {
                control.setAttribute('aria-invalid', 'true');
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = '#' + control.id;
                a.textContent = t('error_item', { label: fieldLabel(name), error: text }); // "Nom : …" in French
                a.addEventListener('click', (ev) => { ev.preventDefault(); control.focus(); });
                li.append(a);
                summaryList.append(li);
            }
        }
        summary.hidden = false;
        if (focus) {
            summary.focus();
        }
    }

    // ── Client-side validation (UX only; the server re-validates everything) ──

    const EMAIL_RE = /^[^\s@<>",;]+@[^\s@<>",;]+\.[^\s@<>",;]{2,}$/;

    function validateClient() {
        const lim = CFG.limits;
        const errors = {};
        const val = (n) => {
            const el = fieldControl(n);
            return el ? el.value.trim() : '';
        };

        // Nothing chosen = a required field left empty (no inline text, like the others).
        if (!currentProject()) errors.project = { code: projectSel.value ? 'project_invalid' : 'required' };
        else if (!currentReason()) errors.reason = { code: reasonSel.value ? 'reason_invalid' : 'required' };

        const name = val('name');
        if (!name) errors.name = { code: 'required' };
        else if (name.length < lim.name_min || name.length > lim.name_max) {
            errors.name = { code: 'name_length', vars: { min: lim.name_min, max: lim.name_max } };
        }

        const email = val('email');
        if (!email) errors.email = { code: 'required' };
        else if (email.length > lim.email_max || !EMAIL_RE.test(email)) errors.email = { code: 'email_invalid' };

        const msg = val('message');
        if (!msg) errors.message = { code: 'required' };
        else if (msg.length < lim.message_min) errors.message = { code: 'message_short', vars: { min: lim.message_min } };
        else if (msg.length > lim.message_max) errors.message = { code: 'message_long', vars: { max: lim.message_max } };

        const r = currentReason();
        if (r) {
            for (const f of r.fields) {
                const v = val(f.name);
                if (!v) continue;
                if (f.type === 'url') {
                    let ok = false;
                    try {
                        const u = new URL(v);
                        ok = u.protocol === 'https:' && !u.username && !u.password
                            && (f.hosts || []).includes(u.hostname.toLowerCase());
                    } catch (e) { ok = false; }
                    if (!ok) errors[f.name] = { code: 'url_invalid', vars: { hosts: (f.hosts || []).join(', ') } };
                }
            }
        }

        const attError = validateAttachments();
        if (attError) errors.attachments = attError;

        if (!form.elements.privacy.checked) errors.privacy = { code: 'privacy' };
        return errors;
    }

    /**
     * Once the visitor has seen the errors, each change re-checks the form:
     * fixed fields lose their error at once, the summary disappears when
     * nothing is left (until the message is sent). Redrawn only when the errors actually change, so the
     * summary (role="alert") is not re-announced at every keystroke.
     */
    function recheck() {
        if (!liveCheck || busy) {
            return;
        }
        const errors = validateClient();
        const sig = (f) => Object.keys(f).sort().map((k) => k + ':' + f[k].code).join('|');
        if (!Object.keys(errors).length) {
            clearErrors();
        } else if (!lastErrors || sig(errors) !== sig(lastErrors.fields)) {
            showErrors(errors, 'err_validation', false);
        }
    }

    // ── Attachments ────────────────────────────────────────────────────────
    // Same rules as the server (Attachments.php), checked as files are chosen.
    // Choosing again ADDS files (instead of replacing the selection) and each
    // file can be removed; both need DataTransfer, otherwise the browser's
    // native behaviour applies and the server still validates everything.

    const ATT = CFG.attachments || { enabled: false };
    const attInput = $('attachments');
    const attBtn = $('attachments-btn');
    const attStatus = $('attachments-status');
    const attList = $('attachments-list');
    let chosen = [];
    const canEdit = (() => {
        try { return typeof DataTransfer === 'function' && !!new DataTransfer().items; } catch (e) { return false; }
    })();

    /** Attachments::formatBytes(), in the language shown. */
    function formatBytes(bytes) {
        if (bytes >= 1048576) {
            return String(Math.round(bytes / 104857.6) / 10).replace(/\.0$/, '').replace('.', t('decimal_sep')) + ' MB';
        }
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    /** A field error's text; sizes ({size}) travel as bytes and are written here. */
    function errorText(err) {
        const vars = Object.assign({}, err.vars);
        if (typeof vars.size === 'number') vars.size = formatBytes(vars.size);
        return t('fe_' + err.code, vars);
    }

    /** The hint under the button: its sizes follow a language switch too. */
    function updateAttHint() {
        const hint = $('attachments-hint');
        if (!hint || !ATT.enabled) return;
        const vars = { n: ATT.max_files, file: formatBytes(ATT.max_file_bytes), total: formatBytes(ATT.max_total_bytes), types: ATT.types };
        hint.dataset.i18nVars = JSON.stringify(vars);
        hint.textContent = t('att_hint', vars);
    }

    function syncInput() {
        if (!canEdit || !attInput) return;
        const dt = new DataTransfer();
        chosen.forEach((f) => dt.items.add(f));
        attInput.files = dt.files;
    }

    function validateAttachments() {
        if (!ATT.enabled || chosen.length === 0) return null;
        if (chosen.length > ATT.max_files) return { code: 'att_too_many', vars: { max: ATT.max_files } };
        let total = 0;
        for (const f of chosen) {
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            if (f.size === 0) return { code: 'att_empty', vars: { name: f.name } };
            if (f.size > ATT.max_file_bytes) {
                return { code: 'att_file_too_large', vars: { name: f.name, size: ATT.max_file_bytes } };
            }
            if (!f.name.includes('.') || !ATT.extensions.includes(ext)) {
                return { code: 'att_type', vars: { name: f.name, types: ATT.types } };
            }
            total += f.size;
        }
        if (total > ATT.max_total_bytes) return { code: 'att_total_too_large', vars: { size: ATT.max_total_bytes } };
        return null;
    }

    function showAttachmentError() {
        const p = $('attachments-error');
        if (!p) return;
        const err = validateAttachments();
        p.hidden = !err;
        p.textContent = err ? errorText(err) : '';
        if (err) attBtn.setAttribute('aria-invalid', 'true');
        else attBtn.removeAttribute('aria-invalid');
    }

    function renderFiles() {
        if (!attList) return;
        attStatus.dataset.i18n = chosen.length ? 'att_count' : 'att_none';
        attStatus.dataset.i18nVars = JSON.stringify({ n: chosen.length });
        attStatus.textContent = t(attStatus.dataset.i18n, { n: chosen.length });
        attList.replaceChildren();
        chosen.forEach((f, i) => {
            const li = document.createElement('li');
            const name = document.createElement('span');
            name.className = 'file-name';
            name.textContent = f.name;
            const size = document.createElement('span');
            size.className = 'file-size';
            size.textContent = formatBytes(f.size);
            li.append(name, ' ', size);
            if (canEdit) {
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'linklike';
                rm.textContent = t('att_remove');
                rm.setAttribute('aria-label', t('att_remove_label', { name: f.name }));
                rm.addEventListener('click', () => {
                    chosen.splice(i, 1);
                    syncInput();
                    renderFiles();
                    showAttachmentError();
                    recheck();
                    attBtn.focus();
                });
                li.append(' ', rm);
            }
            attList.append(li);
        });
    }

    function clearAttachments() {
        chosen = [];
        if (attInput) attInput.value = '';
        syncInput();
        renderFiles();
        showAttachmentError();
    }

    if (attInput && ATT.enabled) {
        attBtn.addEventListener('click', () => attInput.click());
        attInput.addEventListener('change', () => {
            const picked = Array.from(attInput.files || []);
            if (canEdit) {
                const key = (f) => f.name + '|' + f.size + '|' + f.lastModified;
                const known = new Set(chosen.map(key));
                picked.forEach((f) => { if (!known.has(key(f))) chosen.push(f); });
                syncInput();
            } else {
                chosen = picked;
            }
            renderFiles();
            showAttachmentError();
        });
    }

    // ── Proof-of-work: official ALTCHA widget ──────────────────────────────
    // public/assets/vendor/altcha, the upstream build, unmodified.
    // Challenges come from challenge.php through a custom fetch, which also
    // refreshes the CSRF and form tokens; texts come from our language files.

    const widget = $('altcha');
    let altchaPayload = null;   // current solution, single use
    let altchaError = null;     // last challenge.php error code (e.g. 'rate')

    /** Registers our texts for the current language (altcha.* keys) and applies them. */
    function altchaLanguage() {
        const reg = globalThis.$altcha && globalThis.$altcha.i18n;
        const strings = (CFG.altcha || {})[lang] || (CFG.altcha || {})[FALLBACK];
        if (reg && typeof reg.set === 'function' && strings) reg.set(lang, strings);
        if (widget && typeof widget.configure === 'function') widget.configure({ language: lang });
    }

    async function challengeFetch() {
        const ft = encodeURIComponent(form.elements.form_token.value);
        const res = await fetch(CFG.endpoints.challenge + '?ft=' + ft, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        if (json && typeof json.csrf === 'string') form.elements.csrf.value = json.csrf;
        if (json && typeof json.form_token === 'string') form.elements.form_token.value = json.form_token;
        altchaError = res.ok && json && json.ok ? null : (json && json.error) || 'generic';
        return new Response(JSON.stringify(altchaError ? {} : json.challenge), {
            status: altchaError ? (res.status >= 400 ? res.status : 500) : 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    function startAltcha() {
        const A = globalThis.$altcha;
        if (!widget || !A || typeof widget.configure !== 'function') return false;
        widget.addEventListener('statechange', (ev) => {
            const d = ev.detail || {};
            altchaPayload = d.state === 'verified' && typeof d.payload === 'string' ? d.payload : null;
        });
        widget.configure({ fetch: challengeFetch });
        altchaLanguage();
        widget.verify();
        return true;
    }

    /** A solution is single use: start the next one right away. */
    function restartAltcha() {
        altchaPayload = null;
        if (widget && typeof widget.reset === 'function') {
            widget.reset();
            widget.verify();
        }
    }

    /** The current solution, waiting for (or restarting) the widget if needed. */
    function getAltchaPayload() {
        if (altchaPayload) return Promise.resolve(altchaPayload);
        if (!widget || typeof widget.getState !== 'function') return Promise.reject(new Error('altcha'));
        const state = widget.getState();
        if (state !== 'verifying') widget.verify();
        return new Promise((resolve, reject) => {
            const done = (fn, v) => { clearTimeout(timer); widget.removeEventListener('statechange', onState); fn(v); };
            const onState = (ev) => {
                const d = ev.detail || {};
                if (d.state === 'verified' && typeof d.payload === 'string') done(resolve, d.payload);
                else if (d.state === 'error' || d.state === 'expired') done(reject, Object.assign(new Error('altcha'), { code: altchaError }));
            };
            const timer = setTimeout(() => done(reject, new Error('timeout')), 120000);
            widget.addEventListener('statechange', onState);
        });
    }

    // ── Submission ─────────────────────────────────────────────────────────

    let busy = false;
    let lastSuccess = false;

    /**
     * multipart/form-data POST through XHR: unlike fetch it reports upload
     * progress, useful with attachments on a slow mobile connection.
     * Resolves to { ok, status, json }, or null on a network error.
     */
    function postForm(url, data, onProgress) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) onProgress(Math.min(99, Math.round((e.loaded * 100) / e.total)));
            });
            xhr.addEventListener('load', () => {
                let json = null;
                try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
                resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, json });
            });
            xhr.addEventListener('error', () => resolve(null));
            xhr.addEventListener('abort', () => resolve(null));
            xhr.send(data);
        });
    }

    function setBusy(state) {
        busy = state;
        submitBtn.disabled = state;
        submitBtn.dataset.i18n = state ? 'sending' : 'send';
        submitBtn.textContent = t(submitBtn.dataset.i18n);
        form.setAttribute('aria-busy', state ? 'true' : 'false');
    }

    async function onSubmit(ev) {
        ev.preventDefault();
        if (busy) {
            return;
        }
        clearErrors();
        condBox.querySelectorAll('input[type="url"]').forEach(stripFragment);

        const errors = validateClient();
        if (Object.keys(errors).length) {
            liveCheck = true;
            showErrors(errors, 'err_validation');
            return;
        }

        setBusy(true);
        let payload;
        try {
            payload = await getAltchaPayload();
        } catch (e) {
            setBusy(false);
            showErrors({}, e && e.code === 'rate' ? 'err_rate' : 'pow_error');
            return;
        }

        const data = new FormData(form);
        data.set('altcha', payload);
        data.set('lang', lang);
        const withFiles = chosen.length > 0;
        const res = await postForm(CFG.endpoints.submit, data, (pct) => {
            if (withFiles) submitBtn.textContent = t('sending_progress', { p: pct });
        });
        const json = res ? res.json : null;

        setBusy(false);
        // A payload is single-use: always prepare a fresh one for the next attempt.
        restartAltcha();

        if (json && typeof json.csrf === 'string') {
            form.elements.csrf.value = json.csrf;
        }
        if (json && typeof json.form_token === 'string') {
            form.elements.form_token.value = json.form_token;
        }
        if (!res) {
            showErrors({}, 'err_network');
            return;
        }
        if (res.ok && json && json.ok) {
            showSuccess();
            return;
        }
        // 413 may come from the web server itself (LimitRequestBody), as HTML.
        const code = json && typeof json.error === 'string' ? json.error : (res.status === 413 ? 'too_large' : 'generic');
        if (code === 'validation' && json.fields) {
            liveCheck = true;
            showErrors(json.fields, 'err_validation');
        } else {
            showErrors({}, has(CFG.strings[FALLBACK], 'err_' + code) ? 'err_' + code : 'err_generic');
        }
    }

    // The way back: the very page the visitor came from, when this tab arrived
    // here from another site (ui.js counted the steps in history.state). Then
    // "Back to …" under the form and after sending steps back there, after
    // sending also by itself after config auto_return seconds. With
    // nowhere to go back to (a bookmark, a typed address, a site that sends no
    // referrer, a new tab) the first button clears the form instead and the
    // second is not shown.
    function wayBack() {
        const st = history.state || {};
        if (!(st.mcfDepth > 0) || history.length <= st.mcfDepth) return null;
        let host = '';
        try { host = new URL(st.mcfFrom).hostname; } catch (e) { return null; }
        return { depth: st.mcfDepth, host };
    }

    /** "Back to WebTools" from a project's own site, "Back to example.com" from any other. */
    function backLabel(back) {
        const p = CFG.projects.find((x) => x.hosts.includes(back.host));
        return '← ' + t('back_to', { project: p ? L(p.label) : back.host });
    }

    function goBack() {
        stopCountdown();
        const back = wayBack();
        if (back) history.go(-back.depth);
    }

    /**
     * Starts over, as the link in the top bar does: the page again, without the
     * "#project:reason" (a plain reload would keep it, and some browsers also
     * refill the fields on a reload). Asks first when something was typed.
     */
    function resetForm() {
        const typed = ['name', 'email'].some((n) => form.elements[n].value.trim() !== '')
            || messageEl.value.trim() !== '' || chosen.length > 0;
        if (typed && !window.confirm(t('reset_confirm'))) return;
        location.replace(location.pathname + location.search);
    }

    const canGoBack = !!wayBack();
    if (canGoBack) {
        delete cancelBtn.dataset.i18n;
        cancelBtn.textContent = backLabel(wayBack());
    }
    cancelBtn.hidden = false;
    cancelBtn.addEventListener('click', () => (canGoBack ? goBack() : resetForm()));
    returnBtn.addEventListener('click', goBack);

    // Automatic return: a live countdown, paused while the tab is hidden, and
    // "Stay here" to stop it.
    let countdown = 0;
    let timer = null;

    function stopCountdown() {
        clearInterval(timer);
        timer = null;
        autoBox.hidden = true;
    }

    function renderCountdown() {
        autoText.textContent = t('auto_return', { seconds: countdown });
    }

    function startCountdown() {
        countdown = Number(CFG.auto_return) || 0;
        if (countdown <= 0 || !wayBack()) return;
        renderCountdown();
        autoBox.hidden = false;
        timer = setInterval(() => {
            if (document.hidden) return;
            countdown -= 1;
            if (countdown <= 0) goBack();
            else renderCountdown();
        }, 1000);
    }

    $('stay-btn').addEventListener('click', () => {
        stopCountdown();
        returnBtn.focus();
    });

    function renderSuccess() {
        const back = wayBack();
        returnBtn.hidden = !back;
        if (back) returnBtn.textContent = backLabel(back);
        if (timer) renderCountdown();
    }

    function showSuccess() {
        lastSuccess = true;
        renderSuccess();
        formView.hidden = true;
        successView.hidden = false;
        successView.focus();
        startCountdown();
    }

    function sendAnother() {
        stopCountdown();
        lastSuccess = false;
        liveCheck = false;
        messageEl.value = '';
        form.elements.privacy.checked = false;
        // The extra fields belong to the message just sent: a rating, a link
        // and above all the consent to publish are never carried over.
        condBox.replaceChildren();
        renderConditional();
        clearAttachments();
        updateCounter();
        successView.hidden = true;
        formView.hidden = false;
        messageEl.focus();
    }

    // ── Wiring ─────────────────────────────────────────────────────────────

    function renderAll() {
        const project = projectSel.value;
        const reason = reasonSel.value;
        applyStaticTranslations();
        renderProjects(project);
        renderReasons(reason);
        renderConditional();
        updateCounter();
        renderFiles();
        updateAttHint();
        altchaLanguage();
        if (canGoBack) {
            cancelBtn.textContent = backLabel(wayBack());
        }
        if (lastErrors) {
            showErrors(lastErrors.fields, lastErrors.key, false);
        } else {
            showAttachmentError();
        }
        if (lastSuccess) {
            renderSuccess();
        }
    }

    // Language chosen from the drop-down (the server has already saved the cookie).
    document.addEventListener('contact:language', (ev) => {
        const choice = ev.detail && ev.detail.lang;
        if (isLang(choice) && choice !== lang) {
            lang = choice;
            renderAll();
        }
    });

    projectSel.addEventListener('change', () => {
        renderReasons('');
        renderConditional();
        syncHash();
    });

    reasonSel.addEventListener('change', () => {
        renderConditional();
        syncHash();
    });

    window.addEventListener('hashchange', () => {
        const h = fromHash();
        if (h.project && (h.project !== projectSel.value || (h.reason && h.reason !== reasonSel.value))) {
            projectSel.value = h.project;
            renderReasons(h.reason);
            renderConditional();
        }
    });

    messageEl.addEventListener('input', updateCounter);
    form.addEventListener('input', recheck);
    form.addEventListener('change', recheck);
    form.addEventListener('submit', onSubmit);
    $('again-btn').addEventListener('click', sendAnother);

    // Initial render.
    applyStaticTranslations();
    const initial = fromHash();
    renderProjects(initial.project);
    renderReasons(initial.reason);
    renderConditional();
    updateCounter();
    startAltcha();
})();
