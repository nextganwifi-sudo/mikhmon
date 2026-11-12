<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */

// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/reseller_acl.php');
  if (is_file('./include/data_recovery.php')) {
    include_once('./include/data_recovery.php');
    if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
      $recRes = ngwPerformDeepDataRecoveryAndMerge(realpath(__DIR__ . '/..'));
      if (!empty($recRes['routers_recovered']) && is_file('./include/config.php')) {
        include('./include/config.php');
      }
    }
  }

  // array color
  $color = array('1' => 'bg-blue', 'bg-indigo', 'bg-purple', 'bg-pink', 'bg-red', 'bg-yellow', 'bg-green', 'bg-teal', 'bg-cyan', 'bg-grey', 'bg-light-blue');
  $isBnSess = (isset($langid) && $langid === 'bn');
  $isSuperStoreSess = function_exists('isSuperAdminStore') && isSuperAdminStore();
  $isLicensedAdmSess = function_exists('isLicensedSubAdmin') && isLicensedSubAdmin();
  $currAdmRecSess = function_exists('getCurrentAdminRecord') ? getCurrentAdminRecord() : null;
  $allAdminsListSess = function_exists('loadAdminUsers') ? loadAdminUsers() : array();
}
?>

<?php if ($isLicensedAdmSess && is_array($currAdmRecSess)) {
  $myAdmId = (string)$currAdmRecSess['id'];
  $myRoutersList = ngwGetRoutersForAdmin($myAdmId, false);
  $myResellersList = ngwGetResellersForAdmin($myAdmId);
  $myMonthlyUsed = ngwGetAdminMonthlyVoucherUsage($myAdmId);
  $myDaysLeft = ngwGetLicenseDaysRemaining($currAdmRecSess);
  $maxR = (int)$currAdmRecSess['max_routers'];
  $maxRes = (int)$currAdmRecSess['max_resellers'];
  $maxM = (int)$currAdmRecSess['max_monthly_vouchers'];
  $maxAct = (int)$currAdmRecSess['max_active_users'];
?>
<div class="row">
  <div class="col-12">
    <div class="card" style="margin-bottom:10px;">
      <div class="card-body" style="padding:10px 14px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; background:rgba(31,111,235,0.08); border-left:4px solid #2ea043;">
        <div>
          <b><i class="fa fa-id-card text-green"></i> <?= $isBnSess ? 'লাইসেন্সড অ্যাডমিন ড্যাশবোর্ড:' : 'Licensed Admin Status:'; ?></b>
          &nbsp;
          <?= $isBnSess ? 'রাউটার কোটা:' : 'Routers:'; ?> <b><?= count($myRoutersList); ?>/<?= $maxR > 0 ? $maxR : '∞'; ?></b>
          &nbsp;·&nbsp;
          <?= $isBnSess ? 'রিসেলার কোটা:' : 'Resellers:'; ?> <b><?= count($myResellersList); ?>/<?= $maxRes > 0 ? $maxRes : '∞'; ?></b>
          &nbsp;·&nbsp;
          <?= $isBnSess ? 'মাসিক ভাউচার:' : 'Monthly Vouchers:'; ?> <b><?= $myMonthlyUsed; ?>/<?= $maxM > 0 ? $maxM : '∞'; ?></b>
          &nbsp;·&nbsp;
          <?= $isBnSess ? 'অ্যাক্টিভ ইউজার লিমিট:' : 'Active Limit:'; ?> <b><?= $maxAct > 0 ? $maxAct : '∞'; ?></b>
          &nbsp;·&nbsp;
          <?= $isBnSess ? 'মেয়াদ:' : 'Expiry:'; ?>
          <b><?= !empty($currAdmRecSess['license_expires_at']) ? htmlspecialchars($currAdmRecSess['license_expires_at'], ENT_QUOTES, 'UTF-8') . ($myDaysLeft !== null ? ' (' . $myDaysLeft . ($isBnSess ? ' দিন' : 'd') . ')' : '') : ($isBnSess ? 'আজীবন' : 'Lifetime'); ?></b>
        </div>
        <div>
          <a class="btn bg-primary" style="padding:3px 10px; font-size:12px;" href="./admin.php?id=admin-settings"><i class="fa fa-key"></i> <?= $isBnSess ? 'বিস্তারিত লাইসেন্স' : 'My License Details'; ?></a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php } ?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-server"></i> <?= $_router_list ?> <small id="router-count" style="font-size:12px;font-weight:normal;"></small> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="<?= ($langid === 'bn') ? 'রিলোড করুন' : 'Reload data'; ?>"></i>
          <input type="search" id="router-search" placeholder="<?= ($langid === 'bn') ? 'অনুসন্ধান করুন...' : 'Search'; ?>" aria-label="Search routers" style="display:inline-block;width:170px;max-width:45%;margin-left:10px;padding:4px 7px;font-size:13px;font-weight:normal;">
          <?php if ($isSuperStoreSess && count($allAdminsListSess) > 1) { ?>
          <select id="router-owner-filter" aria-label="Filter by Admin" style="display:inline-block;width:190px;max-width:45%;margin-left:8px;padding:4px 7px;font-size:13px;font-weight:normal;">
            <option value=""><?= $isBnSess ? 'সব অ্যাডমিনের রাউটার (All Store)' : 'All Admins (Main Store)'; ?></option>
            <?php foreach ($allAdminsListSess as $admOpt) { ?>
              <option value="<?= htmlspecialchars($admOpt['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <?= htmlspecialchars($admOpt['name'] . ' (@' . $admOpt['username'] . ')', ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php } ?>
          </select>
          <?php } ?>
        </h3>
      </div>
      <div class="card-body">
        <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status" style="margin-bottom:12px;">
            <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <?php unset($_SESSION['router_settings_notice']); ?>
        <?php } ?>
        <?php if (!empty($_SESSION['router_quota_error'])) { ?>
          <div class="alert alert-danger" role="alert" style="margin-bottom:12px;">
            <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['router_quota_error'], ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <?php unset($_SESSION['router_quota_error']); ?>
        <?php } ?>
        <div class="row">
          <?php
          $configLines = is_file('./include/config.php') ? @file('./include/config.php') : array();
          if (!is_array($configLines)) {
            $configLines = array();
          }
          foreach ($configLines as $line) {
            if (strpos($line, "'") === false) {
              continue;
            }
            $parts = explode("'", $line);
            if (!isset($parts[1])) {
              continue;
            }
            $value = $parts[1];
            if ($value === "" || $value === "mikhmon" || !canAccessRouterSession($value)) {
              continue;
            }
            $hsName = (isset($data[$value][4]) && strpos($data[$value][4], '%') !== false)
              ? explode('%', $data[$value][4])[1]
              : $value;
            $ownerInfo = function_exists('ngwGetRouterOwnerInfo') ? ngwGetRouterOwnerInfo($value) : array('owner_admin_id' => 'adm_1', 'owner_admin_name' => 'Main Admin Store', 'owner_admin_username' => 'admin', 'created_by_role' => 'admin', 'created_by_username' => 'admin');
          ?>
              <div class="col-6" data-router-card data-owner-admin="<?= htmlspecialchars($ownerInfo['owner_admin_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="box bmh-75 box-bordered <?= $color[rand(1, 11)]; ?>">
                  <div class="box-group">
                    <div class="box-group-icon">
                      <span class="connect pointer" id="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="fa fa-server"></i>
                      </span>
                    </div>
                    <div class="box-group-area">
                      <span>
                        <?= $_hotspot_name ?> : <?= htmlspecialchars($hsName, ENT_QUOTES, 'UTF-8'); ?><br>
                        <?= $_session_name ?> : <?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?><br>
                        <?php if ($isSuperStoreSess) { ?>
                        <small style="opacity:0.92;">
                          <i class="fa fa-user-secret"></i> <?= $isBnSess ? 'অ্যাডমিন:' : 'Admin:'; ?> <b><?= htmlspecialchars($ownerInfo['owner_admin_name'], ENT_QUOTES, 'UTF-8'); ?></b> (@<?= htmlspecialchars($ownerInfo['owner_admin_username'], ENT_QUOTES, 'UTF-8'); ?>)
                          <?php if (!empty($ownerInfo['created_by_role']) && $ownerInfo['created_by_role'] === 'reseller') { ?>
                            · <?= $isBnSess ? 'রিসেলার:' : 'Reseller:'; ?> @<?= htmlspecialchars($ownerInfo['created_by_username'], ENT_QUOTES, 'UTF-8'); ?>
                          <?php } ?>
                        </small><br>
                        <?php } ?>
                        <span class="connect pointer" id="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>&nbsp;
                        <?php if (hasResellerPermission('manage_router_settings')) { 
                          $delSessionMsg = ($langid === 'bn' ? 'আপনি কি নিশ্চিত যে এই রাউটার সেশন ডিলিট করতে চান: ' : 'Are you sure to delete data ') . $value . ' (' . $hsName . ')?';
                        ?>
                        <a href="./admin.php?id=settings&session=<?= urlencode($value); ?>"><i class="fa fa-edit"></i> <?= $_edit ?></a>&nbsp;
                        <a href="javascript:void(0)" onclick='if(confirm(<?= json_encode($delSessionMsg); ?>)){loadpage("./admin.php?id=remove-session&session=<?= urlencode($value); ?>")}else{}'><i class="fa fa-remove"></i> <?= $_delete ?></a>
                        <?php } ?>
                      </span>
                    </div>
                  </div>
                </div>
              </div>
          <?php
          }
          ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var search = document.getElementById('router-search');
  var ownerFilter = document.getElementById('router-owner-filter');
  var cards = document.querySelectorAll('[data-router-card]');
  function filterCards() {
    var query = search ? search.value.trim().toLowerCase() : '';
    var ownerId = ownerFilter ? ownerFilter.value : '';
    var visibleCount = 0;
    for (var i = 0; i < cards.length; i++) {
      var matchText = (query === '' || cards[i].textContent.toLowerCase().indexOf(query) !== -1);
      var matchOwner = (ownerId === '' || cards[i].getAttribute('data-owner-admin') === ownerId);
      if (matchText && matchOwner) {
        cards[i].style.display = '';
        visibleCount++;
      } else {
        cards[i].style.display = 'none';
      }
    }
    var cntEl = document.getElementById('router-count');
    if (cntEl) cntEl.textContent = '(' + visibleCount + ')';
  }
  filterCards();
  if (search) search.addEventListener('input', filterCards);
  if (ownerFilter) ownerFilter.addEventListener('change', filterCards);
})();
</script>
