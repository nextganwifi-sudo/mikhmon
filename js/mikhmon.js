function RequiredV() {
  var e = document.getElementById("expmode").value,
    t = document.getElementById("validity").style,
    n = document.getElementById("validi");
  if ("rem" === e || "remc" === e || "ntf" === e || "ntfc" === e) {
    t.display = "table-row";
    n.type = "text";
    if ("" === n.value) n.value = "";
    $("#validi").focus();
  } else {
    t.display = "none";
    n.type = "hidden";
  }
}

function defUserl() {
  var e = document.getElementById("user").value,
    t = document.getElementById("num").style,
    n = document.getElementById("lower").style,
    l = document.getElementById("upper").style,
    o = document.getElementById("upplow").style,
    i = document.getElementById("lower1").style,
    d = document.getElementById("upper1").style,
    a = document.getElementById("upplow1").style,
    c = document.getElementById("mix").style,
    m = document.getElementById("mix1").style,
    s = document.getElementById("mix2").style;
  if ("up" === e) {
    $("select[name=userl] option:first").html("4");
    $("select[name=char] option:first").html("Random abcd");
    n.display = "block";
    l.display = "block";
    o.display = "block";
    i.display = "none";
    d.display = "none";
    a.display = "none";
    t.display = "none";
    c.display = "block";
    m.display = "block";
    s.display = "block";
  } else if ("vc" === e) {
    $("select[name=userl] option:first").html("8");
    $("select[name=char] option:first").html("Random abcd2345");
    n.display = "none";
    l.display = "none";
    o.display = "none";
    i.display = "block";
    d.display = "block";
    a.display = "block";
    t.display = "block";
    c.display = "block";
    m.display = "block";
    s.display = "block";
  }
}

function loader() {
  var el = document.getElementById("loader");
  if (el) el.style = "display:inline;";
}

var _ngwNotifyTimer = null;
function notify(e) {
  var t = $("#notify");
  if (!t.length) return;
  if (_ngwNotifyTimer) {
    clearInterval(_ngwNotifyTimer);
    _ngwNotifyTimer = null;
  }
  t.find(".message").text(e);
  t.show();
  var n = t.find(".message").text(),
    l = 0;
  _ngwNotifyTimer = setInterval(function () {
    t.find(".message").append("●");
    if (4 == ++l) {
      t.find(".message").html(n);
      l = 0;
    }
  }, 500);
}

function printBT() {
  window.location = "my.bluetoothprint.scheme://";
}

var _ngwActiveConnectXhr = null;
var _ngwActiveConnectAttempt = 0;

function _ngwEscapeHtml(str) {
  return String(str == null ? "" : str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function _ngwCloseConnectHud(restoreSelect) {
  _ngwActiveConnectAttempt++;
  if (_ngwActiveConnectXhr && _ngwActiveConnectXhr.readyState !== 4) {
    try { _ngwActiveConnectXhr.abort(); } catch (err) {}
  }
  _ngwActiveConnectXhr = null;
  var hud = document.getElementById("ngw-connect-hud");
  if (hud) {
    hud.classList.remove("is-open");
    setTimeout(function () {
      if (hud && hud.parentNode) hud.parentNode.removeChild(hud);
    }, 160);
  }
  if (restoreSelect) {
    var sel = document.querySelector("select.connect");
    var opt = document.getElementById("MikhmonSession");
    if (sel && opt && opt.value) {
      sel.value = opt.value;
    }
  }
}

function connect(e) {
  var raw = String(e || "").trim();
  if (!raw) return;

  var parts = raw.split("&c=");
  var sessionName = parts[0];
  var cParam = parts.length > 1 ? parts[1] : "";

  var cfg = window.NGW_UI_CONFIG || {};
  var isBn = cfg.lang === "bn";
  var txt = {
    connecting: isBn ? "সংযুক্ত হচ্ছে..." : "Connecting...",
    opening: isBn ? "লগইন হচ্ছে..." : "Signing in...",
    connected: isBn ? "সংযুক্ত হয়েছে" : "Connected",
    failed: isBn ? "সংযোগ ব্যর্থ" : "Connection failed",
    retry: isBn ? "আবার চেষ্টা" : "Retry",
    settings: isBn ? "সেটিংস" : "Settings",
    close: isBn ? "বন্ধ" : "Close",
    connErrGeneric: isBn ? "রাউটারের সাথে সংযোগ স্থাপন করা যায়নি।" : "Could not connect to router."
  };

  _ngwActiveConnectAttempt++;
  var attemptId = _ngwActiveConnectAttempt;
  if (_ngwActiveConnectXhr && _ngwActiveConnectXhr.readyState !== 4) {
    try { _ngwActiveConnectXhr.abort(); } catch (err) {}
  }

  var existing = document.getElementById("ngw-connect-hud");
  if (existing && existing.parentNode) {
    existing.parentNode.removeChild(existing);
  }

  var hud = document.createElement("div");
  hud.id = "ngw-connect-hud";
  hud.className = "ngw-connect-hud-backdrop";
  hud.setAttribute("role", "dialog");
  hud.setAttribute("aria-modal", "true");
  hud.innerHTML =
    '<div class="ngw-connect-hud-card">' +
      '<div class="ngw-connect-hud-top">' +
        '<span class="ngw-connect-hud-icon is-pending" id="ngw-hud-main-icon"><i class="fa fa-circle-o-notch fa-spin"></i></span>' +
        '<div class="ngw-connect-hud-meta">' +
          '<div class="ngw-connect-hud-heading">' + _ngwEscapeHtml(sessionName) + '</div>' +
          '<div class="ngw-connect-hud-status">' +
            '<span id="ngw-hud-status-text">' + _ngwEscapeHtml(txt.connecting) + '</span>' +
            '<span class="ngw-connect-latency" id="ngw-hud-latency"></span>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="ngw-connect-hud-x" id="ngw-hud-close" title="' + _ngwEscapeHtml(txt.close) + '" aria-label="' + _ngwEscapeHtml(txt.close) + '">&times;</button>' +
      '</div>' +
      '<div class="ngw-connect-hud-progress"><div class="ngw-connect-hud-bar" id="ngw-hud-bar" style="width:32%"></div></div>' +
      '<div class="ngw-connect-hud-alert" id="ngw-hud-alert" style="display:none;"></div>' +
      '<div class="ngw-connect-hud-actions" id="ngw-hud-actions" style="display:none;"></div>' +
    '</div>';

  document.body.appendChild(hud);
  requestAnimationFrame(function () {
    hud.classList.add("is-open");
  });

  var closeBtn = document.getElementById("ngw-hud-close");
  if (closeBtn) {
    closeBtn.onclick = function () {
      _ngwCloseConnectHud(true);
    };
  }

  function showFailure(errMsg, showSettingsBtn) {
    if (attemptId !== _ngwActiveConnectAttempt) return;
    var bar = document.getElementById("ngw-hud-bar");
    var iconEl = document.getElementById("ngw-hud-main-icon");
    var statusText = document.getElementById("ngw-hud-status-text");
    var alertBox = document.getElementById("ngw-hud-alert");
    var actions = document.getElementById("ngw-hud-actions");

    if (bar) {
      bar.style.width = "100%";
      bar.classList.add("is-error");
    }
    if (iconEl) {
      iconEl.className = "ngw-connect-hud-icon is-error";
      iconEl.innerHTML = '<i class="fa fa-times"></i>';
    }
    if (statusText) {
      statusText.textContent = txt.failed;
    }
    if (alertBox) {
      alertBox.style.display = "block";
      alertBox.textContent = errMsg || txt.connErrGeneric;
    }
    if (actions) {
      var btns =
        '<button type="button" class="btn bg-primary ngw-hud-btn" id="ngw-hud-retry"><i class="fa fa-refresh"></i> ' + _ngwEscapeHtml(txt.retry) + '</button>';
      if (showSettingsBtn) {
        btns +=
          '<a class="btn bg-warning ngw-hud-btn" href="./admin.php?id=settings&session=' + encodeURIComponent(sessionName) + '"><i class="fa fa-gear"></i> ' + _ngwEscapeHtml(txt.settings) + '</a>';
      }
      btns +=
        '<button type="button" class="btn bg-grey ngw-hud-btn" id="ngw-hud-close-err">' + _ngwEscapeHtml(txt.close) + '</button>';
      actions.innerHTML = btns;
      actions.style.display = "flex";
      var retryEl = document.getElementById("ngw-hud-retry");
      if (retryEl) {
        retryEl.onclick = function () { connect(raw); };
      }
      var closeErrEl = document.getElementById("ngw-hud-close-err");
      if (closeErrEl) {
        closeErrEl.onclick = function () { _ngwCloseConnectHud(true); };
      }
    }
  }

  var pingUrl = "./status/connect_probe.php?step=ping&session=" + encodeURIComponent(sessionName) + (cParam ? "&c=" + encodeURIComponent(cParam) : "") + "&_=" + Date.now();
  _ngwActiveConnectXhr = $.ajax({
    url: pingUrl,
    dataType: "json",
    timeout: 5500,
    success: function (res) {
      if (attemptId !== _ngwActiveConnectAttempt) return;
      if (!res || !res.ok) {
        if (res && res.redirect && res.step === "session") {
          window.location.replace(res.redirect);
          return;
        }
        showFailure((res && res.error) ? res.error : txt.connErrGeneric, !!(res && res.can_edit_settings));
        return;
      }

      var latEl = document.getElementById("ngw-hud-latency");
      var bar = document.getElementById("ngw-hud-bar");
      var statusText = document.getElementById("ngw-hud-status-text");

      if (latEl && res.latency_ms) {
        latEl.textContent = " · " + res.latency_ms + "ms";
      }
      if (statusText) {
        statusText.textContent = txt.opening;
      }
      if (bar) {
        bar.style.width = "70%";
      }

      var authUrl = "./status/connect_probe.php?step=auth&session=" + encodeURIComponent(sessionName) + (cParam ? "&c=" + encodeURIComponent(cParam) : "") + "&_=" + Date.now();
      _ngwActiveConnectXhr = $.ajax({
        url: authUrl,
        dataType: "json",
        timeout: 11000,
        success: function (ares) {
          if (attemptId !== _ngwActiveConnectAttempt) return;
          if (!ares || !ares.ok) {
            if (ares && ares.redirect && ares.step === "session") {
              window.location.replace(ares.redirect);
              return;
            }
            showFailure((ares && ares.error) ? ares.error : txt.connErrGeneric, !!(ares && ares.can_edit_settings));
            return;
          }
          var iconEl = document.getElementById("ngw-hud-main-icon");
          if (iconEl) {
            iconEl.className = "ngw-connect-hud-icon is-ok";
            iconEl.innerHTML = '<i class="fa fa-check"></i>';
          }
          if (statusText) {
            statusText.textContent = txt.connected;
          }
          if (bar) {
            bar.style.width = "100%";
            bar.classList.add("is-ok");
          }
          setTimeout(function () {
            if (attemptId !== _ngwActiveConnectAttempt) return;
            window.location.href = ares.redirect || ("./?session=" + encodeURIComponent(sessionName));
          }, 140);
        },
        error: function (xhr, status) {
          if (status === "abort") return;
          showFailure(txt.connErrGeneric, !!(res && res.can_edit_settings));
        }
      });
    },
    error: function (xhr, status) {
      if (status === "abort") return;
      var msg = txt.connErrGeneric;
      try {
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
      } catch (ex) {}
      showFailure(msg, !!(cfg.canEditRouter));
    }
  });
}

function stheme(e) {
  $("#temp").load(e);
}
function dellSelected(e) {
  $("#temp").load(e);
}
function loadpage(e) {
  $("#temp").load(e);
}

function ngwSmartBack(fallbackUrl) {
  try {
    var ref = document.referrer || "";
    if (ref && window.history && window.history.length > 1) {
      var refUrl = new URL(ref, window.location.origin);
      if (
        refUrl.origin === window.location.origin &&
        refUrl.href !== window.location.href &&
        refUrl.search.indexOf("id=login") === -1 &&
        refUrl.search.indexOf("id=logout") === -1 &&
        refUrl.search.indexOf("id=connect") === -1
      ) {
        window.history.back();
        return;
      }
    }
  } catch (e) {}
  if (fallbackUrl) {
    window.location.href = fallbackUrl;
  } else {
    window.location.href = "./admin.php?id=sessions";
  }
}

(function () {
  function injectCompactBackButton() {
    var cfg = window.NGW_UI_CONFIG || {};
    var backUrl = cfg.backUrl || "";
    if (!backUrl) return;
    if (document.querySelector(".ngw-compact-back-btn")) return;

    var mainContainer = document.querySelector("#main .main-container");
    if (!mainContainer) return;

    var label = cfg.backLabel || "Back";
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "ngw-compact-back-btn";
    btn.title = label;
    btn.innerHTML = '<i class="fa fa-arrow-left" aria-hidden="true"></i> <span>' + _ngwEscapeHtml(label) + "</span>";
    btn.onclick = function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      ngwSmartBack(backUrl);
    };

    var profileWrap = mainContainer.querySelector(".ngw-profile-wrap");
    if (profileWrap) {
      var hero = profileWrap.querySelector(".ngw-profile-hero");
      if (hero && hero.parentNode) {
        var bar = document.createElement("div");
        bar.style.cssText = "margin-bottom:8px;display:flex;align-items:center;";
        bar.appendChild(btn);
        profileWrap.insertBefore(bar, profileWrap.firstChild);
      }
      return;
    }

    var cardHeader = mainContainer.querySelector(".card > .card-header");
    if (!cardHeader) return;
    if (cardHeader.querySelector("i.fa-arrow-left")) return;

    var firstHeading = cardHeader.querySelector("h3, h4");
    if (!firstHeading) return;
    firstHeading.insertBefore(btn, firstHeading.firstChild);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", injectCompactBackButton);
  } else {
    injectCompactBackButton();
  }
})();

function sortTable(e, t, n) {
  var l,
    o = e.tBodies[0],
    i = Array.prototype.slice.call(o.rows, 0);
  for (
    n = -(+n || -1),
      i = i.sort(function (e, l) {
        return n * e.cells[t].textContent.trim().localeCompare(l.cells[t].textContent.trim());
      }),
      l = 0;
    l < i.length;
    ++l
  ) {
    o.appendChild(i[l]);
  }
}

function makeSortable(e) {
  var t,
    n = e.tHead;
  if (n && (n = n.rows[0]) && (n = n.cells), n) {
    for (t = n.length; --t >= 0; ) {
      !(function (t) {
        var l = 1;
        n[t].addEventListener("click", function () {
          sortTable(e, t, (l = 1 - l));
        });
      })(t);
    }
  }
}

function makeAllSortable(e) {
  for (var t = (e = e || document.body).getElementsByTagName("table"), n = t.length; --n >= 0; ) {
    makeSortable(t[n]);
  }
}

$(".main-container").show();
$("#loading").hide();

var idleto,
  idtoEl = document.getElementById("idto"),
  idto = idtoEl ? idtoEl.innerHTML : "5";

function idleTimer() {
  var e = document.getElementById("timer");
  function t() {
    if (e) e.innerHTML = idleto;
  }
  window.onmousemove = t;
  window.onmousedown = t;
  window.onclick = t;
  window.onscroll = t;
  window.onkeypress = t;
}

function startTimer() {
  var e = document.getElementById("logout"),
    t = document.getElementById("timer");
  if (!t) return;
  var n = t.innerHTML.split(/[:]+/),
    l = n[0],
    o = checkSecond(n[1] - 1);
  if (59 == o) l -= 1;
  if (0 == l && 0 == o) {
    t.innerHTML = "0:00";
    if (e) e.click();
  }
  t.innerHTML = l + ":" + o;
  setTimeout(startTimer, 1e3);
}

function checkSecond(e) {
  if (e < 10 && e >= 0) e = "0" + e;
  if (e < 0) e = "59";
  return e;
}

idleto = "" == idto || "0" == idto ? "5:00" : idto + ":00";
var timerEl = document.getElementById("timer");
if (timerEl) timerEl.innerHTML = idleto;
var url = window.location.href,
  getID = url.split("=")[1];
if ("login" != getID && "disable" != idto && timerEl) {
  idleTimer();
  startTimer();
}
