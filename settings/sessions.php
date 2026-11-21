<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/reseller_acl.php');
  if (is_file('./include/data_recovery.php')) {
    include_once('./include/data_recovery.php');
    if (function_exists('ngwInvalidatePhpFileCache')) {
      ngwInvalidatePhpFileCache('./include/config.php');
    }
  }
  if (is_file('./include/config.php')) {
    include('./include/config.php');
  }

  // array color
  $color = array('1' => 'bg-blue', 'bg-indigo', 'bg-purple', 'bg-pink', 'bg-red', 'bg-yellow', 'bg-green', 'bg-teal', 'bg-cyan', 'bg-grey', 'bg-light-blue');
}
?>

<style>
.ngw-router-owner-corner {
  position: absolute;
  top: 5px;
  right: 7px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 6px;
  border-radius: 3px;
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid rgba(255, 255, 255, 0.18);
  color: #fff;
  font-size: 10px;
  font-weight: 600;
  line-height: 1.3;
  max-width: 56%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  z-index: 2;
}
@media screen and (max-width: 575px) {
  .ngw-router-owner-corner {
    font-size: 9px;
    padding: 1px 5px;
    top: 4px;
    right: 5px;
    max-width: 52%;
  }
}
</style>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-server"></i> <?= $_router_list ?> <small id="router-count" style="font-size:12px;font-weight:normal;"></small> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="<?= ($langid === 'bn') ? 'রিলোড করুন' : 'Reload data'; ?>"></i>
          <input type="search" id="router-search" placeholder="<?= ($langid === 'bn') ? 'অনুসন্ধান করুন...' : 'Search'; ?>" aria-label="Search routers" style="display:inline-block;width:170px;max-width:45%;margin-left:10px;padding:4px 7px;font-size:13px;font-weight:normal;">
        </h3>
      </div>
      <div class="card-body">
        <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status" style="margin-bottom:12px;">
            <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <?php unset($_SESSION['router_settings_notice']); ?>
        <?php } ?>
        <div class="row">
          <?php
          $configLines = is_file('./include/config.php') ? @file('./include/config.php') : array();
          if (!is_array($configLines)) {
            $configLines = array();
          }
          foreach ($configLines as $line) {
            $trimmedLine = trim((string)$line);
            if ($trimmedLine === '' || strpos($trimmedLine, '$data[') !== 0 || strpos($trimmedLine, "'") === false) {
              continue;
            }
            $parts = explode("'", $trimmedLine);
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
            if ($hsName === '') {
              $hsName = $value;
            }
            $ownerBadgeText = '';
            $ownerBadgeIcon = 'fa-user-circle-o';
            if (isMikhmonAdminStore() && function_exists('getRouterOwnerInfo')) {
              $rOwner = getRouterOwnerInfo($value);
              if (is_array($rOwner) && isset($rOwner['added_by_role']) && $rOwner['added_by_role'] === 'admin') {
                $ownerDispName = !empty($rOwner['added_by_name'])
                  ? (string)$rOwner['added_by_name']
                  : (!empty($rOwner['added_by_username']) ? (string)$rOwner['added_by_username'] : 'Admin');
                $ownerBadgeText = $ownerDispName;
              }
            }
          ?>
              <div class="col-6" data-router-card>
                <div class="box bmh-75 box-bordered <?= $color[rand(1, 11)]; ?>" style="position:relative;">
                  <?php if ($ownerBadgeText !== '') { ?>
                    <span class="ngw-router-owner-corner" title="<?= htmlspecialchars($ownerBadgeText, ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="fa <?= $ownerBadgeIcon; ?>"></i>
                      <span><?= htmlspecialchars($ownerBadgeText, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                  <?php } ?>
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
                        <span class="connect pointer" id="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>&nbsp;
                        <?php if (hasResellerPermission('manage_router_settings')) { 
                          $delSessionMsg = ($langid === 'bn' ? 'আপনি কি নিশ্চিত যে এই রাউটার সেশন ডিলিট করতে চান: ' : 'Are you sure to delete data ') . $value . ' (' . $hsName . ')?';
                          $adminDelCsrf = function_exists('ngwCsrfToken') ? ngwCsrfToken('ngw_admin_csrf') : '';
                        ?>
                        <a href="./admin.php?id=settings&session=<?= rawurlencode($value); ?>"><i class="fa fa-edit"></i> <?= $_edit ?></a>&nbsp;
                        <a href="javascript:void(0)" onclick='if(confirm(<?= json_encode($delSessionMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)){window.location.href="./admin.php?id=remove-session&session=<?= rawurlencode($value); ?>&csrf=<?= rawurlencode($adminDelCsrf); ?>";}else{}'><i class="fa fa-remove"></i> <?= $_delete ?></a>
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
  var cards = document.querySelectorAll('[data-router-card]');
  document.getElementById('router-count').textContent = '(' + cards.length + ')';
  search.addEventListener('input', function () {
    var query = search.value.trim().toLowerCase();
    for (var i = 0; i < cards.length; i++) {
      cards[i].style.display = cards[i].textContent.toLowerCase().indexOf(query) !== -1 ? '' : 'none';
    }
  });
})();
</script>
