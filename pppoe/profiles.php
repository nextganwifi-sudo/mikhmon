<?php
/*
 *  NextGan WiFi Solution — PPPoE Profiles List
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

requireResellerPermission('view_pppoe_profiles', './admin.php?id=sessions');

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));

// Fetch all profiles from MikroTik
$profiles = $isRouterConnected ? $API->comm("/ppp/profile/print") : array();
$totalProfiles = is_array($profiles) ? count($profiles) : 0;

// Also fetch secrets to count users per profile
$secrets = $isRouterConnected ? $API->comm("/ppp/secret/print") : array();
$profileUserCounts = array();
if (is_array($secrets)) {
  foreach ($secrets as $sec) {
    $p = isset($sec['profile']) ? $sec['profile'] : 'default';
    if (!isset($profileUserCounts[$p])) {
      $profileUserCounts[$p] = 0;
    }
    $profileUserCounts[$p]++;
  }
}
?>

<div class="row">
  <div class="col-12">
    <div class="card box-bordered">
      <div class="card-header bg-navy text-white d-flex justify-content-between align-items-center" style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0; font-size:16px; font-weight:600;">
          <i class="fa fa-pie-chart"></i> PPPoE Profiles (<?= $totalProfiles; ?>)
        </h3>
        <div style="display:flex; gap:6px;">
          <?php if (hasResellerPermission('manage_pppoe_profiles')) { ?>
            <a href="./?pppoe=add-profile&session=<?= $session; ?>" class="btn btn-sm bg-primary text-white">
              <i class="fa fa-plus-circle"></i> Add Profile
            </a>
          <?php } ?>
          <a href="./?pppoe=users&session=<?= $session; ?>" class="btn btn-sm bg-secondary text-white">
            <i class="fa fa-users"></i> Users
          </a>
        </div>
      </div>

      <div class="card-body" style="padding:12px;">
        <?php if (isset($_GET['msg'])) { 
          $msg = $_GET['msg'];
          $alertClass = 'alert-success';
          $alertText = 'Operation completed successfully.';
          if ($msg === 'created') $alertText = 'PPPoE profile created successfully.';
          if ($msg === 'updated') $alertText = 'PPPoE profile updated successfully.';
          if ($msg === 'deleted') $alertText = 'PPPoE profile removed successfully.';
        ?>
          <div class="alert <?= $alertClass; ?>" style="padding:8px 12px; margin-bottom:12px; border-radius:4px;">
            <i class="fa fa-check-circle"></i> <?= htmlspecialchars($alertText); ?>
          </div>
        <?php } ?>

        <!-- Search Bar -->
        <div style="margin-bottom:12px;">
          <input type="text" id="profileSearch" onkeyup="filterProfiles()" class="form-control" placeholder="Search profile name, rate-limit, IP pool..." style="max-width:320px;">
        </div>

        <div class="table-responsive" style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
          <table class="table table-bordered table-hover" id="profilesTable" style="width:100%; font-size:13px; margin-bottom:0;">
            <thead style="background:#f8fafc; font-weight:600; color:#334155;">
              <tr>
                <th style="width:50px; text-align:center;">#</th>
                <th>Profile Name</th>
                <th>Rate Limit (Rx/Tx)</th>
                <th>Local Address</th>
                <th>Remote Address</th>
                <th style="text-align:center;">Only One</th>
                <th style="text-align:center;">Users</th>
                <th style="width:90px; text-align:center;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if (!is_array($profiles) || empty($profiles)) {
                echo "<tr><td colspan='8' class='text-center text-muted' style='padding:24px;'>No PPPoE profiles found on this router.</td></tr>";
              } else {
                $idx = 1;
                foreach ($profiles as $p) {
                  $pid = isset($p['.id']) ? $p['.id'] : '';
                  $name = isset($p['name']) ? $p['name'] : '';
                  $rateLimit = isset($p['rate-limit']) && $p['rate-limit'] !== '' ? $p['rate-limit'] : '-';
                  $localAddr = isset($p['local-address']) && $p['local-address'] !== '' ? $p['local-address'] : '-';
                  $remoteAddr = isset($p['remote-address']) && $p['remote-address'] !== '' ? $p['remote-address'] : '-';
                  $onlyOne = isset($p['only-one']) ? $p['only-one'] : 'default';
                  $userCount = isset($profileUserCounts[$name]) ? $profileUserCounts[$name] : 0;
                  $isDefault = ($name === 'default' || $name === 'default-encryption');
              ?>
                <tr class="profile-row" data-name="<?= strtolower($name); ?>" data-rate="<?= strtolower($rateLimit); ?>">
                  <td style="text-align:center; color:#64748b;"><?= $idx++; ?></td>
                  <td style="font-weight:600;">
                    <a href="./?pppoe=users&profile=<?= urlencode($name); ?>&session=<?= $session; ?>" style="color:#0284c7; text-decoration:none;">
                      <?= htmlspecialchars($name); ?>
                    </a>
                    <?php if ($isDefault) { ?>
                      <span class="badge" style="background:#94a3b8; color:#fff; font-size:10px; margin-left:4px; padding:2px 6px;">system</span>
                    <?php } ?>
                  </td>
                  <td>
                    <?php if ($rateLimit !== '-') { ?>
                      <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:600; padding:3px 6px; font-size:11px;">
                        <i class="fa fa-dashboard"></i> <?= htmlspecialchars($rateLimit); ?>
                      </span>
                    <?php } else { echo '<span class="text-muted">-</span>'; } ?>
                  </td>
                  <td><?= htmlspecialchars($localAddr); ?></td>
                  <td><?= htmlspecialchars($remoteAddr); ?></td>
                  <td style="text-align:center;">
                    <span class="badge" style="background:<?= $onlyOne === 'yes' ? '#dcfce7' : '#f1f5f9'; ?>; color:<?= $onlyOne === 'yes' ? '#166534' : '#475569'; ?>; padding:2px 6px; font-size:11px;">
                      <?= htmlspecialchars($onlyOne); ?>
                    </span>
                  </td>
                  <td style="text-align:center;">
                    <a href="./?pppoe=users&profile=<?= urlencode($name); ?>&session=<?= $session; ?>" class="badge" style="background:#ede9fe; color:#6d28d9; padding:3px 8px; font-size:12px; text-decoration:none;">
                      <?= $userCount; ?> users
                    </a>
                  </td>
                  <td style="text-align:center; white-space:nowrap;">
                    <?php if (hasResellerPermission('manage_pppoe_profiles')) { ?>
                      <a href="./?pppoe=profile&id=<?= urlencode($pid); ?>&session=<?= $session; ?>" class="btn btn-xs bg-primary text-white" title="Edit" style="padding:2px 6px;">
                        <i class="fa fa-pencil"></i>
                      </a>
                      <?php if (!$isDefault) { ?>
                        <a href="./?pppoe=remove-profile&id=<?= urlencode($pid); ?>&session=<?= $session; ?>" class="btn btn-xs bg-danger text-white" title="Delete" style="padding:2px 6px; margin-left:2px;" onclick="return confirm('Delete PPPoE profile \'<?= addslashes($name); ?>\'?');">
                          <i class="fa fa-trash"></i>
                        </a>
                      <?php } ?>
                    <?php } else { ?>
                      <span class="text-muted" style="font-size:11px;">Read-only</span>
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
function filterProfiles() {
  var input = document.getElementById('profileSearch');
  var filter = input.value.toLowerCase();
  var rows = document.getElementsByClassName('profile-row');
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
