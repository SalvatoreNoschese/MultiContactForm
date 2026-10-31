/**
 * JavaScript smoke test: loads the page scripts in a fake DOM, in the order
 * app/views/form.php lists them, and fails on any error while they start, such as a function
 * called but deleted or renamed. `node --check` only proves a file parses;
 * a reference to a removed function passes it and breaks in production.
 *
 * Not a browser and not trying to be one: the fake DOM answers every property
 * and call with another fake object, so only real mistakes (undefined names,
 * calling something that is not a function of ours) surface.
 *
 * Usage: node bin/tools/smoke.js        (bin/lint.sh runs it)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
let failed = 0;
const ok = (label) => console.log('  OK  ' + label);
const no = (label, detail) => { failed++; console.log('  NO  ' + label + (detail ? '  — ' + detail : '')); };

/** Anything: every property is another fake, every call returns one. */
function fake(name) {
    const fn = function () {};
    return new Proxy(fn, {
        get(target, key) {
            if (key === Symbol.toPrimitive) return () => '';
            if (key === Symbol.iterator) return function* () {};
            if (key === 'then') return undefined;          // not a Promise
            if (key === 'length') return 0;
            if (key === 'forEach' || key === 'map' || key === 'filter' || key === 'some' || key === 'find') {
                return () => (key === 'some' ? false : key === 'find' ? undefined : []);
            }
            if (!(key in target)) target[key] = fake(name + '.' + String(key));
            return target[key];
        },
        set(target, key, value) { target[key] = value; return true; },
        apply() { return fake(name + '()'); },
        construct() { return fake('new ' + name); },
    });
}

/** The page configuration, as public/index.php emits it (same keys), minimal but valid. */
const CONFIG = {
    lang: 'en',
    languages: { en: 'English' },
    fallback: 'en',
    strings: { en: { send: 'Send', project_ph: 'Project', reason_ph: 'Reason', reason_need_project: '—' } },
    altcha: { en: { label: 'I am not a robot' } },
    projects: [{ id: 'demo', label: { en: 'Demo' }, reasons: [{ id: 'other', label: { en: 'Other' }, fields: [] }] }],
    limits: { name_min: 2, name_max: 100, email_max: 254, message_min: 20, message_max: 5000 },
    attachments: { enabled: false },
    endpoints: { submit: 'submit.php', challenge: 'challenge.php' },
};

function sandbox() {
    const elements = {};
    const element = (id) => elements[id] || (elements[id] = fake('#' + id));
    const document = fake('document');
    document.getElementById = (id) => (id === 'contact-config' ? { textContent: JSON.stringify(CONFIG) } : element(id));
    document.querySelector = (sel) => fake(sel);
    document.querySelectorAll = () => [];
    document.createElement = (tag) => fake('<' + tag + '>');
    document.documentElement = fake('html');
    document.addEventListener = () => {};
    const window = {
        document,
        location: { hash: '#demo', pathname: '/', search: '', href: 'https://contact.example/' },
        history: { replaceState() {} },
        navigator: { hardwareConcurrency: 2, language: 'en' },
        addEventListener() {},
        removeEventListener() {},
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        setTimeout, clearTimeout, setInterval, clearInterval,
        fetch: () => new Promise(() => {}),          // never answers: no network
        Response: class { constructor() {} },
        Worker: class { postMessage() {} terminate() {} },
        DataTransfer: class { constructor() { this.items = { add() {} }; this.files = []; } },
        FormData: class { set() {} append() {} },
        XMLHttpRequest: class { open() {} send() {} setRequestHeader() {} },
        customElements: { whenDefined: () => new Promise(() => {}), get: () => undefined },
        isSecureContext: true,
        crypto: globalThis.crypto,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        Element: class {},
        HTMLElement: class {},
        console,
        JSON, Math, Date, Array, Object, String, Number, Boolean, RegExp, Map, Set, Promise, Symbol, Error, TypeError, URL, encodeURIComponent, decodeURIComponent, parseInt, parseFloat, isNaN, Uint8Array, DataView, TextEncoder, btoa, atob,
    };
    window.window = window;
    window.self = window;
    window.globalThis = window;
    return vm.createContext(window);
}

// Our own scripts only (the vendored widget is tested upstream), as
// src="<?= e(asset('assets/js/x.js')) ?>".
const scripts = (fs.readFileSync(path.join(ROOT, 'app/views/form.php'), 'utf8')
    .match(/<script src="(?:<\?= e\((?:asset|url)\(')?assets\/js\/[^"']+/g) || [])
    .map((tag) => tag.replace(/^.*?(assets\/js\/)/, '$1'));

if (scripts.length === 0) {
    no('app/views/form.php lists no page scripts');
} else {
    const ctx = sandbox();
    for (const rel of scripts) {
        const file = path.join(ROOT, 'public', rel);
        try {
            vm.runInContext(fs.readFileSync(file, 'utf8'), ctx, { filename: rel, timeout: 5000 });
            ok(rel + ' starts');
        } catch (e) {
            no(rel + ' starts', String(e && e.message || e) + (e && e.stack ? ' @ ' + (e.stack.split('\n').find((l) => l.includes(rel + ':')) || '').trim() : ''));
        }
    }
}

process.on('unhandledRejection', (e) => no('unhandled rejection', String(e && e.message || e)));
setTimeout(() => process.exit(failed ? 1 : 0), 300);
