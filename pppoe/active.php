<?php
/*
 *  NextGan WiFi Solution — PPPoE Active Sessions
 *  Real-time connected PPPoE sessions with 1-click disconnect
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  echo "<script>window.location='./admin.php?id=login'</script>";
  return;
}

include_once('./include/config.php');
include_once('./include/ros_version.php');
include_once('./include/reseller_acl.php');
include_once('./include/pppoe_helper.php');
include_once('./lib/formatbytesbites.php');

requireResellerPermission('view_pppoe_active', './admin.php?id=sessions');

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));

// Fetch active sessions from MikroTik
$actives = $isRouterConnected ? $API->comm("/ppp/active/print") : array();
$pppoeActives = array();
if (is_array($actives)) {
  foreach ($actives as $act) {
    $svc = isset($act['service']) ? strtolower($act['service']) : '';
    // Focus on PPPoE sessions, or all if PPPoE
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
      <div class="card-header bg-navy text-white d-flex justify-content-between align-items-center" style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0; font-size:16px; font-weight:600;">
          <i class="fa fa-bolt" style="color:#fde047;"></i> PPPoE Active Sessions (<?= $totalActive; ?>)
        </h3>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn btn-sm bg-primary text-white" onclick="location.reload();">
            <i class="fa fa-refresh"></i> Refresh
          </button>
          <a href="./?pppoe=users&session=<?= $session; ?>" class="btn btn-sm bg-secondary text-white">
            <i class="fa fa-users"></i> Users
          </a>
        </div>
      </div>

      <div class="card-body" style="padding:12px;">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'disconnected') { ?>
          <div class="alert alert-success" style="padding:8px 12px; margin-bottom:12px; border-radius:4px;">
            <i class="fa fa-check-circle"></i> Active session disconnected successfully.
          </div>
        <?php } ?>

        <!-- Search Bar -->
        <div style="margin-bottom:12px;">
          <input type="text" id="activeSearch" onkeyup="filterActives()" class="form-control" placeholder="Search username, IP, Caller ID / MAC..." style="max-width:320px;">
        </div>

        <div class="table-responsive" style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
          <table class="table table-bordered table-hover" id="activeTable" style="width:100%; font-size:13px; margin-bottom:0;">
            <thead style="background:#f8fafc; font-weight:600; color:#334155;">
              <tr>
                <th style="width:40px; text-align:center;">#</th>
                <th>Username</th>
                <th>IP Address</th>
                <th>Caller ID (MAC)</th>
                <th>Service</th>
                <th>Uptime</th>
                <th style="width:100px; text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              if (empty($pppoeActives)) {
                echo "<tr><td colspan='7' class='text-center text-muted' style='padding:24px;'>No active PPPoE sessions currently connected.</td></tr>";
              } else {
                $idx = 1;
                foreach ($pppoeActives as $act) {
                  $aid = isset($act['.id']) ? $act['.id'] : '';
                  $uname = isset($act['name']) ? $act['name'] : '';
                  $ip = isset($act['address']) ? $act['address'] : '-';
                  $callerId = isset($act['caller-id']) && $act['caller-id'] !== '' ? $act['caller-id'] : '-';
                  $svc = isset($act['service']) ? strtoupper($act['service']) : 'PPPOE';
                  $uptime = isset($act['uptime']) ? $act['uptime'] : '-';
              ?>
                <tr class="active-row" data-user="<?= strtolower($uname); ?>" data-ip="<?= strtolower($ip); ?>" data-caller="<?= strtolower($callerId); ?>">
                  <td style="text-align:center; color:#64748b;"><?= $idx++; ?></td>
                  <td style="font-weight:600;">
                    <a href="./?pppoe=user&name=<?= urlencode($uname); ?>&session=<?= $session; ?>" style="color:#0284c7; text-decoration:none;">
                      <?= htmlspecialchars($uname); ?>
                    </a>
                  </td>
                  <td>
                    <span class="badge" style="background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; font-family:monospace; padding:3px 6px;">
                      <?= htmlspecialchars($ip); ?>
                    </span>
                  </td>
                  <td>
                    <span style="font-family:monospace; font-size:12px; color:#475569;">
                      <?= htmlspecialchars($callerId); ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge" style="background:#e0f2fe; color:#0369a1; padding:2px 6px; font-size:11px;">
                      <?= htmlspecialchars($svc); ?>
                    </span>
                  </td>
                  <td style="white-space:nowrap; color:#334155;">
                    <i class="fa fa-clock-o" style="color:#64748b;"></i> <?= htmlspecialchars($uptime); ?>
                  </td>
                  <td style="text-align:center; white-space:nowrap;">
                    <?php if (hasResellerPermission('disconnect_pppoe_active')) { ?>
                      <a href="./?pppoe=disconnect-active&id=<?= urlencode($aid); ?>&user=<?= urlencode($uname); ?>&session=<?= $session; ?>" class="btn btn-xs bg-danger text-white" title="Disconnect Session" style="padding:3px 8px; font-weight:600;" onclick="return confirm('Disconnect active session for user \'<?= addslashes($uname); ?>\'?');">
                        <i class="fa fa-power-off"></i> Disconnect
                      </a>
                    <?php } else { ?>
                      <span class="text-muted" style="font-size:11px;">Active</span>
                    <?php } ?>
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

<script>
function filterActives() {
  var input = document.getElementById('activeSearch');
  var filter = input.value.toLowerCase();
  var rows = document.getElementsByClassName('active-row');
  for (var i = 0; i < rows.length; i++) {
    var txt = rows[i].innerText.toLowerCase();
    if (txt.indexOf(filter) > -1) {
      rows[i].style.display = '';
    } else {
      rows[i].style.display = 'none';
    }
  }
}
</script>
