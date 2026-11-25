(function () {
  'use strict';

  var STORAGE_COUNT = 'ngw_pwa_login_count';
  var STORAGE_INSTALLED = 'ngw_pwa_installed';
  var STORAGE_DISMISSED = 'ngw_pwa_dismissed_forever';
  var STORAGE_LANG = 'ngw_pwa_lang';
  var SESSION_POPUP_SHOWN = 'ngw_pwa_popup_shown_session';

  var deferredPrompt = null;
  var handshakeCounted = false;
  var prefetchedUrls = {};

  function safeStorageGet(store, key) {
    try {
      return store ? store.getItem(key) : null;
    } catch (e) {
      return null;
    }
  }

  function safeStorageSet(store, key, val) {
    try {
      if (store) store.setItem(key, val);
    } catch (e) {}
  }

  function getLang() {
    if (window.NGW_UI_CONFIG && window.NGW_UI_CONFIG.lang === 'bn') {
      return 'bn';
    }
    var bodyLang = document.body && document.body.getAttribute('data-ngw-lang');
    if (bodyLang === 'bn') return 'bn';
    if (bodyLang === 'en') return 'en';
    var saved = safeStorageGet(window.localStorage, STORAGE_LANG);
    return saved === 'bn' ? 'bn' : 'en';
  }

  function syncStoredLang() {
    safeStorageSet(window.localStorage, STORAGE_LANG, getLang());
  }

  function isStandaloneMode() {
    try {
      if (window.navigator && window.navigator.standalone === true) return true;
      if (window.matchMedia) {
        if (window.matchMedia('(display-mode: standalone)').matches) return true;
        if (window.matchMedia('(display-mode: window-controls-overlay)').matches) return true;
      }
      if (document.referrer && document.referrer.indexOf('android-app://') === 0) return true;
    } catch (e) {}
    return false;
  }

  function isLoginPage() {
    if (document.body && document.body.getAttribute('data-ngw-page') === 'login') return true;
    return !document.getElementById('sidenav') && !!document.querySelector('.login-box form');
  }

  function isIosSafari() {
    var ua = (navigator.userAgent || '').toLowerCase();
    var isIos = /iphone|ipad|ipod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    return isIos;
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    var register = function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
    };
    if (document.readyState === 'complete') {
      register();
    } else {
      window.addEventListener('load', register, { once: true });
    }
  }

  function injectStyles() {
    if (document.getElementById('ngw-pwa-styles')) return;
    var css = [
      /* Hide sidebar install button when inside installed standalone app */
      'body.ngw-pwa-standalone #sidenav.sidenav > a#ngw-pwa-sidebar-install,',
      'body.ngw-pwa-standalone a#ngw-pwa-sidebar-install,',
      'a#ngw-pwa-sidebar-install[hidden] { display: none !important; }',

      /* Classic & Modern subtle polish on sidebar install button (keeps theme colors clean) */
      'body[data-ngw-ui="aurora"] #sidenav.sidenav > a#ngw-pwa-sidebar-install > i:first-child { color: #38bdf8 !important; }',
      'body[data-ngw-ui="modern"] #sidenav a#ngw-pwa-sidebar-install i.fa { color: #0ea5e9; }',

      /* Login Offline Warning Banner */
      '.ngw-login-offline-alert {',
      '  display: none; align-items: flex-start; gap: 10px; padding: 10px 12px;',
      '  margin: 0 0 12px; border-radius: 10px; text-align: left;',
      '  background: rgba(245, 158, 11, 0.16); border: 1px solid rgba(251, 191, 36, 0.55);',
      '  color: #fef3c7; font-size: 13px; line-height: 1.45; font-weight: 600;',
      '}',
      '.ngw-login-offline-alert.is-visible { display: flex; }',
      '.ngw-login-offline-alert i { font-size: 16px; margin-top: 2px; color: #fbbf24; flex-shrink: 0; }',
      '.ngw-login-offline-alert.is-shake { animation: ngwPwaShake 0.36s ease; }',
      '@keyframes ngwPwaShake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-5px)} 75%{transform:translateX(5px)} }',

      /* Live Dashboard Connectivity Toast */
      '.ngw-net-banner {',
      '  position: fixed; left: 50%; bottom: 14px; transform: translate(-50%, 20px);',
      '  z-index: 99998; display: flex; align-items: center; gap: 10px;',
      '  max-width: min(92vw, 440px); padding: 10px 14px; border-radius: 12px;',
      '  background: rgba(15, 23, 42, 0.94); border: 1px solid rgba(251, 191, 36, 0.52);',
      '  color: #fef3c7; font-size: 13px; font-weight: 600; line-height: 1.35;',
      '  box-shadow: 0 14px 36px rgba(0, 0, 0, 0.45); backdrop-filter: blur(12px);',
      '  opacity: 0; pointer-events: none; transition: opacity 0.2s ease, transform 0.2s ease;',
      '}',
      '.ngw-net-banner.is-online { border-color: rgba(52, 211, 153, 0.55); color: #d1fae5; }',
      '.ngw-net-banner.is-visible { opacity: 1; transform: translate(-50%, 0); pointer-events: auto; }',
      '.ngw-net-banner-retry {',
      '  border: none; background: rgba(251, 191, 36, 0.2); color: #fde68a;',
      '  font-size: 12px; font-weight: 700; padding: 5px 10px; border-radius: 8px; cursor: pointer;',
      '  white-space: nowrap;',
      '}',

      /* Post-Login PWA Install Modal */
      '.ngw-pwa-modal-backdrop {',
      '  position: fixed; inset: 0; z-index: 99999;',
      '  display: flex; align-items: center; justify-content: center; padding: 16px;',
      '  background: rgba(2, 8, 20, 0.64); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);',
      '  opacity: 0; pointer-events: none; transition: opacity 0.22s ease;',
      '}',
      '.ngw-pwa-modal-backdrop.is-open { opacity: 1; pointer-events: auto; }',
      '.ngw-pwa-modal {',
      '  width: 100%; max-width: 410px; border-radius: 18px; padding: 20px;',
      '  background: #0f172a; color: #f8fafc;',
      '  border: 1px solid rgba(56, 189, 248, 0.34);',
      '  box-shadow: 0 26px 64px rgba(0, 0, 0, 0.56), inset 0 1px 0 rgba(255, 255, 255, 0.12);',
      '  transform: translateY(10px) scale(0.98); transition: transform 0.22s ease;',
      '  font-family: inherit;',
      '}',
      '.ngw-pwa-modal-backdrop.is-open .ngw-pwa-modal { transform: translateY(0) scale(1); }',
      'body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-pwa-modal { background: #141210; border: 2px solid #f59e0b; border-radius: 14px; }',
      'body[data-ngw-ui="aurora"] .ngw-pwa-modal { background: rgba(8, 24, 46, 0.90); border: 1px solid rgba(125, 211, 252, 0.42); backdrop-filter: blur(22px); }',
      '.ngw-pwa-modal-head { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }',
      '.ngw-pwa-modal-icon { width: 52px; height: 52px; border-radius: 13px; flex-shrink: 0; background: #ffffff; object-fit: contain; padding: 3px; border: 1px solid rgba(56, 189, 248, 0.35); box-shadow: 0 8px 20px rgba(2, 132, 199, 0.28); }',
      '.ngw-pwa-modal-title { margin: 0 0 3px; font-size: 17px; font-weight: 800; color: #ffffff; line-height: 1.25; }',
      '.ngw-pwa-modal-sub { margin: 0; font-size: 12.5px; color: #94a3b8; font-weight: 600; }',
      '.ngw-pwa-modal-desc { margin: 0 0 14px; font-size: 13.5px; line-height: 1.5; color: #cbd5e1; }',
      '.ngw-pwa-guide-box { display: none; margin: 0 0 14px; padding: 11px 13px; border-radius: 12px; background: rgba(14, 165, 233, 0.12); border: 1px solid rgba(56, 189, 248, 0.3); font-size: 13px; line-height: 1.55; color: #e0f2fe; }',
      '.ngw-pwa-guide-box.is-visible { display: block; }',
      '.ngw-pwa-guide-box ol { margin: 6px 0 0 18px; padding: 0; }',
      '.ngw-pwa-guide-box li { margin-bottom: 4px; }',
      '.ngw-pwa-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }',
      '#ngw-pwa-install-modal .ngw-pwa-btn-install { flex: 1 1 150px !important; min-height: 44px !important; border: none !important; border-radius: 11px !important; padding: 0 18px !important; background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%) !important; color: #ffffff !important; font-size: 14px !important; font-weight: 700 !important; cursor: pointer !important; box-shadow: 0 8px 22px rgba(6, 182, 212, 0.35) !important; text-shadow: none !important; }',
      '#ngw-pwa-install-modal .ngw-pwa-btn-install:hover { filter: brightness(1.08); }',
      '#ngw-pwa-install-modal .ngw-pwa-btn-later { flex: 0 1 auto !important; min-height: 44px !important; border: 1px solid rgba(148, 163, 184, 0.32) !important; border-radius: 11px !important; padding: 0 16px !important; background: rgba(148, 163, 184, 0.14) !important; color: #e2e8f0 !important; font-size: 13.5px !important; font-weight: 600 !important; cursor: pointer !important; box-shadow: none !important; }',
      '#ngw-pwa-install-modal .ngw-pwa-btn-later:hover { background: rgba(148, 163, 184, 0.22) !important; }',
      '#ngw-pwa-install-modal .ngw-pwa-btn-never { display: block !important; width: 100% !important; margin-top: 10px !important; border: none !important; background: transparent !important; color: #94a3b8 !important; font-size: 12.5px !important; font-weight: 600 !important; cursor: pointer !important; padding: 6px !important; text-align: center !important; box-shadow: none !important; }',
      '#ngw-pwa-install-modal .ngw-pwa-btn-never:hover { color: #e2e8f0 !important; text-decoration: underline !important; }',
      '@media (max-width: 600px) { .ngw-pwa-modal-backdrop { align-items: flex-end; padding: 12px; } .ngw-pwa-modal { border-radius: 18px; } }'
    ].join('\n');

    var style = document.createElement('style');
    style.id = 'ngw-pwa-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function getCopy(lang) {
    if (lang === 'bn') {
      return {
        offlineLogin: 'ইন্টারনেট সংযোগ নেই! মোবাইল ডেটা বা ওয়াই-ফাই চালু করে লগইন চেষ্টা করুন।',
        offlineToast: 'ইন্টারনেট সংযোগ বিচ্ছিন্ন রয়েছে — নেটওয়ার্ক চেক করুন।',
        onlineToast: 'পুনরায় ইন্টারনেট সংযোগ যুক্ত হয়েছে।',
        retry: 'রিলোড',
        installTitle: 'NextGan WiFi অ্যাপ ইনস্টল করুন',
        installSub: 'Android, iOS ও Desktop App',
        installDesc: 'হোম-স্ক্রিন থেকে এক ট্যাপেই দ্রুত ওপেন হবে, দুর্বল নেটওয়ার্কেও দ্রুত লোড হবে এবং ইন্টারনেট না থাকলে সাথে সাথে সতর্ক করবে।',
        installBtn: 'অ্যাপ ইনস্টল করুন',
        laterBtn: 'পরে করব',
        neverBtn: 'আর পপআপ দেখাবেন না',
        iosTitle: 'iPhone / iPad-এ ইনস্টল করার নিয়ম:',
        iosStep1: 'Safari ব্রাউজারের নিচে Share (শেয়ার) আইকনে ট্যাপ করুন।',
        iosStep2: '"Add to Home Screen" সিলেক্ট করে উপরে "Add" চাপুন।',
        browserTitle: 'ব্রাউজার থেকে ইনস্টল করার নিয়ম:',
        browserStep1: 'ব্রাউজারের উপরের মেনু (⋮ বা ⋯) অথবা অ্যাড্রেস বারে থাকা Install আইকনে ক্লিক করুন।',
        browserStep2: '"Install app" / "Add to Home Screen" সিলেক্ট করুন।',
        closeBtn: 'বন্ধ করুন'
      };
    }
    return {
      offlineLogin: 'No internet connection! Turn on Wi-Fi or mobile data before signing in.',
      offlineToast: 'You are offline — check Wi-Fi or mobile data.',
      onlineToast: 'Internet connection restored.',
      retry: 'Reload',
      installTitle: 'Install NextGan WiFi App',
      installSub: 'Android, iOS & Desktop App',
      installDesc: 'Launch in one tap from your home screen with faster static loading on high-latency networks and instant offline alerts.',
      installBtn: 'Install App',
      laterBtn: 'Later',
      neverBtn: "Don't ask again",
      iosTitle: 'Install on iPhone / iPad:',
      iosStep1: 'Tap the Share icon in the Safari toolbar.',
      iosStep2: 'Choose "Add to Home Screen" and tap "Add".',
      browserTitle: 'Install via Browser Menu:',
      browserStep1: 'Click the browser menu (⋮ or ⋯) or the Install icon in the address bar.',
      browserStep2: 'Select "Install app" or "Add to Home Screen".',
      closeBtn: 'Close'
    };
  }

  function initLoginOfflineGuard() {
    var form = document.querySelector('.login-box form');
    if (!form) return;

    var alertBox = document.getElementById('ngw-login-offline-alert');
    if (!alertBox) {
      alertBox = document.createElement('div');
      alertBox.id = 'ngw-login-offline-alert';
      alertBox.className = 'ngw-login-offline-alert';
      alertBox.setAttribute('role', 'alert');
      alertBox.innerHTML =
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;" aria-hidden="true">' +
          '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>' +
          '<line x1="12" y1="9" x2="12" y2="13"></line>' +
          '<line x1="12" y1="17" x2="12.01" y2="17"></line>' +
        '</svg><span></span>';
      form.parentNode.insertBefore(alertBox, form);
    }

    function updateLoginOnlineState() {
      var t = getCopy(getLang());
      var span = alertBox.querySelector('span');
      if (span) span.textContent = t.offlineLogin;
      var offline = navigator.onLine === false;
      alertBox.classList.toggle('is-visible', offline);
    }

    updateLoginOnlineState();
    window.addEventListener('offline', updateLoginOnlineState);
    window.addEventListener('online', updateLoginOnlineState);

    form.addEventListener('submit', function (ev) {
      if (navigator.onLine === false) {
        ev.preventDefault();
        updateLoginOnlineState();
        alertBox.classList.remove('is-shake');
        void alertBox.offsetWidth;
        alertBox.classList.add('is-shake');
      }
    });
  }

  function initConnectivityBanner() {
    if (document.getElementById('ngw-net-banner')) return;
    var banner = document.createElement('div');
    banner.id = 'ngw-net-banner';
    banner.className = 'ngw-net-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.innerHTML =
      '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;" aria-hidden="true">' +
        '<path d="M5 12.55a11 11 0 0 1 14.08 0"></path>' +
        '<path d="M1.42 9a16 16 0 0 1 21.16 0"></path>' +
        '<path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>' +
        '<circle cx="12" cy="20" r="1" fill="currentColor"></circle>' +
      '</svg>' +
      '<span class="ngw-net-banner-text"></span>' +
      '<button type="button" class="ngw-net-banner-retry"></button>';
    document.body.appendChild(banner);

    var textEl = banner.querySelector('.ngw-net-banner-text');
    var retryBtn = banner.querySelector('.ngw-net-banner-retry');
    var onlineHideTimer = null;

    retryBtn.addEventListener('click', function () {
      window.location.reload();
    });

    function showOffline() {
      if (onlineHideTimer) clearTimeout(onlineHideTimer);
      var t = getCopy(getLang());
      textEl.textContent = t.offlineToast;
      retryBtn.textContent = t.retry;
      retryBtn.style.display = 'inline-block';
      banner.classList.remove('is-online');
      banner.classList.add('is-visible');
    }

    function showBackOnline() {
      var t = getCopy(getLang());
      textEl.textContent = t.onlineToast;
      retryBtn.style.display = 'none';
      banner.classList.add('is-online');
      banner.classList.add('is-visible');
      if (onlineHideTimer) clearTimeout(onlineHideTimer);
      onlineHideTimer = setTimeout(function () {
        banner.classList.remove('is-visible');
      }, 2800);
    }

    if (navigator.onLine === false) {
      showOffline();
    }
    window.addEventListener('offline', showOffline);
    window.addEventListener('online', function () {
      if (banner.classList.contains('is-visible')) {
        showBackOnline();
      }
    });
  }

  function createOrOpenInstallModal(fromSidebar) {
    if (fromSidebar !== true) {
      // Automatic PWA install popups are permanently disabled per user request.
      return;
    }
    var lang = getLang();
    var t = getCopy(lang);
    var backdrop = document.getElementById('ngw-pwa-install-modal');

    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'ngw-pwa-install-modal';
      backdrop.className = 'ngw-pwa-modal-backdrop';
      backdrop.setAttribute('role', 'dialog');
      backdrop.setAttribute('aria-modal', 'true');
      backdrop.setAttribute('aria-labelledby', 'ngw-pwa-modal-title');
      backdrop.innerHTML =
        '<div class="ngw-pwa-modal">' +
          '<div class="ngw-pwa-modal-head">' +
            '<img class="ngw-pwa-modal-icon" src="/img/pwa/pwa-192x192.png?v=20261125e" alt="NextGan WiFi App Icon">' +
            '<div>' +
              '<h3 id="ngw-pwa-modal-title" class="ngw-pwa-modal-title"></h3>' +
              '<p class="ngw-pwa-modal-sub"></p>' +
            '</div>' +
          '</div>' +
          '<p class="ngw-pwa-modal-desc"></p>' +
          '<div class="ngw-pwa-guide-box" id="ngw-pwa-guide-box"></div>' +
          '<div class="ngw-pwa-modal-actions">' +
            '<button type="button" class="ngw-pwa-btn-install" id="ngw-pwa-btn-install"></button>' +
            '<button type="button" class="ngw-pwa-btn-later" id="ngw-pwa-btn-later"></button>' +
          '</div>' +
          '<button type="button" class="ngw-pwa-btn-never" id="ngw-pwa-btn-never"></button>' +
        '</div>';
      document.body.appendChild(backdrop);

      backdrop.addEventListener('click', function (ev) {
        if (ev.target === backdrop) {
          closeInstallModal();
        }
      });

      document.getElementById('ngw-pwa-btn-later').addEventListener('click', closeInstallModal);
      document.getElementById('ngw-pwa-btn-never').addEventListener('click', function () {
        safeStorageSet(window.localStorage, STORAGE_DISMISSED, '1');
        closeInstallModal();
      });

      document.getElementById('ngw-pwa-btn-install').addEventListener('click', function () {
        if (deferredPrompt) {
          var promptEvt = deferredPrompt;
          deferredPrompt = null;
          promptEvt.prompt();
          if (promptEvt.userChoice && typeof promptEvt.userChoice.then === 'function') {
            promptEvt.userChoice.then(function (choice) {
              if (choice && choice.outcome === 'accepted') {
                markAppInstalled();
              }
              closeInstallModal();
            }).catch(function () {
              closeInstallModal();
            });
          } else {
            closeInstallModal();
          }
          return;
        }
        renderManualGuide(true);
      });
    }

    backdrop.querySelector('.ngw-pwa-modal-title').textContent = t.installTitle;
    backdrop.querySelector('.ngw-pwa-modal-sub').textContent = t.installSub;
    backdrop.querySelector('.ngw-pwa-modal-desc').textContent = t.installDesc;
    document.getElementById('ngw-pwa-btn-install').textContent = t.installBtn;
    document.getElementById('ngw-pwa-btn-later').textContent = fromSidebar ? t.closeBtn : t.laterBtn;
    var neverBtn = document.getElementById('ngw-pwa-btn-never');
    neverBtn.textContent = t.neverBtn;
    neverBtn.style.display = fromSidebar ? 'none' : 'block';

    renderManualGuide(!deferredPrompt && isIosSafari());

    function renderManualGuide(showNow) {
      var guide = document.getElementById('ngw-pwa-guide-box');
      if (!guide) return;
      if (!showNow) {
        guide.classList.remove('is-visible');
        guide.innerHTML = '';
        return;
      }
      var curT = getCopy(getLang());
      var ios = isIosSafari();
      var title = ios ? curT.iosTitle : curT.browserTitle;
      var s1 = ios ? curT.iosStep1 : curT.browserStep1;
      var s2 = ios ? curT.iosStep2 : curT.browserStep2;
      guide.innerHTML =
        '<strong>' + title + '</strong>' +
        '<ol><li>' + s1 + '</li><li>' + s2 + '</li></ol>';
      guide.classList.add('is-visible');
    }

    requestAnimationFrame(function () {
      backdrop.classList.add('is-open');
    });
  }

  function closeInstallModal() {
    var backdrop = document.getElementById('ngw-pwa-install-modal');
    if (backdrop) {
      backdrop.classList.remove('is-open');
    }
  }

  function markAppInstalled() {
    safeStorageSet(window.localStorage, STORAGE_INSTALLED, '1');
    document.body.classList.add('ngw-pwa-standalone');
    var btn = document.getElementById('ngw-pwa-sidebar-install');
    if (btn) {
      btn.setAttribute('hidden', 'hidden');
    }
    closeInstallModal();
  }

  function wireSidebarInstallButton() {
    var btn = document.getElementById('ngw-pwa-sidebar-install');
    if (!btn) return;
    if (isStandaloneMode()) {
      document.body.classList.add('ngw-pwa-standalone');
      btn.setAttribute('hidden', 'hidden');
      return;
    }
    btn.removeAttribute('hidden');
    if (btn.getAttribute('data-ngw-bound') === '1') return;
    btn.setAttribute('data-ngw-bound', '1');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (deferredPrompt) {
        var promptEvt = deferredPrompt;
        deferredPrompt = null;
        promptEvt.prompt();
        if (promptEvt.userChoice && typeof promptEvt.userChoice.then === 'function') {
          promptEvt.userChoice.then(function (choice) {
            if (choice && choice.outcome === 'accepted') {
              markAppInstalled();
            }
          }).catch(function () {});
        }
        return;
      }
      createOrOpenInstallModal(true);
    });
  }

  function trackLoginCountAndAutoPopup() {
    // Disabled automatic PWA popups per user request. Use manual sidebar Install App button.
    if (isStandaloneMode()) {
      markAppInstalled();
    }
    return;
  }

  function initSafeNavPrefetch() {
    var sidenav = document.getElementById('sidenav');
    if (!sidenav) return;
    var unsafePattern = /(logout|remove|delete|reboot|shutdown|reset|kill|drop|flush)/i;

    function maybePrefetch(target) {
      if (!target || !target.closest) return;
      var a = target.closest('#sidenav a[href]');
      if (!a) return;
      var href = (a.getAttribute('href') || '').trim();
      if (!href || href === '#' || href.indexOf('javascript:') === 0) return;
      if (unsafePattern.test(href)) return;
      if (prefetchedUrls[href]) return;
      prefetchedUrls[href] = true;
      var link = document.createElement('link');
      link.rel = 'prefetch';
      link.as = 'document';
      link.href = href;
      document.head.appendChild(link);
    }

    sidenav.addEventListener('mouseover', function (ev) {
      maybePrefetch(ev.target);
    }, { passive: true });
    sidenav.addEventListener('touchstart', function (ev) {
      maybePrefetch(ev.target);
    }, { passive: true });
  }

  // Always suppress browser mini-infobar and prevent automated install prompts
  window.addEventListener('beforeinstallprompt', function (ev) {
    ev.preventDefault();
    deferredPrompt = ev;
    return false;
  });

  window.addEventListener('appinstalled', function () {
    markAppInstalled();
  });

  function boot() {
    syncStoredLang();
    registerServiceWorker();
    injectStyles();

    // Remove any leftover or rogue install modal if present
    var rogue = document.getElementById('ngw-pwa-install-modal');
    if (rogue) {
      rogue.classList.remove('is-open');
      rogue.remove();
    }

    if (isLoginPage()) {
      // Strictly NO install button or auto-install popup on Login page
      initLoginOfflineGuard();
      return;
    }

    if (isStandaloneMode()) {
      document.body.classList.add('ngw-pwa-standalone');
    }

    initConnectivityBanner();
    wireSidebarInstallButton();
    initSafeNavPrefetch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
