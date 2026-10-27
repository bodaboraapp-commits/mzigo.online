/* Mzigo.online — interaction layer.
   Carrier/destination pickers, send + errand wizards, scan-based tracking
   timeline, partner filters, dashboards and phone/PIN auth, backed by the
   Mzigo.online API. Data model follows the parcel-network design doc:
   Locker IDs, QR labels, fee quotes, pay-at-collection, tier A/B. */
(function () {
  'use strict';

  var D = window.MZIGO_DATA;
  if (!D) return;

  var doc = document;
  var root = doc.documentElement;

  /* ------------------------------------------------------------------ utils */
  function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function kes(n) { return 'KES ' + Number(n || 0).toLocaleString('en-KE'); }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmtDate(iso) {
    if (!iso) return '-';
    var d = new Date(iso);
    if (isNaN(d)) return iso;
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function initials(name) {
    var words = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return 'MZ';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return words.slice(0, 3).map(function (w) { return w[0]; }).join('').toUpperCase();
  }
  function avatar(name, size) {
    return '<span class="mz-avatar mz-avatar--' + (size || 'md') + '">' + esc(initials(name)) + '</span>';
  }
  function randomChars(n, alphabet) {
    var a = alphabet || 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var out = '';
    for (var i = 0; i < n; i++) out += a[Math.floor(Math.random() * a.length)];
    return out;
  }
  function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
  function nowISO() { return new Date().toISOString(); }
  function qs(name) {
    var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
    return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
  }
  function safeNext(fallback) {
    var next = qs('next');
    if (!next) return fallback;
    try {
      if (next.charAt(0) !== '/' || next.charAt(1) === '/' || next.charAt(1) === '\\') return fallback;
      var url = new URL(next, window.location.origin);
      if (url.origin !== window.location.origin) return fallback;
      return url.pathname + url.search + url.hash;
    } catch (e) {
      return fallback;
    }
  }

  /* ------------------------------------------------------------------ icons */
  var ICON = {
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>',
    pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>',
    arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>',
    back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>',
    chevron: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>',
    minus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="26" height="26"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>',
    tick: '<svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path fill-rule="evenodd" d="M19.916 4.626a.75.75 0 0 1 .208 1.04l-8.5 12a.75.75 0 0 1-1.15.104l-4.5-4.5a.75.75 0 1 1 1.06-1.06l3.888 3.887 7.954-11.23a.75.75 0 0 1 1.04-.208Z" clip-rule="evenodd"/></svg>',
    cube: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
    route: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>',
    truck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>',
    phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>',
    signout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>'
  };

  /* ------------------------------------------------------- API + app state */
  var pendingApiKey = '';
  var teamCredential = null;
  var pendingWebhookSecret = null;
  var APP = {
    user: null, tenant: null, branding: null, tenants: [], role: null, usage: null, csrf: '', showDemoAccounts: true,
    partners: [], packageSizes: [], paymentMethods: [],
    packages: [], errands: [], senders: [], demos: [],
    pricingMode: 'paid',
    ready: false, source: 'offline', database: 'mzigo_online'
  };

  /** Fetch a JSON endpoint; throws Error with .fields/.status on failure. */
  function api(path, opts) {
    opts = opts || {};
    var cfg = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (opts.method) cfg.method = opts.method;
    if (opts.body !== undefined) {
      cfg.headers['Content-Type'] = 'application/json';
      cfg.body = JSON.stringify(opts.body);
    }
    if (opts.method === 'POST' && APP.csrf) {
      cfg.headers['X-CSRF-Token'] = APP.csrf;
    }
    return fetch('/api/' + path, cfg).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, error: { message: 'Unexpected response from the server (' + res.status + ').' } };
      }).then(function (data) {
        if (data && data.csrf) APP.csrf = data.csrf;
        if (!res.ok || data.ok === false) {
          var err = new Error((data.error && data.error.message) || 'Request failed (' + res.status + ').');
          err.fields = (data.error && data.error.fields) || {};
          err.code = data.error && data.error.code;
          err.meta = data.error && data.error.meta;
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  /** Load MySQL-backed state (user, catalog, parcels, errands, senders). */
  function hydrate() {
    return api('bootstrap.php').then(function (b) {
      APP.user = b.user || null;
      APP.tenant = b.tenant || null;
      APP.branding = b.branding || null;
      APP.tenants = b.tenants || [];
      APP.role = b.role || null;
      APP.usage = b.usage || null;
      APP.csrf = b.csrf || APP.csrf;
      APP.partners = b.partners || [];
      APP.packageSizes = b.packageSizes || [];
      APP.paymentMethods = b.paymentMethods || [];
      APP.packages = b.packages || [];
      APP.errands = b.errands || [];
      APP.senders = b.senders || [];
      APP.demos = b.demos || [];
      APP.database = b.database || 'mzigo_online';
      APP.pricingMode = b.pricing_mode || 'paid';
      APP.showDemoAccounts = b.show_demo_accounts !== false;
      APP.source = b.source || 'mysql';
      APP.ready = true;
      root.setAttribute('data-mz-source', APP.source);
      root.setAttribute('data-mz-ready', '1');
    }).catch(function (err) {
      console.error('[mzigo] live database unreachable:', err.message);
      APP.user = null;
      APP.tenant = null;
      APP.usage = null;
      APP.partners = [];
      APP.packageSizes = [];
      APP.paymentMethods = [];
      APP.packages = [];
      APP.errands = [];
      APP.senders = [];
      APP.demos = [];
      APP.source = 'offline';
      APP.ready = false;
      root.setAttribute('data-mz-source', 'offline');
      root.setAttribute('data-mz-ready', '0');
      var banner = doc.getElementById('mz-db-offline');
      if (!banner) {
        banner = doc.createElement('div');
        banner.id = 'mz-db-offline';
        banner.className = 'mz-alert';
        banner.style.cssText = 'position:fixed;left:16px;right:16px;bottom:16px;z-index:2000;margin:0;background:#fef3c7;border-color:#f0d68a;';
        banner.innerHTML = '<strong>Live database unavailable.</strong> Mzigo.online only displays records from the live database, so this page cannot load catalog or workspace data until the connection is restored.';
        doc.body.appendChild(banner);
      }
    });
  }

  function refreshData() {
    return hydrate().then(function () {
      initAuthUI();
      if (doc.body.getAttribute('data-page') === 'profile') renderProfile();
    });
  }

  function signOut() {
    return api('auth.php', { method: 'POST', body: { action: 'logout' } })
      .catch(function () { /* ignore */ })
      .then(function () {
        APP.user = null;
        APP.packages = [];
        APP.errands = [];
        APP.senders = [];
        toast('Signed out.');
        return refreshData();
      });
  }

  function packages() { return APP.packages; }
  function errands() { return APP.errands; }
  function user() { return APP.user; }
  function savedSenders() { return APP.senders; }

  /** Union of every carrier's destinations (backed by the MySQL catalog). */
  function allDestinations() {
    var seen = {}, out = [];
    APP.partners.forEach(function (partner) {
      (partner.destinations || []).forEach(function (name) {
        if (!seen[name]) { seen[name] = 1; out.push(name); }
      });
    });
    return out.sort(function (a, b) { return a.localeCompare(b); });
  }

  /* ------------------------------------------------------------------ toast */
  var toastWrap = null;
  function toast(message, kind) {
    if (!toastWrap) {
      toastWrap = doc.createElement('div');
      toastWrap.className = 'mz-toast-wrap';
      doc.body.appendChild(toastWrap);
    }
    var t = doc.createElement('div');
    t.className = 'mz-toast' + (kind ? ' mz-toast--' + kind : '');
    t.setAttribute('role', 'status');
    t.innerHTML = '<span class="mz-toast__icon">' + (kind === 'error' ? ICON.info : ICON.info) + '</span>' +
      '<span>' + esc(message) + '</span>' +
      '<button type="button" class="mz-toast__close" aria-label="Dismiss">' + ICON.x + '</button>';
    t.querySelector('.mz-toast__close').addEventListener('click', function () { t.remove(); });
    toastWrap.appendChild(t);
    window.setTimeout(function () {
      t.style.transition = 'opacity .2s, transform .2s';
      t.style.opacity = '0';
      t.style.transform = 'translateY(6px)';
      window.setTimeout(function () { t.remove(); }, 220);
    }, kind === 'error' ? 6000 : 4200);
  }

  /* -------------------------------------------------------------- analytics */
  function trackEvent(name, meta) {
    try {
      meta = meta || {};
      var role = APP.user
        ? (APP.user.role === 'freelancer' ? 'freelancer' : APP.user.role === 'customer' ? 'customer' : 'merchant')
        : 'guest';
      var payload = {
        event: name,
        path: location.pathname + location.search + location.hash,
        label: meta.label || null,
        location: meta.location || null,
        audience: meta.audience || role,
        meta: meta.meta || {}
      };
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon('/api/events.php', blob)) return;
      }
      fetch('/api/events.php', {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/json' }, body: body
      }).catch(function () {});
    } catch (e) { /* analytics never blocks the UI */ }
  }

  function initAnalytics() {
    var params = new URLSearchParams(location.search);
    var ref = params.get('ref');
    var refSource = params.get('src') || '';
    if (ref && location.pathname !== '/partner-poster' && location.pathname !== '/refer-card') {
      try {
        document.cookie = 'mz_ref=' + encodeURIComponent(ref) + '; path=/; max-age=' + (60 * 60 * 24 * 60) + '; SameSite=Lax';
        localStorage.setItem('mz_ref', ref);
        if (refSource) {
          localStorage.setItem('mz_ref_src', refSource);
          document.cookie = 'mz_ref_src=' + encodeURIComponent(refSource) + '; path=/; max-age=' + (60 * 60 * 24 * 60) + '; SameSite=Lax';
        }
      } catch (e) {}
      trackEvent('referral_click', { label: ref, meta: { source: refSource } });
      fetch('/api/bootstrap.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (b) {
        return fetch('/api/growth.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': b.csrf }, body: JSON.stringify({ action: 'referral_click', code: ref, source: refSource }) });
      }).catch(function () {});
    }
    var path = location.pathname;
    if (path === '/earn' || path === '/earn.html') {
      trackEvent('earn_page_view', { label: 'Earn page', location: 'Earn with Mzigo' });
    }
    trackEvent('page_view', { label: document.title, location: document.title });
    doc.addEventListener('click', function (ev) {
      var el = ev.target.closest('a.mz-btn, button.mz-btn, a.mz-link, button.mz-tab, a.mz-tab, [data-cta]');
      if (!el) return;
      var label = (el.getAttribute('data-cta-label') || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 90);
      if (!label) return;
      var scope = el.closest('[data-cta-audience]');
      var section = el.closest('[data-mz-audience-band], section, footer, header');
      var heading = section ? section.querySelector('h1, h2, h3') : null;
      var locationLabel = el.getAttribute('data-cta-location') || (heading ? heading.textContent.replace(/\s+/g, ' ').trim().slice(0, 90) : document.title);
      var audience = el.getAttribute('data-cta-audience') ||
        (scope ? scope.getAttribute('data-cta-audience') : null) ||
        (APP.user ? (APP.user.role === 'freelancer' ? 'freelancer' : APP.user.role === 'customer' ? 'customer' : 'merchant') : 'guest');
      trackEvent('cta_click', {
        label: label, location: locationLabel, audience: audience,
        meta: { href: el.getAttribute('href') || null, cta_variant: el.getAttribute('data-cta-variant') || null }
      });
      var href = el.getAttribute('href') || '';
      if (href.indexOf('/login?role=customer') !== -1) {
        trackEvent('receiver_signin', { label: label, location: locationLabel });
      }
      if (/contact|report|support/i.test(label) && href.indexOf('/contact') !== -1) {
        trackEvent('support_click', { label: label, location: locationLabel });
      }
    });
  }

  /* ----------------------------------------------------------------- dialog */
  /**
   * Open a modal dialog.
   * cfg: { title, sub, body (HTML for the form fields), submitLabel, onSubmit(form) -> Promise }
   * onSubmit errors are shown inside the dialog; resolving closes it.
   */
  function openDialog(cfg) {
    var lastFocus = doc.activeElement;
    var previousOverflow = doc.body.style.overflow;
    var overlay = doc.createElement('div');
    overlay.className = 'mz-overlay';
    overlay.innerHTML =
      '<div class="mz-dialog" role="dialog" aria-modal="true" aria-labelledby="mz-dialog-title">' +
        '<div class="mz-dialog__head">' +
          '<div class="mz-dialog__title" id="mz-dialog-title">' + esc(cfg.title) + '</div>' +
          (cfg.hideClose ? '' : '<button type="button" class="mz-dialog__close" data-dialog-close aria-label="Close dialog">' + ICON.x + '</button>') +
        '</div>' +
        (cfg.sub ? '<p class="mz-dialog__sub">' + esc(cfg.sub) + '</p>' : '') +
        '<form class="mz-dialog__form" novalidate>' +
          '<div class="mz-dialog__body">' + cfg.body + '</div>' +
          '<div class="mz-dialog__error" data-dialog-error hidden></div>' +
          '<div class="mz-dialog__actions">' +
            '<button type="button" class="mz-btn mz-btn--outline" data-dialog-close>' + esc(cfg.cancelLabel || 'Cancel') + '</button>' +
            '<button type="submit" class="mz-btn" data-dialog-submit>' + esc(cfg.submitLabel || 'Save') + '</button>' +
          '</div>' +
        '</form>' +
      '</div>';

    var form = overlay.querySelector('form');
    var submitBtn = overlay.querySelector('[data-dialog-submit]');
    var errorBox = overlay.querySelector('[data-dialog-error]');
    var closed = false;
    var completed = false;
    if (cfg.wide) overlay.querySelector('.mz-dialog').classList.add('mz-dialog--wide');

    function close() {
      if (closed) return;
      closed = true;
      doc.removeEventListener('keydown', onKey);
      doc.body.style.overflow = previousOverflow;
      overlay.remove();
      if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
      if (!completed && typeof cfg.onDismiss === 'function') {
        try { cfg.onDismiss(); } catch (e) {}
      }
    }
    function onKey(ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); close(); }
    }
    function showError(message) {
      errorBox.textContent = message || 'Could not save. Please try again.';
      errorBox.hidden = false;
    }

    overlay.addEventListener('click', function (ev) {
      if (ev.target === overlay || (ev.target.closest && ev.target.closest('[data-dialog-close]'))) close();
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (submitBtn.disabled) return;
      errorBox.hidden = true;
      submitBtn.disabled = true;
      var result;
      try {
        result = cfg.onSubmit(form);
      } catch (err) {
        result = Promise.reject(err);
      }
      Promise.resolve(result).then(function () {
        completed = true;
        close();
      }).catch(function (err) {
        showError(err && err.message);
        submitBtn.disabled = false;
      });
    });

    doc.body.appendChild(overlay);
    doc.body.style.overflow = 'hidden';
    doc.addEventListener('keydown', onKey);
    var first = overlay.querySelector('input:not([type="hidden"]):not([readonly]), textarea, select');
    if (first) {
      first.focus();
      if (first.select && first.value) first.select();
    }
    return { close: close, overlay: overlay };
  }

  /* ------------------------------------------------------- searchable select */
  function createSelect(cfg) {
    var wrap = doc.createElement('div');
    wrap.className = 'mz-select' + (cfg.disabled ? ' is-disabled' : '');
    var labelHtml = cfg.label ? '<label class="mz-label" style="display:block;margin-bottom:6px;">' + esc(cfg.label) + (cfg.required ? '<span class="mz-req"> *</span>' : '') + '</label>' : '';
    wrap.innerHTML =
      '<div style="position:relative;min-width:0;">' +
        labelHtml +
        '<div class="mz-select__control">' +
          '<span class="mz-select__icon">' + (cfg.icon === 'pin' ? ICON.pin : cfg.icon === 'search' ? ICON.search : '') + '</span>' +
          '<input type="text" autocomplete="off" role="combobox" aria-expanded="false" placeholder="' + esc(cfg.placeholder || '') + '"' +
            (cfg.disabled ? ' disabled' : '') + ' value="' + esc(cfg.value || '') + '" aria-label="' + esc(cfg.label || cfg.placeholder || 'Select') + '">' +
        '</div>' +
        '<div class="mz-select__list" role="listbox" hidden></div>' +
      '</div>';
    var input = wrap.querySelector('input');
    var list = wrap.querySelector('.mz-select__list');
    var items = cfg.items ? cfg.items.slice() : [];
    var selected = null;
    var activeIndex = -1;

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      activeIndex = -1;
    }
    function open() {
      if (wrap.classList.contains('is-disabled')) return;
      render(input.value);
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function optionHTML(item, i) {
      var label = typeof item === 'string' ? item : item.name;
      var meta = cfg.meta && typeof item === 'object' ? cfg.meta(item) : '';
      return '<button type="button" class="mz-option" role="option" data-index="' + i + '">' +
        (cfg.avatar ? avatar(label, 'sm') : '') +
        '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(label) + '</span>' +
        (meta ? '<span class="mz-option__meta">' + esc(meta) + '</span>' : '') +
        '</button>';
    }
    function render(filter) {
      var q = String(filter || '').trim().toLowerCase();
      var match = items.filter(function (it) {
        var name = typeof it === 'string' ? it : it.name;
        return !q || name.toLowerCase().indexOf(q) !== -1;
      });
      if (!match.length) {
        list.innerHTML = '<div class="mz-option mz-option--empty">No matches for "' + esc(filter) + '".</div>';
        return;
      }
      list.innerHTML = match.map(function (it) {
        var idx = items.indexOf(it);
        return optionHTML(it, idx);
      }).join('');
      Array.prototype.forEach.call(list.querySelectorAll('.mz-option[role="option"]'), function (btn) {
        btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        btn.addEventListener('click', function () { pick(items[Number(btn.getAttribute('data-index'))]); });
      });
    }
    function pick(item) {
      selected = item;
      var label = typeof item === 'string' ? item : item.name;
      input.value = label;
      close();
      if (cfg.onChange) cfg.onChange(item);
    }
    function reset() { selected = null; input.value = ''; }

    input.addEventListener('focus', function () { if (!input.disabled) { render(''); open(); } });
    input.addEventListener('click', function () { if (list.hidden) open(); });
    input.addEventListener('input', function () {
      selected = null;
      if (cfg.onChange) cfg.onChange(null, input.value);
      render(input.value);
      if (list.hidden) open();
    });
    input.addEventListener('keydown', function (ev) {
      var opts = Array.prototype.slice.call(list.querySelectorAll('.mz-option[role="option"]'));
      if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        if (list.hidden) open();
        activeIndex = Math.min(activeIndex + 1, opts.length - 1);
      } else if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
      } else if (ev.key === 'Enter') {
        if (!list.hidden && opts[activeIndex]) { ev.preventDefault(); opts[activeIndex].click(); }
      } else if (ev.key === 'Escape') { close(); }
      opts.forEach(function (o, i) { o.classList.toggle('is-active', i === activeIndex); });
    });
    doc.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) close(); });

    if (cfg.mount) {
      cfg.mount.innerHTML = '';
      cfg.mount.appendChild(wrap);
    }

    return {
      el: wrap,
      value: function () { return selected ? (typeof selected === 'string' ? selected : selected.name) : (input.value.trim() || null); },
      item: function () { return selected; },
      setItems: function (next, keepValue) {
        items = (next || []).slice();
        render('');
        if (!keepValue) { if (selected && items.indexOf(selected) === -1) { reset(); if (cfg.onChange) cfg.onChange(null); } }
      },
      set: function (val) {
        var found = null;
        items.forEach(function (it) { if ((typeof it === 'string' ? it : it.name) === val) found = it; });
        if (found) { selected = found; input.value = typeof found === 'string' ? found : found.name; }
        else if (val) { selected = null; input.value = val; }
        else { reset(); }
      },
      reset: reset,
      enable: function (on) {
        wrap.classList.toggle('is-disabled', !on);
        input.disabled = !on;
        if (!on) reset();
      },
      input: input
    };
  }

  /* --------------------------------------------------------- fee + statuses */
  var SIZE_BASE = {
    'Envelope': 120, 'Small': 180, 'Medium': 250, 'Large': 330,
    'Extra Large': 420, 'Extra Extra Large': 520, 'Extra Extra Extra Large': 650
  };
  function quote(cfg) {
    var sizeBase = SIZE_BASE[cfg.size] || 250;
    var base = Math.round((sizeBase + 180) / 10) * 10;
    var holding = 30;
    var insurance = Math.max(30, Math.round(((cfg.value || 0) * 0.015) / 10) * 10);
    var platform = 80;
    var total = Math.ceil((base + holding + insurance + platform) / 10) * 10;
    return { base: base, holding: holding, insurance: insurance, platform: platform, total: total };
  }
  var STATUS_META = {
    CREATED: { label: 'Registered', pill: '' },
    PICKUP_REQUESTED: { label: 'Pickup requested', pill: '' },
    AT_ORIGIN_POINT: { label: 'Dropped off', pill: '' },
    HANDED_TO_TRANSPORTER: { label: 'Handed to transporter', pill: 'transit' },
    IN_TRANSIT: { label: 'In transit', pill: 'transit' },
    ARRIVED_DEST_POINT: { label: 'Arrived at destination', pill: 'transit' },
    READY_FOR_COLLECTION: { label: 'Ready for collection', pill: 'ready' },
    CANCELLED: { label: 'Cancelled', pill: '' },
    COLLECTED_PAID: { label: 'Collected & paid', pill: 'done' },
    REFUSED: { label: 'Refused', pill: '' },
    EXPIRED: { label: 'Holding expired', pill: '' },
    RETURN_IN_TRANSIT: { label: 'Return in transit', pill: 'transit' },
    RETURNED: { label: 'Returned', pill: 'done' }
  };
  var PROGRESS = ['CREATED', 'AT_ORIGIN_POINT', 'HANDED_TO_TRANSPORTER', 'IN_TRANSIT', 'ARRIVED_DEST_POINT', 'READY_FOR_COLLECTION', 'COLLECTED_PAID'];

  function allTrackables() {
    var list = [];
    packages().forEach(function (p) { list.push({ kind: 'parcel', rec: p }); });
    errands().forEach(function (e) { list.push({ kind: 'errand', rec: e }); });
    (APP.demos || []).forEach(function (d) { list.push({ kind: 'parcel', rec: d, demo: true }); });
    return list;
  }

  /** Ask MySQL for a parcel/errand by tracking number, Locker ID or errand ref. */
  function trackLookup(term) {
    return api('track.php?q=' + encodeURIComponent(term));
  }

  /* ------------------------------------------------------------------ header */
  function money(n) { return 'KES ' + Number(n || 0).toLocaleString('en-KE'); }

  function appLinks() {
    if (!APP.user) return [['/send-mzigo', 'Send'], ['/track-mzigo', 'Track'], ['/earn', 'Earn'], ['/partners', 'Partners'], ['/help', 'Help']];
    if (APP.user.role === 'platform_admin') return [['/admin', 'Admin']];
    if (APP.user.role === 'freelancer') return [['/freelancer', 'My work']];
    if (APP.user.role === 'customer') return [['/customer', 'My parcels']];
    var links = [['/profile', 'Dashboard'], ['/orders', 'Orders'], ['/claims', 'Claims'], ['/billing', 'Billing'], ['/settings', 'Settings']];
    if (APP.user.role === 'platform_admin') links.push(['/admin', 'Admin']);
    return links;
  }

  function applyTenantBranding() {
    var b = APP.branding;
    if (!b) return;
    if (b.primary_color) document.documentElement.style.setProperty('--mz-hunter', b.primary_color);
    if (b.secondary_color) document.documentElement.style.setProperty('--mz-muted-fg', b.secondary_color);
  }

  function initAppNav() {
    var host = $('[data-mz-appnav]');
    if (!host || !APP.user) return;
    var links = appLinks();
    if (links.length < 2) { host.innerHTML = ''; return; }
    var path = window.location.pathname;
    host.innerHTML = links.map(function (link) {
      var active = path === link[0] ? ' is-active' : '';
      return '<a class="mz-tab' + active + '" href="' + link[0] + '">' + link[1] + '</a>';
    }).join('');
  }

  function initAuthUI() {
    var loggedIn = !!APP.user;
    applyTenantBranding();
    $$('[data-mz-auth]').forEach(function (el) { el.style.display = loggedIn ? 'none' : ''; });
    var nav = $('[data-mz-nav]');
    if (nav) {
      var path = window.location.pathname;
      nav.innerHTML = appLinks().map(function (link) {
        var active = path === link[0] ? ' style="background:rgba(184,237,191,.6);"' : '';
        return '<a class="mz-navlink" href="' + link[0] + '"' + active + '>' + link[1] + '</a>';
      }).join('');
    }
    var sendCta = $('[data-mz-send-cta]');
    if (sendCta && !loggedIn) {
      var variant = '';
      try { variant = localStorage.getItem('mz_cta_variant2') || ''; } catch (e) {}
      if (variant !== 'send' && variant !== 'mzigo') {
        variant = Math.random() < 0.5 ? 'send' : 'mzigo';
        try { localStorage.setItem('mz_cta_variant2', variant); } catch (e) {}
      }
      sendCta.setAttribute('data-cta-variant', variant);
      sendCta.textContent = variant === 'mzigo' ? 'Send Mzigo' : 'Send a parcel';
      sendCta.setAttribute('href', '/send-mzigo');
    }
    if (sendCta && loggedIn) {
      if (APP.user.role === 'freelancer') {
        sendCta.textContent = 'My jobs';
        sendCta.setAttribute('href', '/freelancer');
      } else if (APP.user.role === 'customer') {
        sendCta.textContent = 'My parcels';
        sendCta.setAttribute('href', '/customer');
      }
    }
    initMobileNav();
    var slot = $('[data-mz-account]');
    if (!slot) return;
    if (loggedIn) {
      var label = APP.tenant ? APP.tenant.name : (APP.user.name || APP.user.email);
      var chipLogo = APP.branding && APP.branding.logo_url
        ? '<img class="mz-tenant-logo" src="' + esc(APP.branding.logo_url) + '" alt="' + esc(label) + '">'
        : avatar(label, 'sm');
      var role = APP.user.role;
      var dashHref = role === 'freelancer' ? '/freelancer' : role === 'customer' ? '/customer' : role === 'admin' ? '/admin' : '/profile';
      var dashLabel = role === 'freelancer' ? 'My jobs' : role === 'customer' ? 'My parcels' : role === 'admin' ? 'Admin console' : 'Workspace dashboard';
      var menuLinks = [
        [dashHref, dashLabel],
        ['/refer', 'Refer &amp; earn'],
        ['/help', 'Help centre']
      ];
      if (role !== 'freelancer' && role !== 'customer' && role !== 'admin') menuLinks.push(['/billing', 'Billing & invoices']);
      if (role !== 'freelancer' && role !== 'customer') menuLinks.push(['/settings', 'Settings']);
      slot.innerHTML =
        '<div class="mz-account-wrap">' +
          '<button type="button" class="mz-tenant-chip" data-account-toggle aria-haspopup="true" aria-expanded="false" title="' + esc(label) + '">' +
            chipLogo + '<span class="mz-tenant-chip__name">' + esc(label) + '</span>' + ICON.chevron +
          '</button>' +
          '<div class="mz-account-menu" data-account-menu hidden>' +
            menuLinks.map(function (pair) { return '<a href="' + pair[0] + '">' + pair[1] + '</a>'; }).join('') +
            '<button type="button" class="mz-account-menu__signout" data-mz-signout>Sign out</button>' +
          '</div>' +
        '</div>';
      slot.style.display = 'flex';
      var toggle = slot.querySelector('[data-account-toggle]');
      var menu = slot.querySelector('[data-account-menu]');
      function closeAccountMenu() {
        if (!menu) return;
        menu.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      }
      if (toggle && menu) {
        toggle.addEventListener('click', function (ev) {
          ev.stopPropagation();
          menu.hidden = !menu.hidden;
          toggle.setAttribute('aria-expanded', String(!menu.hidden));
        });
        document.addEventListener('click', function (ev) {
          if (!menu.hidden && !menu.contains(ev.target) && ev.target !== toggle) closeAccountMenu();
        });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeAccountMenu(); });
        menu.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeAccountMenu); });
      }
      slot.querySelector('[data-mz-signout]').addEventListener('click', function () {
        signOut().then(function () { window.location.reload(); });
      });
    } else {
      slot.innerHTML = '';
      slot.style.display = 'none';
    }
    $$('header').forEach(function (header) {
      if (header.className.indexOf('md:hidden') === -1) return;
      var profileLink = header.querySelector('a[aria-label="Profile"]');
      if (profileLink) {
        profileLink.setAttribute('href', !loggedIn ? '/login'
          : APP.user.role === 'freelancer' ? '/freelancer'
          : APP.user.role === 'customer' ? '/customer' : '/profile');
      }
    });
  }

  function initMobileNav() {
    var nav = document.querySelector('nav[data-show-bottom-nav]');
    if (!nav) return;
    var track = nav.querySelector('div');
    if (!track) return;
    var anchors = track.querySelectorAll('a');
    if (!anchors.length) return;
    var activeCls = anchors[0].className;
    var idleCls = anchors[1] ? anchors[1].className : anchors[0].className;
    var icons = Array.prototype.map.call(anchors, function (a) {
      var svg = a.querySelector('svg');
      return svg ? svg.outerHTML : '';
    });
    var role = APP.user ? APP.user.role : 'guest';
    var items;
    if (role === 'freelancer') {
      items = [
        ['/freelancer', 'Jobs', icons[1] || icons[0]],
        ['/freelancer#earnings', 'Earnings', icons[0]],
        ['/track-mzigo', 'Track', icons[2]],
        ['/freelancer#runner-profile', 'Profile', icons[3]]
      ];
    } else if (role === 'customer') {
      items = [
        ['/customer', 'My parcels', icons[0]],
        ['/track-mzigo', 'Track', icons[2]],
        ['/customer#pay', 'Pay', icons[1]],
        ['/contact', 'Support', icons[3]]
      ];
    } else if (APP.tenant) {
      items = [
        ['/profile', 'Dashboard', icons[0]],
        ['/send-mzigo', 'Send', icons[1]],
        ['/orders', 'Orders', icons[2]],
        ['/settings', 'Account', icons[3]]
      ];
    } else {
      items = [
        ['/', 'Home', icons[0]],
        ['/send-mzigo', 'Send', icons[1]],
        ['/track-mzigo', 'Track', icons[2]],
        ['/earn', 'Earn', (typeof ICON !== 'undefined' && ICON.route) ? ICON.route : icons[3]]
      ];
    }
    var current = location.pathname + location.hash;
    var hasHashMatch = items.some(function (item) {
      return item[0].indexOf('#') !== -1 && current === item[0];
    });
    track.innerHTML = items.map(function (item) {
      var base = item[0].split('#')[0];
      var active;
      if (item[0].indexOf('#') !== -1) {
        active = current === item[0];
      } else {
        active = !hasHashMatch && (base === '/' ? current === '/' : current.indexOf(base) === 0);
      }
      return '<a class="' + (active ? activeCls : idleCls) + '" aria-label="' + item[1] + '" href="' + item[0] + '"' +
        (active ? ' aria-current="page"' : '') + '>' + item[2] + '</a>';
    }).join('');
  }

  function initHeaderSearch() {
    $$('[data-mz-header-search]').forEach(function (input) {
      input.placeholder = 'Search courier or tracking number';
      var host = input.parentElement;
      var group = input.closest ? input.closest('.mz-header-search-group') : null;
      var panel = doc.createElement('div');
      panel.className = 'mz-suggest';
      panel.hidden = true;
      host.appendChild(panel);

      function closeSearch() {
        panel.hidden = true;
        if (group) group.classList.remove('is-open');
        var toggle = group ? group.querySelector('.mz-header-search-toggle') : null;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      }

      if (group && !group.querySelector('.mz-header-search-toggle')) {
        var toggle = doc.createElement('button');
        toggle.type = 'button';
        toggle.className = 'mz-header-search-toggle';
        toggle.setAttribute('aria-label', 'Search couriers or tracking number');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>';
        group.insertBefore(toggle, group.firstChild);
        toggle.addEventListener('click', function (ev) {
          ev.stopPropagation();
          var open = group.classList.toggle('is-open');
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          panel.hidden = true;
          if (open) setTimeout(function () { input.focus(); }, 0);
        });
      }

      function render(q) {
        var raw = String(q || '').trim();
        var query = raw.toLowerCase();
        if (/^(TRK|LK|ERD)-[A-Z0-9-]{3,}$/i.test(raw)) {
          panel.innerHTML = '<button type="button" class="mz-option" data-track-query="' + esc(raw.toUpperCase()) + '">' +
            '<span style="font-weight:700;">Track ' + esc(raw.toUpperCase()) + '</span><span class="mz-option__meta">Track parcel</span></button>';
          var trackBtn = panel.querySelector('[data-track-query]');
          if (trackBtn) trackBtn.addEventListener('click', function () {
            window.location.href = '/track-mzigo?num=' + encodeURIComponent(trackBtn.getAttribute('data-track-query'));
          });
          return;
        }
        var matches = APP.partners.filter(function (p) { return !query || p.name.toLowerCase().indexOf(query) !== -1; });
        panel.innerHTML = matches.length
          ? matches.map(function (p) {
              return '<button type="button" class="mz-option" data-slug="' + p.slug + '">' + avatar(p.name, 'sm') +
                '<span>' + esc(p.name) + '</span><span class="mz-option__meta">Send</span></button>';
            }).join('')
          : '<div class="mz-option mz-option--empty">No courier matches. Paste a tracking number to track a parcel.</div>';
        Array.prototype.forEach.call(panel.querySelectorAll('[data-slug]'), function (btn) {
          btn.addEventListener('click', function () { window.location.href = '/send-mzigo/' + btn.getAttribute('data-slug'); });
        });
      }
      input.addEventListener('focus', function () { render(input.value); panel.hidden = false; });
      input.addEventListener('input', function () { render(input.value); panel.hidden = false; });
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          var track = panel.querySelector('[data-track-query]');
          if (track) {
            window.location.href = '/track-mzigo?num=' + encodeURIComponent(track.getAttribute('data-track-query'));
            return;
          }
          var first = panel.querySelector('[data-slug]');
          if (first) first.click(); else window.location.href = '/partners';
        }
        if (ev.key === 'Escape') closeSearch();
      });
      doc.addEventListener('click', function (ev) {
        if (host.contains(ev.target)) return;
        if (group && group.contains(ev.target)) return;
        closeSearch();
      });
      if (group) {
        window.addEventListener('resize', function () {
          if (window.innerWidth >= 1024) closeSearch();
        });
      }
    });
  }

  /* --------------------------------------------------------------- home page */
  function initHome() {
    var forms = $$('[data-mz-home-form]');
    if (!forms.length) return;

    forms.forEach(function (form) {
      var mode = 'mzigo';
      var hint = form.querySelector('[data-mz-hint]');
      var tabBtns = $$('[data-mz-tab]', form);
      var continueBtn = form.querySelector('[data-mz-continue]');
      var carrierMount = form.querySelector('[data-mz-mount="carrier"]');
      var fromMount = form.querySelector('[data-mz-mount="from"]');
      var toMount = form.querySelector('[data-mz-mount="to"]');
      var landmarks = allDestinations().concat(['CBD, Nairobi', 'Westlands, Nairobi', 'Kilimani, Nairobi', 'Karen, Nairobi', 'Industrial Area, Nairobi']);

      var carrier = createSelect({
        mount: carrierMount,
        items: APP.partners,
        label: 'Courier',
        required: true,
        placeholder: 'Search for a courier',
        icon: 'search',
        avatar: true,
        meta: function () { return 'Select'; },
        onChange: function (item) {
          if (item && item.name) {
            fromSel.setItems(item.destinations.concat([]));
            toSel.setItems(item.destinations.concat([]));
            fromSel.enable(true);
            toSel.enable(true);
            hint.textContent = 'Destinations for ' + item.name + ' loaded. Pick the route to continue.';
            hint.style.color = '';
          } else {
            fromSel.enable(false);
            toSel.enable(false);
            hint.textContent = 'Select a carrier first to load destinations.';
            hint.style.color = '';
          }
          update();
        }
      });
      var fromSel = createSelect({
        mount: fromMount,
        items: [],
        label: 'From',
        placeholder: 'Enter city or town, e.g. Nairobi',
        icon: 'pin',
        disabled: true,
        onChange: update
      });
      var toSel = createSelect({
        mount: toMount,
        items: [],
        label: 'To',
        placeholder: 'Enter city or town, e.g. Mombasa',
        icon: 'pin',
        disabled: true,
        onChange: update
      });

      function update() {
        var ready = mode === 'errand'
          ? !!(fromSel.value() && toSel.value())
          : !!(carrier.value() && fromSel.value() && toSel.value());
        continueBtn.disabled = !ready;
        continueBtn.setAttribute('aria-disabled', String(!ready));
      }

      function setMode(next) {
        mode = next;
        var titleEl = form.querySelector('[data-mz-home-title]');
        var copyEl = form.querySelector('[data-mz-home-copy]');
        if (titleEl) titleEl.textContent = next === 'errand' ? 'Book a local errand runner in minutes' : 'Send a parcel across Kenya, without the guesswork';
        if (copyEl) copyEl.textContent = next === 'errand'
          ? 'Upfront pricing, verified runners and live updates from pickup to delivery.'
          : 'Compare trusted couriers, see your price upfront, pay with M-Pesa and track every scan.';
        tabBtns.forEach(function (b) {
          var on = b.getAttribute('data-mz-tab') === next;
          b.setAttribute('aria-selected', String(on));
          b.classList.toggle('is-active', on);
        });
        if (next === 'errand') {
          fromSel.setItems(landmarks);
          toSel.setItems(landmarks);
          fromSel.enable(true);
          toSel.enable(true);
          fromSel.input.placeholder = 'Search pickup address or landmark';
          toSel.input.placeholder = 'Search destination address or landmark';
          hint.textContent = 'Book a runner for pickups and deliveries around town.';
        } else {
          var item = carrier.item();
          if (item) {
            fromSel.setItems(item.destinations);
            toSel.setItems(item.destinations);
            fromSel.enable(true);
            toSel.enable(true);
            hint.textContent = 'Destinations for ' + item.name + ' loaded. Pick the route to continue.';
          } else {
            fromSel.setItems([]);
            toSel.setItems([]);
            fromSel.enable(false);
            toSel.enable(false);
            hint.textContent = 'Select a carrier first to load destinations.';
          }
          fromSel.input.placeholder = 'Enter city or town, e.g. Nairobi';
          toSel.input.placeholder = 'Enter city or town, e.g. Mombasa';
        }
        update();
      }
      tabBtns.forEach(function (b) {
        b.addEventListener('click', function () { setMode(b.getAttribute('data-mz-tab')); });
      });

      continueBtn.addEventListener('click', function () {
        if (continueBtn.disabled) { toast('Choose a courier, origin and destination first.', 'error'); return; }
        if (mode === 'errand') {
          window.location.href = '/send-errand?carrier=' + encodeURIComponent((carrier.item() || {}).slug || '') +
            '&pickup=' + encodeURIComponent(fromSel.value()) + '&dropoff=' + encodeURIComponent(toSel.value());
          return;
        }
        var chosen = carrier.item();
        toast('Redirecting to create Mzigo…');
        window.setTimeout(function () {
          window.location.href = '/send-mzigo/' + chosen.slug +
            '?from=' + encodeURIComponent(fromSel.value()) + '&to=' + encodeURIComponent(toSel.value());
        }, 900);
      });
      update();
    });
  }

  function initTrackForms() {
    $$('[data-mz-track-form]').forEach(function (form) {
      var input = form.querySelector('input[type="text"], input:not([type])');
      var submit = form.querySelector('[type="submit"]');
      if (!input) return;
      var panel = doc.createElement('div');
      panel.className = 'mz-suggest mz-track-predict';
      panel.hidden = true;
      form.style.position = 'relative';
      form.appendChild(panel);
      var timer = null;
      var requestSeq = 0;

      function hidePanel() { panel.hidden = true; panel.innerHTML = ''; }
      function syncSubmit() {
        if (submit) submit.disabled = input.value.trim() === '';
      }
      function renderPredict(q, res) {
        var record = (res && res.record) || null;
        if (!record) {
          panel.innerHTML = '<div class="mz-option mz-option--empty">No parcel found for <strong>' + esc(q.toUpperCase()) + '</strong> yet.</div>';
          panel.hidden = false;
          return;
        }
        var ref = record.tracking || record.ref || q;
        var status = String(record.status || record.payment_status || '').replace(/_/g, ' ').toLowerCase();
        var route = record.from_city && record.to_city ? record.from_city + ' → ' + record.to_city : (record.dropoff || '');
        panel.innerHTML = '<button type="button" class="mz-option" data-track-predict-go="' + esc(ref) + '">' +
          '<span style="min-width:0;"><span class="mz-mono" style="font-weight:700;">' + esc(ref) + '</span>' +
          '<span style="display:block;font-size:11px;color:var(--mz-muted-fg);">' + esc((record.carrier || 'Mzigo') + (status ? ' · ' + status : '') + (route ? ' · ' + route : '')) + '</span></span>' +
          '<span class="mz-option__meta">Open</span></button>';
        panel.hidden = false;
        var go = panel.querySelector('[data-track-predict-go]');
        if (go) go.addEventListener('click', function () {
          window.location.href = '/track-mzigo?num=' + encodeURIComponent(go.getAttribute('data-track-predict-go'));
        });
      }
      function predict() {
        var q = input.value.trim();
        if (!/^(TRK|LK|ERD)-[A-Z0-9-]{3,}$/i.test(q)) { hidePanel(); return; }
        var seq = ++requestSeq;
        panel.innerHTML = '<div class="mz-option mz-option--empty">Checking ' + esc(q.toUpperCase()) + '…</div>';
        panel.hidden = false;
        api('track.php?q=' + encodeURIComponent(q)).then(function (res) {
          if (seq !== requestSeq || input.value.trim() !== q) return;
          renderPredict(q, res);
        }).catch(function () {
          if (seq !== requestSeq) return;
          hidePanel();
        });
      }
      input.addEventListener('input', function () {
        syncSubmit();
        clearTimeout(timer);
        timer = setTimeout(predict, 260);
      });
      doc.addEventListener('click', function (ev) { if (!form.contains(ev.target)) hidePanel(); });
      syncSubmit();
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var value = input.value.trim();
        if (!value) { toast('Enter a tracking number first.', 'error'); input.focus(); return; }
        hidePanel();
        window.location.href = '/track-mzigo?num=' + encodeURIComponent(value);
      });
    });
  }

  /* ------------------------------------------------------------- website CMS */
  var cmsPromise = null;
  function loadCms() {
    if (!cmsPromise) {
      cmsPromise = api('cms.php').catch(function (err) {
        console.warn('[mzigo] CMS payload unavailable:', err.message);
        return null;
      });
    }
    return cmsPromise;
  }

  /** Apply admin-uploaded brand assets (logo + favicon) to the current page. */
  function applyBranding(branding) {
    if (!branding) return;
    var logo = branding.logo_url;
    var favicon = branding.favicon_url;
    var hero = branding.hero_url;
    if (logo && branding.custom_logo) {
      $$('img').forEach(function (img) {
        var src = img.getAttribute('src') || '';
        if (src.indexOf('mzigo-logo') !== -1) img.setAttribute('src', logo);
      });
    }
    if (hero) {
      $$('[data-mz-hero-image], img[alt="Parcel delivery"]').forEach(function (img) {
        if (img.getAttribute('src') !== hero) img.setAttribute('src', hero);
      });
    }
    if (branding.hero_title) {
      $$('[data-mz-hero-title]').forEach(function (el) { el.textContent = branding.hero_title; });
    }
    if (branding.hero_subtitle) {
      $$('[data-mz-hero-subtitle], [data-mz-brand-tagline]').forEach(function (el) {
        el.textContent = branding.hero_subtitle;
      });
    }
    if (branding.social) {
      $$('[data-mz-social]').forEach(function (link) {
        var network = link.getAttribute('data-mz-social');
        var url = branding.social[network];
        if (url) {
          link.setAttribute('href', url);
          link.hidden = false;
          link.style.display = '';
        } else {
          link.hidden = true;
          link.style.display = 'none';
        }
      });
    }
    if (favicon) {
      $$('link[rel="icon"], link[rel="shortcut icon"]').forEach(function (link) {
        link.setAttribute('href', favicon);
        link.removeAttribute('sizes');
        if (/\.png(\?|$)/i.test(favicon)) link.setAttribute('type', 'image/png');
        else if (/\.ico(\?|$)/i.test(favicon)) link.setAttribute('type', 'image/x-icon');
        else if (/\.svg(\?|$)/i.test(favicon)) link.setAttribute('type', 'image/svg+xml');
      });
      var apple = $('link[rel="apple-touch-icon"]');
      if (apple && logo) apple.setAttribute('href', logo);
    }
  }

  function faqItemHTML(item, index) {
    var open = index === 0 ? ' is-open' : '';
    var expanded = index === 0 ? 'true' : 'false';
    return '<div class="mz-faq-item">' +
      '<button type="button" class="mz-faq-trigger" aria-expanded="' + expanded + '" aria-controls="faq-panel-' + index + '" data-mz-faq-trigger>' +
        '<span>' + esc(item.question) + '</span>' +
        '<span class="mz-faq-trigger__icon">' +
          '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"></path></svg>' +
        '</span>' +
      '</button>' +
      '<div class="mz-faq-panel' + open + '" id="faq-panel-' + index + '" role="region"><div><p>' + esc(item.answer) + '</p></div></div>' +
    '</div>';
  }

  function bindFaqAccordion(list) {
    var triggers = $$('[data-mz-faq-trigger]', list);
    triggers.forEach(function (btn) {
      var panel = doc.getElementById(btn.getAttribute('aria-controls'));
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') === 'true';
        triggers.forEach(function (b) {
          var p = doc.getElementById(b.getAttribute('aria-controls'));
          b.setAttribute('aria-expanded', 'false');
          if (p) { p.classList.remove('is-open'); }
        });
        if (!open) {
          btn.setAttribute('aria-expanded', 'true');
          if (panel) panel.classList.add('is-open');
        }
      });
    });
  }

  function initFaq() {
    var list = $('[data-mz-faq-list]');
    if (!list) return;
    bindFaqAccordion(list);
    loadCms().then(function (res) {
      if (!res || !res.faqs || !res.faqs.length) return;
      var limit = parseInt(list.getAttribute('data-mz-faq-limit') || '0', 10);
      var faqs = limit > 0 ? res.faqs.slice(0, limit) : res.faqs;
      list.innerHTML = faqs.map(faqItemHTML).join('');
      bindFaqAccordion(list);
    });
  }

  /* ------------------------------------------------------------- partners page */
  function initPartners() {
    var grid = $('[data-mz-partners-grid]');
    if (!grid) return;
    var cards = $$('article', grid);
    cards.forEach(function (card) {
      var nameEl = card.querySelector('[data-mz-partner-name]');
      var slug = card.getAttribute('data-partner');
      var partner = APP.partners.filter(function (p) { return p.slug === slug; })[0];
      card.setAttribute('data-name', ((nameEl && nameEl.textContent) || '').toLowerCase());
      card.setAttribute('data-destinations', partner ? partner.destinations.join('|').toLowerCase() : '');
      if (slug && !card.querySelector('[data-partner-routes-link]')) {
        var routesLink = doc.createElement('a');
        routesLink.className = 'mz-link';
        routesLink.href = '/partners/' + encodeURIComponent(slug);
        routesLink.setAttribute('data-partner-routes-link', '');
        routesLink.textContent = 'View routes';
        routesLink.style.cssText = 'display:inline-block;margin-top:6px;font-size:12px;';
        card.appendChild(routesLink);
      }
    });

    var search = $('[data-mz-partner-search]');
    var originSel = createSelect({ mount: $('[data-mz-mount="origin"]'), items: allDestinations(), label: 'Origin', placeholder: 'Select origin', icon: 'pin', onChange: filter });
    var destSel = createSelect({ mount: $('[data-mz-mount="destination"]'), items: allDestinations(), label: 'Destination', placeholder: 'Select destination', icon: 'pin', onChange: filter });
    var countEl = $('[data-mz-partner-count]');

    function filter() {
      var q = (search && search.value || '').trim().toLowerCase();
      var origin = (originSel.value() || '').toLowerCase();
      var dest = (destSel.value() || '').toLowerCase();
      var visible = 0;
      cards.forEach(function (card) {
        var name = card.getAttribute('data-name') || '';
        var destinations = card.getAttribute('data-destinations') || '';
        var ok = (!q || name.indexOf(q) !== -1) &&
          (!origin || destinations.indexOf(origin) !== -1) &&
          (!dest || destinations.indexOf(dest) !== -1);
        card.hidden = !ok;
        if (ok) visible++;
      });
      if (countEl) {
        countEl.textContent = visible + (visible === 1 ? ' partner' : ' partners') + ' match your route.';
        countEl.style.display = '';
      }
      var empty = $('[data-mz-partner-empty]');
      if (empty) empty.hidden = visible !== 0;
    }
    if (search) search.addEventListener('input', filter);

    // Deep link: /partners?q=…&origin=…&destination=…
    if (qs('q') && search) search.value = qs('q');
    filter();
  }

  /* ---------------------------------------------------------------- track page */
  function bindTrackDemo(mount) {
    var demo = mount.querySelector('[data-mz-try-demo]');
    if (!demo) return;
    demo.addEventListener('click', function () {
      var input = $('input[aria-label="Tracking number input"]');
      if (input) input.value = 'TRK-4WEWFH1L';
      renderTrackResult('TRK-4WEWFH1L');
    });
  }

  function copyToClipboard(text, message) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        toast(message || 'Copied.', 'info');
      }).catch(function () {
        toast('Could not copy automatically — long-press the code to copy it.', 'error');
      });
    } else {
      toast('Long-press the code to copy it.', 'info');
    }
  }

  function bindTrackSubscribe(mount, ref) {
    var form = mount.querySelector('[data-track-subscribe]');
    if (!form) return;
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('track-subscribe.php', {
        method: 'POST',
        body: {
          tracking: ref,
          email: formValue(form, 'email'),
          phone: formValue(form, 'phone'),
          consent: !!(form.querySelector('[name="consent"]') || {}).checked
        }
      }).then(function (res) {
        toast((res && res.message) || 'You will receive parcel updates.');
        trackEvent('track_notification_opt_in', { label: 'Track updates opt-in' });
        form.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
        btn.textContent = 'Updates enabled';
      }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function bindCopyButtons(root) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-copy-code]'), function (btn) {
      btn.addEventListener('click', function () {
        trackEvent('collection_code_copy', { label: 'Copy collection code' });
        copyToClipboard(btn.getAttribute('data-copy-code'), 'Collection code copied.');
      });
    });
  }

  function renderTrackRecord(mount, r, kind) {
    var isErrand = kind === 'errand';
    var ref = r.tracking || r.ref || r.id || '';
    var st = STATUS_META[r.status] || STATUS_META.CREATED;
    var scans = (r.scans || []).slice().sort(function (a, b) { return new Date(a.at) - new Date(b.at); });

    var timeline = scans.map(function (scan, i) {
      var meta = STATUS_META[scan.status] || {};
      var cls = i === scans.length - 1 ? 'is-current' : '';
      return '<div class="mz-timeline__item ' + cls + '">' +
          '<span class="mz-timeline__dot">' + (i < scans.length - 1 ? ICON.tick : '') + '</span>' +
          '<div class="mz-timeline__label">' + esc(scan.label || meta.label || scan.status) + '</div>' +
          '<div class="mz-timeline__meta">' + esc(scan.where || '') + (scan.at ? ' · ' + esc(fmtDate(scan.at)) : '') + '</div>' +
          (scan.note ? '<div class="mz-timeline__note">' + esc(scan.note) + '</div>' : '') +
        '</div>';
    }).join('');

    var collect = '';
    if (r.status === 'READY_FOR_COLLECTION') {
      var collectPaid = r.payment_status === 'paid';
      collect = '<div class="mz-list-card" style="margin-top:14px;background:' + (collectPaid ? '#f0fdf4' : '#fffbeb') + ';border-color:' + (collectPaid ? '#a7d7b0' : '#f0d68a') + ';">' +
        '<div class="mz-row"><div><div class="mz-cap">' + (collectPaid ? 'Paid at collection' : 'Pay at collection') + '</div><div style="font-size:20px;font-weight:800;">' + kes((r.fee || {}).total) + '</div></div>' +
        '<span class="mz-pill ' + (collectPaid ? 'mz-pill--done' : 'mz-pill--ready') + '">' + (collectPaid ? 'PAID' : 'M-PESA ready') + '</span></div>' +
        '<p style="font-size:13px;color:var(--mz-muted-fg);margin:0;">' + (collectPaid
          ? 'Payment received' + (r.payment_ref ? ' · reference <span class="mz-mono">' + esc(r.payment_ref) + '</span>' : '') + '. Show your collection code and phone number at the destination office.'
          : 'Pay the delivery fee from your customer account or at the destination office. The parcel is released only after the payment is confirmed.') +
          (collectPaid ? '' : ' <a class="mz-link" href="/login?role=customer">Sign in as customer</a>') + '</p>' +
        (collectPaid && r.collect_code ? '<div class="mz-kv"><dt>Collection code</dt><dd class="mz-mono">' + esc(r.collect_code) +
          ' <button type="button" class="mz-tab" data-copy-code="' + esc(r.collect_code) + '">Copy</button></dd></div>' +
          '<p style="font-size:12px;color:var(--mz-muted-fg);margin:6px 0 0;">Keep this code — it stays available in My parcels.</p>' : '') +
        '</div>';
    }

    var fee = r.fee || {};
    mount.innerHTML =
      '<div class="mz-list-card">' +
        '<div class="mz-row">' +
          '<div style="display:flex;align-items:center;gap:10px;min-width:0;">' + avatar(r.carrier || (isErrand ? 'Errand' : 'mzigo'), 'md') +
            '<div style="min-width:0;"><div class="mz-cap">' + (isErrand ? 'Errand' : 'Parcel') + '</div>' +
            '<div style="font-weight:700;font-size:15px;">' + esc(r.carrier || 'Mzigo.online') + '</div></div></div>' +
          '<span class="mz-pill mz-pill--' + st.pill + '">' + esc(st.label) + '</span>' +
        '</div>' +
        '<div class="mz-receipt-grid">' +
          '<dl style="margin:0;">' +
            '<div class="mz-kv"><dt>' + (isErrand ? 'Errand' : 'Tracking') + '</dt><dd class="mz-mono">' + esc(ref) + '</dd></div>' +
            '<div class="mz-kv"><dt>' + (isErrand ? 'Pickup' : 'From') + '</dt><dd>' + esc(r.from || r.pickup || '') + '</dd></div>' +
            '<div class="mz-kv"><dt>' + (isErrand ? 'Drop-off' : 'To') + '</dt><dd>' + esc(r.to || r.dropoff || '') + '</dd></div>' +
            (r.locker ? '<div class="mz-kv"><dt>Locker ID</dt><dd class="mz-mono">' + esc(r.locker) + '</dd></div>' : '') +
          '</dl>' +
          '<dl style="margin:0;">' +
            '<div class="mz-kv"><dt>Registered</dt><dd>' + esc(fmtDate(r.registered_at || r.created_at || nowISO())) + '</dd></div>' +
            (r.size ? '<div class="mz-kv"><dt>Package size</dt><dd>' + esc(r.size) + '</dd></div>' : '') +
            (r.payment ? '<div class="mz-kv"><dt>Payment</dt><dd>' + esc(r.payment) + '</dd></div>' : '') +
            (fee.total ? '<div class="mz-kv"><dt>' + (isErrand ? 'Runner fee' : 'Delivery fee') + '</dt><dd>' + kes(fee.total) + '</dd></div>' : '') +
          '</dl>' +
        '</div>' +
      '</div>' +
      collect +
      '<div class="mz-list-card" style="margin-top:14px;">' +
        '<div class="mz-cap">Scan history · MySQL</div>' +
        '<div class="mz-timeline">' + timeline + '</div>' +
      '</div>' +
      '<div class="mz-list-card" style="margin-top:14px;">' +
        '<div class="mz-cap">Need help with this parcel?</div>' +
        '<p style="font-size:13px;color:var(--mz-muted-fg);margin:6px 0 0;">If it is delayed, damaged or lost, or you are the receiver and want to manage all your incoming parcels, we can help.</p>' +
        '<div class="mz-row" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;margin-top:10px;">' +
          '<a class="mz-btn mz-btn--outline" href="/contact?ref=' + encodeURIComponent(ref) + '&topic=parcel">Contact support</a>' +
          '<a class="mz-btn mz-btn--outline" href="/claims">Open a claim</a>' +
          (APP.user ? '' : '<a class="mz-link" href="/login?role=customer" style="align-self:center;">Receiving this parcel? Sign in</a>') +
        '</div>' +
        '<form data-track-subscribe style="margin-top:14px;">' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Email for updates (optional)</span><input class="mz-input" name="email" type="email" placeholder="you@example.com"></label>' +
            '<label class="mz-field"><span class="mz-label">Phone for updates (optional)</span><input class="mz-input" name="phone" type="tel" placeholder="0712 345 678"></label>' +
          '</div>' +
          '<label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--mz-muted-fg);"><input type="checkbox" name="consent" style="margin-top:2px;"> Email/SMS me updates about this parcel.</label>' +
          '<button type="submit" class="mz-btn mz-btn--outline" style="margin-top:10px;">Get parcel updates</button>' +
        '</form>' +
      '</div>';
    bindCopyButtons(mount);
    bindTrackSubscribe(mount, ref);
  }

  function renderTrackResult(term) {
    var mount = $('[data-mz-track-result]');
    if (!mount) return Promise.resolve();
    mount.innerHTML = '<div class="mz-list-card" style="text-align:center;padding:26px;">' +
      '<div class="mz-cap">Querying MySQL</div>' +
      '<p style="margin:6px 0 0;font-size:14px;">Looking up “' + esc(term) + '”…</p></div>';
    return trackLookup(term).then(function (res) {
      if (!res.found) {
        mount.innerHTML =
          '<div class="mz-list-card" style="text-align:center;padding:32px 20px;">' +
            '<div class="mz-empty-icon">' + ICON.search + '</div>' +
            '<p style="font-size:17px;font-weight:700;margin:0 0 6px;">No parcel found for “' + esc(term) + '”</p>' +
            '<p style="font-size:13px;color:var(--mz-muted-fg);margin:0 0 16px;">Nothing in the database matches that tracking number or Locker ID. Check it and try again.</p>' +
            '<button type="button" class="mz-tab is-active" data-mz-try-demo style="display:inline-flex;">Try demo parcel TRK-4WEWFH1L</button>' +
          '</div>';
        bindTrackDemo(mount);
        return;
      }
      trackEvent('track_search', { label: term, meta: { found: true } });
      renderTrackRecord(mount, res.record, res.kind);
    }).catch(function (err) {
      if (err.status === 422) {
        mount.innerHTML =
          '<div class="mz-list-card" style="text-align:center;padding:32px 20px;">' +
            '<div class="mz-empty-icon">' + ICON.search + '</div>' +
            '<p style="font-size:17px;font-weight:700;margin:0 0 6px;">Nothing to look up yet</p>' +
            '<p style="font-size:13px;color:var(--mz-muted-fg);margin:0 0 16px;">Enter a tracking number, Locker ID or errand reference.</p>' +
            '<button type="button" class="mz-tab is-active" data-mz-try-demo style="display:inline-flex;">Try demo parcel TRK-4WEWFH1L</button>' +
          '</div>';
        bindTrackDemo(mount);
        return;
      }
      mount.innerHTML = '<div class="mz-list-card" style="text-align:center;padding:28px;">' +
        '<div class="mz-empty-icon">' + ICON.info + '</div>' +
        '<p style="font-weight:700;margin:0 0 6px;">Could not reach the tracking service</p>' +
        '<p style="font-size:13px;color:var(--mz-muted-fg);margin:0;">' + esc(err.message) + '</p></div>';
    });
  }

  function initTrack() {
    var form = $('[data-mz-track-page-form]');
    if (!form) return;
    var input = form.querySelector('input');
    var panel = doc.createElement('div');
    panel.className = 'mz-suggest';
    panel.hidden = true;

    var hist = allTrackables().slice(0, 8);
    function renderPanel(q) {
      var query = String(q || '').trim().toLowerCase();
      var matches = hist.filter(function (t) {
        var name = (t.rec.tracking || t.rec.id || '').toLowerCase();
        var sub = (t.rec.carrier || '').toLowerCase();
        return !query || name.indexOf(query) !== -1 || sub.indexOf(query) !== -1;
      });
      panel.innerHTML = matches.length
        ? matches.map(function (t) {
            var r = t.rec;
            return '<button type="button" class="mz-option" data-track="' + esc(r.tracking || r.ref || r.id) + '">' +
              avatar(r.carrier || (t.kind === 'errand' ? 'Errand' : 'mzigo'), 'sm') +
              '<span style="min-width:0;"><span class="mz-mono">' + esc(r.tracking || r.ref || r.id) + '</span><br><span style="font-size:11px;color:var(--mz-muted-fg);">' + esc(r.carrier || '') + ' · ' + esc(r.to || r.dropoff || '') + '</span></span>' +
              '</button>';
          }).join('')
        : '<div class="mz-option mz-option--empty">No saved parcels yet.</div>';
      Array.prototype.forEach.call(panel.querySelectorAll('[data-track]'), function (btn) {
        btn.addEventListener('click', function () {
          input.value = btn.getAttribute('data-track');
          panel.hidden = true;
          run();
        });
      });
    }
    var host = input.closest('.relative') || input.parentElement;
    host.style.position = 'relative';
    host.appendChild(panel);
    input.addEventListener('focus', function () { renderPanel(input.value); panel.hidden = false; });
    input.addEventListener('input', function () { renderPanel(input.value); panel.hidden = false; });
    doc.addEventListener('click', function (ev) { if (!host.contains(ev.target)) panel.hidden = true; });

    function run() {
      var value = input.value.trim();
      if (!value) { toast('Enter a tracking number first.', 'error'); return; }
      if (history.replaceState) history.replaceState(null, '', '/track-mzigo?num=' + encodeURIComponent(value));
      renderTrackResult(value);
      var res = $('[data-mz-track-result]');
      if (res) res.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var submitBtn = form.querySelector('button[type="submit"]');
    function syncSubmit() {
      if (!submitBtn) return;
      var has = !!(input.value || '').trim();
      submitBtn.disabled = !has;
      submitBtn.setAttribute('aria-disabled', String(!has));
    }
    input.addEventListener('input', syncSubmit);
    syncSubmit();
    form.addEventListener('submit', function (ev) { ev.preventDefault(); panel.hidden = true; run(); syncSubmit(); });

    var num = qs('num') || qs('tracking');
    if (num) { input.value = num; renderTrackResult(num); }
  }

  /* ---------------------------------------------------------------- profile */
  var profileTab = 'packages';
  function tenantStatusBanner() {
    var t = APP.tenant;
    if (!t || ['suspended', 'cancelled'].indexOf(t.status) === -1) return '';
    var isStaff = t.role === 'staff';
    return '<div class="mz-alert" style="margin-bottom:16px;background:#fef3c7;border-color:#f0d68a;">' +
      '<strong>Your workspace is ' + esc(t.status) + '.</strong> ' +
      (isStaff ? 'Ask your workspace owner to reactivate it.' : 'Select a plan on Billing and settle the invoice to reactivate sending.') +
      ' <a class="mz-link" href="/billing">Open billing</a></div>';
  }


  /* ------------------------------------------------------ business dashboard */
  function emailVerificationBanner() {
    var u = APP.user;
    if (!u || !u.email || u.email_verified_at) return '';
    if (['user', 'platform_admin'].indexOf(u.role) === -1) return '';
    return '<div class="mz-alert" style="margin-bottom:16px;background:#fffbeb;border-color:#f0d68a;" data-email-verification-banner>' +
      '<strong>Verify your email.</strong> Confirm <strong>' + esc(u.email) + '</strong> to receive password-reset and account notices. ' +
      '<button type="button" class="mz-link" data-resend-verification>Resend verification email</button>' +
      '<div class="mz-hint" data-verification-result style="margin-top:6px;"></div></div>';
  }

  function initMerchantDashboard() {
    var body = $('[data-mz-profile-body]');
    var tabsMount = $('[data-mz-profile-tabs]');
    if (tabsMount) tabsMount.hidden = true;
    /* One clean page header for every role dashboard (same pill as admin). */
    var hero = document.querySelector('.mz-saas-head');
    if (!hero) {
      var profileTitle = [].slice.call(document.querySelectorAll('h1')).filter(function (h) {
        return h.textContent.trim() === 'My profile';
      })[0];
      hero = profileTitle ? profileTitle.closest('header') : null;
    }
    if (hero) {
      hero.hidden = false;
      hero.innerHTML = '<span class="mz-cap">Workspace</span><h1>Dashboard</h1>' +
        '<p>Usage, recent parcels and errands across your workspace.</p>';
    }
    initAppNav();
    document.title = 'Dashboard · Mzigo.online';
    if (!body) return;
    var u = APP.usage || {};
    var plan = u.plan || {};
    var sub = u.subscription || {};
    var used = u.parcels_used || 0;
    var limit = u.parcels_limit || plan.parcel_limit || 0;
    var pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
    var staff = APP.tenant && APP.tenant.role === 'staff';
    var trial = u.trial_days_left;
    var greeting = 'Welcome back, ' + esc((APP.user && APP.user.name) || 'there');
    var workspaceActions = staff
      ? '<a class="mz-btn mz-btn--outline" href="/help">Help centre</a>'
      : '<a class="mz-btn mz-btn--outline" href="/settings#team">Invite your team</a>' +
        '<a class="mz-btn mz-btn--outline" href="/billing">Upgrade plan</a>' +
        '<a class="mz-btn mz-btn--outline" href="/settings#api">Connect your store (API)</a>' +
        '<a class="mz-tab" href="/help">Help centre</a>';

    body.innerHTML =
      tenantStatusBanner() +
      emailVerificationBanner() +
      (staff ? '<div class="mz-alert" style="margin-bottom:16px;background:#f1f5f9;border-color:#cbd5e1;">You have staff access — sending and orders are available, while billing and settings are read-only.</div>' : '') +
      '<div class="mz-saas-card" style="margin-bottom:16px;">' +
        '<div class="mz-row" style="align-items:flex-start;">' +
          '<div><div class="mz-cap">' + esc(APP.tenant.name) + '</div>' +
            '<div style="font-size:22px;font-weight:800;color:var(--mz-hunter);">' + greeting + '</div>' +
            '<div style="font-size:13px;color:var(--mz-muted-fg);margin-top:2px;">' +
              esc(plan.name || 'No plan') + ' · ' + money(plan.monthly_price_kes || 0) + '/month' +
              (trial != null ? ' · <strong>' + trial + ' trial days left</strong>' : '') +
            '</div></div>' +
          '<span class="mz-badge ' + ((sub.subscription_status === 'active') ? 'mz-badge--ok' : 'mz-badge--warn') + '">' +
            esc((sub.subscription_status || 'no subscription').replace('_', ' ')) + '</span>' +
        '</div>' +
        '<div class="mz-dash-actions">' +
          '<a class="mz-btn" href="/send-mzigo">Send a parcel</a>' +
          '<a class="mz-btn mz-btn--outline" href="/orders">View orders</a>' +
          '<a class="mz-btn mz-btn--outline" href="/send-errand">Book an errand</a>' +
          '<a class="mz-btn mz-btn--outline" href="/billing">Billing</a>' +
        '</div>' +
        (limit
          ? '<div class="mz-meter" style="margin-top:14px;"><span style="width:' + pct + '%"></span></div>' +
            '<div style="font-size:11.5px;color:var(--mz-muted-fg);margin-top:6px;">' + pct + '% of plan used</div>'
          : '') +
      '</div>' +
      '<div class="mz-stats" id="earnings" style="margin-bottom:16px;">' +
        '<div class="mz-stat"><div class="mz-cap">Parcels this month</div><div class="mz-stat__value">' + used + ' / ' + limit + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Remaining quota</div><div class="mz-stat__value">' + (u.parcels_remaining || 0) + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Team members</div><div class="mz-stat__value">' + (u.staff_used || 0) + ' / ' + (plan.staff_limit || 0) + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Plan</div><div class="mz-stat__value" style="font-size:16px;">' + esc(plan.name || '—') + '</div></div>' +
        '<a class="mz-stat" href="/claims" style="text-decoration:none;color:inherit;" aria-label="Open claims"><div class="mz-cap">Open claims</div><div class="mz-stat__value">' + (u.claims_open || 0) + '</div></a>' +
      '</div>' +
      '<div class="mz-saas-card" style="margin-bottom:16px;" data-merchant-kyc-card></div>' +
      '<div class="mz-saas-card" style="margin-bottom:16px;">' +
        '<div class="mz-card__title">Workspace actions</div>' +
        '<div class="mz-row" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;">' + workspaceActions + '</div>' +
      '</div>' +
      '<div class="mz-saas-grid mz-saas-grid--2">' +
        '<div class="mz-saas-card"><div class="mz-row"><div class="mz-card__title" style="margin:0;">Recent parcels</div><a class="mz-link" href="/orders">View all</a></div><div data-dash-parcels>Loading…</div></div>' +
        '<div class="mz-saas-card"><div class="mz-row"><div class="mz-card__title" style="margin:0;">Recent errands</div><a class="mz-link" href="/orders?tab=errands">View all</a></div><div data-dash-errands>Loading…</div></div>' +
      '</div>' +
      '<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">' +
        '<button type="button" class="mz-tab mz-signout-inline" data-dash-signout>Sign out</button>' +
      '</div>';

    loadDashList('parcels', body.querySelector('[data-dash-parcels]'));
    loadDashList('errands', body.querySelector('[data-dash-errands]'));
    initMerchantKyc(body.querySelector('[data-merchant-kyc-card]'));
    var signOutBtn = body.querySelector('[data-dash-signout]');
    if (signOutBtn) signOutBtn.addEventListener('click', function () {
      signOut().then(function () { window.location.href = '/login'; });
    });
  }

  function loadDashList(tab, mount) {
    if (!mount) return;
    api('orders.php?per_page=4&tab=' + tab).then(function (res) {
      if (!res.rows || !res.rows.length) {
        mount.innerHTML = '<div class="mz-empty-note" style="text-align:center;padding:22px;">' +
          '<strong>No ' + tab + ' yet.</strong><br>' +
          (tab === 'parcels'
            ? 'Register your first parcel to see its status here.'
            : 'Book an errand and follow the runner from pickup to delivery.') +
        '</div>' +
        '<div style="text-align:center;padding-top:10px;">' +
          '<a class="mz-btn mz-btn--outline" href="' + (tab === 'parcels' ? '/send-mzigo' : '/send-errand') + '">' +
            (tab === 'parcels' ? 'Send a parcel' : 'Book an errand') +
          '</a>' +
        '</div>';
        return;
      }
      mount.innerHTML = res.rows.map(function (r) {
        var st = STATUS_META[r.status] || STATUS_META.CREATED;
        var ref = tab === 'parcels' ? r.tracking : r.ref;
        var route = tab === 'parcels' ? r.from + ' → ' + r.to : r.pickup + ' → ' + r.dropoff;
        return '<div class="mz-recent-row">' +
          '<div class="mz-recent-row__main"><div class="mz-mono" style="font-size:12px;">' + esc(ref) + '</div>' +
          '<div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(route) + '</div></div>' +
          '<div class="mz-recent-row__side">' +
            '<span class="mz-pill mz-pill--' + esc(st.pill) + '">' + esc(st.label) + '</span>' +
            '<a class="mz-link" href="/track-mzigo?num=' + encodeURIComponent(ref) + '">Track</a>' +
          '</div></div>';
      }).join('');
    }).catch(function (err) {
      mount.innerHTML = '<div class="mz-hint" style="margin-top:10px;">Could not load: ' + esc(err.message) + '</div>';
    });
  }

  /* ---------------------------------------------------------------- contact */
  function initReferPage() {
    var auth = $('[data-refer-auth]');
    var variant = 'A';
    try {
      variant = localStorage.getItem('mz_ref_variant') || (Math.random() < 0.5 ? 'A' : 'B');
      if (variant !== 'A' && variant !== 'B') variant = 'A';
      localStorage.setItem('mz_ref_variant', variant);
    } catch (e) {}
    var tiktokCaptions = {
      0: 'POV: you sent a parcel across Kenya without queuing at three offices. Register online, drop off once, track to collection. Use my code {code}.',
      1: 'How I earn with Mzigo.online as a runner: pick errands, deliver, get paid by M-Pesa. Join with my code {code}.',
      2: 'Receiving a parcel? Pay the delivery fee by M-Pesa at collection, keep the code, collect. Track it here. Code {code}.'
    };
    api('growth.php').then(function (data) {
      var ab = (data.public && data.public.rewards && data.public.rewards.ab_test) || {};
      var abEnabled = ab.enabled !== false;
      if (!abEnabled) variant = 'A';
      trackEvent('referral_variant_exposure', {
        label: 'Referral copy ' + variant,
        meta: { variant: variant, enabled: abEnabled }
      });
      var leader = (data.public && data.public.leaderboard) || [];
      var leaderMount = $('[data-refer-leaderboard]');
      if (leaderMount) {
        leaderMount.innerHTML = leader.length ? leader.map(function (row, i) {
          return '<div class="mz-hint" style="display:flex;justify-content:space-between;padding:6px 0;"><span>' + (i + 1) + '. ' + esc(row.name) + '</span><strong>' + row.referrals + ' invites</strong></div>';
        }).join('') : '<div class="mz-hint">No referrals yet this month — be the first.</div>';
      }
      if (!data.referral) { return; }
      if (auth) auth.style.display = 'none';
      var box = $('[data-refer-code-box]');
      if (box) box.hidden = false;
      var code = data.referral.code;
      var base = location.origin;
      var link = base + '/refer?ref=' + encodeURIComponent(code) + '&src=viral-share-' + variant;
      var codeEl = $('[data-refer-code]');
      if (codeEl) codeEl.textContent = code;
      var stats = $('[data-refer-stats]');
      if (stats) stats.textContent = data.referral.clicks + ' link clicks · ' + data.referral.signups + ' sign-ups · ' + data.referral.activated + ' activated · KES ' + data.referral.earned_kes + ' earned';
      var intro = $('[data-refer-intro]');
      if (intro) intro.textContent = 'Share your link. Rewards are credited when your invite activates.';
      var defaultCopy = variant === 'A'
        ? 'I use Mzigo.online to send parcels across Kenya. Join with my code {code}: '
        : 'Sending parcels should be easy. I use Mzigo.online - register, drop off once, track to collection. Use my code {code}: ';
      var copyTemplate = ab.variants && ab.variants[variant] ? ab.variants[variant] : defaultCopy;
      var copyText = String(copyTemplate).replace(/\{code\}/g, code);
      var wa = $('[data-refer-whatsapp]');
      if (wa) {
        wa.setAttribute('href', 'https://wa.me/?text=' + encodeURIComponent(copyText + link));
        wa.addEventListener('click', function () { trackEvent('referral_share', { label: 'whatsapp', meta: { variant: variant } }); });
      }
      var sms = $('[data-refer-sms]');
      if (sms) {
        sms.setAttribute('href', 'sms:?&body=' + encodeURIComponent(copyText + link));
        sms.addEventListener('click', function () { trackEvent('referral_share', { label: 'sms', meta: { variant: variant } }); });
      }
      var qr = $('[data-refer-qr]');
      if (qr && window.qrcode) qr.innerHTML = qrSVG(link);
      var copy = $('[data-refer-copy]');
      if (copy) copy.addEventListener('click', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(link).then(function () { toast('Referral link copied.'); trackEvent('referral_share', { label: 'copy', meta: { variant: variant } }); }).catch(function () { toast('Long-press the link to copy.', 'info'); });
        else toast('Long-press the link to copy.', 'info');
      });
      // TikTok content kit
      var kit = $('[data-tiktok-kit]');
      if (kit) {
        var angle = kit.querySelector('[data-tiktok-angle]');
        var caption = kit.querySelector('[data-tiktok-caption]');
        var card = kit.querySelector('[data-tiktok-card]');
        function refreshCaption() {
          var tpl = tiktokCaptions[Number(angle.value)] || tiktokCaptions[0];
          var text = tpl.replace('{code}', code) + ' ' + base + '/refer?ref=' + encodeURIComponent(code) + '&src=tiktok-' + variant;
          caption.value = text + '\n\n#Mzigo #ParcelDeliveryKenya #Nairobi #SideHustleKenya';
          card.setAttribute('href', base + '/refer-card?ref=' + encodeURIComponent(code) + '&variant=' + variant);
        }
        angle.addEventListener('change', refreshCaption);
        refreshCaption();
        kit.querySelector('[data-tiktok-copy]').addEventListener('click', function () {
          if (navigator.clipboard) navigator.clipboard.writeText(caption.value).then(function () { toast('TikTok caption copied.'); trackEvent('referral_share', { label: 'tiktok-caption', meta: { variant: variant } }); });
          else toast('Select the caption and copy it.', 'info');
        });
        kit.querySelector('[data-tiktok-share]').addEventListener('click', function () {
          trackEvent('referral_share', { label: 'tiktok', meta: { variant: variant } });
          if (navigator.share) { navigator.share({ title: 'Mzigo.online', text: caption.value }); }
          else if (navigator.clipboard) { navigator.clipboard.writeText(caption.value); toast('Caption copied — paste it in TikTok.', 'info'); }
          else toast('Select the caption to copy it.', 'info');
        });
      }
    }).catch(function () {});
  }

  function initPartnerPosterPage() {
    var root = $('[data-partner-poster]');
    if (!root) return;
    var params = new URLSearchParams(location.search);
    var ref = params.get('ref') || '';
    var program = params.get('program') || 'runner';
    var label = params.get('label') || 'Mzigo partner';
    if (!ref) {
      root.querySelector('[data-poster-title]').textContent = 'Partner code missing';
      root.querySelector('[data-poster-sub]').textContent = 'Generate a poster from Admin → Coupons → Partner QR posters.';
      return;
    }
    var titles = {
      runner: 'Earn with Mzigo.online',
      business: 'Send parcels with Mzigo.online',
      customer: 'Track your parcel with Mzigo.online'
    };
    var subs = {
      runner: 'Scan to join as a runner, pick local errands and get paid by M-Pesa.',
      business: 'Scan to register a workspace, send parcels and track every delivery.',
      customer: 'Scan to track a parcel, pay by M-Pesa at collection and keep your code.'
    };
    root.querySelector('[data-poster-title]').textContent = titles[program] || titles.runner;
    root.querySelector('[data-poster-sub]').textContent = subs[program] || subs.runner;
    root.querySelector('[data-poster-eyebrow]').textContent = label;
    root.querySelector('[data-poster-label]').textContent = label;
    root.querySelector('[data-poster-code]').textContent = ref;
    var link = location.origin + '/refer?ref=' + encodeURIComponent(ref) + '&src=partner-poster&program=' + encodeURIComponent(program);
    var qr = root.querySelector('[data-poster-qr]');
    if (qr && window.qrcode) qr.innerHTML = qrSVG(link);
    var open = $('[data-poster-open]');
    if (open) open.setAttribute('href', link);
    var print = $('[data-poster-print]');
    if (print) print.addEventListener('click', function () { window.print(); });
    var download = $('[data-poster-download]');
    if (download) download.addEventListener('click', function () {
      var title = titles[program] || titles.runner;
      var sub = subs[program] || subs.runner;
      var canvas = document.createElement('canvas');
      canvas.width = 1080; canvas.height = 1350;
      var ctx = canvas.getContext('2d');
      var grad = ctx.createLinearGradient(0, 0, 0, 1350);
      grad.addColorStop(0, '#31461c'); grad.addColorStop(0.62, '#3c5223'); grad.addColorStop(1, '#1f2c12');
      ctx.fillStyle = grad; ctx.fillRect(0, 0, 1080, 1350);
      ctx.fillStyle = '#12ff6b'; ctx.font = '800 54px system-ui, sans-serif'; ctx.fillText('MZIGO', 74, 128);
      ctx.fillStyle = '#f2ffe9'; ctx.fillText('.online', 300, 128);
      ctx.fillStyle = '#c2f8cb'; ctx.font = '700 26px system-ui, sans-serif'; ctx.fillText(String(label || 'Mzigo partner').toUpperCase().slice(0, 42), 74, 226);
      ctx.fillStyle = '#f2ffe9'; ctx.font = '900 76px system-ui, sans-serif';
      var words = String(title).split(' ');
      var line = '';
      var y = 340;
      words.forEach(function (word) {
        var test = line ? line + ' ' + word : word;
        if (ctx.measureText(test).width > 900 && line) {
          ctx.fillText(line, 74, y); y += 86; line = word;
        } else { line = test; }
      });
      if (line) { ctx.fillText(line, 74, y); y += 70; }
      ctx.fillStyle = 'rgba(242,255,233,.86)'; ctx.font = '400 30px system-ui, sans-serif';
      var subLine = '';
      String(sub).split(' ').forEach(function (word) {
        var test = subLine ? subLine + ' ' + word : word;
        if (ctx.measureText(test).width > 900 && subLine) { ctx.fillText(subLine, 74, y); y += 42; subLine = word; }
        else { subLine = test; }
      });
      if (subLine) { ctx.fillText(subLine, 74, y); }
      var svg = qrSVG(link);
      if (!svg) { toast('Could not generate the QR code.', 'error'); return; }
      var img = new Image();
      var blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
      var url = URL.createObjectURL(blob);
      img.onload = function () {
        ctx.fillStyle = '#fff'; ctx.fillRect(70, 720, 500, 500);
        ctx.drawImage(img, 90, 740, 460, 460);
        ctx.fillStyle = '#f2ffe9'; ctx.font = '800 44px ui-monospace, monospace'; ctx.fillText(ref, 620, 810);
        ctx.fillStyle = 'rgba(242,255,233,.75)'; ctx.font = '500 26px system-ui, sans-serif'; ctx.fillText('Scan to join with my code', 620, 870);
        ctx.fillStyle = 'rgba(242,255,233,.55)'; ctx.font = '500 24px system-ui, sans-serif';
        ctx.fillText('mzigo.online  ·  hello@mzigo.online  ·  0800 211 211', 74, 1290);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (out) {
          var a = document.createElement('a');
          a.href = URL.createObjectURL(out); a.download = 'mzigo-partner-poster-' + String(ref).replace(/[^A-Za-z0-9_-]/g, '') + '.png'; a.click();
        }, 'image/png');
      };
      img.src = url;
    });
  }

  function initReferCardPage() {
    var card = $('[data-story-card]');
    if (!card) return;
    var params = new URLSearchParams(location.search);
    var ref = params.get('ref') || 'MZIGO';
    var codeEl = card.querySelector('[data-story-code]');
    if (codeEl) codeEl.textContent = ref;
    var link = location.origin + '/refer?ref=' + encodeURIComponent(ref) + '&src=tiktok';
    var qr = card.querySelector('[data-story-qr]');
    if (qr && window.qrcode) qr.innerHTML = qrSVG(link);
    var download = $('[data-story-download]');
    if (download) download.addEventListener('click', function () {
      var canvas = document.createElement('canvas');
      canvas.width = 1080; canvas.height = 1920;
      var ctx = canvas.getContext('2d');
      var grad = ctx.createLinearGradient(0, 0, 0, 1920);
      grad.addColorStop(0, '#31461c'); grad.addColorStop(0.6, '#3c5223'); grad.addColorStop(1, '#1f2c12');
      ctx.fillStyle = grad; ctx.fillRect(0, 0, 1080, 1920);
      ctx.fillStyle = '#12ff6b'; ctx.font = '800 54px system-ui, sans-serif'; ctx.fillText('MZIGO', 90, 210);
      ctx.fillStyle = '#f2ffe9'; ctx.font = '800 46px system-ui, sans-serif'; ctx.fillText('.online', 310, 210);
      ctx.fillStyle = '#c2f8cb'; ctx.font = '800 30px system-ui, sans-serif'; ctx.fillText('REFER & EARN', 90, 380);
      ctx.fillStyle = '#f2ffe9'; ctx.font = '900 108px system-ui, sans-serif';
      ctx.fillText('Send parcels.', 90, 560); ctx.fillText('Earn rewards.', 90, 690);
      ctx.fillStyle = 'rgba(242,255,233,.82)'; ctx.font = '400 34px system-ui, sans-serif';
      ctx.fillText('Join with my code and send across Kenya.', 90, 790);
      var svg = qrSVG(link);
      if (svg) {
        var img = new Image();
        var blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        img.onload = function () {
          ctx.fillStyle = '#fff'; ctx.fillRect(90, 980, 520, 520);
          ctx.drawImage(img, 110, 1000, 480, 480);
          ctx.fillStyle = '#f2ffe9'; ctx.font = '800 62px ui-monospace, monospace'; ctx.fillText(ref, 90, 1660);
          ctx.fillStyle = 'rgba(242,255,233,.7)'; ctx.font = '600 26px system-ui, sans-serif'; ctx.fillText('SCAN · SIGN UP · SEND YOUR FIRST PARCEL', 90, 1760);
          URL.revokeObjectURL(url);
          canvas.toBlob(function (out) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(out); a.download = 'mzigo-refer-' + ref + '.png'; a.click();
          }, 'image/png');
        };
        img.src = url;
      } else { toast('Could not generate QR — try printing instead.', 'error'); }
    });
    var print = $('[data-story-print]');
    if (print) print.addEventListener('click', function () { window.print(); });
  }

  function initRateRunnerPage() {
    var mount = $('[data-rate-runner]');
    if (!mount) return;
    var token = new URLSearchParams(location.search).get('token') || '';
    if (!token) {
      mount.innerHTML = '<div class="mz-card__title">Rating link missing</div><p class="mz-hint">Open the rating link from your delivery notification.</p>';
      return;
    }
    mount.innerHTML =
      '<div class="mz-card__title">How was your delivery?</div>' +
      '<p class="mz-hint">Your rating helps us reward reliable runners.</p>' +
      '<form data-rate-form><div class="mz-row" style="gap:8px;margin:10px 0;" data-rate-stars>' +
        [1,2,3,4,5].map(function (n) { return '<button type="button" class="mz-tab" data-rate-star="' + n + '" aria-label="' + n + ' stars">' + n + ' ★</button>'; }).join('') +
      '</div>' +
      '<label class="mz-field"><span class="mz-label">Comment (optional)</span><textarea class="mz-input" name="comment" rows="3" maxlength="500" placeholder="Anything we should know?"></textarea></label>' +
      '<button class="mz-btn" type="submit">Submit rating</button></form>';
    var rating = 0;
    mount.querySelectorAll('[data-rate-star]').forEach(function (b) {
      b.addEventListener('click', function () {
        rating = Number(b.getAttribute('data-rate-star'));
        mount.querySelectorAll('[data-rate-star]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
      });
    });
    var form = mount.querySelector('[data-rate-form]');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!rating) { toast('Choose a star rating first.', 'error'); return; }
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('growth.php', { method: 'POST', body: { action: 'rate_runner', token: token, rating: rating, comment: formValue(form, 'comment') } })
        .then(function () { form.innerHTML = '<div class="mz-alert">Thanks — your rating was submitted.</div>'; })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function initContactPage() {
    var form = $('[data-contact-form]');
    if (!form) return;
    var params = new URLSearchParams(location.search);
    var ref = params.get('ref') || '';
    var topic = params.get('topic') || '';
    if (ref || topic) {
      var subject = form.querySelector('[name="subject"]');
      var message = form.querySelector('[name="message"]');
      if (subject && !subject.value) {
        subject.value = topic === 'payment' ? 'Payment support' : 'Parcel support';
      }
      if (message && !message.value) {
        message.value = (ref ? 'Parcel reference: ' + ref + '\n\n' : '') + 'Please help me with ';
      }
    }
    var result = form.querySelector('[data-contact-result]');
    var submit = form.querySelector('[data-contact-submit]');

    /* Support address comes from the backend config, not the static page. */
    api('contact.php').then(function (res) {
      var link = $('[data-contact-support]');
      if (link && res.support_email) {
        link.textContent = res.support_email;
        link.setAttribute('href', 'mailto:' + res.support_email);
      }
    }).catch(function () {});

    function show(kind, html) {
      result.hidden = false;
      result.className = 'mz-alert' + (kind ? ' mz-alert--' + kind : '');
      result.innerHTML = html;
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (submit.disabled) return;
      if (formValue(form, 'company') !== '') return; // honeypot

      var name = formValue(form, 'name');
      var email = formValue(form, 'email');
      var message = formValue(form, 'message');
      if (name === '' || email === '' || message === '') {
        show('error', 'Add your name, email and message before sending.');
        return;
      }
      if (message.length < 10) {
        show('error', 'Please add a little more detail (at least 10 characters).');
        return;
      }

      submit.disabled = true;
      submit.textContent = 'Sending…';
      api('contact.php', { method: 'POST', body: {
        name: name,
        email: email,
        phone: formValue(form, 'phone'),
        subject: formValue(form, 'subject') || 'General enquiry',
        message: message,
        company: ''
      }}).then(function () {
        form.reset();
        show('ok', '<strong>Message sent.</strong> We will reply to ' + esc(email) + ' within one business day.');
        toast('Message sent — we will reply by email.');
      }).catch(function (err) {
        show('error', esc(err.message));
      }).then(function () {
        submit.disabled = false;
        submit.textContent = 'Send message';
      });
    });
  }

  /* ----------------------------------------------------------------- claims */
  function initClaimsPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Fclaims'; return; }
    if (APP.user.role === 'freelancer') { window.location.href = '/freelancer'; return; }
    if (APP.user.role === 'customer') { window.location.href = '/customer'; return; }
    if (!APP.tenant) { window.location.href = '/profile'; return; }
    initAppNav();
    loadClaims();
  }

  function loadClaims() {
    var host = $('[data-app="claims"]');
    if (!host) return;
    host.innerHTML = '<div class="mz-saas-card">Loading claims…</div>';
    api('claims.php').then(function (data) {
      renderClaims(host, data);
    }).catch(function (err) {
      host.innerHTML = '<div class="mz-saas-card"><div class="mz-card__title">Could not load claims</div><p class="mz-hint">' + esc(err.message) + '</p></div>';
    });
  }

  function loadClaimsNotifications(host) {
    var mount = host.querySelector('[data-claims-notifications]');
    if (!mount) return;
    api('notifications.php').then(function (res) {
      var rows = res.notifications || [];
      var unread = res.unread || 0;
      var body = rows.slice(0, 8).map(function (n) {
        return '<div style="border-bottom:1px solid rgba(60,82,35,.10);padding:7px 0;' + (n.read_at ? '' : 'font-weight:600;') + '">' +
          '<div style="font-size:13px;">' + esc(n.subject || n.template || 'Notification') + '</div>' +
          '<div class="mz-hint" style="white-space:pre-line;">' + esc(n.body || '') + '</div>' +
          '<div class="mz-hint">' + esc(fmtDate(n.created_at)) + (n.read_at ? ' · read' : ' · unread') + '</div></div>';
      }).join('') || '<div class="mz-hint">No notifications yet.</div>';
      mount.innerHTML = '<div class="mz-saas-card"><div class="mz-row"><div class="mz-card__title" style="margin:0;">Notifications' +
        (unread ? ' <span class="mz-badge mz-badge--warn">' + unread + ' unread</span>' : '') + '</div>' +
        (unread ? '<button type="button" class="mz-tab" data-claims-notifications-read>Mark all read</button>' : '') + '</div>' + body + '</div>';
      var read = mount.querySelector('[data-claims-notifications-read]');
      if (read) read.addEventListener('click', function () {
        read.disabled = true;
        api('notifications.php', { method: 'POST', body: { action: 'mark_all_read' } })
          .then(function () { loadClaims(); }).catch(function (err) { toast(err.message, 'error'); read.disabled = false; });
      });
    }).catch(function () { mount.innerHTML = ''; });
  }

  function renderClaims(host, data) {
    var statusBadge = { open: 'warn', under_review: 'warn', approved: 'ok', rejected: '', paid: 'ok', withdrawn: '' };
    var claims = data.claims || [];
    var list = claims.length ? claims.map(function (c) {
      return '<div class="mz-mobile-card" style="margin-bottom:10px;">' +
        '<div class="mz-mobile-card__top"><span class="mz-mono">' + esc(c.ref) + '</span>' +
          '<span style="display:flex;gap:6px;flex-wrap:wrap;">' +
          (c.claimant_role === 'customer' ? '<span class="mz-badge mz-badge--warn">customer reported</span>' : '') +
          '<span class="mz-badge ' + (statusBadge[c.status] ? 'mz-badge--' + statusBadge[c.status] : '') + '">' + esc(c.status.replace('_', ' ')) + '</span></span></div>' +
        '<div class="mz-mobile-card__route">' + esc((c.tracking || c.parcel_id) + ' · ' + c.type) + '</div>' +
        '<div class="mz-mobile-card__meta">' + esc(c.description) + '</div>' +
        (c.risk_score ? '<div class="mz-mobile-card__meta">Risk score ' + c.risk_score + (c.claimant_role === 'customer' ? ' · reported by receiver' : '') + '</div>' : '') +
        (c.evidence ? '<div class="mz-mobile-card__meta" style="word-break:break-word;">Evidence: ' + esc(c.evidence) + '</div>' : '') +
        '<div class="mz-mobile-card__bottom"><span class="mz-hint">' + esc(fmtDate(c.created_at)) + (c.estimated_value_kes ? ' · claimed ' + money(c.estimated_value_kes) : '') +
          (c.attachment_count ? ' · ' + c.attachment_count + ' file(s)' : '') +
          (['open', 'under_review'].indexOf(c.status) !== -1 && c.sla_due_at ? (new Date(c.sla_due_at) < new Date() ? ' · <strong>SLA overdue</strong>' : ' · SLA due ' + esc(fmtDate(c.sla_due_at))) : '') +
          (c.status === 'approved' && c.payout_hold_until ? ' · payout releases ' + esc(fmtDate(c.payout_hold_until)) : '') +
          (c.payout_ref ? ' · paid ' + esc(c.payout_ref) + (c.paid_at ? ' on ' + esc(fmtDate(c.paid_at)) : '') : '') +
          (c.status === 'reversed' ? ' · reversed ' + esc(c.reversal_ref || '') : '') +
          (c.recovery_status && c.recovery_status !== 'none' ? ' · recovery ' + esc(c.recovery_status) : '') +
          (c.resolution_note ? ' · ' + esc(c.resolution_note) : '') + '</span>' +
          '<span style="display:flex;gap:10px;flex-wrap:wrap;">' +
          '<button type="button" class="mz-link" data-claim-detail="' + c.id + '">Details</button>' +
          (data.can_manage && c.status === 'rejected' && (c.appeal_count || 0) < 1 && c.appeal_deadline && new Date(c.appeal_deadline) > new Date()
            ? '<button type="button" class="mz-link" data-claim-appeal="' + c.id + '">Appeal</button>' : '') +
          (data.can_manage && ['open', 'under_review'].indexOf(c.status) !== -1
            ? '<button type="button" class="mz-link" data-withdraw-claim="' + c.id + '">Withdraw</button>' : '') + '</span></div></div>';
    }).join('') : '<div class="mz-empty-note" style="text-align:center;padding:22px;">' +
      '<strong>No claims yet.</strong><br>If a parcel arrives lost, damaged or delayed, open a claim from its tracking page.' +
      '<div style="margin-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
        '<a class="mz-btn mz-btn--outline" href="/orders">View orders</a>' +
        '<a class="mz-tab" href="/help">How claims work</a>' +
      '</div></div>';

    var form = data.can_manage
      ? '<div class="mz-saas-card" style="margin-bottom:16px;"><div class="mz-card__title">File a claim</div>' +
        '<form class="mz-stack" data-claim-form>' +
          '<label class="mz-field"><span class="mz-label">Parcel</span><select class="mz-input" name="parcel_id" required>' +
            (data.parcels || []).map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(p.tracking + ' · ' + p.status) + '</option>'; }).join('') +
          '</select></label>' +
          '<div class="mz-form-2col">' +
            '<label class="mz-field"><span class="mz-label">Issue</span><select class="mz-input" name="type">' +
              '<option value="lost">Lost</option><option value="damaged">Damaged</option><option value="delayed">Delayed</option></select></label>' +
            '<label class="mz-field"><span class="mz-label">Estimated value (KES)</span><input class="mz-input" name="estimated_value_kes" type="number" min="0" placeholder="Optional"></label>' +
          '</div>' +
          '<label class="mz-field"><span class="mz-label">What happened?</span><textarea class="mz-input" name="description" rows="3" placeholder="Describe the issue, dates and any evidence references" required></textarea></label>' +
          '<label class="mz-field"><span class="mz-label">Evidence references (required for loss/damage)</span><input class="mz-input" name="evidence" placeholder="Photo/document links, receipt numbers, carrier report…"></label>' +
          '<label class="mz-field"><span class="mz-label">Evidence files (optional, JPG/PNG/PDF, max 5MB each)</span><input class="mz-input" name="files" type="file" accept=".jpg,.jpeg,.png,.pdf" multiple></label>' +
          '<div class="mz-plan-hint">Claims are limited to the declared value and tier cap. Loss claims after 7 days, damage within 24h of collection, delay after 3 days; valid for 30 days. Approved payouts are held for 48 hours before release.</div>' +
          '<button type="submit" class="mz-btn">Submit claim</button>' +
        '</form></div>'
      : '<div class="mz-alert" style="margin-bottom:16px;">Only workspace owners and admins can file claims.</div>';

    host.innerHTML = tenantStatusBanner() + '<div data-claims-notifications style="margin-bottom:16px;"></div>' + form +
      '<div class="mz-saas-card"><div class="mz-row" style="margin-bottom:10px;"><div class="mz-card__title" style="margin:0;">My claims</div>' +
        '<span class="mz-hint">' + claims.length + ' claim(s)</span></div>' + list + '</div>';

    loadClaimsNotifications(host);
    var claimForm = host.querySelector('[data-claim-form]');
    if (claimForm) claimForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = claimForm.querySelector('[type="submit"]');
      btn.disabled = true;
      api('claims.php', { method: 'POST', body: {
        action: 'create_claim',
        parcel_id: formValue(claimForm, 'parcel_id'),
        type: formValue(claimForm, 'type'),
        description: formValue(claimForm, 'description'),
        evidence: formValue(claimForm, 'evidence'),
        estimated_value_kes: formValue(claimForm, 'estimated_value_kes')
      } }).then(function (res) {
        var files = claimForm.querySelector('[name="files"]') ? claimForm.querySelector('[name="files"]').files : null;
        var uploads = [];
        if (res.claim_id && files && files.length) {
          for (var i = 0; i < files.length; i++) {
            var fd = new FormData();
            fd.append('claim_id', res.claim_id);
            fd.append('file', files[i]);
            uploads.push(fetch('/api/claim-upload.php', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'X-CSRF-Token': APP.csrf }, body: fd
            }));
          }
        }
        Promise.all(uploads).then(function () {
          toast(res.message + (files && files.length ? ' Evidence uploaded.' : ''));
          loadClaims();
        }).catch(function () { toast('Claim saved, but some evidence failed to upload.', 'error'); loadClaims(); });
      }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    host.querySelectorAll('[data-withdraw-claim]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!window.confirm('Withdraw this claim?')) return;
        api('claims.php', { method: 'POST', body: { action: 'withdraw_claim', claim_id: Number(btn.getAttribute('data-withdraw-claim')) } })
          .then(function (res) { toast(res.message || 'Claim withdrawn.'); loadClaims(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    });
    host.querySelectorAll('[data-claim-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () { openClaimDetail(Number(btn.getAttribute('data-claim-detail'))); });
    });
    host.querySelectorAll('[data-claim-appeal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var reason = window.prompt('Why should this rejection be reviewed again?');
        if (!reason) return;
        api('claims.php', { method: 'POST', body: { action: 'appeal_claim', claim_id: Number(btn.getAttribute('data-claim-appeal')), reason: reason } })
          .then(function (res) { toast(res.message || 'Appeal submitted.'); loadClaims(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    });
  }

  function openClaimDetail(claimId) {
    api('claim-detail.php?claim_id=' + claimId).then(function (data) {
      var c = data.claim;
      var eventLabels = {
        created: 'Claim submitted', under_review: 'Under review', first_approved: 'First approval',
        approved: 'Approved', rejected: 'Rejected', paid: 'Payout released', reversed: 'Payout reversed',
        appealed: 'Appeal submitted', withdrawn: 'Claim withdrawn', evidence_uploaded: 'Evidence uploaded',
        recovery_requested: 'Recovery requested', recovery_recovered: 'Recovery received', recovery_rejected: 'Recovery rejected'
      };
      var events = data.events || [];
      var ev = events.map(function (e, i) {
        var last = i === events.length - 1;
        return '<div class="mz-timeline__item ' + (last ? 'is-current' : '') + '">' +
          '<span class="mz-timeline__dot">' + (last ? '' : ICON.tick) + '</span>' +
          '<div class="mz-timeline__label">' + esc(eventLabels[e.event] || e.event.replace(/_/g, ' ')) + '</div>' +
          '<div class="mz-timeline__meta">' + esc(fmtDate(e.created_at)) + (e.actor_name ? ' · ' + esc(e.actor_name) : '') + '</div>' +
          (e.note ? '<div class="mz-timeline__note">' + esc(e.note) + '</div>' : '') +
        '</div>';
      }).join('') || '<div class="mz-hint">No events yet.</div>';
      var scanRows = data.scans || [];
      var scans = scanRows.map(function (s, i) {
        var last = i === scanRows.length - 1;
        return '<div class="mz-timeline__item ' + (last ? 'is-current' : '') + '">' +
          '<span class="mz-timeline__dot">' + (last ? '' : ICON.tick) + '</span>' +
          '<div class="mz-timeline__label">' + esc(s.label || s.status) + '</div>' +
          '<div class="mz-timeline__meta">' + esc(s.location || '') + (s.scanned_at ? ' · ' + esc(fmtDate(s.scanned_at)) : '') + '</div>' +
          (s.note ? '<div class="mz-timeline__note">' + esc(s.note) + '</div>' : '') +
        '</div>';
      }).join('') || '<div class="mz-hint">No scans.</div>';
      var files = (data.attachments || []).map(function (a) {
        return '<div><a class="mz-link" href="/api/claim-file.php?id=' + a.id + '" target="_blank" rel="noopener">' + esc(a.filename) + '</a> <span class="mz-hint">(' + Math.round(a.size_bytes / 1024) + ' KB)</span></div>';
      }).join('') || '<div class="mz-hint">No files uploaded.</div>';
      var messages = (data.messages || []).map(function (m) {
        return '<div style="border-bottom:1px solid rgba(60,82,35,.10);padding:7px 0;">' +
          '<div style="font-size:12px;font-weight:600;">' + esc(m.sender_name || m.sender_role) + ' · ' + esc(m.sender_role) +
            ' <span class="mz-hint">' + esc(fmtDate(m.created_at)) + '</span></div>' +
          '<div style="font-size:13px;white-space:pre-line;">' + esc(m.message) + '</div></div>';
      }).join('') || '<div class="mz-hint">No messages yet.</div>';
      var overlay = document.createElement('div');
      overlay.className = 'mz-modal';
      overlay.innerHTML = '<div class="mz-modal__panel"><div class="mz-row" style="align-items:flex-start;">' +
        '<div><div class="mz-card__title" style="margin:0;">Claim ' + esc(c.ref) + '</div><div class="mz-hint">' + esc(c.type) + ' · ' + esc(c.status.replace('_', ' ')) +
        ' · created ' + esc(fmtDate(c.created_at)) + (c.sla_due_at ? ' · SLA ' + esc(fmtDate(c.sla_due_at)) : '') + '</div></div>' +
        '<button type="button" class="mz-tab" data-close-detail>Close</button></div>' +
        '<div class="mz-kv"><dt>Parcel</dt><dd>' + esc(c.tracking || '—') + ' · ' + esc(c.parcel_status || '') + '</dd></div>' +
        '<div class="mz-kv"><dt>Description</dt><dd>' + esc(c.description) + '</dd></div>' +
        '<div class="mz-kv"><dt>Evidence</dt><dd>' + esc(c.evidence || '—') + '</dd></div>' +
        '<div class="mz-kv"><dt>Claimed</dt><dd>' + (c.estimated_value_kes ? money(c.estimated_value_kes) : '—') + ' · cap ' + (c.value_kes ? money(c.value_kes) : '—') + '</dd></div>' +
        '<div class="mz-kv"><dt>Decision</dt><dd>' + esc(c.resolution_note || '—') + '</dd></div>' +
        '<div class="mz-kv"><dt>Approvals</dt><dd>' + (c.first_approved_name ? esc(c.first_approved_name) + ' (first)' : '—') + ' · ' + (c.approved_name ? esc(c.approved_name) + ' (final)' : '—') + '</dd></div>' +
        (c.payout_hold_until ? '<div class="mz-kv"><dt>Payout hold</dt><dd>' + esc(fmtDate(c.payout_hold_until)) + '</dd></div>' : '') +
        (c.payout_ref ? '<div class="mz-kv"><dt>Payout</dt><dd>' + esc(c.payout_ref) + ' · ' + money(c.payout_amount_kes || 0) + '</dd></div>' : '') +
        (c.reversal_ref ? '<div class="mz-kv"><dt>Reversal</dt><dd>' + esc(c.reversal_ref) + '</dd></div>' : '') +
        (c.recovery_status && c.recovery_status !== 'none' ? '<div class="mz-kv"><dt>Recovery</dt><dd>' + esc(c.recovery_status) + ' · ' + esc(c.recovery_ref || '') + ' · ' + (c.recovery_amount_kes ? money(c.recovery_amount_kes) : '—') + '</dd></div>' : '') +
        '<div class="mz-kv"><dt>Claimant / risk</dt><dd>' + esc(c.claimant_role || 'merchant') + ' · risk ' + (c.risk_score || 0) +
          (Array.isArray(c.risk_flags) && c.risk_flags.length ? ' · ' + esc(c.risk_flags.join(', ')) : '') + '</dd></div>' +
        ((data.related_claims || []).length ? '<div style="margin-top:10px;"><div class="mz-cap">Linked claims</div>' +
          data.related_claims.map(function (r) { return '<div class="mz-hint">' + esc(r.ref) + ' · ' + esc(r.tracking || '') + ' · ' + esc(r.status) + (r.risk_score ? ' · risk ' + r.risk_score : '') + '</div>'; }).join('') + '</div>' : '') +
        ((data.payouts || []).length ? '<div style="margin-top:12px;"><div class="mz-cap">Payout rail history</div>' + data.payouts.map(function (p) { return '<div class="mz-hint">' + esc(p.provider) + ' · ' + esc(p.mode) + ' · ' + money(p.amount_kes) + ' · ' + esc(p.status) + (p.provider_ref ? ' · ' + esc(p.provider_ref) : '') + (p.error ? ' · ' + esc(p.error) : '') + '</div>'; }).join('') + '</div>' : '') +
        ((data.recoveries || []).length ? '<div style="margin-top:12px;"><div class="mz-cap">Recovery rail history</div>' + data.recoveries.map(function (r) { return '<div class="mz-hint">' + esc(r.provider) + ' · ' + esc(r.mode) + ' · ' + esc(r.status) + (r.amount_kes ? ' · ' + money(r.amount_kes) : '') + (r.external_ref ? ' · ' + esc(r.external_ref) : '') + '</div>'; }).join('') + '</div>' : '') +
        '<div style="margin-top:14px;"><div class="mz-cap">Messages</div><div style="max-height:220px;overflow:auto;">' + messages + '</div>' +
          '<form class="mz-stack" data-claim-message-form style="margin-top:10px;">' +
            '<textarea class="mz-input" name="message" rows="2" placeholder="Write a message to the other participants…" required></textarea>' +
            '<button type="submit" class="mz-btn">Send message</button>' +
          '</form>' +
          '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">' +
            '<input type="file" name="claim_file" accept=".jpg,.jpeg,.png,.pdf">' +
            '<button type="button" class="mz-tab" data-claim-file-upload>Upload evidence file</button>' +
            '<span class="mz-hint" data-claim-upload-hint></span>' +
          '</div></div>' +
        '<div class="mz-saas-grid mz-saas-grid--2" style="margin-top:14px;"><div><div class="mz-cap">Claim timeline</div><div class="mz-timeline">' + ev + '</div></div><div><div class="mz-cap">Parcel scan trail</div><div class="mz-timeline">' + scans + '</div></div></div>' +
        '<div style="margin-top:12px;"><div class="mz-cap">Evidence files</div>' + files + '</div></div>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay || e.target.closest('[data-close-detail]')) overlay.remove();
      });
      var messageForm = overlay.querySelector('[data-claim-message-form]');
      if (messageForm) messageForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = messageForm.querySelector('[type="submit"]');
        btn.disabled = true;
        api('claim-message.php', { method: 'POST', body: { claim_id: claimId, message: formValue(messageForm, 'message') } })
          .then(function () { overlay.remove(); openClaimDetail(claimId); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
      var uploadBtn = overlay.querySelector('[data-claim-file-upload]');
      if (uploadBtn) uploadBtn.addEventListener('click', function () {
        var input = overlay.querySelector('[name="claim_file"]');
        var hint = overlay.querySelector('[data-claim-upload-hint]');
        if (!input || !input.files || !input.files.length) { toast('Choose a file to upload.', 'error'); return; }
        var fd = new FormData();
        fd.append('claim_id', claimId);
        fd.append('file', input.files[0]);
        uploadBtn.disabled = true;
        if (hint) hint.textContent = 'Uploading…';
        fetch('/api/claim-upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': APP.csrf }, body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) { if (data.ok === false) throw new Error((data.error && data.error.message) || 'Upload failed.'); overlay.remove(); openClaimDetail(claimId); })
          .catch(function (err) { toast(err.message, 'error'); uploadBtn.disabled = false; if (hint) hint.textContent = ''; });
      });
    }).catch(function (err) { toast(err.message, 'error'); });
  }

  /* ------------------------------------------------------------- orders page */
  var ordersState = { tab: 'parcels', q: '', status: '', from: '', to: '', page: 1, per_page: 10 };
  var ordersData = { rows: [] };

  function initOrdersPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Forders'; return; }
    if (APP.user.role === 'freelancer') { window.location.href = '/freelancer'; return; }
    if (APP.user.role === 'customer') { window.location.href = '/customer'; return; }
    if (!APP.tenant) {
      initAppNav();
      renderOrdersShell();
      loadOrders();
      return;
    }
    initAppNav();
    var hashTab = (window.location.hash || '').replace('#', '');
    if (hashTab === 'errands') ordersState.tab = 'errands';
    renderOrdersShell();
    loadOrders();
  }

  function renderOrdersShell() {
    var host = $('[data-app="orders"]');
    if (!host) return;
    host.innerHTML =
      tenantStatusBanner() +
      '<div class="mz-saas-card" style="margin-bottom:16px;">' +
        '<div class="mz-row" style="margin-bottom:12px;">' +
          '<div class="mz-tabs mz-tabs--2" data-orders-tabs style="max-width:320px;">' +
            '<button type="button" class="mz-tab" data-orders-tab="parcels">Parcels</button>' +
            '<button type="button" class="mz-tab" data-orders-tab="errands">Errands</button>' +
          '</div>' +
          '<div class="mz-orders-toolbar">' +
            '<input class="mz-input" data-orders-search placeholder="Search tracking, receiver, route…" aria-label="Search orders">' +
            '<select class="mz-input" data-orders-status style="width:auto;" aria-label="Filter by status"></select>' +
            '<input class="mz-input" type="date" data-orders-from style="width:auto;" aria-label="From date">' +
            '<input class="mz-input" type="date" data-orders-to style="width:auto;" aria-label="To date">' +
            '<button type="button" class="mz-tab" data-orders-export>Export CSV</button>' +
          '</div>' +
        '</div>' +
        '<div data-orders-chips class="mz-orders-chips"></div>' +
      '</div>' +
      '<div class="mz-saas-card" data-orders-table></div>';

    var tabs = host.querySelectorAll('[data-orders-tab]');
    tabs.forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-orders-tab') === ordersState.tab);
      btn.addEventListener('click', function () {
        ordersState.tab = btn.getAttribute('data-orders-tab');
        ordersState.status = '';
        ordersState.page = 1;
        tabs.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        loadOrders();
      });
    });

    var statusSel = host.querySelector('[data-orders-status]');
    function statusOptions() {
      var keys = Object.keys(STATUS_META);
      statusSel.innerHTML = '<option value="">All statuses</option>' + keys.map(function (k) {
        return '<option value="' + k + '">' + esc(STATUS_META[k].label) + '</option>';
      }).join('');
      statusSel.value = ordersState.status;
    }
    statusOptions();

    var search = host.querySelector('[data-orders-search]');
    var timer = null;
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        ordersState.q = search.value.trim();
        ordersState.page = 1;
        loadOrders();
      }, 300);
    });
    statusSel.addEventListener('change', function () {
      ordersState.status = statusSel.value;
      ordersState.page = 1;
      loadOrders();
    });
    var fromInput = host.querySelector('[data-orders-from]');
    var toInput = host.querySelector('[data-orders-to]');
    [fromInput, toInput].forEach(function (input) {
      input.addEventListener('change', function () {
        ordersState.from = fromInput.value;
        ordersState.to = toInput.value;
        ordersState.page = 1;
        loadOrders();
      });
    });
    host.querySelector('[data-orders-export]').addEventListener('click', exportOrdersCsv);
  }

  function ordersQuery(extra) {
    var params = new URLSearchParams();
    params.set('tab', ordersState.tab);
    if (ordersState.q) params.set('q', ordersState.q);
    if (ordersState.status) params.set('status', ordersState.status);
    if (ordersState.from) params.set('from', ordersState.from);
    if (ordersState.to) params.set('to', ordersState.to);
    if (extra) Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
    return params.toString();
  }

  function loadOrders() {
    var host = $('[data-app="orders"]');
    var table = host.querySelector('[data-orders-table]');
    table.innerHTML = '<div class="mz-saas-card">Loading orders…</div>';
    api('orders.php?' + ordersQuery({ page: ordersState.page, per_page: ordersState.per_page })).then(function (res) {
      if (res.workspace_required) {
        table.innerHTML = '<div class="mz-saas-card" style="text-align:center;padding:26px;">' +
          '<div class="mz-card__title" style="margin:0 0 6px;">Orders are workspace-only</div>' +
          '<p class="mz-hint" style="margin:0 0 14px;">Choose a business workspace to see its orders. Platform-wide oversight lives in the Admin console.</p>' +
          (APP.user && APP.user.role === 'platform_admin' ? '<a class="mz-btn" href="/admin">Open Admin console</a>' : '<a class="mz-btn" href="/signup">Create a workspace</a>') +
          '</div>';
        return;
      }
      ordersData.rows = res.rows || [];
      renderOrdersTable(table, res);
      renderOrdersChips(host.querySelector('[data-orders-chips]'), res);
      var statusSel = host.querySelector('[data-orders-status]');
      if (statusSel) statusSel.value = ordersState.status;
    }).catch(function (err) {
      table.innerHTML = '<div class="mz-saas-card"><div class="mz-card__title">Could not load orders</div><p class="mz-hint">' + esc(err.message) + '</p></div>';
    });
  }

  function renderOrdersChips(chips, res) {
    if (!chips) return;
    var items = [['', 'All', res.total || 0]].concat(Object.keys(res.counts || {}).map(function (k) {
      return [k, (STATUS_META[k] && STATUS_META[k].label) || k, res.counts[k]];
    }));
    chips.innerHTML = items.map(function (it) {
      var active = ordersState.status === it[0];
      return '<button type="button" class="mz-tab' + (active ? ' is-active' : '') + '" data-orders-chip="' + esc(it[0]) + '" style="font-size:12px;">' +
        esc(it[1]) + ' <span class="mz-orders-chip__count">' + it[2] + '</span></button>';
    }).join('');
    chips.querySelectorAll('[data-orders-chip]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        ordersState.status = btn.getAttribute('data-orders-chip');
        ordersState.page = 1;
        loadOrders();
      });
    });
  }

  function renderOrdersTable(table, res) {
    var isParcels = res.tab === 'parcels';
    var head = isParcels
      ? '<tr><th>Tracking</th><th>Route</th><th>Carrier</th><th>Receiver</th><th>Status</th><th>Fee</th><th>Payment</th><th>Date</th><th></th></tr>'
      : '<tr><th>Ref</th><th>Route</th><th>Carrier</th><th>Recipient</th><th>Status</th><th>Fee</th><th>Date</th><th></th></tr>';
    var body = (res.rows || []).length ? res.rows.map(function (r, index) {
      var st = STATUS_META[r.status] || STATUS_META.CREATED;
      var ref = isParcels ? r.tracking : r.ref;
      var route = isParcels ? (r.from + ' → ' + r.to) : (r.pickup + ' → ' + r.dropoff);
      var who = isParcels ? (r.receiver + '<div style="font-size:10px;color:var(--mz-muted-fg);">' + esc(r.receiver_phone || '') + '</div>') : r.recipient;
      var pay = isParcels
        ? '<span class="mz-badge ' + (r.payment_status === 'paid' ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + esc(r.payment_status) + '</span>'
        : esc(r.payment || '');
      var canManageOrders = !!(APP.tenant && ['owner', 'admin'].indexOf(APP.tenant.role) !== -1);
      var orderAction = '';
      if (isParcels && canManageOrders) {
        if (['CREATED', 'AT_ORIGIN_POINT'].indexOf(r.status) !== -1) {
          orderAction += ' · <button type="button" class="mz-link" data-order-action="cancel_parcel" data-order-ref="' + esc(ref) + '">Cancel</button>';
        }
        if (['READY_FOR_COLLECTION', 'ARRIVED_DEST_POINT'].indexOf(r.status) !== -1) {
          orderAction += ' · <button type="button" class="mz-link" data-order-action="request_return" data-order-ref="' + esc(ref) + '">Return</button>';
        }
      }
      var advanceable = ['CREATED', 'AT_ORIGIN_POINT', 'HANDED_TO_TRANSPORTER', 'IN_TRANSIT', 'ARRIVED_DEST_POINT'];
      if (isParcels && canManageOrders && advanceable.indexOf(r.status) !== -1) {
        orderAction += ' · <button type="button" class="mz-link" data-order-action="advance_parcel" data-order-ref="' + esc(ref) + '">Advance</button>';
      }
      var actions = '<a class="mz-link" href="/track-mzigo?num=' + encodeURIComponent(ref) + '">Track</a>' +
        (isParcels
          ? ' · <button type="button" class="mz-link" data-order-doc="label" data-order-index="' + index + '">Label</button>'
          : '') +
        ' · <button type="button" class="mz-link" data-order-doc="receipt" data-order-index="' + index + '">Receipt</button>' + orderAction;
      return '<tr><td class="mz-mono">' + esc(ref) + '</td><td>' + esc(route) + '</td><td>' + esc(r.carrier) + '</td>' +
        '<td>' + who + '</td><td><span class="mz-pill mz-pill--' + esc(st.pill) + '">' + esc(st.label) + '</span></td>' +
        '<td>' + money(r.fee_total) + '</td>' + '<td>' + pay + '</td>' +
        '<td style="white-space:nowrap;">' + esc(fmtDate(isParcels ? r.registered_at : r.created_at)) + '</td>' +
        '<td style="white-space:nowrap;">' + actions + '</td></tr>';
    }).join('') : '<tr><td colspan="' + (isParcels ? 9 : 8) + '">' +
      '<div style="text-align:center;padding:18px;">' +
        '<strong>No ' + (isParcels ? 'parcels' : 'errands') + ' match these filters.</strong>' +
        '<div class="mz-hint" style="margin-top:4px;">Clear the search or date filters, or start a new ' + (isParcels ? 'parcel' : 'errand') + '.</div>' +
        '<div style="margin-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
          '<a class="mz-btn mz-btn--outline" href="' + (isParcels ? '/send-mzigo' : '/send-errand') + '">' + (isParcels ? 'Send a parcel' : 'Book an errand') + '</a>' +
          '<a class="mz-tab" href="/orders">Clear filters</a>' +
          (isParcels && /^(TRK|LK|ERD)-[A-Z0-9-]{3,}$/i.test(ordersState.q || '')
            ? '<a class="mz-tab" href="/track-mzigo?num=' + encodeURIComponent((ordersState.q || '').trim().toUpperCase()) + '">Track this number publicly</a>'
            : '') +
        '</div>' +
      '</div></td></tr>';

    var cards = (res.rows || []).map(function (r, index) {
      var st = STATUS_META[r.status] || STATUS_META.CREATED;
      var ref = isParcels ? r.tracking : r.ref;
      var route = isParcels ? (r.from + ' → ' + r.to) : (r.pickup + ' → ' + r.dropoff);
      var who = isParcels ? (r.receiver + (r.receiver_phone ? ' · ' + r.receiver_phone : '')) : r.recipient;
      var pay = isParcels ? '<span class="mz-badge ' + (r.payment_status === 'paid' ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + esc(r.payment_status) + '</span>' : '';
      var canManageOrders = !!(APP.tenant && ['owner', 'admin'].indexOf(APP.tenant.role) !== -1);
      var mobileAction = '';
      if (isParcels && canManageOrders) {
        if (['CREATED', 'AT_ORIGIN_POINT'].indexOf(r.status) !== -1) {
          mobileAction = '<button type="button" class="mz-link" data-order-action="cancel_parcel" data-order-ref="' + esc(ref) + '">Cancel</button>';
        } else if (['READY_FOR_COLLECTION', 'ARRIVED_DEST_POINT'].indexOf(r.status) !== -1) {
          mobileAction = '<button type="button" class="mz-link" data-order-action="request_return" data-order-ref="' + esc(ref) + '">Return</button>';
        }
      }
            if (isParcels && canManageOrders && ['CREATED', 'AT_ORIGIN_POINT', 'HANDED_TO_TRANSPORTER', 'IN_TRANSIT', 'ARRIVED_DEST_POINT'].indexOf(r.status) !== -1) {
        mobileAction += '<button type="button" class="mz-link" data-order-action="advance_parcel" data-order-ref="' + esc(ref) + '">Advance</button>';
      }
return '<div class="mz-mobile-card">' +
        '<div class="mz-mobile-card__top"><span class="mz-mono">' + esc(ref) + '</span><span class="mz-pill mz-pill--' + esc(st.pill) + '">' + esc(st.label) + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(route) + '</div>' +
        '<div class="mz-mobile-card__meta">' + esc(r.carrier) + ' · ' + esc(who) + '</div>' +
        '<div class="mz-mobile-card__bottom"><div><div class="mz-mono">' + money(r.fee_total) + '</div>' +
          '<div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(fmtDate(isParcels ? r.registered_at : r.created_at)) + ' ' + pay + '</div></div>' +
          '<div class="mz-mobile-card__actions"><a class="mz-link" href="/track-mzigo?num=' + encodeURIComponent(ref) + '">Track</a>' +
            (isParcels ? '<button type="button" class="mz-link" data-order-doc="label" data-order-index="' + index + '">Label</button>' : '') +
            '<button type="button" class="mz-link" data-order-doc="receipt" data-order-index="' + index + '">Receipt</button>' + mobileAction + '</div></div></div>';
    }).join('');
    table.innerHTML =
      '<div class="mz-orders-table-wrap"><div class="mz-table-wrap" role="region" aria-label="Orders table" tabindex="0">' +
        '<table class="mz-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div></div>' +
      '<div class="mz-orders-mobile">' + (cards || '<div class="mz-hint">No ' + (isParcels ? 'parcels' : 'errands') + ' match these filters.</div>') + '</div>' +
      '<div class="mz-pager"><button type="button" class="mz-tab" data-orders-prev' + (res.page <= 1 ? ' disabled' : '') + '>← Previous</button>' +
        '<span class="mz-pager__label">Page ' + res.page + ' of ' + res.pages + ' · ' + res.total + ' ' + (isParcels ? 'parcels' : 'errands') + '</span>' +
        '<button type="button" class="mz-tab" data-orders-next' + (res.page >= res.pages ? ' disabled' : '') + '>Next →</button></div>';

    table.querySelector('[data-orders-prev]').addEventListener('click', function () {
      if (res.page > 1) { ordersState.page = res.page - 1; loadOrders(); }
    });
    table.querySelector('[data-orders-next]').addEventListener('click', function () {
      if (res.page < res.pages) { ordersState.page = res.page + 1; loadOrders(); }
    });
    table.querySelectorAll('[data-order-doc]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = ordersData.rows[Number(btn.getAttribute('data-order-index'))];
        if (row) openOrderDoc(btn.getAttribute('data-order-doc'), row, isParcels);
      });
    });
    table.querySelectorAll('[data-order-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-order-action');
        var label = action === 'cancel_parcel' ? 'Cancel this parcel?' : (action === 'request_return' ? 'Request a return for this parcel?' : 'Advance this parcel to the next status?');
        if (!window.confirm(label)) return;
        btn.disabled = true;
        api('orders.php', { method: 'POST', body: { action: action, tracking: btn.getAttribute('data-order-ref') } })
          .then(function (res) { toast(res.message || 'Order updated.'); loadOrders(); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });
  }

  function exportOrdersCsv() {
    fetch('/api/orders.php?' + ordersQuery({ format: 'csv' }), { credentials: 'same-origin' }).then(function (res) {
      if (!res.ok) throw new Error('Export failed');
      return res.blob();
    }).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'mzigo-' + ordersState.tab + '-' + new Date().toISOString().slice(0, 10) + '.csv';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }).catch(function (err) { toast(err.message, 'error'); });
  }

  function openOrderDoc(kind, row, isParcel) {
    var base = window.location.origin;
    if (kind === 'label') {
      var labelWin = window.open('', '_blank', 'width=900,height=760');
      if (!labelWin) { toast('Allow pop-ups to print receipts/labels.', 'error'); return; }
      labelWin.document.open();
      labelWin.document.write(shippingLabelHTML(row, isParcel));
      labelWin.document.close();
      setTimeout(function () { labelWin.focus(); }, 200);
      return;
    }
    var trackUrl = base + '/track-mzigo?num=' + encodeURIComponent(row.tracking || row.ref);
    var brand = workspaceDocumentBrand();
    var title = brand.name + ' receipt ' + (row.tracking || row.ref);
    var qr = row.tracking ? qrSVG(trackUrl) : '';
    var heading = 'Payment receipt';
    var brandHeader = documentBrandMarkHTML(brand);
    var brandFooter = (brand.footer ? '<div>' + esc(brand.footer) + '</div>' : '') +
      '<div>' + esc(brand.support) + '</div><div>' + esc(brand.tagline) + '</div>';
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>' +
      '<style>body{font-family:Outfit,Segoe UI,sans-serif;color:#1f2a12;margin:24px;}h1{font-size:20px;margin:0 0 4px;}' +
      '.muted{color:#5b6b45;font-size:12px;}table{width:100%;border-collapse:collapse;margin-top:14px;font-size:13px;}' +
      'td,th{border-bottom:1px solid #e3ead9;padding:6px 4px;text-align:left;}th{width:38%;color:#5b6b45;font-weight:600;}' +
      '.qr{width:150px;text-align:center;font-size:11px;flex:0 0 150px;}.qr svg{width:140px;height:140px;}' +
      '.box{border:2px solid ' + esc(brand.primary) + ';border-radius:12px;padding:18px;margin-top:16px;}' +
      '.doc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;}.doc-footer{margin-top:20px;padding-top:10px;border-top:1px solid #e3ead9;color:#5b6b45;font-size:11px;line-height:1.6;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;}' +
      '@media print{button{display:none;}}button{margin-top:16px;padding:8px 14px;border-radius:8px;border:1px solid ' + esc(brand.primary) + ';background:' + esc(brand.primary) + ';color:#fff;cursor:pointer;}' +
      '</style></head><body>' +
      '<div class="doc-head"><div>' + brandHeader + '</div><div class="qr">' + qr + '<div>' + esc(row.tracking || row.ref) + '</div></div></div>' +
      '<h1>' + heading + '</h1><div class="muted">' + esc(brand.name) + ' &middot; ' + esc(new Date().toLocaleString()) + '</div>' +
      '<div class="box"><table>' +
      '<tr><th>Reference</th><td>' + esc(row.tracking || row.ref) + '</td></tr>' +
      '<tr><th>Carrier</th><td>' + esc(row.carrier) + '</td></tr>' +
      (isParcel
        ? '<tr><th>Route</th><td>' + esc(row.from) + ' → ' + esc(row.to) + '</td></tr>' +
          '<tr><th>Locker</th><td>' + esc(row.locker || '—') + '</td></tr>' +
          '<tr><th>Receiver</th><td>' + esc(row.receiver) + ' · ' + esc(row.receiver_phone || '') + '</td></tr>' +
          '<tr><th>Sender</th><td>' + esc(row.sender) + '</td></tr>' +
          '<tr><th>Size / value</th><td>' + esc(row.size || '—') + ' · ' + money(row.value) + '</td></tr>'
        : '<tr><th>Route</th><td>' + esc(row.pickup) + ' → ' + esc(row.dropoff) + '</td></tr>' +
          '<tr><th>Recipient</th><td>' + esc(row.recipient) + '</td></tr>') +
      '<tr><th>Fee total</th><td>' + money(row.fee_total) + '</td></tr>' +
      (isParcel && kind === 'receipt'
        ? '<tr><th>Fee breakdown</th><td>Base ' + money(row.fee.base) + ' · holding ' + money(row.fee.holding) + ' · insurance ' + money(row.fee.insurance) + ' · platform ' + money(row.fee.platform) + '</td></tr>' +
          '<tr><th>Payment status</th><td>' + esc(row.payment_status || '') + (row.payment_ref ? ' · ' + esc(row.payment_ref) : '') + '</td></tr>'
        : '<tr><th>Payment</th><td>' + esc(row.payment || '') + '</td></tr>') +
      '</table></div>' +
      '<div class="doc-footer">' + brandFooter + '</div>' +
      '<button onclick="window.print()">Print / Save as PDF</button>' +
      '</body></html>';
    var win = window.open('', '_blank', 'width=760,height=900');
    if (!win) { toast('Allow pop-ups to print receipts/labels.', 'error'); return; }
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(function () { win.focus(); }, 200);
  }

  function renderProfile() {
    var body = $('[data-mz-profile-body]');
    if (!body) return;
    var u = user();
    var authBanner = '';
    if (!u) {
      authBanner =
        '<div class="mz-list-card" style="margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">' +
          '<div style="flex:1;min-width:200px;">' +
            '<div style="font-weight:700;">Sign in to sync your parcels</div>' +
            '<div style="font-size:13px;color:var(--mz-muted-fg);">Create a free account to get receipts, QR labels and delivery updates on every parcel.</div>' +
          '</div>' +
          '<div style="display:flex;gap:8px;">' +
            '<a class="mz-tab" href="/login">Sign in</a>' +
            '<a class="mz-tab is-active" href="/signup">Start free trial</a>' +
          '</div>' +
        '</div>';
    } else {
      authBanner =
        '<div class="mz-list-card" style="margin-bottom:16px;display:flex;gap:14px;align-items:center;">' +
          avatar(u.name, 'lg') +
          '<div style="flex:1;min-width:0;"><div style="font-weight:700;">' + esc(u.name) + '</div>' +
          '<div style="font-size:13px;color:var(--mz-muted-fg);">' + esc(u.phone) + (u.business ? ' · ' + esc(u.business) : '') + '</div></div>' +
          '<button type="button" class="mz-tab" data-mz-signout>Sign out</button>' +
        '</div>';
    }

    authBanner = tenantStatusBanner() + authBanner;

    var content = '';
    if (profileTab === 'packages') {
      var list = packages().slice().reverse();
      if (!list.length) {
        content = emptyState('packages');
      } else {
        content = list.map(function (p) {
          var st = STATUS_META[p.status] || STATUS_META.CREATED;
          return '<div class="mz-list-card">' +
            '<div class="mz-row">' +
              '<div style="display:flex;align-items:center;gap:10px;">' + avatar(p.carrier, 'sm') +
                '<div><div class="mz-mono">' + esc(p.tracking) + '</div>' +
                '<div style="font-size:12px;color:var(--mz-muted-fg);">' + esc(p.from) + ' → ' + esc(p.to) + ' · ' + esc(fmtDate(p.registered_at)) + '</div></div></div>' +
              '<span class="mz-pill mz-pill--' + st.pill + '">' + esc(st.label) + '</span>' +
            '</div>' +
            '<div class="mz-row"><span class="mz-cap">Locker ' + esc(p.locker) + '</span>' +
              '<span style="display:flex;gap:8px;">' +
                '<a class="mz-tab" style="border:1px solid var(--mz-border);" href="/track-mzigo?num=' + encodeURIComponent(p.tracking) + '">Track</a>' +
                '<a class="mz-tab is-active" href="/track-mzigo?num=' + encodeURIComponent(p.tracking) + '">Receipt &amp; QR</a>' +
              '</span></div>' +
            '</div>';
        }).join('');
      }
    } else if (profileTab === 'errands') {
      var list2 = errands().slice().reverse();
      if (!list2.length) content = emptyState('errands');
      else content = list2.map(function (e) {
        var st = STATUS_META[e.status] || STATUS_META.CREATED;
        return '<div class="mz-list-card"><div class="mz-row">' +
          '<div><div class="mz-mono">' + esc(e.ref || e.id) + '</div>' +
          '<div style="font-size:12px;color:var(--mz-muted-fg);">' + esc(e.pickup) + ' → ' + esc(e.dropoff) + '</div></div>' +
          '<span class="mz-pill mz-pill--' + st.pill + '">' + esc(st.label) + '</span></div>' +
          '<div class="mz-row"><span class="mz-cap">Runner fee ' + kes((e.fee || {}).total) + '</span>' +
          '<a class="mz-tab" href="/track-mzigo?num=' + encodeURIComponent(e.ref || e.id) + '">Track errand</a></div></div>';
      }).join('');
    } else {
      var senders = savedSenders();
      packages().forEach(function (p) { if (p.sender && p.sender_phone) senders.push({ name: p.sender, phone: p.sender_phone }); });
      var seen = {};
      senders = senders.filter(function (s) { if (seen[s.phone]) return false; seen[s.phone] = 1; return true; });
      content = senders.length
        ? senders.map(function (s) {
            return '<div class="mz-list-card"><div class="mz-row">' + avatar(s.name, 'sm') +
              '<div style="flex:1;min-width:0;"><div style="font-weight:600;">' + esc(s.name) + '</div>' +
              '<div class="mz-mono" style="color:var(--mz-muted-fg);">' + esc(s.phone) + '</div></div>' +
              '<a class="mz-tab is-active" href="/send-mzigo">Send</a></div></div>';
          }).join('')
        : emptyState('senders');
    }
    var planCard = '';
    if (APP.user && APP.tenant && APP.usage && APP.usage.plan) {
      var u = APP.usage;
      var pct = u.parcels_limit ? Math.min(100, Math.round((u.parcels_used / u.parcels_limit) * 100)) : 0;
      var subStatus = (u.subscription && u.subscription.subscription_status) || 'trial';
      planCard =
        '<div class="mz-saas-card" style="margin-bottom:16px;">' +
          '<div class="mz-row">' +
            '<div><div class="mz-cap">Current plan</div>' +
              '<div style="font-size:18px;font-weight:700;">' + esc(u.plan.name) + ' · ' + money(u.plan.monthly_price_kes) + '/mo</div></div>' +
            '<span class="mz-badge mz-badge--' + (subStatus === 'active' ? 'ok' : 'warn') + '">' + esc(subStatus.replace('_', ' ')) +
              (u.trial_days_left != null ? ' · ' + u.trial_days_left + ' days left' : '') + '</span>' +
          '</div>' +
          '<div class="mz-meter"><span style="width:' + pct + '%"></span></div>' +
          '<div class="mz-row" style="margin-top:8px;">' +
            '<span style="font-size:12px;color:var(--mz-muted-fg);">' + u.parcels_used + ' of ' + u.parcels_limit +
              ' parcels this month · ' + u.staff_used + '/' + u.plan.staff_limit + ' team members</span>' +
            '<span style="display:flex;gap:8px;">' +
              '<a class="mz-tab" href="/billing">Manage billing</a>' +
              '<a class="mz-tab is-active" href="/send-mzigo">New parcel</a>' +
            '</span>' +
          '</div>' +
        '</div>';
    } else if (APP.user && APP.user.role === 'platform_admin') {
      planCard = '<div class="mz-list-card" style="margin-bottom:16px;">Platform admin account · <a class="mz-link" href="/admin">Open admin console</a></div>';
    }
    var dbBadge = '';
    if (APP.source === 'mysql') {
      var pkgCount = APP.packages.length;
      var errandCount = APP.errands.length;
      dbBadge = '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:12px;font-size:12px;color:var(--mz-muted-fg);">' +
        '<span style="width:8px;height:8px;border-radius:999px;background:#16a34a;display:inline-block;"></span>' +
        '<span><strong style="color:var(--mz-hunter);">MySQL connected</strong> · ' + esc(APP.database) + ' · ' +
        pkgCount + ' parcel' + (pkgCount === 1 ? '' : 's') + ', ' + errandCount + ' errand' + (errandCount === 1 ? '' : 's') + '</span></div>';
    }
    body.innerHTML = dbBadge + planCard + authBanner + '<section aria-live="polite">' + content + '</section>';
    var so = body.querySelector('[data-mz-signout]');
    if (so) so.addEventListener('click', function () { signOut(); });
  }

  function emptyState(kind) {
    var copy = {
      packages: ['No packages registered yet.', 'Create your first package to get a receipt and QR code for tracking.'],
      errands: ['No errands booked yet.', 'Book a runner to pick up or deliver something around town.'],
      senders: ['No saved senders yet.', 'Sender details are saved automatically when you register a parcel.']
    }[kind] || ['Nothing here yet.', ''];
    return '<div class="mz-list-card" style="text-align:center;padding:28px 20px;">' +
      '<div class="mz-empty-icon">' + (kind === 'errands' ? ICON.route : kind === 'senders' ? ICON.users : ICON.cube) + '</div>' +
      '<p style="font-size:15px;font-weight:600;margin:0 0 4px;">' + copy[0] + '</p>' +
      '<p style="font-size:13px;color:var(--mz-muted-fg);margin:0 0 14px;">' + copy[1] + '</p>' +
      '<a class="mz-tab is-active" style="display:inline-flex;" href="/send-mzigo">' + (kind === 'errands' ? 'Book an errand' : 'Send Mzigo') + '</a>' +
      '</div>';
  }

  function initProfile() {
    if (APP.user && APP.user.role === 'freelancer') { window.location.href = '/freelancer'; return; }
    if (APP.user && APP.user.role === 'customer') { window.location.href = '/customer'; return; }
    if (APP.user && APP.tenant) { initMerchantDashboard(); return; }
    var tabsMount = $('[data-mz-profile-tabs]');
    if (!tabsMount) return;
    tabsMount.classList.add('mz-tabs', 'mz-tabs--3');
    var labels = [['packages', 'Packages'], ['errands', 'Errands'], ['senders', 'Senders']];
    tabsMount.innerHTML = labels.map(function (t) {
      return '<button type="button" class="mz-tab" role="tab" data-profile-tab="' + t[0] + '" aria-selected="' + (t[0] === profileTab) + '">' + t[1] + '</button>';
    }).join('');
    tabsMount.querySelectorAll('[data-profile-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        profileTab = btn.getAttribute('data-profile-tab');
        tabsMount.querySelectorAll('[data-profile-tab]').forEach(function (b) {
          b.setAttribute('aria-selected', String(b === btn));
          b.classList.toggle('is-active', b === btn);
        });
        renderProfile();
      });
    });
    tabsMount.querySelectorAll('[data-profile-tab]').forEach(function (b) {
      if (b.getAttribute('data-profile-tab') === profileTab) b.classList.add('is-active');
    });
    renderProfile();
  }

  /* ------------------------------------------------------------------- auth */
  /* Auth lives on the dedicated /login and /signup pages (see the SaaS section). */
  function initAuth() {}

  /* ------------------------------------------------------------------- QR */
  function qrSVG(text) {
    try {
      if (typeof window.qrcode !== 'function') return '';
      var qr = window.qrcode(0, 'M');
      qr.addData(text);
      qr.make();
      return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    } catch (e) { return ''; }
  }

  function workspaceDocumentBrand() {
    var tenant = (typeof APP !== 'undefined' && APP.tenant) ? APP.tenant : null;
    var branding = (typeof APP !== 'undefined' && APP.branding) ? APP.branding : {};
    var name = tenant && tenant.name ? String(tenant.name) : 'Mzigo.online';
    return {
      workspace: !!tenant,
      name: name,
      logo: branding.logo_url || '',
      primary: branding.primary_color || '#3c5223',
      support: branding.support_email || 'hello@mzigo.online',
      footer: branding.document_footer || '',
      tagline: tenant ? 'Powered By Mzigo.online' : 'Parcel network'
    };
  }

  function documentBrandMarkHTML(brand) {
    var logo = brand.logo
      ? '<img src="' + esc(brand.logo) + '" alt="' + esc(brand.name) + '" style="max-height:28px;max-width:150px;object-fit:contain;display:block;">'
      : '';
    return '<div style="display:flex;align-items:center;gap:8px;min-width:0;">' + logo +
      '<div style="min-width:0;"><div style="font-size:14px;font-weight:800;color:#1f2a12;line-height:1.15;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(brand.name) + '</div>' +
      '<div style="font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7a5c;margin-top:2px;">' + esc(brand.tagline) + '</div></div></div>';
  }

  function code39SVG(value) {
    var patterns = {
      '0':'101001101101','1':'110100101011','2':'101100101011','3':'110110010101','4':'101001101011',
      '5':'110100110101','6':'101100110101','7':'101001011011','8':'110100101101','9':'101100101101',
      'A':'110101001011','B':'101101001011','C':'110110100101','D':'101011001011','E':'110101100101',
      'F':'101101100101','G':'101010011011','H':'110101001101','I':'101101001101','J':'101011001101',
      'K':'110101010011','L':'101101010011','M':'110110101001','N':'101011010011','O':'110101101001',
      'P':'101101101001','Q':'101010110011','R':'110101011001','S':'101101011001','T':'101011011001',
      'U':'110010101011','V':'100110101011','W':'110011010101','X':'100101101011','Y':'110010110101',
      'Z':'100110110101','-':'100101011011','.':'110010101101',' ':'100110101101',
      '$':'100100100101','/':'100100101001','+':'100101001001','%':'101001001001','*':'100101101101'
    };
    var text = String(value || '').toUpperCase().replace(/[^0-9A-Z\-. $/+%]/g, '').slice(0, 28);
    if (!text) return '';
    var module = 2;
    var height = 48;
    var x = 0;
    var bars = [];
    var chars = ('*' + text + '*').split('');
    chars.forEach(function (ch, index) {
      var pattern = patterns[ch] || patterns['-'];
      for (var i = 0; i < pattern.length; i++) {
        if (pattern.charAt(i) === '1') {
          bars.push('<rect x="' + x + '" y="0" width="' + module + '" height="' + height + '" fill="#111"/>');
        }
        x += module;
      }
      if (index < chars.length - 1) { x += module; }
    });
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + x + '" height="' + height + '" viewBox="0 0 ' + x + ' ' + height + '" shape-rendering="crispEdges" role="img" aria-label="Barcode ' + esc(text) + '">' + bars.join('') + '</svg>';
  }

  function shippingLabelHTML(row, isParcel) {
    var ref = String(row.tracking || row.ref || '');
    var trackUrl = window.location.origin + '/track-mzigo?num=' + encodeURIComponent(ref);
    var fromName = row.sender || 'Mzigo sender';
    var toName = row.receiver || row.recipient || 'Receiver';
    var fromLine = row.from || row.pickup || 'Nairobi';
    var toLine = row.to || row.dropoff || 'Destination';
    var phone = row.receiver_phone || row.recipient_phone || '';
    var locker = row.locker || '';
    var carrier = row.carrier || 'Mzigo partner';
    var size = row.size || (isParcel ? 'Parcel' : 'Errand');
    var itemNo = row.ref || ref;
    var instruction = locker ? 'Locker ' + locker : (isParcel ? 'Present at partner office' : 'Hand to runner');
    var brand = workspaceDocumentBrand();
    var brandMark = brand.logo
      ? '<div class="brand-tenant">' + esc(brand.name) + '</div><img class="brand-logo" src="' + esc(brand.logo) + '" alt="' + esc(brand.name) + '">'
      : '<div class="brand-mark">' + esc(brand.name) + '</div>';
    var brandTagline = brand.workspace ? 'Powered By Mzigo.online' : 'Parcel network';
    var qr = ref ? qrSVG(trackUrl) : '';
    var barcode = code39SVG(ref);
    var target = fromLine + ' to ' + toLine;
    var phoneLine = phone ? phone : (locker ? 'Locker ' + locker : '');
    var handling = '<div class="handling-icons">' +
      '<div class="handle-icon" title="Fragile"><svg viewBox="0 0 24 24"><path d="M8 3h8l-1 5h-6L8 3Z"/><path d="M10 8v5l2 2 2-2V8M12 15v4M9 21h6"/></svg></div>' +
      '<div class="handle-icon" title="This way up"><svg viewBox="0 0 24 24"><path d="M12 20V5M6 11l6-6 6 6"/></svg></div>' +
      '<div class="handle-icon" title="Keep dry"><svg viewBox="0 0 24 24"><path d="M4 13a8 8 0 0 1 16 0H4Z"/><path d="M12 13v6a2 2 0 0 0 4 0"/></svg></div>' +
      '</div><div class="handling-label">HANDLE WITH CARE</div>';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(brand.name) + ' label ' + esc(ref) + '</title>' +
      '<style>' +
      '@page{size:6in 4in;margin:0;}*{box-sizing:border-box;}html,body{margin:0;min-height:100%;}' +
      'body{font-family:"Segoe UI",Arial,sans-serif;background:#e7dfd2;color:#111;padding:24px;}' +
      '.label-toolbar{max-width:6in;margin:0 auto 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12px;color:#5b4f3f;}' +
      '.label-toolbar strong{font-size:13px;color:#1f2a12;}.label-toolbar button{border:1px solid #111;background:#111;color:#fff;border-radius:999px;padding:8px 14px;font:700 12px "Segoe UI",Arial,sans-serif;cursor:pointer;}' +
      '.label-page{width:6in;height:4in;margin:0 auto;padding:.12in;background:#e7dfd2;}' +
      '.label{width:100%;height:100%;background:#fff;border:2px solid #111;border-radius:.14in;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 24px rgba(31,42,18,.16);}' +
      '.label-top{height:19%;min-height:.72in;display:flex;border-bottom:1.5px solid #111;}' +
      '.label-brand{width:47%;padding:7pt 10pt;border-right:1.5px solid #111;display:flex;flex-direction:column;justify-content:center;gap:1pt;}' +
      '.brand-mark{font-size:17pt;font-weight:900;letter-spacing:-.04em;line-height:1;}.brand-tenant{flex:0 0 auto;font-size:7.5pt;font-weight:800;line-height:1.1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:1.65in;}.brand-logo{flex:0 0 auto;max-height:.18in;max-width:1.45in;object-fit:contain;display:block;margin:1pt 0;}' +
      '.brand-sub{flex:0 0 auto;font-size:5.2pt;font-weight:700;letter-spacing:.14em;color:#444;line-height:1.2;}' +
      '.label-product{flex:1;padding:8pt 11pt;text-align:right;display:flex;flex-direction:column;justify-content:center;align-items:flex-end;}' +
      '.product-kicker{font-size:5.2pt;font-weight:800;letter-spacing:.16em;color:#555;}.product-name{font-size:15pt;font-weight:900;line-height:1.05;margin-top:2pt;text-transform:uppercase;}.product-meta{font-size:6.4pt;color:#444;margin-top:2pt;}' +
      '.label-mid{display:grid;grid-template-columns:1fr 1fr .9fr;min-height:0;flex:1;border-bottom:1.5px solid #111;}' +
      '.addr{padding:8pt 10pt;border-right:1px solid #111;min-width:0;}.addr:last-of-type{border-right:1.5px solid #111;}' +
      '.addr-label{font-size:5.2pt;font-weight:800;letter-spacing:.14em;color:#222;}.addr-name{font-size:10pt;font-weight:800;line-height:1.15;margin-top:3pt;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.addr-line{font-size:7.2pt;line-height:1.35;color:#333;margin-top:2pt;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.addr-meta{font-size:6.2pt;line-height:1.35;color:#666;margin-top:5pt;}' +
      '.qr-panel{padding:7pt 7pt 6pt;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:0;}' +
      '.qr{width:1.02in;height:1.02in;display:flex;align-items:center;justify-content:center;}.qr svg{width:100%;height:100%;display:block;}' +
      '.qr-label{font-size:4.6pt;font-weight:800;letter-spacing:.12em;margin-top:4pt;color:#333;text-align:center;}.qr-code{font-size:5.2pt;color:#555;margin-top:1pt;}' +
      '.label-bottom{height:34%;min-height:1.05in;display:grid;grid-template-columns:1.05fr 1.35fr .9fr;}' +
      '.barcode-panel{padding:7pt 10pt;border-right:1px solid #111;display:flex;flex-direction:column;justify-content:center;min-width:0;}' +
      '.barcode-panel svg{width:100%;height:.42in;display:block;}.barcode-text{font-family:ui-monospace,Consolas,monospace;font-size:6.5pt;letter-spacing:.08em;margin-top:4pt;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
      '.handling-panel{border-right:1px solid #111;padding:7pt 8pt;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:5pt;}.handling-icons{display:flex;gap:7pt;align-items:center;}.handle-icon{width:.34in;height:.34in;border:1.5px solid #111;border-radius:3pt;display:flex;align-items:center;justify-content:center;padding:3pt;}.handle-icon svg{width:100%;height:100%;fill:none;stroke:#111;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;}.handling-label{font-size:4.7pt;font-weight:800;letter-spacing:.12em;color:#333;}' +
      '.item-panel{padding:8pt 10pt;display:flex;flex-direction:column;justify-content:center;min-width:0;}.item-label{font-size:5.2pt;font-weight:800;letter-spacing:.14em;color:#222;}.item-value{font-size:8.5pt;font-weight:800;margin-top:3pt;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.item-instruction-label{font-size:4.5pt;font-weight:800;letter-spacing:.1em;color:#777;margin-top:5pt;}.item-instruction{font-size:6.4pt;line-height:1.35;color:#555;margin-top:1pt;}' +
      '@media print{body{padding:0;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}.label-toolbar{display:none;}.label-page{width:6in;height:4in;padding:0;margin:0;background:#fff;}.label{height:4in;border-radius:.1in;box-shadow:none;}}' +
      '</style></head><body>' +
      '<div class="label-toolbar"><span><strong>Shipping label</strong> &nbsp; 6 x 4 in</span><button type="button" onclick="window.print()">Print / Save PDF</button></div>' +
      '<div class="label-page"><div class="label">' +
        '<div class="label-top"><div class="label-brand">' + brandMark + '<div class="brand-sub">' + esc(brandTagline) + '</div></div><div class="label-product"><div class="product-kicker">PARCEL LABEL</div><div class="product-name">' + esc(size) + '</div><div class="product-meta">' + esc(carrier) + ' &middot; ' + esc(target) + '</div></div></div>' +
        '<div class="label-mid">' +
          '<div class="addr"><div class="addr-label">FROM</div><div class="addr-name">' + esc(fromName) + '</div><div class="addr-line">' + esc(fromLine) + '</div><div class="addr-meta">Kenya &middot; Mzigo.online</div></div>' +
          '<div class="addr"><div class="addr-label">TO</div><div class="addr-name">' + esc(toName) + '</div><div class="addr-line">' + esc(toLine) + '</div><div class="addr-meta">' + esc(phoneLine || 'Contact on arrival') + '</div></div>' +
          '<div class="qr-panel"><div class="qr">' + qr + '</div><div class="qr-label">SCAN TO TRACK</div><div class="qr-code">' + esc(ref) + '</div></div>' +
        '</div>' +
        '<div class="label-bottom">' +
          '<div class="barcode-panel">' + barcode + '<div class="barcode-text">' + esc(ref) + '</div></div>' +
          '<div class="handling-panel">' + handling + '</div>' +
          '<div class="item-panel"><div class="item-label">ITEM NO.</div><div class="item-value">' + esc(itemNo) + '</div><div class="item-instruction-label">DELIVERY INSTRUCTION</div><div class="item-instruction">' + esc(instruction) + '</div></div>' +
        '</div>' +
      '</div></div>' +
      '</body></html>';
  }

  /* --------------------------------------------------------------- wizard UI */
  function stepTitle(pageLabel, step) {
    var title = $('[data-mz-wizard-title]');
    var sub = $('[data-mz-wizard-sub]');
    if (title) title.textContent = pageLabel;
    if (sub) sub.textContent = 'Step ' + step + ' of 3';
  }
  function renderStepper(host, step, labels) {
    if (!host) return;
    host.innerHTML = labels.map(function (label, i) {
      var n = i + 1;
      var cls = n === step ? 'is-current' : n < step ? 'is-done' : '';
      var icon = n < step ? ICON.tick : (n === 1 ? ICON.route : n === 2 ? ICON.users : ICON.cube);
      return '<div class="mz-stepper__item ' + cls + '">' +
        '<span class="mz-stepper__dot">' + icon + '</span>' +
        '<span class="mz-stepper__label">' + esc(typeof label === 'string' ? label : label.label) + '</span></div>';
    }).join('');
  }
  function showStep(scope, n) {
    $$('.mz-step', scope).forEach(function (s) {
      s.hidden = Number(s.getAttribute('data-step')) !== n;
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function summaryHTML(idPrefix) {
    return '<div class="mz-summary">' +
      '<div class="mz-summary__item">' + avatar('mzigo', 'sm') + '<div style="min-width:0;"><div class="mz-summary__key">Courier</div><div class="mz-summary__val" data-mz-sum="' + idPrefix + '-carrier">—</div></div></div>' +
      '<div class="mz-summary__item">' + '<div style="min-width:0;"><div class="mz-summary__key">From</div><div class="mz-summary__val" data-mz-sum="' + idPrefix + '-from">—</div></div></div>' +
      '<div class="mz-summary__item">' + '<div style="min-width:0;"><div class="mz-summary__key">To</div><div class="mz-summary__val" data-mz-sum="' + idPrefix + '-to">—</div></div></div>' +
      '</div>';
  }
  function setSum(prefix, key, value) {
    var el = $('[data-mz-sum="' + prefix + '-' + key + '"]');
    if (el) el.textContent = value || '—';
  }

  /* --------------------------------------------------------------- send flow */
  var sendState = {
    carrier: null, from: '', to: '', senderName: '', senderPhone: '',
    receiverName: '', receiverPhone: '', description: '', value: '', size: '',
    instructions: '', payment: '', quote: null
  };

  function renderReceipt(mount, item, kind) {
    var fee = item.fee || {};
    var ref = kind === 'errand' ? (item.ref || item.id) : (item.tracking || item.id);
    var qrPayload = (window.location.origin || 'http://localhost:8090') + '/track-mzigo?num=' + encodeURIComponent(ref);
    var rows = kind === 'errand'
      ? '<div class="mz-kv"><dt>Errand ID</dt><dd class="mz-mono">' + esc(item.ref || item.id) + '</dd></div>' +
        '<div class="mz-kv"><dt>Pickup</dt><dd>' + esc(item.pickup) + '</dd></div>' +
        '<div class="mz-kv"><dt>Drop-off</dt><dd>' + esc(item.dropoff) + '</dd></div>'
      : '<div class="mz-kv"><dt>Tracking number</dt><dd class="mz-mono">' + esc(item.tracking) + '</dd></div>' +
        '<div class="mz-kv"><dt>Locker ID</dt><dd class="mz-mono">' + esc(item.locker) + '</dd></div>' +
        '<div class="mz-kv"><dt>Route</dt><dd>' + esc(item.from) + ' → ' + esc(item.to) + '</dd></div>';

    mount.innerHTML =
      '<div class="mz-list-card" style="text-align:center;padding:28px 22px;">' +
        '<div class="mz-success__icon">' + ICON.check + '</div>' +
        '<h2 style="font-size:22px;font-weight:800;margin:0 0 4px;">' + (kind === 'errand' ? 'Errand booked' : 'Mzigo registered') + '</h2>' +
        '<p style="font-size:13px;color:var(--mz-muted-fg);margin:0 0 18px;">' + (kind === 'errand'
          ? 'Your runner request is in. Keep this reference for updates.'
          : 'Your parcel has a Locker ID and QR label. Drop it off at the courier office to start tracking.') + '</p>' +
        '<div class="mz-qr">' + qrSVG(qrPayload) + '</div>' +
        '<div style="font-size:11px;color:var(--mz-muted-fg);margin:8px 0 18px;">Scan to open the tracking page</div>' +
        '<div style="text-align:left;max-width:520px;margin:0 auto;">' +
          '<dl style="margin:0;">' + rows +
            '<div class="mz-kv"><dt>Courier</dt><dd>' + esc(item.carrier || 'Mzigo.online') + '</dd></div>' +
            '<div class="mz-kv"><dt>Registered</dt><dd>' + esc(fmtDate(item.registered_at)) + '</dd></div>' +
            '<div class="mz-kv"><dt>Payment</dt><dd>' + esc(item.payment || 'M-PESA') + '</dd></div>' +
          '</dl>' +
          '<div style="margin-top:14px;">' +
            '<div class="mz-cap" style="margin-bottom:4px;">' + (kind === 'errand' ? 'Runner fee estimate' : 'Delivery fee') + '</div>' +
            '<div class="mz-fee-line"><span>Base</span><span>' + kes(fee.base) + '</span></div>' +
            (kind === 'parcel' ? '<div class="mz-fee-line"><span>Holding (Tier ' + esc(item.tier) + ')</span><span>' + kes(fee.holding) + '</span></div>' : '') +
            (kind === 'parcel' ? '<div class="mz-fee-line"><span>Insurance share</span><span>' + kes(fee.insurance) + '</span></div>' : '') +
            '<div class="mz-fee-line"><span>Platform fee</span><span>' + kes(fee.platform) + '</span></div>' +
            '<div class="mz-fee-line mz-fee-line--total"><span>Total</span><span>' + kes(fee.total) + '</span></div>' +
          '</div>' +
        '</div>' +
        '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:22px;">' +
          '<a class="mz-tab is-active" href="/track-mzigo?num=' + encodeURIComponent(ref) + '">Track ' + (kind === 'errand' ? 'errand' : 'parcel') + '</a>' +
          '<button type="button" class="mz-tab" data-mz-again style="border:1px solid var(--mz-border);">' + (kind === 'errand' ? 'Book another errand' : 'Send another parcel') + '</button>' +
          '<a class="mz-tab" style="border:1px solid var(--mz-border);" href="/profile">View profile</a>' +
        '</div>' +
        '<p style="font-size:11px;color:var(--mz-muted-fg);margin:16px 0 0;">Pay at collection by M-Pesa. The parcel is released only after the payment is confirmed.</p>' +
      '</div>';
    var again = mount.querySelector('[data-mz-again]');
    if (again) again.addEventListener('click', function () { window.location.reload(); });
  }

  function initSend() {
    var wizard = $('[data-mz-send-wizard]');
    if (!wizard) return;
    var pathSlug = (window.location.pathname.match(/\/send-mzigo\/([^/]+)/) || [])[1];
    var initialSlug = decodeURIComponent(pathSlug || qs('carrier') || '');
    var initialFrom = qs('from');
    var initialTo = qs('to');

    var stepper = $('[data-mz-stepper]');
    var stepLabels = ['Courier & route', 'Sender & receiver', 'Parcel'];
    var step1 = wizard.querySelector('[data-step="1"]');
    var step2 = wizard.querySelector('[data-step="2"]');
    var step3 = wizard.querySelector('[data-step="3"]');
    var receipt = wizard.querySelector('[data-step="4"]');

    var carrierSel = createSelect({
      mount: step1.querySelector('[data-mz-mount="carrier"]'),
      items: APP.partners, label: '', placeholder: 'Search for a courier', icon: 'search', avatar: true,
      meta: function () { return 'Select'; },
      onChange: function (item) {
        sendState.carrier = item ? item.name : null;
        if (item) {
          fromSel.setItems(item.destinations);
          toSel.setItems(item.destinations);
          fromSel.enable(true); toSel.enable(true);
          var hint = step1.querySelector('[data-mz-hint]');
          if (hint) hint.textContent = 'Selected carrier ' + item.name + '. Now choose the route.';
          var chip = step1.querySelector('[data-mz-selected-carrier]');
          if (chip) {
            chip.innerHTML = avatar(item.name, 'md') +
              '<div style="min-width:0;"><div class="mz-cap">Selected carrier</div><div style="font-weight:700;">' + esc(item.name) + '</div></div>' +
              '<button type="button" class="mz-tab" data-mz-clear-carrier style="margin-left:auto;">Change</button>';
            chip.hidden = false;
            var clear = chip.querySelector('[data-mz-clear-carrier]');
            clear.addEventListener('click', function () {
              carrierSel.reset(); sendState.carrier = null; chip.hidden = true;
              fromSel.enable(false); toSel.enable(false);
              updateStep1();
            });
          }
          setSum('s', 'carrier', item.name);
        }
        updateStep1();
      }
    });
    var fromSel = createSelect({
      mount: step1.querySelector('[data-mz-mount="from"]'), items: [], label: 'From',
      placeholder: 'Enter city or town, e.g. Nairobi', icon: 'pin', disabled: true,
      onChange: function (v) { sendState.from = v || ''; setSum('s', 'from', sendState.from); updateStep1(); }
    });
    var toSel = createSelect({
      mount: step1.querySelector('[data-mz-mount="to"]'), items: [], label: 'To',
      placeholder: 'Enter city or town, e.g. Mombasa', icon: 'pin', disabled: true,
      onChange: function (v) { sendState.to = v || ''; setSum('s', 'to', sendState.to); updateStep1(); }
    });

    function updateStep1() {
      var cont = step1.querySelector('[data-mz-next]');
      if (!cont) return;
      var ready = !!(sendState.carrier && sendState.from && sendState.to);
      cont.disabled = !ready;
      cont.setAttribute('aria-disabled', String(!ready));
    }

    // preselect carrier from URL
    if (initialSlug) {
      var partner = APP.partners.filter(function (p) { return p.slug === initialSlug; })[0];
      if (partner) {
        carrierSel.setItems(APP.partners);
        carrierSel.set(partner.name);
        sendState.carrier = partner.name;
        fromSel.setItems(partner.destinations); toSel.setItems(partner.destinations);
        fromSel.enable(true); toSel.enable(true);
        var chip = step1.querySelector('[data-mz-selected-carrier]');
        if (chip) {
          chip.innerHTML = avatar(partner.name, 'md') +
            '<div style="min-width:0;"><div class="mz-cap">Selected carrier</div><div style="font-weight:700;">' + esc(partner.name) + '</div></div>' +
            '<button type="button" class="mz-tab" data-mz-clear-carrier style="margin-left:auto;">Change</button>';
          chip.hidden = false;
          chip.querySelector('[data-mz-clear-carrier]').addEventListener('click', function () {
            carrierSel.reset(); sendState.carrier = null; chip.hidden = true;
            fromSel.enable(false); toSel.enable(false); updateStep1();
          });
        }
        if (initialFrom) { fromSel.set(initialFrom); sendState.from = initialFrom; setSum('s', 'from', initialFrom); }
        if (initialTo) { toSel.set(initialTo); sendState.to = initialTo; setSum('s', 'to', initialTo); }
        setSum('s', 'carrier', partner.name);
      }
    }
    updateStep1();

    // step 2 fields
    var s2 = {
      name: step2.querySelector('[data-mz-sender-name]'),
      phone: step2.querySelector('[data-mz-sender-phone]'),
      rname: step2.querySelector('[data-mz-receiver-name]'),
      rphone: step2.querySelector('[data-mz-receiver-phone]')
    };
    if (s2.name) s2.name.addEventListener('input', function () { sendState.senderName = s2.name.value; });
    if (s2.phone) s2.phone.addEventListener('input', function () { sendState.senderPhone = s2.phone.value; });
    if (s2.rname) s2.rname.addEventListener('input', function () { sendState.receiverName = s2.rname.value; });
    if (s2.rphone) s2.rphone.addEventListener('input', function () { sendState.receiverPhone = s2.rphone.value; });

    // saved sender suggestion
    var saved = savedSenders();
    var savedBox = step2.querySelector('[data-mz-saved-senders]');
    if (savedBox) {
      if (saved.length) {
        savedBox.hidden = false;
        savedBox.innerHTML = '<div class="mz-cap" style="margin-bottom:6px;">Saved senders</div>' +
          saved.map(function (s, i) {
            return '<button type="button" class="mz-tab" data-mz-saved="' + i + '" style="border:1px solid var(--mz-border);margin:0 6px 6px 0;">' + esc(s.name) + ' · ' + esc(s.phone) + '</button>';
          }).join('');
        savedBox.querySelectorAll('[data-mz-saved]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var s = saved[Number(btn.getAttribute('data-mz-saved'))];
            sendState.senderName = s.name; sendState.senderPhone = s.phone;
            if (s2.name) s2.name.value = s.name;
            if (s2.phone) s2.phone.value = s.phone;
          });
        });
      } else savedBox.hidden = true;
    }

    // step 3 fields
    var s3 = {
      description: step3.querySelector('[data-mz-description]'),
      value: step3.querySelector('[data-mz-value]'),
      size: step3.querySelector('[data-mz-size]'),
      instructions: step3.querySelector('[data-mz-instructions]'),
      payment: step3.querySelector('[data-mz-payment]'),
      estimate: step3.querySelector('[data-mz-estimate]')
    };
    function refreshEstimate() {
      var q = quote({ size: (s3.size && s3.size.value) || 'Medium', value: Number((s3.value && s3.value.value) || 0) });
      sendState.quote = q;
      if (s3.estimate) {
        s3.estimate.innerHTML = '<div class="mz-fee-line"><span>Base</span><span>' + kes(q.base) + '</span></div>' +
          '<div class="mz-fee-line"><span>Holding</span><span>' + kes(q.holding) + '</span></div>' +
          '<div class="mz-fee-line"><span>Insurance share</span><span>' + kes(q.insurance) + '</span></div>' +
          '<div class="mz-fee-line"><span>Platform fee</span><span>' + kes(q.platform) + '</span></div>' +
          '<div class="mz-fee-line mz-fee-line--total"><span>Total</span><span>' + kes(q.total) + '</span></div>';
      }
    }
    ['description', 'value', 'instructions'].forEach(function (k) {
      if (s3[k]) s3[k].addEventListener('input', function () {
        sendState[k] = s3[k].value;
        if (k === 'value') refreshEstimate();
      });
    });
    if (s3.size) s3.size.addEventListener('change', function () { sendState.size = s3.size.value; refreshEstimate(); });
    if (s3.payment) s3.payment.addEventListener('change', function () { sendState.payment = s3.payment.value; });
    refreshEstimate();

    // navigation
    function go(n) {
      if (n === 2) {
        setSum('s2', 'carrier', sendState.carrier);
        setSum('s2', 'from', sendState.from);
        setSum('s2', 'to', sendState.to);
      }
      if (n === 3) {
        setSum('s3', 'carrier', sendState.carrier);
        setSum('s3', 'from', sendState.from);
        setSum('s3', 'to', sendState.to);
      }
      showStep(wizard, n);
      stepTitle('Send Mzigo', Math.min(n, 3));
      renderStepper(stepper, Math.min(n, 3), stepLabels);
    }

    var next1 = step1.querySelector('[data-mz-next]');
    if (next1) next1.addEventListener('click', function () {
      if (!sendState.carrier || !sendState.from || !sendState.to) { toast('Choose a courier, origin and destination.', 'error'); return; }
      go(2);
    });
    var next2 = step2.querySelector('[data-mz-next]');
    if (next2) next2.addEventListener('click', function () {
      var name = (s2.name && s2.name.value || '').trim();
      var phone = (s2.phone && s2.phone.value || '').trim();
      var rname = (s2.rname && s2.rname.value || '').trim();
      var rphone = (s2.rphone && s2.rphone.value || '').trim();
      if (name.length < 3) { toast('Enter the sender name.', 'error'); s2.name && s2.name.focus(); return; }
      if (!/^(\+?254|0)?7\d{8}$/.test(phone.replace(/[^\d+]/g, ''))) { toast('Enter a valid sender phone number.', 'error'); s2.phone && s2.phone.focus(); return; }
      if (rname.length < 3) { toast('Enter the receiver name.', 'error'); s2.rname && s2.rname.focus(); return; }
      if (!/^(\+?254|0)?7\d{8}$/.test(rphone.replace(/[^\d+]/g, ''))) { toast('Enter a valid receiver phone number.', 'error'); s2.rphone && s2.rphone.focus(); return; }
      sendState.senderName = name; sendState.senderPhone = phone;
      sendState.receiverName = rname; sendState.receiverPhone = rphone;
      go(3);
    });
    var back2 = step2.querySelector('[data-mz-back]');
    if (back2) back2.addEventListener('click', function () { go(1); });
    var back3 = step3.querySelector('[data-mz-back]');
    if (back3) back3.addEventListener('click', function () { go(2); });
    var edit3 = step3.querySelector('[data-mz-edit]');
    if (edit3) edit3.addEventListener('click', function () { go(1); });

    var submit = step3.querySelector('[data-mz-submit]');
    if (submit) submit.addEventListener('click', function () {
      var description = (s3.description && s3.description.value || '').trim();
      var value = Number((s3.value && s3.value.value) || 0);
      var size = (s3.size && s3.size.value) || '';
      var payment = (s3.payment && s3.payment.value) || '';
      if (description.length < 3) { toast('Describe the parcel contents.', 'error'); s3.description && s3.description.focus(); return; }
      if (!(value > 0)) { toast('Declare the parcel value in KES.', 'error'); s3.value && s3.value.focus(); return; }
      if (!size) { toast('Select the package size.', 'error'); s3.size && s3.size.focus(); return; }
      if (!payment) { toast('Select the preferred payment method.', 'error'); s3.payment && s3.payment.focus(); return; }
      var partner = APP.partners.filter(function (p) { return p.name === sendState.carrier; })[0];
      var payload = {
        carrier_slug: partner ? partner.slug : '',
        from: sendState.from,
        to: sendState.to,
        sender_name: sendState.senderName,
        sender_phone: sendState.senderPhone,
        receiver_name: sendState.receiverName,
        receiver_phone: sendState.receiverPhone,
        description: description,
        size: size,
        value: value,
        payment: payment
      };
      submit.disabled = true;
      api('register-parcel.php', { method: 'POST', body: payload }).then(function (res) {
        var parcel = res.parcel;
        APP.packages.unshift(parcel);
        var mount = receipt.querySelector('[data-mz-receipt]');
        renderReceipt(mount, parcel, 'parcel');
        showStep(wizard, 4);
        stepper.innerHTML = '';
        var sub = $('[data-mz-wizard-sub]');
        if (sub) sub.textContent = 'Registered';
        toast('Parcel ' + parcel.tracking + ' saved to MySQL.');
      }).catch(function (err) {
        if (err.code === 'plan_limit') showUpgradeNotice(err);
        toast(err.message, 'error');
      }).then(function () { submit.disabled = false; });
    });


    go(initialSlug && initialFrom && initialTo ? 2 : 1);
  }

  /* --------------------------------------------------------------- errand flow */
  var errandState = { carrier: null, pickup: '', dropoff: '', senderName: '', senderPhone: '', recipientName: '', recipientPhone: '', description: '', size: '', distanceKm: '', instructions: '', payment: 'M-PESA' };
  function initErrand() {
    var wizard = $('[data-mz-errand-wizard]') || $('[data-mz-signwizard]') || $('[data-mz-send-wizard]');
    if (!wizard) return;
    var stepper = $('[data-mz-stepper]');
    var labels = ['Route', 'Contacts', 'Parcel'];
    var step1 = wizard.querySelector('[data-step="1"]');
    var step2 = wizard.querySelector('[data-step="2"]');
    var step3 = wizard.querySelector('[data-step="3"]');
    var receipt = wizard.querySelector('[data-step="4"]');
    var places = allDestinations().concat(['CBD, Nairobi', 'Westlands, Nairobi', 'Kilimani, Nairobi', 'Karen, Nairobi', 'Industrial Area, Nairobi', 'Nairobi CBD', 'Mombasa CBD', 'Kisumu CBD']);

    var carrierSel = createSelect({
      mount: step1.querySelector('[data-mz-mount="carrier"]'), items: APP.partners, label: '',
      placeholder: 'Search for a courier', icon: 'search', avatar: true,
      onChange: function (item) { errandState.carrier = item ? item.name : null; }
    });
    var pickupSel = createSelect({
      mount: step1.querySelector('[data-mz-mount="pickup"]'), items: places, label: 'From',
      placeholder: 'Search pickup address or landmark', icon: 'pin',
      onChange: function (v) { errandState.pickup = v || ''; update(); }
    });
    var dropSel = createSelect({
      mount: step1.querySelector('[data-mz-mount="dropoff"]'), items: places, label: 'To',
      placeholder: 'Search destination address or landmark', icon: 'pin',
      onChange: function (v) { errandState.dropoff = v || ''; update(); }
    });
    function update() {
      var next = step1.querySelector('[data-mz-next]');
      if (!next) return;
      next.disabled = !(errandState.pickup && errandState.dropoff);
    }
    update();

    var n2 = {
      name: step2.querySelector('[data-mz-sender-name]'),
      phone: step2.querySelector('[data-mz-sender-phone]'),
      rname: step2.querySelector('[data-mz-recipient-name]'),
      rphone: step2.querySelector('[data-mz-recipient-phone]')
    };
    var n3 = {
      description: step3.querySelector('[data-mz-description]'),
      size: step3.querySelector('[data-mz-errand-size]'),
      distance: step3.querySelector('[data-mz-errand-distance]'),
      instructions: step3.querySelector('[data-mz-instructions]'),
      payment: step3.querySelector('[data-mz-payment]'),
      estimate: step3.querySelector('[data-mz-estimate]')
    };
    function refreshEstimate() {
      var total = 300;
      errandState.quote = { base: 250, platform: 50, total: total };
      if (n3.estimate) {
        n3.estimate.innerHTML = '<div class="mz-fee-line"><span>Runner base fee</span><span>' + kes(250) + '</span></div>' +
          '<div class="mz-fee-line"><span>Platform fee</span><span>' + kes(50) + '</span></div>' +
          '<div class="mz-fee-line mz-fee-line--total"><span>Estimated total</span><span>' + kes(total) + '</span></div>';
      }
    }
    refreshEstimate();

    function go(n) {
      if (n === 2) { setSum('e2', 'carrier', errandState.carrier || 'Any runner'); setSum('e2', 'from', errandState.pickup); setSum('e2', 'to', errandState.dropoff); }
      if (n === 3) { setSum('e3', 'carrier', errandState.carrier || 'Any runner'); setSum('e3', 'from', errandState.pickup); setSum('e3', 'to', errandState.dropoff); }
      showStep(wizard, n);
      stepTitle('Run an errand', Math.min(n, 3));
      renderStepper(stepper, Math.min(n, 3), labels);
    }

    var next1 = step1.querySelector('[data-mz-next]');
    if (next1) next1.addEventListener('click', function () {
      if (!errandState.pickup || !errandState.dropoff) { toast('Choose the pickup and drop-off points.', 'error'); return; }
      go(2);
    });
    var next2 = step2.querySelector('[data-mz-next]');
    if (next2) next2.addEventListener('click', function () {
      var name = (n2.name && n2.name.value || '').trim();
      var phone = (n2.phone && n2.phone.value || '').trim();
      var rname = (n2.rname && n2.rname.value || '').trim();
      var rphone = (n2.rphone && n2.rphone.value || '').trim();
      if (name.length < 3) { toast('Enter the contact name.', 'error'); return; }
      if (!/^(\+?254|0)?7\d{8}$/.test(phone.replace(/[^\d+]/g, ''))) { toast('Enter a valid contact phone number.', 'error'); return; }
      if (rname.length < 3) { toast('Enter the recipient name.', 'error'); return; }
      if (!/^(\+?254|0)?7\d{8}$/.test(rphone.replace(/[^\d+]/g, ''))) { toast('Enter a valid recipient phone number.', 'error'); return; }
      errandState.senderName = name; errandState.senderPhone = phone;
      errandState.recipientName = rname; errandState.recipientPhone = rphone;
      go(3);
    });
    var back2 = step2.querySelector('[data-mz-back]');
    if (back2) back2.addEventListener('click', function () { go(1); });
    var back3 = step3.querySelector('[data-mz-back]');
    if (back3) back3.addEventListener('click', function () { go(2); });

    var submit = step3.querySelector('[data-mz-submit]');
    if (submit) submit.addEventListener('click', function () {
      var description = (n3.description && n3.description.value || '').trim();
      if (description.length < 3) { toast('Describe what the runner is collecting.', 'error'); return; }
      errandState.description = description;
      errandState.size = (n3.size && n3.size.value) || '';
      errandState.distanceKm = (n3.distance && n3.distance.value || '').trim();
      errandState.instructions = (n3.instructions && n3.instructions.value || '').trim();
      errandState.payment = (n3.payment && n3.payment.value) || 'M-PESA';
      var payload = {
        carrier: errandState.carrier || '',
        pickup: errandState.pickup,
        dropoff: errandState.dropoff,
        sender_name: errandState.senderName,
        sender_phone: errandState.senderPhone,
        receiver_name: errandState.recipientName,
        receiver_phone: errandState.recipientPhone,
        description: description,
        size: errandState.size,
        distance_km: errandState.distanceKm,
        instructions: errandState.instructions,
        payment: errandState.payment
      };
      var bookBtn = step3.querySelector('[data-mz-submit]');
      if (bookBtn) bookBtn.disabled = true;
      api('errands.php', { method: 'POST', body: payload }).then(function (res) {
        var record = res.errand;
        APP.errands.unshift(record);
        var mount = receipt.querySelector('[data-mz-receipt]');
        renderReceipt(mount, record, 'errand');
        showStep(wizard, 4);
        stepper.innerHTML = '';
        var sub = $('[data-mz-wizard-sub]');
        if (sub) sub.textContent = 'Booked';
        toast('Errand ' + record.ref + ' saved to MySQL.');
      }).catch(function (err) {
        toast(err.message, 'error');
      }).then(function () { if (bookBtn) bookBtn.disabled = false; });
    });


    // prefill from home errand tab
    var preCarrier = qs('carrier'); var prePickup = qs('pickup'); var preDrop = qs('dropoff');
    if (preCarrier) { var p = APP.partners.filter(function (x) { return x.slug === preCarrier; })[0]; if (p) { carrierSel.set(p.name); errandState.carrier = p.name; } }
    if (prePickup) { pickupSel.set(prePickup); errandState.pickup = prePickup; }
    if (preDrop) { dropSel.set(preDrop); errandState.dropoff = preDrop; }
    update();
    go(1);
  }


  /* ------------------------------------------------------------- SaaS pages */
  function showUpgradeNotice(err) {
    var host = $('[data-step="3"]');
    if (!host) return;
    var old = host.querySelector('[data-mz-upgrade]');
    if (old) old.remove();
    var meta = err.meta || {};
    var note = doc.createElement('div');
    note.setAttribute('data-mz-upgrade', '');
    note.className = 'mz-alert mz-alert--warn';
    note.innerHTML = '<strong>Plan limit reached.</strong> ' + esc(meta.used || '') + ' of ' + esc(meta.limit || '') +
      ' parcels used this month. <a class="mz-link" href="/billing">Upgrade your plan</a> to keep sending.';
    host.appendChild(note);
  }

  function formValue(form, name) {
    var el = form && form.elements ? form.elements.namedItem(name) : null;
    return el && typeof el.value === 'string' ? el.value.trim() : '';
  }

  function appRedirectOnAuth(err) {
    if (err && (err.status === 401 || err.code === 'unauthenticated')) {
      window.location.href = '/login?next=' + encodeURIComponent(window.location.pathname);
      return true;
    }
    return false;
  }

  function fillNote(host, message, kind) {
    if (!host) return;
    host.innerHTML = '<div class="mz-alert' + (kind ? ' mz-alert--' + kind : '') + '">' + message + '</div>';
  }

  function showTwoFactorPrompt(form, res) {
    if (form.querySelector('[data-twofa-prompt]')) return;
    var submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    var box = doc.createElement('div');
    box.className = 'mz-alert';
    box.setAttribute('data-twofa-prompt', '');
    box.style.marginTop = '12px';
    box.innerHTML = '<div style="margin-bottom:6px;">Enter the 6-digit authenticator code, or a one-time recovery code.</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' +
        '<input class="mz-input" name="twofa_code" inputmode="text" maxlength="20" placeholder="123456 or ABCDE-FGHIJ" style="max-width:220px;">' +
        '<button type="button" class="mz-btn" data-twofa-verify>Verify code</button>' +
      '</div><div class="mz-hint" data-twofa-error style="margin-top:6px;"></div>';
    form.appendChild(box);
    var verify = box.querySelector('[data-twofa-verify]');
    verify.addEventListener('click', function () {
      var code = (box.querySelector('[name="twofa_code"]').value || '').replace(/\D/g, '');
      if (code.length !== 6) { box.querySelector('[data-twofa-error]').textContent = 'Enter the 6-digit code.'; return; }
      verify.disabled = true;
      api('auth.php', { method: 'POST', body: { action: 'verify_2fa', code: code } }).then(function (r) {
        APP.csrf = r.csrf || APP.csrf;
        window.location.href = safeNext(r.redirect || '/profile');
      }).catch(function (err) {
        box.querySelector('[data-twofa-error]').textContent = err.message;
        verify.disabled = false;
      });
    });
  }

  function initLoginPage() {
    var demoBox = $('.mz-demo-box');
    if (demoBox) {
      if (APP.showDemoAccounts === false) demoBox.remove();
      else demoBox.hidden = false;
    }
    var loginTabs = $$('[data-login-tab]');
    function showLoginPanel(name) {
      loginTabs.forEach(function (btn) {
        var on = btn.getAttribute('data-login-tab') === name;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', String(on));
      });
      $$('[data-login-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-login-panel') !== name;
      });
    }
    loginTabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tab = btn.getAttribute('data-login-tab');
        showLoginPanel(tab);
        if (tab === 'freelancer') trackEvent('runner_join_start', { label: 'Freelancer tab' });
        if (tab === 'customer') trackEvent('receiver_signin', { label: 'Customer tab' });
      });
    });
    // Deep links: /login?role=freelancer or ?role=customer preselects the tab.
    var roleParam = new URLSearchParams(window.location.search).get('role');
    if (roleParam && ['business', 'phone', 'freelancer', 'customer'].indexOf(roleParam) !== -1) {
      showLoginPanel(roleParam);
    }

    /* business / admin sign-in (unchanged) */
    var form = $('[data-app-form="login"]');
    if (form) form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('auth.php', {
        method: 'POST',
        body: { action: 'login', identifier: formValue(form, 'identifier'), password: formValue(form, 'password') }
      }).then(function (res) {
        if (res && res.twofa_required) { showTwoFactorPrompt(form, res); return; }
        window.location.href = safeNext(res.redirect || '/profile');
      }).catch(function (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      });
    });

    /* freelancer: phone -> system-generated PIN -> verify */
    var freelancerPhone = '';
    var startForm = $('[data-freelancer-form="start"]');
    if (startForm) startForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var terms = startForm.querySelector('[name="runner_terms"]');
      if (terms && !terms.checked) {
        toast('Please accept the Runner Terms and Privacy Policy to continue.', 'error');
        return;
      }
      var btn = startForm.querySelector('[type="submit"]');
      var phone = formValue(startForm, 'phone');
      btn.disabled = true;
      api('auth.php', { method: 'POST', body: { action: 'freelancer-start', phone: phone } })
        .then(function (res) {
          freelancerPhone = res.phone || phone;
          trackEvent('runner_phone_submitted', { label: 'Runner phone submitted' });
          var hint = $('[data-freelancer-hint]');
          if (hint) {
            hint.innerHTML = res.demo_pin
              ? 'Demo PIN: <strong>' + esc(res.demo_pin) + '</strong>. In production this PIN is sent by SMS.'
              : 'Enter the 4-digit PIN sent to your phone.';
          }
          var step1 = $('[data-freelancer-step="1"]');
          var step2 = $('[data-freelancer-step="2"]');
          if (step1) step1.hidden = true;
          if (step2) step2.hidden = false;
          var pinInput = $('[data-freelancer-form="login"] [name="pin"]');
          if (pinInput) { pinInput.value = ''; pinInput.focus(); }
          toast(res.demo_pin ? 'PIN issued for ' + freelancerPhone + ' — demo PIN ' + res.demo_pin : 'PIN sent to ' + freelancerPhone, 'info');
          btn.disabled = false;
        }).catch(function (err) {
          toast(err.message, 'error');
          btn.disabled = false;
        });
    });

    var resendPin = $('[data-freelancer-resend]');
    if (resendPin) resendPin.addEventListener('click', function () {
      if (!freelancerPhone) { toast('Enter your phone number first.', 'error'); return; }
      resendPin.disabled = true;
      var original = resendPin.textContent;
      resendPin.textContent = 'Sending…';
      api('auth.php', { method: 'POST', body: { action: 'freelancer-start', phone: freelancerPhone } })
        .then(function (res) {
          if (res.demo_pin) {
            var hint = $('[data-freelancer-hint]');
            if (hint) hint.innerHTML = 'New demo PIN: <strong>' + esc(res.demo_pin) + '</strong>. In production this PIN is sent by SMS.';
          }
          toast(res.demo_pin ? 'New PIN issued — demo PIN ' + res.demo_pin : 'A new PIN was sent to your phone.', 'info');
          resendPin.textContent = 'PIN sent';
          setTimeout(function () { resendPin.textContent = original; resendPin.disabled = false; }, 6000);
        }).catch(function (err) {
          toast(err.message, 'error');
          resendPin.textContent = original;
          resendPin.disabled = false;
        });
    });

    var freelancerForm = $('[data-freelancer-form="login"]');
    if (freelancerForm) freelancerForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = freelancerForm.querySelector('[type="submit"]');
      var pin = formValue(freelancerForm, 'pin');
      if (!freelancerPhone) { toast('Enter your phone number first.', 'error'); showLoginPanel('freelancer'); return; }
      btn.disabled = true;
      api('auth.php', { method: 'POST', body: { action: 'freelancer-login', phone: freelancerPhone, pin: pin } })
        .then(function (res) {
          trackEvent('runner_pin_verified', { label: 'Runner PIN verified' });
          toast('Signed in as a freelancer.');
          window.location.href = res.redirect || '/freelancer';
        }).catch(function (err) {
          toast(err.message, 'error');
          btn.disabled = false;
        });
    });

    var back = $('[data-freelancer-back]');
    if (back) back.addEventListener('click', function () {
      var step1 = $('[data-freelancer-step="1"]');
      var step2 = $('[data-freelancer-step="2"]');
      if (step1) step1.hidden = false;
      if (step2) step2.hidden = true;
    });

    /* unified phone sign-in: customer or freelancer */
    var phoneRole = 'customer';
    var phoneNumber = '';
    var pStart = $('[data-phone-form="start"]');
    var pLogin = $('[data-phone-form="login"]');
    function syncPhoneRole() {
      var roleSel = $('[data-phone-role]');
      phoneRole = roleSel ? roleSel.value : 'customer';
      var channelWrap = $('[data-phone-channel-wrap]');
      var terms = document.querySelector('[data-phone-terms]');
      if (channelWrap) channelWrap.hidden = phoneRole !== 'customer';
      if (terms) {
        terms.hidden = phoneRole !== 'freelancer';
        var box = terms.querySelector('[name="runner_terms"]');
        if (box) box.required = phoneRole === 'freelancer';
      }
      var codeLabel = $('[data-phone-code-label]');
      if (codeLabel) codeLabel.textContent = phoneRole === 'freelancer' ? '4-digit runner PIN' : '4-digit code';
    }
    var phoneRoleSel = $('[data-phone-role]');
    if (phoneRoleSel) phoneRoleSel.addEventListener('change', syncPhoneRole);
    syncPhoneRole();
    if (pStart) pStart.addEventListener('submit', function (ev) {
      ev.preventDefault();
      syncPhoneRole();
      var btn = pStart.querySelector('[type="submit"]');
      var phone = formValue(pStart, 'phone');
      if (!phone) { toast('Enter your phone number.', 'error'); return; }
      if (phoneRole === 'freelancer') {
        var terms = pStart.querySelector('[name="runner_terms"]');
        if (terms && !terms.checked) { toast('Please accept the Runner Terms and Privacy Policy to continue.', 'error'); return; }
      }
      var body = { action: phoneRole === 'freelancer' ? 'freelancer-start' : 'customer-start', phone: phone };
      if (phoneRole === 'customer') body.channel = formValue(pStart, 'channel') || 'sms';
      btn.disabled = true;
      api('auth.php', { method: 'POST', body: body }).then(function (res) {
        phoneNumber = res.phone || phone;
        var demo = res.demo_code || res.demo_pin || '';
        var hint = $('[data-phone-hint]');
        if (hint) hint.innerHTML = demo
          ? 'Demo ' + (phoneRole === 'freelancer' ? 'PIN' : 'code') + ': <strong>' + esc(demo) + '</strong>.'
          : (phoneRole === 'freelancer' ? 'Enter the 4-digit PIN sent to your phone.' : 'Enter the code sent to ' + esc(res.sent_to || 'your phone') + '.');
        var step1 = $('[data-phone-step="1"]');
        var step2 = $('[data-phone-step="2"]');
        if (step1) step1.hidden = true;
        if (step2) step2.hidden = false;
        var codeInput = pLogin ? pLogin.querySelector('[name="code"]') : null;
        if (codeInput) { codeInput.value = ''; codeInput.focus(); }
        toast(demo ? 'Demo ' + (phoneRole === 'freelancer' ? 'PIN' : 'code') + ': ' + demo : 'Code sent to ' + phoneNumber, 'info');
        btn.disabled = false;
      }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    if (pLogin) pLogin.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = pLogin.querySelector('[type="submit"]');
      var code = formValue(pLogin, 'code');
      if (!phoneNumber) { toast('Enter your phone number first.', 'error'); showLoginPanel('phone'); return; }
      btn.disabled = true;
      api('auth.php', { method: 'POST', body: { action: phoneRole === 'freelancer' ? 'freelancer-login' : 'customer-login', phone: phoneNumber, code: code, pin: code } })
        .then(function (res) {
          toast(phoneRole === 'freelancer' ? 'Signed in as a freelancer.' : 'Signed in as a customer.');
          window.location.href = res.redirect || (phoneRole === 'freelancer' ? '/freelancer' : '/customer');
        }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    var phoneBack = $('[data-phone-back]');
    if (phoneBack) phoneBack.addEventListener('click', function () {
      var step1 = $('[data-phone-step="1"]');
      var step2 = $('[data-phone-step="2"]');
      if (step1) step1.hidden = false;
      if (step2) step2.hidden = true;
    });

    /* customer: phone -> system-generated code -> verify */
    var customerPhone = '';
    var cStart = $('[data-customer-form="start"]');
    if (cStart) cStart.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = cStart.querySelector('[type="submit"]');
      var phone = formValue(cStart, 'phone');
      var channel = formValue(cStart, 'channel') || 'sms';
      btn.disabled = true;
      api('auth.php', { method: 'POST', body: { action: 'customer-start', phone: phone, channel: channel } })
        .then(function (res) {
          customerPhone = res.phone || phone;
          var hint = $('[data-customer-hint]');
          var where = res.channel === 'email' ? (res.sent_to || 'your email') : 'your phone';
          if (hint) {
            hint.innerHTML = res.demo_code
              ? 'Demo code: <strong>' + esc(res.demo_code) + '</strong>. In production this code is sent by ' + esc(res.channel === 'email' ? 'email' : 'SMS') + '.'
              : 'Enter the 4-digit code sent to ' + esc(where) + '.';
          }
          var step1 = $('[data-customer-step="1"]');
          var step2 = $('[data-customer-step="2"]');
          if (step1) step1.hidden = true;
          if (step2) step2.hidden = false;
          var codeInput = $('[data-customer-form="login"] [name="code"]');
          if (codeInput) { codeInput.value = ''; codeInput.focus(); }
          toast(res.demo_code ? 'Code issued for ' + customerPhone + ' — demo code ' + res.demo_code : 'Code sent via ' + (res.channel === 'email' ? 'email' : 'SMS') + ' to ' + where, 'info');
          btn.disabled = false;
        }).catch(function (err) {
          toast(err.message, 'error');
          btn.disabled = false;
        });
    });
    var cLogin = $('[data-customer-form="login"]');
    if (cLogin) cLogin.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = cLogin.querySelector('[type="submit"]');
      if (!customerPhone) { toast('Enter your phone number first.', 'error'); showLoginPanel('customer'); return; }
      btn.disabled = true;
      api('auth.php', { method: 'POST', body: { action: 'customer-login', phone: customerPhone, code: formValue(cLogin, 'code') } })
        .then(function (res) { window.location.href = res.redirect || '/customer'; })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    var cBack = $('[data-customer-back]');
    if (cBack) cBack.addEventListener('click', function () {
      var step1 = $('[data-customer-step="1"]');
      var step2 = $('[data-customer-step="2"]');
      if (step1) step1.hidden = false;
      if (step2) step2.hidden = true;
    });
  }

  function initForgotPasswordPage() {
    var form = $('[data-forgot-password-form]');
    if (!form) return;
    var result = $('[data-forgot-password-result]');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      if (result) { result.hidden = true; }
      api('password-reset.php', { method: 'POST', body: { action: 'request', email: formValue(form, 'email') } })
        .then(function (res) {
          if (result) {
            result.hidden = false;
            result.innerHTML = esc(res.message) +
              (res.demo_link ? '<div style="margin-top:8px;"><a class="mz-link" href="' + esc(res.demo_link) + '">Open the demo reset link</a></div>' : '');
          }
          btn.disabled = false;
        }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function initResetPasswordPage() {
    var form = $('[data-reset-password-form]');
    if (!form) return;
    var token = qs('token') || '';
    var result = $('[data-reset-password-result]');
    var submit = form.querySelector('[type="submit"]');
    if (!token) {
      if (result) { result.hidden = false; result.textContent = 'This reset link is missing a token. Request a new link.'; }
      submit.disabled = true;
      return;
    }
    api('password-reset.php?token=' + encodeURIComponent(token)).then(function (res) {
      if (!res.valid) {
        if (result) { result.hidden = false; result.textContent = 'This reset link is invalid or has expired. Request a new link.'; }
        submit.disabled = true;
      }
    }).catch(function () {});
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      submit.disabled = true;
      api('password-reset.php', { method: 'POST', body: {
        action: 'reset', token: token, password: formValue(form, 'password'), password_confirm: formValue(form, 'password_confirm')
      }}).then(function (res) {
        if (result) { result.hidden = false; result.textContent = res.message || 'Password updated.'; }
        toast('Password updated. Sign in with your new password.');
        setTimeout(function () { window.location.href = '/login'; }, 1200);
      }).catch(function (err) { toast(err.message, 'error'); submit.disabled = false; });
    });
  }

  function initVerifyEmailPage() {
    var result = $('[data-verify-email-result]');
    var token = qs('token') || '';
    if (!result) return;
    if (!token) {
      result.textContent = 'Open the verification link from your email to confirm your address.';
      return;
    }
    api('email-verification.php', { method: 'POST', body: { action: 'verify', token: token } })
      .then(function (res) {
        result.className = 'mz-alert';
        result.innerHTML = esc(res.message || 'Email verified.') + ' <a class="mz-link" href="/login">Sign in</a>';
        toast('Email verified.');
      }).catch(function (err) {
        result.className = 'mz-alert';
        result.textContent = err.message;
      });
  }

  function initSignupPage() {
    var form = $('[data-app-form="signup"]');
    if (!form) return;
    if (APP.pricingMode === 'free') {
      form.parentElement.insertAdjacentHTML('beforebegin',
        '<div class="mz-alert" style="margin-bottom:16px;">Mzigo.online is free to use right now — create an account and start sending. No trial or payment required.</div>');
    }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('auth.php', {
        method: 'POST',
        body: {
          action: 'signup',
          business_name: formValue(form, 'business_name'),
          name: formValue(form, 'name'),
          email: formValue(form, 'email'),
          phone: formValue(form, 'phone'),
          password: formValue(form, 'password')
        }
      }).then(function (res) {
        toast('Workspace created — welcome to Mzigo.online!');
        window.location.href = res.redirect || '/profile';
      }).catch(function (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      });
    });
  }

  function initFreelancerPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Ffreelancer'; return; }
    if (APP.user.role !== 'freelancer') {
      window.location.href = APP.user.role === 'platform_admin' ? '/admin'
        : APP.user.role === 'customer' ? '/customer' : '/profile';
      return;
    }
    initAppNav();
    var host = $('[data-app="freelancer"]');
    if (!host) return;
    loadFreelancer(host);
  }

  function loadFreelancer(host) {
    host.innerHTML = '<div class="mz-saas-card">Loading your runner dashboard…</div>';
    api('freelancer.php').then(function (data) {
      renderFreelancer(host, data);
    }).catch(function (err) {
      host.innerHTML = '<div class="mz-saas-card"><div class="mz-card__title">Could not load your runner dashboard</div>' +
        '<p class="mz-hint">' + esc(err.message) + '</p>' +
        '<button type="button" class="mz-btn" data-runner-retry>Retry</button></div>';
      var retry = host.querySelector('[data-runner-retry]');
      if (retry) retry.addEventListener('click', function () { loadFreelancer(host); });
    });
  }

  function runnerOptionList(options, selected, placeholder) {
    var html = '<option value="">' + esc(placeholder || 'Choose') + '</option>';
    Object.keys(options || {}).forEach(function (key) {
      html += '<option value="' + esc(key) + '"' + (key === selected ? ' selected' : '') + '>' + esc(options[key]) + '</option>';
    });
    return html;
  }

  function renderFreelancer(host, data) {
    setTimeout(function () { mountReferralCard(host, 'runner'); }, 0);
    var u = APP.user;
    var p = data.profile || {};
    var opts = data.options || {};
    var earn = data.earnings || {};
    var payoutCfg = data.payout_config || { mode: 'demo', schedule: 'instant', min_kes: 50 };
    var payoutMin = Number(payoutCfg.min_kes || 50);
    var payoutMode = payoutCfg.mode || 'demo';
    var kycRequired = data.kyc_required !== false;
    var kycApproved = !kycRequired || (p.kyc_status === 'approved');
    var missing = data.missing || [];
    var complete = !!p.onboarding_completed_at;
    var days = Array.isArray(p.availability_days) ? p.availability_days : [];
    var dayText = days.map(function (key) { return (opts.days || {})[key] || key; }).join(', ');
    var windowText = (opts.windows || {})[p.availability_window] || '';
    var statusBadge = complete
      ? '<span class="mz-badge mz-badge--ok">Active</span>'
      : '<span class="mz-badge mz-badge--warn">Profile incomplete</span>';

    var onboarding = '';
    if (!complete) {
      var labels = {
        name: 'Your name', areas: 'Areas you serve', availability_days: 'Availability days',
        availability_window: 'Availability window', vehicle_type: 'Vehicle type',
        payout_phone: 'M-Pesa payout number', terms: 'Terms acceptance'
      };
      var remaining = missing.map(function (key) {
        return '<li style="display:flex;align-items:center;gap:9px;list-style:none;">' +
          '<span aria-hidden="true" style="width:16px;height:16px;flex:none;border:2px solid #b9cfae;border-radius:50%;display:inline-block;"></span>' +
          esc(labels[key] || key) + '</li>';
      }).join('');
      onboarding =
        '<div class="mz-saas-card" style="margin-bottom:16px;" data-runner-onboarding>' +
          '<div class="mz-card__title">Get ready for jobs</div>' +
          '<p class="mz-hint">Finish these steps so we can match you with errands in your area.</p>' +
          '<ul style="list-style:none;padding:0;margin:10px 0 0;">' + remaining + '</ul>' +
          '<a class="mz-btn" style="margin-top:12px;" href="#runner-profile">Complete your runner profile</a>' +
        '</div>';
    }

    var jobData = data.jobs || {};
    var availableJobs = jobData.available || [];
    var activeJobs = jobData.active || [];
    var completedJobs = jobData.completed || [];
    var notes = data.notifications || { items: [], unread: 0 };
    var areas = data.matching_areas || [];
    var payouts = data.payouts || [];
    var statusLabel = { accepted: 'Accepted', picked_up: 'Picked up', delivered: 'Delivered' };
    var canAccept = complete && !!p.available_now && kycApproved;

    var areaNote = areas.length
      ? '<p class="mz-hint" style="margin:0 0 10px;">Showing errands that match your areas: <strong>' + esc(areas.join(', ')) + '</strong>.</p>'
      : '';
    var availableBody = availableJobs.length
      ? availableJobs.map(function (job) {
          return '<div class="mz-list-card" style="margin-bottom:10px;">' +
            '<div class="mz-row">' +
              '<div style="min-width:0;">' +
                '<div class="mz-mono" style="font-weight:700;">' + esc(job.ref) + '</div>' +
                '<div style="font-size:14px;">' + esc(job.pickup) + ' → ' + esc(job.dropoff) + '</div>' +
                (job.description ? '<div class="mz-hint">' + esc(job.description) + '</div>' : '') +
              '</div>' +
              '<div style="text-align:right;flex:none;"><div style="font-weight:800;">' + money(job.fee_kes) + '</div><div class="mz-cap">fee</div></div>' +
            '</div>' +
            '<div class="mz-row" style="margin-top:10px;justify-content:flex-start;gap:8px;flex-wrap:wrap;">' +
              '<button type="button" class="mz-btn" data-accept-job="' + job.id + '"' + (canAccept ? '' : ' disabled') + '>Accept job</button>' +
              '<button type="button" class="mz-tab" data-decline-job="' + job.id + '">Not now</button>' +
              (canAccept ? '' : '<span class="mz-hint" style="margin:0;">' + (!complete ? 'Complete your profile first' : (!kycApproved ? 'Verify your ID to accept' : 'Switch availability on to accept')) + '</span>') +
            '</div>' +
          '</div>';
        }).join('')
      : '<div class="mz-empty-note" style="padding:20px;">' +
          '<strong>No available errands' + (areas.length ? ' in your areas' : '') + ' right now.</strong><br>' +
          (complete
            ? (areas.length
                ? 'New errands in ' + esc(areas.join(', ')) + ' will appear here. Refresh to check again.'
                : 'New errands appear here as senders book them. Refresh to check again.')
            : 'Complete your runner profile to accept errands.') +
        '</div>';

    var activeBody = activeJobs.length
      ? activeJobs.map(function (job) {
          var contact = job.contact_name
            ? '<div class="mz-hint">Pickup: ' + esc(job.contact_name) + ' · ' + esc(job.contact_phone) +
              '<br>Receiver: ' + esc(job.receiver_name) + ' · ' + esc(job.receiver_phone) + '</div>'
            : '';
          var actions = '';
          if (job.status === 'accepted') {
            actions = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">' +
              '<button type="button" class="mz-btn" data-pickup-job="' + job.id + '">Confirm pickup</button>' +
              '<button type="button" class="mz-tab" data-cancel-job="' + job.id + '">Cancel job</button>' +
            '</div>';
          } else if (job.status === 'picked_up') {
            actions = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">' +
              '<input class="mz-input" style="max-width:320px;" data-proof-note="' + job.id + '" placeholder="Delivery note (e.g. handed to receiver)">' +
              (job.otp_required
                ? '<input class="mz-input" style="max-width:200px;" inputmode="numeric" maxlength="6" data-delivery-otp="' + job.id + '" placeholder="Receiver 6-digit code">' +
                  '<button type="button" class="mz-tab" data-resend-otp="' + job.id + '">Resend receiver code</button>'
                : '') +
              '<input class="mz-input" type="file" accept="image/jpeg,image/png" data-proof-photo="' + job.id + '" aria-label="Delivery photo (optional)">' +
              '<button type="button" class="mz-btn" data-deliver-job="' + job.id + '">Mark delivered</button>' +
              '<button type="button" class="mz-tab" data-cancel-job="' + job.id + '">Cancel job</button>' +
            '</div>';
          }
          return '<div class="mz-list-card" style="margin-bottom:10px;">' +
            '<div class="mz-row"><div style="min-width:0;">' +
              '<div class="mz-mono" style="font-weight:700;">' + esc(job.ref) + '</div>' +
              '<div style="font-size:14px;">' + esc(job.pickup) + ' → ' + esc(job.dropoff) + '</div>' +
              contact +
            '</div><span class="mz-pill">' + esc(statusLabel[job.status] || job.status) + '</span></div>' +
            '<p class="mz-hint" style="margin:8px 0 0;">Fee ' + money(job.fee_kes) + ' · ' + (job.description ? esc(job.description) : 'Errand') + '</p>' +
            actions +
          '</div>';
        }).join('')
      : '<div class="mz-hint">No active jobs. Accept an errand from the available list.</div>';

    var completedBody = completedJobs.length
      ? completedJobs.map(function (job) {
          return '<div class="mz-list-card" style="margin-bottom:8px;">' +
            '<div class="mz-row"><div style="min-width:0;">' +
              '<div class="mz-mono" style="font-weight:700;">' + esc(job.ref) + '</div>' +
              '<div class="mz-hint">' + esc(job.pickup) + ' → ' + esc(job.dropoff) + ' · ' + esc(fmtDate(job.delivered_at || job.created_at)) + '</div>' +
            '</div><strong>' + money(job.fee_kes) + '</strong></div>' +
            (job.proof_note ? '<div class="mz-hint">Proof: ' + esc(job.proof_note) + '</div>' : '') +
          '</div>';
        }).join('')
      : '<div class="mz-hint">No completed jobs yet.</div>';

    var notificationsCard = notes.items.length
      ? '<div class="mz-saas-card" style="margin-bottom:16px;" data-runner-notifications>' +
          '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Updates' +
            (notes.unread ? ' <span class="mz-badge mz-badge--warn">' + notes.unread + ' new</span>' : '') + '</div>' +
            (notes.unread ? '<button type="button" class="mz-tab" data-notifications-read>Mark all read</button>' : '') +
          '</div>' +
          notes.items.map(function (n) {
            return '<div style="border-bottom:1px solid rgba(60,82,35,.10);padding:9px 0;">' +
              '<div style="font-size:13px;font-weight:' + (n.read_at ? '500' : '700') + ';">' + esc(n.title) + '</div>' +
              '<div class="mz-hint">' + esc(n.body) + '</div>' +
              '<div class="mz-hint">' + esc(fmtDate(n.created_at)) + '</div>' +
            '</div>';
          }).join('') +
        '</div>'
      : '';

    var vehicleOptions = (opts.vehicles || []).map(function (v) {
      return '<option value="' + esc(v) + '"' + (v === p.vehicle_type ? ' selected' : '') + '>' + esc(v) + '</option>';
    }).join('');
    var dayChecks = Object.keys(opts.days || {}).map(function (key) {
      return '<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin:0 12px 8px 0;">' +
        '<input type="checkbox" data-runner-day value="' + esc(key) + '"' + (days.indexOf(key) !== -1 ? ' checked' : '') + '> ' +
        esc((opts.days || {})[key]) + '</label>';
    }).join('');
    var payoutLabel = p.payout_phone
      ? 'Payouts to <strong>' + esc(p.payout_phone) + '</strong>'
      : 'No payout number saved yet — add one in your runner profile.';

    var profileForm =
      '<div class="mz-saas-card" id="runner-profile" style="margin-top:16px;">' +
        '<div class="mz-card__title">Runner profile &amp; availability</div>' +
        '<p class="mz-hint">We use these details to match you with errands. Your payout number is where M-Pesa earnings are sent.</p>' +
        '<form data-runner-profile style="margin-top:10px;">' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Full name</span>' +
              '<input class="mz-input" name="name" value="' + esc(p.name || u.name || '') + '" required></label>' +
            '<label class="mz-field"><span class="mz-label">Areas you can serve</span>' +
              '<input class="mz-input" name="areas" value="' + esc(p.areas || '') + '" placeholder="e.g. Nairobi CBD, Westlands, Kilimani" required></label>' +
          '</div>' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Vehicle</span>' +
              '<select class="mz-input" name="vehicle_type" required><option value="">Choose vehicle</option>' + vehicleOptions + '</select></label>' +
            '<label class="mz-field"><span class="mz-label">Availability window</span>' +
              '<select class="mz-input" name="availability_window" required>' + runnerOptionList(opts.windows, p.availability_window, 'Choose window') + '</select></label>' +
          '</div>' +
          '<div class="mz-label" style="margin-top:6px;">Days you can work</div>' +
          '<div style="display:flex;flex-wrap:wrap;margin-top:4px;">' + dayChecks + '</div>' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">M-Pesa payout number</span>' +
              '<input class="mz-input" name="payout_phone" type="tel" value="' + esc(p.payout_phone || '') + '" placeholder="0712 345 678" required></label>' +
            '<label class="mz-field"><span class="mz-label">National ID (optional)</span>' +
              '<input class="mz-input" name="id_number" value="' + esc(p.id_number || '') + '"></label>' +
          '</div>' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Maximum distance (km)</span>' +
              '<input class="mz-input" name="max_distance_km" type="number" min="1" max="200" value="' + esc(p.max_distance_km || '') + '" placeholder="Optional"></label>' +
            '<label class="mz-field"><span class="mz-label">Preferred parcel sizes</span>' +
              '<input class="mz-input" name="preferred_sizes" value="' + esc(p.preferred_sizes || '') + '" placeholder="e.g. Envelope, Small, Medium"></label>' +
          '</div>' +
          '<label style="display:flex;align-items:center;gap:8px;margin:8px 0;font-size:13px;">' +
            '<input type="checkbox" name="job_alerts"' + (p.job_alerts === false ? '' : ' checked') + '> Send me updates about matching errands' +
          '</label>' +
          '<label style="display:flex;align-items:flex-start;gap:9px;font-size:12px;line-height:1.5;color:var(--mz-muted-fg);">' +
            '<input type="checkbox" name="terms_accepted" style="margin-top:2px;"' + (p.terms_accepted_at ? ' checked' : '') + ' required>' +
            '<span>I agree to the <a class="mz-link" href="/terms#runner-terms" target="_blank" rel="noopener">Runner Terms</a> and <a class="mz-link" href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</span>' +
          '</label>' +
          '<button class="mz-btn" type="submit" style="margin-top:12px;">Save runner profile</button>' +
        '</form>' +
        '<p class="mz-hint" style="margin-top:10px;">How payouts work: <a class="mz-link" href="/earn#pay">see runner payouts</a> · <a class="mz-link" href="/contact">Get help</a></p>' +
      '</div>';

    host.innerHTML =
      '<div class="mz-saas-grid mz-saas-grid--2" style="margin-bottom:16px;">' +
        '<div class="mz-saas-card">' +
          '<div class="mz-row">' +
            '<div style="display:flex;align-items:center;gap:12px;min-width:0;">' + avatar(u.name, 'lg') +
              '<div style="min-width:0;"><div class="mz-cap">Runner account</div>' +
              '<div style="font-size:18px;font-weight:700;">' + esc(p.name || u.name) + '</div>' +
              '<div style="font-size:13px;color:var(--mz-muted-fg);">' + esc(u.phone || '') + '</div></div>' +
            '</div>' + statusBadge +
          '</div>' +
          '<div style="margin-top:14px;font-size:12px;color:var(--mz-muted-fg);">Joined ' +
            esc(fmtDate(u.created_at || nowISO())) + ' · phone + PIN sign-in</div>' +
        '</div>' +
        '<div class="mz-saas-card">' +
          '<div class="mz-card__title">Your PIN</div>' +
          '<p class="mz-hint" data-freelancer-pin-note>Your 4-digit PIN is system generated. In this demo the PIN is <strong>1234</strong>.</p>' +
          '<button type="button" class="mz-btn mz-btn--outline" data-freelancer-reset>Reset PIN</button>' +
        '</div>' +
      '</div>' +
      onboarding +
      '<div data-runner-kyc style="margin-bottom:16px;"></div>' +
      '<div class="mz-row" style="gap:10px;margin-bottom:16px;justify-content:flex-start;flex-wrap:wrap;" data-freelancer-actions>' +
        '<a class="mz-btn" href="#runner-profile">' + (complete ? 'Update profile &amp; availability' : 'Complete your profile') + '</a>' +
        '<a class="mz-btn mz-btn--outline" href="#earnings">View earnings</a>' +
        '<a class="mz-tab" href="/earn#pay">How runners get paid</a>' +
        '<a class="mz-tab" href="/contact">Help &amp; support</a>' +
      '</div>' +
      '<div class="mz-saas-card" style="margin-bottom:16px;">' +
        '<div class="mz-row">' +
          '<div><div class="mz-card__title" style="margin:0;">Availability</div>' +
            '<p class="mz-hint" style="margin:4px 0 0;">' +
              (complete
                ? (p.available_now ? 'You are available for jobs.' : 'You are offline. Switch on to receive errands.')
                : 'Complete your profile to switch availability on.') +
            '</p></div>' +
          '<label style="display:flex;align-items:center;gap:8px;font-weight:600;">' +
            '<input type="checkbox" data-runner-available' + (p.available_now ? ' checked' : '') + (complete ? '' : ' disabled') + '> Available' +
          '</label>' +
        '</div>' +
        (dayText ? '<p class="mz-hint" style="margin-top:10px;">' + esc(dayText) + (windowText ? ' · ' + esc(windowText) : '') + '</p>' : '') +
      '</div>' +
      '<div class="mz-stats" id="earnings" style="margin-bottom:16px;">' +
        '<div class="mz-stat"><div class="mz-cap">Earnings this month</div><div class="mz-stat__value">' + money(earn.this_month_kes || 0) + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Pending payout</div><div class="mz-stat__value">' + money(earn.pending_kes || 0) + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Bonus available</div><div class="mz-stat__value">' + money(earn.bonus_pending_kes || 0) + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Paid to date</div><div class="mz-stat__value">' + money(earn.paid_kes || 0) + '</div></div>' +
      '</div>' +
      '<div class="mz-saas-card" style="margin-bottom:16px;">' +
        '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Earnings &amp; payouts</div>' +
          '<span class="mz-badge">M-Pesa · ' + esc(p.payout_phone || 'not set') + '</span></div>' +
        '<p class="mz-hint">' + esc(String(earn.completed_jobs || 0)) + ' completed jobs. Completed job fees are added to your pending payout; request a payout to your M-Pesa number below.</p>' +
        (earn.tier && earn.tier.label ? '<p class="mz-hint" style="margin:4px 0 0;color:var(--mz-hunter);font-weight:600;">' + esc(earn.tier.label) + ' tier · ' + earn.tier.rate + '% quality bonus · ' + (earn.tier.stats && earn.tier.stats.rating !== null ? earn.tier.stats.rating + '★' : 'no ratings yet') + (earn.tier.next ? ' · ' + esc(earn.tier.next.jobs_needed) + ' jobs to ' + esc(earn.tier.next.label) : ' · top tier') + ' · cap ' + money(earn.tier.cap_kes || 0) + '/month</p>' : '') +
        (earn.bonus_pending_kes > 0 ? '<p class="mz-hint" style="margin:4px 0 0;color:var(--mz-hunter);font-weight:600;">Includes ' + money(earn.bonus_pending_kes) + ' incentive bonus for rated and volume-eligible jobs.</p>' : '') +
        '<div class="mz-row" style="gap:10px;justify-content:flex-start;flex-wrap:wrap;">' +
          '<button type="button" class="mz-btn" data-runner-payout' + (complete && earn.pending_kes >= payoutMin ? '' : ' disabled') + '>Request M-Pesa payout' + (payoutMode === 'demo' ? ' (demo)' : '') + '</button>' +
          '<span class="mz-hint" style="margin:0;align-self:center;">Minimum KES ' + payoutMin + ' · ' + esc(payoutMode === 'demo' ? 'demo M-Pesa transfer' : 'live M-Pesa transfer') + ' · ' + esc(payoutCfg.schedule || 'instant') + '</span>' +
        '</div>' +
        (earn.pending_kes > 0 && earn.pending_kes < 50
          ? '<p class="mz-hint" style="margin:8px 0 0;">Complete a few more jobs to reach the KES 50 minimum.</p>'
          : '') +
        '<div style="margin-top:14px;">' +
          '<div class="mz-card__title" style="font-size:14px;">Payout history</div>' +
          (payouts.length
            ? payouts.map(function (po) {
                return '<div class="mz-list-card" style="margin-bottom:8px;padding:10px 12px;">' +
                  '<div class="mz-row"><div><div class="mz-mono" style="font-weight:700;">' + esc(po.ref) + '</div>' +
                    '<div class="mz-hint">' + esc(fmtDate(po.paid_at || po.requested_at)) + ' · ' + esc(po.phone) + (po.bonus_kes ? ' · bonus ' + money(po.bonus_kes) : '') + '</div></div>' +
                    '<div style="text-align:right;"><strong>' + money(po.amount_kes) + '</strong>' +
                      '<div class="mz-badge mz-badge--ok" style="margin-top:2px;">' + esc(po.status) + '</div></div></div>' +
                  (po.provider_ref ? '<div class="mz-hint">M-Pesa ref ' + esc(po.provider_ref) + '</div>' : '') +
                '</div>';
              }).join('')
            : '<div class="mz-hint">No payouts yet. Completed jobs will show here after you request a payout.</div>') +
        '</div>' +
      '</div>' +
      '<p class="mz-hint" style="margin:-6px 0 16px;">' + payoutLabel + '. ' +
        (earn.rating_avg ? 'Rating <strong>' + esc(String(earn.rating_avg)) + '/5</strong> from ' + esc(String(earn.rating_count)) + ' rated jobs. ' : '') +
        '<a class="mz-link" href="/earn#pay">How earnings work</a></p>' +
      notificationsCard +
      '<div class="mz-saas-card" style="margin-bottom:16px;" id="freelancer-jobs">' +
        '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Jobs</div>' +
          '<button type="button" class="mz-tab" data-runner-refresh>Refresh</button></div>' +
        '<div class="mz-card__title" style="font-size:14px;margin-top:14px;">Available errands' +
          (availableJobs.length ? ' <span class="mz-badge">' + availableJobs.length + '</span>' : '') + '</div>' +
        areaNote +
        availableBody +
        '<div class="mz-card__title" style="font-size:14px;margin-top:18px;">Active jobs</div>' +
        activeBody +
        '<div class="mz-card__title" style="font-size:14px;margin-top:18px;">Completed</div>' +
        completedBody +
      '</div>' +
      profileForm +
      '<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">' +
        '<a class="mz-tab" href="/track-mzigo">Track a parcel</a>' +
        '<button type="button" class="mz-tab mz-signout-inline" data-freelancer-signout>Sign out</button>' +
      '</div>';

    var profile = host.querySelector('[data-runner-profile]');
    if (profile) profile.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var daysChecked = Array.prototype.map.call(host.querySelectorAll('[data-runner-day]:checked'), function (el) { return el.value; });
      if (!daysChecked.length) { toast('Select at least one day you can work.', 'error'); return; }
      var btn = profile.querySelector('[type="submit"]');
      btn.disabled = true;
      api('freelancer.php', {
        method: 'POST',
        body: {
          action: 'save-profile',
          name: formValue(profile, 'name'),
          areas: formValue(profile, 'areas'),
          vehicle_type: formValue(profile, 'vehicle_type'),
          availability_window: formValue(profile, 'availability_window'),
          availability_days: daysChecked,
          payout_phone: formValue(profile, 'payout_phone'),
          id_number: formValue(profile, 'id_number'),
          terms_accepted: !!(profile.querySelector('[name="terms_accepted"]') || {}).checked,
          available_now: !!(host.querySelector('[data-runner-available]') || {}).checked,
          job_alerts: !!(profile.querySelector('[name="job_alerts"]') || {}).checked,
          max_distance_km: formValue(profile, 'max_distance_km'),
          preferred_sizes: formValue(profile, 'preferred_sizes')
        }
      }).then(function (fresh) {
        toast('Runner profile saved.');
        if (fresh.missing && !fresh.missing.length) {
          trackEvent('runner_onboarding_complete', { label: 'Runner onboarding complete' });
        }
        if (fresh.profile && fresh.profile.name) {
          APP.user.name = fresh.profile.name;
          initAuthUI();
        }
        renderFreelancer(host, fresh);
      }).catch(function (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      });
    });

    var availability = host.querySelector('[data-runner-available]');
    if (availability) availability.addEventListener('change', function () {
      availability.disabled = true;
      api('freelancer.php', { method: 'POST', body: { action: 'set-availability', available_now: availability.checked } })
        .then(function (fresh) {
          if (availability.checked) trackEvent('availability_on', { label: 'Availability on' });
          toast(availability.checked ? 'You are now available for jobs.' : 'You are now offline.', 'info');
          renderFreelancer(host, fresh);
        }).catch(function (err) {
          availability.checked = !availability.checked;
          availability.disabled = false;
          toast(err.message, 'error');
        });
    });

    initRunnerKyc(host);

    var payoutBtn = host.querySelector('[data-runner-payout]');
    if (payoutBtn) payoutBtn.addEventListener('click', function () {
      payoutBtn.disabled = true;
      api('freelancer.php', { method: 'POST', body: { action: 'request-payout' } })
        .then(function (fresh) {
          trackEvent('payout_requested', { label: 'Runner payout requested', meta: { pending: earn.pending_kes } });
          toast(payoutMode === 'demo'
            ? (payoutCfg.schedule === 'instant' ? 'Payout sent (demo M-Pesa).' : 'Payout scheduled.')
            : 'Payout submitted to M-Pesa.');
          renderFreelancer(host, fresh);
        }).catch(function (err) {
          toast(err.message, 'error');
          payoutBtn.disabled = false;
        });
    });

    var refresh = host.querySelector('[data-runner-refresh]');
    if (refresh) refresh.addEventListener('click', function () {
      refresh.disabled = true;
      refresh.textContent = 'Refreshing…';
      loadFreelancer(host);
    });

    function runnerJobAction(button, action, extra) {
      button.disabled = true;
      var body = { action: action };
      Object.keys(extra || {}).forEach(function (key) { body[key] = extra[key]; });
      api('freelancer.php', { method: 'POST', body: body })
        .then(function (fresh) {
          var tracked = { 'accept-job': 'job_accepted', 'decline-job': 'job_declined', 'pickup-job': 'job_view', 'deliver-job': 'job_completed' }[action];
          if (tracked) trackEvent(tracked, { label: action, meta: extra || {} });
          toast(action === 'accept-job' ? 'Job accepted — check your active jobs.'
            : action === 'decline-job' ? 'Job hidden from your list.'
            : action === 'pickup-job' ? 'Pickup confirmed.'
            : 'Job marked delivered.', 'info');
          renderFreelancer(host, fresh);
        }).catch(function (err) {
          toast(err.message, 'error');
          button.disabled = false;
        });
    }

    Array.prototype.forEach.call(host.querySelectorAll('[data-accept-job]'), function (btn) {
      btn.addEventListener('click', function () {
        runnerJobAction(btn, 'accept-job', { job_id: Number(btn.getAttribute('data-accept-job')) });
      });
    });
    Array.prototype.forEach.call(host.querySelectorAll('[data-decline-job]'), function (btn) {
      btn.addEventListener('click', function () {
        runnerJobAction(btn, 'decline-job', { job_id: Number(btn.getAttribute('data-decline-job')) });
      });
    });
    Array.prototype.forEach.call(host.querySelectorAll('[data-pickup-job]'), function (btn) {
      btn.addEventListener('click', function () {
        runnerJobAction(btn, 'pickup-job', { job_id: Number(btn.getAttribute('data-pickup-job')) });
      });
    });
    Array.prototype.forEach.call(host.querySelectorAll('[data-cancel-job]'), function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        api('freelancer.php', { method: 'POST', body: { action: 'cancel-job', job_id: Number(btn.getAttribute('data-cancel-job')) } })
          .then(function (fresh) { toast('Job released.'); trackEvent('job_cancelled', { label: 'Runner cancelled job' }); renderFreelancer(host, fresh); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });
    Array.prototype.forEach.call(host.querySelectorAll('[data-deliver-job]'), function (btn) {
      btn.addEventListener('click', function () {
        var jobId = btn.getAttribute('data-deliver-job');
        var input = host.querySelector('[data-proof-note="' + jobId + '"]');
        var proof = input ? input.value.trim() : '';
        if (proof.length < 3) { toast('Add a short delivery note (at least 3 characters).', 'error'); return; }
        var otpInput = host.querySelector('[data-delivery-otp="' + jobId + '"]');
        var otp = otpInput ? otpInput.value.replace(/\D/g, '') : '';
        if (otpInput && otp.length !== 6) { toast('Enter the 6-digit receiver code.', 'error'); return; }
        var photoInput = host.querySelector('[data-proof-photo="' + jobId + '"]');
        var finish = function () {
          runnerJobAction(btn, 'deliver-job', { job_id: Number(jobId), proof_note: proof, otp: otp });
        };
        if (photoInput && photoInput.files && photoInput.files[0]) {
          var fd = new FormData();
          fd.append('job_id', jobId);
          fd.append('file', photoInput.files[0]);
          btn.disabled = true;
          fetch('/api/runner-proof.php', {
            method: 'POST', credentials: 'same-origin',
            headers: APP.csrf ? { 'X-CSRF-Token': APP.csrf } : {}, body: fd
          }).then(function (res) { return res.json(); }).then(function (res) {
            if (res.ok === false) throw new Error((res.error && res.error.message) || 'Proof upload failed.');
            finish();
          }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        } else {
          finish();
        }
      });
    });
    Array.prototype.forEach.call(host.querySelectorAll('[data-resend-otp]'), function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        api('freelancer.php', { method: 'POST', body: { action: 'resend-delivery-otp', job_id: Number(btn.getAttribute('data-resend-otp')) } })
          .then(function (fresh) { toast('New receiver code sent.'); renderFreelancer(host, fresh); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });
    var markRead = host.querySelector('[data-notifications-read]');
    if (markRead) markRead.addEventListener('click', function () {
      markRead.disabled = true;
      api('freelancer.php', { method: 'POST', body: { action: 'mark-notifications-read' } })
        .then(function (fresh) { renderFreelancer(host, fresh); })
        .catch(function (err) { toast(err.message, 'error'); markRead.disabled = false; });
    });

    var reset = host.querySelector('[data-freelancer-reset]');
    if (reset) reset.addEventListener('click', function () {
      reset.disabled = true;
      api('auth.php', { method: 'POST', body: { action: 'freelancer-start', phone: u.phone } })
        .then(function (res) {
          toast(res.demo_pin ? 'New demo PIN: ' + res.demo_pin : 'A new PIN was sent to your phone.', 'info');
          reset.disabled = false;
        }).catch(function (err) { toast(err.message, 'error'); reset.disabled = false; });
    });
    var out = host.querySelector('[data-freelancer-signout]');
    if (out) out.addEventListener('click', function () {
      signOut().then(function () { window.location.href = '/login'; });
    });
  }

  
  function mountReferralCard(host, program) {
    if (!host) return;
    var old = host.querySelector('[data-referral-card]');
    if (old) old.remove();
    var card = document.createElement('div');
    card.className = 'mz-saas-card';
    card.setAttribute('data-referral-card', '1');
    card.style.marginTop = '16px';
    card.innerHTML = '<div class="mz-card__title">Earn with friends</div><p class="mz-hint">Invite a ' + (program === 'runner' ? 'runner' : program === 'business' ? 'business' : 'sender') + '. Rewards are credited when they activate.</p><div data-referral-card-body class="mz-hint">Loading…</div>';
    host.appendChild(card);
    api('growth.php').then(function (data) {
      var body = card.querySelector('[data-referral-card-body]');
      if (!body) return;
      if (!data.referral) { body.innerHTML = '<a class="mz-btn mz-btn--sm" href="/refer">Get your referral link</a>'; return; }
      var code = data.referral.code;
      var link = location.origin + '/refer?ref=' + encodeURIComponent(code);
      body.innerHTML = '<div class="mz-mono" style="font-size:18px;font-weight:800;">' + esc(code) + '</div>' +
        '<div class="mz-hint" style="margin:4px 0 8px;">' + data.referral.clicks + ' clicks · ' + data.referral.signups + ' sign-ups · ' + data.referral.activated + ' activated · KES ' + data.referral.earned_kes + ' earned</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;"><a class="mz-btn mz-btn--sm" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent('Join Mzigo.online with my link: ' + link) + '">Share on WhatsApp</a>' +
        '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-referral-copy>Copy link</button><a class="mz-tab" href="/refer">Referral page</a></div>';
      var copy = body.querySelector('[data-referral-copy]');
      if (copy) copy.addEventListener('click', function () { if (navigator.clipboard) navigator.clipboard.writeText(link).then(function(){toast('Referral link copied.');}); else toast('Long-press to copy the link.', 'info'); });
    }).catch(function () { card.remove(); });
  }

  function initRunnerKyc(host) {
    var mount = host.querySelector('[data-runner-kyc]');
    if (!mount) return;
    mount.innerHTML = '<div class="mz-saas-card"><div class="mz-hint">Loading verification status…</div></div>';
    api('kyc.php').then(function (data) {
      var status = (data.status || {}).status || 'not_submitted';
      var docs = data.documents || [];
      var badge = status === 'approved' ? '<span class="mz-badge mz-badge--ok">Verified</span>'
        : status === 'pending' ? '<span class="mz-badge mz-badge--warn">Under review</span>'
        : status === 'rejected' ? '<span class="mz-badge mz-badge--warn">Rejected</span>'
        : '<span class="mz-badge mz-badge--warn">Not verified</span>';
      var docOpts = Object.keys(data.doc_types || {}).map(function (key) {
        return '<option value="' + esc(key) + '">' + esc(data.doc_types[key]) + '</option>';
      }).join('');
      var form = (status === 'approved') ? '' :
        '<form data-kyc-form style="margin-top:10px;">' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Document type</span><select class="mz-input" name="doc_type">' + docOpts + '</select></label>' +
            '<label class="mz-field"><span class="mz-label">Document (JPG, PNG or PDF, max 5MB)</span><input class="mz-input" type="file" name="file" accept="image/jpeg,image/png,application/pdf" required></label>' +
          '</div>' +
          '<button class="mz-btn" type="submit" style="margin-top:10px;">Upload for verification</button>' +
        '</form>';
      var docsBody = docs.length ? docs.map(function (d) {
        return '<div class="mz-hint">' + esc(d.doc_type) + ' · ' + esc(d.status) + ' · ' + esc(fmtDate(d.created_at)) +
          ' · <a class="mz-link" href="/api/kyc-document.php?id=' + d.id + '" target="_blank" rel="noopener">view</a></div>';
      }).join('') : '<div class="mz-hint">No documents uploaded yet.</div>';
      mount.innerHTML =
        '<div class="mz-saas-card">' +
          '<div class="mz-row"><div class="mz-card__title" style="margin:0;">ID verification</div>' + badge + '</div>' +
          '<p class="mz-hint">' + (status === 'approved'
            ? 'Your ID is verified. You can accept jobs when your profile and availability are set.'
            : 'Upload a national ID, passport or driver licence so an admin can verify you before you accept jobs.') + '</p>' +
          (status === 'rejected' && (data.status || {}).note ? '<div class="mz-alert">Reviewer note: ' + esc(data.status.note) + '</div>' : '') +
          form +
          '<div style="margin-top:12px;">' + docsBody + '</div>' +
        '</div>';
      var formEl = mount.querySelector('[data-kyc-form]');
      if (formEl) formEl.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = formEl.querySelector('[type="submit"]');
        var file = formEl.querySelector('[name="file"]').files[0];
        if (!file) { toast('Choose a document first.', 'error'); return; }
        var fd = new FormData();
        fd.append('doc_type', formValue(formEl, 'doc_type'));
        fd.append('file', file);
        btn.disabled = true;
        fetch('/api/kyc.php', {
          method: 'POST', credentials: 'same-origin',
          headers: APP.csrf ? { 'X-CSRF-Token': APP.csrf } : {}, body: fd
        }).then(function (res) { return res.json(); }).then(function (res) {
          if (res.ok === false) throw new Error((res.error && res.error.message) || 'Upload failed.');
          toast('KYC document uploaded — under review.');
          loadFreelancer(host);
        }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    }).catch(function (err) {
      mount.innerHTML = '<div class="mz-saas-card"><div class="mz-hint">Could not load verification status: ' + esc(err.message) + '</div></div>';
    });
  }

  function initMerchantKyc(container) {
    if (!container) return;
    container.innerHTML = '<div class="mz-hint">Loading business verification…</div>';
    api('kyc.php').then(function (data) {
      var status = (data.status || {}).status || 'not_submitted';
      var badge = status === 'approved' ? '<span class="mz-badge mz-badge--ok">Verified</span>'
        : status === 'pending' ? '<span class="mz-badge mz-badge--warn">Under review</span>'
        : status === 'rejected' ? '<span class="mz-badge mz-badge--warn">Rejected</span>'
        : '<span class="mz-badge mz-badge--warn">Not verified</span>';
      var docOpts = Object.keys(data.doc_types || {}).map(function (key) {
        return '<option value="' + esc(key) + '">' + esc(data.doc_types[key]) + '</option>';
      }).join('');
      var form = status === 'approved' ? '' :
        '<form data-merchant-kyc-form style="margin-top:10px;">' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Document type</span><select class="mz-input" name="doc_type">' + docOpts + '</select></label>' +
            '<label class="mz-field"><span class="mz-label">Document (JPG, PNG or PDF, max 5MB)</span><input class="mz-input" type="file" name="file" accept="image/jpeg,image/png,application/pdf" required></label>' +
          '</div>' +
          '<button class="mz-btn" type="submit" style="margin-top:10px;">Upload business document</button>' +
        '</form>';
      var docsBody = (data.documents || []).length ? data.documents.map(function (d) {
        return '<div class="mz-hint">' + esc(d.doc_type) + ' · ' + esc(d.status) + ' · ' + esc(fmtDate(d.created_at)) + '</div>';
      }).join('') : '<div class="mz-hint">No business documents uploaded yet.</div>';
      container.innerHTML =
        '<div class="mz-card__title">Business verification (KYC)</div>' +
        '<div class="mz-row" style="justify-content:flex-start;gap:8px;">' + badge + '</div>' +
        '<p class="mz-hint">Upload your business permit, KRA PIN or registration certificate for verification. Admin reviews the document.</p>' +
        form + '<div style="margin-top:12px;">' + docsBody + '</div>';
      var formEl = container.querySelector('[data-merchant-kyc-form]');
      if (formEl) formEl.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = formEl.querySelector('[type="submit"]');
        var file = formEl.querySelector('[name="file"]').files[0];
        if (!file) { toast('Choose a document first.', 'error'); return; }
        var fd = new FormData();
        fd.append('doc_type', formValue(formEl, 'doc_type'));
        fd.append('file', file);
        btn.disabled = true;
        fetch('/api/kyc.php', {
          method: 'POST', credentials: 'same-origin',
          headers: APP.csrf ? { 'X-CSRF-Token': APP.csrf } : {}, body: fd
        }).then(function (res) { return res.json(); }).then(function (res) {
          if (res.ok === false) throw new Error((res.error && res.error.message) || 'Upload failed.');
          toast('Business document uploaded — under review.');
          initMerchantKyc(container);
        }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    }).catch(function (err) {
      container.innerHTML = '<div class="mz-hint">Could not load business verification: ' + esc(err.message) + '</div>';
    });
  }

  function openCustomerCheckout(host, tracking, fee) {
    var couponCode = '';
    var dialog = openDialog({
      title: 'Pay ' + money(fee) + ' at collection',
      sub: 'Delivery fee for ' + tracking + ' · M-Pesa demo checkout',
      submitLabel: 'Pay ' + money(fee),
      body:
        '<div class="mz-alert" style="margin-bottom:12px;">Pay the delivery fee to receive your collection code. In demo mode no real money moves.</div>' +
        '<div class="mz-form-grid"><label class="mz-field"><span class="mz-label">Coupon code (optional)</span>' +
          '<input class="mz-input" name="coupon_code" placeholder="e.g. MZIGO100"></label>' +
          '<div style="display:flex;align-items:flex-end;"><button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-coupon-apply>Apply coupon</button></div></div>' +
        '<div class="mz-kv"><dt>Delivery fee</dt><dd>' + money(fee) + '</dd></div>' +
        '<div class="mz-kv"><dt>Coupon discount</dt><dd data-coupon-discount>' + money(0) + '</dd></div>' +
        '<div class="mz-kv"><dt>Referral credit</dt><dd data-credit-discount>' + money(0) + '</dd></div>' +
        '<div class="mz-kv"><dt>Total to pay</dt><dd data-coupon-total><strong>' + money(fee) + '</strong></dd></div>' +
        '<div class="mz-hint" data-coupon-message></div>',
      onSubmit: function (form) {
        return api('customer.php', { method: 'POST', body: { action: 'pay', tracking: tracking, coupon_code: couponCode } }).then(function (res) {
          toast('Payment confirmed · ' + res.reference);
          trackEvent('customer_pay', { label: 'Pay with M-Pesa', meta: { reference: res.reference, coupon: couponCode } });
          dialog.close();
          setTimeout(function () { loadCustomer(host); }, 50);
        });
      }
    });
    var creditAvailable = 0;
    api('growth.php').then(function (data) {
      creditAvailable = Math.min(fee, Number((data.credits && data.credits.parcel) || 0));
      if (creditAvailable <= 0) return;
      dialog.overlay.querySelector('[data-credit-discount]').textContent = money(creditAvailable);
      dialog.overlay.querySelector('[data-coupon-total]').innerHTML = '<strong>' + money(Math.max(0, fee - creditAvailable)) + '</strong>';
      var payBtn = dialog.overlay.querySelector('form [type="submit"]') || dialog.overlay.querySelector('[type="submit"]');
      if (payBtn) payBtn.textContent = 'Pay ' + money(Math.max(0, fee - creditAvailable));
      var note = dialog.overlay.querySelector('[data-coupon-message]');
      if (note) note.textContent = 'Referral credit applied automatically.';
    }).catch(function () {});
    var apply = dialog.overlay.querySelector('[data-coupon-apply]');
    if (apply) apply.addEventListener('click', function () {
      var code = (dialog.overlay.querySelector('[name="coupon_code"]').value || '').trim();
      if (!code) { toast('Enter a coupon code.', 'error'); return; }
      apply.disabled = true;
      api('growth.php', { method: 'POST', body: { action: 'validate_coupon', code: code, amount_kes: fee, context: 'delivery' } })
        .then(function (res) {
          couponCode = code;
          dialog.overlay.querySelector('[data-coupon-discount]').textContent = money(res.discount_kes);
          dialog.overlay.querySelector('[data-coupon-total]').innerHTML = '<strong>' + money(Math.max(0, res.final_kes - creditAvailable)) + '</strong>';
          dialog.overlay.querySelector('[data-coupon-message]').textContent = res.coupon.name + ' applied.';
          var submitBtn = dialog.overlay.querySelector('form [type="submit"]') || dialog.overlay.querySelector('[type="submit"]');
          if (submitBtn) submitBtn.textContent = 'Pay ' + money(Math.max(0, res.final_kes - creditAvailable));
          trackEvent('coupon_applied', { label: res.coupon.code });
          apply.disabled = false;
        }).catch(function (err) {
          couponCode = '';
          dialog.overlay.querySelector('[data-coupon-discount]').textContent = money(0);
          dialog.overlay.querySelector('[data-coupon-total]').innerHTML = '<strong>' + money(Math.max(0, fee - creditAvailable)) + '</strong>';
          dialog.overlay.querySelector('[data-coupon-message]').textContent = err.message;
          var resetBtn = dialog.overlay.querySelector('form [type="submit"]') || dialog.overlay.querySelector('[type="submit"]');
          if (resetBtn) resetBtn.textContent = 'Pay ' + money(fee);
          apply.disabled = false;
        });
    });
  }

  function initCustomerPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Fcustomer'; return; }
    if (APP.user.role !== 'customer') {
      window.location.href = APP.user.role === 'platform_admin' ? '/admin'
        : APP.user.role === 'freelancer' ? '/freelancer' : '/profile';
      return;
    }
    initAppNav();
    var host = $('[data-app="customer"]');
    if (!host) return;
    loadCustomer(host);
  }

  function loadCustomer(host) {
    host.innerHTML = '<div class="mz-saas-card">Loading your incoming parcels…</div>';
    api('customer.php').then(function (data) {
      renderCustomer(host, data);
    }).catch(function (err) {
      host.innerHTML = '<div class="mz-saas-card"><div class="mz-card__title">Could not load your parcels</div>' +
        '<p class="mz-hint">' + esc(err.message) + '</p>' +
        '<button type="button" class="mz-btn" data-customer-retry>Retry</button></div>';
      var retry = host.querySelector('[data-customer-retry]');
      if (retry) retry.addEventListener('click', function () { loadCustomer(host); });
    });
  }

  function customerParcelCard(p) {
    var meta = STATUS_META[p.status] || { label: p.status, pill: '' };
    var paid = p.payment_status === 'paid';
    var fee = p.amount_due || (p.fee || {}).total || 0;
    var action = '';
    if (paid) {
      action = '<div class="mz-row" style="margin-top:12px;align-items:flex-end;">' +
          '<div class="mz-kv" style="margin:0;"><dt>Collection code</dt><dd class="mz-mono">' + esc(p.collect_code || '—') +
            (p.collect_code ? ' <button type="button" class="mz-tab" data-copy-code="' + esc(p.collect_code) + '">Copy</button>' : '') +
          '</dd></div>' +
        '</div>' +
        '<p style="font-size:12px;color:var(--mz-muted-fg);margin:6px 0 0;">Keep this code — it stays in My parcels until collection. ' +
          '<button type="button" class="mz-link" data-customer-receipt="' + esc(p.tracking) + '">Email receipt</button></p>' +
        '<div class="mz-row" style="gap:12px;margin-top:8px;justify-content:flex-start;flex-wrap:wrap;">' +
          '<a class="mz-link" href="/contact?topic=payment">Contact support</a>' +
          '<a class="mz-link" href="/claims">Open a claim</a>' +
        '</div>' +
        (p.payment_ref ? '<div style="font-size:11px;color:var(--mz-muted-fg);margin-top:6px;">Receipt ' + esc(p.payment_ref) + (p.paid_at ? ' · ' + esc(fmtDate(p.paid_at)) : '') + '</div>' : '');
    } else if (p.can_pay) {
      action = '<button type="button" class="mz-btn" data-customer-pay="' + esc(p.tracking) + '" data-customer-fee="' + fee + '" style="margin-top:12px;">Pay ' + money(fee) + ' with M-Pesa (demo)</button>' +
        '<div style="font-size:11px;color:var(--mz-muted-fg);margin-top:6px;">Your collection code appears after payment.</div>' +
        '<div class="mz-row" style="gap:12px;margin-top:8px;justify-content:flex-start;flex-wrap:wrap;">' +
          '<span class="mz-hint" style="margin:0;">Parcel not as expected?</span>' +
          '<a class="mz-link" href="/contact?ref=' + encodeURIComponent(p.tracking) + '&topic=payment">Contact support</a>' +
          '<a class="mz-link" href="/claims">Open a claim</a>' +
        '</div>';
    } else {
      action = '<div class="mz-hint" style="margin-top:12px;">Payment opens when the parcel is ready for collection.</div>';
    }
    return '<div class="mz-list-card" style="margin-bottom:12px;">' +
      '<div class="mz-row">' +
        '<div style="min-width:0;"><div class="mz-mono" style="font-weight:700;">' + esc(p.tracking) + '</div>' +
        '<div style="font-size:12px;color:var(--mz-muted-fg);">' + esc(p.carrier) + ' · ' + esc(p.from) + ' → ' + esc(p.to) + '</div></div>' +
        '<span class="mz-pill mz-pill--' + esc(meta.pill) + '">' + esc(meta.label) + '</span>' +
      '</div>' +
      '<div class="mz-row" style="margin-top:10px;align-items:flex-end;">' +
        '<div><div class="mz-cap">Delivery fee</div><div style="font-size:18px;font-weight:800;">' + money(fee) + '</div></div>' +
        '<span class="mz-badge ' + (paid ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (paid ? 'Paid' : 'Payment due') + '</span>' +
      '</div>' +
      action +
    '</div>';
  }

  function customerNotificationsCard(notes) {
    notes = notes || [];
    var unread = notes.filter(function (n) { return !n.read_at; }).length;
    var rows = notes.map(function (n) {
      return '<div style="border-bottom:1px solid rgba(60,82,35,.10);padding:8px 0;' + (n.read_at ? '' : 'background:#f7fbf0;border-radius:6px;padding-left:6px;') + '">' +
        '<div style="font-size:13px;font-weight:' + (n.read_at ? '500' : '700') + ';">' + esc(n.subject || n.template || 'Notification') + '</div>' +
        '<div class="mz-hint" style="white-space:pre-line;">' + esc(n.body || '') + '</div>' +
        '<div class="mz-hint">' + esc(fmtDate(n.created_at)) + ' · ' + esc(n.channel || '') + ' · ' + esc(n.status || '') + (n.read_at ? ' · read' : ' · unread') + '</div></div>';
    }).join('');
    return '<div class="mz-saas-card"><div class="mz-row"><div class="mz-card__title" style="margin:0;">Notifications' +
      (unread ? ' <span class="mz-badge mz-badge--warn">' + unread + ' unread</span>' : '') + '</div>' +
      (unread ? '<button type="button" class="mz-tab" data-customer-notifications-read>Mark all read</button>' : '') + '</div>' +
      (rows || '<div class="mz-hint">No notifications yet.</div>') + '</div>';
  }

  function renderCustomer(host, data) {
    setTimeout(function () { mountReferralCard(host, 'customer'); }, 0);
    var c = data.customer;
    var sum = data.summary;
    var parcels = data.parcels || [];
    var channelOpts = [{ code: 'all', label: 'All available channels' }].concat(data.channels || []);
    var preference = data.preference || 'all';
    var prefs = data.email_preferences || {};
    var prefCard = '<div class="mz-saas-card"><div class="mz-card__title">Email &amp; notification preferences</div>' +
      '<p class="mz-hint">Add an email for receipts and parcel updates, then choose what Mzigo.online may email you about.</p>' +
      '<label class="mz-field"><span class="mz-label">Email address</span>' +
        '<input class="mz-input" type="email" data-customer-email value="' + esc(c.email || '') + '" placeholder="you@example.com"></label>' +
      '<div class="mz-hint" style="margin:-2px 0 10px;">' + (c.email ? (c.email_verified ? '<span class="mz-badge mz-badge--ok">Verified</span>' : '<span class="mz-badge mz-badge--warn">Not verified</span>') : 'No email on file yet.') + '</div>' +
      '<button type="button" class="mz-btn mz-btn--outline" data-save-customer-email>Save email</button>' +
      '<div style="border-top:1px solid rgba(60,82,35,.12);margin:16px 0 12px;"></div>' +
      '<div class="mz-label">Email me about</div>' +
      [['email_enabled','All email notifications'],['parcel_updates','Parcel status and collection updates'],['payment_receipts','Payment receipts and collection codes'],['claim_updates','Claim updates and messages'],['marketing','Offers and platform news']].map(function (row) {
        return '<label style="display:flex;align-items:center;gap:8px;margin:7px 0;font-size:13px;">' +
          '<input type="checkbox" data-customer-pref="' + row[0] + '"' + (prefs[row[0]] ? ' checked' : '') + '> ' + esc(row[1]) + '</label>';
      }).join('') +
      '<div style="border-top:1px solid rgba(60,82,35,.12);margin:14px 0 10px;"></div>' +
      '<div class="mz-label">WhatsApp parcel updates</div>' +
      '<p class="mz-hint">Opt in to receive parcel registered, ready, payment and collection updates on WhatsApp. Reply STOP on WhatsApp to opt out.</p>' +
      '<label style="display:flex;align-items:center;gap:8px;margin:7px 0;font-size:13px;"><input type="checkbox" data-customer-wa-optin' + (data.whatsapp_optin ? ' checked' : '') + '> Receive parcel updates on WhatsApp</label>' +
      '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-save-wa-optin>Save WhatsApp preference</button>' +
      '<div class="mz-label" style="margin-top:14px;">Preferred delivery channel</div>' +
      channelOpts.map(function (ch) {
        return '<label style="display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px;">' +
          '<input type="radio" name="comm-channel" value="' + esc(ch.code) + '"' + (preference === ch.code ? ' checked' : '') + '> ' + esc(ch.label) + '</label>';
      }).join('') +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">' +
        '<button type="button" class="mz-btn" data-save-notification-prefs>Save preferences</button>' +
        '<button type="button" class="mz-btn mz-btn--outline" data-save-channel>Save channel</button>' +
      '</div></div>';
    var cards = parcels.length
      ? parcels.map(customerParcelCard).join('')
      : '<div class="mz-saas-card"><div class="mz-card__title">No incoming parcels yet</div>' +
        '<p class="mz-hint">When a seller registers a parcel with your phone number as the receiver, it appears here for tracking and payment.</p>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">' +
          '<a class="mz-btn mz-btn--outline" href="/track-mzigo">Track a parcel</a>' +
          '<a class="mz-tab" href="/help">Help centre</a>' +
        '</div></div>';
    var rateable = parcels.filter(function (p) { return p.payment_status === 'paid' && !p.csat_rating; });
    var csatCard = rateable.length
      ? '<div class="mz-saas-card" style="margin-top:16px;" data-customer-csat><div class="mz-card__title">Rate your delivery</div>' +
        '<p class="mz-hint">Your feedback helps us improve the collection experience.</p>' +
        '<label class="mz-field"><span class="mz-label">Parcel</span><select class="mz-input" data-csat-tracking>' +
          rateable.map(function (p) { return '<option value="' + esc(p.tracking) + '">' + esc(p.tracking) + ' · ' + esc(p.carrier || '') + '</option>'; }).join('') +
        '</select></label>' +
        '<div class="mz-row" style="gap:6px;margin:8px 0;" data-csat-stars>' +
          [1,2,3,4,5].map(function (n) { return '<button type="button" class="mz-tab" data-csat-star="' + n + '">' + n + ' ★</button>'; }).join('') +
        '</div>' +
        '<label class="mz-field"><span class="mz-label">Comment (optional)</span><textarea class="mz-input" data-csat-comment rows="2" maxlength="500" placeholder="How was the delivery or collection?"></textarea></label>' +
        '<button type="button" class="mz-btn mz-btn--sm" data-csat-submit>Submit rating</button></div>'
      : '';
    var payable = parcels.filter(function (p) { return p.can_pay && p.payment_status !== 'paid'; });
    var paybar = '';
    if (payable.length) {
      var first = payable[0];
      var firstFee = first.amount_due || (first.fee || {}).total || 0;
      paybar = '<div class="mz-customer-paybar" data-customer-paybar>' +
        '<div style="min-width:0;"><div class="mz-cap">Payment due</div>' +
          '<div class="mz-mono" style="font-weight:700;font-size:13px;">' + esc(first.tracking) + '</div></div>' +
        '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">' +
          '<a class="mz-link" href="/contact?topic=payment">Contact support</a>' +
          '<button type="button" class="mz-btn" data-customer-pay="' + esc(first.tracking) + '" data-customer-fee="' + firstFee + '">Pay ' + money(firstFee) + '</button>' +
        '</div></div>';
    }
    host.innerHTML =
      '<div class="mz-saas-grid mz-saas-grid--2" style="margin-bottom:16px;">' +
        '<div class="mz-saas-card">' +
          '<div class="mz-row">' +
            '<div style="display:flex;align-items:center;gap:12px;min-width:0;">' + avatar(c.name, 'lg') +
              '<div style="min-width:0;"><div class="mz-cap">Customer account</div>' +
              '<div style="font-size:18px;font-weight:700;">' + esc(c.name) + '</div>' +
              '<div style="font-size:13px;color:var(--mz-muted-fg);">' + esc(c.phone) + '</div></div>' +
            '</div>' +
            '<span class="mz-badge">Receiver</span>' +
          '</div>' +
        '</div>' +
        '<div class="mz-saas-card">' +
          '<div class="mz-card__title">How pay-at-collection works</div>' +
          '<ul class="mz-check-list">' +
            '<li>Track every parcel addressed to your phone.</li>' +
            '<li>Pay the delivery fee by M-Pesa when it is ready.</li>' +
            '<li>Show your collection code at the office to collect.</li>' +
          '</ul>' +
        '</div>' +
      '</div>' +
      paybar +
      '<div class="mz-stats" style="margin-bottom:16px;">' +
        '<div class="mz-stat"><div class="mz-cap">Incoming parcels</div><div class="mz-stat__value">' + sum.parcels + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Payment due</div><div class="mz-stat__value">' + sum.unpaid + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Paid</div><div class="mz-stat__value">' + sum.paid + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Paid total</div><div class="mz-stat__value">' + money(sum.paid_total_kes) + '</div></div>' +
      '</div>' +
      '<div style="display:flex;justify-content:flex-end;gap:14px;margin:2px 0 10px;flex-wrap:wrap;">' +
        '<a class="mz-link" href="/contact?topic=payment">Contact support</a>' +
        '<a class="mz-tab" href="/track-mzigo">Track another parcel</a>' +
      '</div>' +
      '<div id="pay">' + cards + '</div>' +
      csatCard +
      customerClaimsSection(data) +
      '<div style="margin-top:16px;">' + customerNotificationsCard(data.notifications) + '</div>' +
      '<div style="margin-top:16px;">' + prefCard + '</div>' +
      '<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">' +
        '<button type="button" class="mz-tab mz-signout-inline" data-customer-signout>Sign out</button>' +
      '</div>';
    document.body.classList.toggle('mz-has-paybar', !!payable.length);

    bindCopyButtons(host);

    host.querySelectorAll('[data-customer-receipt]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        api('customer.php', { method: 'POST', body: { action: 'resend_receipt', tracking: btn.getAttribute('data-customer-receipt') } })
          .then(function (res) { toast((res && res.message) || 'Receipt sent.'); btn.disabled = false; })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });

    var csatRating = 0;
    host.querySelectorAll('[data-csat-star]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        csatRating = Number(btn.getAttribute('data-csat-star'));
        host.querySelectorAll('[data-csat-star]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      });
    });
    var csatSubmit = host.querySelector('[data-csat-submit]');
    if (csatSubmit) csatSubmit.addEventListener('click', function () {
      var tracking = (host.querySelector('[data-csat-tracking]') || {}).value || '';
      var comment = (host.querySelector('[data-csat-comment]') || {}).value || '';
      if (!csatRating) { toast('Choose 1–5 stars first.', 'error'); return; }
      csatSubmit.disabled = true;
      api('customer.php', { method: 'POST', body: { action: 'rate_parcel', tracking: tracking, rating: csatRating, comment: comment } })
        .then(function (res) { toast(res.message || 'Rating saved.'); loadCustomer(host); })
        .catch(function (err) { toast(err.message, 'error'); csatSubmit.disabled = false; });
    });
    host.querySelectorAll('[data-customer-pay]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openCustomerCheckout(host, btn.getAttribute('data-customer-pay'), Number(btn.getAttribute('data-customer-fee') || 0));
      });
    });
    host.querySelectorAll('[data-customer-pay-old]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tracking = btn.getAttribute('data-customer-pay-old');
        btn.disabled = true;
        api('customer.php', { method: 'POST', body: { action: 'pay', tracking: tracking } }).then(function (res) {
          trackEvent('customer_pay', { label: 'Pay with M-Pesa', meta: { reference: res.reference } });
          toast('Payment confirmed · ' + res.reference);
          loadCustomer(host);
        }).catch(function (err) {
          toast(err.message, 'error');
          btn.disabled = false;
        });
      });
    });
    var saveChannel = host.querySelector('[data-save-channel]');
    if (saveChannel) saveChannel.addEventListener('click', function () {
      var checked = host.querySelector('[name="comm-channel"]:checked');
      if (!checked) return;
      saveChannel.disabled = true;
      api('customer.php', { method: 'POST', body: { action: 'update_preferences', channel: checked.value } })
        .then(function (res) { toast('Communication preference saved: ' + res.preference); saveChannel.disabled = false; })
        .catch(function (err) { toast(err.message, 'error'); saveChannel.disabled = false; });
    });
    var saveWa = host.querySelector('[data-save-wa-optin]');
    if (saveWa) saveWa.addEventListener('click', function () {
      var box = host.querySelector('[data-customer-wa-optin]');
      saveWa.disabled = true;
      api('customer.php', { method: 'POST', body: { action: 'update_whatsapp_optin', opt_in: !!(box && box.checked) } })
        .then(function (res) { toast(res.whatsapp_optin ? 'WhatsApp updates opted in.' : 'WhatsApp updates opted out.'); saveWa.disabled = false; })
        .catch(function (err) { toast(err.message, 'error'); saveWa.disabled = false; });
    });
    var saveEmail = host.querySelector('[data-save-customer-email]');
    if (saveEmail) saveEmail.addEventListener('click', function () {
      var input = host.querySelector('[data-customer-email]');
      saveEmail.disabled = true;
      api('customer.php', { method: 'POST', body: { action: 'update_email', email: input ? input.value.trim() : '' } })
        .then(function (res) { toast(res.message || 'Email saved.'); loadCustomer(host); })
        .catch(function (err) { toast(err.message, 'error'); saveEmail.disabled = false; });
    });
    var savePrefs = host.querySelector('[data-save-notification-prefs]');
    if (savePrefs) savePrefs.addEventListener('click', function () {
      var body = { action: 'update_notification_preferences' };
      host.querySelectorAll('[data-customer-pref]').forEach(function (box) {
        body[box.getAttribute('data-customer-pref')] = !!box.checked;
      });
      savePrefs.disabled = true;
      api('customer.php', { method: 'POST', body: body })
        .then(function (res) { toast(res.message || 'Preferences saved.'); loadCustomer(host); })
        .catch(function (err) { toast(err.message, 'error'); savePrefs.disabled = false; });
    });
    bindCustomerClaims(host, data);
    var markRead = host.querySelector('[data-customer-notifications-read]');
    if (markRead) markRead.addEventListener('click', function () {
      markRead.disabled = true;
      api('notifications.php', { method: 'POST', body: { action: 'mark_all_read' } })
        .then(function () { loadCustomer(host); })
        .catch(function (err) { toast(err.message, 'error'); markRead.disabled = false; });
    });
    var out = host.querySelector('[data-customer-signout]');
    if (out) out.addEventListener('click', function () {
      signOut().then(function () { window.location.href = '/login'; });
    });
  }

  function customerClaimsSection(data) {
    var claims = data.claims || [];
    var parcels = data.parcels || [];
    var statusBadge = { open: 'warn', under_review: 'warn', approved: 'ok', rejected: '', paid: 'ok', withdrawn: '', reversed: 'warn' };
    var claimCards = claims.length ? claims.map(function (c) {
      return '<div class="mz-mobile-card" style="margin-bottom:10px;">' +
        '<div class="mz-mobile-card__top"><span class="mz-mono">' + esc(c.ref) + '</span>' +
          '<span class="mz-badge ' + (statusBadge[c.status] ? 'mz-badge--' + statusBadge[c.status] : '') + '">' + esc(c.status.replace('_', ' ')) + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(c.tracking || 'Parcel') + ' · ' + esc(c.type) + '</div>' +
        '<div class="mz-mobile-card__meta">' + esc(c.description) + '</div>' +
        (c.resolution_note ? '<div class="mz-mobile-card__meta">Seller note: ' + esc(c.resolution_note) + '</div>' : '') +
        '<div class="mz-mobile-card__bottom"><span class="mz-hint">' + esc(fmtDate(c.created_at)) +
          (c.estimated_value_kes ? ' · claimed ' + money(c.estimated_value_kes) : '') +
          (c.status === 'approved' && c.payout_hold_until ? ' · payout hold until ' + esc(fmtDate(c.payout_hold_until)) : '') + '</span>' +
          '<button type="button" class="mz-link" data-customer-claim-detail="' + c.id + '">Details</button></div></div>';
    }).join('') : '<div class="mz-hint">You have not reported any parcel issues yet.</div>';
    var parcelOptions = parcels.map(function (p) {
      return '<option value="' + esc(p.tracking) + '">' + esc(p.tracking + ' · ' + p.status) + '</option>';
    }).join('');
    return '<div class="mz-saas-grid mz-saas-grid--2" style="margin-top:16px;">' +
      '<div class="mz-saas-card"><div class="mz-card__title">Report a parcel issue</div>' +
        '<p class="mz-hint">Verify your phone to report a lost, damaged or delayed parcel. The seller reviews and handles the claim.</p>' +
        '<form class="mz-stack" data-customer-claim-form>' +
          '<label class="mz-field"><span class="mz-label">Parcel</span><select class="mz-input" name="tracking" required>' + parcelOptions + '</select></label>' +
          '<label class="mz-field"><span class="mz-label">Issue</span><select class="mz-input" name="type"><option value="damaged">Damaged</option><option value="lost">Lost</option><option value="delayed">Delayed</option></select></label>' +
          '<label class="mz-field"><span class="mz-label">What happened?</span><textarea class="mz-input" name="description" rows="3" placeholder="Describe the issue, dates and evidence references" required></textarea></label>' +
          '<label class="mz-field"><span class="mz-label">Evidence references (required for lost/damaged)</span><input class="mz-input" name="evidence" placeholder="Photo/document links, receipt numbers…"></label>' +
          '<label class="mz-field"><span class="mz-label">Estimated value KES (optional, capped by coverage)</span><input class="mz-input" name="estimated_value_kes" type="number" min="0"></label>' +
          '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><button type="button" class="mz-tab" data-customer-claim-send>Send verification code</button>' +
          '<span class="mz-hint" data-customer-claim-otp-hint>Code expires in 10 minutes.</span></div>' +
          '<label class="mz-field"><span class="mz-label">Verification code</span><input class="mz-input" name="otp" inputmode="numeric" placeholder="6-digit code" required></label>' +
          '<button type="submit" class="mz-btn">Submit verified claim</button>' +
        '</form></div>' +
      '<div class="mz-saas-card"><div class="mz-row" style="margin-bottom:8px;"><div class="mz-card__title" style="margin:0;">My claims</div><span class="mz-hint">' + claims.length + ' report(s)</span></div>' +
        claimCards + '</div></div>';
  }

  function bindCustomerClaims(host, data) {
    var form = host.querySelector('[data-customer-claim-form]');
    if (!form) return;
    var send = form.querySelector('[data-customer-claim-send]');
    var hint = form.querySelector('[data-customer-claim-otp-hint]');
    if (send) send.addEventListener('click', function () {
      send.disabled = true;
      api('customer.php', { method: 'POST', body: {
        action: 'request_claim_otp', tracking: formValue(form, 'tracking'), type: formValue(form, 'type')
      }}).then(function (res) {
        if (hint) hint.textContent = res.demo_otp ? 'Demo verification code: ' + res.demo_otp + ' (valid 10 minutes)' : 'Verification code sent to your phone (valid 10 minutes).';
        toast(res.message || 'Verification code sent.');
        send.disabled = false;
      }).catch(function (err) { toast(err.message, 'error'); send.disabled = false; });
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('customer.php', { method: 'POST', body: {
        action: 'file_claim', tracking: formValue(form, 'tracking'), type: formValue(form, 'type'),
        description: formValue(form, 'description'), evidence: formValue(form, 'evidence'),
        estimated_value_kes: formValue(form, 'estimated_value_kes'), otp: formValue(form, 'otp')
      }}).then(function (res) { toast(res.message || 'Claim submitted.'); loadCustomer(host); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    host.querySelectorAll('[data-customer-claim-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () { openClaimDetail(Number(btn.getAttribute('data-customer-claim-detail'))); });
    });
  }

  function renderFreePricing(grid) {
    var head = document.querySelector('.mz-saas-head');
    if (head) {
      var cap = head.querySelector('.mz-cap');
      var h1 = head.querySelector('h1');
      var lead = head.querySelector('p');
      if (cap) cap.textContent = 'Free to use';
      if (h1) h1.textContent = 'Mzigo.online is free to use right now';
      if (lead) lead.textContent = 'No subscription is charged while we onboard courier partners. Delivery fees are quoted per parcel and paid at collection.';
    }
    grid.innerHTML =
      '<div class="mz-saas-card" style="grid-column:1/-1;">' +
        '<div class="mz-card__title">No subscription is charged right now</div>' +
        '<p class="mz-hint">Mzigo.online is free to use while we onboard courier partners. Send, track and manage parcels without a monthly plan. Delivery fees are quoted per parcel and paid at collection.</p>' +
        '<div class="mz-row" style="gap:10px;margin-top:12px;">' +
          '<a class="mz-btn" href="/send-mzigo">Send a parcel</a>' +
          '<a class="mz-btn mz-btn--outline" href="/signup">Create a workspace</a>' +
        '</div>' +
      '</div>';
  }

  function initPricingPage() {
    var grid = $('[data-pricing-grid]');
    if (!grid) return;
    // Plans and prices are only shown when the platform is in paid mode.
    if (APP.pricingMode === 'free') { renderFreePricing(grid); return; }
    api('plans.php').then(function (res) {
      if (res.pricing_mode === 'free') { renderFreePricing(grid); return; }
      grid.innerHTML = res.plans.map(function (plan, index) {
        var featured = plan.code === 'growth';
        return '<div class="mz-plan-card' + (featured ? ' mz-plan-card--featured' : '') + '">' +
          (featured ? '<span class="mz-badge mz-badge--ok">Most popular</span>' : '') +
          '<div class="mz-plan-name">' + esc(plan.name) + '</div>' +
          '<div class="mz-plan-price">' + money(plan.monthly_price_kes) + '<span>/month</span></div>' +
          '<div class="mz-plan-meta">' + plan.parcel_limit + ' parcels / month · ' + plan.staff_limit + ' team members</div>' +
          '<ul class="mz-check-list mz-check-list--sm">' + (plan.features || []).map(function (f) { return '<li>' + esc(f) + '</li>'; }).join('') + '</ul>' +
          '<a class="mz-btn' + (index === 0 ? ' mz-btn--outline' : '') + '" href="/signup">Start free trial</a>' +
        '</div>';
      }).join('');
    }).catch(function (err) {
      fillNote(grid, 'Could not load plans: ' + esc(err.message), 'error');
    });
  }

  /* ------------------------------------------------------------------ billing */
  function openInvoiceCheckout(host, invoiceId, amount, billCredit) {
    var couponCode = '';
    var credit = Math.min(amount, billCredit || 0);
    var dialog = openDialog({
      title: 'Pay invoice ' + money(amount),
      sub: 'Subscription payment · demo M-Pesa checkout',
      submitLabel: 'Pay ' + money(Math.max(0, amount - credit)),
      body:
        '<div class="mz-alert" style="margin-bottom:12px;">Demo payment mode — no real money moves. Coupons and referral bill credit are applied here.</div>' +
        '<label class="mz-field"><span class="mz-label">Coupon code (optional)</span><input class="mz-input" name="coupon_code" placeholder="e.g. MZIGO100"></label>' +
        '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-invoice-coupon-apply>Apply coupon</button>' +
        '<div class="mz-kv" style="margin-top:12px;"><dt>Invoice amount</dt><dd>' + money(amount) + '</dd></div>' +
        '<div class="mz-kv"><dt>Coupon discount</dt><dd data-invoice-discount>' + money(0) + '</dd></div>' +
        '<div class="mz-kv"><dt>Referral bill credit</dt><dd data-invoice-credit>' + money(credit) + '</dd></div>' +
        '<div class="mz-kv"><dt>Total to pay</dt><dd data-invoice-total><strong>' + money(Math.max(0, amount - credit)) + '</strong></dd></div>' +
        '<div class="mz-hint" data-invoice-message></div>',
      onSubmit: function () {
        return api('billing.php', { method: 'POST', body: { action: 'pay_invoice', invoice_id: invoiceId, coupon_code: couponCode } })
          .then(function (res) {
            toast(res.message + ' Ref ' + res.reference);
            trackEvent('billing_invoice_paid', { label: 'Subscription invoice', meta: { coupon: couponCode, credit: credit } });
            dialog.close();
            setTimeout(loadBilling, 50);
          });
      }
    });
    var apply = dialog.overlay.querySelector('[data-invoice-coupon-apply]');
    if (apply) apply.addEventListener('click', function () {
      var code = (dialog.overlay.querySelector('[name="coupon_code"]').value || '').trim();
      if (!code) { toast('Enter a coupon code.', 'error'); return; }
      apply.disabled = true;
      api('growth.php', { method: 'POST', body: { action: 'validate_coupon', code: code, amount_kes: amount, context: 'subscription' } })
        .then(function (res) {
          couponCode = code;
          var finalAmount = Math.max(0, res.final_kes - credit);
          dialog.overlay.querySelector('[data-invoice-discount]').textContent = money(res.discount_kes);
          dialog.overlay.querySelector('[data-invoice-total]').innerHTML = '<strong>' + money(finalAmount) + '</strong>';
          dialog.overlay.querySelector('[data-invoice-message]').textContent = res.coupon.name + ' applied.';
          var payBtn = dialog.overlay.querySelector('form [type="submit"]') || dialog.overlay.querySelector('[type="submit"]');
          if (payBtn) payBtn.textContent = 'Pay ' + money(finalAmount);
          apply.disabled = false;
        }).catch(function (err) {
          couponCode = '';
          dialog.overlay.querySelector('[data-invoice-discount]').textContent = money(0);
          dialog.overlay.querySelector('[data-invoice-total]').innerHTML = '<strong>' + money(Math.max(0, amount - credit)) + '</strong>';
          dialog.overlay.querySelector('[data-invoice-message]').textContent = err.message;
          apply.disabled = false;
        });
    });
  }

  function initBillingPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Fbilling'; return; }
    if (APP.user.role === 'freelancer') { window.location.href = '/freelancer'; return; }
    if (APP.user.role === 'customer') { window.location.href = '/customer'; return; }
    initAppNav();
    loadBilling();
  }

  function loadBilling() {
    var host = $('[data-app="billing"]');
    if (!host) return;
    api('billing.php').then(function (data) {
      renderBilling(host, data);
      loadStatements(host);
    }).catch(function (err) {
      if (appRedirectOnAuth(err)) return;
      fillNote(host, esc(err.message), 'error');
    });
  }

  function loadStatements(host) {
    api('settlements.php').then(function (data) {
      var rows = data.rows || [];
      var tableRows = rows.map(function (r) {
        return '<tr><td class="mz-mono">' + esc(r.month) + '</td><td>' + r.parcels + '</td>' +
          '<td>' + money(r.delivery_fees) + '</td><td>' + money(r.platform_fees) + '</td>' +
          '<td>' + money(r.collected) + '</td><td>' + money(r.pending) + '</td>' +
          '<td>' + money(r.subscription_paid) + '</td><td>' + money(r.refunded) + '</td></tr>';
      }).join('');
      var cards = rows.map(function (r) {
        return '<div class="mz-mobile-card"><div class="mz-mobile-card__top"><span class="mz-mono">' + esc(r.month) + '</span>' +
          '<span class="mz-badge">' + r.parcels + ' parcels</span></div>' +
          '<div class="mz-mobile-card__route">Delivery fees ' + money(r.delivery_fees) + ' · platform ' + money(r.platform_fees) + '</div>' +
          '<div class="mz-mobile-card__meta">Collected ' + money(r.collected) + ' · pending ' + money(r.pending) + '</div>' +
          '<div class="mz-mobile-card__bottom"><span class="mz-hint">Subscription ' + money(r.subscription_paid) + (r.refunded ? ' · refunded ' + money(r.refunded) : '') + '</span></div></div>';
      }).join('');
      var card = document.createElement('div');
      card.className = 'mz-saas-card';
      card.style.marginTop = '18px';
      card.innerHTML = '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Settlement statements</div>' +
        '<div class="mz-hint">Monthly fees, customer collections and subscription payments.</div></div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="mz-tab" data-statements-csv>Export CSV</button>' +
        '<button type="button" class="mz-tab" data-statements-print>Print</button></div></div>' +
        '<div class="mz-settings-table"><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Month</th><th>Parcels</th><th>Delivery fees</th><th>Platform fees</th><th>Collected</th><th>Pending</th><th>Subscription</th><th>Refunded</th></tr></thead><tbody>' + (tableRows || '<tr><td colspan="8" class="mz-hint">No statements yet.</td></tr>') + '</tbody></table></div></div>' +
        '<div class="mz-settings-mobile">' + (cards || '<div class="mz-hint">No statements yet.</div>') + '</div>';
      host.appendChild(card);
      var csv = card.querySelector('[data-statements-csv]');
      if (csv) csv.addEventListener('click', function () {
        fetch('/api/settlements.php?format=csv', { credentials: 'same-origin' }).then(function (res) { return res.blob(); }).then(function (blob) {
          var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
          a.download = 'mzigo-statements-' + new Date().toISOString().slice(0, 10) + '.csv';
          document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 400);
        }).catch(function (err) { toast(err.message, 'error'); });
      });
      var print = card.querySelector('[data-statements-print]');
      if (print) print.addEventListener('click', function () {
        var w = window.open('', '_blank');
        if (!w) { toast('Allow pop-ups to print statements.', 'error'); return; }
        var brand = workspaceDocumentBrand();
        var brandHeader = documentBrandMarkHTML(brand);
        var brandFooter = (brand.footer ? '<div>' + esc(brand.footer) + '</div>' : '') +
          '<div>' + esc(brand.support) + '</div><div>' + esc(brand.tagline) + '</div>';
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(brand.name) + ' statements</title>' +
          '<style>body{font-family:Outfit,Segoe UI,sans-serif;margin:28px;color:#1f2a12}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}th,td{border-bottom:1px solid #e3ead9;padding:6px;text-align:left}th{color:#5b6b45}.doc-footer{margin-top:18px;padding-top:10px;border-top:1px solid #e3ead9;color:#5b6b45;font-size:11px;line-height:1.6;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}@media print{button{display:none}}</style></head><body>' +
          '<div>' + brandHeader + '</div><h1>' + esc(brand.name) + ' settlement statements</h1><div>' + esc(data.tenant || '') + ' &middot; ' + esc(new Date().toLocaleString()) + '</div>' +
          '<table><thead><tr><th>Month</th><th>Parcels</th><th>Delivery</th><th>Platform</th><th>Collected</th><th>Pending</th><th>Subscription</th><th>Refunded</th></tr></thead><tbody>' + tableRows + '</tbody></table>' +
          '<div class="doc-footer">' + brandFooter + '</div>' +
          '<button onclick="window.print()" style="margin-top:16px;padding:8px 14px;border:0;border-radius:8px;background:' + esc(brand.primary) + ';color:#fff;cursor:pointer">Print / Save as PDF</button></body></html>';
        w.document.write(html); w.document.close();
      });
    }).catch(function (err) { console.error('statements failed', err); });
  }

  function renderBilling(host, data) {
    var TX_LABELS = { subscription: 'Subscription', parcel_payment: 'Parcel collection', manual: 'Manual settlement', refund: 'Refund' };
    var paymentsHTML = (data.transactions || []).length ? (
      '<div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead><tbody>' +
      data.transactions.map(function (tx) {
        return '<tr><td class="mz-mono">' + esc(tx.reference) + '</td><td>' + esc(TX_LABELS[tx.type] || tx.type) + '</td>' +
          '<td>' + money(tx.amount_kes) + '</td><td>' + esc(tx.method) + '</td>' +
          '<td><span class="mz-badge mz-badge--' + (tx.status === 'succeeded' ? 'ok' : tx.status === 'failed' ? 'warn' : '') + '">' + esc(tx.status) + '</span></td>' +
          '<td>' + esc(fmtDate(tx.created_at)) + '</td></tr>';
      }).join('') + '</tbody></table></div>'
    ) : '<div class="mz-empty-note">No payments yet.</div>';

    var usage = data.usage;
    var sub = usage.subscription || {};
    var plan = usage.plan || { name: '—', monthly_price_kes: 0, parcel_limit: 0 };
    var pct = plan.parcel_limit ? Math.min(100, Math.round((usage.parcels_used / plan.parcel_limit) * 100)) : 0;
    var status = (sub.subscription_status || 'cancelled').replace('_', ' ');
    var readOnly = !!(APP.tenant && APP.tenant.role === 'staff');
    var platformFree = !!data.free;
    var workspacePaused = !!(data.tenant && ['suspended', 'cancelled'].indexOf(data.tenant.status) !== -1);
    var billingInfo = data.billing || {};
    var subState = data.subscription || {};
    var cancelAtEnd = !!subState.cancel_at_period_end;
    var pendingPlanName = subState.pending_plan_name || '';
    var pendingPlanAt = subState.pending_plan_at || '';
    var periodEnd = subState.current_period_end || '';
    var billingExtras =
      '<div class="mz-saas-grid mz-saas-grid--2" style="margin:18px 0;">' +
        '<div class="mz-saas-card"><div class="mz-card__title">Billing details</div>' +
          '<form class="mz-stack" data-billing-details>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">Billing name</span><input class="mz-input" name="billing_name" value="' + esc(billingInfo.name || data.tenant.name) + '"' + (readOnly ? ' disabled' : '') + '></label>' +
              '<label class="mz-field"><span class="mz-label">Billing email</span><input class="mz-input" name="billing_email" type="email" value="' + esc(billingInfo.email || '') + '"' + (readOnly ? ' disabled' : '') + '></label>' +
            '</div>' +
            '<label class="mz-field"><span class="mz-label">Billing address</span><input class="mz-input" name="billing_address" value="' + esc(billingInfo.address || '') + '" placeholder="Street, city, postal address"' + (readOnly ? ' disabled' : '') + '></label>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">KRA PIN / Tax ID</span><input class="mz-input" name="tax_id" value="' + esc(billingInfo.tax_id || '') + '"' + (readOnly ? ' disabled' : '') + '></label>' +
              '<label class="mz-field"><span class="mz-label">VAT rate (%)</span><input class="mz-input" name="tax_rate" type="number" min="0" max="30" step="0.01" value="' + esc(String(billingInfo.tax_rate != null ? billingInfo.tax_rate : 16)) + '"' + (readOnly ? ' disabled' : '') + '></label>' +
            '</div>' +
            (readOnly ? '<div class="mz-hint">Only owners and admins can edit billing details.</div>' : '<button type="submit" class="mz-btn">Save billing details</button>') +
          '</form></div>' +
        '<div class="mz-saas-card"><div class="mz-card__title">Subscription controls</div>' +
          (cancelAtEnd
            ? '<div class="mz-alert" style="margin-bottom:12px;">Subscription cancels at period end' + (periodEnd ? ' (' + esc(fmtDate(periodEnd)) + ')' : '') + '.</div>' + (!readOnly ? '<button type="button" class="mz-btn" data-sub-resume>Keep subscription</button>' : '')
            : (pendingPlanName
              ? '<div class="mz-alert" style="margin-bottom:12px;">Downgrade to <strong>' + esc(pendingPlanName) + '</strong> scheduled' + (pendingPlanAt ? ' for ' + esc(fmtDate(pendingPlanAt)) : '') + '.</div>' + (!readOnly ? '<button type="button" class="mz-btn mz-btn--outline" data-sub-cancel-change>Cancel scheduled downgrade</button>' : '')
              : '<div class="mz-hint" style="margin-bottom:12px;">Plans renew manually in this demo. Cancel at period end or schedule a downgrade; upgrades are billed immediately.</div>' + (!readOnly ? '<button type="button" class="mz-btn mz-btn--outline" data-sub-cancel>Cancel at period end</button>' : ''))) +
          '<div class="mz-plan-hint" style="margin-top:10px;">No mid-cycle cash refunds. Downgrades/cancellations apply from the next period; VAT is included in the plan price and shown on invoices.</div>' +
        '</div>' +
      '</div>';
    var invoicesMobile = (data.invoices || []).map(function (inv) {
      return '<div class="mz-mobile-card"><div class="mz-mobile-card__top"><span class="mz-mono">' + esc(inv.number) + '</span>' +
        '<span class="mz-badge ' + (inv.status === 'paid' ? 'mz-badge--ok' : inv.status === 'pending' ? 'mz-badge--warn' : '') + '">' + esc(inv.status) + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(inv.plan_name) + ' · ' + money(inv.amount_kes) + '</div>' +
        '<div class="mz-mobile-card__bottom"><div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(fmtDate(inv.issued_at)) + '</div>' +
          (inv.status === 'pending'
            ? (platformFree ? '<span class="mz-hint">No payment required</span>' : (readOnly ? '<span class="mz-hint">Owner action required</span>' : '<button type="button" class="mz-link" data-pay="' + inv.id + '" data-pay-amount="' + inv.amount_kes + '">Pay with M-Pesa</button>'))
            : '<span class="mz-hint">' + esc(inv.reference || '') + '</span>') +
          ' <button type="button" class="mz-link" data-invoice-doc="' + inv.id + '">Invoice</button></div></div>';
    }).join('');
    var paymentsMobile = (data.transactions || []).map(function (tx) {
      return '<div class="mz-mobile-card"><div class="mz-mobile-card__top"><span class="mz-mono">' + esc(tx.reference) + '</span>' +
        '<span class="mz-badge ' + (tx.status === 'succeeded' ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + esc(tx.status) + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(TX_LABELS[tx.type] || tx.type) + ' · ' + money(tx.amount_kes) + '</div>' +
        '<div class="mz-mobile-card__bottom"><div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(tx.method) + ' · ' + esc(fmtDate(tx.created_at)) + '</div></div></div>';
    }).join('');

    var plansHTML = data.plans.map(function (p) {
      var current = plan.code === p.code;
      var downgrade = !current && !platformFree && (p.monthly_price_kes < plan.monthly_price_kes);
      var label = current ? 'Current plan' : (downgrade ? 'Downgrade at renewal' : (p.monthly_price_kes > plan.monthly_price_kes ? 'Upgrade now' : 'Switch plan'));
      return '<div class="mz-plan-card' + (current ? ' mz-plan-card--current' : '') + '">' +
        '<div class="mz-plan-name">' + esc(p.name) + (current ? ' <span class="mz-badge mz-badge--ok">Current</span>' : '') + '</div>' +
        '<div class="mz-plan-price">' + money(p.monthly_price_kes) + '<span>/month</span></div>' +
        '<div class="mz-plan-meta">' + p.parcel_limit + ' parcels · ' + p.staff_limit + ' team</div>' +
        '<button type="button" class="mz-btn' + (current ? ' mz-btn--outline' : '') + '" data-plan="' + p.code + '" data-plan-mode="' + (downgrade ? 'schedule' : 'now') + '"' + (current || readOnly ? ' disabled' : '') + '>' +
          label + '</button>' +
      '</div>';
    }).join('');

    var invoicesHTML = (data.invoices || []).length ? (
      '<div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Invoice</th><th>Plan</th><th>Amount</th><th>Status</th><th>Issued</th><th></th></tr></thead><tbody>' +
      data.invoices.map(function (inv) {
        return '<tr><td class="mz-mono">' + esc(inv.number) + '</td><td>' + esc(inv.plan_name) + '</td>' +
          '<td>' + money(inv.amount_kes) + '</td>' +
          '<td><span class="mz-badge mz-badge--' + (inv.status === 'paid' ? 'ok' : inv.status === 'pending' ? 'warn' : '') + '">' + esc(inv.status) + '</span></td>' +
          '<td>' + esc(fmtDate(inv.issued_at)) + '</td>' +
          '<td>' + (inv.status === 'pending'
            ? (platformFree
              ? '<span style="font-size:11px;color:var(--mz-muted-fg);">No payment required (free mode)</span>'
              : (readOnly
                ? '<span style="font-size:11px;color:var(--mz-muted-fg);">Owner action required</span>'
                : '<button type="button" class="mz-btn" style="height:30px;padding:0 12px;font-size:11px;" data-pay="' + inv.id + '" data-pay-amount="' + inv.amount_kes + '">Pay with M-Pesa</button>'))
            : (inv.reference ? '<span style="font-size:11px;color:var(--mz-muted-fg);">' + esc(inv.reference) + '</span>' : '')) +
            ' <button type="button" class="mz-link" data-invoice-doc="' + inv.id + '">Invoice</button></td></tr>';
      }).join('') + '</tbody></table></div>'
    ) : '<div class="mz-empty-note">No invoices yet — your trial does not require payment.</div>';

    var keyAlert = pendingApiKey
      ? '<div class="mz-alert" style="margin-bottom:16px;">New API key (copy it now — it will not be shown again): <code style="word-break:break-all;">' +
          esc(pendingApiKey) + '</code> <button type="button" class="mz-link" data-dismiss-key>Dismiss</button></div>'
      : '';
    var freeBanner = data.free
      ? '<div class="mz-alert" style="margin-bottom:16px;">The platform is currently free — plan changes take effect immediately and no invoice is raised.</div>'
      : '';
    var pausedBanner = workspacePaused
      ? '<div class="mz-alert" style="margin-bottom:16px;background:#fef3c7;border-color:#f0d68a;"><strong>Your workspace is paused.</strong> ' +
        (readOnly ? 'Ask your workspace owner to choose a plan and settle the invoice.' : 'Choose a plan below, then settle the invoice to resume sending immediately.') + '</div>'
      : '';
    var readOnlyBanner = readOnly
      ? '<div class="mz-alert" style="margin-bottom:16px;background:#f1f5f9;border-color:#cbd5e1;">Read-only: only workspace owners and admins can change plans or pay invoices.</div>'
      : '';
    var teamBanner = readOnly
      ? '<div class="mz-alert" style="margin-bottom:16px;background:#f1f5f9;border-color:#cbd5e1;">Read-only: only workspace owners and admins can edit the business profile, team or API keys.</div>'
      : '';
    var credBanner = teamCredential
      ? '<div class="mz-alert" style="margin-bottom:16px;">Share these sign-in details with <strong>' + esc(teamCredential.email) + '</strong>: ' +
        (teamCredential.password
          ? 'temporary password <code>' + esc(teamCredential.password) + '</code>'
          : 'they already have an account; ask them to sign in.') +
        ' <button type="button" class="mz-link" data-cred-copy>Copy</button> ' +
        '<button type="button" class="mz-link" data-cred-dismiss>Dismiss</button></div>'
      : '';
    host.innerHTML = pausedBanner + readOnlyBanner + keyAlert + freeBanner +
      '<div class="mz-saas-grid mz-saas-grid--2" style="margin-bottom:18px;">' +
        '<div class="mz-saas-card">' +
          '<div class="mz-row"><div><div class="mz-cap">Workspace</div><div style="font-size:18px;font-weight:700;">' + esc(data.tenant.name) + '</div></div>' +
          '<span class="mz-badge mz-badge--' + (status === 'active' ? 'ok' : 'warn') + '">' + esc(status) + '</span></div>' +
          '<div style="margin-top:10px;font-size:13px;color:var(--mz-muted-fg);">' + esc(plan.name) + ' · ' + money(plan.monthly_price_kes) + '/month' +
            (usage.trial_days_left != null ? ' · ' + usage.trial_days_left + ' trial days left' : '') + '</div>' +
        '</div>' +
        '<div class="mz-saas-card">' +
          '<div class="mz-cap">This month</div>' +
          '<div style="font-size:18px;font-weight:700;margin-bottom:6px;">' + usage.parcels_used + ' / ' + plan.parcel_limit + ' parcels</div>' +
          '<div class="mz-meter"><span style="width:' + pct + '%"></span></div>' +
          '<div style="margin-top:8px;font-size:12px;color:var(--mz-muted-fg);">' + usage.staff_used + ' of ' + plan.staff_limit + ' team members · ' + data.provider + ' payments</div>' +
        '</div>' +
      '</div>' +
      billingExtras +
      '<div class="mz-cap" style="margin:18px 0 10px;">Plans</div>' +
      '<div class="mz-pricing-grid mz-pricing-grid--compact">' + plansHTML + '</div>' +
      '<div class="mz-cap" style="margin:22px 0 10px;">Invoices</div>' +
      '<div class="mz-invoices-table">' + invoicesHTML + '</div><div class="mz-invoices-mobile">' + (invoicesMobile || '<div class="mz-hint">No invoices yet.</div>') + '</div>' +
      '<div class="mz-cap" style="margin:22px 0 10px;">Payments</div>' +
      '<div class="mz-payments-table">' + paymentsHTML + '</div><div class="mz-payments-mobile">' + (paymentsMobile || '<div class="mz-hint">No payments yet.</div>') + '</div>' +
      '<div class="mz-plan-hint" style="margin-top:14px;">Demo payment mode: clicking “Pay with M-Pesa” settles the invoice through the demo provider and activates the workspace.</div>';

    host.querySelectorAll('[data-plan]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var code = btn.getAttribute('data-plan');
        var mode = btn.getAttribute('data-plan-mode');
        btn.disabled = true;
        api('billing.php', { method: 'POST', body: {
          action: mode === 'schedule' ? 'schedule_plan_change' : 'change_plan', plan_code: code
        } })
          .then(function (res) {
            if (res.invoice) toast('Invoice ' + res.invoice.number + ' created for ' + money(res.invoice.amount_kes) + '.');
            else toast(res.message || 'Plan updated.');
            loadBilling();
          })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });
    host.querySelectorAll('[data-pay]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openInvoiceCheckout(host, Number(btn.getAttribute('data-pay')), Number(btn.getAttribute('data-pay-amount') || 0), Number(data.bill_credit_kes || 0));
      });
    });
    var detailsForm = host.querySelector('[data-billing-details]');
    if (detailsForm) detailsForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = detailsForm.querySelector('[type="submit"]');
      btn.disabled = true;
      api('billing.php', { method: 'POST', body: {
        action: 'update_billing_details',
        billing_name: formValue(detailsForm, 'billing_name'),
        billing_email: formValue(detailsForm, 'billing_email'),
        billing_address: formValue(detailsForm, 'billing_address'),
        tax_id: formValue(detailsForm, 'tax_id'),
        tax_rate: Number(formValue(detailsForm, 'tax_rate') || 0)
      } }).then(function (res) { toast(res.message || 'Billing details saved.'); loadBilling(); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    function billingAction(action, confirmText) {
      if (confirmText && !window.confirm(confirmText)) return;
      api('billing.php', { method: 'POST', body: { action: action } })
        .then(function (res) { toast(res.message || 'Billing updated.'); loadBilling(); })
        .catch(function (err) { toast(err.message, 'error'); });
    }
    var cancelBtn = host.querySelector('[data-sub-cancel]');
    if (cancelBtn) cancelBtn.addEventListener('click', function () { billingAction('schedule_cancel', 'Cancel the subscription at the end of the current period?'); });
    var resumeBtn = host.querySelector('[data-sub-resume]');
    if (resumeBtn) resumeBtn.addEventListener('click', function () { billingAction('resume_subscription'); });
    var cancelChange = host.querySelector('[data-sub-cancel-change]');
    if (cancelChange) cancelChange.addEventListener('click', function () { billingAction('cancel_scheduled_change'); });
    host.querySelectorAll('[data-invoice-doc]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window.open('/api/invoice-doc.php?invoice=' + btn.getAttribute('data-invoice-doc'), '_blank', 'noopener');
      });
    });
  }

  /* ----------------------------------------------------------------- settings */
  function addSettingsAnchors() {
    var host = $('[data-app="settings"]');
    if (!host) return;
    host.querySelectorAll('.mz-saas-card').forEach(function (card) {
      var title = (card.querySelector('.mz-card__title') || {}).textContent || '';
      if (!card.id && /team|member/i.test(title)) card.id = 'team';
      if (!card.id && /api key/i.test(title)) card.id = 'api';
    });
  }

  function initSettingsPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Fsettings'; return; }
    if (APP.user.role === 'freelancer') { window.location.href = '/freelancer'; return; }
    if (APP.user.role === 'customer') { window.location.href = '/customer'; return; }
    initAppNav();
    loadSettings();
  }

  function loadSettings() {
    var host = $('[data-app="settings"]');
    if (!host) return;
    api('settings.php').then(function (data) {
      renderSettings(host, data);
    }).catch(function (err) {
      if (appRedirectOnAuth(err)) return;
      fillNote(host, esc(err.message), 'error');
    });
  }


  function twoFactorCard(t) {
    t = t || {};
    var enabled = !!t.enabled;
    return '<div class="mz-saas-card" style="margin-top:16px;" data-twofa-card>' +
      '<div class="mz-row"><div><div class="mz-card__title" style="margin:0;">Two-factor authentication</div>' +
      '<div class="mz-hint">Protect sign-in with a 6-digit code from an authenticator app.</div></div>' +
      '<span class="mz-badge ' + (enabled ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (enabled ? 'Enabled' : 'Not enabled') + '</span></div>' +
      (enabled
        ? '<div class="mz-hint" style="margin-top:10px;">Recovery codes left: <strong>' + Number(t.recovery_remaining || 0) + '</strong> of 8</div>' +
          '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;"><button type="button" class="mz-btn mz-btn--outline" data-twofa-recovery>Generate recovery codes</button>' +
          '<button type="button" class="mz-btn mz-btn--outline" data-twofa-disable>Disable 2FA</button></div>'
        : '<button type="button" class="mz-btn" style="margin-top:12px;" data-twofa-setup>Set up authenticator</button><div data-twofa-setup-area hidden style="margin-top:12px;"></div>') +
      '</div>';
  }

  function showRecoveryCodes(codes) {
    codes = codes || [];
    if (!codes.length) return;
    openDialog({
      title: 'Save your 2FA recovery codes',
      sub: 'Each code can be used once if you lose your authenticator. Store them somewhere safe.',
      submitLabel: 'I have saved them',
      body: '<div class="mz-alert" style="background:#fffbeb;border-color:#f0d68a;color:#92400e;">These codes will not be shown again.</div>' +
        '<pre style="font-family:ui-monospace,monospace;font-size:15px;line-height:1.9;background:#f7faf3;border:1px solid #e3ebd8;border-radius:10px;padding:14px;">' + codes.map(esc).join('\n') + '</pre>',
      onSubmit: function () { return Promise.resolve(); }
    });
  }

  function bindTwoFactorCard(card, reload) {
    if (!card) return;
    var setup = card.querySelector('[data-twofa-setup]');
    if (setup) setup.addEventListener('click', function () {
      setup.disabled = true;
      api('2fa.php', { method: 'POST', body: { action: 'setup' } }).then(function (res) {
        var area = card.querySelector('[data-twofa-setup-area]');
        area.hidden = false;
        area.innerHTML = '<div class="mz-hint">Add this secret to your authenticator app (manual entry):</div>' +
          '<div class="mz-mono" style="word-break:break-all;margin:6px 0;">' + esc(res.secret) + '</div>' +
          '<div class="mz-hint" style="word-break:break-all;">Or open: <a class="mz-link" href="' + esc(res.otpauth) + '">otpauth link</a></div>' +
          '<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;"><input class="mz-input" data-twofa-enable-code inputmode="numeric" maxlength="6" placeholder="6-digit code" style="max-width:160px;">' +
          '<button type="button" class="mz-btn" data-twofa-enable>Confirm & enable</button></div>';
        var confirm = area.querySelector('[data-twofa-enable]');
        confirm.addEventListener('click', function () {
          confirm.disabled = true;
          api('2fa.php', { method: 'POST', body: { action: 'enable', code: area.querySelector('[data-twofa-enable-code]').value } })
            .then(function (res) { toast('Two-factor authentication enabled.'); showRecoveryCodes(res.recovery_codes); reload(); })
            .catch(function (err) { toast(err.message, 'error'); confirm.disabled = false; });
        });
      }).catch(function (err) { toast(err.message, 'error'); setup.disabled = false; });
    });
    var regenerate = card.querySelector('[data-twofa-recovery]');
    if (regenerate) regenerate.addEventListener('click', function () {
      var password = window.prompt('Enter your current password to generate new recovery codes:');
      if (!password) return;
      regenerate.disabled = true;
      api('2fa.php', { method: 'POST', body: { action: 'regenerate_recovery_codes', password: password } })
        .then(function (res) { showRecoveryCodes(res.recovery_codes); toast('New recovery codes generated.'); reload(); })
        .catch(function (err) { toast(err.message, 'error'); regenerate.disabled = false; });
    });
    var disable = card.querySelector('[data-twofa-disable]');
    if (disable) disable.addEventListener('click', function () {
      var password = window.prompt('Enter your current password to disable 2FA:');
      if (!password) return;
      disable.disabled = true;
      api('2fa.php', { method: 'POST', body: { action: 'disable', password: password } })
        .then(function () { toast('Two-factor authentication disabled.'); reload(); })
        .catch(function (err) { toast(err.message, 'error'); disable.disabled = false; });
    });
  }

  function securityCards(data) {
    var usage = data.api_usage || { rows: [], total_30d: 0 };
    var usageRows = (usage.rows || []).map(function (u) {
      return '<tr><td class="mz-mono">' + esc(u.day) + '</td><td>' + esc(u.key_name) + '</td><td>' + u.requests + '</td></tr>';
    }).join('');
    return twoFactorCard(data.twofa || {}) +
            '<div class="mz-saas-card" style="margin-top:16px;"><div class="mz-row"><div class="mz-card__title" style="margin:0;">API usage (30 days)</div>' +
        '<span class="mz-badge">' + (usage.total_30d || 0) + ' requests</span></div>' +
        '<div class="mz-table-wrap" role="region" aria-label="API usage" tabindex="0"><table class="mz-table"><thead><tr><th>Day</th><th>API key</th><th>Requests</th></tr></thead><tbody>' +
        (usageRows || '<tr><td colspan="3" class="mz-hint">No API requests recorded yet.</td></tr>') + '</tbody></table></div></div>';
  }

  function bindSecurityCards(host) {
    bindTwoFactorCard(host.querySelector('[data-twofa-card]'), loadSettings);
  }

  function appendTenantBranding(host, data, canManage) {
    if (!host || !canManage) return;
    var b = data.branding || {};
    var cards = data.rate_cards || [];
    var options = data.rate_card_options || { carriers: [], sizes: [] };
    var carrierName = function (slug) {
      for (var i = 0; i < options.carriers.length; i++) { if (options.carriers[i].slug === slug) return options.carriers[i].name; }
      return slug || 'All carriers';
    };
    var rateRows = cards.map(function (r) {
      return '<tr><td>' + esc(carrierName(r.carrier_slug)) + '</td><td>' + esc(r.route_from || 'Any') + ' → ' + esc(r.route_to || 'Any') + '</td>' +
        '<td>' + esc(r.size || 'All sizes') + '</td><td>' + (r.base_fee_kes !== null ? money(r.base_fee_kes) : '—') + '</td>' +
        '<td>' + (r.holding_fee_kes !== null ? money(r.holding_fee_kes) : '—') + '</td><td>' + (r.insurance_pct !== null ? r.insurance_pct + '%' : '—') + '</td>' +
        '<td>' + (r.platform_fee_kes !== null ? money(r.platform_fee_kes) : '—') + '</td>' +
        '<td>' + (Number(r.is_active) ? '<span class="mz-badge mz-badge--ok">Active</span>' : '<span class="mz-badge">Off</span>') + '</td>' +
        '<td style="white-space:nowrap;"><button type="button" class="mz-link" data-rate-edit="' + r.id + '">Edit</button> · <button type="button" class="mz-link" data-rate-delete="' + r.id + '">Delete</button></td></tr>';
    }).join('');
    var brandingCard = '<div class="mz-saas-card" style="margin-top:18px;" data-tenant-branding>' +
      '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Workspace branding</div>' +
      '<div class="mz-hint">Colours and document details used across your workspace. Upload the workspace logo under Business profile.</div></div>' +
      '<span class="mz-badge">' + esc(data.tenant.name) + '</span></div>' +
      '<form class="mz-stack" data-tenant-branding-form>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Primary colour</span><input class="mz-input" type="color" name="primary_color" value="' + esc(b.primary_color || '#3c5223') + '"></label>' +
          '<label class="mz-field"><span class="mz-label">Secondary colour</span><input class="mz-input" type="color" name="secondary_color" value="' + esc(b.secondary_color || '#4a6330') + '"></label>' +
        '</div>' +
        '<label class="mz-field"><span class="mz-label">Accent colour</span><input class="mz-input" type="color" name="accent_color" value="' + esc(b.accent_color || '#8ddc95') + '"></label>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Support email</span><input class="mz-input" type="email" name="support_email" value="' + esc(b.support_email || '') + '"></label>' +
          '<label class="mz-field"><span class="mz-label">Support phone</span><input class="mz-input" name="support_phone" value="' + esc(b.support_phone || '') + '"></label>' +
        '</div>' +
        '<label class="mz-field"><span class="mz-label">Document footer</span><input class="mz-input" name="document_footer" maxlength="255" value="' + esc(b.document_footer || '') + '" placeholder="Payment terms, support hours, etc."></label>' +
        '<button class="mz-btn" type="submit">Save branding</button></form></div>';
    var rateCard = '<div class="mz-saas-card" style="margin-top:18px;" data-rate-cards>' +
      '<div class="mz-card__title" style="margin:0 0 6px;">Carrier rate cards</div>' +
      '<div class="mz-hint" style="margin-bottom:12px;">Override the platform quote for a carrier, route and/or size. The most specific matching card wins; unset fields fall back to the platform rate.</div>' +
      '<div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Carrier</th><th>Route</th><th>Size</th><th>Base</th><th>Holding</th><th>Insurance</th><th>Platform</th><th>Status</th><th></th></tr></thead><tbody>' +
        (rateRows || '<tr><td colspan="9" class="mz-hint">No tenant rate overrides yet.</td></tr>') + '</tbody></table></div>' +
      '<form class="mz-stack" data-rate-create style="margin-top:14px;">' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Carrier</span><select class="mz-input" name="carrier_slug"><option value="">All carriers</option>' +
          options.carriers.map(function (c) { return '<option value="' + esc(c.slug) + '">' + esc(c.name) + '</option>'; }).join('') + '</select></label>' +
        '<label class="mz-field"><span class="mz-label">Size</span><select class="mz-input" name="size"><option value="">All sizes</option>' +
          (options.sizes || []).map(function (s) { return '<option value="' + esc(s) + '">' + esc(s) + '</option>'; }).join('') + '</select></label></div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">From (optional)</span><input class="mz-input" name="route_from" placeholder="Nairobi"></label>' +
        '<label class="mz-field"><span class="mz-label">To (optional)</span><input class="mz-input" name="route_to" placeholder="Mombasa"></label></div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Base fee KES</span><input class="mz-input" name="base_fee_kes" type="number" min="0" placeholder="Fallback"></label>' +
        '<label class="mz-field"><span class="mz-label">Holding fee KES</span><input class="mz-input" name="holding_fee_kes" type="number" min="0"></label></div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Insurance %</span><input class="mz-input" name="insurance_pct" type="number" step="0.01" min="0" max="100"></label>' +
        '<label class="mz-field"><span class="mz-label">Platform fee KES</span><input class="mz-input" name="platform_fee_kes" type="number" min="0"></label></div>' +
        '<label class="mz-check"><input type="checkbox" name="is_active" checked><span><strong>Active</strong></span></label>' +
        '<button class="mz-btn" type="submit">Add rate card</button></form></div>';
    host.insertAdjacentHTML('beforeend', brandingCard + rateCard);
    var brandingForm = host.querySelector('[data-tenant-branding-form]');
    if (brandingForm) brandingForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('settings.php', { method: 'POST', body: { action: 'save_branding', primary_color: formValue(brandingForm, 'primary_color'), secondary_color: formValue(brandingForm, 'secondary_color'), accent_color: formValue(brandingForm, 'accent_color'), support_email: formValue(brandingForm, 'support_email'), support_phone: formValue(brandingForm, 'support_phone'), document_footer: formValue(brandingForm, 'document_footer') } })
        .then(function (res) { toast(res.message || 'Branding saved.'); loadSettings(); })
        .catch(function (err) { toast(err.message, 'error'); });
    });
    var logoInput = host.querySelector('[data-tenant-logo-input]');
    if (logoInput) logoInput.addEventListener('change', function () {
      if (!logoInput.files || !logoInput.files.length) return;
      var fd = new FormData(); fd.append('action', 'upload_branding_logo'); fd.append('logo', logoInput.files[0]); fd.append('_csrf', APP.csrf || '');
      var hint = host.querySelector('[data-tenant-logo-hint]'); if (hint) hint.textContent = 'Uploading…';
      fetch('/api/settings.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': APP.csrf || '', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.ok) throw new Error((d.error && d.error.message) || 'Upload failed.'); return d; }); })
        .then(function (res) { toast(res.message || 'Logo uploaded.'); loadSettings(); })
        .catch(function (err) { if (hint) hint.textContent = err.message; toast(err.message, 'error'); });
    });
    var removeLogo = host.querySelector('[data-remove-brand-logo]');
    if (removeLogo) removeLogo.addEventListener('click', function () {
      if (!window.confirm('Remove this workspace logo?')) return;
      api('settings.php', { method: 'POST', body: { action: 'remove_branding_logo' } })
        .then(function (res) { toast(res.message || 'Logo removed.'); loadSettings(); })
        .catch(function (err) { toast(err.message, 'error'); });
    });
    var rateForm = host.querySelector('[data-rate-create]');
    if (rateForm) rateForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('settings.php', { method: 'POST', body: { action: 'save_rate_card', carrier_slug: formValue(rateForm, 'carrier_slug'), size: formValue(rateForm, 'size'), route_from: formValue(rateForm, 'route_from'), route_to: formValue(rateForm, 'route_to'), base_fee_kes: formValue(rateForm, 'base_fee_kes'), holding_fee_kes: formValue(rateForm, 'holding_fee_kes'), insurance_pct: formValue(rateForm, 'insurance_pct'), platform_fee_kes: formValue(rateForm, 'platform_fee_kes'), is_active: !!rateForm.querySelector('[name="is_active"]').checked } })
        .then(function (res) { toast(res.message || 'Rate card saved.'); loadSettings(); })
        .catch(function (err) { toast(err.message, 'error'); });
    });
    host.querySelectorAll('[data-rate-delete]').forEach(function (btn) { btn.addEventListener('click', function () {
      if (!window.confirm('Delete this rate card?')) return;
      api('settings.php', { method: 'POST', body: { action: 'delete_rate_card', id: Number(btn.getAttribute('data-rate-delete')) } })
        .then(function (res) { toast(res.message || 'Rate card deleted.'); loadSettings(); }).catch(function (err) { toast(err.message, 'error'); });
    }); });
    host.querySelectorAll('[data-rate-edit]').forEach(function (btn) { btn.addEventListener('click', function () {
      var rate = cards.filter(function (r) { return String(r.id) === btn.getAttribute('data-rate-edit'); })[0];
      if (!rate) return;
      openDialog({ title: 'Edit rate card', submitLabel: 'Save rate card',
        body: '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Carrier</span><select class="mz-input" name="carrier_slug"><option value="">All carriers</option>' + options.carriers.map(function (c) { return '<option value="' + esc(c.slug) + '"' + (rate.carrier_slug === c.slug ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></label>' +
          '<label class="mz-field"><span class="mz-label">Size</span><select class="mz-input" name="size"><option value="">All sizes</option>' + (options.sizes || []).map(function (s) { return '<option value="' + esc(s) + '"' + (rate.size === s ? ' selected' : '') + '>' + esc(s) + '</option>'; }).join('') + '</select></label></div>' +
          '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">From</span><input class="mz-input" name="route_from" value="' + esc(rate.route_from || '') + '"></label><label class="mz-field"><span class="mz-label">To</span><input class="mz-input" name="route_to" value="' + esc(rate.route_to || '') + '"></label></div>' +
          '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Base fee KES</span><input class="mz-input" name="base_fee_kes" value="' + (rate.base_fee_kes ?? '') + '" type="number"></label><label class="mz-field"><span class="mz-label">Holding fee KES</span><input class="mz-input" name="holding_fee_kes" value="' + (rate.holding_fee_kes ?? '') + '" type="number"></label></div>' +
          '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Insurance %</span><input class="mz-input" name="insurance_pct" value="' + (rate.insurance_pct ?? '') + '" type="number" step="0.01"></label><label class="mz-field"><span class="mz-label">Platform fee KES</span><input class="mz-input" name="platform_fee_kes" value="' + (rate.platform_fee_kes ?? '') + '" type="number"></label></div>' +
          '<label class="mz-check"><input type="checkbox" name="is_active"' + (Number(rate.is_active) ? ' checked' : '') + '><span><strong>Active</strong></span></label>',
        onSubmit: function (form) {
          return api('settings.php', { method: 'POST', body: { action: 'save_rate_card', id: rate.id, carrier_slug: formValue(form, 'carrier_slug'), size: formValue(form, 'size'), route_from: formValue(form, 'route_from'), route_to: formValue(form, 'route_to'), base_fee_kes: formValue(form, 'base_fee_kes'), holding_fee_kes: formValue(form, 'holding_fee_kes'), insurance_pct: formValue(form, 'insurance_pct'), platform_fee_kes: formValue(form, 'platform_fee_kes'), is_active: !!form.querySelector('[name="is_active"]').checked } }).then(function (res) { toast(res.message || 'Rate card saved.'); loadSettings(); });
        }
      });
    }); });
  }

  function renderSettings(host, data) {
    var tenant = data.tenant;
    var usage = data.usage;
    var canManage = ['owner', 'admin'].indexOf(data.role) !== -1;
    var brand = data.branding || {};
    var teamBanner = canManage ? '' : '<div class="mz-alert" style="margin-bottom:16px;background:#f1f5f9;border-color:#cbd5e1;">Read-only: only workspace owners and admins can edit the business profile, team or API keys.</div>';
    var credBanner = teamCredential
      ? '<div class="mz-alert" style="margin-bottom:16px;">Share these sign-in details with <strong>' + esc(teamCredential.email) + '</strong>: ' +
        (teamCredential.password ? 'temporary password <code>' + esc(teamCredential.password) + '</code>' : 'they already have an account; ask them to sign in.') +
        ' <button type="button" class="mz-link" data-cred-copy>Copy</button> <button type="button" class="mz-link" data-cred-dismiss>Dismiss</button></div>'
      : '';

    var membersHTML = data.members.map(function (m) {
      return '<tr><td>' + avatar(m.name, 'sm') + ' ' + esc(m.name) + '</td><td>' + esc(m.email || '—') + '</td>' +
        '<td><span class="mz-badge">' + esc(m.role) + '</span></td>' +
        '<td>' + (data.role === 'owner' && m.role !== 'owner' ? '<button type="button" class="mz-link" data-remove-member="' + m.id + '">Remove</button>' : '') + '</td></tr>';
    }).join('');

    var membersMobile = data.members.map(function (m) {
      return '<div class="mz-mobile-card"><div class="mz-mobile-card__top"><strong>' + esc(m.name) + '</strong><span class="mz-badge">' + esc(m.role) + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(m.email || '—') + '</div>' +
        (data.role === 'owner' && m.role !== 'owner' ? '<div class="mz-mobile-card__bottom"><button type="button" class="mz-link" data-remove-member="' + m.id + '">Remove</button></div>' : '') +
        '</div>';
    }).join('');
    var keysHTML = data.apiKeys.length ? data.apiKeys.map(function (k) {
      return '<tr><td>' + esc(k.name) + '</td><td class="mz-mono">' + esc(k.prefix) + '…</td>' +
        '<td>' + esc(k.scopes || 'read,write') + '</td>' +
        '<td>' + esc(fmtDate(k.created_at)) + '</td>' +
        '<td>' + (k.revoked_at ? '<span class="mz-badge">revoked</span>' : '<span class="mz-badge mz-badge--ok">active</span>') + '</td>' +
        '<td>' + (!k.revoked_at ? '<button type="button" class="mz-link" data-revoke-key="' + k.id + '">Revoke</button>' : '') + '</td></tr>';
    }).join('') : '<tr><td colspan="6" style="color:var(--mz-muted-fg);">No API keys yet.</td></tr>';

    var keyAlert = pendingApiKey
      ? '<div class="mz-alert" style="margin-bottom:16px;">New API key (copy it now — it will not be shown again): <code style="word-break:break-all;">' +
          esc(pendingApiKey) + '</code> <button type="button" class="mz-link" data-dismiss-key>Dismiss</button></div>'
      : '';
    var keysMobile = data.apiKeys.length ? data.apiKeys.map(function (k) {
      return '<div class="mz-mobile-card"><div class="mz-mobile-card__top"><span class="mz-mono">' + esc(k.prefix) + '…</span>' +
        '<span class="mz-badge ' + (k.revoked_at ? '' : 'mz-badge--ok') + '">' + (k.revoked_at ? 'revoked' : 'active') + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(k.name) + '</div>' +
        '<div class="mz-mobile-card__meta">Scopes: ' + esc(k.scopes || 'read,write') + '</div>' +
        (!k.revoked_at ? '<div class="mz-mobile-card__bottom"><button type="button" class="mz-link" data-revoke-key="' + k.id + '">Revoke</button></div>' : '') +
        '</div>';
    }).join('') : '';

    var hooks = data.webhooks || [];
    var webhookEvents = data.webhook_events || [];
    var hooksHTML = hooks.length ? hooks.map(function (h) {
      return '<div class="mz-mobile-card" style="margin-bottom:8px;"><div class="mz-mobile-card__top"><span class="mz-mono" style="word-break:break-all;">' + esc(h.url) + '</span>' +
        '<span class="mz-badge ' + (h.last_status === 'sent' ? 'mz-badge--ok' : (h.last_status === 'failed' ? 'mz-badge--warn' : '')) + '">' + esc(h.last_status || 'new') + '</span></div>' +
        '<div class="mz-mobile-card__route">' + esc(h.events) + '</div>' +
        '<div class="mz-mobile-card__bottom"><span class="mz-hint">' + (h.last_delivery_at ? 'Last delivery ' + esc(fmtDate(h.last_delivery_at)) : 'No deliveries yet') + '</span>' +
          '<button type="button" class="mz-link" data-delete-webhook="' + h.id + '">Delete</button></div></div>';
    }).join('') : '<div class="mz-hint">No webhooks configured.</div>';
    var hooksCard = canManage
      ? '<div class="mz-saas-card"><div class="mz-card__title">Webhooks</div>' +
        '<div class="mz-hint" style="margin-bottom:10px;">Signed POST events for your ERP/OMS. Signature: HMAC-SHA256 of the raw body with your signing secret.</div>' +
        '<div data-webhooks-list>' + hooksHTML + '</div>' +
        '<form class="mz-stack" data-settings-form="webhook" style="margin-top:16px;">' +
          '<label class="mz-field"><span class="mz-label">Endpoint URL</span><input class="mz-input" name="url" type="url" placeholder="https://example.com/mzigo/webhook" required></label>' +
          '<div class="mz-field"><span class="mz-label">Events</span><div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">' +
            webhookEvents.map(function (ev) { return '<label style="display:flex;gap:6px;align-items:center;font-size:12px;"><input type="checkbox" name="events" value="' + esc(ev) + '"' + (ev === 'parcel.created' ? ' checked' : '') + '> ' + esc(ev) + '</label>'; }).join('') +
          '</div></div>' +
          '<button class="mz-btn" type="submit">Create webhook</button>' +
        '</form></div>'
      : '';
    var webhookSecretBanner = pendingWebhookSecret
      ? '<div class="mz-alert" style="margin-bottom:16px;">Webhook signing secret (copy it now — it will not be shown again): <code style="word-break:break-all;">' + esc(pendingWebhookSecret) + '</code> <button type="button" class="mz-link" data-dismiss-webhook-secret>Dismiss</button></div>'
      : '';
    host.innerHTML = webhookSecretBanner + credBanner + teamBanner + keyAlert +
      '<div class="mz-saas-grid mz-saas-grid--2" style="margin-bottom:18px;">' +
      '<div class="mz-saas-grid mz-saas-grid--2" style="margin-bottom:18px;">' +
        '<div class="mz-saas-card">' +
          '<div class="mz-card__title">Business profile</div>' +
          '<form class="mz-stack" data-settings-form="business">' +
            '<label class="mz-field"><span class="mz-label">Business name</span><input class="mz-input" name="name" value="' + esc(tenant.name) + '"' + (canManage ? '' : ' disabled') + '></label>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">Email</span><input class="mz-input" name="email" type="email" value="' + esc(tenant.email || '') + '"' + (canManage ? '' : ' disabled') + '></label>' +
              '<label class="mz-field"><span class="mz-label">Phone</span><input class="mz-input" name="phone" value="' + esc(tenant.phone || '') + '"' + (canManage ? '' : ' disabled') + '></label>' +
            '</div>' +
            (canManage ? '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--mz-border);">' +
              '<div class="mz-label" style="margin-bottom:6px;">Business logo</div>' +
              '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">' +
                (brand.logo_url ? '<img src="' + esc(brand.logo_url) + '" alt="Business logo" style="height:54px;max-width:190px;object-fit:contain;background:#fff;border:1px solid rgba(60,82,35,.14);border-radius:10px;padding:6px;">' : '<span class="mz-hint">No logo uploaded yet.</span>') +
                '<label class="mz-btn mz-btn--outline" style="cursor:pointer;">Upload logo<input type="file" accept="image/png,image/jpeg,image/webp" data-tenant-logo-input hidden></label>' +
                (brand.logo_url ? '<button type="button" class="mz-link" data-remove-brand-logo>Remove logo</button>' : '') +
              '</div>' +
              '<div class="mz-hint" style="margin-top:6px;" data-tenant-logo-hint>PNG, JPG or WebP up to 2 MB. Used on shipping labels, receipts, invoices and statements.</div>' +
            '</div>' : '') +
            (canManage ? '<button class="mz-btn" type="submit">Save business profile</button>' : '') +
          '</form>' +
        '</div>' +
        '<div class="mz-saas-card">' +
          '<div class="mz-card__title">Plan limits</div>' +
          '<div style="font-size:13px;color:var(--mz-muted-fg);line-height:1.9;">' +
            '<div><strong>' + esc(usage.plan.name) + '</strong> · ' + money(usage.plan.monthly_price_kes) + '/month</div>' +
            '<div>' + usage.parcels_used + ' / ' + usage.plan.parcel_limit + ' parcels this month</div>' +
            '<div>' + usage.staff_used + ' / ' + usage.plan.staff_limit + ' team members</div>' +
          '</div>' +
          '<a class="mz-btn mz-btn--outline" style="margin-top:12px;" href="/billing">Manage billing</a>' +
        '</div>' +
      '</div>' +
      '<div class="mz-saas-card" style="margin-bottom:18px;">' +
        '<div class="mz-card__title">Team</div>' +
        '<div class="mz-settings-table"><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Member</th><th>Email</th><th>Role</th><th></th></tr></thead><tbody>' + membersHTML + '</tbody></table></div></div>' +
        (canManage ? '<div class="mz-settings-mobile">' + membersMobile + '</div>' : '') +
        (canManage ? '<form class="mz-stack" data-settings-form="member" style="margin-top:16px;">' +
          '<div class="mz-form-2col">' +
            '<label class="mz-field"><span class="mz-label">Name</span><input class="mz-input" name="name" placeholder="Staff name" required></label>' +
            '<label class="mz-field"><span class="mz-label">Email</span><input class="mz-input" name="email" type="email" placeholder="staff@business.co.ke" required></label>' +
          '</div>' +
          '<div class="mz-form-2col">' +
            '<label class="mz-field"><span class="mz-label">Phone</span><input class="mz-input" name="phone" placeholder="0712 345 678" required></label>' +
            '<label class="mz-field"><span class="mz-label">Role</span><select class="mz-input" name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></label>' +
          '</div>' +
          '<label class="mz-field"><span class="mz-label">Temporary password (optional)</span><input class="mz-input" name="password" type="text" placeholder="Leave blank to generate"></label>' +
          '<button class="mz-btn" type="submit">Add team member</button>' +
        '</form>' : '<div class="mz-plan-hint">Only owners and admins can manage the team.</div>') +
      '</div>' +
      '<div class="mz-saas-card">' +
        '<div class="mz-card__title" data-api-keys-card-title>API keys</div>' +
        '<div class="mz-settings-table"><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Name</th><th>Key</th><th>Scopes</th><th>Created</th><th>Status</th><th></th></tr></thead><tbody>' + keysHTML + '</tbody></table></div></div>' +
        '<div class="mz-settings-mobile">' + (keysMobile || '<div class="mz-hint">No API keys yet.</div>') + '</div>' +
        (canManage ? '<form class="mz-stack" data-settings-form="apikey" style="margin-top:16px;">' +
          '<div class="mz-form-2col" style="align-items:end;">' +
            '<label class="mz-field"><span class="mz-label">Key name</span><input class="mz-input" name="name" placeholder="e.g. Checkout integration" required></label>' +
            '<button class="mz-btn" type="submit">Create API key</button>' +
          '</div>' +
          '<div style="display:flex;gap:16px;font-size:12px;"><label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="scope" value="read" checked> Read (GET)</label>' +
          '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="scope" value="write" checked> Write (POST)</label></div>' +
          '<div class="mz-plan-hint">Use with the public API: <code>POST /api/v1/parcels.php</code> and <code>GET /api/v1/track.php?q=…</code> with the <code>X-API-Key</code> header. <a href="/docs/API_V1.md" target="_blank" rel="noopener">API v1 docs</a> · <a href="/docs/openapi/v1.yaml" target="_blank" rel="noopener">OpenAPI</a></div>' +
        '</form>' : '') +
      '</div>' + hooksCard;

    var businessForm = host.querySelector('[data-settings-form="business"]');
    if (businessForm) businessForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('settings.php', { method: 'POST', body: {
        action: 'update_business', name: formValue(businessForm, 'name'),
        email: formValue(businessForm, 'email'), phone: formValue(businessForm, 'phone')
      } }).then(function (res) { toast(res.message); loadSettings(); })
        .catch(function (err) { toast(err.message, 'error'); });
    });

    var memberForm = host.querySelector('[data-settings-form="member"]');
    if (memberForm) memberForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('settings.php', { method: 'POST', body: {
        action: 'add_member', name: formValue(memberForm, 'name'), email: formValue(memberForm, 'email'),
        phone: formValue(memberForm, 'phone'), role: formValue(memberForm, 'role'),
        password: formValue(memberForm, 'password')
      } }).then(function (res) {
        teamCredential = { email: formValue(memberForm, 'email'), password: res.temporary_password || null };
        toast('Member added.');
        loadSettings();
      }).catch(function (err) { toast(err.message, 'error'); });
    });

    var keyForm = host.querySelector('[data-settings-form="apikey"]');
    if (keyForm) keyForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var scopes = [];
      keyForm.querySelectorAll('[name="scope"]:checked').forEach(function (cb) { scopes.push(cb.value); });
      api('settings.php', { method: 'POST', body: { action: 'create_api_key', name: formValue(keyForm, 'name'), scopes: scopes } })
        .then(function (res) {
          pendingApiKey = res.key;
          toast('API key created — copy it from the banner.');
          loadSettings();
        }).catch(function (err) { toast(err.message, 'error'); });
    });

    host.querySelectorAll('[data-remove-member]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        api('settings.php', { method: 'POST', body: { action: 'remove_member', user_id: Number(btn.getAttribute('data-remove-member')) } })
          .then(function () { toast('Member removed.'); loadSettings(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    });
    var dismiss = host.querySelector('[data-dismiss-key]');
    if (dismiss) dismiss.addEventListener('click', function () { pendingApiKey = ''; loadSettings(); });
    host.querySelectorAll('[data-revoke-key]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        api('settings.php', { method: 'POST', body: { action: 'revoke_api_key', key_id: Number(btn.getAttribute('data-revoke-key')) } })
          .then(function () { toast('API key revoked.'); loadSettings(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    });
    var webhookForm = host.querySelector('[data-settings-form="webhook"]');
    if (webhookForm) webhookForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var events = [];
      webhookForm.querySelectorAll('[name="events"]:checked').forEach(function (cb) { events.push(cb.value); });
      var btn = webhookForm.querySelector('[type="submit"]');
      btn.disabled = true;
      api('settings.php', { method: 'POST', body: { action: 'create_webhook', url: formValue(webhookForm, 'url'), events: events } })
        .then(function (res) { pendingWebhookSecret = res.webhook_secret; toast(res.message || 'Webhook created.'); loadSettings(); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    host.querySelectorAll('[data-delete-webhook]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!window.confirm('Delete this webhook?')) return;
        api('settings.php', { method: 'POST', body: { action: 'delete_webhook', webhook_id: Number(btn.getAttribute('data-delete-webhook')) } })
          .then(function () { toast('Webhook deleted.'); loadSettings(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    });
    var dismissSecret = host.querySelector('[data-dismiss-webhook-secret]');
    if (dismissSecret) dismissSecret.addEventListener('click', function () { pendingWebhookSecret = null; loadSettings(); });
    var keysCard = host.querySelector('[data-api-keys-card-title]');
    if (keysCard && !canManage) {
      var keysCardWrap = keysCard.closest('.mz-saas-card');
      if (keysCardWrap) keysCardWrap.hidden = true;
    }
    appendTenantBranding(host, data, canManage);
    host.insertAdjacentHTML('beforeend', securityCards(data));
    bindSecurityCards(host);
    var credCopy = host.querySelector('[data-cred-copy]');
    if (credCopy) credCopy.addEventListener('click', function () {
      var text = teamCredential && teamCredential.password ? teamCredential.password : '';
      if (text && navigator.clipboard) navigator.clipboard.writeText(text);
      toast('Temporary password copied.');
    });
    var credDismiss = host.querySelector('[data-cred-dismiss]');
    if (credDismiss) credDismiss.addEventListener('click', function () {
      teamCredential = null;
      loadSettings();
    });
  }

  /* -------------------------------------------------------------------- admin */
  function initAdminPage() {
    if (!APP.user) { window.location.href = '/login?next=%2Fadmin'; return; }
    if (APP.user.role === 'freelancer') { window.location.href = '/freelancer'; return; }
    if (APP.user.role === 'customer') { window.location.href = '/customer'; return; }
    initAppNav();
    loadAdmin();
  }

  function loadAdmin() {
    var host = $('[data-app="admin"]');
    if (!host) return;
    api('admin.php').then(function (data) {
      renderAdmin(host, data);
    }).catch(function (err) {
      if (appRedirectOnAuth(err)) return;
      if (err.status === 403) { fillNote(host, 'Platform admin access required.', 'error'); return; }
      fillNote(host, esc(err.message), 'error');
    });
  }

  var AUDIT_PER_PAGE = 5;

  function renderAuditCard(card, rows, page) {
    var total = rows.length;
    var pageCount = Math.max(1, Math.ceil(total / AUDIT_PER_PAGE));
    var current = Math.min(Math.max(1, page), pageCount);
    var start = (current - 1) * AUDIT_PER_PAGE;
    var visible = rows.slice(start, start + AUDIT_PER_PAGE);

    var items = visible.length
      ? visible.map(function (a) {
          return '<li class="mz-audit-item"><span class="mz-mono">' + esc(a.action) + '</span> · ' +
            esc(a.tenant_name || 'platform') + ' · ' + esc(a.actor_name || 'system') +
            ' · <span style="color:var(--mz-muted-fg);">' + esc(fmtDate(a.created_at)) + '</span></li>';
        }).join('')
      : '<li class="mz-audit-item" style="color:var(--mz-muted-fg);">No activity yet.</li>';

    var from = total === 0 ? 0 : start + 1;
    var to = Math.min(start + AUDIT_PER_PAGE, total);

    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:6px;">' +
        '<div class="mz-card__title" style="margin:0;">Recent activity</div>' +
        '<span style="font-size:12px;color:var(--mz-muted-fg);">Showing ' + from + '–' + to + ' of ' + total + '</span>' +
      '</div>' +
      '<ul class="mz-audit">' + items + '</ul>' +
      '<div class="mz-pager">' +
        '<button type="button" class="mz-tab" data-audit-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>← Previous</button>' +
        '<span class="mz-pager__label">Page ' + current + ' of ' + pageCount + '</span>' +
        '<button type="button" class="mz-tab" data-audit-page="' + (current + 1) + '"' + (current >= pageCount ? ' disabled' : '') + '>Next →</button>' +
      '</div>';

    card.querySelectorAll('[data-audit-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        renderAuditCard(card, rows, Number(btn.getAttribute('data-audit-page')));
      });
    });
  }


  /* --------------------------------------------------------- admin analytics */
  var ADMIN_RANGE = 90;
  var adminAnalyticsSeq = 0;
  var adminCharts = [];
  var chartLibPromise = null;
  var CHART_COLORS = {
    hunter: '#3c5223', teal: '#0d9488', amber: '#d97706',
    blue: '#2563eb', sage: '#9bd4a8', rust: '#b45309',
    rose: '#be123c', slate: '#64748b'
  };

  function loadChartLib() {
    if (window.Chart) return Promise.resolve(window.Chart);
    if (chartLibPromise) return chartLibPromise;
    chartLibPromise = new Promise(function (resolve, reject) {
      var script = doc.createElement('script');
      script.src = '/assets/js/vendor/chart.umd.min.js';
      script.defer = true;
      script.onload = function () {
        if (window.Chart) resolve(window.Chart);
        else reject(new Error('Chart library did not initialise'));
      };
      script.onerror = function () { reject(new Error('Could not load chart library')); };
      doc.head.appendChild(script);
    });
    return chartLibPromise;
  }

  function sumValues(values) { return (values || []).reduce(function (a, b) { return a + Number(b || 0); }, 0); }
  function komma(n) { return Number(n || 0).toLocaleString('en-KE'); }
  function kesShort(n) {
    n = Number(n || 0);
    if (Math.abs(n) >= 1000000) return 'KES ' + (n / 1000000).toFixed(1) + 'M';
    if (Math.abs(n) >= 1000) return 'KES ' + (n / 1000).toFixed(0) + 'k';
    return 'KES ' + n;
  }
  function trendChip(value) {
    if (value === null || value === undefined) return '';
    var up = value > 0;
    var flat = value === 0;
    var cls = flat ? 'mz-trend--flat' : up ? 'mz-trend--up' : 'mz-trend--down';
    var arrow = flat ? '→' : up ? '▲' : '▼';
    return '<span class="mz-trend ' + cls + '">' + arrow + ' ' + Math.abs(value).toFixed(1) + '%</span>';
  }
  function kpiCard(label, value, foot, trend) {
    return '<div class="mz-kpi">' +
      '<div class="mz-kpi__label">' + label + '</div>' +
      '<div class="mz-kpi__value">' + value + '</div>' +
      '<div class="mz-kpi__foot">' + (trend !== undefined ? trendChip(trend) + ' ' : '') + foot + '</div>' +
    '</div>';
  }

  function analyticsChartCards() {
    var charts = [
      ['revenue', 'Revenue', 'Subscriptions + parcel platform fees'],
      ['volume', 'Parcel & errand volume', 'Registered parcels and booked errands'],
      ['revenue-mix', 'Revenue mix', 'Where the money came from'],
      ['status-mix', 'Parcel status mix', 'Operational pipeline'],
      ['carriers', 'Carrier share', 'Parcels by courier partner'],
      ['tenants', 'Top workspaces', 'Parcels per workspace']
    ];
    return charts.map(function (c) {
      return '<div class="mz-chart-card">' +
        '<div class="mz-chart-head"><div class="mz-chart-title">' + c[1] + '</div>' +
        '<div class="mz-chart-sub">' + c[2] + '</div></div>' +
        '<div class="mz-chart-canvas"><canvas data-chart="' + c[0] + '" role="img" aria-label="' + esc(c[1]) + '"></canvas></div>' +
      '</div>';
    }).join('');
  }

  function analyticsShell() {
    return '<div class="mz-row mz-analytics-head">' +
        '<div><div class="mz-cap">Business intelligence</div>' +
        '<div class="mz-section-title">Performance overview</div>' +
        '<div class="mz-hint">Revenue, adoption and operational mix from MySQL.</div></div>' +
        '<div class="mz-range-tabs" role="tablist" aria-label="Analytics range">' +
          '<button type="button" class="mz-tab" data-analytics-range="30" role="tab">30 days</button>' +
          '<button type="button" class="mz-tab" data-analytics-range="90" role="tab" aria-selected="true">90 days</button>' +
          '<button type="button" class="mz-tab" data-analytics-range="365" role="tab">12 months</button>' +
        '</div>' +
      '</div>' +
      '<div class="mz-analytics-kpis" data-analytics-kpis></div>' +
      '<div class="mz-chart-grid">' + analyticsChartCards() + '</div>' +
      '<div class="mz-analytics-foot" data-analytics-foot>Loading analytics…</div>';
  }

  function initAdminOverview(host) {
    var mount = host.querySelector('[data-admin-overview]');
    if (!mount) return;
    var days = Number(localStorage.getItem('mz_admin_overview_days') || 30);
    function pct(v) { return v === null || v === undefined ? '—' : Number(v).toFixed(1) + '%'; }
    function ageText(hours) {
      if (!hours) return '—';
      if (hours < 24) return hours + 'h';
      return Math.round(hours / 24) + 'd';
    }
    function kpi(label, value, meta) {
      return '<div class="mz-ov-kpi"><div class="mz-ov-kpi__label">' + label + '</div>' +
        '<div class="mz-ov-kpi__value">' + value + '</div><div class="mz-ov-kpi__meta">' + meta + '</div></div>';
    }
    function load() {
      api('admin-overview.php?days=' + days).then(function (data) {
        var p = data.promise || {};
        var m = data.money || {};
        var t = data.today || {};
        var e = data.experience || {};
        var g = data.growth || {};
        var r = data.runner || {};
        var pl = data.platform || {};
        var j = data.journey || {};
        var attention = (data.attention || []).filter(function (a) { return a.count > 0; });
        var tabs = [['all', 'All', attention.reduce(function (sum, a) { return sum + a.count; }, 0)]];
        [['payments', 'Payments'], ['delivery', 'Delivery'], ['support', 'Support'], ['trust', 'Trust']].forEach(function (pair) {
          var count = attention.filter(function (a) { return a.tab === pair[0]; }).reduce(function (sum, a) { return sum + a.count; }, 0);
          if (count > 0) tabs.push([pair[0], pair[1], count]);
        });
        var rows = attention.map(function (a) {
          var amount = a.amount_kes > 0 ? '<span class="mz-att__amount">' + money(a.amount_kes) + '</span>' : '';
          return '<div class="mz-att-row" data-attn-row="' + esc(a.tab) + '" data-alert-key="' + esc(a.key) + '">' +
            '<div><div class="mz-att__title">' + esc(a.label) + ' <span class="mz-badge ' + (a.severity === 'high' ? 'mz-badge--warn' : '') + '">' + esc(a.severity) + '</span></div>' +
              '<div class="mz-att__meta"><span>' + a.count + ' item' + (a.count === 1 ? '' : 's') + '</span><span>· oldest ' + ageText(a.age_hours) + '</span>' + (amount ? '<span>· ' + amount + '</span>' : '') +
              '<span>· ' + esc(a.owner) + ' · SLA ' + a.sla_hours + 'h</span></div></div>' +
            '<div class="mz-att__actions"><a class="mz-att__action" href="' + esc(a.href) + '">Review →</a>' +
              '<button type="button" class="mz-ov-tab" data-alert-ack="' + esc(a.key) + '">Ack</button>' +
              '<button type="button" class="mz-ov-tab" data-alert-escalate="' + esc(a.key) + '">Escalate</button></div></div>';
        }).join('') || '<div class="mz-hint">No customer-impacting alerts right now.</div>';
        var stages = (j.stages || []).map(function (stage, i) {
          var max = Math.max.apply(null, (j.stages || []).map(function (x) { return x.count; }).concat([1]));
          var rate = i === 0 || !(j.stages[0] || {}).count ? null : Math.round(1000 * stage.count / j.stages[0].count) / 10;
          return '<div class="mz-funnel__stage"><div class="mz-funnel__plot"><div class="mz-funnel__bar" style="height:' + Math.max(10, Math.round(110 * stage.count / max)) + 'px"></div></div>' +
            '<span class="mz-funnel__count">' + stage.count + '</span><span class="mz-funnel__label">' + esc(stage.label) + '</span>' +
            '<span class="mz-funnel__rate">' + (rate === null ? '' : rate + '%') + '</span></div>';
        }).join('');
        var journeyCard = '<div class="mz-ov-card" style="margin-bottom:14px;"><div class="mz-row"><div><h3>Customer journey</h3><p class="mz-ov-card__hint">Registered → dropped off → in transit → ready → paid → collected. Where the promise breaks.</p></div><span class="mz-badge">' + days + ' days</span></div>' +
          '<div class="mz-funnel">' + stages + '</div>' +
          '<p class="mz-ov-note">Drop-off ' + pct(j.drop_off_rate) + ' · collection ' + pct(j.collection_rate) + ' · unpaid ready ' + pct(j.unpaid_ready_rate) + ' · collection p50 ' + (j.collection_hours_p50 || 0) + 'h / p90 ' + (j.collection_hours_p90 || 0) + 'h. Stage timestamps come from scans; promise windows are captured on registration.</p></div>';
        var cohortRows = (data.cohorts || []).map(function (c) {
          return '<tr><td>' + esc(c.cohort) + '</td><td>' + c.senders + '</td><td>' + c.avg_parcels + '</td><td>' + money(Number(c.avg_platform_fees)) + '</td><td>' + c.repeat_senders + '</td></tr>';
        }).join('') || '<tr><td colspan="5" class="mz-hint">No cohort data yet.</td></tr>';
        var carrierRows = (data.carriers || []).slice(0, 5).map(function (c) {
          return '<tr><td>' + esc(c.carrier) + '</td><td>' + c.volume + '</td><td>' + Number(c.paid_pct).toFixed(1) + '%</td><td>' + (c.avg_hours ? Number(c.avg_hours).toFixed(1) + 'h' : '—') + '</td><td>' + c.claims + '</td></tr>';
        }).join('') || '<tr><td colspan="5" class="mz-hint">No carrier data yet.</td></tr>';
        var cityRows = (data.cities || []).slice(0, 5).map(function (c) {
          return '<tr><td>' + esc(c.from_city) + ' → ' + esc(c.to_city) + '</td><td>' + c.parcels + '</td><td>' + money(Number(c.gmv)) + '</td></tr>';
        }).join('') || '<tr><td colspan="3" class="mz-hint">No route data yet.</td></tr>';
        mount.innerHTML =
          '<div class="mz-overview">' +
            '<div class="mz-overview__head"><div><span class="mz-cap">Customer operations</span><h2>Customer promise overview</h2>' +
              '<p>What customers need from us today — before revenue and platform detail. Read-only aggregates from existing data.</p></div>' +
              '<div class="mz-overview__controls"><div class="mz-overview__range">' +
                [30, 90, 365].map(function (d) { return '<button type="button" data-ov-days="' + d + '" class="' + (days === d ? 'is-active' : '') + '">' + (d === 365 ? '12m' : d + 'd') + '</button>'; }).join('') +
              '</div><label class="mz-promise">Promise <input type="number" min="1" max="240" data-promise-hours value="' + Number((data.config || {}).promise_hours || 48) + '"> h <button type="button" class="mz-btn mz-btn--sm mz-btn--outline" data-promise-save>Save</button></label></div></div>' +
            '<div class="mz-ov-kpis">' +
              kpi('On-time delivery', pct(p.on_time_pct), (p.on_time_sample ? p.on_time_sample + ' collected with promise' : 'No promise sample yet')) +
              kpi('Fee collection', pct(p.fee_collection_pct), p.unpaid_ready_count + ' unpaid ready · ' + money(p.unpaid_ready_kes || 0)) +
              kpi('Claims resolved', pct(p.claims_resolved_pct), (p.claims_median_days || 0) + 'd median resolution') +
              kpi('Customer rating', (p.rating || 0) > 0 ? Number(p.rating).toFixed(1) + ' / 5' : '—', (p.rated_jobs || 0) + ' rated runner jobs · CSAT ' + ((e.csat || 0) > 0 ? Number(e.csat).toFixed(1) : 'n/a')) +
              kpi('Repeat senders', pct(p.repeat_sender_pct), (p.repeat_senders || 0) + ' of ' + (p.total_senders || 0) + ' senders in period') +
            '</div>' + journeyCard +
            '<div class="mz-ov-grid">' +
              '<div class="mz-ov-card"><div class="mz-row"><div><h3>Needs attention now</h3><p class="mz-ov-card__hint">SLA-sorted, routed to an owner. Every row opens an existing workflow.</p></div><span class="mz-badge mz-badge--warn">' + attention.length + ' groups</span></div>' +
                '<div class="mz-ov-tabs">' + tabs.map(function (tab, i) { return '<button type="button" class="mz-ov-tab' + (i === 0 ? ' is-active' : '') + '" data-ov-tab="' + tab[0] + '">' + tab[1] + ' <strong>' + tab[2] + '</strong></button>'; }).join('') + '</div>' +
                '<div data-attn-list>' + rows + '</div>' +
                '<p class="mz-ov-note">Routing: Finance/support, operations, trust &amp; safety, engineering. Severity high = customer-impacting. Escalation links open the existing sections — no new workflow IDs.</p></div>' +
              '<div class="mz-ov-rail">' +
                '<div class="mz-ov-card"><h3>Today</h3><p class="mz-ov-card__hint">Live operational pulse.</p><div class="mz-today">' +
                  '<div><b>' + t.registered + '</b><span>Registered</span></div><div><b>' + t.collected + '</b><span>Collected</span></div>' +
                  '<div><b>' + t.errands_active + '</b><span>Errands active</span></div><div><b>' + t.runners_online + '</b><span>Runners online</span></div>' +
                  '<div><b>' + t.new_receivers + '</b><span>New receivers</span></div><div><b>' + (r.completed_jobs || 0) + '</b><span>Runner jobs done</span></div>' +
                '</div></div>' +
                '<div class="mz-ov-card"><h3>Money</h3><p class="mz-ov-card__hint">Cash customers can feel.</p>' +
                  '<div class="mz-money-row"><span>Fees collected</span><b>' + money(m.fees_collected_kes || 0) + '</b></div>' +
                  '<div class="mz-money-row"><span>Outstanding at collection</span><b class="mz-att__amount">' + money(m.outstanding_kes || 0) + '</b></div>' +
                  '<div class="mz-money-row"><span>Runner payouts pending</span><b>' + money(m.payouts_pending_kes || 0) + '</b></div>' +
                  '<div class="mz-money-row"><span>Coupon + credit discounts</span><b>' + money(m.discounts_kes || 0) + '</b></div>' +
                  '<div class="mz-money-row"><span>MRR</span><b>' + money(m.mrr_kes || 0) + '</b></div></div>' +
                '<div class="mz-ov-card"><h3>Quick actions</h3><p class="mz-ov-card__hint">Most-used admin workflows.</p><div class="mz-quick">' +
                  '<a href="/admin#runnerjobs">Create runner job</a><a href="/admin#kyc">Review KYC</a><a href="/admin#messages">Contact inbox</a><a href="/admin#insurance">Insurance retry</a></div></div>' +
              '</div></div>' +
            '<div class="mz-ov-2col">' +
              '<div class="mz-ov-card"><h3>Carrier scorecard</h3><p class="mz-ov-card__hint">Top 5 carriers by volume — delivery performance and claims.</p><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Carrier</th><th>Volume</th><th>Paid</th><th>Avg hours</th><th>Claims</th></tr></thead><tbody>' + carrierRows + '</tbody></table></div></div>' +
              '<div class="mz-ov-card"><h3>City / route heatmap</h3><p class="mz-ov-card__hint">Top 5 corridors by volume, with GMV.</p><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Route</th><th>Parcels</th><th>GMV</th></tr></thead><tbody>' + cityRows + '</tbody></table></div></div>' +
            '</div>' +
            '<p class="mz-ov-note">Read-only overview · P0/P1/P2 metrics · Existing sections, URLs, hash IDs and workflow action IDs are unchanged. CSAT and promise data are additive.</p>' +
          '</div>';
        mount.querySelectorAll('[data-ov-days]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            days = Number(btn.getAttribute('data-ov-days')) || 30;
            try { localStorage.setItem('mz_admin_overview_days', String(days)); } catch (e) {}
            load();
          });
        });
        mount.querySelector('[data-promise-save]').addEventListener('click', function (ev) {
          var input = mount.querySelector('[data-promise-hours]');
          var hours = Number(input.value || 48);
          ev.currentTarget.disabled = true;
          api('admin.php', { method: 'POST', body: { action: 'save_promise_config', hours: hours } })
            .then(function () { toast('Promise window saved.'); load(); })
            .catch(function (err) { toast(err.message, 'error'); ev.currentTarget.disabled = false; });
        });
        function alertAction(key, action, btn) {
          btn.disabled = true;
          api('admin-alert-action.php', { method: 'POST', body: { alert_key: key, action: action } })
            .then(function (res) { toast(res.message || 'Alert updated.'); btn.textContent = action === 'escalate' ? 'Escalated' : 'Acked'; })
            .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        }
        mount.querySelectorAll('[data-alert-ack]').forEach(function (btn) {
          btn.addEventListener('click', function () { alertAction(btn.getAttribute('data-alert-ack'), 'ack', btn); });
        });
        mount.querySelectorAll('[data-alert-escalate]').forEach(function (btn) {
          btn.addEventListener('click', function () { alertAction(btn.getAttribute('data-alert-escalate'), 'escalate', btn); });
        });
        mount.querySelectorAll('[data-ov-tab]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var tab = btn.getAttribute('data-ov-tab');
            mount.querySelectorAll('[data-ov-tab]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            mount.querySelectorAll('[data-attn-row]').forEach(function (row) {
              row.hidden = tab !== 'all' && row.getAttribute('data-attn-row') !== tab;
            });
          });
        });
      }).catch(function () {
        mount.innerHTML = '';
      });
    }
    load();
  }

  function initAdminAnalytics(host) {
    var section = host.querySelector('[data-admin-analytics]');
    if (!section) return;
    section.innerHTML = analyticsShell();
    section.querySelectorAll('[data-analytics-range]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var range = Number(btn.getAttribute('data-analytics-range'));
        if (range === ADMIN_RANGE) return;
        ADMIN_RANGE = range;
        section.querySelectorAll('[data-analytics-range]').forEach(function (b) {
          var on = Number(b.getAttribute('data-analytics-range')) === range;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', String(on));
        });
        loadAdminAnalytics(section, range);
      });
    });
    section.querySelectorAll('[data-analytics-range]').forEach(function (b) {
      var on = Number(b.getAttribute('data-analytics-range')) === ADMIN_RANGE;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', String(on));
    });
    loadAdminAnalytics(section, ADMIN_RANGE);
  }

  function loadAdminAnalytics(section, range) {
    var seq = ++adminAnalyticsSeq;
    var foot = section.querySelector('[data-analytics-foot]');
    if (foot) foot.textContent = 'Loading ' + range + '-day analytics from MySQL…';
    section.classList.add('is-loading');
    api('admin-analytics.php?range=' + range).then(function (data) {
      if (seq !== adminAnalyticsSeq) return; // a newer range switch won
      renderAdminAnalytics(section, data);
    }).catch(function (err) {
      if (seq !== adminAnalyticsSeq) return;
      section.classList.remove('is-loading');
      if (foot) {
        foot.innerHTML = 'Could not load analytics: ' + esc(err.message) +
          ' <button type="button" class="mz-link" data-analytics-retry>Retry</button>';
        var retry = foot.querySelector('[data-analytics-retry]');
        if (retry) retry.addEventListener('click', function () { loadAdminAnalytics(section, range); });
      }
    });
  }

  function renderAdminAnalytics(section, data) {
    section.classList.remove('is-loading');
    var k = data.kpis;
    var kpis = section.querySelector('[data-analytics-kpis]');
    if (kpis) {
      kpis.innerHTML =
        kpiCard('MRR', money(k.mrr_kes), 'from ' + k.active_tenants + ' active workspace' + (k.active_tenants === 1 ? '' : 's')) +
        kpiCard('Revenue', money(k.revenue_kes), 'vs ' + money(k.prev_revenue_kes) + ' previous', k.revenue_growth_pct) +
        kpiCard('ARPA', money(k.arpa_kes), 'average per active workspace') +
        kpiCard('Trial → paid', k.conversion_pct.toFixed(1) + '%', k.tenants_total + ' workspaces total') +
        kpiCard('Parcels / workspace', k.parcels_per_tenant, k.parcels + ' parcels in period', k.parcels_growth_pct) +
        kpiCard('Parcel GMV', money(k.gmv_kes), 'avg ' + money(k.avg_parcel_value_kes) + ' per parcel', k.gmv_growth_pct);
    }
    var foot = section.querySelector('[data-analytics-foot]');
    if (foot) {
      foot.innerHTML = 'MySQL · ' + esc(APP.database) + ' · ' + esc(data.range.bucket) + ' buckets · ' +
        esc(data.range.start.slice(0, 10)) + ' → ' + esc(data.range.end.slice(0, 10)) +
        ' · ' + komma(k.parcels) + ' parcels · ' + komma(k.errands) + ' errands';
    }
    var grid = section.querySelector('.mz-chart-grid');
    if (grid) grid.innerHTML = analyticsChartCards();
    drawAdminCharts(section, data);
  }

  function destroyAdminCharts() {
    adminCharts.forEach(function (chart) {
      try { chart.destroy(); } catch (e) { /* already gone */ }
    });
    adminCharts = [];
  }

  function drawAdminCharts(section, data) {
    var canvases = Array.prototype.slice.call(section.querySelectorAll('canvas[data-chart]'));
    destroyAdminCharts();
    loadChartLib().then(function (Chart) {
      Chart.defaults.font.family = '"Outfit", "Segoe UI", system-ui, sans-serif';
      Chart.defaults.color = '#4a6330';
      Chart.defaults.borderColor = 'rgba(60, 82, 35, 0.08)';
      Chart.defaults.animation = false;

      var labels = data.series.labels;
      var tooltipKes = {
        callbacks: {
          label: function (ctx) {
            var value = ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed;
            return ' ' + ctx.dataset.label + ': ' + money(value);
          }
        }
      };
      var tooltipCount = {
        callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + komma(ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed); } }
      };
      var legend = { position: 'bottom', labels: { boxWidth: 9, boxHeight: 9, usePointStyle: true, pointStyle: 'circle', font: { size: 11 } } };
      var grid = { color: 'rgba(60, 82, 35, 0.07)', drawTicks: false };
      var byKey = {};
      canvases.forEach(function (canvas) { byKey[canvas.getAttribute('data-chart')] = canvas; });

      /* 1. Revenue — stacked bar */
      if (byKey.revenue) {
        var total = sumValues(data.series.subscriptions) + sumValues(data.series.platform_fees);
        if (total === 0) { emptyChart(byKey.revenue, 'No revenue collected in this period.'); }
        else {
          adminCharts.push(new Chart(byKey.revenue, {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [
                { label: 'Subscriptions', data: data.series.subscriptions, backgroundColor: CHART_COLORS.hunter, borderRadius: 3, stack: 'rev' },
                { label: 'Platform fees', data: data.series.platform_fees, backgroundColor: CHART_COLORS.teal, borderRadius: 3, stack: 'rev' }
              ]
            },
            options: {
              responsive: true, maintainAspectRatio: false,
              plugins: { legend: legend, tooltip: tooltipKes },
              scales: { x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { size: 10 } } },
                        y: { stacked: true, beginAtZero: true, grid: grid, ticks: { font: { size: 10 }, callback: function (v) { return kesShort(v); } } } }
            }
          }));
        }
      }

      /* 2. Volume — parcels + errands bars */
      if (byKey.volume) {
        if (sumValues(data.series.parcels) + sumValues(data.series.errands) === 0) {
          emptyChart(byKey.volume, 'No parcels or errands in this period.');
        } else {
          adminCharts.push(new Chart(byKey.volume, {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [
                { label: 'Parcels', data: data.series.parcels, backgroundColor: CHART_COLORS.hunter, borderRadius: 3 },
                { label: 'Errands', data: data.series.errands, backgroundColor: CHART_COLORS.amber, borderRadius: 3 }
              ]
            },
            options: {
              responsive: true, maintainAspectRatio: false,
              plugins: { legend: legend, tooltip: tooltipCount },
              scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { size: 10 } } },
                        y: { beginAtZero: true, grid: grid, ticks: { precision: 0, font: { size: 10 } } } }
            }
          }));
        }
      }

      /* 3. Revenue mix — doughnut */
      if (byKey['revenue-mix']) {
        var mix = data.distributions.revenue_mix || [];
        if (sumValues(mix.map(function (x) { return x.amount; })) === 0) {
          emptyChart(byKey['revenue-mix'], 'No revenue mix to show yet.');
        } else {
          adminCharts.push(new Chart(byKey['revenue-mix'], {
            type: 'doughnut',
            data: {
              labels: mix.map(function (x) { return x.label; }),
              datasets: [{ data: mix.map(function (x) { return x.amount; }),
                backgroundColor: [CHART_COLORS.hunter, CHART_COLORS.teal, CHART_COLORS.amber],
                borderColor: '#f7fff9', borderWidth: 3, hoverOffset: 4 }]
            },
            options: {
              responsive: true, maintainAspectRatio: false, cutout: '60%',
              plugins: {
                legend: { position: 'bottom', labels: legend.labels },
                tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + money(ctx.parsed); } } }
              }
            }
          }));
        }
      }

      /* 4. Status mix — doughnut */
      if (byKey['status-mix']) {
        var statuses = data.distributions.statuses || [];
        if (!statuses.length) {
          emptyChart(byKey['status-mix'], 'No parcels in this period.');
        } else {
          adminCharts.push(new Chart(byKey['status-mix'], {
            type: 'doughnut',
            data: {
              labels: statuses.map(function (x) { return (STATUS_META[x.status] && STATUS_META[x.status].label) || x.status; }),
              datasets: [{ data: statuses.map(function (x) { return x.count; }),
                backgroundColor: [CHART_COLORS.teal, CHART_COLORS.hunter, CHART_COLORS.amber, CHART_COLORS.blue, CHART_COLORS.sage, CHART_COLORS.rust, CHART_COLORS.rose],
                borderColor: '#f7fff9', borderWidth: 3, hoverOffset: 4 }]
            },
            options: {
              responsive: true, maintainAspectRatio: false, cutout: '60%',
              plugins: {
                legend: { position: 'bottom', labels: legend.labels },
                tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + komma(ctx.parsed); } } }
              }
            }
          }));
        }
      }

      /* 5. Carrier share — horizontal bar (top 6 + other) */
      if (byKey.carriers) {
        var carriers = (data.distributions.carriers || []).slice();
        var rest = carriers.slice(6).reduce(function (a, c) { return a + c.parcels; }, 0);
        carriers = carriers.slice(0, 6);
        if (rest > 0) carriers.push({ name: 'Other', parcels: rest });
        if (!carriers.length) {
          emptyChart(byKey.carriers, 'No carrier activity in this period.');
        } else {
          adminCharts.push(new Chart(byKey.carriers, {
            type: 'bar',
            data: {
              labels: carriers.map(function (c) { return c.name; }),
              datasets: [{ label: 'Parcels', data: carriers.map(function (c) { return c.parcels; }),
                backgroundColor: CHART_COLORS.teal, borderRadius: 3 }]
            },
            options: {
              indexAxis: 'y', responsive: true, maintainAspectRatio: false,
              plugins: { legend: { display: false }, tooltip: tooltipCount },
              scales: { x: { beginAtZero: true, grid: grid, ticks: { precision: 0, font: { size: 10 } } },
                        y: { grid: { display: false }, ticks: { font: { size: 10 } } } }
            }
          }));
        }
      }

      /* 6. Top workspaces — horizontal bar */
      if (byKey.tenants) {
        var top = data.top_tenants || [];
        if (!top.length) {
          emptyChart(byKey.tenants, 'No workspace parcel activity in this period.');
        } else {
          adminCharts.push(new Chart(byKey.tenants, {
            type: 'bar',
            data: {
              labels: top.map(function (t) { return t.name; }),
              datasets: [{ label: 'Parcels', data: top.map(function (t) { return t.parcels; }),
                backgroundColor: CHART_COLORS.hunter, borderRadius: 3 }]
            },
            options: {
              indexAxis: 'y', responsive: true, maintainAspectRatio: false,
              plugins: { legend: { display: false }, tooltip: tooltipCount },
              scales: { x: { beginAtZero: true, grid: grid, ticks: { precision: 0, font: { size: 10 } } },
                        y: { grid: { display: false }, ticks: { font: { size: 10 } } } }
            }
          }));
        }
      }
    }).catch(function () {
      canvases.forEach(function (canvas) {
        emptyChart(canvas, 'Charts could not load. KPI totals above are still live.');
      });
    });
  }

  function emptyChart(canvas, message) {
    var wrap = canvas.parentElement;
    if (!wrap) return;
    wrap.innerHTML = '<div class="mz-chart-empty">' + esc(message) + '</div>';
  }

  var ADMIN_TABLE_PER_PAGE = 5;
  var adminTablePages = { workspaces: 1, invoices: 1, transactions: 1, users: 1 };
  var adminUserCredential = null;

  /**
   * Shared 5-per-page table with Previous/Next paging.
   * config: { title, columns, rows[], empty, page, perPage, onPage }
   */
  function renderPagedTable(card, config) {
    if (!card) return;
    var perPage = config.perPage || ADMIN_TABLE_PER_PAGE;
    var total = config.rows.length;
    var pageCount = Math.max(1, Math.ceil(total / perPage));
    var page = Math.min(Math.max(1, config.page || 1), pageCount);
    if (config.onPage) config.onPage(page);

    var start = (page - 1) * perPage;
    var visible = config.rows.slice(start, start + perPage);
    var from = total === 0 ? 0 : start + 1;
    var to = Math.min(start + perPage, total);
    var head = config.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('');
    var body = visible.length
      ? visible.join('')
      : '<tr><td colspan="' + config.columns.length + '" style="color:var(--mz-muted-fg);">' + esc(config.empty || 'No records yet.') + '</td></tr>';

    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:10px;">' +
        '<div class="mz-card__title" style="margin:0;">' + esc(config.title) + '</div>' +
        '<span style="font-size:12px;color:var(--mz-muted-fg);">Showing ' + from + '–' + to + ' of ' + total + '</span>' +
      '</div>' +
      '<div class="mz-table-wrap" role="region" aria-label="' + esc(config.title) + ' table" tabindex="0">' +
        '<table class="mz-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>' +
      '</div>' +
      '<div class="mz-pager">' +
        '<button type="button" class="mz-tab" data-table-page="' + (page - 1) + '" aria-label="Previous page of ' + esc(config.title) + '"' + (page <= 1 ? ' disabled' : '') + '>← Previous</button>' +
        '<span class="mz-pager__label">Page ' + page + ' of ' + pageCount + '</span>' +
        '<button type="button" class="mz-tab" data-table-page="' + (page + 1) + '" aria-label="Next page of ' + esc(config.title) + '"' + (page >= pageCount ? ' disabled' : '') + '>Next →</button>' +
      '</div>';

    card.querySelectorAll('[data-table-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        renderPagedTable(card, {
          title: config.title,
          columns: config.columns,
          rows: config.rows,
          empty: config.empty,
          perPage: perPage,
          page: Number(btn.getAttribute('data-table-page')),
          onPage: config.onPage
        });
      });
    });
  }

  /** Admin action handlers are delegated once so they survive table paging. */
  function bindAdminActions(host) {
    if (host.getAttribute('data-admin-actions') === '1') return;
    host.setAttribute('data-admin-actions', '1');
    host.addEventListener('click', function (ev) {
      var target = ev.target;
      var manageBtn = target.closest('[data-channel-manage]');
      if (manageBtn) {
        var chan = channelByCode(manageBtn.getAttribute('data-channel-manage'));
        if (chan) openChannelDialog(chan);
        return;
      }
      if (target.closest('[data-channel-add]')) {
        openChannelDialog(null);
        return;
      }
      var userBtn = target.closest('[data-user-action]');
      if (userBtn) {
        var uid = Number(userBtn.getAttribute('data-user'));
        var uact = userBtn.getAttribute('data-user-action');
        if (uact === 'delete' && !window.confirm('Delete this user? They will lose access immediately.')) return;
        var body;
        if (uact === 'suspend') body = { action: 'user_status', user_id: uid, status: 'suspended' };
        else if (uact === 'activate') body = { action: 'user_status', user_id: uid, status: 'active' };
        else if (uact === 'reset') body = { action: 'reset_user_credentials', user_id: uid };
        else body = { action: 'delete_user', user_id: uid };
        userBtn.disabled = true;
        api('admin.php', { method: 'POST', body: body }).then(function (res) {
          if (res.credential) adminUserCredential = res.credential;
          toast(uact === 'delete' ? 'User deleted.' : 'User updated.');
          loadAdmin();
        }).catch(function (err) { toast(err.message, 'error'); userBtn.disabled = false; });
        return;
      }
      var revokeBtn = target.closest('[data-session-revoke]');
      if (revokeBtn) {
        revokeBtn.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'revoke_session', session_id: revokeBtn.getAttribute('data-session-revoke') } })
          .then(function () { toast('Session revoked.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); revokeBtn.disabled = false; });
        return;
      }
      var planBtn = target.closest('[data-set-plan]');
      if (planBtn) {
        var id = planBtn.getAttribute('data-set-plan');
        var select = host.querySelector('[data-plan-select="' + id + '"]');
        if (!select) return;
        api('admin.php', { method: 'POST', body: { action: 'change_plan', tenant_id: Number(id), plan_code: select.value } })
          .then(function () { toast('Plan updated.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
        return;
      }
      var tenantBtn = target.closest('[data-tenant-action]');
      if (tenantBtn) {
        api('admin.php', {
          method: 'POST',
          body: { action: tenantBtn.getAttribute('data-tenant-action'), tenant_id: Number(tenantBtn.getAttribute('data-tenant')) }
        }).then(function () { toast('Workspace updated.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
        return;
      }
      var heroCopySave = target.closest('[data-hero-copy-save]');
      if (heroCopySave) {
        var heroTitleInput = $('[data-hero-copy-title]');
        var heroSubtitleInput = $('[data-hero-copy-subtitle]');
        heroCopySave.disabled = true;
        api('admin.php', { method: 'POST', body: {
          action: 'save_hero_copy',
          hero_title: heroTitleInput ? heroTitleInput.value.trim() : '',
          hero_subtitle: heroSubtitleInput ? heroSubtitleInput.value.trim() : ''
        }}).then(function () {
          toast('Landing page copy saved.');
          loadAdmin();
        }).catch(function (err) {
          toast(err.message, 'error');
          heroCopySave.disabled = false;
        });
        return;
      }
      if (target.closest('[data-hero-copy-reset]')) {
        api('admin.php', { method: 'POST', body: {
          action: 'save_hero_copy', hero_title: '', hero_subtitle: ''
        }}).then(function () {
          toast('Landing page copy restored to defaults.');
          loadAdmin();
        }).catch(function (err) { toast(err.message, 'error'); });
        return;
      }
      var brandBtn = target.closest('[data-brand-upload]');
      if (brandBtn) {
        uploadBrandAsset(brandBtn.getAttribute('data-brand-upload'));
        return;
      }
      if (target.closest('[data-brand-reset]')) {
        if (!window.confirm('Restore the bundled logo and favicon?')) return;
        api('admin.php', { method: 'POST', body: { action: 'branding_reset' } })
          .then(function () { toast('Brand assets restored.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
        return;
      }
      if (target.closest('[data-faq-add]')) {
        openFaqDialog(null);
        return;
      }
      var faqEditBtn = target.closest('[data-faq-edit]');
      if (faqEditBtn) {
        var faq = faqById(faqEditBtn.getAttribute('data-faq-edit'));
        if (faq) openFaqDialog(faq);
        return;
      }
      var faqDeleteBtn = target.closest('[data-faq-delete]');
      if (faqDeleteBtn) {
        if (!window.confirm('Delete this FAQ? It disappears from the website immediately.')) return;
        faqDeleteBtn.disabled = true;
        api('admin.php', { method: 'POST', body: {
          action: 'faq_delete', faq_id: Number(faqDeleteBtn.getAttribute('data-faq-delete'))
        }}).then(function () { toast('FAQ deleted.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); faqDeleteBtn.disabled = false; });
        return;
      }
      var contactBtn = target.closest('[data-contact-open]');
      if (contactBtn) {
        openContactMessage(contactBtn.getAttribute('data-contact-open'));
        return;
      }
      var claimDetailBtn = target.closest('[data-claim-detail]');
      if (claimDetailBtn) { openClaimDetail(Number(claimDetailBtn.getAttribute('data-claim-detail'))); return; }
      var claimReverseBtn = target.closest('[data-claim-reverse]');
      if (claimReverseBtn) {
        var rReason = window.prompt('Reason for reversing this payout (required):', '');
        if (!rReason || rReason.trim().length < 5) { toast('A reversal reason is required.', 'error'); return; }
        api('admin.php', { method: 'POST', body: { action: 'reverse_payout', claim_id: Number(claimReverseBtn.getAttribute('data-claim-reverse')), reason: rReason } })
          .then(function (res) { toast('Payout reversed: ' + res.reversal_ref); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
        return;
      }
      var claimPayoutProvider = target.closest('[data-claim-payout-provider]');
      if (claimPayoutProvider) {
        var providerAmount = window.prompt('Payout amount (KES):', '');
        if (providerAmount === null) return;
        if (!Number(providerAmount) || Number(providerAmount) <= 0) { toast('Enter a valid payout amount.', 'error'); return; }
        var providerNote = window.prompt('Payout note (optional):', '') || '';
        claimPayoutProvider.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'initiate_claim_payout', claim_id: Number(claimPayoutProvider.getAttribute('data-claim-payout-provider')), amount_kes: Number(providerAmount), note: providerNote } })
          .then(function (res) { toast(res.message || 'Payout processed.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); claimPayoutProvider.disabled = false; });
        return;
      }
      var claimRecoveryRequest = target.closest('[data-claim-recovery-request]');
      if (claimRecoveryRequest) {
        var recoveryAmount = window.prompt('Recovery amount KES (optional):', '') || '';
        var recoveryNote = window.prompt('Recovery note (optional):', '') || '';
        claimRecoveryRequest.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'request_claim_recovery', claim_id: Number(claimRecoveryRequest.getAttribute('data-claim-recovery-request')), amount_kes: recoveryAmount, note: recoveryNote } })
          .then(function (res) { toast(res.message || 'Recovery requested.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); claimRecoveryRequest.disabled = false; });
        return;
      }
      var claimRecoveryBtn = target.closest('[data-claim-recovery]');
      if (claimRecoveryBtn) {
        var recStatus = window.prompt('Recovery status (requested/recovered/rejected):', 'requested');
        if (!recStatus) return;
        var recRef = window.prompt('Carrier/insurer reference (optional):', '') || '';
        var recAmount = window.prompt('Recovered amount KES (optional):', '') || '';
        var recNote = window.prompt('Recovery note (optional):', '') || '';
        api('admin.php', { method: 'POST', body: { action: 'record_recovery', claim_id: Number(claimRecoveryBtn.getAttribute('data-claim-recovery')), recovery_status: recStatus, recovery_ref: recRef, recovery_amount_kes: recAmount, recovery_note: recNote } })
          .then(function () { toast('Recovery recorded.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
        return;
      }
      var claimBtn = target.closest('[data-claim-action]');
      if (claimBtn) {
        var claimStatus = claimBtn.getAttribute('data-claim-action');
        var note = null;
        var payout = null;
        if (claimStatus === 'rejected' || claimStatus === 'approved' || claimStatus === 'paid') {
          note = window.prompt('Decision reason (required, at least 5 characters):', '');
          if (note === null) return;
          if (note.trim().length < 5) { toast('A decision reason is required.', 'error'); return; }
        }
        if (claimStatus === 'paid') {
          var amount = window.prompt('Payout amount (KES):', '');
          if (amount === null) return;
          payout = Number(amount);
          if (!payout || payout <= 0) { toast('Enter a valid payout amount.', 'error'); return; }
        }
        claimBtn.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'update_claim', claim_id: Number(claimBtn.getAttribute('data-claim')), status: claimStatus, resolution_note: note, payout_kes: payout } })
          .then(function (res) { toast(res.message || ('Claim updated' + (res.payout_ref ? ': ' + res.payout_ref : '.'))); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); claimBtn.disabled = false; });
        return;
      }
      var refundBtn = target.closest('[data-admin-refund]');
      if (refundBtn) {
        if (!window.confirm('Record a refund for this paid invoice? This writes a refund transaction.')) return;
        refundBtn.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'refund_invoice', invoice_id: Number(refundBtn.getAttribute('data-admin-refund')) } })
          .then(function (res) { toast('Refund recorded: ' + res.refund_ref); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); refundBtn.disabled = false; });
        return;
      }
      var invoiceBtn = target.closest('[data-invoice-action]');
      if (invoiceBtn) {
        api('admin.php', {
          method: 'POST',
          body: { action: invoiceBtn.getAttribute('data-invoice-action'), invoice_id: Number(invoiceBtn.getAttribute('data-invoice')) }
        }).then(function () { toast('Invoice updated.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
      }
    });
  }

  function renderAdminSessions(card, sessions) {
    if (!card) return;
    var rows = sessions.rows || [];
    var trs = rows.map(function (s) {
      return '<tr><td class="mz-mono" title="' + esc(s.id) + '">' + esc(s.id_short) + '</td>' +
        '<td>' + esc(s.user_name || 'Anonymous') + '<div style="font-size:10px;color:var(--mz-muted-fg);">' + esc(s.user_role || '') + '</div></td>' +
        '<td>' + esc(s.tenant_name || '—') + '</td>' +
        '<td class="mz-mono">' + esc(s.ip || '') + '</td>' +
        '<td style="white-space:nowrap;">' + esc(fmtDate(s.last_seen_at)) + '</td>' +
        '<td>' + (s.current
          ? '<span class="mz-badge mz-badge--ok">This device</span>'
          : '<button type="button" class="mz-link" data-session-revoke="' + esc(s.id) + '">Revoke</button>') + '</td></tr>';
    });
    renderPagedTable(card, {
      title: 'Sessions (' + (sessions.active || 0) + ' active)',
      columns: ['Session', 'User', 'Workspace', 'IP', 'Last seen', ''],
      rows: trs,
      empty: 'No sessions recorded.',
      perPage: 10,
      page: adminTablePages.sessions || 1,
      onPage: function (page) { adminTablePages.sessions = page; }
    });
  }

  function renderAdminUsers(card, data) {
    if (!card) return;
    var users = (data.users && data.users.rows) || [];
    var activeCount = (data.users && data.users.active) || 0;
    var tenants = data.tenants || [];
    var note = adminUserCredential
      ? '<div class="mz-alert" style="margin-bottom:12px;">Credential for the user (' +
          esc(adminUserCredential.label) + '): <code>' + esc(adminUserCredential.value) + '</code> — share it securely; it will not be shown again. ' +
          '<button type="button" class="mz-link" data-user-credential-dismiss>Dismiss</button></div>'
      : '';
    var roleLabel = { user: 'Seller/Merchant', platform_admin: 'Admin', freelancer: 'Freelancer', customer: 'Customer' };
    var rows = users.map(function (u) {
      var role = roleLabel[u.role] || u.role;
      if (u.member_role) role += ' · ' + u.member_role;
      var statusBadge = u.status === 'active' ? 'mz-badge--ok' : u.status === 'suspended' ? 'mz-badge--warn' : '';
      return '<tr><td><div style="font-weight:600;">' + esc(u.name) + '</div><div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(u.phone || '') + (u.email ? ' · ' + esc(u.email) : '') + '</div></td>' +
        '<td>' + esc(role) + '</td>' +
        '<td>' + esc(u.tenant_name || '—') + '</td>' +
        '<td><span class="mz-badge ' + statusBadge + '">' + esc(u.status) + '</span></td>' +
        '<td style="white-space:nowrap;">' + (u.last_login_at ? esc(fmtDate(u.last_login_at)) : '—') + '</td>' +
        '<td style="white-space:nowrap;">' +
          (u.role === 'platform_admin' ? '<span class="mz-hint">protected</span>' :
            (u.status === 'suspended'
              ? '<button type="button" class="mz-link" data-user-action="activate" data-user="' + u.id + '">Activate</button>'
              : '<button type="button" class="mz-link" data-user-action="suspend" data-user="' + u.id + '">Suspend</button>') +
            ' · <button type="button" class="mz-link" data-user-action="reset" data-user="' + u.id + '">Reset</button>' +
            ' · <button type="button" class="mz-link" data-user-action="delete" data-user="' + u.id + '">Delete</button>') +
        '</td></tr>';
    });

    card.innerHTML = note +
      '<div class="mz-row" style="margin-bottom:10px;">' +
        '<div class="mz-card__title" style="margin:0;">Users</div>' +
        '<div style="display:flex;align-items:center;gap:10px;">' +
          '<span style="font-size:12px;color:var(--mz-muted-fg);">' + activeCount + ' active</span>' +
          '<button type="button" class="mz-tab" data-user-create-toggle>Create user</button>' +
        '</div>' +
      '</div>' +
      '<div data-user-create hidden style="border:1px dashed var(--mz-border);border-radius:14px;padding:14px;margin-bottom:14px;">' +
        '<div class="mz-card__title" style="margin:0 0 10px;">New user</div>' +
        '<form data-user-create-form style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">' +
          '<label class="mz-field"><span class="mz-label">Full name</span><input class="mz-input" name="name" required></label>' +
          '<label class="mz-field"><span class="mz-label">Phone</span><input class="mz-input" name="phone" type="tel" required></label>' +
          '<label class="mz-field"><span class="mz-label">Email (optional)</span><input class="mz-input" name="email" type="email"></label>' +
          '<label class="mz-field"><span class="mz-label">Role</span><select class="mz-input" name="role">' +
            '<option value="employee">Employee</option><option value="merchant">Seller / Merchant</option>' +
            '<option value="freelancer">Freelancer</option><option value="customer">Customer</option></select></label>' +
          '<label class="mz-field"><span class="mz-label">Workspace (merchant/employee)</span><select class="mz-input" name="tenant_id"><option value="">—</option>' +
            tenants.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('') +
          '</select></label>' +
          '<div style="display:flex;align-items:flex-end;gap:8px;"><button type="submit" class="mz-btn">Create user</button>' +
          '<button type="button" class="mz-btn mz-btn--outline" data-user-create-cancel>Cancel</button></div>' +
        '</form>' +
      '</div>' +
      '<div data-users-table></div>';

    var form = card.querySelector('[data-user-create-form]');
    var panel = card.querySelector('[data-user-create]');
    var toggle = card.querySelector('[data-user-create-toggle]');
    if (toggle) toggle.addEventListener('click', function () { panel.hidden = !panel.hidden; });
    var cancel = card.querySelector('[data-user-create-cancel]');
    if (cancel) cancel.addEventListener('click', function () { panel.hidden = true; });
    var dismiss = card.querySelector('[data-user-credential-dismiss]');
    if (dismiss) dismiss.addEventListener('click', function () { adminUserCredential = null; card.querySelector('.mz-alert').remove(); });
    if (form) form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('admin.php', { method: 'POST', body: {
        action: 'create_user',
        name: formValue(form, 'name'),
        phone: formValue(form, 'phone'),
        email: formValue(form, 'email'),
        role: formValue(form, 'role'),
        tenant_id: Number(formValue(form, 'tenant_id') || 0)
      }}).then(function (res) {
        adminUserCredential = res.credential || null;
        toast('User created.');
        loadAdmin();
      }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });

    renderPagedTable(card.querySelector('[data-users-table]'), {
      title: 'All users',
      columns: ['User', 'Role', 'Workspace', 'Status', 'Last login', 'Actions'],
      rows: rows,
      empty: 'No users yet.',
      perPage: 5,
      page: adminTablePages.users || 1,
      onPage: function (page) { adminTablePages.users = page; }
    });
  }

  var adminChannels = [];
  var adminCommsTemplates = [];
  var pendingPointToken = '';
  var adminChannelProviders = {};
  var adminSenderHints = {};

  var CHANNEL_PROVIDER_GROUPS = [
    { key: 'sms', label: 'SMS providers' },
    { key: 'whatsapp', label: 'WhatsApp providers' },
    { key: 'email', label: 'Email providers' },
    { key: 'generic', label: 'Generic / in-platform' }
  ];

  function channelProvider(key) {
    return key && adminChannelProviders[key] ? adminChannelProviders[key] : null;
  }

  function channelProviderLabel(key) {
    var provider = channelProvider(key);
    return provider ? provider.label : (key || 'log');
  }

  function channelProviderOptions(current) {
    var keys = Object.keys(adminChannelProviders);
    if (!keys.length) {
      return '<option value="' + esc(current || 'log') + '">' + esc(current || 'log') + '</option>';
    }
    var html = '';
    if (current && !adminChannelProviders[current]) {
      html += '<option value="' + esc(current) + '" selected>' + esc(current) + ' (not supported)</option>';
    }
    CHANNEL_PROVIDER_GROUPS.forEach(function (group) {
      var groupKeys = keys.filter(function (key) {
        return adminChannelProviders[key].group === group.key;
      });
      if (!groupKeys.length) return;
      html += '<optgroup label="' + esc(group.label) + '">' +
        groupKeys.map(function (key) {
          return '<option value="' + esc(key) + '"' + (key === current ? ' selected' : '') + '>' +
            esc(adminChannelProviders[key].label) + '</option>';
        }).join('') +
        '</optgroup>';
    });
    return html;
  }

  function channelProviderFieldsHTML(providerKey, values) {
    var provider = channelProvider(providerKey);
    if (!provider || !provider.fields || !provider.fields.length) {
      return '<div class="mz-provider-fields__empty">This provider needs no extra settings.</div>';
    }
    var vals = values || {};
    return provider.fields.map(function (field) {
      var control = field.type === 'textarea'
        ? '<textarea class="mz-input mz-mono" rows="3" data-provider-field="' + esc(field.key) + '" autocomplete="off" placeholder="' + esc(field.placeholder || '') + '">' + esc(vals[field.key] || '') + '</textarea>'
        : '<input class="mz-input" data-provider-field="' + esc(field.key) + '" type="' + esc(field.type || 'text') + '" autocomplete="off" placeholder="' + esc(field.placeholder || '') + '" value="' + esc(vals[field.key] || '') + '">';
      return '<label class="mz-field"><span class="mz-label">' + esc(field.label) + (field.required ? ' *' : '') + '</span>' +
        control +
        (field.hint ? '<span class="mz-hint">' + esc(field.hint) + '</span>' : '') +
        '</label>';
    }).join('');
  }

  function channelByCode(code) {
    for (var i = 0; i < adminChannels.length; i++) {
      if (adminChannels[i].code === code) return adminChannels[i];
    }
    return null;
  }

  function openChannelDialog(channel) {
    var isNew = !channel;
    var c = channel || { code: '', label: '', provider: 'log', sender: '', config: null, is_active: true };
    var values = c.config && typeof c.config === 'object' ? c.config : {};
    var providerKey = channelProvider(c.provider) ? c.provider : (isNew ? 'log' : (c.provider || 'log'));
    var senderHint = adminSenderHints[c.code] || null;

    var dialog = openDialog({
      title: isNew ? 'Add communication channel' : 'Manage ' + c.label + ' channel',
      sub: isNew
        ? 'Register a channel and its provider credentials for customer notifications.'
        : 'Update the provider credentials, then save to activate or deactivate this channel.',
      submitLabel: isNew ? 'Add channel' : 'Save channel',
      wide: true,
      body:
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Channel code *</span>' +
            '<input class="mz-input mz-mono" name="code" maxlength="20" value="' + esc(c.code) + '" placeholder="sms, whatsapp, email, push"' + (isNew ? ' required' : ' readonly') + '>' +
          '</label>' +
          '<label class="mz-field"><span class="mz-label">Label *</span>' +
            '<input class="mz-input" name="label" maxlength="40" value="' + esc(c.label) + '" placeholder="SMS" required>' +
          '</label>' +
        '</div>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Provider *</span>' +
            '<select class="mz-input" name="provider">' + channelProviderOptions(providerKey) + '</select>' +
          '</label>' +
          '<label class="mz-field"><span class="mz-label">Sender / from</span>' +
            '<input class="mz-input" name="sender" maxlength="160" value="' + esc(c.sender || '') + '" placeholder="' + esc(senderHint ? senderHint.placeholder : 'MZIGO') + '">' +
            '<span class="mz-hint" data-sender-hint>' + esc(senderHint ? senderHint.hint : 'Number or address messages are sent from.') + '</span>' +
          '</label>' +
        '</div>' +
        (isNew ? '' : '<div class="mz-hint" style="margin:-6px 0 12px;">Channel code <strong>' + esc(c.code) + '</strong> is permanent — it is referenced by the notification log.</div>') +
        '<div class="mz-field"><span class="mz-label">Provider settings</span>' +
          '<div class="mz-provider-fields" data-provider-fields>' + channelProviderFieldsHTML(providerKey, values) + '</div>' +
          '<span class="mz-hint" data-provider-hint>' + esc((channelProvider(providerKey) || {}).hint || '') + '</span>' +
        '</div>' +
        '<label class="mz-check"><input type="checkbox" name="is_active"' + (c.is_active ? ' checked' : '') + '>' +
          '<span><strong>Active</strong> — include this channel when notifying customers.</span></label>',
      onSubmit: function (form) {
        var code = formValue(form, 'code').toLowerCase();
        var label = formValue(form, 'label');
        var provider = formValue(form, 'provider') || 'log';
        var providerDef = channelProvider(provider);
        var activeEl = form.elements.namedItem('is_active');
        if (!/^[a-z0-9_]{2,20}$/.test(code)) {
          throw new Error('Channel code must be 2–20 characters: lowercase letters, numbers or underscores.');
        }
        if (label === '') throw new Error('Enter a channel label.');
        if (!providerDef) throw new Error('Choose one of the supported providers.');

        var settings = {};
        form.querySelectorAll('[data-provider-field]').forEach(function (input) {
          var key = input.getAttribute('data-provider-field');
          var value = input.value.trim();
          if (key && value !== '') settings[key] = value;
        });
        var missing = (providerDef.fields || []).filter(function (field) {
          return field.required && !settings[field.key];
        });
        if (missing.length) {
          throw new Error('Add ' + missing[0].label + ' for ' + providerDef.label + '.');
        }

        return api('admin.php', { method: 'POST', body: {
          action: 'save_channel',
          original_code: isNew ? '' : c.code,
          code: code,
          label: label,
          provider: provider,
          sender: formValue(form, 'sender'),
          config: settings,
          is_active: !!(activeEl && activeEl.checked)
        }}).then(function () {
          toast(isNew ? 'Channel added.' : 'Channel saved.');
          loadAdmin();
        });
      }
    });

    /* Live form behaviour: provider swap re-renders the credential fields,
       and the sender example follows the channel code. */
    var overlay = dialog.overlay;
    var form = overlay.querySelector('form');
    var providerSelect = form.elements.namedItem('provider');
    var codeInput = form.elements.namedItem('code');
    var labelInput = form.elements.namedItem('label');
    var senderInput = form.elements.namedItem('sender');
    var fieldsBox = overlay.querySelector('[data-provider-fields]');
    var providerHint = overlay.querySelector('[data-provider-hint]');
    var senderHintBox = overlay.querySelector('[data-sender-hint]');

    function collectSettings() {
      var out = {};
      fieldsBox.querySelectorAll('[data-provider-field]').forEach(function (input) {
        var value = input.value.trim();
        if (value !== '') out[input.getAttribute('data-provider-field')] = value;
      });
      return out;
    }
    function syncSenderHint() {
      var code = (codeInput.value || '').toLowerCase();
      var hint = adminSenderHints[code] || null;
      senderInput.placeholder = hint ? hint.placeholder : 'MZIGO';
      senderHintBox.textContent = hint ? hint.hint : 'Number or address messages are sent from.';
      if (labelInput.value === '' && code !== '') {
        labelInput.placeholder = code.charAt(0).toUpperCase() + code.slice(1);
      }
    }
    providerSelect.addEventListener('change', function () {
      var next = providerSelect.value;
      var kept = collectSettings();
      fieldsBox.innerHTML = channelProviderFieldsHTML(next, kept);
      var def = channelProvider(next);
      providerHint.textContent = def ? def.hint : '';
    });
    codeInput.addEventListener('input', syncSenderHint);
    syncSenderHint();
  }

  function renderAdminChannels(card, channels) {
    if (!card) return;
    adminChannels = channels || [];
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:12px;">' +
        '<div><div class="mz-card__title" style="margin:0;">Communication channels</div>' +
        '<div style="font-size:12px;color:var(--mz-muted-fg);">Customer messaging — open a channel to configure its provider or switch it on/off</div></div>' +
        '<button type="button" class="mz-btn mz-btn--outline mz-channel-action" data-channel-add>+ Add channel</button>' +
      '</div>' +
      (adminChannels.length
        ? '<div class="mz-channel-list">' + adminChannels.map(function (c) {
            var providerDef = channelProvider(c.provider);
            var settings = c.config && typeof c.config === 'object' ? c.config : {};
            var meta = ['code: ' + c.code, 'provider: ' + channelProviderLabel(c.provider)];
            if (c.sender) meta.push('from: ' + c.sender);
            var keys = Object.keys(settings).length;
            if (keys) meta.push(keys + ' setting' + (keys === 1 ? '' : 's'));
            var missing = providerDef
              ? (providerDef.fields || []).filter(function (field) { return field.required && !settings[field.key]; })
              : [];
            if (missing.length) {
              meta.push(providerDef.label + ' needs ' + missing.length + ' credential' + (missing.length === 1 ? '' : 's'));
            }
            return '<div class="mz-channel-row">' +
              '<div class="mz-channel-row__main">' +
                '<div class="mz-channel-row__title"><strong>' + esc(c.label) + '</strong></div>' +
                '<div class="mz-channel-row__meta">' + esc(meta.join(' · ')) + '</div>' +
              '</div>' +
              '<div class="mz-channel-row__side">' +
                '<span class="mz-badge ' + (c.is_active ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (c.is_active ? 'Active' : 'Disabled') + '</span>' +
                '<button type="button" class="mz-btn mz-btn--outline mz-channel-action" data-channel-manage="' + esc(c.code) + '">Manage</button>' +
              '</div>' +
            '</div>';
          }).join('') + '</div>'
        : '<div class="mz-empty-note">No channels configured yet. Add one to start sending customer notifications.</div>');
  }

  function renderAdminNotifications(card, rows) {
    if (!card) return;
    var shortError = function (error) {
      var text = String(error || '').replace(/\s+/g, ' ').trim();
      if (text.length <= 46) return text;
      var head = text.split(':')[0];
      if (head.length >= 8 && head.length <= 40) return head;
      return text.slice(0, 46).replace(/[\s,;.]+$/, '') + '…';
    };
    var txRows = rows.map(function (n) {
      var error = n.error ? String(n.error) : '';
      var created = new Date(String(n.created_at).replace(' ', 'T'));
      var dateShort = isNaN(created) ? String(n.created_at).slice(0, 10) : created.toLocaleDateString('en-KE', { day: 'numeric', month: 'short' });
      return '<tr>' +
        '<td style="overflow:hidden;"><div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(n.recipient || '') + '">' + esc(n.recipient || '—') + '</div>' +
          '<div class="mz-mono" style="font-size:10px;color:var(--mz-muted-fg);">' + esc(n.channel) + '</div></td>' +
        '<td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(n.template) + '">' + esc(n.template) + '</td>' +
        '<td style="overflow:hidden;"><span class="mz-badge ' + (n.status === 'sent' ? 'mz-badge--ok' : n.status === 'skipped' ? 'mz-badge--warn' : '') + '">' + esc(n.status) + '</span>' +
        (error ? '<div class="mz-notif-error" title="' + esc(error) + '" style="font-size:10px;color:var(--mz-muted-fg);max-width:100%;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:help;">' + esc(shortError(error)) + '</div>' : '') + '</td>' +
        '<td style="white-space:nowrap;" title="' + esc(fmtDate(n.created_at)) + '">' + esc(dateShort) + '</td></tr>';
    });
    card.innerHTML = '<div class="mz-card__title" style="margin:0 0 10px;">Notifications log</div><div data-notifications-table></div>';
    renderPagedTable(card.querySelector('[data-notifications-table]'), {
      title: 'Sent messages', columns: ['To', 'Template', 'Status', 'Sent'],
      rows: txRows, empty: 'No notifications sent yet.', perPage: 5,
      page: adminTablePages.notifications || 1, onPage: function (page) { adminTablePages.notifications = page; }
    });
  }

  function renderAdminApiUsage(card, data) {
    if (!card) return;
    var daily = data.daily || [];
    var max = 0;
    daily.forEach(function (d) { max = Math.max(max, Number(d.requests || 0)); });
    var bars = daily.map(function (d) {
      var pct = max > 0 ? Math.max(2, Math.round((Number(d.requests || 0) / max) * 100)) : 0;
      return '<div title="' + esc(d.day) + ': ' + Number(d.requests || 0) + ' requests" style="flex:1;min-width:3px;display:flex;flex-direction:column;align-items:center;gap:4px;">' +
        '<div style="width:100%;height:74px;display:flex;align-items:flex-end;"><div style="width:100%;height:' + pct + '%;background:#3c5223;border-radius:3px 3px 0 0;opacity:.75;"></div></div>' +
        '<span class="mz-hint" style="font-size:8px;white-space:nowrap;">' + esc(d.day.slice(8, 10)) + '</span></div>';
    }).join('');
    var tenantRows = (data.by_tenant || []).map(function (t) {
      return '<tr><td>' + esc(t.tenant_name || ('Workspace #' + t.tenant_id)) + '</td><td class="mz-mono">' + Number(t.requests || 0).toLocaleString() + '</td><td>' + Number(t.keys_used || 0) + '</td></tr>';
    }).join('');
    var keyRows = (data.by_key || []).map(function (k) {
      return '<tr><td><strong>' + esc(k.key_name || ('Key #' + k.api_key_id)) + '</strong><div class="mz-hint mz-mono">' + esc(k.prefix || '') + '</div></td>' +
        '<td>' + esc(k.tenant_name || '') + '</td><td class="mz-mono">' + Number(k.requests || 0).toLocaleString() + '</td></tr>';
    }).join('');
    card.innerHTML = '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Platform API usage</div>' +
      '<div class="mz-hint">Requests recorded by tenant API keys across the platform, last 30 days.</div></div>' +
      '<span class="mz-badge">' + Number(data.total_30d || 0).toLocaleString() + ' requests / 30d</span></div>' +
      '<div class="mz-stats mz-stats--4" style="margin-bottom:14px;">' +
        '<div class="mz-stat"><div class="mz-cap">Last 30 days</div><div class="mz-stat__value">' + Number(data.total_30d || 0).toLocaleString() + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Last 7 days</div><div class="mz-stat__value">' + Number(data.last_7d || 0).toLocaleString() + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Today</div><div class="mz-stat__value">' + Number(data.today || 0).toLocaleString() + '</div></div>' +
        '<div class="mz-stat"><div class="mz-cap">Active workspaces / keys</div><div class="mz-stat__value">' + Number(data.active_tenants || 0) + ' / ' + Number(data.active_keys || 0) + '</div></div>' +
      '</div>' +
      (Number(data.total_30d || 0) === 0
        ? '<div class="mz-empty-note">No API requests recorded in the last 30 days.</div>'
        : '<div class="mz-label" style="margin-bottom:6px;">Daily requests (last 30 days)</div>' +
          '<div style="display:flex;align-items:flex-end;gap:2px;padding:8px 0 4px;">' + bars + '</div>' +
          '<div class="mz-saas-grid mz-saas-grid--2" style="margin-top:16px;">' +
            '<div><div class="mz-label">Top workspaces</div><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Workspace</th><th>Requests</th><th>Keys</th></tr></thead><tbody>' +
              (tenantRows || '<tr><td colspan="3" class="mz-hint">No workspace usage.</td></tr>') + '</tbody></table></div></div>' +
            '<div><div class="mz-label">Top API keys</div><div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Key</th><th>Workspace</th><th>Requests</th></tr></thead><tbody>' +
              (keyRows || '<tr><td colspan="3" class="mz-hint">No key usage.</td></tr>') + '</tbody></table></div></div>' +
          '</div>');
  }

  function renderAdminDeliverability(card, data) {
    if (!card) return;
    var suppressions = data.suppressions || [];
    var events = data.events || [];
    var supRows = suppressions.map(function (s) {
      return '<tr><td title="' + esc(s.email) + '">' + esc(s.email) + '</td>' +
        '<td><span class="mz-badge mz-badge--warn">' + esc(s.event_type) + '</span></td>' +
        '<td class="mz-hint" title="' + esc(s.reason || '') + '">' + esc(s.reason || '—') + '</td>' +
        '<td><button type="button" class="mz-link" data-remove-suppression="' + esc(s.email) + '">Remove</button></td></tr>';
    }).join('');
    var eventRows = events.map(function (e) {
      return '<tr><td>' + esc(fmtDate(e.created_at)) + '</td><td><span class="mz-badge">' + esc(e.event_type) + '</span></td>' +
        '<td title="' + esc(e.email) + '">' + esc(e.email) + '</td>' +
        '<td class="mz-hint" title="' + esc(e.reason || '') + '">' + esc(e.reason || '—') + '</td></tr>';
    }).join('');
    card.innerHTML = '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Email deliverability</div>' +
      '<div class="mz-hint">Hard bounces and complaints automatically suppress an address. Suppressed addresses are skipped by the mailer.</div></div>' +
      '<span class="mz-badge ' + (suppressions.length ? 'mz-badge--warn' : 'mz-badge--ok') + '">' + suppressions.length + ' suppressed</span></div>' +
      '<div class="mz-deliverability-grid">' +
        '<section><div class="mz-label">Suppressed addresses</div><div class="mz-table-wrap"><table class="mz-table mz-deliverability-table mz-deliverability-table--suppressions"><thead><tr><th>Email</th><th>Event</th><th>Reason</th><th></th></tr></thead><tbody>' +
          (supRows || '<tr><td colspan="4" class="mz-hint">No suppressed addresses.</td></tr>') + '</tbody></table></div></section>' +
        '<section><div class="mz-label">Recent bounce/complaint events</div><div class="mz-table-wrap"><table class="mz-table mz-deliverability-table mz-deliverability-table--events"><thead><tr><th>When</th><th>Event</th><th>Email</th><th>Reason</th></tr></thead><tbody>' +
          (eventRows || '<tr><td colspan="4" class="mz-hint">No bounce/complaint events yet.</td></tr>') + '</tbody></table></div></section>' +
      '</div>';
    card.querySelectorAll('[data-remove-suppression]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!window.confirm('Allow email to this address again?')) return;
        btn.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'remove_email_suppression', email: btn.getAttribute('data-remove-suppression') } })
          .then(function (res) { toast(res.message || 'Address unsuppressed.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });
  }

  function renderAdminCommsTemplates(card, rows) {
    if (!card) return;
    adminCommsTemplates = rows || [];
    var items = adminCommsTemplates.map(function (t) {
      return '<tr><td><span class="mz-badge">' + esc(t.stage) + '</span></td>' +
        '<td><div style="font-weight:600;">' + esc(t.label) + '</div><div class="mz-mono" style="font-size:10px;color:var(--mz-muted-fg);">' + esc(t.template) + '</div></td>' +
        '<td>' + esc(t.recipient) + '</td><td class="mz-hint" style="max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(t.trigger || '') + '">' + esc(t.trigger || '—') + '</td>' +
        '<td>' + t.sent + (t.attempts > t.sent ? ' <span class="mz-hint">/ ' + t.attempts + '</span>' : '') + '</td>' +
        '<td>' + (t.last_sent ? esc(fmtDate(t.last_sent)) : '—') + '</td>' +
        '<td style="white-space:nowrap;"><button type="button" class="mz-link" data-template-view="' + t.id + '">View</button> · <button type="button" class="mz-link" data-template-edit="' + t.id + '">Edit</button></td></tr>';
    });
    card.innerHTML = '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Customer journey communication templates</div>' +
      '<div class="mz-hint">Templates used end to end by the platform. Edit subject/body or add a template for a new journey trigger.</div></div>' +
      '<button type="button" class="mz-btn mz-btn--outline" data-template-add>+ Add template</button></div>' +
      '<div data-comms-templates-table></div>';
    renderPagedTable(card.querySelector('[data-comms-templates-table]'), {
      title: 'Journey templates', columns: ['Stage', 'Template', 'Recipient', 'Trigger', 'Sent', 'Last sent', ''],
      rows: items, empty: 'No templates recorded.', perPage: 6,
      page: adminTablePages.comms_templates || 1,
      onPage: function (page) { adminTablePages.comms_templates = page; }
    });
    if (card.getAttribute('data-template-bound') !== '1') {
      card.setAttribute('data-template-bound', '1');
      card.addEventListener('click', function (ev) {
        var add = ev.target.closest('[data-template-add]');
        if (add) { openTemplateEditor(null); return; }
        var view = ev.target.closest('[data-template-view]');
        if (view) { var t = templateById(Number(view.getAttribute('data-template-view'))); if (t) openTemplateView(t); return; }
        var edit = ev.target.closest('[data-template-edit]');
        if (edit) { var e = templateById(Number(edit.getAttribute('data-template-edit'))); if (e) openTemplateEditor(e); }
      });
    }
  }

  function templateById(id) {
    for (var i = 0; i < adminCommsTemplates.length; i++) {
      if (Number(adminCommsTemplates[i].id) === Number(id)) return adminCommsTemplates[i];
    }
    return null;
  }

  function openTemplateView(t) {
    var preview = t.body_html
      ? '<iframe sandbox srcdoc="' + esc(t.body_html) + '" style="width:100%;height:260px;border:1px solid rgba(60,82,35,.15);border-radius:10px;background:#fff;"></iframe>'
      : '<div class="mz-hint">No HTML override — the branded system layout is used.</div>';
    openDialog({
      title: 'View template · ' + t.label,
      sub: t.template + ' · ' + t.stage + ' · ' + t.recipient,
      wide: true,
      submitLabel: 'Close',
      body: '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Template key</span><div class="mz-input mz-mono">' + esc(t.template) + '</div></label>' +
        '<label class="mz-field"><span class="mz-label">Trigger</span><div class="mz-input">' + esc(t.trigger || '—') + '</div></label></div>' +
        '<label class="mz-field"><span class="mz-label">Subject override</span><div class="mz-input">' + esc(t.subject || '— system subject —') + '</div></label>' +
        '<label class="mz-field"><span class="mz-label">Plain-text override</span><pre style="margin:0;white-space:pre-wrap;max-height:160px;overflow:auto;font-size:12px;background:#f7faf3;border:1px solid #e3ebd8;border-radius:10px;padding:10px;">' + esc(t.body_text || '— system plain-text body —') + '</pre></label>' +
        '<div class="mz-label" style="margin:10px 0 6px;">HTML override preview</div>' + preview +
        '<p class="mz-hint" style="margin-top:8px;">Active: ' + (t.is_active ? 'yes' : 'no') + ' · Last updated: ' + esc(t.updated_at ? fmtDate(t.updated_at) : '—') + '</p>',
      onSubmit: function () { return Promise.resolve(); }
    });
  }

  function openTemplateEditor(t) {
    var isNew = !t;
    t = t || { template_key: '', label: '', stage: 'Journey', recipient: 'Customer', trigger: '', subject: '', body_text: '', body_html: '', is_active: 1, is_system: 0 };
    var dialog = openDialog({
      title: isNew ? 'Add customer-journey template' : 'Edit template · ' + t.label,
      sub: isNew ? 'Create a template for a journey trigger. The key is used by notification calls.' : 'System templates can be edited or disabled; custom templates can be deleted.',
      wide: true,
      submitLabel: isNew ? 'Add template' : 'Save template',
      body:
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Template key *</span><input class="mz-input mz-mono" name="template_key" value="' + esc(t.template_key || t.template || '') + '"' + (isNew ? '' : ' readonly') + ' placeholder="parcel_out_for_delivery"></label>' +
          '<label class="mz-field"><span class="mz-label">Label *</span><input class="mz-input" name="label" value="' + esc(t.label || '') + '" required></label>' +
        '</div>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Stage</span><input class="mz-input" name="stage" value="' + esc(t.stage || 'Journey') + '" placeholder="Journey"></label>' +
          '<label class="mz-field"><span class="mz-label">Recipient</span><input class="mz-input" name="recipient" value="' + esc(t.recipient || 'Customer') + '" placeholder="Customer"></label>' +
        '</div>' +
        '<label class="mz-field"><span class="mz-label">Trigger description</span><input class="mz-input" name="trigger" value="' + esc(t.trigger || '') + '" placeholder="Orders → Advance"></label>' +
        '<label class="mz-field"><span class="mz-label">Subject override (optional)</span><input class="mz-input" name="subject" value="' + esc(t.subject || '') + '" placeholder="Leave empty for the system subject"></label>' +
        '<label class="mz-field"><span class="mz-label">Plain-text body override (optional)</span><textarea class="mz-input" name="body_text" rows="4" placeholder="Leave empty for the system text">' + esc(t.body_text || '') + '</textarea></label>' +
        '<label class="mz-field"><span class="mz-label">HTML override (optional, full HTML)</span><textarea class="mz-input" name="body_html" rows="8" style="font-family:ui-monospace,monospace;font-size:12px;" placeholder="Leave empty to keep the branded Mzigo HTML layout">' + esc(t.body_html || '') + '</textarea></label>' +
        '<p class="mz-hint">Placeholders: <code>{{name}}</code> <code>{{tracking}}</code> <code>{{status}}</code> <code>{{amount}}</code> <code>{{reference}}</code> <code>{{link}}</code> <code>{{claim_ref}}</code> <code>{{note}}</code> <code>{{business}}</code> <code>{{code}}</code></p>' +
        '<label class="mz-check"><input type="checkbox" name="is_active"' + (t.is_active ? ' checked' : '') + '><span><strong>Active</strong> — apply this template when the trigger fires</span></label>' +
        (!isNew && !t.is_system ? '<button type="button" class="mz-link" style="margin-top:12px;" data-template-delete>Delete custom template</button>' : ''),
      onSubmit: function (form) {
        var payload = {
          action: 'save_template', template_id: isNew ? '' : t.id, template_key: formValue(form, 'template_key'),
          label: formValue(form, 'label'), stage: formValue(form, 'stage'), recipient: formValue(form, 'recipient'),
          trigger: formValue(form, 'trigger'), subject: formValue(form, 'subject'),
          body_text: formValue(form, 'body_text'), body_html: formValue(form, 'body_html'),
          is_active: !!(form.elements.namedItem('is_active') && form.elements.namedItem('is_active').checked)
        };
        return api('admin.php', { method: 'POST', body: payload }).then(function (res) {
          toast(res.message || 'Template saved.');
          loadAdmin();
        });
      }
    });
    var del = dialog.overlay.querySelector('[data-template-delete]');
    if (del) del.addEventListener('click', function () {
      if (!window.confirm('Delete this custom template?')) return;
      api('admin.php', { method: 'POST', body: { action: 'delete_template', template_id: t.id } })
        .then(function (res) { toast(res.message || 'Template deleted.'); dialog.close(); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); });
    });
  }

  function renderAdminPoints(card, data) {
    if (!card) return;
    var points = data.partner_points || [];
    var carriers = data.point_carriers || [];
    var pointTenants = data.tenants || [];
    var tokenBanner = pendingPointToken
      ? '<div class="mz-alert" style="margin-bottom:12px;">Point access token (copy it now — it will not be shown again): <code style="word-break:break-all;">' + esc(pendingPointToken) + '</code> <button type="button" class="mz-link" data-dismiss-point-token>Dismiss</button></div>'
      : '';
    var rows = points.map(function (p) {
      return '<tr><td><strong>' + esc(p.name) + '</strong><div class="mz-mono mz-hint">' + esc(p.code) + '</div></td>' +
        '<td>' + esc(p.carrier_name || p.partner_slug) + '<div class="mz-hint">' + esc(p.workspace_name || 'All workspaces') + '</div></td><td>' + esc(p.point_type) + '</td>' +
        '<td class="mz-hint">' + esc(p.location || '—') + '<div>' + (p.last_scan_at ? 'Last scan ' + esc(fmtDate(p.last_scan_at)) : 'No scans yet') + '</div></td>' +
        '<td><span class="mz-badge ' + (Number(p.is_active) ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (Number(p.is_active) ? 'Active' : 'Disabled') + '</span></td>' +
        '<td style="white-space:nowrap;"><button type="button" class="mz-link" data-point-edit="' + p.id + '">Edit</button> · ' +
          '<button type="button" class="mz-link" data-point-regenerate="' + p.id + '">New token</button> · ' +
          '<button type="button" class="mz-link" data-point-toggle="' + p.id + '">' + (Number(p.is_active) ? 'Disable' : 'Enable') + '</button> · ' +
          '<button type="button" class="mz-link" data-point-delete="' + p.id + '">Delete</button></td></tr>';
    }).join('');
    card.innerHTML = tokenBanner +
      '<div class="mz-row" style="margin-bottom:12px;"><div><div class="mz-card__title" style="margin:0;">Partner scan points</div>' +
      '<div class="mz-hint">Each point gets a one-time access token for the phone scanner. Tokens are hashed at rest and can be regenerated.</div></div>' +
      '<a class="mz-btn mz-btn--outline" href="/scan" target="_blank" rel="noopener">Open scanner</a></div>' +
      '<div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Point</th><th>Carrier</th><th>Type</th><th>Location / last scan</th><th>Status</th><th></th></tr></thead><tbody>' +
        (rows || '<tr><td colspan="6" class="mz-hint">No scan points yet.</td></tr>') + '</tbody></table></div>' +
      '<div class="mz-card__title" style="margin:20px 0 10px;">Add scan point</div>' +
      '<form class="mz-stack" data-point-create>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Point name *</span><input class="mz-input" name="name" placeholder="Chania Nairobi office" required></label>' +
        '<label class="mz-field"><span class="mz-label">Point code *</span><input class="mz-input mz-mono" name="code" placeholder="CHANIA-NBO-01" maxlength="24" required></label></div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Carrier *</span><select class="mz-input" name="partner_slug">' +
          carriers.map(function (c) { return '<option value="' + esc(c.slug) + '">' + esc(c.name) + '</option>'; }).join('') + '</select></label>' +
        '<label class="mz-field"><span class="mz-label">Workspace</span><select class="mz-input" name="tenant_id"><option value="">All workspaces</option>' +
          pointTenants.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('') + '</select></label>' +
        '<label class="mz-field"><span class="mz-label">Point type</span><select class="mz-input" name="point_type">' +
          ['dropoff','handover','arrival','collection','multi'].map(function (t) { return '<option value="' + t + '"' + (t === 'multi' ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select></label></div>' +
        '<label class="mz-field"><span class="mz-label">Location</span><input class="mz-input" name="location" placeholder="Chania office, Nairobi"></label>' +
        '<button class="mz-btn" type="submit">Create point & generate token</button></form>';
    var dismiss = card.querySelector('[data-dismiss-point-token]');
    if (dismiss) dismiss.addEventListener('click', function () { pendingPointToken = ''; loadAdmin(); });
    card.querySelectorAll('[data-point-edit]').forEach(function (btn) { btn.addEventListener('click', function () { openPointEditor(points.filter(function (p) { return String(p.id) === btn.getAttribute('data-point-edit'); })[0], pointTenants); }); });
    card.querySelectorAll('[data-point-regenerate]').forEach(function (btn) { btn.addEventListener('click', function () {
      if (!window.confirm('Regenerate this point token? The old token will stop working immediately.')) return;
      api('admin.php', { method: 'POST', body: { action: 'regenerate_point_token', point_id: Number(btn.getAttribute('data-point-regenerate')) } })
        .then(function (res) { pendingPointToken = res.point_token || ''; toast(res.message || 'Token regenerated.'); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); });
    }); });
    card.querySelectorAll('[data-point-toggle]').forEach(function (btn) { btn.addEventListener('click', function () {
      api('admin.php', { method: 'POST', body: { action: 'toggle_partner_point', point_id: Number(btn.getAttribute('data-point-toggle')) } })
        .then(function (res) { toast(res.message || 'Point updated.'); loadAdmin(); }).catch(function (err) { toast(err.message, 'error'); });
    }); });
    card.querySelectorAll('[data-point-delete]').forEach(function (btn) { btn.addEventListener('click', function () {
      if (!window.confirm('Delete this scan point? Its token will stop working.')) return;
      api('admin.php', { method: 'POST', body: { action: 'delete_partner_point', point_id: Number(btn.getAttribute('data-point-delete')) } })
        .then(function (res) { toast(res.message || 'Point deleted.'); loadAdmin(); }).catch(function (err) { toast(err.message, 'error'); });
    }); });
    var create = card.querySelector('[data-point-create]');
    if (create) create.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = create.querySelector('[type="submit"]'); btn.disabled = true;
      api('admin.php', { method: 'POST', body: {
        action: 'create_partner_point', name: formValue(create, 'name'), code: formValue(create, 'code'),
        tenant_id: formValue(create, 'tenant_id'), partner_slug: formValue(create, 'partner_slug'),
        point_type: formValue(create, 'point_type'), location: formValue(create, 'location')
      } }).then(function (res) { pendingPointToken = res.point_token || ''; toast(res.message || 'Point created.'); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function openPointEditor(point, pointTenants) {
    if (!point) return;
    pointTenants = pointTenants || [];
    openDialog({
      title: 'Edit scan point · ' + point.code,
      submitLabel: 'Save point',
      body: '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Point name *</span><input class="mz-input" name="name" value="' + esc(point.name) + '" required></label>' +
        '<label class="mz-field"><span class="mz-label">Point type</span><select class="mz-input" name="point_type">' +
        ['dropoff','handover','arrival','collection','multi'].map(function (t) { return '<option value="' + t + '"' + (t === point.point_type ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select></label></div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Workspace</span><select class="mz-input" name="tenant_id"><option value="">All workspaces</option>' +
          pointTenants.map(function (t) { return '<option value="' + t.id + '"' + (Number(point.tenant_id) === Number(t.id) ? ' selected' : '') + '>' + esc(t.name) + '</option>'; }).join('') + '</select></label>' +
        '<label class="mz-field"><span class="mz-label">Location</span><input class="mz-input" name="location" value="' + esc(point.location || '') + '"></label></div>',
      onSubmit: function (form) {
        return api('admin.php', { method: 'POST', body: { action: 'update_partner_point', point_id: point.id,
          tenant_id: formValue(form, 'tenant_id'), name: formValue(form, 'name'), point_type: formValue(form, 'point_type'), location: formValue(form, 'location') } })
          .then(function (res) { toast(res.message || 'Point updated.'); loadAdmin(); });
      }
    });
  }

  function renderAdminClaimRails(card, c) {
    if (!card) return;
    var pc = c.payout_config || {};
    var rc = c.recovery_config || {};
    var secretBanner = c.callback_secret_once
      ? '<div class="mz-alert" style="margin-bottom:12px;">New callback secret (copy now): <code style="word-break:break-all;">' + esc(c.callback_secret_once) + '</code></div>'
      : '';
    function field(name, label, value, type) {
      return '<label class="mz-field"><span class="mz-label">' + esc(label) + '</span><input class="mz-input" name="' + name + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '" autocomplete="off"></label>';
    }
    card.innerHTML = secretBanner +
      '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Claim payout &amp; recovery rails</div>' +
      '<div class="mz-hint">Choose demo for mock settlement, or sandbox/live for provider integration. Credentials are encrypted at rest and masked after saving.</div></div>' +
      '<span class="mz-badge ' + (c.payout_ready ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (c.payout_ready ? 'Payout ready' : 'Payout setup incomplete') + '</span></div>' +
      '<form data-claim-rails-form class="mz-stack">' +
        '<div class="mz-card__title" style="font-size:13px;margin:4px 0 0;">Payout provider</div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Mode</span><select class="mz-input" name="payout_mode">' +
          ['demo','sandbox','live'].map(function (m) { return '<option value="' + m + '"' + (c.payout_mode === m ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select></label>' +
        '<label class="mz-field"><span class="mz-label">Provider</span><select class="mz-input" name="payout_provider">' +
          [['demo','Demo'],['mpesa','M-Pesa B2C']].map(function (m) { return '<option value="' + m[0] + '"' + (c.payout_provider === m[0] ? ' selected' : '') + '>' + m[1] + '</option>'; }).join('') + '</select></label></div>' +
        '<div class="mz-form-2col">' + field('payout_consumer_key', 'Consumer key', pc.consumer_key) + field('payout_consumer_secret', 'Consumer secret', pc.consumer_secret, 'password') + '</div>' +
        '<div class="mz-form-2col">' + field('payout_shortcode', 'Shortcode / PartyA', pc.shortcode) + field('payout_passkey', 'Passkey', pc.passkey, 'password') + '</div>' +
        '<div class="mz-form-2col">' + field('payout_initiator_name', 'Initiator name', pc.initiator_name) + field('payout_security_credential', 'Security credential', pc.security_credential, 'password') + '</div>' +
        '<label class="mz-field"><span class="mz-label">Result callback URL</span><input class="mz-input" name="payout_callback_url" value="' + esc(pc.callback_url || c.callback_endpoint || '') + '"></label>' +
        '<div class="mz-card__title" style="font-size:13px;margin:10px 0 0;">Recovery provider</div>' +
        '<div class="mz-form-2col"><label class="mz-field"><span class="mz-label">Mode</span><select class="mz-input" name="recovery_mode">' +
          ['demo','sandbox','live'].map(function (m) { return '<option value="' + m + '"' + (c.recovery_mode === m ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select></label>' +
        '<label class="mz-field"><span class="mz-label">Provider</span><select class="mz-input" name="recovery_provider">' +
          [['demo','Demo'],['webhook','Insurer/carrier webhook']].map(function (m) { return '<option value="' + m[0] + '"' + (c.recovery_provider === m[0] ? ' selected' : '') + '>' + m[1] + '</option>'; }).join('') + '</select></label></div>' +
        '<label class="mz-field"><span class="mz-label">Recovery endpoint</span><input class="mz-input" name="recovery_endpoint" value="' + esc(rc.endpoint || '') + '" placeholder="https://insurer.example.com/mzigo/recovery"></label>' +
        '<div class="mz-form-2col">' + field('recovery_api_key', 'Bearer/API key', rc.api_key, 'password') + field('recovery_signing_secret', 'Signing secret', rc.signing_secret, 'password') + '</div>' +
        '<label class="mz-field"><span class="mz-label">Recovery callback URL</span><input class="mz-input" name="recovery_callback_url" value="' + esc(rc.callback_url || c.callback_endpoint || '') + '"></label>' +
        '<div class="mz-field"><span class="mz-label">Callback signature secret</span><input class="mz-input" name="callback_secret" type="password" value="' + (c.callback_secret_set ? '••••••••' : '') + '" placeholder="' + (c.callback_secret_set ? 'Saved — leave masked to keep' : 'Generated when saved') + '"></div>' +
        '<div class="mz-hint">Callback endpoint: <code>' + esc(c.callback_endpoint || '') + '</code> — send <code>X-Mzigo-Signature: sha256=…</code> or use <code>?token=…</code>. <button type="button" class="mz-link" data-claim-rail-regenerate>Generate new secret</button></div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;"><button class="mz-btn" type="submit">Save claim rails</button>' +
        '<span class="mz-hint">' + (c.recovery_ready ? 'Recovery ready' : 'Recovery setup incomplete') + '</span></div>' +
      '</form>';
    var form = card.querySelector('[data-claim-rails-form]');
    if (form) form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]'); btn.disabled = true;
      var payload = {
        action: 'save_claim_rails', payout_mode: formValue(form, 'payout_mode'), payout_provider: formValue(form, 'payout_provider'),
        recovery_mode: formValue(form, 'recovery_mode'), recovery_provider: formValue(form, 'recovery_provider'),
        callback_secret: formValue(form, 'callback_secret'),
        payout_config: { consumer_key: formValue(form, 'payout_consumer_key'), consumer_secret: formValue(form, 'payout_consumer_secret'),
          shortcode: formValue(form, 'payout_shortcode'), passkey: formValue(form, 'payout_passkey'), initiator_name: formValue(form, 'payout_initiator_name'),
          security_credential: formValue(form, 'payout_security_credential'), callback_url: formValue(form, 'payout_callback_url') },
        recovery_config: { endpoint: formValue(form, 'recovery_endpoint'), api_key: formValue(form, 'recovery_api_key'),
          signing_secret: formValue(form, 'recovery_signing_secret'), callback_url: formValue(form, 'recovery_callback_url') }
      };
      api('admin.php', { method: 'POST', body: payload }).then(function (res) {
        toast(res.message || 'Claim rails saved.');
        if (res.claim_rails && res.claim_rails.callback_secret_once) {
          window.prompt('Copy the new claim callback secret:', res.claim_rails.callback_secret_once);
        }
        loadAdmin();
      }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    var regen = card.querySelector('[data-claim-rail-regenerate]');
    if (regen) regen.addEventListener('click', function () {
      if (!window.confirm('Generate a new callback secret? Existing provider callbacks will fail until updated.')) return;
      api('admin.php', { method: 'POST', body: { action: 'regenerate_claim_callback_secret' } })
        .then(function (res) { toast('New callback secret generated.'); if (res.callback_secret) window.prompt('Copy the new callback secret:', res.callback_secret); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); });
    });
  }

  function renderAdminPricing(card, data) {
    if (!card) return;
    var mode = data.pricing_mode || 'paid';
    var plans = data.plans || [];
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:12px;">' +
        '<div><div class="mz-card__title" style="margin:0;">Pricing</div>' +
        '<div style="font-size:12px;color:var(--mz-muted-fg);">Platform mode: <strong>' + (mode === 'free' ? 'Free' : 'Paid plans') + '</strong> · edit amounts and package contents per card</div>' +
        '<div style="font-size:11px;color:var(--mz-muted-fg);margin-top:4px;">Policy: edits apply to future invoices and take effect immediately on limits; already-paid invoices are unchanged.</div></div>' +
        '<button type="button" class="mz-tab" data-pricing-mode="' + (mode === 'free' ? 'paid' : 'free') + '">' +
          (mode === 'free' ? 'Activate paid plans' : 'Make platform free') + '</button>' +
      '</div>' +
      '<div class="mz-plan-editor">' +
        plans.map(function (plan) {
          return '<div class="mz-plan-form" data-plan-row="' + plan.id + '">' +
            '<div class="mz-plan-form__head">' +
              '<span class="mz-plan-form__code">' + esc(plan.code) + '</span>' +
              '<label class="mz-plan-form__toggle" title="Show this plan on the pricing page"><input type="checkbox" data-plan-active="' + plan.id + '"' + (plan.is_active ? ' checked' : '') + '> Active</label>' +
            '</div>' +
            '<div class="mz-plan-form__fields">' +
              '<label class="mz-field"><span class="mz-label">Name</span><input class="mz-input" data-plan-name="' + plan.id + '" value="' + esc(plan.name) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Amount (KES/mo)</span><input class="mz-input" type="number" min="0" data-plan-price="' + plan.id + '" value="' + plan.monthly_price_kes + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Parcels/month</span><input class="mz-input" type="number" min="0" data-plan-parcels="' + plan.id + '" value="' + plan.parcel_limit + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Team members</span><input class="mz-input" type="number" min="0" data-plan-staff="' + plan.id + '" value="' + plan.staff_limit + '"></label>' +
            '</div>' +
            '<label class="mz-field mz-plan-form__features"><span class="mz-label">Package content (one feature per line)</span>' +
              '<textarea class="mz-input" rows="3" data-plan-features="' + plan.id + '">' + esc((plan.features || []).join(String.fromCharCode(10))) + '</textarea></label>' +
            '<button type="button" class="mz-btn mz-plan-form__save" data-plan-save="' + plan.id + '">Save plan</button>' +
          '</div>';
        }).join('') +
      '</div>';
    var toggle = card.querySelector('[data-pricing-mode]');
    if (toggle) toggle.addEventListener('click', function () {
      toggle.disabled = true;
      api('admin.php', { method: 'POST', body: { action: 'set_pricing_mode', mode: toggle.getAttribute('data-pricing-mode') } })
        .then(function (res) { toast('Pricing mode: ' + res.pricing_mode); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); toggle.disabled = false; });
    });
    card.querySelectorAll('[data-plan-save]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-plan-save');
        var q = function (sel) { return card.querySelector('[' + sel + '="' + id + '"]'); };
        btn.disabled = true;
        api('admin.php', { method: 'POST', body: {
          action: 'update_plan', plan_id: Number(id),
          name: q('data-plan-name').value, monthly_price_kes: Number(q('data-plan-price').value),
          parcel_limit: Number(q('data-plan-parcels').value), staff_limit: Number(q('data-plan-staff').value),
          features: q('data-plan-features').value.split(String.fromCharCode(10)),
          is_active: q('data-plan-active').checked
        }}).then(function () { toast('Plan saved.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });
  }

  var adminSection = 'overview';
  var ADMIN_SECTIONS = ['overview', 'analytics', 'workspaces', 'invoices', 'transactions', 'unassigned', 'claims', 'runnerjobs', 'kyc', 'messages', 'sessions', 'users', 'communications', 'whatsapp', 'campaigns', 'points', 'activities', 'growth', 'faqs', 'insurance', 'cms', 'seo', 'settings'];

  function adminIcon(name) {
    var paths = {
      analytics: '<path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/>',
      overview: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
      runnerjobs: '<path d="M3 17h4l3-9 4 13 3-8h4"/>',
      kyc: '<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
      insurance: '<path d="M12 3l8 4v6c0 4.5-3.4 7.8-8 9-4.6-1.2-8-4.5-8-9V7l8-4z"/><path d="M8 13h8"/><path d="M12 9v8"/>',
      faqs: '<path d="M9 9a3 3 0 1 1 5.2 2.1c-1 .8-1.7 1.4-1.7 2.9"/><circle cx="12" cy="17.5" r="1"/>',
      growth: '<path d="M20 12v9H4v-9"/><path d="M2 7h20v5H2z"/><path d="M12 21V7"/><path d="M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/>',
      workspaces: '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/>',
      invoices: '<path d="M6 2h9l5 5v15l-3-2-3 2-3-2-3 2-2-1.5V2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
      transactions: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
      seo: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/>',
      unassigned: '<path d="M3 7h18v13H3z"/><path d="M3 7l3-4h12l3 4"/><path d="M9 12h6"/>',
      claims: '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M12 11v4M12 18h.01"/>',
      messages: '<path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="m3 7 9 6 9-6"/>',
      cms: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/>',
      sessions: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
      users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
      communications: '<path d="M3 11l18-8-8 18-2-8-8-2z"/>',
      whatsapp: '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8.5 9.5c.3 2.8 2.2 4.7 5 5"/>',
      campaigns: '<path d="M3 11l18-8-8 18-2-8-8-2z"/><path d="M8 13l3 3"/>',
      points: '<path d="M12 22s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
      activities: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
      settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1.08 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'
    };
    return '<svg class="mz-admin-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || '') + '</svg>';
  }

  function adminNavList(data) {
    var m = data.metrics || {};
    var pending = m.invoices_pending || 0;
    var activeSessions = (data.sessions && data.sessions.active) || 0;
    var activeUsers = (data.users && data.users.active) || 0;
    var notifications = (data.notifications || []).length;
    return [
      ['overview', 'Overview', ''],
      ['analytics', 'Analytics', ''],
      ['workspaces', 'Workspaces', m.tenants_total || ''],
      ['invoices', 'Invoices', pending ? pending + ' due' : ''],
      ['transactions', 'Transactions', ''],
      ['unassigned', 'Unassigned parcels', (data.unassigned_parcels || []).length || ''],
      ['claims', 'Claims', (data.claims && data.claims.filter(function (c) { return c.status === 'open' || c.status === 'under_review'; }).length) || ''],
      ['runnerjobs', 'Runner jobs', ''],
      ['kyc', 'KYC approvals', ''],
      ['insurance', 'Insurance', ''],
      ['messages', 'Messages', (data.contact_messages && data.contact_messages.counts && data.contact_messages.counts.new) || ''],
      ['sessions', 'Sessions', activeSessions ? activeSessions + ' active' : ''],
      ['users', 'Users', activeUsers ? activeUsers + ' active' : ''],
      ['communications', 'Communications', notifications || '' + ''],
      ['whatsapp', 'WhatsApp', (data.whatsapp_metrics && data.whatsapp_metrics.open_handoffs) || ''],
      ['campaigns', 'Campaigns', ''],
      ['points', 'Scan points', (data.partner_points || []).length || ''],
      ['activities', 'Activities', ''],
      ['growth', 'Coupons', ''],
      ['faqs', 'FAQs', ''],
      ['cms', 'Website', ''],
      ['seo', 'SEO', ''],
      ['settings', 'Settings', '']
    ];
  }

  function adminSectionHead(title, sub) {
    return '<div class="mz-admin-section__head"><div><div class="mz-cap">Admin console</div>' +
      '<h2 class="mz-admin-section__title">' + title + '</h2>' +
      '<p class="mz-admin-section__sub">' + sub + '</p></div></div>';
  }

  function switchAdminSection(host, key, push) {
    if (ADMIN_SECTIONS.indexOf(key) < 0) key = 'overview';
    adminSection = key;
    host.querySelectorAll('[data-admin-section]').forEach(function (section) {
      section.hidden = section.getAttribute('data-admin-section') !== key;
    });
    host.querySelectorAll('[data-admin-goto]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-admin-goto') === key);
      btn.setAttribute('aria-current', btn.getAttribute('data-admin-goto') === key ? 'page' : 'false');
    });
    if (push) history.replaceState(null, '', '#' + key);
    if (key === 'analytics' || key === 'overview') setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 60);
  }

  function adminInitialSection() {
    var key = (window.location.hash || '').replace('#', '');
    return ADMIN_SECTIONS.indexOf(key) >= 0 ? key : adminSection;
  }

  function renderAdminTwoFactor(card, twofa) {
    if (!card) return;
    card.innerHTML = '<div class="mz-card__title" style="margin:0 0 10px;">Platform admin 2FA</div>' + twoFactorCard(twofa);
    bindTwoFactorCard(card, loadAdmin);
  }

  function renderAdminEmailTest(card, admin) {
    if (!card) return;
    card.innerHTML = '<div class="mz-card__title" style="margin:0 0 8px;">SMTP delivery test</div>' +
      '<div class="mz-hint" style="margin-bottom:10px;">Sends a real email through the configured email channel. In log mode it is recorded only in the demo mail log.</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;"><input class="mz-input" data-smtp-test-email value="' + esc(admin.email || '') + '" placeholder="you@example.com" style="max-width:320px;">' +
      '<button type="button" class="mz-btn" data-smtp-test-send>Send test email</button></div>' +
      '<div class="mz-hint" data-smtp-test-result style="margin-top:8px;"></div>';
    var btn = card.querySelector('[data-smtp-test-send]');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      var out = card.querySelector('[data-smtp-test-result]');
      api('admin.php', { method: 'POST', body: { action: 'send_test_email', email: card.querySelector('[data-smtp-test-email]').value } })
        .then(function (res) { if (out) out.textContent = res.message; toast(res.message); btn.disabled = false; })
        .catch(function (err) { if (out) out.textContent = err.message; toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function renderAdminSmsTest(card) {
    if (!card) return;
    card.innerHTML = '<div class="mz-card__title" style="margin:0 0 8px;">SMS delivery test</div>' +
      '<div class="mz-hint" style="margin-bottom:10px;">Sends a real SMS through the active SMS channel. Use Infobip in Admin → Communications → SMS channel.</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;"><input class="mz-input" data-sms-test-phone placeholder="+254 712 345 678" style="max-width:320px;">' +
      '<button type="button" class="mz-btn" data-sms-test-send>Send test SMS</button></div>' +
      '<div class="mz-hint" data-sms-test-result style="margin-top:8px;"></div>';
    var btn = card.querySelector('[data-sms-test-send]');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      var out = card.querySelector('[data-sms-test-result]');
      api('admin.php', { method: 'POST', body: { action: 'send_test_sms', phone: card.querySelector('[data-sms-test-phone]').value } })
        .then(function (res) { if (out) out.textContent = res.message; toast(res.message); btn.disabled = false; })
        .catch(function (err) { if (out) out.textContent = err.message; toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function renderAdminDemoAccounts(card, visible) {
    if (!card) return;
    card.innerHTML = '<div class="mz-row">' +
      '<div><div class="mz-card__title" style="margin:0;">Login page demo accounts</div>' +
      '<div class="mz-hint">The demo credentials box on /login. Turn it off before sharing the platform with real users.</div></div>' +
      '<span class="mz-badge ' + (visible ? 'mz-badge--warn' : 'mz-badge--ok') + '">' + (visible ? 'Visible' : 'Hidden') + '</span></div>' +
      '<button type="button" class="mz-btn mz-btn--outline" style="margin-top:12px;" data-toggle-demo-accounts>' +
      (visible ? 'Hide demo accounts' : 'Show demo accounts') + '</button>';
    var btn = card.querySelector('[data-toggle-demo-accounts]');
    if (btn) btn.addEventListener('click', function () {
      btn.disabled = true;
      api('admin.php', { method: 'POST', body: { action: 'toggle_demo_accounts', show: !visible } })
        .then(function (res) { toast(res.show_demo_accounts ? 'Demo accounts are now visible on the login page.' : 'Demo accounts hidden from the login page.'); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function renderAdminUnassigned(card, rows) {
    if (!card) return;
    if (!rows.length) {
      card.innerHTML = '<div class="mz-card__title">Unassigned parcels</div><p class="mz-hint">No unassigned parcels. Every parcel is attached to a workspace.</p>';
      return;
    }
    var html = rows.map(function (p) {
      return '<tr><td class="mz-mono"><a class="mz-link" href="' + esc(p.public_url || '#') + '">' + esc(p.tracking) + '</a></td>' +
        '<td>' + esc((p.from_city || '') + ' to ' + (p.to_city || '')) + '</td>' +
        '<td>' + esc(p.carrier || '') + '</td>' +
        '<td>' + esc(p.sender_name || '') + '</td>' +
        '<td>' + esc(p.receiver_name || '') + '<div style="font-size:10px;color:var(--mz-muted-fg);">' + esc(p.receiver_phone || '') + '</div></td>' +
        '<td>' + esc(p.size || '—') + '</td>' +
        '<td>' + money(p.value_kes || 0) + '</td>' +
        '<td><span class="mz-badge">' + esc(p.status || '') + '</span></td>' +
        '<td style="white-space:nowrap;">' + esc(fmtDate(p.registered_at)) + '</td></tr>';
    }).join('');
    card.innerHTML = '<div class="mz-row"><div><div class="mz-card__title" style="margin:0;">Unassigned parcels</div>' +
      '<p class="mz-hint" style="margin:4px 0 0;">' + rows.length + ' parcel(s) with no workspace. Read-only; tracking remains public.</p></div></div>' +
      '<div class="mz-table-wrap" style="margin-top:12px;"><table class="mz-table"><thead><tr><th>Tracking</th><th>Route</th><th>Courier</th><th>Sender</th><th>Receiver</th><th>Size</th><th>Value</th><th>Status</th><th>Registered</th></tr></thead><tbody>' + html + '</tbody></table></div>';
  }

  function renderAdminClaimAnalytics(card, a) {
    if (!card) return;
    a = a || {};
    var stat = function (label, value) {
      return '<div class="mz-stat"><div class="mz-cap">' + label + '</div><div class="mz-stat__value">' + value + '</div></div>';
    };
    var monthly = (a.monthly || []).map(function (m) {
      return '<tr><td class="mz-mono">' + esc(m.month) + '</td><td>' + m.claims + '</td><td>' + money(m.paid_kes) + '</td></tr>';
    }).join('');
    card.innerHTML = '<div class="mz-row" style="margin-bottom:10px;"><div class="mz-card__title" style="margin:0;">Claim analytics</div>' +
      '<a class="mz-tab" href="/api/claim-export.php">Export claims CSV</a></div>' +
      '<div class="mz-stats" style="margin-bottom:12px;">' +
        stat('Total claims', a.total || 0) +
        stat('Open', a.open || 0) +
        stat('Paid', a.paid || 0) +
        stat('Paid out', money(a.paid_kes || 0)) +
        stat('High risk', a.high_risk || 0) +
      '</div>' +
      '<div class="mz-row" style="margin-bottom:10px;flex-wrap:wrap;gap:10px;">' +
        '<span class="mz-hint">Recovered: ' + money(a.recovered_kes || 0) + '</span>' +
        '<span class="mz-hint">Avg decision: ' + (a.avg_decision_hours != null ? a.avg_decision_hours + 'h' : '—') + '</span>' +
        '<span class="mz-hint">Active blacklist entries: ' + (a.blacklist_active || 0) + '</span>' +
      '</div>' +
      '<div class="mz-table-wrap" role="region" aria-label="Claim analytics by month" tabindex="0"><table class="mz-table"><thead><tr><th>Month</th><th>Claims</th><th>Paid out</th></tr></thead><tbody>' +
        (monthly || '<tr><td colspan="3" class="mz-hint">No claim history yet.</td></tr>') + '</tbody></table></div>';
  }

  function renderAdminClaimRisk(card, rows) {
    if (!card) return;
    rows = rows || [];
    var body = rows.map(function (b) {
      return '<tr><td>' + esc(b.scope) + '</td><td class="mz-mono">' + esc(b.value) + '</td>' +
        '<td>' + esc(b.reason || '—') + '</td>' +
        '<td><span class="mz-badge ' + (b.is_active ? 'mz-badge--warn' : '') + '">' + (b.is_active ? 'active' : 'inactive') + '</span></td>' +
        '<td>' + esc(b.expires_at ? fmtDate(b.expires_at) : 'never') + '</td>' +
        '<td>' + (b.is_active ? '<button type="button" class="mz-link" data-blacklist-remove="' + b.id + '">Remove</button>' : '') + '</td></tr>';
    }).join('');
    card.innerHTML = '<div class="mz-card__title" style="margin:0 0 8px;">Risk & blacklist</div>' +
      '<div class="mz-hint" style="margin-bottom:10px;">Blocked phones, users or workspaces cannot file claims. A phone value is normalised to the last 9 digits.</div>' +
      '<form class="mz-stack" data-blacklist-form style="margin-bottom:14px;">' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Scope</span><select class="mz-input" name="scope"><option value="phone">Phone</option><option value="user">User ID</option><option value="tenant">Workspace ID</option></select></label>' +
          '<label class="mz-field"><span class="mz-label">Value</span><input class="mz-input" name="value" placeholder="Phone, user ID or tenant ID" required></label>' +
        '</div>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Reason</span><input class="mz-input" name="reason" placeholder="Reason for the block"></label>' +
          '<label class="mz-field"><span class="mz-label">Expires after (days, optional)</span><input class="mz-input" name="expires_days" type="number" min="1" placeholder="e.g. 90"></label>' +
        '</div>' +
        '<button type="submit" class="mz-btn">Add blacklist entry</button>' +
      '</form>' +
      '<div class="mz-table-wrap" role="region" aria-label="Claim blacklist" tabindex="0"><table class="mz-table"><thead><tr><th>Scope</th><th>Value</th><th>Reason</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody>' +
        (body || '<tr><td colspan="6" class="mz-hint">No blacklist entries.</td></tr>') + '</tbody></table></div>';
    var form = card.querySelector('[data-blacklist-form]');
    if (form) form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      api('admin.php', { method: 'POST', body: {
        action: 'add_blacklist', scope: formValue(form, 'scope'), value: formValue(form, 'value'),
        reason: formValue(form, 'reason'), expires_days: formValue(form, 'expires_days')
      }}).then(function (res) { toast(res.message || 'Blacklist entry saved.'); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
    card.querySelectorAll('[data-blacklist-remove]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!window.confirm('Remove this blacklist entry?')) return;
        api('admin.php', { method: 'POST', body: { action: 'remove_blacklist', blacklist_id: Number(btn.getAttribute('data-blacklist-remove')) } })
          .then(function (res) { toast(res.message || 'Blacklist entry removed.'); loadAdmin(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    });
  }

  function renderAdminClaims(card, claims) {
    if (!card) return;
    var rows = claims.map(function (c) {
      var badge = { open: 'warn', under_review: 'warn', approved: 'ok', rejected: '', paid: 'ok', withdrawn: '', payout_processing: 'warn' };
      return '<tr><td class="mz-mono">' + esc(c.ref) + (c.evidence ? '<div class="mz-hint" style="max-width:180px;word-break:break-word;">Evidence: ' + esc(c.evidence) + '</div>' : '') + '</td><td>' + esc(c.tenant_name || '—') + '</td>' +
        '<td class="mz-mono">' + esc(c.tracking || '—') + '</td><td>' + esc(c.type) + '</td>' +
        '<td><span class="mz-badge ' + (badge[c.status] ? 'mz-badge--' + badge[c.status] : '') + '">' + esc(c.status.replace('_', ' ')) + '</span>' +
          (c.claimant_role === 'customer' ? '<div class="mz-hint">Customer-reported</div>' : '') +
          (c.risk_score ? '<div class="mz-hint">Risk ' + c.risk_score + (c.risk_score >= 60 ? ' · high' : (c.risk_score >= 30 ? ' · medium' : '')) + '</div>' : '') +
          (c.first_approved_name && c.status === 'under_review' ? '<div class="mz-hint">1st approval: ' + esc(c.first_approved_name) + '</div>' : '') +
          (c.sla_due_at && ['open', 'under_review'].indexOf(c.status) !== -1 ? '<div class="mz-hint"' + (new Date(c.sla_due_at) < new Date() ? ' style="color:#b91c1c;"' : '') + '>SLA ' + esc(fmtDate(c.sla_due_at)) + '</div>' : '') + '</td>' +
        '<td>' + (c.estimated_value_kes ? money(c.estimated_value_kes) : '—') + '</td>' +
        '<td>' + (c.payout_ref ? esc(c.payout_ref) + (c.reversal_ref ? '<div class="mz-hint">reversed ' + esc(c.reversal_ref) + '</div>' : '') : (c.status === 'approved' && c.payout_hold_until ? '<span class="mz-hint">Hold until ' + esc(fmtDate(c.payout_hold_until)) + '</span>' : '—')) + '</td>' +
        '<td style="white-space:nowrap;">' +
          '<button type="button" class="mz-link" data-claim-detail="' + c.id + '">View</button> · ' +
          (['open', 'under_review'].indexOf(c.status) !== -1
            ? '<button type="button" class="mz-link" data-claim-action="under_review" data-claim="' + c.id + '">Review</button> · ' +
              '<button type="button" class="mz-link" data-claim-action="approved" data-claim="' + c.id + '">Approve</button> · ' +
              '<button type="button" class="mz-link" data-claim-action="rejected" data-claim="' + c.id + '">Reject</button>'
            : (c.status === 'approved'
        ? '<button type="button" class="mz-link" data-claim-payout-provider="' + c.id + '">Provider payout</button> · ' +
          '<button type="button" class="mz-link" data-claim-action="paid" data-claim="' + c.id + '">Manual payout</button>'
        : (c.status === 'payout_processing' ? '<span class="mz-hint">Provider payout processing</span>' : ''))) +
          (c.status === 'paid' ? ' · <button type="button" class="mz-link" data-claim-reverse="' + c.id + '">Reverse payout</button>' : '') +
          (['paid', 'reversed'].indexOf(c.status) !== -1
            ? ' · <button type="button" class="mz-link" data-claim-recovery-request="' + c.id + '">Request recovery</button> · ' +
              '<button type="button" class="mz-link" data-claim-recovery="' + c.id + '">Manual recovery</button>'
            : '') +
        '</td></tr>';
    }).join('');
    card.innerHTML = '<div class="mz-card__title" style="margin:0 0 10px;">Claims</div>' +
      '<div class="mz-table-wrap" role="region" aria-label="Claims table" tabindex="0"><table class="mz-table"><thead><tr>' +
      '<th>Claim</th><th>Workspace</th><th>Parcel</th><th>Type</th><th>Status</th><th>Claimed</th><th>Payout</th><th></th></tr></thead>' +
      '<tbody>' + (rows || '<tr><td colspan="8"><div style="text-align:center;padding:18px;"><strong>No claims yet.</strong><div class="mz-hint">Open a claim from an order or tracking page if something goes wrong.</div><a class="mz-tab" style="display:inline-flex;margin-top:8px;" href="/orders">View orders</a></div></td></tr>') + '</tbody></table></div>';
  }

  var adminContactMessages = { rows: [], counts: {} };

  function contactMessageById(id) {
    var rows = adminContactMessages.rows || [];
    for (var i = 0; i < rows.length; i++) {
      if (String(rows[i].id) === String(id)) return rows[i];
    }
    return null;
  }

  function renderAdminMessages(card, payload) {
    if (!card) return;
    payload = payload || {};
    adminContactMessages = payload;
    var rows = payload.rows || [];
    var counts = payload.counts || {};
    var chips = [['new', 'New'], ['read', 'Read'], ['replied', 'Replied'], ['archived', 'Archived']].map(function (pair) {
      return '<span>' + pair[1] + '<strong>' + (counts[pair[0]] || 0) + '</strong></span>';
    }).join('');
    var body = rows.map(function (msg) {
      var badge = { new: 'warn', read: '', replied: 'ok', archived: '' };
      return '<tr>' +
        '<td><div style="font-weight:600;">' + esc(msg.name) + '</div>' +
          '<div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(msg.email) + '</div></td>' +
        '<td>' + esc(msg.subject) +
          '<div class="mz-hint">' + (msg.notes || []).length + ' note' + ((msg.notes || []).length === 1 ? '' : 's') +
          ' · ' + (msg.replies || []).length + ' repl' + ((msg.replies || []).length === 1 ? 'y' : 'ies') + '</div></td>' +
        '<td style="white-space:nowrap;">' + esc(fmtDate(msg.created_at)) + '</td>' +
        '<td><span class="mz-badge ' + (badge[msg.status] ? 'mz-badge--' + badge[msg.status] : '') + '">' + esc(msg.status) + '</span></td>' +
        '<td style="white-space:nowrap;"><button type="button" class="mz-link" data-contact-open="' + msg.id + '">Open</button></td>' +
      '</tr>';
    }).join('');
    var supportEmail = payload.support_email || 'hello@mzigo.online';
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:10px;">' +
        '<div class="mz-card__title" style="margin:0;">Contact inbox</div>' +
        '<a class="mz-link" href="mailto:' + esc(supportEmail) + '">' + esc(supportEmail) + '</a>' +
      '</div>' +
      '<div class="mz-contact-chips">' + chips + '</div>' +
      '<div class="mz-table-wrap" role="region" aria-label="Contact messages" tabindex="0"><table class="mz-table"><thead><tr>' +
        '<th>From</th><th>Topic</th><th>Received</th><th>Status</th><th></th></tr></thead><tbody>' +
        (body || '<tr><td colspan="5" class="mz-hint">No messages yet — submissions from the /contact page land here.</td></tr>') +
      '</tbody></table></div>';
  }

  function openContactMessage(id) {
    var msg = contactMessageById(id);
    if (!msg) return;

    /* New messages become read as soon as the admin opens them. */
    if (msg.status === 'new') {
      msg.status = 'read';
      api('admin.php', { method: 'POST', body: {
        action: 'contact_status', contact_id: msg.id, status: 'read', admin_note: msg.admin_note || ''
      }}).then(function (res) {
        adminContactMessages = res.contact_messages || adminContactMessages;
        var card = $('[data-admin-messages]');
        if (card) renderAdminMessages(card, adminContactMessages);
      }).catch(function () {});
    }

    var statuses = [['new', 'New'], ['read', 'Read'], ['replied', 'Replied'], ['archived', 'Archived']];
    var notes = msg.notes || [];
    var replies = msg.replies || [];
    var availableChannels = (adminContactMessages.channels || []).filter(function (ch) {
      return ch.code === 'email' ? !!msg.email : !!msg.phone;
    });
    var notesBody = notes.length
      ? notes.map(function (n) {
          return '<div class="mz-list-card" style="margin-bottom:8px;padding:10px 12px;">' +
            '<div style="font-size:13px;white-space:pre-line;">' + esc(n.note) + '</div>' +
            '<div class="mz-hint">' + esc(n.admin_name || 'Admin') + ' · ' + esc(fmtDate(n.created_at)) +
              ' · <button type="button" class="mz-link" data-contact-note-delete="' + n.id + '">Delete</button></div>' +
          '</div>';
        }).join('')
      : '<div class="mz-hint">No internal notes yet.</div>';
    var repliesBody = replies.length
      ? replies.map(function (r) {
          return '<div class="mz-list-card" style="margin-bottom:8px;padding:10px 12px;">' +
            '<div class="mz-row"><span class="mz-badge ' + (r.status === 'sent' ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + esc(r.channel) + ' · ' + esc(r.status) + '</span>' +
              '<span class="mz-hint">' + esc(r.recipient) + ' · ' + esc(fmtDate(r.created_at)) + '</span></div>' +
            '<div style="font-size:13px;margin-top:6px;white-space:pre-line;">' + esc(r.body) + '</div>' +
            (r.error ? '<div class="mz-hint">' + esc(r.error) + '</div>' : '') +
          '</div>';
        }).join('')
      : '<div class="mz-hint">No replies sent yet.</div>';
    var channelInputs = availableChannels.length
      ? availableChannels.map(function (ch, i) {
          var recipient = ch.code === 'email' ? msg.email : (msg.phone || '');
          return '<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:4px 0;">' +
            '<input type="checkbox" data-contact-channel value="' + esc(ch.code) + '"' + (i === 0 ? ' checked' : '') + '> ' +
            esc(ch.label) + ' <span class="mz-hint">(' + esc(recipient) + ')</span></label>';
        }).join('')
      : '<div class="mz-hint">No active channels configured. Enable Email/SMS/WhatsApp in Communications.</div>';

    var dialog = openDialog({
      title: msg.subject,
      sub: 'From ' + msg.name + ' · ' + msg.email + ' · ' + fmtDate(msg.created_at),
      submitLabel: 'Save status',
      wide: true,
      body:
        '<div class="mz-contact-msg">' + esc(msg.message) + '</div>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Status</span><select class="mz-input" name="status">' +
            statuses.map(function (pair) {
              return '<option value="' + pair[0] + '"' + (msg.status === pair[0] ? ' selected' : '') + '>' + pair[1] + '</option>';
            }).join('') +
          '</select></label>' +
          '<label class="mz-field"><span class="mz-label">Phone</span><input class="mz-input" readonly value="' + esc(msg.phone || '—') + '"></label>' +
        '</div>' +
        '<div class="mz-card__title" style="font-size:15px;margin:14px 0 8px;">Internal notes</div>' +
        '<div data-contact-notes>' + notesBody + '</div>' +
        '<label class="mz-field" style="margin-top:8px;"><span class="mz-label">Add internal note (multiple allowed)</span>' +
          '<textarea class="mz-input" data-contact-note-input rows="3" maxlength="2000" placeholder="Visible to admins only — e.g. called the customer, resend tracking"></textarea></label>' +
        '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-contact-note-add>Add note</button>' +
        '<div class="mz-card__title" style="font-size:15px;margin:18px 0 8px;">Direct reply</div>' +
        '<div data-contact-channels>' + channelInputs + '</div>' +
        '<label class="mz-field" style="margin-top:8px;"><span class="mz-label">Reply message</span>' +
          '<textarea class="mz-input" data-contact-reply-body rows="4" maxlength="4000" placeholder="Write the reply that will be sent to the customer through the selected channel(s)."></textarea></label>' +
        '<button type="button" class="mz-btn mz-btn--sm" data-contact-reply-send>Send reply</button>' +
        '<div class="mz-hint" style="margin-top:6px;">Replies use the configured notification channels (Admin → Communications).</div>' +
        '<div class="mz-card__title" style="font-size:15px;margin:18px 0 8px;">Reply history</div>' +
        '<div data-contact-replies>' + repliesBody + '</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">' +
          '<a class="mz-btn mz-btn--outline mz-btn--sm" href="mailto:' + esc(msg.email) + '?subject=' + encodeURIComponent('Re: ' + msg.subject) + '">Open mail client</a>' +
          '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-contact-delete="' + msg.id + '">Delete message</button>' +
        '</div>',
      onSubmit: function (form) {
        return api('admin.php', { method: 'POST', body: {
          action: 'contact_status',
          contact_id: msg.id,
          status: formValue(form, 'status'),
          admin_note: msg.admin_note || ''
        }}).then(function (res) {
          adminContactMessages = res.contact_messages || adminContactMessages;
          toast('Message updated.');
          loadAdmin();
        });
      }
    });

    function refreshThread(response) {
      if (response && response.contact_messages) adminContactMessages = response.contact_messages;
      var card = $('[data-admin-messages]');
      if (card) renderAdminMessages(card, adminContactMessages);
      dialog.close();
      setTimeout(function () { openContactMessage(msg.id); }, 30);
    }

    var noteAdd = dialog.overlay.querySelector('[data-contact-note-add]');
    if (noteAdd) noteAdd.addEventListener('click', function () {
      var input = dialog.overlay.querySelector('[data-contact-note-input]');
      var note = input ? input.value.trim() : '';
      if (note.length < 2) { toast('Write an internal note first.', 'error'); return; }
      noteAdd.disabled = true;
      api('admin.php', { method: 'POST', body: { action: 'contact_note_add', contact_id: msg.id, note: note } })
        .then(function (res) { toast('Internal note added.'); refreshThread(res); })
        .catch(function (err) { toast(err.message, 'error'); noteAdd.disabled = false; });
    });

    Array.prototype.forEach.call(dialog.overlay.querySelectorAll('[data-contact-note-delete]'), function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        api('admin.php', { method: 'POST', body: { action: 'contact_note_delete', note_id: Number(btn.getAttribute('data-contact-note-delete')) } })
          .then(function (res) { toast('Note deleted.'); refreshThread(res); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
    });

    var replySend = dialog.overlay.querySelector('[data-contact-reply-send]');
    if (replySend) replySend.addEventListener('click', function () {
      var bodyEl = dialog.overlay.querySelector('[data-contact-reply-body]');
      var body = bodyEl ? bodyEl.value.trim() : '';
      if (body.length < 2) { toast('Write a reply first.', 'error'); return; }
      var channels = Array.prototype.map.call(dialog.overlay.querySelectorAll('[data-contact-channel]:checked'), function (el) { return el.value; });
      if (!channels.length) { toast('Choose at least one channel.', 'error'); return; }
      replySend.disabled = true;
      api('admin.php', { method: 'POST', body: { action: 'contact_reply', contact_id: msg.id, body: body, channels: channels } })
        .then(function (res) { toast('Reply sent.'); refreshThread(res); })
        .catch(function (err) { toast(err.message, 'error'); replySend.disabled = false; });
    });

    var del = dialog.overlay.querySelector('[data-contact-delete]');
    if (del) del.addEventListener('click', function () {
      if (!window.confirm('Delete this message permanently?')) return;
      del.disabled = true;
      api('admin.php', { method: 'POST', body: { action: 'contact_delete', contact_id: msg.id } }).then(function (res) {
        adminContactMessages = res.contact_messages || adminContactMessages;
        dialog.close();
        toast('Message deleted.');
        loadAdmin();
      }).catch(function (err) {
        toast(err.message, 'error');
        del.disabled = false;
      });
    });
  }

  var adminFaqs = [];
  var adminFaqsPage = 1;

  function renderAdminNewsletter(card, data) {
    if (!card) return;
    var cfg = data.config || {};
    var channels = data.channels || [];
    var counts = data.counts || {};
    var recent = data.recent || [];
    var triggers = cfg.triggers || {};
    var check = function (key) { return triggers[key] ? ' checked' : ''; };
    var channelChecks = channels.map(function (c) {
      var selected = (cfg.channels || []).indexOf(c.code) !== -1;
      return '<label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-right:14px;">' +
        '<input type="checkbox" data-nl-channel="' + esc(c.code) + '"' + (selected ? ' checked' : '') + '>' +
        esc(c.label) + (c.active ? '' : ' <span class="mz-hint">(inactive)</span>') + '</label>';
    }).join('');
    var recentRows = recent.map(function (r) {
      var badge = r.status === 'confirmed' ? 'mz-badge--ok' : (r.status === 'pending' ? 'mz-badge--warn' : '');
      return '<tr><td>' + esc(r.email || r.phone || '') + '</td><td>' + esc(r.channel) + '</td>' +
        '<td><span class="mz-badge ' + badge + '">' + esc(r.status) + '</span></td>' +
        '<td>' + esc(r.trigger_source || r.source || '') + '</td>' +
        '<td style="white-space:nowrap;">' + esc(fmtDate(r.created_at)) + '</td></tr>';
    }).join('') || '<tr><td colspan="5" class="mz-hint">No subscribers yet.</td></tr>';
    card.innerHTML =
      '<div class="mz-row"><div><div class="mz-card__title" style="margin:0;">Newsletter &amp; alerts prompt</div>' +
        '<p class="mz-hint" style="margin:4px 0 0;">Opt-in prompt for the public site. Disabled by default; subscribers use double opt-in.</p></div>' +
        '<span class="mz-badge">' + (counts.confirmed || 0) + ' confirmed</span></div>' +
      '<label style="display:flex;align-items:center;gap:8px;margin:12px 0;"><input type="checkbox" data-nl-enabled' + (cfg.enabled ? ' checked' : '') + '> Enable prompt on public pages</label>' +
      '<div class="mz-form-2col">' +
        '<label class="mz-field"><span class="mz-label">Pilot traffic (%)</span><input class="mz-input" type="number" min="1" max="100" data-nl="pilot_percent" value="' + Number(cfg.pilot_percent || 10) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Allowed pages (comma separated, / = home)</span><input class="mz-input" data-nl="pages" value="' + esc((cfg.pages || ['/']).join(', ')) + '"></label>' +
      '</div>' +
      '<div class="mz-field" style="margin-top:10px;"><span class="mz-label">Channels</span><div style="display:flex;flex-wrap:wrap;gap:4px;">' + channelChecks + '</div></div>' +
      '<div class="mz-form-2col" style="margin-top:10px;">' +
        '<label class="mz-field"><span class="mz-label">Idle seconds</span><input class="mz-input" type="number" data-nl="idle_seconds" value="' + Number(cfg.idle_seconds || 20) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Minimum dwell (seconds)</span><input class="mz-input" type="number" data-nl="min_dwell_seconds" value="' + Number(cfg.min_dwell_seconds || 15) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Active seconds</span><input class="mz-input" type="number" data-nl="active_seconds" value="' + Number(cfg.active_seconds || 45) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Scroll depth (%)</span><input class="mz-input" type="number" data-nl="scroll_pct" value="' + Number(cfg.scroll_pct || 60) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Page views for active</span><input class="mz-input" type="number" data-nl="active_pageviews" value="' + Number(cfg.active_pageviews || 3) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Abandoned task delay (seconds)</span><input class="mz-input" type="number" data-nl="abandoned_seconds" value="' + Number(cfg.abandoned_seconds || 30) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Frequency cap (days)</span><input class="mz-input" type="number" data-nl="frequency_days" value="' + Number(cfg.frequency_days || 7) + '"></label>' +
        '<label class="mz-field"><span class="mz-label">Dismiss suppression (days)</span><input class="mz-input" type="number" data-nl="dismiss_days" value="' + Number(cfg.dismiss_days || 60) + '"></label>' +
      '</div>' +
      '<div class="mz-field" style="margin-top:10px;"><span class="mz-label">Prompt artwork</span>' +
        '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:6px;">' +
          (cfg.artwork_url ? '<img src="' + esc(cfg.artwork_url) + '" alt="Newsletter artwork" style="max-height:90px;max-width:220px;object-fit:contain;border:1px solid var(--mz-border);border-radius:10px;background:#fff;padding:4px;">' : '<span class="mz-hint">No artwork uploaded. Add a PNG, JPG or WebP (300 x 160 to 3000 x 3000).</span>') +
          '<input class="mz-input" type="file" accept="image/png,image/jpeg,image/webp" data-nl-artwork style="max-width:260px;padding:8px;">' +
          '<button type="button" class="mz-btn mz-btn--sm" data-nl-artwork-upload>Upload artwork</button>' +
          (cfg.artwork_url ? '<button type="button" class="mz-link" data-nl-artwork-remove>Remove</button>' : '') +
        '</div><p class="mz-hint" style="margin:6px 0 0;">Used in the prompt and confirmation email.</p></div>' +
      '<div class="mz-field" style="margin-top:10px;"><span class="mz-label">Triggers</span><div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12px;">' +
        '<label><input type="checkbox" data-nl-trigger="idle"' + check('idle') + '> Idle</label>' +
        '<label><input type="checkbox" data-nl-trigger="active"' + check('active') + '> Active</label>' +
        '<label><input type="checkbox" data-nl-trigger="abandoned"' + check('abandoned') + '> Abandoned task</label>' +
        '<label><input type="checkbox" data-nl-trigger="exit"' + check('exit') + '> Exit intent</label>' +
      '</div></div>' +
      '<div class="mz-form-2col" style="margin-top:10px;">' +
        '<label class="mz-field"><span class="mz-label">Title</span><input class="mz-input" data-nl="title" value="' + esc(cfg.title || '') + '"></label>' +
        '<label class="mz-field"><span class="mz-label">CTA label</span><input class="mz-input" data-nl="cta" value="' + esc(cfg.cta || '') + '"></label>' +
        '<label class="mz-field" style="grid-column:1/-1;"><span class="mz-label">Body</span><textarea class="mz-input" rows="2" data-nl="body">' + esc(cfg.body || '') + '</textarea></label>' +
        '<label class="mz-field" style="grid-column:1/-1;"><span class="mz-label">Consent label</span><textarea class="mz-input" rows="2" data-nl="consent_label">' + esc(cfg.consent_label || '') + '</textarea></label>' +
        '<label class="mz-field"><span class="mz-label">Lead magnet URL (optional)</span><input class="mz-input" data-nl="lead_magnet_url" value="' + esc(cfg.lead_magnet_url || '') + '" placeholder="/blog/guide or https://..."></label>' +
        '<label class="mz-field"><span class="mz-label">Lead magnet button label</span><input class="mz-input" data-nl="lead_magnet_label" value="' + esc(cfg.lead_magnet_label || '') + '"></label>' +
      '</div>' +
      '<div class="mz-row" style="margin-top:10px;justify-content:flex-start;gap:8px;flex-wrap:wrap;"><button type="button" class="mz-btn mz-btn--sm" data-nl-save>Save prompt</button>' +
        '<a class="mz-btn mz-btn--outline mz-btn--sm" href="/?newsletter_preview=1" target="_blank" rel="noopener">Preview prompt</a>' +
        '<a class="mz-btn mz-btn--outline mz-btn--sm" href="/api/admin-newsletter-export.php">Export subscribers</a></div>' +
      '<div class="mz-hint" style="margin-top:8px;">' + (counts.pending || 0) + ' pending · ' + (counts.confirmed || 0) + ' confirmed · ' + (counts.unsubscribed || 0) + ' unsubscribed</div>' +
      '<div class="mz-table-wrap" style="margin-top:12px;"><table class="mz-table"><thead><tr><th>Contact</th><th>Channel</th><th>Status</th><th>Trigger</th><th>Created</th></tr></thead><tbody>' + recentRows + '</tbody></table></div>';

    var artUpload = card.querySelector('[data-nl-artwork-upload]');
    if (artUpload) artUpload.addEventListener('click', function () {
      var input = card.querySelector('[data-nl-artwork]');
      var file = input && input.files && input.files[0];
      if (!file) { toast('Choose an artwork file first.', 'error'); return; }
      artUpload.disabled = true;
      var fd = new FormData();
      fd.append('action', 'upload_newsletter_artwork');
      fd.append('artwork', file);
      fetch('/api/admin.php', { method: 'POST', credentials: 'same-origin', headers: APP.csrf ? { 'X-CSRF-Token': APP.csrf } : {}, body: fd })
        .then(function (res) { return res.json().then(function (data) { if (!res.ok || !data.ok) throw new Error((data.error && data.error.message) || 'Upload failed.'); return data; }); })
        .then(function (res) { toast('Artwork uploaded.'); renderAdminNewsletter(card, res.newsletter_prompt || {}); })
        .catch(function (err) { toast(err.message, 'error'); artUpload.disabled = false; });
    });
    var artRemove = card.querySelector('[data-nl-artwork-remove]');
    if (artRemove) artRemove.addEventListener('click', function () {
      if (!window.confirm('Remove the newsletter artwork?')) return;
      api('admin.php', { method: 'POST', body: { action: 'remove_newsletter_artwork' } })
        .then(function (res) { toast('Artwork removed.'); renderAdminNewsletter(card, res.newsletter_prompt || {}); })
        .catch(function (err) { toast(err.message, 'error'); });
    });

    var save = card.querySelector('[data-nl-save]');
    if (!save) return;
    save.addEventListener('click', function () {
      save.disabled = true;
      var val = function (key) { var el = card.querySelector('[data-nl="' + key + '"]'); return el ? el.value : ''; };
      var selected = [];
      card.querySelectorAll('[data-nl-channel]:checked').forEach(function (el) { selected.push(el.getAttribute('data-nl-channel')); });
      var triggers = {};
      card.querySelectorAll('[data-nl-trigger]').forEach(function (el) { triggers[el.getAttribute('data-nl-trigger')] = el.checked; });
      var config = {
        enabled: card.querySelector('[data-nl-enabled]').checked,
        pilot_percent: Number(val('pilot_percent') || 10),
        pages: String(val('pages') || '/').split(',').map(function (p) { return p.trim(); }).filter(Boolean),
        channels: selected,
        triggers: triggers,
        idle_seconds: Number(val('idle_seconds') || 20),
        min_dwell_seconds: Number(val('min_dwell_seconds') || 15),
        active_seconds: Number(val('active_seconds') || 45),
        scroll_pct: Number(val('scroll_pct') || 60),
        active_pageviews: Number(val('active_pageviews') || 3),
        abandoned_seconds: Number(val('abandoned_seconds') || 30),
        frequency_days: Number(val('frequency_days') || 7),
        dismiss_days: Number(val('dismiss_days') || 60),
        title: val('title'), body: val('body'), cta: val('cta'),
        consent_label: val('consent_label'),
        lead_magnet_url: val('lead_magnet_url'),
        lead_magnet_label: val('lead_magnet_label')
      };
      api('admin.php', { method: 'POST', body: { action: 'save_newsletter_prompt', config: config } })
        .then(function (res) { toast('Newsletter prompt saved.'); renderAdminNewsletter(card, res.newsletter_prompt || {}); })
        .catch(function (err) { toast(err.message, 'error'); save.disabled = false; });
    });
  }

  function renderAdminSocialLinks(card, branding) {
    if (!card) return;
    var catalog = branding.social_catalog || {};
    var values = branding.social_values || {};
    var fields = Object.keys(catalog).map(function (key) {
      return '<label class="mz-field"><span class="mz-label">' + esc(catalog[key].label) + '</span>' +
        '<input class="mz-input" data-social-input="' + esc(key) + '" maxlength="255" value="' + esc(values[key] || '') + '" placeholder="' + esc(catalog[key].placeholder || '') + '"></label>';
    }).join('');
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:10px;"><div><div class="mz-card__title" style="margin:0;">Social media links</div>' +
      '<div class="mz-hint">Shown as icons in the website footer. Enter a full URL or just the handle/ID — the platform builds the right link. Leave a field blank to hide that icon.</div></div></div>' +
      '<form class="mz-stack" data-social-form><div class="mz-form-2col">' + fields + '</div>' +
      '<div style="display:flex;align-items:center;gap:10px;"><button class="mz-btn" type="submit">Save social links</button><span class="mz-hint">Footer updates after saving.</span></div></form>';
    var form = card.querySelector('[data-social-form]');
    if (form) form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('[type="submit"]'); btn.disabled = true;
      var payload = { action: 'save_social_links' };
      form.querySelectorAll('[data-social-input]').forEach(function (input) { payload[input.getAttribute('data-social-input')] = input.value.trim(); });
      api('admin.php', { method: 'POST', body: payload })
        .then(function (res) { toast('Social links saved.'); loadAdmin(); })
        .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
    });
  }

  function renderAdminSeo(card, seo, vitals) {
    if (!card) return;
    seo = seo || {}; vitals = vitals || {};
    var metricRows = (vitals.metrics || []).map(function (m) {
      return '<tr><td>' + esc(m.metric) + '</td><td><strong>' + esc(String(m.p75)) + '</strong></td><td>' + esc(String(m.samples)) + '</td></tr>';
    }).join('');
    card.innerHTML =
      '<div class="mz-card__title" style="margin:0 0 6px;">Search &amp; Core Web Vitals</div>' +
      '<p class="mz-hint">Search Console / Bing verification and real-user CWV. Verification meta tags are emitted on the next page build/deploy.</p>' +
      '<div class="mz-form-2col">' +
        '<label class="mz-field"><span class="mz-label">Google Search Console token</span><input class="mz-input" data-seo-field="search_console_token" maxlength="255" value="' + esc(seo.search_console_token || '') + '" placeholder="google-site-verification token"></label>' +
        '<label class="mz-field"><span class="mz-label">Bing verification token</span><input class="mz-input" data-seo-field="bing_token" maxlength="255" value="' + esc(seo.bing_token || '') + '" placeholder="msvalidate.01 value"></label>' +
      '</div>' +
      '<label class="mz-field"><span class="mz-label">CrUX/PageSpeed API key (optional)</span><input class="mz-input" type="password" data-seo-field="cwv_api_key" maxlength="255" value="' + esc(seo.cwv_api_key || '') + '" placeholder="For external field-data checks"></label>' +
      '<div class="mz-row" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;"><button type="button" class="mz-btn" data-seo-save>Save SEO settings</button><span class="mz-hint">' + esc(String(vitals.samples || 0)) + ' CWV samples in the last 7 days</span></div>' +
      '<div class="mz-card__title" style="font-size:14px;margin-top:16px;">Real-user Core Web Vitals (p75, 7 days)</div>' +
      '<div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Metric</th><th>p75</th><th>Samples</th></tr></thead><tbody>' +
        (metricRows || '<tr><td colspan="3" class="mz-hint">No real-user vitals collected yet.</td></tr>') + '</tbody></table></div>';
    var save = card.querySelector('[data-seo-save]');
    if (save) save.addEventListener('click', function () {
      save.disabled = true;
      var body = { action: 'save_seo_settings' };
      card.querySelectorAll('[data-seo-field]').forEach(function (el) { body[el.getAttribute('data-seo-field')] = el.value.trim(); });
      api('admin.php', { method: 'POST', body: body })
        .then(function (res) { toast('SEO settings saved.'); renderAdminSeo(card, res.seo || {}, res.web_vitals || {}); })
        .catch(function (err) { toast(err.message, 'error'); save.disabled = false; });
    });
  }

  function renderAdminBranding(card, branding) {
    if (!card) return;
    branding = branding || {};
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:12px;">' +
        '<div class="mz-card__title" style="margin:0;">Brand assets</div>' +
        '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-brand-reset>Restore defaults</button>' +
      '</div>' +
      '<div class="mz-brand-grid">' +
        '<div class="mz-brand-row">' +
          '<div class="mz-brand-preview"><img src="' + esc(branding.logo_url || '/assets/img/mzigo-logo.png') + '" alt="Logo preview"></div>' +
          '<div class="mz-brand-body">' +
            '<div class="mz-label">Site logo</div>' +
            '<div class="mz-hint">PNG, JPG or WebP · up to 2 MB · any shape is fitted into the wide header/footer slot automatically.</div>' +
            '<div class="mz-brand-actions">' +
              '<input class="mz-input mz-brand-file" type="file" accept=".png,.jpg,.jpeg,.webp" data-brand-file="logo">' +
              '<button type="button" class="mz-btn" data-brand-upload="logo">Upload logo</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="mz-brand-row">' +
          '<div class="mz-brand-preview mz-brand-preview--favicon"><img src="' + esc(branding.favicon_url || '/assets/img/favicon.ico') + '" alt="Favicon preview"></div>' +
          '<div class="mz-brand-body">' +
            '<div class="mz-label">Favicon</div>' +
            '<div class="mz-hint">ICO or PNG · 32×32 or 64×64 · up to 2 MB. Shown in browser tabs and bookmarks.</div>' +
            '<div class="mz-brand-actions">' +
              '<input class="mz-input mz-brand-file" type="file" accept=".ico,.png" data-brand-file="favicon">' +
              '<button type="button" class="mz-btn" data-brand-upload="favicon">Upload favicon</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="mz-brand-row">' +
          '<div class="mz-brand-preview mz-brand-preview--hero"><img src="' + esc(branding.hero_url || '/assets/img/delivery.jpg') + '" alt="Hero photo preview"></div>' +
          '<div class="mz-brand-body">' +
            '<div class="mz-label">Hero photo</div>' +
            '<div class="mz-hint">JPG, PNG or WebP · up to 2 MB · landscape 16:9 works best (e.g. 1920×1080). Shown in the landing-page hero.</div>' +
            '<div class="mz-brand-actions">' +
              '<input class="mz-input mz-brand-file" type="file" accept=".png,.jpg,.jpeg,.webp" data-brand-file="hero">' +
              '<button type="button" class="mz-btn" data-brand-upload="hero">Upload hero photo</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  function renderAdminHeroCopy(card, branding) {
    if (!card) return;
    branding = branding || {};
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:12px;">' +
        '<div class="mz-card__title" style="margin:0;">Landing page copy</div>' +
        '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-hero-copy-reset>Restore defaults</button>' +
      '</div>' +
      '<p class="mz-hint" style="margin:0 0 12px;">The headline and subtitle shown in the landing-page hero. The subtitle is also used as the footer brand tagline — changes go live as soon as you save.</p>' +
      '<div class="mz-form-2col">' +
        '<label class="mz-field"><span class="mz-label">Headline</span>' +
          '<input class="mz-input" data-hero-copy-title maxlength="80" value="' + esc(branding.hero_title || '') + '" placeholder="Send or Track Mzigo"></label>' +
        '<label class="mz-field"><span class="mz-label">Subtitle</span>' +
          '<input class="mz-input" data-hero-copy-subtitle maxlength="140" value="' + esc(branding.hero_subtitle || '') + '" placeholder="Your trusted package delivery and tracking solution"></label>' +
      '</div>' +
      '<div class="mz-row" style="gap:10px;margin-top:2px;">' +
        '<button type="button" class="mz-btn" data-hero-copy-save>Save copy</button>' +
        '<span class="mz-hint" style="margin:0;">' + (branding.custom_hero_copy ? 'Custom copy is live.' : 'Currently using the default copy.') + '</span>' +
      '</div>';
  }

  function renderAdminFaqs(card, faqs) {
    if (!card) return;
    adminFaqs = faqs || [];
    var perPage = 6;
    var total = adminFaqs.length;
    var pageCount = Math.max(1, Math.ceil(total / perPage));
    adminFaqsPage = Math.min(Math.max(1, adminFaqsPage), pageCount);
    var start = (adminFaqsPage - 1) * perPage;
    var visible = adminFaqs.slice(start, start + perPage);
    var rows = visible.map(function (faq) {
      var excerpt = faq.answer.length > 130 ? faq.answer.slice(0, 127) + '…' : faq.answer;
      return '<tr>' +
        '<td style="max-width:420px;"><div style="font-weight:600;">' + esc(faq.question) + '</div>' +
          '<div class="mz-hint">' + esc(excerpt) + '</div></td>' +
        '<td>' + faq.sort_order + '</td>' +
        '<td><span class="mz-badge ' + (faq.is_active ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (faq.is_active ? 'Live' : 'Hidden') + '</span></td>' +
        '<td style="white-space:nowrap;">' +
          '<button type="button" class="mz-link" data-faq-edit="' + faq.id + '">Edit</button> · ' +
          '<button type="button" class="mz-link" data-faq-delete="' + faq.id + '">Delete</button>' +
        '</td></tr>';
    }).join('');
    var from = total === 0 ? 0 : start + 1;
    var to = Math.min(start + perPage, total);
    var pager = '<div class="mz-pager" style="margin-top:12px;">' +
      '<button type="button" class="mz-tab" data-faq-page="' + (adminFaqsPage - 1) + '"' + (adminFaqsPage <= 1 ? ' disabled' : '') + '>← Previous</button>' +
      '<span class="mz-pager__label">Showing ' + from + '–' + to + ' of ' + total + ' · Page ' + adminFaqsPage + ' of ' + pageCount + '</span>' +
      '<button type="button" class="mz-tab" data-faq-page="' + (adminFaqsPage + 1) + '"' + (adminFaqsPage >= pageCount ? ' disabled' : '') + '>Next →</button>' +
    '</div>';
    card.innerHTML =
      '<div class="mz-row" style="margin-bottom:10px;">' +
        '<div class="mz-card__title" style="margin:0;">Landing-page FAQs</div>' +
        '<button type="button" class="mz-btn mz-btn--sm" data-faq-add>+ Add FAQ</button>' +
      '</div>' +
      '<div class="mz-table-wrap" role="region" aria-label="FAQ table" tabindex="0"><table class="mz-table"><thead><tr>' +
        '<th>Question &amp; answer</th><th>Order</th><th>Status</th><th></th></tr></thead><tbody>' +
        (rows || '<tr><td colspan="4" class="mz-hint">No FAQs yet — add the first one.</td></tr>') +
      '</tbody></table></div>' + pager;
    Array.prototype.forEach.call(card.querySelectorAll('[data-faq-page]'), function (btn) {
      btn.addEventListener('click', function () {
        adminFaqsPage = Number(btn.getAttribute('data-faq-page')) || 1;
        renderAdminFaqs(card, adminFaqs);
      });
    });
  }

  function faqById(id) {
    for (var i = 0; i < adminFaqs.length; i++) {
      if (String(adminFaqs[i].id) === String(id)) return adminFaqs[i];
    }
    return null;
  }

  function openFaqDialog(faq) {
    var isNew = !faq;
    var nextOrder = 1;
    adminFaqs.forEach(function (item) { nextOrder = Math.max(nextOrder, item.sort_order + 1); });
    var f = faq || { id: 0, question: '', answer: '', sort_order: nextOrder, is_active: true };
    openDialog({
      title: isNew ? 'Add FAQ' : 'Edit FAQ',
      sub: 'Changes appear on the landing page as soon as they are saved.',
      submitLabel: isNew ? 'Add FAQ' : 'Save FAQ',
      wide: true,
      body:
        '<label class="mz-field"><span class="mz-label">Question *</span>' +
          '<input class="mz-input" name="question" maxlength="200" value="' + esc(f.question) + '" placeholder="e.g. How do I send a parcel?"></label>' +
        '<label class="mz-field"><span class="mz-label">Answer *</span>' +
          '<textarea class="mz-input" name="answer" rows="5" maxlength="2000" placeholder="Keep it short and helpful.">' + esc(f.answer) + '</textarea></label>' +
        '<div class="mz-form-2col">' +
          '<label class="mz-field"><span class="mz-label">Sort order</span>' +
            '<input class="mz-input" type="number" name="sort_order" min="0" max="999" value="' + f.sort_order + '"></label>' +
          '<label class="mz-field"><span class="mz-label">Visibility</span>' +
            '<select class="mz-input" name="is_active">' +
              '<option value="1"' + (f.is_active ? ' selected' : '') + '>Live on the website</option>' +
              '<option value="0"' + (!f.is_active ? ' selected' : '') + '>Hidden</option>' +
            '</select></label>' +
        '</div>',
      onSubmit: function (form) {
        var question = formValue(form, 'question');
        var answer = formValue(form, 'answer');
        if (question.length < 3) throw new Error('Enter a question (at least 3 characters).');
        if (answer.length < 5) throw new Error('Enter an answer (at least 5 characters).');
        return api('admin.php', { method: 'POST', body: {
          action: 'faq_save',
          faq_id: f.id || 0,
          question: question,
          answer: answer,
          sort_order: Number(formValue(form, 'sort_order') || 0),
          is_active: formValue(form, 'is_active') !== '0'
        }}).then(function () {
          toast(isNew ? 'FAQ added.' : 'FAQ saved.');
          loadAdmin();
        });
      }
    });
  }

  function uploadBrandAsset(kind) {
    var input = $('[data-brand-file="' + kind + '"]');
    var file = input && input.files && input.files[0];
    if (!file) {
      toast('Choose a ' + kind + ' file first.', 'error');
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast('Files must be smaller than 2 MB.', 'error');
      return;
    }
    var button = $('[data-brand-upload="' + kind + '"]');
    var labels = { logo: 'Upload logo', favicon: 'Upload favicon', hero: 'Upload hero photo' };
    var doneLabels = { logo: 'Logo updated.', favicon: 'Favicon updated.', hero: 'Hero photo updated.' };
    var label = labels[kind] || 'Upload';
    if (button) { button.disabled = true; button.textContent = 'Uploading…'; }

    var fd = new FormData();
    fd.append('action', 'branding_upload');
    fd.append('kind', kind);
    fd.append('file', file);

    fetch('/api/admin.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: APP.csrf ? { 'X-CSRF-Token': APP.csrf } : {},
      body: fd
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('Unexpected response (' + res.status + ').');
      }).then(function (data) {
        if (data && data.csrf) APP.csrf = data.csrf;
        if (!res.ok || data.ok === false) {
          throw new Error((data.error && data.error.message) || 'Upload failed.');
        }
        return data;
      });
    }).then(function () {
      toast(doneLabels[kind] || 'Asset updated.');
      loadAdmin();
    }).catch(function (err) {
      toast(err.message, 'error');
      if (button) { button.disabled = false; button.textContent = label; }
    });
  }

  function initAdminInsurance(host) {
    var mount = host.querySelector('[data-admin-insurance]');
    if (!mount) return;
    function field(cfg, key, label, type, placeholder, hint) {
      return '<label class="mz-field"><span class="mz-label">' + label + '</span>' +
        '<input class="mz-input" data-insurance-field="' + key + '" type="' + (type || 'text') + '" value="' + esc(cfg[key] || '') + '" placeholder="' + esc(placeholder || '') + '">' +
        (hint ? '<span class="mz-hint">' + hint + '</span>' : '') + '</label>';
    }
    var insurancePolicyPage = 1;
    var insurancePolicyData = [];
    function renderPolicyTable() {
      var perPage = 6;
      var total = insurancePolicyData.length;
      var pageCount = Math.max(1, Math.ceil(total / perPage));
      insurancePolicyPage = Math.min(Math.max(1, insurancePolicyPage), pageCount);
      var start = (insurancePolicyPage - 1) * perPage;
      var rows = insurancePolicyData.slice(start, start + perPage).map(function (p) {
        return '<tr><td class="mz-mono">' + esc(p.tracking) + '</td><td>' + money(p.value_kes) + '</td>' +
          '<td>' + esc(p.insurance_policy_no || '—') + '</td>' +
          '<td><span class="mz-badge ' + (p.insurance_status === 'issued' ? 'mz-badge--ok' : p.insurance_status === 'failed' ? 'mz-badge--warn' : '') + '">' + esc(p.insurance_status) + '</span></td>' +
          '<td>' + esc(p.insurance_provider || '') + '</td><td>' + esc(fmtDate(p.insurance_issued_at)) + '</td></tr>';
      }).join('') || '<tr><td colspan="6" class="mz-hint">No insured parcels yet.</td></tr>';
      var body = mount.querySelector('[data-insurance-policy-body]');
      var pager = mount.querySelector('[data-insurance-pager]');
      if (body) body.innerHTML = rows;
      if (!pager) return;
      var from = total === 0 ? 0 : start + 1;
      var to = Math.min(start + perPage, total);
      pager.innerHTML = '<div class="mz-pager" style="margin-top:12px;">' +
        '<button type="button" class="mz-tab" data-insurance-page="' + (insurancePolicyPage - 1) + '"' + (insurancePolicyPage <= 1 ? ' disabled' : '') + '>← Previous</button>' +
        '<span class="mz-pager__label">Showing ' + from + '–' + to + ' of ' + total + ' · Page ' + insurancePolicyPage + ' of ' + pageCount + '</span>' +
        '<button type="button" class="mz-tab" data-insurance-page="' + (insurancePolicyPage + 1) + '"' + (insurancePolicyPage >= pageCount ? ' disabled' : '') + '>Next →</button>' +
        '</div>';
      Array.prototype.forEach.call(pager.querySelectorAll('[data-insurance-page]'), function (btn) {
        btn.addEventListener('click', function () {
          insurancePolicyPage = Number(btn.getAttribute('data-insurance-page')) || 1;
          renderPolicyTable();
        });
      });
    }

    function load() {
      api('admin-insurance.php').then(function (data) {
        var cfg = data.config || {};
        var stats = data.stats || {};
        insurancePolicyData = data.policies || [];
        insurancePolicyPage = 1;
        mount.innerHTML =
          '<div class="mz-saas-card" style="margin-bottom:16px;">' +
            '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Insurer API</div><span class="mz-badge ' + (data.demo ? 'mz-badge--warn' : 'mz-badge--ok') + '">' + (data.demo ? 'Demo policies' : 'Live policies') + '</span></div>' +
            '<p class="mz-hint">' + (data.demo ? 'Demo mode issues demo policy numbers for testing. Configure live mode before claiming real cover.' : 'Live mode calls the insurer API and stores the real policy number on each parcel.') + '</p>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">Mode</span><select class="mz-input" data-insurance-field="mode"><option value="demo"' + (cfg.mode === 'demo' ? ' selected' : '') + '>Demo</option><option value="live"' + (cfg.mode === 'live' ? ' selected' : '') + '>Live insurer API</option></select></label>' +
              field(cfg, 'provider_name', 'Provider name', 'text', 'e.g. Jubilee Insurance') +
              field(cfg, 'api_base_url', 'API base URL', 'url', 'https://api.insurer.co.ke') +
              field(cfg, 'issue_path', 'Issue policy path', 'text', '/policies') +
              '<label class="mz-field"><span class="mz-label">Auth type</span><select class="mz-input" data-insurance-field="auth_type"><option value="bearer"' + (cfg.auth_type === 'bearer' ? ' selected' : '') + '>Bearer token</option><option value="basic"' + (cfg.auth_type === 'basic' ? ' selected' : '') + '>Basic auth</option><option value="api_key"' + (cfg.auth_type === 'api_key' ? ' selected' : '') + '>API key headers</option></select></label>' +
              field(cfg, 'api_key', 'API key / client ID', 'password', cfg.api_key_set ? 'Saved — leave blank to keep' : 'Enter API key') +
              field(cfg, 'api_secret', 'API secret', 'password', cfg.api_secret_set ? 'Saved — leave blank to keep' : 'Enter API secret') +
              field(cfg, 'product_code', 'Insurance product code', 'text', 'e.g. MZIGO_PARCEL_KE') +
              field(cfg, 'policy_number_path', 'Policy number JSON path', 'text', 'policy_number or data.policy_number') +
              field(cfg, 'timeout_seconds', 'Timeout (seconds)', 'number', '15') +
              field(cfg, 'terms_url', 'Insurance terms path', 'text', '/terms#risk-and-cover') +
              '<label class="mz-field" style="grid-column:1/-1;"><span class="mz-label"><input type="checkbox" data-insurance-field="require_policy"' + (cfg.require_policy ? ' checked' : '') + '> Block registration if the insurer cannot issue a policy</span><span class="mz-hint">Recommended for live mode: never tell a sender they are covered when no policy exists.</span></label>' +
            '</div>' +
            '<div class="mz-row" style="gap:8px;justify-content:flex-start;flex-wrap:wrap;margin-top:8px;">' +
              '<button type="button" class="mz-btn mz-btn--sm" data-insurance-save>Save insurance config</button>' +
              '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-insurance-test>Test connection</button>' +
              '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-insurance-retry>Retry pending policies</button>' +
            '</div>' +
            '<div class="mz-hint" data-insurance-result style="margin-top:8px;"></div>' +
          '</div>' +
          '<div class="mz-saas-card">' +
            '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Policy status</div><div class="mz-hint">Issued ' + (stats.issued || 0) + ' · pending ' + (stats.pending || 0) + ' · failed ' + (stats.failed || 0) + '</div></div>' +
            '<div class="mz-table-wrap" style="margin-top:10px;"><table class="mz-table"><thead><tr><th>Parcel</th><th>Declared value</th><th>Policy number</th><th>Status</th><th>Provider</th><th>Issued</th></tr></thead><tbody data-insurance-policy-body></tbody></table></div>' +
            '<div data-insurance-pager></div>' +
          '</div>';
        renderPolicyTable();
        function collect() {
          var config = {};
          mount.querySelectorAll('[data-insurance-field]').forEach(function (el) {
            var key = el.getAttribute('data-insurance-field');
            config[key] = el.type === 'checkbox' ? el.checked : el.value;
          });
          return config;
        }
        mount.querySelector('[data-insurance-save]').addEventListener('click', function () {
          api('admin-insurance.php', { method: 'POST', body: { action: 'save_config', config: collect() } })
            .then(function () { toast('Insurance configuration saved.'); load(); })
            .catch(function (err) { toast(err.message, 'error'); });
        });
        mount.querySelector('[data-insurance-test]').addEventListener('click', function (ev) {
          ev.currentTarget.disabled = true;
          api('admin-insurance.php', { method: 'POST', body: { action: 'test_connection' } })
            .then(function (res) { toast(res.message || 'Connection OK.'); })
            .catch(function (err) { toast(err.message, 'error'); ev.currentTarget.disabled = false; });
        });
        mount.querySelector('[data-insurance-retry]').addEventListener('click', function (ev) {
          ev.currentTarget.disabled = true;
          api('admin-insurance.php', { method: 'POST', body: { action: 'retry_pending', limit: 20 } })
            .then(function (res) { toast(res.message); load(); })
            .catch(function (err) { toast(err.message, 'error'); ev.currentTarget.disabled = false; });
        });
      }).catch(function (err) {
        mount.innerHTML = '<div class="mz-alert">Could not load insurance settings: ' + esc(err.message) + '</div>';
      });
    }
    load();
  }

  function initAdminKyc(host) {
    var mount = host.querySelector('[data-admin-kyc]');
    if (!mount) return;
    function load() {
      mount.innerHTML = '<div class="mz-hint">Loading KYC documents…</div>';
      api('admin-kyc.php').then(function (data) {
        var rows = data.pending || [];
        var body = rows.length ? rows.map(function (row) {
          return '<div class="mz-list-card" style="margin-bottom:10px;">' +
            '<div class="mz-row"><div style="min-width:0;">' +
              '<div style="font-weight:700;">' + esc(row.user_name) + ' · ' + esc(row.doc_type) + '</div>' +
              '<div class="mz-hint">' + (row.subject_type === 'merchant' ? 'Merchant: ' + esc(row.tenant_name || row.subject_id) : 'Runner') +
                ' · ' + esc(row.user_phone) + ' · ' + esc(fmtDate(row.created_at)) + '</div>' +
            '</div><span class="mz-badge mz-badge--warn">' + esc(row.status) + '</span></div>' +
            (row.subject_type === 'merchant'
              ? '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--mz-border);">' +
                  (row.tenant_logo_url ? '<img src="' + esc(row.tenant_logo_url) + '" alt="Workspace logo" style="height:44px;max-width:150px;object-fit:contain;background:#fff;border:1px solid rgba(60,82,35,.14);border-radius:8px;padding:4px;">' : '<span class="mz-hint">No workspace logo uploaded.</span>') +
                  '<label class="mz-btn mz-btn--outline mz-btn--sm" style="cursor:pointer;">Upload workspace logo<input type="file" accept="image/png,image/jpeg,image/webp" data-kyc-logo-input data-kyc-tenant="' + row.subject_id + '" hidden></label>' +
                  (row.tenant_logo_url ? '<button type="button" class="mz-link" data-kyc-logo-remove="' + row.subject_id + '">Remove logo</button>' : '') +
                  '<span class="mz-hint" data-kyc-logo-hint style="margin:0;">Logo used on workspace labels, receipts, invoices and statements.</span>' +
                '</div>'
              : '') +
            '<div class="mz-row" style="margin-top:10px;justify-content:flex-start;gap:8px;flex-wrap:wrap;">' +
              '<a class="mz-tab" href="/api/kyc-document.php?id=' + row.id + '" target="_blank" rel="noopener">Open document</a>' +
              '<input class="mz-input" style="max-width:260px;" data-kyc-note="' + row.id + '" placeholder="Optional review note">' +
              '<button type="button" class="mz-btn" data-kyc-approve="' + row.id + '">Approve</button>' +
              '<button type="button" class="mz-tab" data-kyc-reject="' + row.id + '">Reject</button>' +
            '</div></div>';
        }).join('') : '<div class="mz-hint">No KYC documents are waiting for review.</div>';
        mount.innerHTML = '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Pending documents</div>' +
          '<button type="button" class="mz-tab" data-kyc-refresh>Refresh</button></div>' + body;
        var refresh = mount.querySelector('[data-kyc-refresh]');
        if (refresh) refresh.addEventListener('click', load);
        Array.prototype.forEach.call(mount.querySelectorAll('[data-kyc-logo-input]'), function (input) {
          input.addEventListener('change', function () {
            if (!input.files || !input.files.length) return;
            var tenantId = Number(input.getAttribute('data-kyc-tenant'));
            var fd = new FormData();
            fd.append('action', 'upload');
            fd.append('tenant_id', String(tenantId));
            fd.append('_csrf', APP.csrf || '');
            fd.append('logo', input.files[0]);
            input.disabled = true;
            fetch('/api/admin-tenant-logo.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': APP.csrf || '', 'Accept': 'application/json' } })
              .then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.ok) throw new Error((d.error && d.error.message) || 'Upload failed.'); return d; }); })
              .then(function () { toast('Workspace logo updated.'); load(); })
              .catch(function (err) { toast(err.message, 'error'); input.disabled = false; });
          });
        });
        Array.prototype.forEach.call(mount.querySelectorAll('[data-kyc-logo-remove]'), function (btn) {
          btn.addEventListener('click', function () {
            if (!window.confirm('Remove this workspace logo?')) return;
            var fd = new FormData();
            fd.append('action', 'remove');
            fd.append('tenant_id', btn.getAttribute('data-kyc-logo-remove'));
            fd.append('_csrf', APP.csrf || '');
            fetch('/api/admin-tenant-logo.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': APP.csrf || '', 'Accept': 'application/json' } })
              .then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.ok) throw new Error((d.error && d.error.message) || 'Remove failed.'); return d; }); })
              .then(function () { toast('Workspace logo removed.'); load(); })
              .catch(function (err) { toast(err.message, 'error'); });
          });
        });
        Array.prototype.forEach.call(mount.querySelectorAll('[data-kyc-approve],[data-kyc-reject]'), function (btn) {
          btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-kyc-approve') || btn.getAttribute('data-kyc-reject');
            var decision = btn.hasAttribute('data-kyc-approve') ? 'approved' : 'rejected';
            var noteEl = mount.querySelector('[data-kyc-note="' + id + '"]');
            btn.disabled = true;
            api('admin-kyc.php', { method: 'POST', body: { action: 'review', doc_id: Number(id), decision: decision, note: noteEl ? noteEl.value : '' } })
              .then(function (fresh) { toast('KYC ' + decision + '.'); mount.innerHTML = ''; initAdminKyc(host); })
              .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
          });
        });
      }).catch(function (err) {
        mount.innerHTML = '<div class="mz-hint">Could not load KYC queue: ' + esc(err.message) + '</div>';
      });
    }
    load();
  }

  function renderAdminPayoutSettings(card, data) {
    if (!card) return;
    var cfg = data.payout_config || {};
    var pending = data.pending_runner_payouts || [];
    var field = function (label, name, value, type, placeholder) {
      return '<label class="mz-field"><span class="mz-label">' + label + '</span>' +
        '<input class="mz-input" data-payout-field="' + name + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '" placeholder="' + esc(placeholder || '') + '"></label>';
    };
    var pendingBody = pending.length
      ? pending.map(function (p) {
          return '<div class="mz-hint">' + esc(p.runner_name || p.runner_id) + ' · KES ' + Number(p.amount_kes).toLocaleString() + ' · ' + esc(p.phone) + ' · ' + esc(fmtDate(p.requested_at)) + '</div>';
        }).join('')
      : '<div class="mz-hint">No pending payouts.</div>';
    card.innerHTML =
      '<div class="mz-card__title">M-Pesa payouts</div>' +
      '<p class="mz-hint">Demo mode pays instantly with a demo reference. Live mode calls Daraja B2C and requires credentials and approved shortcodes.</p>' +
      '<div class="mz-form-grid">' +
        '<label class="mz-field"><span class="mz-label">Mode</span><select class="mz-input" data-payout-field="mode">' +
          '<option value="demo"' + (cfg.mode === 'demo' ? ' selected' : '') + '>Demo</option>' +
          '<option value="live"' + (cfg.mode === 'live' ? ' selected' : '') + '>Live (Daraja B2C)</option></select></label>' +
        '<label class="mz-field"><span class="mz-label">Schedule</span><select class="mz-input" data-payout-field="schedule">' +
          ['instant', 'weekly', 'manual'].map(function (v) { return '<option value="' + v + '"' + (cfg.schedule === v ? ' selected' : '') + '>' + v + '</option>'; }).join('') +
        '</select></label>' +
        field('Minimum payout (KES)', 'min_kes', cfg.min_kes, 'number') +
        '<label class="mz-field"><span class="mz-label">Daraja environment</span><select class="mz-input" data-payout-field="daraja_env">' +
          '<option value="sandbox"' + (cfg.daraja_env === 'sandbox' ? ' selected' : '') + '>Sandbox</option>' +
          '<option value="production"' + (cfg.daraja_env === 'production' ? ' selected' : '') + '>Production</option></select></label>' +
      '</div>' +
      '<div class="mz-form-grid">' +
        field('Consumer key', 'consumer_key', cfg.consumer_key) +
        field('Consumer secret', 'consumer_secret', cfg.consumer_secret, 'password') +
        field('B2C shortcode', 'shortcode', cfg.shortcode) +
        field('Passkey', 'passkey', cfg.passkey, 'password') +
        field('Initiator name', 'initiator_name', cfg.initiator_name) +
        field('Security credential', 'security_credential', cfg.security_credential, 'password') +
        field('Result URL', 'result_url', cfg.result_url) +
        field('Timeout URL', 'timeout_url', cfg.timeout_url) +
      '</div>' +
      '<div class="mz-card__title" style="font-size:14px;margin-top:16px;">Runner incentives &amp; bonuses</div>' +
      '<p class="mz-hint">Bonuses are added to the next runner payout for delivered jobs with the minimum rating; the volume bonus applies when enough eligible jobs are in the payout.</p>' +
      '<div class="mz-form-grid">' +
        '<label class="mz-field" style="display:flex;align-items:center;gap:8px;padding-top:22px;"><input type="checkbox" data-payout-field="incentive_enabled" value="1"' + (cfg.incentive_enabled ? ' checked' : '') + '> Enable rating &amp; volume bonuses</label>' +
        field('Minimum rating (1-5)', 'incentive_min_rating', cfg.incentive_min_rating, 'number') +
        field('Bonus per eligible job (KES)', 'incentive_per_job_kes', cfg.incentive_per_job_kes, 'number') +
        field('Volume threshold (jobs)', 'incentive_volume_threshold', cfg.incentive_volume_threshold, 'number') +
        field('Volume bonus (KES)', 'incentive_volume_bonus_kes', cfg.incentive_volume_bonus_kes, 'number') +
      '</div>' +
      '<div class="mz-row" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;">' +
        '<button type="button" class="mz-btn" data-payout-save>Save payout settings</button>' +
        '<button type="button" class="mz-btn mz-btn--outline" data-payout-process>Process pending payouts (' + pending.length + ')</button>' +
      '</div>' +
      '<div class="mz-card__title" style="font-size:14px;margin-top:16px;">Pending payouts</div>' + pendingBody;
    var save = card.querySelector('[data-payout-save]');
    if (save) save.addEventListener('click', function () {
      var body = { action: 'save_payout_config' };
      Array.prototype.forEach.call(card.querySelectorAll('[data-payout-field]'), function (el) {
        var key = el.getAttribute('data-payout-field');
        body[key] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
      });
      save.disabled = true;
      api('admin.php', { method: 'POST', body: body }).then(function (fresh) {
        toast('Payout settings saved.');
        renderAdminPayoutSettings(card, { payout_config: fresh.payout_config, pending_runner_payouts: pending });
      }).catch(function (err) { toast(err.message, 'error'); save.disabled = false; });
    });
    var processBtn = card.querySelector('[data-payout-process]');
    if (processBtn) processBtn.addEventListener('click', function () {
      processBtn.disabled = true;
      api('admin.php', { method: 'POST', body: { action: 'process_payouts' } })
        .then(function (res) { toast('Payouts processed: ' + ((res.processed || []).length)); processBtn.disabled = false; })
        .catch(function (err) { toast(err.message, 'error'); processBtn.disabled = false; });
    });
  }

  function renderAdminRunnerConfig(card, data) {
    if (!card) return;
    var cfg = data.runner_launch_config || {};
    card.innerHTML =
      '<div class="mz-card__title">Runner launch areas &amp; KYC</div>' +
      '<p class="mz-hint">Jobs are limited to these cities and runners must choose an area inside one of them. KYC is enforced before a runner can accept jobs.</p>' +
      '<label class="mz-field"><span class="mz-label">Supported cities (comma separated)</span>' +
        '<input class="mz-input" data-runner-cities value="' + esc((cfg.cities || []).join(', ')) + '"></label>' +
      '<label class="mz-field"><span class="mz-label">Supported vehicles (comma separated)</span>' +
        '<input class="mz-input" data-runner-vehicles value="' + esc((cfg.vehicles || []).join(', ')) + '"></label>' +
      '<label style="display:flex;align-items:center;gap:8px;margin:10px 0;"><input type="checkbox" data-runner-kyc-required' + (cfg.kyc_required ? ' checked' : '') + '> Require approved KYC before accepting jobs</label>' +
      '<button type="button" class="mz-btn" data-runner-config-save>Save runner settings</button>';
    var save = card.querySelector('[data-runner-config-save]');
    if (save) save.addEventListener('click', function () {
      save.disabled = true;
      api('admin.php', {
        method: 'POST',
        body: {
          action: 'save_runner_config',
          cities: (card.querySelector('[data-runner-cities]').value || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean),
          vehicles: (card.querySelector('[data-runner-vehicles]').value || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean),
          kyc_required: card.querySelector('[data-runner-kyc-required]').checked
        }
      }).then(function (fresh) {
        toast('Runner settings saved.');
        renderAdminRunnerConfig(card, { runner_launch_config: fresh.runner_launch_config });
      }).catch(function (err) { toast(err.message, 'error'); save.disabled = false; });
    });
  }

  function initAdminCampaigns(host) {
    var mount = host.querySelector('[data-admin-campaigns]');
    if (!mount) return;
    var state = { id: 0 };
    var eventCatalog = { subscriber_confirmed: 'Subscriber confirmed after campaign creation' };
    var conditionCatalog = {
      subscriber_age_days: 'Subscriber age at least N days',
      no_campaign_days: 'No marketing campaign in last N days',
      audience_at_least: 'Audience has at least N confirmed subscribers'
    };

    function parseConfig(c) {
      var cfg = c && c.trigger_config;
      if (typeof cfg === 'string') { try { cfg = JSON.parse(cfg); } catch (e) { cfg = null; } }
      if (!cfg || typeof cfg !== 'object') {
        cfg = { time: (c && (c.trigger_type === 'time' || c.trigger_type === 'all')) || !c,
          logic: 'all',
          events: c && c.trigger_event ? [{ event: c.trigger_event, days: 0 }] : [],
          conditions: c && c.trigger_condition ? [{ condition: c.trigger_condition, days: Number(c.trigger_days || 0) }] : [] };
      }
      return cfg;
    }
    function summary(c) {
      var cfg = parseConfig(c);
      var parts = [];
      if (cfg.time) { parts.push(c && c.scheduled_at ? 'time ' + fmtDate(c.scheduled_at) : 'at a scheduled time'); }
      (cfg.events || []).forEach(function (r) { parts.push('event ' + (eventCatalog[r.event] || r.event) + (Number(r.days) ? ' (+' + r.days + ' days)' : '')); });
      (cfg.conditions || []).forEach(function (r) { parts.push('condition ' + (conditionCatalog[r.condition] || r.condition) + (Number(r.days) ? ' (' + r.days + ')' : '')); });
      if (!parts.length) { return 'No trigger selected'; }
      return parts.join(cfg.logic === 'any' ? ' OR ' : ' AND ');
    }
    function options(map, selected) {
      return Object.keys(map).map(function (key) {
        return '<option value="' + esc(key) + '"' + (key === selected ? ' selected' : '') + '>' + esc(map[key]) + '</option>';
      }).join('');
    }
    function eventRow(rule) {
      rule = rule || { event: 'subscriber_confirmed', days: 0 };
      return '<div class="mz-campaign-rule" data-campaign-event-row style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;">' +
        '<label class="mz-field" style="flex:1;min-width:220px;"><span class="mz-label">Event</span><select class="mz-input" data-rule-event>' + options(eventCatalog, rule.event) + '</select></label>' +
        '<label class="mz-field" style="width:120px;"><span class="mz-label">After N days</span><input class="mz-input" type="number" min="0" max="3650" data-rule-days value="' + Number(rule.days || 0) + '"></label>' +
        '<button type="button" class="mz-tab" data-campaign-event-remove>Remove</button></div>';
    }
    function conditionRow(rule) {
      rule = rule || { condition: 'subscriber_age_days', days: 0 };
      return '<div class="mz-campaign-rule" data-campaign-condition-row style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;">' +
        '<label class="mz-field" style="flex:1;min-width:220px;"><span class="mz-label">Condition</span><select class="mz-input" data-rule-condition>' + options(conditionCatalog, rule.condition) + '</select></label>' +
        '<label class="mz-field" style="width:120px;"><span class="mz-label">Days / count</span><input class="mz-input" type="number" min="0" max="3650" data-rule-days value="' + Number(rule.days || 0) + '"></label>' +
        '<button type="button" class="mz-tab" data-campaign-condition-remove>Remove</button></div>';
    }

    function render(data) {
      data = data || {};
      var campaigns = data.campaigns || [];
      var counts = data.audience_counts || {};
      var current = campaigns.filter(function (c) { return Number(c.id) === state.id; })[0] || {};
      var isEdit = !!state.id;
      var cfg = isEdit ? parseConfig(current) : { time: true, logic: 'all', events: [], conditions: [] };
      var listRows = campaigns.map(function (c) {
        var badge = c.status === 'sent' ? 'mz-badge--ok' : (c.status === 'scheduled' ? 'mz-badge--warn' : '');
        return '<tr><td><strong>' + esc(c.name) + '</strong><div class="mz-hint">' + esc(c.subject) + '</div></td>' +
          '<td>' + esc(c.audience) + '<div class="mz-hint">' + esc(summary(c)) + '</div></td>' +
          '<td><span class="mz-badge ' + badge + '">' + esc(c.status) + '</span></td>' +
          '<td>' + c.sent_count + ' sent / ' + c.failed_count + ' failed</td>' +
          '<td style="white-space:nowrap;">' + (c.scheduled_at ? esc(fmtDate(c.scheduled_at)) : '-') + '</td>' +
          '<td style="white-space:nowrap;">' +
            (c.status !== 'sent' ? '<button type="button" class="mz-link" data-campaign-edit="' + c.id + '">Edit</button> - ' : '') +
            (c.status === 'draft' || c.status === 'scheduled' ? '<button type="button" class="mz-link" data-campaign-send-row="' + c.id + '">Send now</button> - ' : '') +
            (c.status === 'scheduled' ? '<button type="button" class="mz-link" data-campaign-cancel="' + c.id + '">Cancel</button> - ' : '') +
            '<button type="button" class="mz-link" data-campaign-delete="' + c.id + '">Delete</button>' +
          '</td></tr>';
      }).join('') || '<tr><td colspan="6" class="mz-hint">No campaigns yet. Create your first marketing message above.</td></tr>';

      mount.innerHTML =
        '<div class="mz-row"><div><div class="mz-card__title" style="margin:0;">' + (isEdit ? 'Edit campaign' : 'New campaign') + '</div>' +
          '<p class="mz-hint" style="margin:4px 0 0;">Only confirmed newsletter/alert subscribers receive these messages. Order, delivery and onboarding messages remain in Communications.</p></div>' +
          '<span class="mz-badge">' + (counts.all || 0) + ' confirmed subscribers</span></div>' +
        '<div class="mz-form-2col" style="margin-top:12px;">' +
          '<label class="mz-field"><span class="mz-label">Campaign name</span><input class="mz-input" data-campaign="name" value="' + esc(current.name || '') + '" placeholder="August parcel tips"></label>' +
          '<label class="mz-field"><span class="mz-label">Audience</span><select class="mz-input" data-campaign="audience">' +
            '<option value="all"' + (current.audience === 'all' ? ' selected' : '') + '>All confirmed subscribers (' + (counts.all || 0) + ')</option>' +
            '<option value="newsletter"' + (current.audience === 'newsletter' ? ' selected' : '') + '>Newsletter interest (' + (counts.newsletter || 0) + ')</option>' +
            '<option value="alerts"' + (current.audience === 'alerts' ? ' selected' : '') + '>Product alerts (' + (counts.alerts || 0) + ')</option>' +
          '</select></label>' +
        '</div>' +
        '<div class="mz-saas-card" style="margin-top:12px;background:color-mix(in srgb,var(--mz-hunter) 4%,#fff);">' +
          '<div class="mz-card__title" style="margin:0;">When should this send?</div>' +
          '<p class="mz-hint" style="margin:4px 0 8px;">Select one or more triggers. Rules can be combined with ALL or ANY logic.</p>' +
          '<div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12px;margin-bottom:8px;">' +
            '<label><input type="checkbox" data-campaign-trigger="time"' + (cfg.time ? ' checked' : '') + '> Time</label>' +
            '<label><input type="checkbox" data-campaign-trigger="event"' + ((cfg.events || []).length ? ' checked' : '') + '> Event</label>' +
            '<label><input type="checkbox" data-campaign-trigger="condition"' + ((cfg.conditions || []).length ? ' checked' : '') + '> Condition</label>' +
          '</div>' +
          '<div data-campaign-time-block style="' + (cfg.time ? '' : 'display:none;') + '"><label class="mz-field"><span class="mz-label">Send at</span><input class="mz-input" type="datetime-local" data-campaign="scheduled_at" value="' + esc(current.scheduled_at ? String(current.scheduled_at).replace(' ', 'T').slice(0, 16) : '') + '"></label></div>' +
          '<div data-campaign-event-block style="' + ((cfg.events || []).length ? '' : 'display:none;') + '">' +
            '<div class="mz-row"><span class="mz-label">Events</span><button type="button" class="mz-tab" data-campaign-add-event>+ Add event</button></div>' +
            '<div data-campaign-event-rows>' + (cfg.events || []).map(eventRow).join('') + '</div>' +
          '</div>' +
          '<div data-campaign-condition-block style="' + ((cfg.conditions || []).length ? '' : 'display:none;') + '">' +
            '<div class="mz-row"><span class="mz-label">Conditions</span><button type="button" class="mz-tab" data-campaign-add-condition>+ Add condition</button></div>' +
            '<div data-campaign-condition-rows>' + (cfg.conditions || []).map(conditionRow).join('') + '</div>' +
          '</div>' +
          '<div class="mz-form-2col" style="margin-top:10px;"><label class="mz-field"><span class="mz-label">Match rules</span><select class="mz-input" data-campaign="trigger_logic"><option value="all"' + (cfg.logic === 'all' ? ' selected' : '') + '>ALL selected rules</option><option value="any"' + (cfg.logic === 'any' ? ' selected' : '') + '>ANY selected rule</option></select></label>' +
          '<div style="display:flex;align-items:flex-end;"><span class="mz-hint" data-campaign-trigger-summary>' + esc(summary(current)) + '</span></div></div>' +
          '<p class="mz-hint" style="margin-top:6px;">Time triggers need a date. Event/condition campaigns can be activated without one and are evaluated by the marketing cron.</p>' +
        '</div>' +
        '<label class="mz-field" style="margin-top:12px;"><span class="mz-label">Email subject</span><input class="mz-input" data-campaign="subject" value="' + esc(current.subject || '') + '" maxlength="160" placeholder="Your August delivery brief"></label>' +
        '<label class="mz-field" style="margin-top:10px;"><span class="mz-label">Message</span><textarea class="mz-input" rows="6" data-campaign="body" placeholder="Write the marketing message. Plain text; include the useful information first.">' + esc(current.body || '') + '</textarea></label>' +
        '<div class="mz-form-2col" style="margin-top:10px;">' +
          '<label class="mz-field"><span class="mz-label">CTA label (optional)</span><input class="mz-input" data-campaign="cta_label" value="' + esc(current.cta_label || '') + '" placeholder="Read the guide"></label>' +
          '<label class="mz-field"><span class="mz-label">CTA URL (optional)</span><input class="mz-input" data-campaign="cta_url" value="' + esc(current.cta_url || '') + '" placeholder="/blog/..."></label>' +
          '<label class="mz-field"><span class="mz-label">Artwork URL (optional)</span><input class="mz-input" data-campaign="artwork_url" value="' + esc(current.artwork_url || data.artwork_url || '') + '" placeholder="/assets/img/newsletter/..."></label>' +
        '</div>' +
        '<p class="mz-hint" style="margin-top:8px;">Channels: each subscriber receives the campaign on the channel they chose at signup. Inactive channels and marketing opt-outs are skipped. Every campaign automatically includes a signed unsubscribe link.</p>' +
        '<div class="mz-row" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;margin-top:12px;">' +
          '<button type="button" class="mz-btn mz-btn--sm" data-campaign-save>Save draft</button>' +
          '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-campaign-schedule>Save &amp; activate</button>' +
          (isEdit ? '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-campaign-send>Send now</button>' : '') +
          (isEdit ? '<button type="button" class="mz-tab" data-campaign-new>New campaign</button>' : '') +
        '</div>' +
        '<div class="mz-table-wrap" style="margin-top:18px;"><table class="mz-table"><thead><tr><th>Campaign</th><th>Audience / trigger</th><th>Status</th><th>Delivery</th><th>Scheduled</th><th></th></tr></thead><tbody>' + listRows + '</tbody></table></div>';

      var val = function (key) { var el = mount.querySelector('[data-campaign="' + key + '"]'); return el ? el.value : ''; };
      function readTriggerConfig() {
        var events = [];
        var eventsOn = mount.querySelector('[data-campaign-trigger="event"]').checked;
        mount.querySelectorAll('[data-campaign-event-row]').forEach(function (row) {
          var event = row.querySelector('[data-rule-event]').value;
          if (eventsOn && event) { events.push({ event: event, days: Number(row.querySelector('[data-rule-days]').value || 0) }); }
        });
        var conditions = [];
        var conditionsOn = mount.querySelector('[data-campaign-trigger="condition"]').checked;
        mount.querySelectorAll('[data-campaign-condition-row]').forEach(function (row) {
          var condition = row.querySelector('[data-rule-condition]').value;
          if (conditionsOn && condition) { conditions.push({ condition: condition, days: Number(row.querySelector('[data-rule-days]').value || 0) }); }
        });
        return {
          time: !!mount.querySelector('[data-campaign-trigger="time"]').checked,
          scheduled_at: val('scheduled_at'),
          logic: val('trigger_logic') || 'all',
          events: events,
          conditions: conditions
        };
      }
      function refreshSummary() {
        var el = mount.querySelector('[data-campaign-trigger-summary]');
        if (el) el.textContent = summary({ trigger_config: readTriggerConfig(), scheduled_at: val('scheduled_at') });
      }
      function syncTriggerBlocks() {
        var time = mount.querySelector('[data-campaign-trigger="time"]').checked;
        var event = mount.querySelector('[data-campaign-trigger="event"]').checked;
        var condition = mount.querySelector('[data-campaign-trigger="condition"]').checked;
        mount.querySelector('[data-campaign-time-block]').style.display = time ? '' : 'none';
        mount.querySelector('[data-campaign-event-block]').style.display = event ? '' : 'none';
        mount.querySelector('[data-campaign-condition-block]').style.display = condition ? '' : 'none';
        refreshSummary();
      }
      function bindRuleRemove() {
        mount.querySelectorAll('[data-campaign-event-remove]').forEach(function (btn) {
          if (btn.dataset.bound) return; btn.dataset.bound = '1';
          btn.addEventListener('click', function () {
            var block = btn.closest('[data-campaign-event-block]');
            btn.closest('[data-campaign-event-row]').remove();
            if (block && !block.querySelector('[data-campaign-event-row]')) {
              mount.querySelector('[data-campaign-trigger="event"]').checked = false;
              syncTriggerBlocks();
            } else { refreshSummary(); }
          });
        });
        mount.querySelectorAll('[data-campaign-condition-remove]').forEach(function (btn) {
          if (btn.dataset.bound) return; btn.dataset.bound = '1';
          btn.addEventListener('click', function () {
            var block = btn.closest('[data-campaign-condition-block]');
            btn.closest('[data-campaign-condition-row]').remove();
            if (block && !block.querySelector('[data-campaign-condition-row]')) {
              mount.querySelector('[data-campaign-trigger="condition"]').checked = false;
              syncTriggerBlocks();
            } else { refreshSummary(); }
          });
        });
      }
      mount.querySelectorAll('[data-campaign-trigger]').forEach(function (chip) { chip.addEventListener('change', syncTriggerBlocks); });
      var addEvent = mount.querySelector('[data-campaign-add-event]');
      if (addEvent) addEvent.addEventListener('click', function () {
        mount.querySelector('[data-campaign-event-rows]').insertAdjacentHTML('beforeend', eventRow({ event: 'subscriber_confirmed', days: 0 }));
        bindRuleRemove(); syncTriggerBlocks();
      });
      var addCondition = mount.querySelector('[data-campaign-add-condition]');
      if (addCondition) addCondition.addEventListener('click', function () {
        mount.querySelector('[data-campaign-condition-rows]').insertAdjacentHTML('beforeend', conditionRow({ condition: 'subscriber_age_days', days: 0 }));
        bindRuleRemove(); syncTriggerBlocks();
      });
      mount.querySelectorAll('[data-campaign-event-row] [data-rule-days], [data-campaign-condition-row] [data-rule-days]').forEach(function (input) {
        input.addEventListener('input', refreshSummary);
      });
      var logic = mount.querySelector('[data-campaign="trigger_logic"]');
      if (logic) logic.addEventListener('change', refreshSummary);
      bindRuleRemove();
      syncTriggerBlocks();

      function collect() {
        return {
          id: state.id,
          name: val('name'), subject: val('subject'), body: val('body'), audience: val('audience'),
          cta_label: val('cta_label'), cta_url: val('cta_url'), artwork_url: val('artwork_url'),
          scheduled_at: val('scheduled_at'), trigger_config: readTriggerConfig()
        };
      }
      function saveDraft() {
        api('admin-campaigns.php', { method: 'POST', body: Object.assign({ action: 'save_campaign' }, collect()) })
          .then(function (res) { state.id = Number(res.saved_id || state.id); toast('Campaign saved.'); render(res); })
          .catch(function (err) { toast(err.message, 'error'); });
      }
      function schedule() {
        var body = collect();
        if (body.trigger_config.time && !body.trigger_config.scheduled_at) {
          toast('Choose a schedule date for the Time trigger.', 'error'); return;
        }
        if (!body.trigger_config.time && !body.trigger_config.events.length && !body.trigger_config.conditions.length) {
          toast('Select at least one trigger rule.', 'error'); return;
        }
        api('admin-campaigns.php', { method: 'POST', body: Object.assign({ action: 'save_campaign' }, body) })
          .then(function (res) {
            var id = Number(res.saved_id || state.id);
            return api('admin-campaigns.php', { method: 'POST', body: { action: 'schedule_campaign', id: id, scheduled_at: body.scheduled_at } }).then(function (fresh) {
              state.id = id; toast('Campaign activated.'); render(fresh);
            });
          }).catch(function (err) { toast(err.message, 'error'); });
      }
      function sendNow(id) {
        if (!window.confirm('Send this marketing campaign to eligible confirmed subscribers now? This cannot be undone.')) return;
        api('admin-campaigns.php', { method: 'POST', body: { action: 'send_campaign', id: id } })
          .then(function (res) { toast('Campaign sent: ' + (res.sent || 0) + ' sent, ' + (res.failed || 0) + ' failed/skipped.'); state.id = 0; render(res); })
          .catch(function (err) { toast(err.message, 'error'); });
      }
      mount.querySelector('[data-campaign-save]').addEventListener('click', saveDraft);
      mount.querySelector('[data-campaign-schedule]').addEventListener('click', schedule);
      var send = mount.querySelector('[data-campaign-send]');
      if (send) send.addEventListener('click', function () {
        api('admin-campaigns.php', { method: 'POST', body: Object.assign({ action: 'save_campaign' }, collect()) })
          .then(function (res) { sendNow(Number(res.saved_id || state.id)); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
      var newBtn = mount.querySelector('[data-campaign-new]');
      if (newBtn) newBtn.addEventListener('click', function () { state.id = 0; render(data); });
      mount.querySelectorAll('[data-campaign-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () { state.id = Number(btn.getAttribute('data-campaign-edit')); render(data); });
      });
      mount.querySelectorAll('[data-campaign-send-row]').forEach(function (btn) {
        btn.addEventListener('click', function () { sendNow(Number(btn.getAttribute('data-campaign-send-row'))); });
      });
      mount.querySelectorAll('[data-campaign-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!window.confirm('Cancel this scheduled campaign?')) return;
          api('admin-campaigns.php', { method: 'POST', body: { action: 'cancel_campaign', id: Number(btn.getAttribute('data-campaign-cancel')) } })
            .then(function (res) { toast('Campaign cancelled.'); render(res); }).catch(function (err) { toast(err.message, 'error'); });
        });
      });
      mount.querySelectorAll('[data-campaign-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!window.confirm('Delete this campaign and its delivery records?')) return;
          api('admin-campaigns.php', { method: 'POST', body: { action: 'delete_campaign', id: Number(btn.getAttribute('data-campaign-delete')) } })
            .then(function (res) { toast('Campaign deleted.'); state.id = 0; render(res); }).catch(function (err) { toast(err.message, 'error'); });
        });
      });
    }

    api('admin-campaigns.php').then(render).catch(function (err) {
      mount.innerHTML = '<div class="mz-hint">Could not load campaigns: ' + esc(err.message) + '</div>';
    });
  }

  function initAdminWhatsApp(host) {
    var mount = host.querySelector('[data-admin-whatsapp]');
    if (!mount) return;
    var waData = null;
    var selected = 0;
    var currentThread = null;

    function badge(status) {
      var cls = status === 'human' ? 'mz-badge--warn' : status === 'closed' ? '' : 'mz-badge--ok';
      return '<span class="mz-badge ' + cls + '">' + esc(status) + '</span>';
    }
    function field(name, label, value, type, placeholder, full) {
      return '<label class="mz-field' + (full ? ' mz-field--full' : '') + '"><span class="mz-label">' + label + '</span>' +
        '<input class="mz-input" data-wa-field="' + name + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '" placeholder="' + esc(placeholder || '') + '"></label>';
    }
    function configCard(cfg) {
      return '<div class="mz-saas-card" style="margin-bottom:16px;">' +
        '<div class="mz-row"><div><div class="mz-card__title" style="margin:0;">WhatsApp assistant</div>' +
          '<p class="mz-hint" style="margin:4px 0 0;">Phase 1: FAQs, parcel tracking and human handoff. Demo mode works without Meta credentials.</p></div>' +
          '<span class="mz-badge ' + (cfg.mode === 'live' ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (cfg.mode === 'live' ? 'Live' : 'Demo') + '</span></div>' +
        '<form data-wa-config-form style="margin-top:12px;"><div class="mz-wa-form">' +
          '<label class="mz-field"><span class="mz-label">Mode</span><select class="mz-input" data-wa-field="mode"><option value="demo"' + (cfg.mode === 'demo' ? ' selected' : '') + '>Demo (no Meta calls)</option><option value="live"' + (cfg.mode === 'live' ? ' selected' : '') + '>Live Meta Cloud API</option></select></label>' +
          '<label class="mz-field"><span class="mz-label">Assistant</span><select class="mz-input" data-wa-field="enabled"><option value="1"' + (cfg.enabled ? ' selected' : '') + '>Enabled</option><option value="0"' + (!cfg.enabled ? ' selected' : '') + '>Paused</option></select></label>' +
          '<label class="mz-field"><span class="mz-label">WhatsApp provider</span><select class="mz-input" data-wa-field="provider"><option value="meta"' + (cfg.provider === 'meta' ? ' selected' : '') + '>Meta WhatsApp Cloud API</option><option value="infobip"' + (cfg.provider === 'infobip' ? ' selected' : '') + '>Infobip WhatsApp</option></select></label>' +
          field('business_name', 'Business name', cfg.business_name, 'text', 'Mzigo.online') +
          field('infobip_base_url', 'Infobip WhatsApp base URL', cfg.infobip_base_url, 'text', 'd845wr.api.infobip.com') +
          field('infobip_sender', 'Infobip WhatsApp sender', cfg.infobip_sender, 'text', 'Your approved WhatsApp sender') +
          field('infobip_api_key', 'Infobip WhatsApp API key', '', 'password', cfg.infobip_api_key_set ? 'Saved — leave blank to keep' : 'Infobip API key') +
          field('infobip_webhook_secret', 'Infobip webhook secret', '', 'password', cfg.infobip_webhook_secret_set ? 'Saved — leave blank to keep' : 'Optional Authorization secret') +
          field('phone_number_id', 'Meta phone number ID', cfg.phone_number_id, 'text', '1234567890') +
          field('waba_id', 'WABA ID', cfg.waba_id, 'text', 'WhatsApp Business Account ID') +
          field('graph_version', 'Graph API version', cfg.graph_version, 'text', 'v23.0') +
          field('access_token', 'Meta access token', '', 'password', cfg.access_token_set ? 'Saved — leave blank to keep' : 'Permanent system-user token') +
          field('app_secret', 'Meta app secret', '', 'password', cfg.app_secret_set ? 'Saved — leave blank to keep' : 'App secret for webhook signatures') +
          field('verify_token', 'Webhook verify token', cfg.verify_token, 'text', '') +
          field('ai_provider', 'AI provider', cfg.ai_provider, 'text', 'demo | openai | anthropic') +
          field('ai_model', 'AI model', cfg.ai_model, 'text', 'gpt-4o-mini') +
          field('ai_base_url', 'AI base URL (optional)', cfg.ai_base_url, 'text', 'https://api.openai.com/v1') +
          field('ai_api_key', 'AI API key', '', 'password', cfg.ai_api_key_set ? 'Saved — leave blank to keep' : 'Provider API key') +
          '<label class="mz-field"><span class="mz-label">Auto reply</span><select class="mz-input" data-wa-field="auto_reply"><option value="1"' + (cfg.auto_reply ? ' selected' : '') + '>On</option><option value="0"' + (!cfg.auto_reply ? ' selected' : '') + '>Off</option></select></label>' +
          '<label class="mz-field mz-field--full"><span class="mz-label">Handoff message</span><input class="mz-input" data-wa-field="handoff_message" value="' + esc(cfg.handoff_message || '') + '"></label>' +
          '<label class="mz-field mz-field--full"><span class="mz-label">AI system prompt</span><textarea class="mz-input" data-wa-field="ai_system_prompt">' + esc(cfg.ai_system_prompt || '') + '</textarea></label>' +
        '</div>' +
        '<div class="mz-row" style="gap:8px;justify-content:flex-start;flex-wrap:wrap;margin-top:8px;">' +
          '<button type="submit" class="mz-btn mz-btn--sm">Save WhatsApp settings</button>' +
          '<button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-wa-process>Process queue</button>' +
        '</div></form>' +
        '<div class="mz-hint" style="margin-top:10px;">Webhook URL: <span class="mz-wa-webhook">' + esc(cfg.webhook_url || '') + '</span><br>Live mode requires the Meta access token and app secret. The verify token above is the one to paste into Meta.</div>' +
      '</div>';
    }
    function templatesCard(templates, outbound) {
      var labels = { registered: 'Parcel registered', ready: 'Ready for collection', payment_received: 'Payment received + code', collected: 'Collected', status: 'Other status update' };
      var rows = ['registered', 'ready', 'payment_received', 'collected', 'status'].map(function (event) {
        var t = (templates || []).filter(function (x) { return x.event === event; })[0] || { event: event, template_name: '', language: 'en', active: 0 };
        return '<div class="mz-field mz-field--full" style="display:grid;grid-template-columns:150px 1fr 70px 70px;gap:8px;align-items:end;" data-wa-tpl-row="' + event + '">' +
          '<div><span class="mz-label">' + labels[event] + '</span><span class="mz-hint">' + event + '</span></div>' +
          '<label class="mz-field"><span class="mz-label">Approved template name</span><input class="mz-input" data-wa-tpl-name value="' + esc(t.template_name || '') + '" placeholder="mzigo_..."></label>' +
          '<label class="mz-field"><span class="mz-label">Lang</span><input class="mz-input" data-wa-tpl-lang value="' + esc(t.language || 'en') + '"></label>' +
          '<label class="mz-field"><span class="mz-label">Active</span><input type="checkbox" data-wa-tpl-active' + (t.active ? ' checked' : '') + '></label></div>';
      }).join('');
      var logs = (outbound || []).map(function (o) {
        return '<div class="mz-hint" style="border-bottom:1px solid rgba(60,82,35,.08);padding:5px 0;">' + esc(fmtDate(o.created_at)) + ' · ' + esc(o.event) + ' · ' + esc(o.phone || '') + ' · ' + esc(o.status) + (o.error ? ' · ' + esc(o.error) : '') + '</div>';
      }).join('') || '<div class="mz-hint">No template sends yet.</div>';
      return '<div class="mz-saas-card" style="margin-bottom:16px;"><div class="mz-card__title">Approved parcel-update templates</div>' +
        '<p class="mz-hint">Create and get these templates approved in Meta first. Parameter order is fixed: registered (name, tracking, carrier, route); ready (name, tracking, fee); payment_received (name, tracking, fee, collection code); collected (name, tracking); status (name, tracking, status, note).</p>' +
        '<div style="margin-top:8px;">' + rows + '</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"><button type="button" class="mz-btn mz-btn--sm" data-wa-save-templates>Save templates</button></div>' +
        '<div style="border-top:1px solid rgba(60,82,35,.12);margin:14px 0 10px;"></div>' +
        '<div class="mz-label">Send a demo test</div><div class="mz-wa-form" style="margin-top:6px;">' +
          '<label class="mz-field"><span class="mz-label">Event</span><select class="mz-input" data-wa-test-event>' + ['registered','ready','payment_received','collected','status'].map(function (e) { return '<option value="' + e + '">' + labels[e] + '</option>'; }).join('') + '</select></label>' +
          '<label class="mz-field"><span class="mz-label">Opted-in phone</span><input class="mz-input" data-wa-test-phone value="254711000111"></label>' +
          '<div class="mz-field mz-field--full"><button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-wa-test-template>Send demo template</button></div>' +
        '</div>' +
        '<div class="mz-label" style="margin-top:14px;">Recent outbound template events</div>' + logs + '</div>';
    }
    function inboxCard(data) {
      var convs = data.conversations || [];
      var list = convs.length ? convs.map(function (c) {
        return '<button type="button" class="mz-wa-item' + (selected === c.id ? ' is-active' : '') + '" data-wa-conversation="' + c.id + '">' +
          '<div class="mz-wa-item__top"><span>' + esc(c.name || c.phone || c.wa_id) + '</span>' + badge(c.status) + '</div>' +
          '<div class="mz-wa-item__msg">' + esc(c.last_message || 'No messages yet') + '</div>' +
          (c.open_handoffs ? '<div class="mz-wa-item__msg" style="color:#b45309;">' + c.open_handoffs + ' open handoff(s)</div>' : '') + '</button>';
      }).join('') : '<div class="mz-hint">No WhatsApp conversations yet. Use the test simulator below.</div>';
      var handoffs = (data.handoffs || []).map(function (h) {
        return '<div class="mz-wa-handoff"><strong>' + esc(h.contact_name || h.wa_id) + '</strong> · ' + esc(h.reason) + '<br>' +
          '<span class="mz-hint">' + esc(fmtDate(h.created_at)) + '</span> ' +
          '<button type="button" class="mz-link" data-wa-resolve="' + h.id + '">Resolve</button></div>';
      }).join('') || '<div class="mz-hint">No open handoffs.</div>';
      return '<div class="mz-saas-card"><div class="mz-card__title">Inbox</div><div class="mz-wa-list" data-wa-list>' + list + '</div>' +
        '<div class="mz-card__title" style="font-size:14px;margin-top:16px;">Handoff queue</div>' + handoffs + '</div>';
    }
    function threadCard() {
      return '<div class="mz-saas-card" data-wa-thread-card><div class="mz-card__title">Conversation</div><div data-wa-thread><div class="mz-hint">Select a conversation or simulate an inbound message.</div></div></div>';
    }
    function whatsappTestCard(cfg) {
      return '<div class="mz-saas-card" style="margin-bottom:16px;"><div class="mz-card__title">Send a live WhatsApp test</div>' +
        '<p class="mz-hint">Sends through the selected provider (<strong>' + esc(cfg.provider || 'meta') + '</strong>). Use a WhatsApp-verified number.</p>' +
        '<div class="mz-wa-form"><label class="mz-field"><span class="mz-label">Phone number</span><input class="mz-input" data-wa-test-phone placeholder="+254 712 345 678"></label>' +
        '<label class="mz-field"><span class="mz-label">Message</span><input class="mz-input" data-wa-test-body value="Mzigo.online WhatsApp delivery test"></label>' +
        '<div class="mz-field mz-field--full"><button type="button" class="mz-btn mz-btn--sm" data-wa-test-send>Send test WhatsApp</button><span class="mz-hint" data-wa-test-result style="margin-left:10px;"></span></div></div></div>';
    }
    function simulateCard() {
      return '<div class="mz-saas-card" style="margin-top:16px;"><div class="mz-card__title">Test assistant (demo safe)</div>' +
        '<p class="mz-hint">Simulate a customer message. This always uses demo sending, even when live mode is enabled.</p>' +
        '<form data-wa-simulate class="mz-wa-form"><label class="mz-field"><span class="mz-label">Customer phone</span><input class="mz-input" name="phone" value="254700000000"></label>' +
        '<label class="mz-field"><span class="mz-label">Message</span><input class="mz-input" name="body" value="How do I track my parcel?"></label>' +
        '<div class="mz-field mz-field--full"><button type="submit" class="mz-btn mz-btn--sm">Simulate inbound</button></div></form></div>';
    }
    function bindConfig() {
      var form = mount.querySelector('[data-wa-config-form]');
      if (form) form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var config = {};
        mount.querySelectorAll('[data-wa-field]').forEach(function (el) { config[el.getAttribute('data-wa-field')] = el.value; });
        config.enabled = config.enabled === '1';
        config.auto_reply = config.auto_reply === '1';
        api('admin-whatsapp.php', { method: 'POST', body: { action: 'save_config', config: config } })
          .then(function () { toast('WhatsApp settings saved.'); load(); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
      var process = mount.querySelector('[data-wa-process]');
      if (process) process.addEventListener('click', function () {
        process.disabled = true;
        api('admin-whatsapp.php', { method: 'POST', body: { action: 'process_queue', limit: 20 } })
          .then(function (res) { toast(res.processed + ' message(s) processed.'); absorb(res); render(); if (selected) openThread(selected); })
          .catch(function (err) { toast(err.message, 'error'); process.disabled = false; });
      });
      var sim = mount.querySelector('[data-wa-simulate]');
      if (sim) sim.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var phone = sim.querySelector('[name="phone"]').value.trim();
        var body = sim.querySelector('[name="body"]').value.trim();
        api('admin-whatsapp.php', { method: 'POST', body: { action: 'simulate_inbound', phone: phone, body: body } })
          .then(function (res) { absorb(res); selected = Number(res.conversation_id || 0); render(); openThread(selected); toast('Test message processed.'); })
          .catch(function (err) { toast(err.message, 'error'); });
      });
    }
    function bindTemplates() {
      var save = mount.querySelector('[data-wa-save-templates]');
      if (save) save.addEventListener('click', function () {
        var templates = {};
        mount.querySelectorAll('[data-wa-tpl-row]').forEach(function (row) {
          var event = row.getAttribute('data-wa-tpl-row');
          templates[event] = {
            template_name: (row.querySelector('[data-wa-tpl-name]') || {}).value || '',
            language: (row.querySelector('[data-wa-tpl-lang]') || {}).value || 'en',
            active: !!(row.querySelector('[data-wa-tpl-active]') || {}).checked
          };
        });
        save.disabled = true;
        api('admin-whatsapp.php', { method: 'POST', body: { action: 'save_templates', templates: templates } })
          .then(function (res) { toast('WhatsApp templates saved.'); absorb(res); render(); })
          .catch(function (err) { toast(err.message, 'error'); save.disabled = false; });
      });
      var test = mount.querySelector('[data-wa-test-template]');
      if (test) test.addEventListener('click', function () {
        var event = (mount.querySelector('[data-wa-test-event]') || {}).value || 'registered';
        var phone = (mount.querySelector('[data-wa-test-phone]') || {}).value || '';
        test.disabled = true;
        api('admin-whatsapp.php', { method: 'POST', body: { action: 'test_template', event: event, phone: phone } })
          .then(function (res) {
            var r = res.result || {};
            toast(r.ok ? 'Demo template sent.' : ('Template skipped: ' + (r.skipped || r.error || 'check opt-in and active template')));
            absorb(res); render();
          }).catch(function (err) { toast(err.message, 'error'); test.disabled = false; });
      });
    }
    function bindWhatsAppTest() {
      var btn = mount.querySelector('[data-wa-test-send]');
      if (!btn) return;
      btn.addEventListener('click', function () {
        var out = mount.querySelector('[data-wa-test-result]');
        btn.disabled = true;
        api('admin-whatsapp.php', { method: 'POST', body: {
          action: 'send_test_whatsapp',
          phone: (mount.querySelector('[data-wa-test-phone]') || {}).value || '',
          body: (mount.querySelector('[data-wa-test-body]') || {}).value || ''
        } }).then(function (res) {
          var r = res.result || {};
          var msg = r.ok ? ('Sent via ' + (r.provider || 'provider') + ' (' + (r.message_id || 'accepted') + ')') : ('Failed: ' + (r.error || 'unknown error'));
          if (out) out.textContent = msg;
          toast(msg, r.ok ? undefined : 'error');
          btn.disabled = false;
        }).catch(function (err) { if (out) out.textContent = err.message; toast(err.message, 'error'); btn.disabled = false; });
      });
    }
    function bindList() {
      mount.querySelectorAll('[data-wa-conversation]').forEach(function (btn) {
        btn.addEventListener('click', function () { selected = Number(btn.getAttribute('data-wa-conversation')); render(); openThread(selected); });
      });
      mount.querySelectorAll('[data-wa-resolve]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          api('admin-whatsapp.php', { method: 'POST', body: { action: 'resolve_handoff', handoff_id: Number(btn.getAttribute('data-wa-resolve')) } })
            .then(function (res) { toast('Handoff resolved.'); absorb(res); render(); if (selected) openThread(selected); })
            .catch(function (err) { toast(err.message, 'error'); });
        });
      });
    }
    function absorb(res) {
      if (!res) return;
      if (res.whatsapp) waData = res.whatsapp;
      else if (res.config && res.metrics) waData = res;
    }
    function render() {
      if (!waData) return;
      var cfg = waData.config || {};
      var m = waData.metrics || {};
      mount.innerHTML =
        '<div class="mz-stats" style="margin-bottom:14px;">' +
          '<div class="mz-stat"><div class="mz-cap">Conversations</div><div class="mz-stat__value">' + (m.conversations || 0) + '</div></div>' +
          '<div class="mz-stat"><div class="mz-cap">Open handoffs</div><div class="mz-stat__value">' + (m.open_handoffs || 0) + '</div></div>' +
          '<div class="mz-stat"><div class="mz-cap">Messages today</div><div class="mz-stat__value">' + (m.messages_today || 0) + '</div></div>' +
          '<div class="mz-stat"><div class="mz-cap">Queued jobs</div><div class="mz-stat__value">' + (m.queued || 0) + '</div></div>' +
        '</div>' +
        configCard(cfg) +
        templatesCard(waData.templates || [], waData.outbound || []) +
        whatsappTestCard(cfg) +
        '<div class="mz-wa-layout">' + inboxCard(waData) + threadCard() + '</div>' +
        simulateCard();
      bindConfig(); bindList(); bindTemplates(); bindWhatsAppTest();
      if (selected && currentThread) renderThread(currentThread);
    }
    function renderThread(res) {
      var thread = mount.querySelector('[data-wa-thread]');
      if (!thread) return;
      var conv = res.conversation || {};
      var messages = res.messages || [];
      thread.innerHTML =
        '<div class="mz-row" style="margin-bottom:8px;"><div><strong>' + esc(conv.name || conv.phone || conv.wa_id) + '</strong><div class="mz-hint">' + esc(conv.phone || '') + ' · ' + esc(conv.status) + '</div></div>' +
          '<div style="display:flex;gap:4px;flex-wrap:wrap;"><button type="button" class="mz-tab" data-wa-takeover>Take over</button><button type="button" class="mz-tab" data-wa-close>Close</button><button type="button" class="mz-tab" data-wa-reopen>Bot</button></div></div>' +
        (conv.opted_out ? '<div class="mz-wa-handoff">Customer opted out of WhatsApp messages.</div>' : '') +
        '<div class="mz-wa-thread">' + (messages.length ? messages.map(function (msg) {
          return '<div class="mz-wa-msg ' + (msg.direction === 'inbound' ? 'inbound' : 'outbound') + '">' + esc(msg.body) +
            '<span class="mz-wa-msg__meta">' + esc(fmtDate(msg.created_at)) + (msg.ai_model ? ' · ' + esc(msg.ai_model) : '') + (msg.status === 'failed' ? ' · failed' : '') + '</span></div>';
        }).join('') : '<div class="mz-hint">No messages yet.</div>') + '</div>' +
        '<div style="margin-top:10px;"><label class="mz-field"><span class="mz-label">Reply as human</span><textarea class="mz-input" data-wa-reply rows="3" placeholder="Type a reply to send on WhatsApp..."></textarea></label>' +
        '<button type="button" class="mz-btn mz-btn--sm" data-wa-send>Send reply</button></div>';
      var act = function (action) {
        api('admin-whatsapp.php', { method: 'POST', body: { action: action, conversation_id: Number(conv.id) } })
          .then(function (res) { toast('Conversation updated.'); absorb(res); render(); openThread(Number(conv.id)); })
          .catch(function (err) { toast(err.message, 'error'); });
      };
      thread.querySelector('[data-wa-takeover]').addEventListener('click', function () { act('takeover'); });
      thread.querySelector('[data-wa-close]').addEventListener('click', function () { act('close'); });
      thread.querySelector('[data-wa-reopen]').addEventListener('click', function () { act('reopen'); });
      thread.querySelector('[data-wa-send]').addEventListener('click', function (ev) {
        var body = (thread.querySelector('[data-wa-reply]') || {}).value || '';
        if (!body.trim()) { toast('Write a reply first.', 'error'); return; }
        ev.currentTarget.disabled = true;
        api('admin-whatsapp.php', { method: 'POST', body: { action: 'reply', conversation_id: Number(conv.id), body: body } })
          .then(function (res) { toast('Reply sent.'); absorb(res); render(); openThread(Number(conv.id)); })
          .catch(function (err) { toast(err.message, 'error'); ev.currentTarget.disabled = false; });
      });
    }
    function openThread(id) {
      if (!id) return;
      selected = Number(id);
      api('admin-whatsapp.php', { method: 'POST', body: { action: 'conversation', conversation_id: selected } })
        .then(function (res) { currentThread = res; renderThread(res); })
        .catch(function (err) { toast(err.message, 'error'); });
    }
    function load() {
      api('admin-whatsapp.php').then(function (data) {
        waData = data; render(); if (selected) openThread(selected);
      }).catch(function (err) {
        mount.innerHTML = '<div class="mz-alert">Could not load WhatsApp settings: ' + esc(err.message) + '</div>';
      });
    }
    load();
  }

  function initAdminGrowth(host) {
    var mount = host.querySelector('[data-admin-growth]');
    if (!mount) return;
    function reload() {
      api('admin-growth.php').then(function (data) {
        var coupons = data.coupons || [];
        var campaignRows = (data.campaigns || []).map(function (c) {
          return '<tr><td><div style="font-weight:600;">' + esc(c.name) + '</div><div class="mz-hint">' + esc(c.recurrence) + '</div></td>' +
            '<td>' + esc(fmtDate(c.next_run_at)) + '</td><td>' + c.sent_count + '</td>' +
            '<td><span class="mz-badge ' + (c.status === 'sent' ? 'mz-badge--ok' : c.status === 'paused' ? 'mz-badge--warn' : '') + '">' + esc(c.status) + '</span></td>' +
            '<td style="white-space:nowrap;"><button type="button" class="mz-link" data-campaign-run="' + c.id + '">Run now</button> · ' +
              '<button type="button" class="mz-link" data-campaign-toggle="' + c.id + '">' + (c.status === 'paused' ? 'Resume' : 'Pause') + '</button> · ' +
              '<button type="button" class="mz-link" data-campaign-delete="' + c.id + '">Delete</button></td></tr>';
        }).join('') || '<tr><td colspan="5" class="mz-hint">No scheduled campaigns yet.</td></tr>';
        var codeOptions = (data.codes || []).map(function (c) {
          return '<option value="' + esc(c.code) + '">' + esc(c.code) + ' · ' + esc(c.name) + ' · ' + esc(c.program) + '</option>';
        }).join('');
        var sourceSummary = (data.click_sources || []).map(function (row) { return esc(row.source) + ' (' + row.clicks + ')'; }).join(' · ');
        var rows = coupons.map(function (c) {
          return '<tr><td><div style="font-weight:600;">' + esc(c.code) + '</div><div class="mz-hint">' + esc(c.name) + '</div></td>' +
            '<td>' + esc(c.type === 'percent' ? c.value + '%' : money(c.value)) + '</td>' +
            '<td>' + esc(c.applies_to) + '</td>' +
            '<td>' + c.redemptions + '</td>' +
            '<td><span class="mz-badge ' + (c.active ? 'mz-badge--ok' : 'mz-badge--warn') + '">' + (c.active ? 'Active' : 'Paused') + '</span></td>' +
            '<td><button type="button" class="mz-link" data-coupon-delete="' + c.id + '">Delete</button></td></tr>';
        }).join('') || '<tr><td colspan="6" class="mz-hint">No coupons yet.</td></tr>';
        var cfg = data.incentive_config || {};
        var ref = data.referral_config || {};
        var ab = ref.ab_test || {};
        var abCopy = ab.variants || {};
        var abRows = (data.ab_stats || []).map(function (r) {
          return '<tr><td><strong>' + esc(r.variant) + '</strong></td><td>' + r.exposures + '</td><td>' + r.shares + '</td><td>' + r.clicks + '</td><td>' + r.signups + '</td><td>' + r.qualified + '</td><td>' + r.conversion_rate + '%</td></tr>';
        }).join('') || '<tr><td colspan="7" class="mz-hint">No A/B traffic yet.</td></tr>';
        var attributionRows = (data.attribution_sources || []).map(function (r) {
          return '<tr><td>' + esc(r.source) + '</td><td>' + r.clicks + '</td><td>' + r.signups + '</td><td>' + r.qualified + '</td><td>' + r.conversion_rate + '%</td></tr>';
        }).join('') || '<tr><td colspan="5" class="mz-hint">No attribution data yet.</td></tr>';
        var campaignStats = data.campaign_stats || {};
        mount.innerHTML =
          '<div class="mz-saas-card" style="margin-bottom:16px;">' +
            '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Coupons</div></div>' +
            '<form data-coupon-form class="mz-form-2col" style="margin-top:10px;">' +
              '<label class="mz-field"><span class="mz-label">Code</span><input class="mz-input" name="code" required maxlength="40" placeholder="MZIGO100"></label>' +
              '<label class="mz-field"><span class="mz-label">Name</span><input class="mz-input" name="name" maxlength="120" placeholder="Launch offer"></label>' +
              '<label class="mz-field"><span class="mz-label">Type</span><select class="mz-input" name="type"><option value="fixed">Fixed KES</option><option value="percent">Percent</option></select></label>' +
              '<label class="mz-field"><span class="mz-label">Value</span><input class="mz-input" name="value" type="number" min="1" value="100"></label>' +
              '<label class="mz-field"><span class="mz-label">Applies to</span><select class="mz-input" name="applies_to"><option value="delivery">Delivery</option><option value="subscription">Subscription</option><option value="all">All</option></select></label>' +
              '<label class="mz-field"><span class="mz-label">Minimum spend (KES)</span><input class="mz-input" name="min_spend_kes" type="number" min="0" value="0"></label>' +
              '<label class="mz-field"><span class="mz-label">Usage limit (blank = unlimited)</span><input class="mz-input" name="usage_limit" type="number" min="1"></label>' +
              '<label class="mz-field"><span class="mz-label">Per-user limit</span><input class="mz-input" name="per_user_limit" type="number" min="1" value="1"></label>' +
              '<label class="mz-field"><span class="mz-label">Expires at</span><input class="mz-input" name="expires_at" type="date"></label>' +
              '<label class="mz-field"><span class="mz-label">Status</span><select class="mz-input" name="active"><option value="1">Active</option><option value="0">Paused</option></select></label>' +
              '<div style="display:flex;align-items:flex-end;"><button class="mz-btn" type="submit">Create coupon</button></div>' +
            '</form>' +
            '<div class="mz-table-wrap" style="margin-top:12px;"><table class="mz-table"><thead><tr><th>Coupon</th><th>Value</th><th>Applies</th><th>Uses</th><th>Status</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
          '</div>' +
          '<div class="mz-saas-card" style="margin-bottom:16px;">' +
            '<div class="mz-card__title">Runner incentives</div>' +
            '<p class="mz-hint">Tier rates are percentages of the job fee. Cap applies per runner per month.</p>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">Monthly bonus cap (KES)</span><input class="mz-input" data-growth-field="bonus_cap_month_kes" type="number" min="0" value="' + Number(cfg.bonus_cap_month_kes || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Silver rate (%)</span><input class="mz-input" data-growth-field="tiers.silver.rate" type="number" min="0" value="' + Number((cfg.tiers && cfg.tiers.silver && cfg.tiers.silver.rate) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Gold rate (%)</span><input class="mz-input" data-growth-field="tiers.gold.rate" type="number" min="0" value="' + Number((cfg.tiers && cfg.tiers.gold && cfg.tiers.gold.rate) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Platinum rate (%)</span><input class="mz-input" data-growth-field="tiers.platinum.rate" type="number" min="0" value="' + Number((cfg.tiers && cfg.tiers.platinum && cfg.tiers.platinum.rate) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Welcome bonus (KES)</span><input class="mz-input" data-growth-field="welcome.amount_kes" type="number" min="0" value="' + Number((cfg.welcome && cfg.welcome.amount_kes) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Reliability bonus (KES)</span><input class="mz-input" data-growth-field="reliability.amount_kes" type="number" min="0" value="' + Number((cfg.reliability && cfg.reliability.amount_kes) || 0) + '"></label>' +
            '</div>' +
            '<button type="button" class="mz-btn mz-btn--sm" data-growth-save-incentive style="margin-top:8px;">Save incentives</button>' +
          '</div>' +
          '<div class="mz-saas-card">' +
            '<div class="mz-card__title">Referral rewards</div>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">Runner referrer (KES)</span><input class="mz-input" data-growth-field="runner.referrer_kes" type="number" min="0" value="' + Number((ref.runner && ref.runner.referrer_kes) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Runner referee (KES)</span><input class="mz-input" data-growth-field="runner.referee_kes" type="number" min="0" value="' + Number((ref.runner && ref.runner.referee_kes) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Business referrer (KES)</span><input class="mz-input" data-growth-field="business.referrer_kes" type="number" min="0" value="' + Number((ref.business && ref.business.referrer_kes) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Customer referrer (KES)</span><input class="mz-input" data-growth-field="customer.referrer_kes" type="number" min="0" value="' + Number((ref.customer && ref.customer.referrer_kes) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Customer referee (KES)</span><input class="mz-input" data-growth-field="customer.referee_kes" type="number" min="0" value="' + Number((ref.customer && ref.customer.referee_kes) || 0) + '"></label>' +
              '<label class="mz-field"><span class="mz-label">Attribution window (days)</span><input class="mz-input" data-growth-field="attribution_days" type="number" min="1" value="' + Number(ref.attribution_days || 60) + '"></label>' +
            '</div>' +
            '<button type="button" class="mz-btn mz-btn--sm" data-growth-save-referral style="margin-top:8px;">Save referral rewards</button>' +
            ' <button type="button" class="mz-btn mz-btn--outline mz-btn--sm" data-growth-reminders style="margin-top:8px;">Send referral reminders</button>' +
            '<div class="mz-saas-card" style="margin-top:16px;">' +
            '<div class="mz-row"><div class="mz-card__title" style="margin:0;">Referral activity</div>' +
              '<div class="mz-hint">' + (data.referral_stats ? data.referral_stats.clicks + ' clicks · ' + data.referral_stats.signups + ' sign-ups · ' + data.referral_stats.qualified + ' qualified · K-factor ' + data.referral_stats.k_factor + ' · ' + data.referral_stats.conversion_rate + '% conversion - ' + data.referral_stats.milestones + ' milestones' : '') + '</div></div>' +
            '<div class="mz-table-wrap" style="margin-top:10px;"><table class="mz-table"><thead><tr><th>Referrer</th><th>Programme</th><th>Status</th><th>Attributed</th></tr></thead><tbody>' +
              ((data.referrals || []).length ? data.referrals.map(function (r) {
                return '<tr><td><div style="font-weight:600;">' + esc(r.referrer_name || '—') + '</div><div class="mz-hint">' + esc(r.code || '') + '</div></td>' +
                  '<td>' + esc(r.program) + '</td>' +
                  '<td><span class="mz-badge ' + (r.status === 'qualified' ? 'mz-badge--ok' : '') + '">' + esc(r.status) + '</span></td>' +
                  '<td>' + esc(fmtDate(r.attributed_at)) + '</td></tr>';
              }).join('') : '<tr><td colspan="4" class="mz-hint">No referrals yet.</td></tr>') +
            '</tbody></table></div>' +
          '</div>' +
          '<div class="mz-saas-card" style="margin-top:16px;">' +
            '<div class="mz-card__title">Attribution by source</div>' +
            '<p class="mz-hint">Clicks, attributed sign-ups and qualified referrals grouped by the source captured in the link.</p>' +
            '<div class="mz-table-wrap" style="margin-top:10px;"><table class="mz-table"><thead><tr><th>Source</th><th>Clicks</th><th>Sign-ups</th><th>Qualified</th><th>Conversion</th></tr></thead><tbody>' + attributionRows + '</tbody></table></div>' +
          '</div>' +
          '<div class="mz-saas-card" style="margin-top:16px;">' +
            '<div class="mz-card__title">Referral A/B copy test</div>' +
            '<p class="mz-hint">Visitors are assigned deterministically to A or B. Exposures, shares, clicks and sign-ups are attributed from <code>viral-share-A/B</code> sources. Use <code>{code}</code> in the copy.</p>' +
            '<label style="display:flex;align-items:center;gap:8px;margin:8px 0;"><input type="checkbox" data-ab-enabled' + (ab.enabled !== false ? ' checked' : '') + '> Run A/B test</label>' +
            '<div class="mz-form-2col">' +
              '<label class="mz-field"><span class="mz-label">Variant A copy</span><textarea class="mz-input" data-ab-a rows="3">' + esc(abCopy.A || '') + '</textarea></label>' +
              '<label class="mz-field"><span class="mz-label">Variant B copy</span><textarea class="mz-input" data-ab-b rows="3">' + esc(abCopy.B || '') + '</textarea></label>' +
            '</div>' +
            '<button type="button" class="mz-btn mz-btn--sm" data-ab-save>Save A/B test</button>' +
            '<div class="mz-table-wrap" style="margin-top:12px;"><table class="mz-table"><thead><tr><th>Variant</th><th>Exposures</th><th>Shares</th><th>Clicks</th><th>Sign-ups</th><th>Qualified</th><th>Conversion</th></tr></thead><tbody>' + abRows + '</tbody></table></div>' +
          '</div>' +
          '<div class="mz-saas-card" style="margin-top:16px;">' +
            '<div class="mz-card__title">Scheduled referral campaigns</div>' +
            '<p class="mz-hint">Send a referral nudge on a schedule. The cron runner processes due campaigns; “Run now” sends immediately. Variables: <code>{code}</code> and <code>{link}</code>.</p>' +
            '<form data-campaign-form class="mz-form-2col" style="margin-top:10px;">' +
              '<label class="mz-field"><span class="mz-label">Campaign name</span><input class="mz-input" name="name" maxlength="120" placeholder="Monthly runner nudge" required></label>' +
              '<label class="mz-field"><span class="mz-label">Schedule at</span><input class="mz-input" type="datetime-local" name="schedule_at"></label>' +
              '<label class="mz-field" style="grid-column:1/-1;"><span class="mz-label">Message</span><textarea class="mz-input" name="message" rows="3" maxlength="2000" placeholder="Share your code {code} and earn when a friend activates. {link}"></textarea></label>' +
              '<label class="mz-field"><span class="mz-label">Recurrence</span><select class="mz-input" name="recurrence"><option value="once">Once</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>' +
              '<div style="display:flex;align-items:flex-end;"><button class="mz-btn" type="submit">Schedule campaign</button></div>' +
            '</form>' +
            '<div class="mz-table-wrap" style="margin-top:12px;"><table class="mz-table"><thead><tr><th>Campaign</th><th>Next run</th><th>Sent</th><th>Status</th><th></th></tr></thead><tbody>' + campaignRows + '</tbody></table></div>' +
                        '<div class="mz-hint" style="margin-top:8px;">Cron: <code>php _server/cron/referral_campaigns.php</code> every 15 minutes. Campaign results: ' + (campaignStats.sent || 0) + ' sent, ' + (campaignStats.clicks || 0) + ' clicks, ' + (campaignStats.signups || 0) + ' sign-ups, ' + (campaignStats.qualified || 0) + ' qualified. Click sources: ' + (sourceSummary || 'direct only') + '</div>' +
          '</div>' +
          '<div class="mz-saas-card" style="margin-top:16px;">' +
            '<div class="mz-card__title">Partner QR posters</div>' +
            '<p class="mz-hint">Generate a printable poster for a partner office. The QR carries the partner code and a <code>partner-poster</code> source for tracking.</p>' +
            '<div class="mz-form-2col" style="margin-top:10px;">' +
              '<label class="mz-field"><span class="mz-label">Referral code</span><input class="mz-input" data-poster-code-input list="growth-code-list" placeholder="MZ-XXXXXX"><datalist id="growth-code-list">' + codeOptions + '</datalist></label>' +
              '<label class="mz-field"><span class="mz-label">Programme</span><select class="mz-input" data-poster-program><option value="runner">Runner</option><option value="business">Business</option><option value="customer">Customer</option></select></label>' +
              '<label class="mz-field"><span class="mz-label">Partner label</span><input class="mz-input" data-poster-label-input placeholder="Partner office name"></label>' +
              '<div style="display:flex;align-items:flex-end;gap:8px;"><button type="button" class="mz-btn" data-poster-generate>Generate poster</button><a class="mz-btn mz-btn--outline" data-poster-preview target="_blank" rel="noopener" hidden>Open</a></div>' +
            '</div>' +
            '<div class="mz-hint" data-poster-link style="margin-top:8px;">Choose a code and generate a poster link.</div>' +
          '</div>';

        var form = mount.querySelector('[data-coupon-form]');
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var body = { action: 'save_coupon' };
          Array.prototype.forEach.call(form.elements, function (el) { if (el.name) body[el.name] = el.value; });
          api('admin-growth.php', { method: 'POST', body: body }).then(function () { toast('Coupon created.'); reload(); }).catch(function (err) { toast(err.message, 'error'); });
        });
        var campaignForm = mount.querySelector('[data-campaign-form]');
        campaignForm.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var body = { action: 'save_campaign' };
          Array.prototype.forEach.call(campaignForm.elements, function (el) { if (el.name) body[el.name] = el.value; });
          api('admin-growth.php', { method: 'POST', body: body }).then(function () { toast('Campaign scheduled.'); reload(); }).catch(function (err) { toast(err.message, 'error'); });
        });
        mount.querySelectorAll('[data-campaign-run]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            btn.disabled = true;
            api('admin-growth.php', { method: 'POST', body: { action: 'run_campaign', id: Number(btn.getAttribute('data-campaign-run')) } })
              .then(function (res) { toast('Campaign sent to ' + res.sent + ' user(s).'); reload(); })
              .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
          });
        });
        mount.querySelectorAll('[data-campaign-toggle]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            api('admin-growth.php', { method: 'POST', body: { action: 'toggle_campaign', id: Number(btn.getAttribute('data-campaign-toggle')) } })
              .then(function () { reload(); }).catch(function (err) { toast(err.message, 'error'); });
          });
        });
        mount.querySelectorAll('[data-campaign-delete]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            if (!window.confirm('Delete this campaign?')) return;
            api('admin-growth.php', { method: 'POST', body: { action: 'delete_campaign', id: Number(btn.getAttribute('data-campaign-delete')) } })
              .then(function () { toast('Campaign deleted.'); reload(); }).catch(function (err) { toast(err.message, 'error'); });
          });
        });
        mount.querySelector('[data-poster-generate]').addEventListener('click', function () {
          var code = (mount.querySelector('[data-poster-code-input]').value || '').trim();
          var program = mount.querySelector('[data-poster-program]').value;
          var label = (mount.querySelector('[data-poster-label-input]').value || 'Mzigo partner').trim();
          if (!code) { toast('Enter or choose a referral code.', 'error'); return; }
          var url = location.origin + '/partner-poster?ref=' + encodeURIComponent(code) + '&program=' + encodeURIComponent(program) + '&label=' + encodeURIComponent(label) + '&src=partner-poster';
          var link = mount.querySelector('[data-poster-preview]');
          link.hidden = false; link.setAttribute('href', url);
          mount.querySelector('[data-poster-link]').innerHTML = '<a class="mz-link" target="_blank" rel="noopener" href="' + url + '">' + esc(url) + '</a>';
          if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { toast('Poster link copied.'); });
        });
        var abSave = mount.querySelector('[data-ab-save]');
        if (abSave) abSave.addEventListener('click', function () {
          abSave.disabled = true;
          api('admin-growth.php', { method: 'POST', body: { action: 'save_referral_config', config: { ab_test: {
            enabled: mount.querySelector('[data-ab-enabled]').checked,
            variants: { A: mount.querySelector('[data-ab-a]').value, B: mount.querySelector('[data-ab-b]').value }
          } } } }).then(function () { toast('A/B test saved.'); reload(); }).catch(function (err) { toast(err.message, 'error'); abSave.disabled = false; });
        });
        mount.querySelectorAll('[data-coupon-delete]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            if (!window.confirm('Delete this coupon?')) return;
            api('admin-growth.php', { method: 'POST', body: { action: 'delete_coupon', id: Number(btn.getAttribute('data-coupon-delete')) } })
              .then(function () { toast('Coupon deleted.'); reload(); }).catch(function (err) { toast(err.message, 'error'); });
          });
        });
        mount.querySelector('[data-growth-save-incentive]').addEventListener('click', function () {
          var config = { tiers: {}, bonus_cap_month_kes: Number(mount.querySelector('[data-growth-field="bonus_cap_month_kes"]').value || 0) };
          ['silver', 'gold', 'platinum'].forEach(function (code) {
            config.tiers[code] = { rate: Number(mount.querySelector('[data-growth-field="tiers.' + code + '.rate"]').value || 0) };
          });
          config.welcome = { amount_kes: Number(mount.querySelector('[data-growth-field="welcome.amount_kes"]').value || 0) };
          config.reliability = { amount_kes: Number(mount.querySelector('[data-growth-field="reliability.amount_kes"]').value || 0) };
          api('admin-growth.php', { method: 'POST', body: { action: 'save_incentive_config', config: config } })
            .then(function () { toast('Incentive settings saved.'); }).catch(function (err) { toast(err.message, 'error'); });
        });
        mount.querySelector('[data-growth-reminders]').addEventListener('click', function (ev) {
          var btn = ev.currentTarget; btn.disabled = true;
          api('admin-growth.php', { method: 'POST', body: { action: 'send_referral_reminders' } })
            .then(function (res) { toast(res.message); btn.disabled = false; })
            .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        });
        mount.querySelector('[data-growth-save-referral]').addEventListener('click', function () {
          var config = { runner: {}, business: {}, customer: {} };
          mount.querySelectorAll('[data-growth-field^="runner."]').forEach(function (el) { config.runner[el.getAttribute('data-growth-field').split('.')[1]] = Number(el.value || 0); });
          mount.querySelectorAll('[data-growth-field^="business."]').forEach(function (el) { config.business[el.getAttribute('data-growth-field').split('.')[1]] = Number(el.value || 0); });
          mount.querySelectorAll('[data-growth-field^="customer."]').forEach(function (el) { config.customer[el.getAttribute('data-growth-field').split('.')[1]] = Number(el.value || 0); });
          config.attribution_days = Number(mount.querySelector('[data-growth-field="attribution_days"]').value || 60);
          api('admin-growth.php', { method: 'POST', body: { action: 'save_referral_config', config: config } })
            .then(function () { toast('Referral rewards saved.'); }).catch(function (err) { toast(err.message, 'error'); });
        });
      }).catch(function (err) {
        mount.innerHTML = '<div class="mz-alert">Could not load growth settings: ' + esc(err.message) + '</div>';
      });
    }
    reload();
  }

  function initAdminAlerts(host) {
    var mount = host.querySelector('[data-admin-alerts]');
    if (!mount) return;
    api('admin-alerts.php').then(function (data) {
      var alerts = (data.alerts || []).filter(function (a) { return a.count > 0; });
      mount.innerHTML = '<div class="mz-card__title">Ops alerts</div>' +
        (alerts.length ? alerts.map(function (a) {
          return '<div class="mz-alert" style="margin-top:8px;display:flex;justify-content:space-between;gap:10px;">' +
            '<span><strong>' + esc(a.label) + ':</strong> ' + a.count + '</span>' +
            '<a class="mz-link" href="' + esc(a.href) + '">Review</a></div>';
        }).join('') : '<p class="mz-hint">No operational alerts right now.</p>');
    }).catch(function (err) {
      mount.innerHTML = '<div class="mz-hint">Could not load alerts: ' + esc(err.message) + '</div>';
    });
  }

  function initAdminEvents(host) {
    var mount = host.querySelector('[data-admin-events]');
    if (!mount) return;
    mount.innerHTML = '<div class="mz-hint">Loading CTA &amp; funnel events?</div>';
    api('admin-events.php').then(function (data) {
      var counts = data.counts || [];
      var recent = data.recent || [];
      mount.innerHTML =
        '<div data-admin-cta-events></div>' +
        '<div data-admin-cta-recent style="margin-top:16px;"></div>';
      renderPagedTable(mount.querySelector('[data-admin-cta-events]'), {
        title: 'CTA & funnel events (last 7 days)',
        columns: ['Event', 'Count (7 days)', 'Signed in'],
        rows: counts.map(function (c) {
          return '<tr><td>' + esc(c.event) + '</td><td>' + c.count + '</td><td>' + c.signed_in + '</td></tr>';
        }),
        empty: 'No analytics events yet.',
        perPage: 6,
        page: adminTablePages.ctaEvents || 1,
        onPage: function (page) { adminTablePages.ctaEvents = page; }
      });
      var recentRows = recent.map(function (r) {
        return '<tr><td>' + esc(r.label) + '</td><td>' + esc(r.location) + '</td><td>' + esc(r.audience) + '</td>' +
          '<td class="mz-mono" style="font-size:11px;">' + esc(r.path) + '</td><td>' + esc(fmtDate(r.created_at)) + '</td></tr>';
      });
      renderPagedTable(mount.querySelector('[data-admin-cta-recent]'), {
        title: 'Recent CTA clicks',
        columns: ['CTA', 'Location', 'Audience', 'Page', 'When'],
        rows: recentRows,
        empty: 'No CTA clicks captured yet.',
        perPage: 6,
        page: adminTablePages.ctaClicks || 1,
        onPage: function (page) { adminTablePages.ctaClicks = page; }
      });
    }).catch(function (err) {
      mount.innerHTML = '<div class="mz-hint">Could not load events: ' + esc(err.message) + '</div>';
    });
  }

  function initAdminRunnerJobs(host) {
    var mount = host.querySelector('[data-admin-runner-jobs]');
    if (!mount) return;

    function render(data) {
      var jobs = data.jobs || [];
      var runners = data.runners || [];
      var runnerOptions = function () {
        return '<option value="">Assign runner…</option>' + runners.map(function (r) {
          return '<option value="' + r.id + '">' + esc(r.name) + ' · ' + esc(r.phone) + (r.available_now ? ' · available' : '') + '</option>';
        }).join('');
      };
      var rows = jobs.length ? jobs.map(function (job) {
        var actions = '';
        if (job.status === 'available') {
          actions = '<select class="mz-input" style="height:30px;padding:0 8px;font-size:11px;display:inline-block;width:auto;" data-rj-runner="' + job.id + '" aria-label="Runner for ' + esc(job.ref) + '">' + runnerOptions() + '</select> ' +
            '<button type="button" class="mz-link" data-rj-assign="' + job.id + '">Assign</button> · ';
        }
        if (job.status !== 'delivered' && job.status !== 'cancelled') {
          actions += '<button type="button" class="mz-link" data-rj-cancel="' + job.id + '">Cancel</button>';
        }
        if (job.status === 'delivered') {
          actions += '<select class="mz-input" style="height:30px;padding:0 6px;font-size:11px;display:inline-block;width:auto;" data-rj-rating="' + job.id + '" aria-label="Rate ' + esc(job.ref) + '">' +
            '<option value="">Rate…</option>' + [1,2,3,4,5].map(function (n) { return '<option value="' + n + '">' + n + '★</option>'; }).join('') + '</select> ' +
            '<button type="button" class="mz-link" data-rj-rate="' + job.id + '">Save</button>';
          if (job.proof_photo_path) actions += ' · <a class="mz-link" href="/api/runner-proof-view.php?id=' + job.id + '" target="_blank" rel="noopener">Proof</a>';
        }
        if (job.status === 'cancelled') {
          actions += '<button type="button" class="mz-link" data-rj-reopen="' + job.id + '">Reopen</button>';
        }
        if (job.status === 'cancelled' || job.status === 'delivered') {
          actions += ' <span class="mz-hint">' + esc(job.status) + '</span>';
        }
        return '<tr><td class="mz-mono">' + esc(job.ref) + '</td>' +
          '<td>' + esc(job.pickup) + ' → ' + esc(job.dropoff) + '<div class="mz-hint">' + esc(job.description || '') + '</div></td>' +
          '<td>' + money(job.fee_kes) + '</td>' +
          '<td><span class="mz-pill">' + esc(job.status) + '</span></td>' +
          '<td>' + esc(job.runner_name || '—') + (job.runner_phone ? '<div class="mz-hint">' + esc(job.runner_phone) + '</div>' : '') + '</td>' +
          '<td style="white-space:nowrap;">' + actions + '</td></tr>';
      }).join('') : '<tr><td colspan="6"><div class="mz-hint" style="text-align:center;padding:16px;">No runner jobs yet. Create one above or book an errand.</div></td></tr>';

      mount.innerHTML =
        '<div class="mz-row" style="margin-bottom:10px;"><div class="mz-card__title" style="margin:0;">Create a runner job</div>' +
          '<button type="button" class="mz-tab" data-rj-refresh>Refresh</button></div>' +
        '<form data-rj-create class="mz-stack">' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Pickup</span><input class="mz-input" name="pickup" placeholder="e.g. Nairobi CBD, Kencom" required></label>' +
            '<label class="mz-field"><span class="mz-label">Drop-off</span><input class="mz-input" name="dropoff" placeholder="e.g. Westlands, Sarit Centre" required></label>' +
          '</div>' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Runner fee (KES)</span><input class="mz-input" name="fee_kes" type="number" min="1" step="1" value="250" required></label>' +
            '<label class="mz-field"><span class="mz-label">Description</span><input class="mz-input" name="description" placeholder="e.g. Documents envelope"></label>' +
          '</div>' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Sender name</span><input class="mz-input" name="contact_name"></label>' +
            '<label class="mz-field"><span class="mz-label">Sender phone</span><input class="mz-input" name="contact_phone" type="tel"></label>' +
          '</div>' +
          '<div class="mz-form-grid">' +
            '<label class="mz-field"><span class="mz-label">Receiver name</span><input class="mz-input" name="receiver_name"></label>' +
            '<label class="mz-field"><span class="mz-label">Receiver phone</span><input class="mz-input" name="receiver_phone" type="tel"></label>' +
          '</div>' +
          '<div><button class="mz-btn" type="submit">Create job</button></div>' +
        '</form>' +
        '<div class="mz-card__title" style="margin-top:20px;">Runner jobs</div>' +
        '<div class="mz-table-wrap"><table class="mz-table"><thead><tr><th>Ref</th><th>Route</th><th>Fee</th><th>Status</th><th>Runner</th><th>Actions</th></tr></thead><tbody>' + rows + '</tbody></table></div>';

      var create = mount.querySelector('[data-rj-create]');
      if (create) create.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = create.querySelector('[type="submit"]');
        btn.disabled = true;
        api('admin-runner-jobs.php', {
          method: 'POST',
          body: {
            action: 'create-job',
            pickup: formValue(create, 'pickup'),
            dropoff: formValue(create, 'dropoff'),
            fee_kes: Number(formValue(create, 'fee_kes')),
            description: formValue(create, 'description'),
            contact_name: formValue(create, 'contact_name'),
            contact_phone: formValue(create, 'contact_phone'),
            receiver_name: formValue(create, 'receiver_name'),
            receiver_phone: formValue(create, 'receiver_phone')
          }
        }).then(function (fresh) { toast('Runner job created.'); render(fresh); })
          .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
      Array.prototype.forEach.call(mount.querySelectorAll('[data-rj-assign]'), function (btn) {
        btn.addEventListener('click', function () {
          var jobId = btn.getAttribute('data-rj-assign');
          var select = mount.querySelector('[data-rj-runner="' + jobId + '"]');
          var runnerId = select ? Number(select.value) : 0;
          if (!runnerId) { toast('Choose a runner first.', 'error'); return; }
          btn.disabled = true;
          api('admin-runner-jobs.php', { method: 'POST', body: { action: 'assign-job', job_id: Number(jobId), runner_id: runnerId } })
            .then(function (fresh) { toast('Job assigned.'); render(fresh); })
            .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        });
      });
      Array.prototype.forEach.call(mount.querySelectorAll('[data-rj-cancel]'), function (btn) {
        btn.addEventListener('click', function () {
          btn.disabled = true;
          api('admin-runner-jobs.php', { method: 'POST', body: { action: 'cancel-job', job_id: Number(btn.getAttribute('data-rj-cancel')) } })
            .then(function (fresh) { toast('Job cancelled.'); render(fresh); })
            .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        });
      });
      Array.prototype.forEach.call(mount.querySelectorAll('[data-rj-rate]'), function (btn) {
        btn.addEventListener('click', function () {
          var jobId = btn.getAttribute('data-rj-rate');
          var select = mount.querySelector('[data-rj-rating="' + jobId + '"]');
          var rating = select ? Number(select.value) : 0;
          if (!rating) { toast('Choose a rating first.', 'error'); return; }
          btn.disabled = true;
          api('admin-runner-jobs.php', { method: 'POST', body: { action: 'rate-job', job_id: Number(jobId), rating: rating } })
            .then(function (fresh) { toast('Rating saved.'); render(fresh); })
            .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        });
      });
      Array.prototype.forEach.call(mount.querySelectorAll('[data-rj-reopen]'), function (btn) {
        btn.addEventListener('click', function () {
          btn.disabled = true;
          api('admin-runner-jobs.php', { method: 'POST', body: { action: 'reopen-job', job_id: Number(btn.getAttribute('data-rj-reopen')) } })
            .then(function (fresh) { toast('Job reopened.'); render(fresh); })
            .catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
        });
      });
      var refresh = mount.querySelector('[data-rj-refresh]');
      if (refresh) refresh.addEventListener('click', function () { load(); });
    }

    function load() {
      mount.innerHTML = '<div class="mz-hint">Loading runner jobs…</div>';
      api('admin-runner-jobs.php').then(render).catch(function (err) {
        mount.innerHTML = '<div class="mz-hint">Could not load runner jobs: ' + esc(err.message) + '</div>';
      });
    }
    load();
  }

  function renderAdmin(host, data) {
    var m = data.metrics;
    adminChannelProviders = data.channel_providers || {};
    adminSenderHints = data.channel_sender_hints || {};
    var stat = function (label, value) {
      return '<div class="mz-stat"><div class="mz-cap">' + label + '</div><div class="mz-stat__value">' + value + '</div></div>';
    };
    var metrics =
      stat('Workspaces', m.tenants_total) +
      stat('Active', m.tenants_active) +
      stat('On trial', m.tenants_trial) +
      stat('Suspended', m.tenants_suspended) +
      stat('Pending invoices', m.invoices_pending);

    var planOptionsFor = function (current) {
      return data.plans.map(function (p) {
        return '<option value="' + p.code + '"' + (p.code === current ? ' selected' : '') + '>' + esc(p.name) + '</option>';
      }).join('');
    };

    var tenantRows = data.tenants.map(function (t) {
      return '<tr><td><div style="font-weight:600;">' + esc(t.name) + '</div><div style="font-size:11px;color:var(--mz-muted-fg);">' + esc(t.slug) + '</div></td>' +
        '<td>' + esc(t.owner_email || '—') + '</td>' +
        '<td><span class="mz-badge">' + esc(t.plan_name || '—') + '</span></td>' +
        '<td><span class="mz-badge mz-badge--' + (t.status === 'active' ? 'ok' : t.status === 'trial' ? 'warn' : '') + '">' + esc(t.status) + '</span></td>' +
        '<td>' + t.parcels_month + '</td>' +
        '<td>' + money(t.paid_kes) + '</td>' +
        '<td style="white-space:nowrap;">' +
          '<select class="mz-input" style="height:30px;padding:0 8px;font-size:11px;display:inline-block;width:auto;" data-plan-select="' + t.id + '" aria-label="Plan for ' + esc(t.name) + '">' + planOptionsFor(t.plan_code) + '</select> ' +
          '<button type="button" class="mz-link" data-set-plan="' + t.id + '">Set</button> · ' +
          (t.status === 'suspended'
            ? '<button type="button" class="mz-link" data-tenant-action="activate_tenant" data-tenant="' + t.id + '">Activate</button>'
            : '<button type="button" class="mz-link" data-tenant-action="suspend_tenant" data-tenant="' + t.id + '">Suspend</button>') +
        '</td></tr>';
    });

    var invoiceRows = data.invoices.map(function (inv) {
      return '<tr><td class="mz-mono">' + esc(inv.number) + '</td><td>' + esc(inv.tenant_name) + '</td>' +
        '<td>' + esc(inv.plan_code) + '</td><td>' + money(inv.amount_kes) + '</td>' +
        '<td><span class="mz-badge mz-badge--' + (inv.status === 'paid' ? 'ok' : inv.status === 'pending' ? 'warn' : 'mz-badge--warn') + '">' + esc(inv.status) + '</span></td>' +
        '<td>' + (inv.status === 'pending'
          ? '<button type="button" class="mz-link" data-invoice-action="mark_invoice_paid" data-invoice="' + inv.id + '">Mark paid</button> · ' +
            '<button type="button" class="mz-link" data-invoice-action="void_invoice" data-invoice="' + inv.id + '">Void</button>'
          : (inv.status === 'paid'
            ? '<button type="button" class="mz-link" data-admin-refund="' + inv.id + '">Refund</button>'
            : (inv.refund_ref ? '<span style="font-size:11px;color:var(--mz-muted-fg);">' + esc(inv.refund_ref) + '</span>' : ''))) + '</td></tr>';
    });


    var nav = adminNavList(data);
    host.innerHTML =
      '<div class="mz-admin-layout">' +
        '<aside class="mz-admin-nav" aria-label="Admin sections">' +
          '<div class="mz-admin-nav__title">Mzigo.online</div>' +
          nav.map(function (item) {
            return '<button type="button" class="mz-admin-nav__item' + (item[0] === adminInitialSection() ? ' is-active' : '') + '" data-admin-goto="' + item[0] + '">' +
              adminIcon(item[0]) + '<span class="mz-admin-nav__label">' + item[1] + '</span>' +
              (item[2] ? '<span class="mz-admin-nav__badge">' + esc(String(item[2])) + '</span>' : '') + '</button>';
          }).join('') +
        '</aside>' +
        '<div class="mz-admin-main">' +
          '<section class="mz-admin-section" data-admin-section="overview">' +
            adminSectionHead('Overview', 'Platform performance overview — revenue, volume and operational mix.') +
            '<div class="mz-saas-card" data-admin-analytics></div>' +
            '<div class="mz-overview-divider">Platform detail</div>' +
            '<div class="mz-stats" style="margin-bottom:14px;">' + metrics + '</div>' +
            '<div class="mz-saas-card" data-admin-api-usage style="margin-top:16px;"></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="analytics" hidden>' +
            adminSectionHead('Analytics', 'Customer promise and operations.') +
            '<div data-admin-overview></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="workspaces" hidden>' +
            adminSectionHead('Workspaces', 'Tenant accounts, plans, status and parcel usage.') +
            '<div class="mz-saas-card" data-admin-table="workspaces"></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="invoices" hidden>' +
            adminSectionHead('Invoices', 'Subscription invoices, whether pending, paid or void.') +
            '<div class="mz-saas-card" data-admin-table="invoices"></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="transactions" hidden>' +
            adminSectionHead('Transactions', 'Every payment recorded in the platform ledger.') +
            '<div class="mz-saas-card" data-admin-transactions></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="unassigned" hidden>' +
            adminSectionHead('Unassigned parcels', 'Read-only queue of parcels that are not associated with a workspace. Public tracking keeps working.') +
            '<div class="mz-saas-card" data-admin-unassigned></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="claims" hidden>' +
            adminSectionHead('Claims', 'Lost, damaged and delayed parcel claims, customer reports, risk and payouts.') +
            '<div class="mz-saas-card" data-admin-claim-analytics style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-claim-risk style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-claims></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="runnerjobs" hidden>' +
            adminSectionHead('Runner jobs', 'Create, assign and cancel runner errands — ops dispatch without a booking.') +
            '<div class="mz-saas-card" data-admin-runner-jobs></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="kyc" hidden>' +
            adminSectionHead('KYC approvals', 'Review runner ID and merchant business verification documents.') +
            '<div class="mz-saas-card" data-admin-kyc></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="insurance" hidden>' +
            adminSectionHead('Insurance', 'Configure the insurer API. Live mode issues a real policy number at parcel registration; demo mode issues clearly-labelled demo policies.') +
            '<div data-admin-insurance></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="messages" hidden>' +
            adminSectionHead('Messages', 'Contact-form submissions from the website — open, reply, note, archive or delete them.') +
            '<div class="mz-saas-card" data-admin-messages></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="sessions" hidden>' +
            adminSectionHead('Sessions', 'Active and recent sign-ins, stored in MySQL.') +
            '<div class="mz-saas-card" data-admin-sessions></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="users" hidden>' +
            adminSectionHead('Users', 'Create and manage admin, merchant, employee, freelancer and customer accounts.') +
            '<div class="mz-saas-card" data-admin-users></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="communications" hidden>' +
            adminSectionHead('Communications', 'Channel controls, SMTP delivery testing and the outbound customer message log.') +
            '<div class="mz-saas-card" data-admin-email-test style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-sms-test style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-deliverability style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-grid mz-saas-grid--2 mz-comms-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">' +
              '<div class="mz-saas-card" data-admin-channels></div>' +
              '<div class="mz-saas-card" data-admin-notifications></div>' +
            '</div>' +
            '<div class="mz-saas-card" data-admin-comms-templates style="margin-top:16px;"></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="whatsapp" hidden>' +
            adminSectionHead('WhatsApp', 'Phase 1 AI assistant — FAQs, parcel tracking and human handoff.') +
            '<div data-admin-whatsapp></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="campaigns" hidden>' +
            adminSectionHead('Campaigns', 'Marketing messages for confirmed newsletter and alert subscribers. Transactional order and onboarding messages stay in Communications.') +
            '<div class="mz-saas-card" data-admin-campaigns></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="points" hidden>' +
            adminSectionHead('Scan points', 'Partner drop-off, handover, arrival and collection points, with one-time access tokens for the mobile scanner.') +
            '<div class="mz-saas-card" data-admin-points></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="activities" hidden>' +
            adminSectionHead('Activities', 'Operational alerts plus the audit trail of authentication, billing and admin actions.') +
            '<div class="mz-saas-card" data-admin-alerts style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-audit-card></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="growth" hidden>' +
            adminSectionHead('Coupons', 'Coupon codes, runner bonuses and referral rewards — changes apply immediately.') +
            '<div data-admin-growth></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="faqs" hidden>' +
            adminSectionHead('Landing-page FAQs', 'Questions shown on the landing page and the full FAQ page — no deploy needed.') +
            '<div class="mz-saas-card" data-admin-faqs></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="cms" hidden>' +
            adminSectionHead('Website', 'Brand assets, landing-page copy, social links and demo visibility — no deploy needed.') +
            '<div class="mz-saas-card" data-admin-branding style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-hero-copy style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-newsletter style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-social style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-demo-accounts></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="seo" hidden>' +
            adminSectionHead('SEO', 'Search visibility, sitemap, indexing and CTA funnel events.') +
            '<div class="mz-saas-card" data-admin-seo-access style="margin-bottom:16px;">' +
              '<div class="mz-row"><div><div class="mz-card__title" style="margin:0;">Indexing and crawling</div>' +
              '<p class="mz-hint" style="margin:4px 0 0;">Open the generated sitemap, robots file and public pages. Verification and Core Web Vitals settings are below.</p></div></div>' +
              '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">' +
                '<a class="mz-btn" href="/sitemap.xml" target="_blank" rel="noopener">Open sitemap</a>' +
                '<a class="mz-btn mz-btn--outline" href="/robots.txt" target="_blank" rel="noopener">Open robots.txt</a>' +
                '<a class="mz-btn mz-btn--outline" href="/" target="_blank" rel="noopener">Open public site</a>' +
                '<a class="mz-btn mz-btn--outline" href="/partners" target="_blank" rel="noopener">Partner pages</a>' +
              '</div>' +
              '<p class="mz-hint" style="margin:10px 0 0;">External: <a class="mz-link" href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> &middot; <a class="mz-link" href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a></p>' +
            '</div>' +
            '<div class="mz-saas-card" data-admin-seo style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-events></div>' +
          '</section>' +
          '<section class="mz-admin-section" data-admin-section="settings" hidden>' +
            adminSectionHead('Settings', 'Payouts, runner areas/KYC, pricing and platform configuration.') +
            '<div class="mz-saas-card" data-admin-payout-settings style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-runner-config style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-claim-rails style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-pricing style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card" data-admin-twofa style="margin-bottom:16px;"></div>' +
            '<div class="mz-saas-card"><div class="mz-card__title">Platform</div><div class="mz-kv"><dt>Software</dt><dd>Mzigo.online SaaS</dd></div>' +
            '<div class="mz-kv"><dt>Signed in as</dt><dd>' + esc((data.admin && data.admin.name) || 'Platform admin') + '</dd></div>' +
            '<div class="mz-kv"><dt>Database</dt><dd>' + esc(APP.database || '') + '</dd></div>' +
            '<div class="mz-kv"><dt>Notifications</dt><dd>' + esc(String((data.channels || []).filter(function (c) { return c.is_active; }).map(function (c) { return c.label; }).join(', ') || '—')) + '</dd></div></div>' +
          '</section>' +
        '</div>' +
      '</div>';

    switchAdminSection(host, adminInitialSection(), false);
    host.querySelectorAll('[data-admin-goto]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchAdminSection(host, btn.getAttribute('data-admin-goto'), true);
      });
    });
    if (!window.__mzAdminHashBound) {
      window.__mzAdminHashBound = true;
      window.addEventListener('hashchange', function () {
        var target = document.querySelector('[data-app="admin"]');
        if (target && target.querySelector('.mz-admin-layout')) {
          switchAdminSection(target, adminInitialSection(), false);
        }
      });
    }

    renderPagedTable(host.querySelector('[data-admin-table="workspaces"]'), {
      title: 'Workspaces',
      columns: ['Workspace', 'Owner', 'Plan', 'Status', 'Parcels/mo', 'Paid', 'Actions'],
      rows: tenantRows,
      empty: 'No workspaces yet.',
      perPage: ADMIN_TABLE_PER_PAGE,
      page: adminTablePages.workspaces,
      onPage: function (page) { adminTablePages.workspaces = page; }
    });
    renderPagedTable(host.querySelector('[data-admin-table="invoices"]'), {
      title: 'Invoices',
      columns: ['Invoice', 'Workspace', 'Plan', 'Amount', 'Status', ''],
      rows: invoiceRows,
      empty: 'No invoices yet.',
      perPage: ADMIN_TABLE_PER_PAGE,
      page: adminTablePages.invoices,
      onPage: function (page) { adminTablePages.invoices = page; }
    });

    var txLabels = { subscription: 'Subscription', parcel_payment: 'Parcel collection', manual: 'Manual', refund: 'Refund' };
    var txRows = (data.transactions || []).map(function (tx) {
      return '<tr><td class="mz-mono">' + esc(tx.reference) + '</td>' +
        '<td>' + esc(tx.tenant_name || '—') + '</td>' +
        '<td>' + esc(txLabels[tx.type] || tx.type) + '</td>' +
        '<td>' + money(tx.amount_kes) + '</td>' +
        '<td>' + esc(tx.method) + '</td>' +
        '<td><span class="mz-badge mz-badge--' + (tx.status === 'succeeded' ? 'ok' : tx.status === 'failed' ? 'warn' : '') + '">' + esc(tx.status) + '</span></td>' +
        '<td style="white-space:nowrap;">' + esc(fmtDate(tx.created_at)) + '</td></tr>';
    });
    renderPagedTable(host.querySelector('[data-admin-transactions]'), {
      title: 'Transactions',
      columns: ['Reference', 'Workspace', 'Type', 'Amount', 'Method', 'Status', 'Date'],
      rows: txRows,
      empty: 'No transactions yet.',
      perPage: 5,
      page: adminTablePages.transactions || 1,
      onPage: function (page) { adminTablePages.transactions = page; }
    });

    renderAdminUnassigned(host.querySelector('[data-admin-unassigned]'), data.unassigned_parcels || []);
    renderAdminClaimAnalytics(host.querySelector('[data-admin-claim-analytics]'), data.claims_analytics || {});
    renderAdminClaimRisk(host.querySelector('[data-admin-claim-risk]'), data.claim_blacklist || []);
    renderAdminClaims(host.querySelector('[data-admin-claims]'), data.claims || []);
    renderAdminMessages(host.querySelector('[data-admin-messages]'), data.contact_messages || {});
    renderAdminHeroCopy(host.querySelector('[data-admin-hero-copy]'), data.branding || {});
    renderAdminNewsletter(host.querySelector('[data-admin-newsletter]'), data.newsletter_prompt || {});
    renderAdminSocialLinks(host.querySelector('[data-admin-social]'), data.branding || {});
    renderAdminSeo(host.querySelector('[data-admin-seo]'), data.seo || {}, data.web_vitals || {});
    renderAdminDemoAccounts(host.querySelector('[data-admin-demo-accounts]'), data.show_demo_accounts !== false);
    renderAdminBranding(host.querySelector('[data-admin-branding]'), data.branding || {});
    renderAdminFaqs(host.querySelector('[data-admin-faqs]'), data.faqs || []);
    renderAdminSessions(host.querySelector('[data-admin-sessions]'), data.sessions || { active: 0, rows: [] });
    renderAdminUsers(host.querySelector('[data-admin-users]'), data);
    renderAdminChannels(host.querySelector('[data-admin-channels]'), data.channels || []);
    renderAdminEmailTest(host.querySelector('[data-admin-email-test]'), data.admin || {});
    renderAdminSmsTest(host.querySelector('[data-admin-sms-test]'));
    renderAdminNotifications(host.querySelector('[data-admin-notifications]'), data.notifications || []);
    renderAdminCommsTemplates(host.querySelector('[data-admin-comms-templates]'), data.comms_templates || []);
    renderAdminDeliverability(host.querySelector('[data-admin-deliverability]'), data.email_deliverability || {});
    renderAdminApiUsage(host.querySelector('[data-admin-api-usage]'), data.api_usage || {});
    renderAdminPoints(host.querySelector('[data-admin-points]'), data);
    renderAdminClaimRails(host.querySelector('[data-admin-claim-rails]'), data.claim_rails || {});
    renderAdminPricing(host.querySelector('[data-admin-pricing]'), data);
    renderAdminTwoFactor(host.querySelector('[data-admin-twofa]'), data.twofa || {});
    renderAuditCard(host.querySelector('[data-audit-card]'), data.audit || [], 1);

    initAdminOverview(host);
    initAdminAnalytics(host);
    initAdminWhatsApp(host);
    initAdminGrowth(host);
    initAdminAlerts(host);
    initAdminEvents(host);
    initAdminCampaigns(host);
    initAdminKyc(host);
    initAdminInsurance(host);
    initAdminRunnerJobs(host);
    renderAdminPayoutSettings(host.querySelector('[data-admin-payout-settings]'), data);
    renderAdminRunnerConfig(host.querySelector('[data-admin-runner-config]'), data);
    bindAdminActions(host);
  }

  function ensurePricingLink() {
    if (APP.pricingMode === 'free') return;
    var footer = $('footer');
    if (!footer || footer.querySelector('a[href="/pricing"]')) return;
    var sendLink = null;
    footer.querySelectorAll('a').forEach(function (a) {
      if (!sendLink && a.getAttribute('href') === '/send-mzigo') sendLink = a;
    });
    if (!sendLink || !sendLink.parentElement) return;
    var li = sendLink.parentElement.cloneNode(true);
    var link = li.querySelector('a');
    if (!link) return;
    link.setAttribute('href', '/pricing');
    link.textContent = 'Pricing';
    sendLink.parentElement.parentElement.appendChild(li);
  }

  function initNoopLinks() {
    $$('a[href="#"]').forEach(function (a) {
      if (a.hasAttribute('data-mz-auth')) return;
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        toast("That page isn't available yet. Contact support and we'll help you find it.", 'info');
      });
    });
  }

  function initWebVitals() {
    var page = doc.body.getAttribute('data-page') || '';
    if (['admin','settings','freelancer','customer','orders','claims','billing'].indexOf(page) !== -1) return;
    if (!('PerformanceObserver' in window)) return;
    var metrics = {};
    var rating = function (name, value) {
      if (name === 'CLS') return value <= 0.1 ? 'good' : (value <= 0.25 ? 'needs-improvement' : 'poor');
      if (name === 'INP') return value <= 200 ? 'good' : (value <= 500 ? 'needs-improvement' : 'poor');
      if (name === 'LCP') return value <= 2500 ? 'good' : (value <= 4000 ? 'needs-improvement' : 'poor');
      if (name === 'FCP') return value <= 1800 ? 'good' : (value <= 3000 ? 'needs-improvement' : 'poor');
      return value <= 800 ? 'good' : (value <= 1800 ? 'needs-improvement' : 'poor');
    };
    var set = function (name, value) {
      if (!isFinite(value) || value < 0) return;
      metrics[name] = { metric: name, value: name === 'CLS' ? Math.round(value * 1000) / 1000 : Math.round(value), rating: rating(name, value) };
    };
    try {
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) { if (entry.name === 'largest-contentful-paint') set('LCP', entry.startTime); });
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {}
    try {
      var cls = 0;
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) { if (!entry.hadRecentInput) cls += entry.value; });
        set('CLS', cls);
      }).observe({ type: 'layout-shift', buffered: true });
    } catch (e) {}
    try {
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) { if (entry.duration) set('INP', Math.max(metrics.INP ? metrics.INP.value : 0, entry.duration)); });
      }).observe({ type: 'event', buffered: true, durationThreshold: 40 });
    } catch (e) {}
    try {
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) { if (entry.name === 'first-contentful-paint') set('FCP', entry.startTime); });
      }).observe({ type: 'paint', buffered: true });
    } catch (e) {}
    var nav = performance.getEntriesByType('navigation')[0];
    if (nav) set('TTFB', nav.responseStart);
    var sent = false;
    var send = function () {
      if (sent) return;
      sent = true;
      var list = Object.keys(metrics).map(function (k) { return metrics[k]; });
      if (!list.length) return;
      var payload = JSON.stringify({ page_path: window.location.pathname, device: window.innerWidth < 768 ? 'mobile' : 'desktop',
        connection: (navigator.connection && navigator.connection.effectiveType) || '', metrics: list });
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/api/vitals.php', new Blob([payload], { type: 'application/json' }));
      } else {
        fetch('/api/vitals.php', { method: 'POST', credentials: 'same-origin', keepalive: true,
          headers: { 'Content-Type': 'application/json' }, body: payload }).catch(function () {});
      }
    };
    window.addEventListener('pagehide', send, { once: true });
    doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'hidden') send(); });
  }

  /* ------------------------------------------------------- newsletter prompt */
  function initNewsletterPrompt() {
    var path = (location.pathname || '/').replace(/\/+$/, '') || '/';
    var preview = qs('newsletter_preview') === '1';
    var taskPage = /^\/send-(mzigo|errand)(\/|$)/.test(path);
    if (!preview && /^\/(admin|settings|orders|claims|billing|profile|freelancer|customer|scan|login|signup|forgot-password|reset-password|verify-email|partner-poster|refer-card|track-mzigo)(\/|$)/.test(path)) return;
    api('newsletter.php?action=config').then(function (res) {
      var cfg = (res && res.config) || {};
      if ((!cfg.enabled && !preview) || !cfg.channels || !cfg.channels.length) return;
      if (APP.user && !cfg.show_authenticated && !preview) return;
      var pages = cfg.pages || ['/'];
      var pageOk = pages.some(function (p) {
        if (p === '*') return true;
        if (p.slice(-1) === '*') return path.indexOf(p.slice(0, -1)) === 0;
        return path === p || (path === '' && p === '/');
      });
      if (!pageOk && !preview) return;

      var lsGet = function (key) { try { return localStorage.getItem(key) || ''; } catch (e) { return ''; } };
      var lsSet = function (key, value) { try { localStorage.setItem(key, String(value)); } catch (e) {} };
      var sessionGet = function (key) { try { return sessionStorage.getItem(key) || ''; } catch (e) { return ''; } };
      var sessionSet = function (key, value) { try { sessionStorage.setItem(key, String(value)); } catch (e) {} };
      var now = function () { return Date.now(); };
      var dayMs = 86400000;
      if (!preview && lsGet('mz_nl_subscribed') === '1') return;
      if (!preview) {
        var last = Number(lsGet('mz_nl_last') || 0);
        var dismissed = Number(lsGet('mz_nl_dismissed') || 0);
        if (sessionGet('mz_nl_shown') === '1') return;
        if (last && now() - last < Number(cfg.frequency_days || 7) * dayMs) return;
        if (dismissed && now() - dismissed < Number(cfg.dismiss_days || 60) * dayMs) return;
        var bucket = Number(lsGet('mz_nl_bucket') || 0);
        if (!bucket) { bucket = Math.floor(Math.random() * 100) + 1; lsSet('mz_nl_bucket', bucket); }
        if (bucket > Number(cfg.pilot_percent || 10)) return;
      }

      var pageviews = Number(sessionGet('mz_nl_views') || 0) + 1;
      sessionSet('mz_nl_views', pageviews);
      var shown = false;
      var start = now();
      var lastActivity = now();
      var lastTick = now();
      var activeMs = 0;
      var maxScroll = 0;
      var hasTask = false;
      var channelLabels = {};
      (cfg.channels || []).forEach(function (c) { channelLabels[c.code] = c.label; });

      function markActivity() {
        lastActivity = now();
      }
      function taskStarted() {
        var roots = document.querySelectorAll('[data-mz-send-wizard], [data-mz-errand-wizard]');
        for (var i = 0; i < roots.length; i++) {
          var steps = roots[i].querySelectorAll('[data-step]');
          for (var j = 0; j < steps.length; j++) {
            var step = Number(steps[j].getAttribute('data-step') || 0);
            if (step > 1 && !steps[j].hidden) return true;
          }
        }
        return false;
      }
      function editingTask() {
        var active = document.activeElement;
        return !!(active && active.closest && active.closest('[data-mz-send-wizard], [data-mz-errand-wizard]'));
      }
      function copyBlock(text) {
        return esc(String(text || '')).replace(/\n/g, '<br>');
      }
      function showPrompt(trigger) {
        if (shown) return;
        shown = true;
        sessionSet('mz_nl_shown', '1');
        lsSet('mz_nl_last', now());
        trackEvent('newsletter_prompt_impression', { label: trigger, meta: { trigger: trigger } });
        var channels = cfg.channels || [];
        var emailChannel = null;
        var otherChannel = null;
        channels.forEach(function (c) {
          if (c.code === 'email') emailChannel = c;
          else if (!otherChannel) otherChannel = c;
        });
        var channel = emailChannel ? 'email' : (channels[0] ? channels[0].code : 'email');
        var leadLabel = cfg.lead_magnet_url ? (cfg.lead_magnet_label || cfg.cta) : cfg.cta;
        var consentText = cfg.consent_label || 'I agree to receive the Mzigo.online newsletter and product alerts.';
        var body = '<div data-nl-fields>' +
          (cfg.artwork_url ? '<img src="' + esc(cfg.artwork_url) + '" alt="Mzigo.online updates" style="display:block;width:100%;max-height:180px;object-fit:cover;border-radius:12px;margin:0 0 14px;">' : '') +
          '<p class="mz-hint" style="margin:0 0 12px;">' + copyBlock(cfg.body) + '</p>' +
          '<label class="mz-field"><span class="mz-label" data-nl-channel-label>Email</span><input class="mz-input" name="nl_contact" type="email" autocomplete="email" placeholder="you@example.com"></label>' +
          (emailChannel && otherChannel ? '<button type="button" class="mz-link" data-nl-switch-channel style="font-size:12px;margin-top:-4px;">Prefer ' + esc(otherChannel.label) + ' instead?</button>' : '') +
          '<p class="mz-hint" style="margin:12px 0 0;font-size:11px;line-height:1.5;">By clicking &quot;' + esc(leadLabel) + '&quot;, you confirm: ' + esc(consentText) + ' <a class="mz-link" href="/privacy#marketing" target="_blank" rel="noopener">Privacy Policy</a>.</p>' +
          '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>' +
          '</div>';
        var dialog = openDialog({
          title: cfg.title,
          sub: '',
          body: body,
          submitLabel: leadLabel,
          cancelLabel: cfg.dismiss,
          hideClose: true,
          onDismiss: function () {
            lsSet('mz_nl_dismissed', now());
            trackEvent('newsletter_prompt_dismiss', { label: trigger, meta: { trigger: trigger } });
          },
          onSubmit: function (form) {
            var contact = (form.querySelector('[name="nl_contact"]').value || '').trim();
            if (channel === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact)) {
              throw new Error('Enter a valid email address.');
            }
            if (channel !== 'email' && !contact) {
              throw new Error('Enter a valid phone number.');
            }
            return api('newsletter.php', {
              method: 'POST',
              body: {
                action: 'subscribe',
                channel: channel,
                email: channel === 'email' ? contact : '',
                phone: channel === 'email' ? '' : contact,
                interests: ['newsletter', 'alerts'],
                consent: 1,
                website: (form.querySelector('[name="website"]').value || ''),
                trigger_source: trigger,
                page_path: location.pathname,
                source: cfg.lead_magnet_url ? 'landing-lead-magnet' : 'landing-prompt'
              }
            }).then(function (res) {
              lsSet('mz_nl_last', now());
              trackEvent('newsletter_prompt_submit', { label: trigger, meta: { trigger: trigger, channel: channel, lead_magnet: !!cfg.lead_magnet_url } });
              toast(res.message || 'Check your messages to confirm your subscription.');
            });
          }
        });
        var contactInput = dialog.overlay.querySelector('[name="nl_contact"]');
        var labelEl = dialog.overlay.querySelector('[data-nl-channel-label]');
        var switchBtn = dialog.overlay.querySelector('[data-nl-switch-channel]');
        function syncChannel() {
          if (!contactInput || !labelEl) return;
          var isEmail = channel === 'email';
          labelEl.textContent = isEmail ? 'Email' : 'Phone';
          contactInput.type = isEmail ? 'email' : 'tel';
          contactInput.autocomplete = isEmail ? 'email' : 'tel';
          contactInput.placeholder = isEmail ? 'you@example.com' : '0712 345 678';
          if (switchBtn) {
            switchBtn.textContent = isEmail
              ? 'Prefer ' + (otherChannel ? otherChannel.label : 'SMS') + ' instead?'
              : 'Prefer email instead?';
          }
        }
        if (switchBtn && emailChannel && otherChannel) {
          switchBtn.addEventListener('click', function () {
            channel = channel === 'email' ? otherChannel.code : 'email';
            syncChannel();
            contactInput.focus();
          });
        }
        syncChannel();
        return;
      }

      if (preview) {
        setTimeout(function () { showPrompt('preview'); }, 600);
        return;
      }

      ['scroll', 'pointerdown', 'keydown', 'touchstart'].forEach(function (evt) {
        doc.addEventListener(evt, function () {
          markActivity();
          if (taskStarted()) hasTask = true;
        }, { passive: true });
      });
      window.addEventListener('scroll', function () {
        var top = window.scrollY || document.documentElement.scrollTop || 0;
        var height = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
        maxScroll = Math.max(maxScroll, Math.min(100, (top / height) * 100));
      }, { passive: true });
      doc.addEventListener('mouseleave', function (ev) {
        if (ev.clientY <= 0 && !ev.relatedTarget && !shown) {
          if (cfg.triggers.exit) showPrompt('exit');
        }
      });
      var timer = setInterval(function () {
        if (shown) { clearInterval(timer); return; }
        if (document.visibilityState !== 'visible') { lastTick = now(); return; }
        activeMs += now() - lastTick;
        lastTick = now();
        var idleMs = now() - lastActivity;
        var dwellMs = now() - start;
        if (taskPage && !hasTask && !taskStarted()) return;
        if (cfg.triggers.abandoned && taskPage && (hasTask || taskStarted()) && idleMs >= Number(cfg.abandoned_seconds || 30) * 1000) {
          showPrompt('abandoned');
        } else if (!taskPage && cfg.triggers.idle && dwellMs >= Number(cfg.min_dwell_seconds || 15) * 1000 && idleMs >= Number(cfg.idle_seconds || 20) * 1000) {
          showPrompt('idle');
        } else if (!taskPage && cfg.triggers.active && activeMs >= Number(cfg.active_seconds || 45) * 1000 &&
            (maxScroll >= Number(cfg.scroll_pct || 60) || pageviews >= Number(cfg.active_pageviews || 3))) {
          showPrompt('active');
        }
      }, 1000);
    }).catch(function () { /* prompt is optional and never blocks the page */ });
  }

  function init() {
    hydrate().then(function () {
      initHeaderSearch();
      initAuthUI();
      initAuth();
      doc.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-resend-verification]');
        if (!btn) return;
        btn.disabled = true;
        api('email-verification.php', { method: 'POST', body: { action: 'resend' } })
          .then(function (res) {
            var out = btn.parentElement.querySelector('[data-verification-result]');
            if (out) out.innerHTML = res.demo_link
              ? 'Verification email sent. Demo link: <a class="mz-link" href="' + esc(res.demo_link) + '">open link</a>'
              : 'Verification email sent. Check your inbox.';
            toast('Verification email sent.');
            btn.disabled = false;
          }).catch(function (err) { toast(err.message, 'error'); btn.disabled = false; });
      });
      initNoopLinks();
      ensurePricingLink();
      initTrackForms();
      initAnalytics();
      initWebVitals();
      initNewsletterPrompt();
      loadCms().then(function (res) { if (res) applyBranding(res.branding); });
      initFaq();
      var page = doc.body.getAttribute('data-page') || '';
      if (page === 'home') initHome();
      if (page === 'partners') initPartners();
      if (page === 'track') initTrack();
      if (page === 'profile') initProfile();
      if (page === 'send') initSend();
      if (page === 'errand') initErrand();
      if (page === 'login') initLoginPage();
      if (page === 'forgot-password') initForgotPasswordPage();
      if (page === 'reset-password') initResetPasswordPage();
      if (page === 'verify-email') initVerifyEmailPage();
      if (page === 'signup') initSignupPage();
      if (page === 'pricing') initPricingPage();
      if (page === 'billing') initBillingPage();
      if (page === 'settings') { initSettingsPage(); setTimeout(addSettingsAnchors, 800); }
      if (page === 'admin') initAdminPage();
      if (page === 'freelancer') initFreelancerPage();
      if (page === 'customer') initCustomerPage();
      if (page === 'orders') initOrdersPage();
      if (page === 'claims') initClaimsPage();
      if (page === 'contact') initContactPage();
      if (page === 'refer') initReferPage();
      if (page === 'rate-runner') initRateRunnerPage();
      if (page === 'partner-poster') initPartnerPosterPage();
      if (page === 'refer-card') initReferCardPage();
    });
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
})();
