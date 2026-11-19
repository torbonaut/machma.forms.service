/*!
 * machma forms – Embed-Skript v1
 * https://forms.machma.app · MIT License · © 2026 machma.app
 *
 * <div data-machma-form="site/form" data-locale="de"></div>             → rendert das Formular aus /spec
 * <form data-machma-form="site/form" data-redirect-url="/danke">…</form> → übernimmt eigenes HTML
 * <script src="https://forms.machma.app/v1/embed.js?v=1" defer></script>
 *
 * Kein Framework, kein Shadow DOM, keine Cookies, keine Drittanbieter.
 * Texte werden ausschließlich per textContent gesetzt, nie per innerHTML.
 */
(function () {
  'use strict';

  if (window.machmaForms) return;

  var VERSION = '1.0.0';
  var MIN_MS = 3200;           // server requires >= 3 s between challenge and submit
  var CHALLENGE_MAX_AGE = 9 * 60 * 1000;
  var HONEYPOT = '_website';

  var script = document.currentScript;
  var DEFAULT_ENDPOINT = (function () {
    try { return new URL(script && script.src ? script.src : '', location.href).origin; } catch (e) { return 'https://forms.machma.app'; }
  })();

  var TEXT = {
    de: {
      submit: 'Absenden', sending: 'Wird gesendet …', success: 'Danke! Ihre Nachricht wurde gesendet.',
      error: 'Etwas ist schiefgelaufen. Bitte später erneut versuchen.', network: 'Keine Verbindung. Bitte später erneut versuchen.',
      load: 'Das Formular konnte nicht geladen werden.', choose: 'Bitte wählen …',
      protecting: 'Spamschutz wird berechnet …', protectedOk: 'Spamschutz aktiv – ohne Cookies (ALTCHA)',
      protectFailed: 'Spamschutz konnte nicht geladen werden.', honeypot: 'Bitte leer lassen', required: 'Pflichtfeld'
    },
    en: {
      submit: 'Send', sending: 'Sending …', success: 'Thank you! Your message has been sent.',
      error: 'Something went wrong. Please try again later.', network: 'No connection. Please try again later.',
      load: 'The form could not be loaded.', choose: 'Please choose …',
      protecting: 'Calculating spam protection …', protectedOk: 'Spam protection active – no cookies (ALTCHA)',
      protectFailed: 'Spam protection could not be loaded.', honeypot: 'Please leave empty', required: 'Required'
    }
  };

  function t(locale, key) { return (TEXT[locale] || TEXT.de)[key] || TEXT.de[key]; }
  function pick(i18n, locale) {
    if (!i18n) return '';
    if (i18n[locale] != null) return i18n[locale];
    if (i18n.de != null) return i18n.de;
    for (var k in i18n) if (Object.prototype.hasOwnProperty.call(i18n, k)) return i18n[k];
    return '';
  }
  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (!Object.prototype.hasOwnProperty.call(attrs, k) || attrs[k] == null || attrs[k] === false) continue;
      if (k === 'className') node.className = attrs[k];
      else node.setAttribute(k, attrs[k] === true ? '' : String(attrs[k]));
    }
    if (text != null) node.textContent = text;
    return node;
  }

  // ------------------------------------------------------------------ SHA-256 (sync, for ALTCHA proof-of-work)
  var K = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
  ];
  var W = new Int32Array(64);
  function sha256hex(str) { // ASCII input only (salt + number)
    var len = str.length, nBlocks = ((len + 8) >> 6) + 1, words = new Int32Array(nBlocks * 16), i;
    for (i = 0; i < len; i++) words[i >> 2] |= str.charCodeAt(i) << (24 - (i & 3) * 8);
    words[len >> 2] |= 0x80 << (24 - (len & 3) * 8);
    words[nBlocks * 16 - 1] = len * 8;
    var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a,
        h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
    for (var b = 0; b < nBlocks; b++) {
      for (i = 0; i < 16; i++) W[i] = words[b * 16 + i];
      for (i = 16; i < 64; i++) {
        var x = W[i - 15], y = W[i - 2];
        var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
        var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
        W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0;
      }
      var a = h0, bb = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
      for (i = 0; i < 64; i++) {
        var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
        var t1 = (h + S1 + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0;
        var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
        var t2 = (S0 + ((a & bb) ^ (a & c) ^ (bb & c))) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = bb; bb = a; a = (t1 + t2) | 0;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + bb) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
    }
    var out = '', hs = [h0, h1, h2, h3, h4, h5, h6, h7];
    for (i = 0; i < 8; i++) out += ('00000000' + (hs[i] >>> 0).toString(16)).slice(-8);
    return out;
  }

  /** Fetches an ALTCHA challenge and solves it in small chunks so the page stays responsive. */
  function solveChallenge(endpoint, site) {
    var fetchedAt = Date.now();
    return fetch(endpoint + '/v1/challenge?site=' + encodeURIComponent(site), { credentials: 'omit', mode: 'cors' })
      .then(function (r) { if (!r.ok) throw new Error('challenge ' + r.status); return r.json(); })
      .then(function (ch) {
        if (ch.algorithm !== 'SHA-256') throw new Error('algorithm');
        var start = Date.now();
        return new Promise(function (resolve, reject) {
          var n = 0, max = ch.maxnumber || 1000000;
          (function step() {
            var until = Math.min(n + 4000, max);
            for (; n <= until; n++) {
              if (sha256hex(ch.salt + n) === ch.challenge) {
                resolve({
                  fetchedAt: fetchedAt,
                  payload: btoa(JSON.stringify({
                    algorithm: ch.algorithm, challenge: ch.challenge, number: n,
                    salt: ch.salt, signature: ch.signature, took: Date.now() - start
                  }))
                });
                return;
              }
            }
            if (n > max) reject(new Error('unsolved'));
            else setTimeout(step, 0);
          })();
        });
      });
  }

  // ------------------------------------------------------------------ default styles (overridable, low specificity)
  // <script src=".../embed.js" data-styles="off"> → no default styles (e.g. under a strict style-src CSP)
  var STYLES_OFF = !!(script && script.getAttribute('data-styles') === 'off');
  function injectStyles() {
    if (STYLES_OFF || document.getElementById('mf-default-styles')) return;
    var css =
      ':where(.mf-form){--mf-gap:1rem;--mf-accent:#1f5f4a;--mf-accent-ink:#fff;--mf-error:#b3261e;--mf-border:#c9c8c2;--mf-radius:6px;display:grid;gap:var(--mf-gap)}' +
      ':where(.mf-field){display:grid;gap:.35rem;margin:0;padding:0;border:0;min-width:0}' +
      ':where(.mf-label){font-weight:600}' +
      ':where(.mf-required){color:var(--mf-error);margin-left:.15em}' +
      ':where(.mf-input){font:inherit;padding:.6rem .7rem;border:1px solid var(--mf-border);border-radius:var(--mf-radius);background:inherit;color:inherit;width:100%;box-sizing:border-box}' +
      ':where(.mf-input:focus-visible){outline:2px solid var(--mf-accent);outline-offset:1px}' +
      ':where(.mf-options){display:grid;gap:.35rem}' +
      ':where(.mf-option){display:flex;gap:.5rem;align-items:flex-start;font-weight:normal}' +
      ':where(.mf-option input){margin-top:.25em}' +
      ':where(.mf-help){font-size:.875em;opacity:.75;margin:0}' +
      ':where(.mf-error){color:var(--mf-error);font-size:.875em;margin:0}' +
      ':where(.mf-invalid){border-color:var(--mf-error)}' +
      ':where(.mf-submit){font:inherit;font-weight:600;justify-self:start;padding:.7rem 1.4rem;border:0;border-radius:var(--mf-radius);background:var(--mf-accent);color:var(--mf-accent-ink);cursor:pointer}' +
      ':where(.mf-submit[disabled]){opacity:.6;cursor:progress}' +
      ':where(.mf-status){margin:0}' +
      ':where(.mf-status.mf-status--error){color:var(--mf-error)}' +
      ':where(.mf-altcha){font-size:.8em;opacity:.65;margin:0}' +
      ':where(.mf-success){padding:1rem 1.2rem;border-left:4px solid var(--mf-accent);background:color-mix(in srgb,var(--mf-accent) 8%,transparent)}';
    try {
      var style = el('style', { id: 'mf-default-styles' });
      style.textContent = css;
      (document.head || document.documentElement).appendChild(style);
    } catch (e) { /* CSP may block inline styles – the form still works unstyled */ }
  }

  function hideVisually(node) {
    // CSSOM assignments are allowed under a strict style-src CSP (unlike style attributes).
    node.style.position = 'absolute';
    node.style.left = '-10000px';
    node.style.width = '1px';
    node.style.height = '1px';
    node.style.overflow = 'hidden';
  }

  // ------------------------------------------------------------------ rendering (mode 1)
  var uid = 0;
  function renderField(field, locale, ns) {
    var id = ns + '-' + field.name + '-' + (++uid);
    var label = pick(field.label, locale);
    var required = !!field.required;
    var type = field.type;
    var input, wrap;

    if (type === 'hidden') {
      return el('input', { type: 'hidden', name: field.name, value: field['default'] != null ? field['default'] : '' });
    }

    function labelNode(tag, attrs) {
      var l = el(tag, attrs);
      l.className = 'mf-label';
      l.appendChild(document.createTextNode(label));
      if (required) {
        var star = el('span', { className: 'mf-required', 'aria-hidden': 'true' }, '*');
        l.appendChild(star);
      }
      return l;
    }

    if (type === 'radio' || (type === 'checkbox' && field.options)) {
      wrap = el('fieldset', { className: 'mf-field mf-field--' + type, 'data-mf-name': field.name });
      wrap.appendChild(labelNode('legend'));
      var box = el('div', { className: 'mf-options' });
      field.options.forEach(function (opt, i) {
        var optLabel = el('label', { className: 'mf-option' });
        var defaults = [].concat(field['default'] != null ? field['default'] : []);
        var inp = el('input', {
          type: type, name: field.name, value: opt.value,
          required: type === 'radio' && required && i === 0,
          checked: defaults.indexOf(opt.value) !== -1
        });
        optLabel.appendChild(inp);
        optLabel.appendChild(el('span', null, pick(opt.label, locale)));
        box.appendChild(optLabel);
      });
      wrap.appendChild(box);
    } else if (type === 'consent' || type === 'checkbox') {
      wrap = el('div', { className: 'mf-field mf-field--' + type, 'data-mf-name': field.name });
      var l = el('label', { className: 'mf-option' });
      input = el('input', { type: 'checkbox', name: field.name, id: id, required: required, checked: field['default'] === true });
      l.appendChild(input);
      var span = el('span', null, label);
      if (required) span.appendChild(el('span', { className: 'mf-required', 'aria-hidden': 'true' }, '*'));
      l.appendChild(span);
      wrap.appendChild(l);
    } else {
      wrap = el('div', { className: 'mf-field mf-field--' + type, 'data-mf-name': field.name });
      wrap.appendChild(labelNode('label', { 'for': id }));
      var common = {
        id: id, name: field.name, className: 'mf-input', required: required,
        placeholder: pick(field.placeholder, locale) || null, autocomplete: field.autocomplete || null
      };
      if (type === 'textarea') {
        common.maxlength = field.maxLength; common.minlength = field.minLength; common.rows = field.rows || 6;
        input = el('textarea', common);
        if (field['default'] != null) input.value = field['default'];
      } else if (type === 'select') {
        input = el('select', common);
        input.appendChild(el('option', { value: '' }, t(locale, 'choose')));
        field.options.forEach(function (opt) {
          input.appendChild(el('option', { value: opt.value, selected: field['default'] === opt.value }, pick(opt.label, locale)));
        });
      } else {
        common.type = type; // text, email, tel, number, date
        if (type === 'number') { common.min = field.min; common.max = field.max; common.step = field.step || 'any'; common.inputmode = 'decimal'; }
        else if (type !== 'date') { common.maxlength = field.maxLength; common.minlength = field.minLength; }
        input = el('input', common);
        if (field['default'] != null) input.value = String(field['default']);
      }
      wrap.appendChild(input);
    }

    if (field.help) {
      var help = el('p', { className: 'mf-help', id: id + '-help' }, pick(field.help, locale));
      wrap.appendChild(help);
      if (input) input.setAttribute('aria-describedby', help.id);
    }
    return wrap;
  }

  function renderForm(spec, locale, ns) {
    var form = el('form', { className: 'mf-form', 'data-mf-form': spec.site + '/' + spec.id });
    spec.fields.forEach(function (field) { form.appendChild(renderField(field, locale, ns)); });
    form.appendChild(el('button', { type: 'submit', className: 'mf-submit' }, pick(spec.submitLabel, locale) || t(locale, 'submit')));
    return form;
  }

  // ------------------------------------------------------------------ behaviour (both modes)
  function collect(form) {
    var data = {}, groups = {};
    Array.prototype.forEach.call(form.elements, function (e) {
      if (!e.name || e.disabled || e.name === HONEYPOT || e.type === 'submit' || e.type === 'button' || e.type === 'file') return;
      if (e.type === 'checkbox') { (groups[e.name] = groups[e.name] || []).push(e); return; }
      if (e.type === 'radio') { if (e.checked) data[e.name] = e.value; else if (!(e.name in data)) data[e.name] = null; return; }
      if (e.type === 'number') { data[e.name] = e.value === '' ? null : (isNaN(e.valueAsNumber) ? e.value : e.valueAsNumber); return; }
      if (e.tagName === 'SELECT' && e.multiple) {
        data[e.name] = Array.prototype.filter.call(e.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
        return;
      }
      data[e.name] = e.value;
    });
    Object.keys(groups).forEach(function (name) {
      var boxes = groups[name];
      var single = boxes.length === 1 && !boxes[0].closest('.mf-options') && (boxes[0].value === 'on' || boxes[0].value === 'true' || boxes[0].value === '1');
      data[name] = single ? boxes[0].checked
        : boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    });
    return data;
  }

  function clearErrors(form) {
    Array.prototype.forEach.call(form.querySelectorAll('.mf-error[data-mf-generated]'), function (n) { n.parentNode.removeChild(n); });
    Array.prototype.forEach.call(form.querySelectorAll('.mf-invalid'), function (n) {
      n.classList.remove('mf-invalid'); n.removeAttribute('aria-invalid');
    });
  }

  function showFieldErrors(form, errors) {
    var first = null;
    Object.keys(errors).forEach(function (name) {
      var inputs = Array.prototype.filter.call(form.elements, function (e) { return e.name === name; });
      if (!inputs.length) return;
      var container = inputs[0].closest('[data-mf-name]') || inputs[0].closest('.mf-field') || null;
      var msg = el('p', { className: 'mf-error', 'data-mf-generated': '1', id: 'mf-err-' + (++uid) }, errors[name]);
      inputs.forEach(function (i) { i.classList.add('mf-invalid'); i.setAttribute('aria-invalid', 'true'); i.setAttribute('aria-errormessage', msg.id); });
      if (container) container.appendChild(msg);
      else {
        // own HTML (mode 2): place the message after the wrapping <label>, not inside it
        var last = inputs[inputs.length - 1];
        (last.closest('label') || last).insertAdjacentElement('afterend', msg);
      }
      if (!first) first = inputs[0];
    });
    if (first && first.focus) first.focus();
  }

  function setup(root, form, opts) {
    var locale = opts.locale, spec = opts.spec;
    var endpoint = opts.endpoint, site = opts.site, formId = opts.form;

    // Honeypot – invisible for humans, tempting for bots
    var hp = el('div', { className: 'mf-hp', 'aria-hidden': 'true' });
    hideVisually(hp);
    var hpLabel = el('label', null, t(locale, 'honeypot'));
    hpLabel.appendChild(el('input', { type: 'text', name: HONEYPOT, tabindex: '-1', autocomplete: 'off' }));
    hp.appendChild(hpLabel);
    form.appendChild(hp);

    var submit = form.querySelector('[type="submit"]');
    var status = el('p', { className: 'mf-status', role: 'status', 'aria-live': 'polite' });
    var altchaNote = el('p', { className: 'mf-altcha' });
    if (submit && submit.parentNode === form) submit.insertAdjacentElement('afterend', status);
    else form.appendChild(status);
    form.appendChild(altchaNote);

    var challenge = null;
    function startChallenge() {
      if (challenge && Date.now() - challenge.startedAt < CHALLENGE_MAX_AGE) return challenge.promise;
      altchaNote.textContent = t(locale, 'protecting');
      var started = Date.now();
      var promise = solveChallenge(endpoint, site).then(function (sol) {
        altchaNote.textContent = t(locale, 'protectedOk');
        return sol;
      }, function (err) {
        altchaNote.textContent = t(locale, 'protectFailed');
        challenge = null;
        throw err;
      });
      challenge = { promise: promise, startedAt: started };
      return promise;
    }
    // Load the challenge only once someone interacts with the form.
    form.addEventListener('focusin', startChallenge, { once: true });
    form.addEventListener('pointerdown', startChallenge, { once: true });

    var sending = false;
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (sending) return;
      if (form.reportValidity && !form.reportValidity()) return;
      sending = true;
      clearErrors(form);
      root.classList.add('mf-sending');
      status.classList.remove('mf-status--error');
      status.textContent = t(locale, 'sending');
      if (submit) submit.disabled = true;

      function done(message, isError) {
        sending = false;
        root.classList.remove('mf-sending');
        if (submit) submit.disabled = false;
        status.textContent = message || '';
        status.classList.toggle('mf-status--error', !!isError);
      }

      startChallenge().then(function (sol) {
        var wait = Math.max(0, sol.fetchedAt + MIN_MS - Date.now());
        return new Promise(function (r) { setTimeout(function () { r(sol); }, wait); });
      }).then(function (sol) {
        challenge = null; // a solution can be used only once
        var body = collect(form);
        body.locale = locale;
        body.altcha = sol.payload;
        body[HONEYPOT] = form.elements[HONEYPOT] ? form.elements[HONEYPOT].value : '';
        if (opts.redirectUrl) body._redirect = opts.redirectUrl;
        return fetch(endpoint + '/v1/' + encodeURIComponent(site) + '/' + encodeURIComponent(formId), {
          method: 'POST', mode: 'cors', credentials: 'omit',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(body)
        }).then(function (res) {
          return res.json().catch(function () { return {}; }).then(function (json) { return { res: res, json: json }; });
        });
      }).then(function (r) {
        var json = r.json || {};
        if (r.res.ok && json.ok) {
          root.dispatchEvent(new CustomEvent('machma:success', { bubbles: true, detail: json }));
          if (json.redirect && !opts.noRedirect) {
            location.href = new URL(json.redirect, location.href).href;
            return;
          }
          sending = false;
          root.classList.remove('mf-sending');
          root.classList.add('mf-sent');
          var msg = el('div', { className: 'mf-success', role: 'status', tabindex: '-1' },
            pick(spec && spec.successMessage, locale) || opts.successText || t(locale, 'success'));
          form.parentNode.replaceChild(msg, form);
          msg.focus();
          return;
        }
        root.dispatchEvent(new CustomEvent('machma:error', { bubbles: true, detail: json }));
        if (json.fields) showFieldErrors(form, json.fields);
        done(json.message || t(locale, 'error'), true);
        startChallenge().catch(function () {}); // fresh challenge for the next try
      }).catch(function () {
        done(t(locale, 'network'), true);
        challenge = null;
      });
    });

    root.classList.remove('mf-loading');
    root.classList.add('mf-ready');
  }

  function init(root) {
    if (root.__machmaForm) return;
    root.__machmaForm = true;
    var ref = (root.getAttribute('data-machma-form') || '').split('/');
    if (ref.length !== 2) return;
    var htmlLang = (document.documentElement.getAttribute('lang') || '').slice(0, 2).toLowerCase();
    var opts = {
      site: ref[0], form: ref[1],
      locale: (root.getAttribute('data-locale') || htmlLang || 'de').slice(0, 2).toLowerCase(),
      endpoint: (root.getAttribute('data-endpoint') || DEFAULT_ENDPOINT).replace(/\/+$/, ''),
      redirectUrl: root.getAttribute('data-redirect-url') || null,
      successText: root.getAttribute('data-success-message') || null,
      noRedirect: root.hasAttribute('data-no-redirect')
    };
    root.classList.add('mf-root', 'mf-loading');
    injectStyles();

    if (root.tagName === 'FORM') { // mode 2: take over existing HTML
      setup(root, root, opts);
      return;
    }

    // mode 1: render from /spec
    fetch(opts.endpoint + '/v1/' + encodeURIComponent(opts.site) + '/' + encodeURIComponent(opts.form) + '/spec', { credentials: 'omit', mode: 'cors' })
      .then(function (r) { if (!r.ok) throw new Error('spec ' + r.status); return r.json(); })
      .then(function (spec) {
        if (spec.locales && spec.locales.indexOf(opts.locale) === -1) opts.locale = spec.locales[0];
        opts.spec = spec;
        var form = renderForm(spec, opts.locale, 'mf-' + opts.site + '-' + opts.form);
        while (root.firstChild) root.removeChild(root.firstChild);
        root.appendChild(form);
        setup(root, form, opts);
      })
      .catch(function () {
        root.classList.remove('mf-loading');
        root.classList.add('mf-failed');
        while (root.firstChild) root.removeChild(root.firstChild);
        root.appendChild(el('p', { className: 'mf-status mf-status--error', role: 'alert' }, t(opts.locale, 'load')));
      });
  }

  function initAll(scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll('[data-machma-form]'), init);
  }

  window.machmaForms = { version: VERSION, init: initAll, _sha256: sha256hex };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initAll(); });
  else initAll();
  // Astro View Transitions & similar client-side navigation
  document.addEventListener('astro:page-load', function () { initAll(); });
})();
