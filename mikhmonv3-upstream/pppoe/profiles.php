<?php
/*
 *  NextGan WiFi Solution — PPPoE Profiles List
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
      <div class="card-header">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <h3 style="margin:0;">
            <i class="fa fa-pie-chart"></i> <?= ($langid === 'bn') ? 'পিপিপিওই প্রোফাইল' : 'PPPoE Profiles'; ?>
            <span style="font-size: 14px">
              <?php if (hasResellerPermission('manage_pppoe_profiles')) { ?>
              &nbsp; | &nbsp; <a href="./?pppoe=add-profile&session=<?= urlencode($session); ?>" title="Add PPPoE Profile"><i class="fa fa-plus-circle"></i> <?= ($langid === 'bn') ? 'প্রোফাইল যুক্ত করুন' : 'Add Profile'; ?></a>
              <?php } ?>
              &nbsp; | &nbsp; <a href="./?pppoe=profiles&session=<?= urlencode($session); ?>" title="Refresh"><i class="fa fa-refresh"></i> <?= ($langid === 'bn') ? 'রিলোড' : 'Reload'; ?></a>
            </span>
            <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> Processing... </i></small>
          </h3>
          <div style="display:inline-flex; align-items:center; gap:4px;">
            <input type="text" id="filterTable" class="form-control" style="width:180px; height:28px; font-size:11px; padding:2px 8px; border-radius:3px;" placeholder="<?= ($langid === 'bn') ? 'প্রোফাইল সার্চ...' : 'Search profiles...'; ?>">
          </div>
        </div>
      </div>

      <div class="card-body">
        <?php if (!$isRouterConnected) { ?>
          <div class="bg-danger pd-5 radius-3 mr-b-10 text-white"><i class="fa fa-plug"></i> <?= ($langid === 'bn') ? 'রাউটার অফলাইন অথবা API পোর্ট ৮৭২৮ এ পৌঁছানো যাচ্ছে না।' : 'Router is offline or unreachable via API port 8728.'; ?></div>
        <?php } ?>
        <?php if (isset($_GET['msg'])) { 
          $msg = $_GET['msg'];
          $alertText = ($langid === 'bn') ? 'অপারেশন সফলভাবে সম্পন্ন হয়েছে।' : 'Operation completed successfully.';
          if ($msg === 'created') $alertText = ($langid === 'bn') ? 'পিপিপিওই প্রোফাইল সফলভাবে তৈরি হয়েছে।' : 'PPPoE profile created successfully.';
          if ($msg === 'updated') $alertText = ($langid === 'bn') ? 'পিপিপিওই প্রোফাইল সফলভাবে আপডেট হয়েছে।' : 'PPPoE profile updated successfully.';
          if ($msg === 'deleted') $alertText = ($langid === 'bn') ? 'পিপিপিওই প্রোফাইল সফলভাবে মুছে ফেলা হয়েছে।' : 'PPPoE profile removed successfully.';
        ?>
          <div class="bg-green pd-5 radius-3 mr-b-10 text-white">
            <i class="fa fa-check-circle"></i> <?= htmlspecialchars($alertText); ?>
          </div>
        <?php } ?>

        <!-- Stat Box -->
        <div class="row mr-b-10">
          <div class="col-4 col-box-6">
            <div class="box bg-blue bmh-75">
              <a href="./?pppoe=profiles&session=<?= urlencode($session); ?>">
                <h1><?= $totalProfiles; ?> <span style="font-size: 14px;"><?= ($langid === 'bn') ? 'প্রোফাইল' : 'Profiles'; ?></span></h1>
                <div><i class="fa fa-pie-chart"></i> <?= ($langid === 'bn') ? 'মোট পিপিপিওই প্রোফাইল' : 'Total PPPoE Profiles'; ?></div>
              </a>
            </div>
          </div>
        </div>

        <div class="overflow box-bordered" style="max-height:72vh;">
          <table class="table table-bordered table-hover text-nowrap" id="dataTable" style="font-size:12px; margin-bottom:0;">
            <thead style="background:rgba(255,255,255,0.04);">
              <tr>
                <th style="width:40px;" class="text-center">#</th>
                <th><?= ($langid === 'bn') ? 'প্রোফাইল নাম' : 'Profile Name'; ?></th>
                <th><?= ($langid === 'bn') ? 'রেট লিমিট (Rx/Tx)' : 'Rate Limit (Rx/Tx)'; ?></th>
                <th><?= ($langid === 'bn') ? 'লোকাল আইপি' : 'Local Address'; ?></th>
                <th><?= ($langid === 'bn') ? 'রিমোট আইপি' : 'Remote Address'; ?></th>
                <th style="text-align:center;"><?= ($langid === 'bn') ? 'একক সেশন (Only One)' : 'Only One'; ?></th>
                <th style="text-align:center;"><?= ($langid === 'bn') ? 'ইউজার সংখ্যা' : 'Users'; ?></th>
                <th style="width:90px;" class="text-center"><?= ($langid === 'bn') ? 'অ্যাকশন' : 'Action'; ?></th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if (!is_array($profiles) || empty($profiles)) {
                echo "<tr><td colspan='8' class='text-center pd-10'>" . (($langid === 'bn') ? 'এই রাউটারে কোনো পিপিপিওই প্রোফাইল পাওয়া যায়নি।' : 'No PPPoE profiles found on this router.') . "</td></tr>";
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
                <tr>
                  <td class="align-middle text-center" style="font-weight:600; opacity:0.85;"><?= $idx++; ?></td>
                  <td class="align-middle">
                    <b style="font-size:13px;"><?= htmlspecialchars($name); ?></b>
                    <?php if ($isDefault) { ?>
                      <span class="badge bg-secondary" style="font-size:10px; margin-left:4px;">system</span>
                    <?php } ?>
                  </td>
                  <td class="align-middle">
                    <?php if ($rateLimit !== '-') { ?>
                      <span class="badge bg-blue" style="font-size:11px;">
                        <i class="fa fa-dashboard"></i> <?= htmlspecialchars($rateLimit); ?>
                      </span>
                    <?php } else { echo '<span style="opacity:0.6;">-</span>'; } ?>
                  </td>
                  <td class="align-middle"><code><?= htmlspecialchars($localAddr); ?></code></td>
                  <td class="align-middle"><code><?= htmlspecialchars($remoteAddr); ?></code></td>
                  <td class="align-middle text-center">
                    <span class="badge <?= $onlyOne === 'yes' ? 'bg-green' : 'bg-secondary'; ?>" style="font-size:11px;">
                      <?= htmlspecialchars($onlyOne); ?>
                    </span>
                  </td>
                  <td class="align-middle text-center">
                    <span class="badge bg-primary" style="font-size:11px;">
                      <?= $userCount; ?> users
                    </span>
                  </td>
                  <td class="align-middle text-center" style="white-space:nowrap;">
                    <?php if (hasResellerPermission('manage_pppoe_profiles')) { ?>
                      <button type="button" class="btn bg-secondary pd-2p5 radius-3 text-white" style="padding:3px 8px; font-size:12px; cursor:pointer;" onclick="openProfileRowMenu(event, '<?= htmlspecialchars($pid, ENT_QUOTES); ?>', '<?= htmlspecialchars(addslashes($name), ENT_QUOTES); ?>', <?= $isDefault ? 'true' : 'false'; ?>)" title="Actions">
                        <i class="fa fa-ellipsis-v"></i>
                      </button>
                    <?php } else { ?>
                      <span style="font-size:11px; opacity:0.6;">Read-only</span>
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

<!-- Floating Action Dropdown Menu for Profiles -->
<div id="profileFloatingMenu" style="display:none; position:fixed; z-index:999999; background:#1e293b; border:1px solid rgba(255,255,255,0.2); box-shadow:0 10px 25px rgba(0,0,0,0.6); border-radius:4px; min-width:130px; padding:4px 0; text-align:left;">
  <a id="floatingProfEditLink" href="#" style="display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:11px; color:#f1f5f9; text-decoration:none;">
    <i class="fa fa-edit text-primary" style="width:16px;"></i> <?= ($langid === 'bn') ? 'প্রোফাইল এডিট' : 'Edit Profile'; ?>
  </a>
  <a id="floatingProfDelLink" href="#" style="display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:11px; color:#ef4444; text-decoration:none;" onclick="return confirm('<?= ($langid === 'bn') ? 'আপনি কি এই প্রোফাইল মুছে ফেলতে চান?' : 'Delete this PPPoE profile?'; ?>');">
    <i class="fa fa-trash text-danger" style="width:16px;"></i> <?= ($langid === 'bn') ? 'প্রোফাইল ডিলিট' : 'Delete Profile'; ?>
  </a>
</div>

<script>
function openProfileRowMenu(event, pid, name, isDefault) {
  event.stopPropagation();
  var menu = document.getElementById('profileFloatingMenu');
  var btn = event.currentTarget;
  var rect = btn.getBoundingClientRect();

  var editLink = document.getElementById('floatingProfEditLink');
  var delLink = document.getElementById('floatingProfDelLink');

  editLink.href = './?pppoe=profile&id=' + encodeURIComponent(pid) + '&session=<?= urlencode($session); ?>';
  if (isDefault) {
    delLink.style.display = 'none';
  } else {
    delLink.style.display = 'flex';
    delLink.href = './?pppoe=remove-profile&id=' + encodeURIComponent(pid) + '&session=<?= urlencode($session); ?>';
  }

  menu.style.display = 'block';
  var menuWidth = menu.offsetWidth || 130;
  var left = rect.right - menuWidth;
  if (left < 10) left = 10;
  var top = rect.bottom + 2;
  if (top + 100 > window.innerHeight) {
    top = rect.top - 80;
  }
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
}

window.addEventListener('click', function() {
  var menu = document.getElementById('profileFloatingMenu');
  if (menu) menu.style.display = 'none';
});
</script>

