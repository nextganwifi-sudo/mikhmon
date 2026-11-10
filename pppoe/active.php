<?php
/*
 *  NextGan WiFi Solution — PPPoE Active Sessions
 *  Real-time connected PPPoE sessions with 1-click disconnect and live traffic monitor
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  echo "<script>window.location='./admin.php?id=login'</script>";
  return;
}

include_once __DIR__ . '/../include/config.php';
include_once __DIR__ . '/../include/ros_version.php';
include_once __DIR__ . '/../include/reseller_acl.php';
include_once __DIR__ . '/../include/pppoe_helper.php';
include_once __DIR__ . '/../lib/formatbytesbites.php';

requireResellerPermission('view_pppoe_active', './admin.php?id=sessions');

$session = isset($_GET['session']) ? (string)$_GET['session'] : '';
if ($session === '' || !canAccessRouterSession($session)) {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
  return;
}

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));

// Fetch active sessions from MikroTik
$actives = $isRouterConnected ? $API->comm("/ppp/active/print") : array();
$pppoeActives = array();
if (is_array($actives)) {
  foreach ($actives as $act) {
    $svc = isset($act['service']) ? strtolower($act['service']) : '';
    if ($svc === 'pppoe' || $svc === '') {
      $pppoeActives[] = $act;
    }
  }
}
$totalActive = count($pppoeActives);
?>

<div class="row">
  <div class="col-12">
    <div class="card box-bordered">
      <div class="card-header">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <h3 style="margin:0;">
            <i class="fa fa-wifi text-green"></i> <?= ($langid === 'bn') ? 'অনলাইন ইউজার' : 'Online Users'; ?> (<?= $totalActive; ?>)
            <span style="font-size: 14px">
              &nbsp; | &nbsp; <a href="./?pppoe=active&session=<?= urlencode($session); ?>" title="<?= ($langid === 'bn') ? 'রিলোড' : 'Reload'; ?>"><i class="fa fa-refresh"></i> <?= ($langid === 'bn') ? 'রিলোড' : 'Reload'; ?></a>
            </span>
            <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> Processing... </i></small>
          </h3>
          <div style="display:inline-flex; align-items:center; gap:4px;">
            <input type="text" id="filterTable" class="form-control" style="width:180px; height:28px; font-size:11px; padding:2px 8px; border-radius:3px;" placeholder="<?= ($langid === 'bn') ? 'অনলাইন ইউজার খুঁজুন...' : 'Search online users...'; ?>">
          </div>
        </div>
      </div>

      <div class="card-body">
        <?php if (!$isRouterConnected) { ?>
          <div class="bg-danger pd-5 radius-3 mr-b-10 text-white"><i class="fa fa-plug"></i> <?= ($langid === 'bn') ? 'রাউটার অফলাইন অথবা API পোর্ট ৮৭২৮ এ পৌঁছানো যাচ্ছে না।' : 'Router is offline or unreachable via API port 8728.'; ?></div>
        <?php } ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'disconnected') { ?>
          <div class="bg-green pd-5 radius-3 mr-b-10 text-white">
            <i class="fa fa-check-circle"></i> <?= ($langid === 'bn') ? 'সেশন সফলভাবে ডিসকানেক্ট করা হয়েছে।' : 'Active session disconnected successfully.'; ?>
          </div>
        <?php } ?>

        <!-- Stat Box -->
        <div class="row mr-b-10">
          <div class="col-4 col-box-6">
            <div class="box bg-blue bmh-75">
              <a href="./?pppoe=active&session=<?= urlencode($session); ?>">
                <h1><?= $totalActive; ?> <span style="font-size: 14px;"><?= ($langid === 'bn') ? 'অনলাইন' : 'Online'; ?></span></h1>
                <div><i class="fa fa-wifi"></i> <?= ($langid === 'bn') ? 'অনলাইন ইউজার' : 'Online Users'; ?></div>
              </a>
            </div>
          </div>
        </div>

        <div class="overflow box-bordered" style="max-height:72vh;">
          <table class="table table-bordered table-hover text-nowrap" id="dataTable" style="font-size:12px; margin-bottom:0;">
            <thead style="background:rgba(255,255,255,0.04);">
              <tr>
                <th style="width:40px;" class="text-center">#</th>
                <th><?= ($langid === 'bn') ? 'ইউজারনেম' : 'Username'; ?></th>
                <th><?= ($langid === 'bn') ? 'আইপি অ্যাড্রেস' : 'IP Address'; ?></th>
                <th><?= ($langid === 'bn') ? 'ম্যাক / কলার আইডি' : 'Caller ID (MAC)'; ?></th>
                <th><?= ($langid === 'bn') ? 'সার্ভিস' : 'Service'; ?></th>
                <th><?= ($langid === 'bn') ? 'আপটাইম' : 'Uptime'; ?></th>
                <th style="width:60px;" class="text-center"><?= ($langid === 'bn') ? 'অ্যাকশন' : 'Action'; ?></th>
              </tr>
            </thead>
            <tbody>
              <?php
              if (empty($pppoeActives)) {
                echo "<tr><td colspan='7' class='text-center pd-10'>" . (($langid === 'bn') ? 'বর্তমানে কোনো সক্রিয় অনলাইন সেশন নেই।' : 'No active PPPoE sessions currently connected.') . "</td></tr>";
              } else {
                $idx = 1;
                foreach ($pppoeActives as $act) {
                  $aid = isset($act['.id']) ? $act['.id'] : '';
                  $uname = isset($act['name']) ? $act['name'] : '';
                  $ip = isset($act['address']) ? $act['address'] : '-';
                  $callerId = isset($act['caller-id']) && $act['caller-id'] !== '' ? $act['caller-id'] : '-';
                  $svc = isset($act['service']) ? strtoupper($act['service']) : 'PPPOE';
                  $uptime = isset($act['uptime']) ? $act['uptime'] : '-';
                  $ifaceCandidate = isset($act['interface']) && $act['interface'] !== '' ? $act['interface'] : '<pppoe-' . $uname . '>';
              ?>
                <tr>
                  <td class="align-middle text-center" style="font-weight:600; opacity:0.85;"><?= $idx++; ?></td>
                  <td class="align-middle">
                    <a href="./?pppoe=user&user=<?= urlencode($uname); ?>&session=<?= urlencode($session); ?>" style="font-weight:700; text-decoration:none;">
                      <i class="fa fa-user" style="opacity:0.7; margin-right:3px;"></i> <?= htmlspecialchars($uname); ?>
                    </a>
                  </td>
                  <td class="align-middle">
                    <span class="badge bg-green" style="font-family:monospace; font-size:11px;">
                      <?= htmlspecialchars($ip); ?>
                    </span>
                  </td>
                  <td class="align-middle">
                    <code style="font-size:11px;"><?= htmlspecialchars($callerId); ?></code>
                  </td>
                  <td class="align-middle">
                    <span class="badge bg-blue" style="font-size:10px;">
                      <?= htmlspecialchars($svc); ?>
                    </span>
                  </td>
                  <td class="align-middle">
                    <i class="fa fa-clock-o" style="opacity:0.65;"></i> <?= htmlspecialchars($uptime); ?>
                  </td>
                  <td class="align-middle text-center" style="white-space:nowrap;">
                    <button type="button" class="btn bg-secondary pd-2p5 radius-3 text-white" style="padding:3px 8px; font-size:12px; cursor:pointer;" onclick="openActiveRowMenu(event, '<?= htmlspecialchars($aid, ENT_QUOTES); ?>', '<?= htmlspecialchars(addslashes($uname), ENT_QUOTES); ?>', '<?= htmlspecialchars(addslashes($ifaceCandidate), ENT_QUOTES); ?>', '<?= htmlspecialchars(addslashes($ip), ENT_QUOTES); ?>')" title="Actions">
                      <i class="fa fa-ellipsis-v"></i>
                    </button>
                  </td>
                </tr>
              <?php } } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Live Traffic Modal -->
<div id="pppoeTrafficModal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; padding:15px; box-sizing:border-box;">
  <div class="card box-bordered" style="width:100%; max-width:820px; margin:auto; background:inherit; background-color:#1e293b; color:#f8fafc; box-shadow:0 12px 36px rgba(0,0,0,0.6); border-radius:6px; overflow:hidden;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background:#0f172a; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.1);">
      <h3 style="margin:0; font-size:15px; color:#fff; display:flex; align-items:center; gap:8px;">
        <i class="fa fa-area-chart" style="color:#22c55e;"></i>
        <span>Live Traffic Monitor &mdash; <b id="ptmUsername" style="color:#38bdf8;">-</b></span>
      </h3>
      <button type="button" onclick="closePppoeLiveTraffic()" style="background:transparent; border:none; color:#94a3b8; font-size:20px; line-height:1; cursor:pointer; padding:2px 8px;" title="Close">&times;</button>
    </div>

    <div class="card-body" style="padding:16px;">
      <!-- Details & Status Bar -->
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px; font-size:12px; color:#94a3b8;">
        <div>
          <span>Interface: <code id="ptmInterface" style="color:#e2e8f0; background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:3px;">-</code></span>
          &nbsp;&bull;&nbsp;
          <span>IP: <code id="ptmIp" style="color:#e2e8f0; background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:3px;">-</code></span>
        </div>
        <div>
          <span id="ptmStatusBadge" class="badge bg-green" style="font-size:10px; padding:3px 7px;">
            <i class="fa fa-circle text-white"></i> LIVE POLLING (2s)
          </span>
        </div>
      </div>

      <!-- Live Speed Cards -->
      <div class="row mr-b-10" style="margin-left:-5px; margin-right:-5px;">
        <div class="col-6" style="padding-left:5px; padding-right:5px;">
          <div style="background:#0f172a; border:1px solid #166534; border-radius:6px; padding:12px; text-align:center;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#4ade80; font-weight:600;">
              <i class="fa fa-arrow-circle-down"></i> Download (Rx)
            </div>
            <div id="ptmRxSpeed" style="font-size:24px; font-weight:700; color:#22c55e; margin:4px 0; font-family:monospace;">
              0 bps
            </div>
            <div style="font-size:10px; color:#64748b;">Incoming bits/sec</div>
          </div>
        </div>
        <div class="col-6" style="padding-left:5px; padding-right:5px;">
          <div style="background:#0f172a; border:1px solid #1e40af; border-radius:6px; padding:12px; text-align:center;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#60a5fa; font-weight:600;">
              <i class="fa fa-arrow-circle-up"></i> Upload (Tx)
            </div>
            <div id="ptmTxSpeed" style="font-size:24px; font-weight:700; color:#38bdf8; margin:4px 0; font-family:monospace;">
              0 bps
            </div>
            <div style="font-size:10px; color:#64748b;">Outgoing bits/sec</div>
          </div>
        </div>
      </div>

      <!-- Highcharts Real-time Traffic Graph -->
      <div id="trafficChartContainer" style="width:100%; height:260px; background:#0f172a; border-radius:6px; border:1px solid rgba(255,255,255,0.08); overflow:hidden;"></div>
      
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px;">
        <span style="font-size:11px; color:#64748b;">
          <i class="fa fa-info-circle"></i> Monitoring RouterOS dynamic interface traffic live via API
        </span>
        <button type="button" class="btn bg-secondary pd-5 radius-3 text-white" onclick="closePppoeLiveTraffic()" style="font-size:12px; cursor:pointer;">
          <i class="fa fa-times"></i> Close Monitor
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Load Highcharts if not present -->
<script src="js/highcharts/highcharts.js"></script>

<script>
var ptmTimer = null;
var ptmChart = null;
var ptmSession = "<?= htmlspecialchars($session, ENT_QUOTES); ?>";
var ptmCurrentIface = "";

function formatBandwidth(bits) {
  bits = parseInt(bits) || 0;
  var sizes = ['bps', 'kbps', 'Mbps', 'Gbps'];
  if (bits === 0) return '0 bps';
  var i = parseInt(Math.floor(Math.log(bits) / Math.log(1024)));
  if (i >= sizes.length) i = sizes.length - 1;
  return parseFloat((bits / Math.pow(1024, i)).toFixed(2)) + ' ' + sizes[i];
}

function initPtmChart() {
  if (typeof Highcharts === 'undefined') return;

  Highcharts.setOptions({
    global: { useUTC: false }
  });

  ptmChart = new Highcharts.Chart({
    chart: {
      renderTo: 'trafficChartContainer',
      backgroundColor: '#0f172a',
      type: 'areaspline',
      animation: Highcharts.svg,
      spacing: [10, 10, 15, 10]
    },
    title: { text: null },
    credits: { enabled: false },
    xAxis: {
      type: 'datetime',
      tickPixelInterval: 120,
      labels: { style: { color: '#94a3b8', fontSize: '10px' } },
      lineColor: 'rgba(255,255,255,0.1)',
      tickColor: 'rgba(255,255,255,0.1)'
    },
    yAxis: {
      title: { text: null },
      min: 0,
      gridLineColor: 'rgba(255,255,255,0.06)',
      labels: {
        style: { color: '#94a3b8', fontSize: '10px' },
        formatter: function () {
          return formatBandwidth(this.value);
        }
      }
    },
    tooltip: {
      shared: true,
      backgroundColor: '#1e293b',
      borderColor: 'rgba(255,255,255,0.2)',
      style: { color: '#f8fafc' },
      formatter: function () {
        var s = '<b>Time: ' + Highcharts.dateFormat('%H:%M:%S', this.x) + '</b><br/>';
        $.each(this.points, function () {
          s += '<span style="color:' + this.series.color + '">\u25CF</span> ' + this.series.name + ': <b>' + formatBandwidth(this.y) + '</b><br/>';
        });
        return s;
      }
    },
    plotOptions: {
      areaspline: {
        fillOpacity: 0.25,
        lineWidth: 2,
        marker: { enabled: false }
      }
    },
    series: [{
      name: 'Tx (Upload)',
      color: '#38bdf8',
      data: []
    }, {
      name: 'Rx (Download)',
      color: '#22c55e',
      data: []
    }]
  });
}

function fetchPtmTraffic() {
  if (!ptmCurrentIface) return;

  $.ajax({
    url: './traffic/traffic.php?session=' + encodeURIComponent(ptmSession) + '&iface=' + encodeURIComponent(ptmCurrentIface),
    dataType: 'json',
    timeout: 3000,
    success: function(data) {
      if (Array.isArray(data) && data.length >= 2) {
        var tx = parseInt(data[0].data[0]) || 0;
        var rx = parseInt(data[1].data[0]) || 0;

        document.getElementById('ptmTxSpeed').innerText = formatBandwidth(tx);
        document.getElementById('ptmRxSpeed').innerText = formatBandwidth(rx);

        if (ptmChart && ptmChart.series && ptmChart.series.length >= 2) {
          var now = (new Date()).getTime();
          var shift = ptmChart.series[0].data.length > 25;
          ptmChart.series[0].addPoint([now, tx], true, shift);
          ptmChart.series[1].addPoint([now, rx], true, shift);
        }
      }
    },
    error: function() {
      document.getElementById('ptmStatusBadge').className = 'badge bg-red';
      document.getElementById('ptmStatusBadge').innerHTML = '<i class="fa fa-warning"></i> Connecting...';
    }
  });
}

function openPppoeLiveTraffic(uname, iface, ip) {
  ptmCurrentIface = iface;

  document.getElementById('ptmUsername').innerText = uname;
  document.getElementById('ptmInterface').innerText = iface;
  document.getElementById('ptmIp').innerText = ip;
  document.getElementById('ptmTxSpeed').innerText = '0 bps';
  document.getElementById('ptmRxSpeed').innerText = '0 bps';
  document.getElementById('ptmStatusBadge').className = 'badge bg-green';
  document.getElementById('ptmStatusBadge').innerHTML = '<i class="fa fa-circle text-white"></i> LIVE POLLING (2s)';

  var modal = document.getElementById('pppoeTrafficModal');
  modal.style.display = 'flex';

  if (!ptmChart) {
    initPtmChart();
  } else {
    // Reset points
    ptmChart.series[0].setData([], true);
    ptmChart.series[1].setData([], true);
  }

  // Clear existing interval if any
  if (ptmTimer) clearInterval(ptmTimer);

  // Initial fetch
  fetchPtmTraffic();
  // Poll every 2 seconds
  ptmTimer = setInterval(fetchPtmTraffic, 2000);
}

function closePppoeLiveTraffic() {
  if (ptmTimer) {
    clearInterval(ptmTimer);
    ptmTimer = null;
  }
  var modal = document.getElementById('pppoeTrafficModal');
  if (modal) modal.style.display = 'none';
  ptmCurrentIface = "";
}

// Close modal when clicking outside modal box
window.addEventListener('click', function(e) {
  var modal = document.getElementById('pppoeTrafficModal');
  if (e.target === modal) {
    closePppoeLiveTraffic();
  }
});
</script>

<!-- Floating Action Dropdown Menu for Active Sessions -->
<div id="activeFloatingMenu" style="display:none; position:fixed; z-index:999999; background:#1e293b; border:1px solid rgba(255,255,255,0.2); box-shadow:0 10px 25px rgba(0,0,0,0.6); border-radius:4px; min-width:140px; padding:4px 0; text-align:left;">
  <a href="javascript:void(0)" onclick="triggerLiveTrafficFromMenu()" style="display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:11px; color:#22c55e; text-decoration:none;">
    <i class="fa fa-area-chart" style="width:16px;"></i> <?= ($langid === 'bn') ? 'লাইভ ট্রাফিক' : 'Live Traffic'; ?>
  </a>
  <?php if (hasResellerPermission('disconnect_pppoe_active')) { ?>
  <a id="floatingActiveDiscLink" href="#" style="display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:11px; color:#ef4444; text-decoration:none;" onclick="return confirm('<?= ($langid === 'bn') ? 'আপনি কি এই সক্রিয় সেশন ডিসকানেক্ট করতে চান?' : 'Disconnect active session?'; ?>');">
    <i class="fa fa-power-off" style="width:16px;"></i> <?= ($langid === 'bn') ? 'ডিসকানেক্ট' : 'Disconnect'; ?>
  </a>
  <?php } ?>
</div>

<script>
var currentMenuTarget = { aid: '', uname: '', iface: '', ip: '' };

function openActiveRowMenu(event, aid, uname, iface, ip) {
  event.stopPropagation();
  currentMenuTarget = { aid: aid, uname: uname, iface: iface, ip: ip };

  var menu = document.getElementById('activeFloatingMenu');
  var btn = event.currentTarget;
  var rect = btn.getBoundingClientRect();

  var discLink = document.getElementById('floatingActiveDiscLink');
  if (discLink) {
    discLink.href = './?disconnect-pppoe-active=' + encodeURIComponent(aid) + '&session=<?= urlencode($session); ?>';
  }

  menu.style.display = 'block';
  var menuWidth = menu.offsetWidth || 140;
  var left = rect.right - menuWidth;
  if (left < 10) left = 10;
  var top = rect.bottom + 2;
  if (top + 120 > window.innerHeight) {
    top = rect.top - 100;
  }
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
}

function triggerLiveTrafficFromMenu() {
  var menu = document.getElementById('activeFloatingMenu');
  if (menu) menu.style.display = 'none';
  if (currentMenuTarget.uname) {
    openPppoeLiveTraffic(currentMenuTarget.uname, currentMenuTarget.iface, currentMenuTarget.ip);
  }
}

window.addEventListener('click', function() {
  var menu = document.getElementById('activeFloatingMenu');
  if (menu) menu.style.display = 'none';
});

// Auto-refresh active sessions in background every 25 seconds when idle
setInterval(function() {
  var filterInput = document.getElementById('filterTable');
  var trafficModal = document.getElementById('pppoeTrafficModal');
  var activeMenu = document.getElementById('activeFloatingMenu');
  var isTyping = filterInput && (filterInput.value.trim().length > 0 || document.activeElement === filterInput);
  var isModalOpen = trafficModal && trafficModal.style.display === 'flex';
  var isMenuOpen = activeMenu && activeMenu.style.display === 'block';

  if (!isTyping && !isModalOpen && !isMenuOpen && !document.hidden) {
    window.location.reload();
  }
}, 25000);
</script>
