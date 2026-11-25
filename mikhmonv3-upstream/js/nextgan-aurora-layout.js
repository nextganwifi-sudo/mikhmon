(function () {
  'use strict';

  function isGlassOs() {
    return document.body && document.body.getAttribute('data-ngw-ui') === 'aurora';
  }

  function isMobileViewport() {
    return window.innerWidth <= 980;
  }

  function cleanLegacyInlineLayout() {
    var sidenav = document.getElementById('sidenav');
    var main = document.getElementById('main');
    var openNav = document.getElementById('openNav');
    var closeNav = document.getElementById('closeNav');

    if (sidenav) {
      sidenav.style.removeProperty('width');
      sidenav.style.removeProperty('display');
      var items = sidenav.querySelectorAll(':scope > a.menu, :scope > .dropdown-btn');
      for (var i = 0; i < items.length; i++) {
        items[i].style.removeProperty('display');
      }
    }
    if (main) {
      main.style.removeProperty('margin-left');
    }
    if (openNav) {
      openNav.style.removeProperty('display');
    }
    if (closeNav) {
      closeNav.style.removeProperty('display');
    }
  }

  function neutralizeLegacyResizeHandler() {
    if (!document.body) return;
    document.body.onresize = function () {
      cleanLegacyInlineLayout();
      if (!isMobileViewport()) {
        document.body.classList.remove('ngw-gl-drawer-open');
      }
    };
    window.addEventListener('resize', function () {
      cleanLegacyInlineLayout();
      if (!isMobileViewport()) {
        document.body.classList.remove('ngw-gl-drawer-open');
      }
    });
  }

  function ensureBackdrop() {
    var existing = document.querySelector('.ngw-gl-drawer-backdrop');
    var sidenav = document.getElementById('sidenav');
    if (existing) {
      if (sidenav && sidenav.parentNode && existing.parentNode !== sidenav.parentNode) {
        sidenav.parentNode.insertBefore(existing, sidenav);
      }
      return existing;
    }
    var bd = document.createElement('div');
    bd.className = 'ngw-gl-drawer-backdrop';
    bd.setAttribute('aria-hidden', 'true');
    bd.addEventListener('click', function () {
      document.body.classList.remove('ngw-gl-drawer-open');
    });
    if (sidenav && sidenav.parentNode) {
      sidenav.parentNode.insertBefore(bd, sidenav);
    } else {
      document.body.appendChild(bd);
    }
    return bd;
  }

  function ensureMobileDrawerHeader() {
    var sidenav = document.getElementById('sidenav');
    if (!sidenav || sidenav.querySelector('.ngw-gl-drawer-head')) return;
    var isBn = window.NGW_UI_CONFIG && window.NGW_UI_CONFIG.lang === 'bn';
    var head = document.createElement('div');
    head.className = 'ngw-gl-drawer-head';
    head.innerHTML =
      '<span class="ngw-gl-drawer-title"><i class="fa fa-bars" aria-hidden="true"></i><span>' +
        (isBn ? 'নেভিগেশন মেনু' : 'Navigation Menu') +
      '</span></span>' +
      '<button type="button" class="ngw-gl-drawer-close" aria-label="Close menu">&times;</button>';
    var closeBtn = head.querySelector('.ngw-gl-drawer-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        document.body.classList.remove('ngw-gl-drawer-open');
      });
    }
    sidenav.insertBefore(head, sidenav.firstChild);
  }

  function bindNavToggle() {
    ensureBackdrop();
    ensureMobileDrawerHeader();

    document.addEventListener('click', function (ev) {
      if (!isGlassOs()) return;
      var btn = ev.target && ev.target.closest ? ev.target.closest('#openNav, #closeNav') : null;
      if (!btn) return;

      ev.preventDefault();
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === 'function') {
        ev.stopImmediatePropagation();
      }

      cleanLegacyInlineLayout();
      if (isMobileViewport()) {
        document.body.classList.toggle('ngw-gl-drawer-open');
      } else {
        document.body.classList.toggle('ngw-gl-rail-collapsed');
      }
    }, true);

    // Close mobile drawer when navigating via a real link inside #sidenav
    document.addEventListener('click', function (ev) {
      if (!isGlassOs() || !isMobileViewport()) return;
      var link = ev.target && ev.target.closest ? ev.target.closest('#sidenav a[href]') : null;
      if (!link) return;
      var href = (link.getAttribute('href') || '').trim();
      if (href && href !== '#' && href.indexOf('javascript:') !== 0) {
        document.body.classList.remove('ngw-gl-drawer-open');
      }
    });
  }

  function collectSidenavCommands() {
    var sidenav = document.getElementById('sidenav');
    if (!sidenav) return [];
    var anchors = sidenav.querySelectorAll('a[href]');
    var seen = {};
    var items = [];
    for (var i = 0; i < anchors.length; i++) {
      var a = anchors[i];
      var href = (a.getAttribute('href') || '').trim();
      if (!href || href === '#' || href.indexOf('javascript:') === 0) continue;
      var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
      if (!label) continue;
      var key = href + '|' + label;
      if (seen[key]) continue;
      seen[key] = true;
      var iconEl = a.querySelector('i');
      var iconCls = iconEl ? iconEl.className : 'fa fa-arrow-right';
      items.push({
        label: label,
        href: href,
        icon: iconCls,
        isActive: a.classList.contains('active')
      });
    }
    return items;
  }

  function initGlassCommandPalette() {
    var navbarLeft = document.querySelector('#navbar .navbar-left');
    var sidenav = document.getElementById('sidenav');
    if (!navbarLeft || !sidenav) return;
    if (document.getElementById('ngwGlCmdTrigger')) return;

    var isBn = window.NGW_UI_CONFIG && window.NGW_UI_CONFIG.lang === 'bn';

    // Inject Glass Spotlight trigger button into top island
    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.id = 'ngwGlCmdTrigger';
    trigger.className = 'ngw-gl-cmd-trigger ngw-gl-raw';
    trigger.title = isBn ? 'কুইক মেনু সার্চ (Ctrl+K)' : 'Quick Command Launcher (Ctrl+K)';
    trigger.innerHTML =
      '<i class="fa fa-search" aria-hidden="true"></i>' +
      '<span class="ngw-gl-cmd-label">' + (isBn ? 'কুইক মেনু...' : 'Quick Jump...') + '</span>' +
      '<span class="ngw-gl-cmd-kbd">Ctrl K</span>';

    var cpage = document.getElementById('cpage');
    if (cpage && cpage.parentNode === navbarLeft) {
      navbarLeft.insertBefore(trigger, cpage.nextSibling);
    } else {
      navbarLeft.appendChild(trigger);
    }

    // Create Glass Spotlight Modal
    var backdrop = document.createElement('div');
    backdrop.id = 'ngwGlCmdBackdrop';
    backdrop.className = 'ngw-gl-cmd-backdrop';
    backdrop.innerHTML =
      '<div class="ngw-gl-cmd-dialog" role="dialog" aria-modal="true" aria-label="Quick Launcher">' +
        '<div class="ngw-gl-cmd-head">' +
          '<i class="fa fa-search" aria-hidden="true"></i>' +
          '<input type="text" id="ngwGlCmdInput" class="ngw-gl-cmd-input" autocomplete="off" placeholder="' +
            (isBn ? 'যেকোনো মেনু বা অপশন খুঁজুন...' : 'Jump to any menu or system page...') + '">' +
          '<button type="button" id="ngwGlCmdClose" class="btn" style="padding:4px 9px;font-size:12px;">ESC</button>' +
        '</div>' +
        '<div id="ngwGlCmdList" class="ngw-gl-cmd-list"></div>' +
      '</div>';
    document.body.appendChild(backdrop);

    var input = document.getElementById('ngwGlCmdInput');
    var listEl = document.getElementById('ngwGlCmdList');
    var closeBtn = document.getElementById('ngwGlCmdClose');
    var allCommands = [];

    function renderCommands(query) {
      if (!listEl) return;
      var q = (query || '').trim().toLowerCase();
      listEl.innerHTML = '';
      var shown = 0;
      for (var i = 0; i < allCommands.length; i++) {
        var cmd = allCommands[i];
        if (q !== '' && cmd.label.toLowerCase().indexOf(q) === -1) continue;
        var a = document.createElement('a');
        a.href = cmd.href;
        a.className = 'ngw-gl-cmd-item' + (cmd.isActive ? ' is-focused' : '');
        a.innerHTML =
          '<span class="ngw-gl-cmd-item-left">' +
            '<i class="' + cmd.icon.replace(/"/g, '') + '" aria-hidden="true"></i>' +
            '<span></span>' +
          '</span>' +
          '<i class="fa fa-angle-right" style="opacity:0.55;" aria-hidden="true"></i>';
        a.querySelector('.ngw-gl-cmd-item-left span').textContent = cmd.label;
        listEl.appendChild(a);
        shown++;
      }
      if (shown === 0) {
        var empty = document.createElement('div');
        empty.style.cssText = 'padding:22px 12px;text-align:center;opacity:0.7;font-size:13px;';
        empty.textContent = isBn ? 'কোনো মেনু অপশন পাওয়া যায়নি।' : 'No matching menu options found.';
        listEl.appendChild(empty);
      }
    }

    function openPalette() {
      allCommands = collectSidenavCommands();
      if (input) input.value = '';
      renderCommands('');
      backdrop.classList.add('is-open');
      if (input) {
        setTimeout(function () { input.focus(); }, 30);
      }
    }

    function closePalette() {
      backdrop.classList.remove('is-open');
    }

    trigger.addEventListener('click', openPalette);
    if (closeBtn) closeBtn.addEventListener('click', closePalette);
    backdrop.addEventListener('click', function (ev) {
      if (ev.target === backdrop) closePalette();
    });
    if (input) {
      input.addEventListener('input', function () {
        renderCommands(input.value);
      });
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          var firstLink = listEl ? listEl.querySelector('a.ngw-gl-cmd-item') : null;
          if (firstLink) {
            window.location.href = firstLink.href;
          }
        }
      });
    }

    document.addEventListener('keydown', function (ev) {
      if (!isGlassOs()) return;
      if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K')) {
        ev.preventDefault();
        if (backdrop.classList.contains('is-open')) {
          closePalette();
        } else {
          openPalette();
        }
      } else if (ev.key === 'Escape') {
        if (backdrop.classList.contains('is-open')) {
          closePalette();
        } else if (document.body.classList.contains('ngw-gl-drawer-open')) {
          document.body.classList.remove('ngw-gl-drawer-open');
        }
      }
    });
  }

  function boot() {
    if (!isGlassOs()) return;
    cleanLegacyInlineLayout();
    neutralizeLegacyResizeHandler();
    bindNavToggle();
    initGlassCommandPalette();

    // Re-clean after delayed legacy scripts finish binding
    setTimeout(cleanLegacyInlineLayout, 80);
    setTimeout(cleanLegacyInlineLayout, 350);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
