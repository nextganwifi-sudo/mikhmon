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
  if (is_file('./include/router_model_helper.php')) {
    include_once('./include/router_model_helper.php');
  }
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

  $routerCards = array();
  $sessionGroups = array();
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
    $rawIpCheck = (isset($data[$value][1]) && strpos($data[$value][1], '!') !== false) ? trim((string)explode('!', $data[$value][1], 2)[1]) : '';
    $rawUsrCheck = (isset($data[$value][2]) && strpos($data[$value][2], '@|@') !== false) ? trim((string)explode('@|@', $data[$value][2], 2)[1]) : '';
    $hsName = (isset($data[$value][4]) && strpos($data[$value][4], '%') !== false)
      ? explode('%', $data[$value][4])[1]
      : $value;
    if (($value === 'new' || preg_match('/^new-\d+$/', $value)) && $rawIpCheck === '' && $rawUsrCheck === '' && trim((string)$hsName) === '') {
      continue;
    }
    if ($hsName === '') {
      $hsName = $value;
    }
    $dispSessionName = function_exists('getRouterDisplaySessionName') ? getRouterDisplaySessionName($value) : $value;
    $groupKey = function_exists('mb_strtolower') ? mb_strtolower($dispSessionName, 'UTF-8') : strtolower($dispSessionName);
    if (!isset($sessionGroups[$groupKey])) {
      $sessionGroups[$groupKey] = array('label' => $dispSessionName, 'count' => 0);
    }
    $sessionGroups[$groupKey]['count']++;

    $ownerBadgeText = '';
    if (isMikhmonAdminStore() && function_exists('getRouterOwnerInfo')) {
      $rOwner = getRouterOwnerInfo($value);
      if (is_array($rOwner) && isset($rOwner['added_by_role']) && $rOwner['added_by_role'] === 'admin') {
        $ownerDispName = !empty($rOwner['added_by_name'])
          ? (string)$rOwner['added_by_name']
          : (!empty($rOwner['added_by_username']) ? (string)$rOwner['added_by_username'] : 'Admin');
        $ownerBadgeText = $ownerDispName;
      }
    }

    $hwInfo = function_exists('ngwGetRouterHardwareInfo') ? ngwGetRouterHardwareInfo($value) : null;
    $hwImage = (is_array($hwInfo) && !empty($hwInfo['image'])) ? (string)$hwInfo['image'] : '';
    $hwLabel = (is_array($hwInfo) && !empty($hwInfo['label'])) ? (string)$hwInfo['label'] : '';

    $routerCards[] = array(
      'key' => $value,
      'hs_name' => $hsName,
      'display_session' => $dispSessionName,
      'group_key' => $groupKey,
      'owner_badge' => $ownerBadgeText,
      'hw_image' => $hwImage,
      'hw_label' => $hwLabel,
      'color' => $color[rand(1, 11)],
    );
  }
  uasort($sessionGroups, function ($a, $b) {
    if ($a['count'] !== $b['count']) {
      return ($b['count'] - $a['count']);
    }
    return strnatcasecmp($a['label'], $b['label']);
  });
  $totalRoutersCount = count($routerCards);
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
.ngw-rfilter-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: 6px;
  vertical-align: middle;
}
.ngw-rfilter-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.3;
  border-radius: 3px;
  border: 1px solid rgba(128, 128, 128, 0.42);
  background: rgba(128, 128, 128, 0.14);
  color: inherit;
  cursor: pointer;
  white-space: nowrap;
  max-width: 190px;
  transition: border-color .14s ease, background .14s ease;
}
.ngw-rfilter-btn:hover {
  border-color: #38bdf8;
  background: rgba(56, 189, 248, 0.14);
}
.ngw-rfilter-btn.is-active {
  border-color: #0284c7;
  background: rgba(2, 132, 199, 0.22);
}
.ngw-rfilter-btn-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 125px;
}
.ngw-rfilter-clear {
  display: none;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 24px;
  padding: 0;
  border-radius: 3px;
  border: 1px solid rgba(239, 68, 68, 0.45);
  background: rgba(239, 68, 68, 0.15);
  color: #ef4444;
  font-size: 13px;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
}
.ngw-rfilter-clear.is-visible {
  display: inline-flex;
}
.ngw-rfilter-clear:hover {
  background: #ef4444;
  color: #fff;
}
<?php
$ngwIsDarkTheme = (!isset($theme) || $theme === '' || $theme === 'dark');
$ngwMenuBg = $ngwIsDarkTheme ? '#222933' : '#ffffff';
$ngwMenuColor = $ngwIsDarkTheme ? '#f1f5f9' : '#1e293b';
$ngwMenuBorder = $ngwIsDarkTheme ? 'rgba(148, 163, 184, 0.32)' : 'rgba(100, 116, 139, 0.28)';
$ngwMenuShadow = $ngwIsDarkTheme ? '0 10px 28px rgba(0, 0, 0, 0.48)' : '0 10px 26px rgba(15, 23, 42, 0.16)';
$ngwSearchBg = $ngwIsDarkTheme ? 'rgba(0, 0, 0, 0.24)' : '#f8fafc';
$ngwSearchBorder = $ngwIsDarkTheme ? 'rgba(255, 255, 255, 0.22)' : '#cbd5e1';
$ngwCntBg = $ngwIsDarkTheme ? 'rgba(255, 255, 255, 0.14)' : 'rgba(15, 23, 42, 0.09)';
?>
.ngw-rfilter-menu {
  position: absolute;
  top: calc(100% + 5px);
  left: 0;
  min-width: 215px;
  max-width: 270px;
  max-height: 300px;
  overflow-y: auto;
  border-radius: 5px;
  border: 1px solid <?= $ngwMenuBorder; ?>;
  background: <?= $ngwMenuBg; ?>;
  color: <?= $ngwMenuColor; ?>;
  box-shadow: <?= $ngwMenuShadow; ?>;
  padding: 5px;
  display: none;
  z-index: 990;
  font-weight: normal;
}
.ngw-rfilter-menu.open {
  display: block;
}
.ngw-rfilter-search {
  width: 100%;
  box-sizing: border-box;
  margin-bottom: 5px;
  padding: 4px 7px;
  font-size: 12px;
  border-radius: 3px;
  border: 1px solid <?= $ngwSearchBorder; ?>;
  background: <?= $ngwSearchBg; ?>;
  color: <?= $ngwMenuColor; ?>;
}
.ngw-rfilter-item {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 8px;
  border: 0;
  border-radius: 3px;
  background: transparent;
  color: inherit;
  font-size: 12.5px;
  text-align: left;
  cursor: pointer;
}
.ngw-rfilter-item:hover {
  background: rgba(56, 189, 248, 0.18);
}
.ngw-rfilter-item.is-selected {
  background: #0284c7;
  color: #ffffff;
  font-weight: 600;
}
.ngw-rfilter-item.is-selected .ngw-rfilter-item-cnt {
  background: rgba(255, 255, 255, 0.24);
  color: #ffffff;
}
.ngw-rfilter-item-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ngw-rfilter-item-cnt {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 10px;
  background: <?= $ngwCntBg; ?>;
}
@media screen and (max-width: 575px) {
  .ngw-router-owner-corner {
    font-size: 9px;
    padding: 1px 5px;
    top: 4px;
    right: 5px;
    max-width: 52%;
  }
  .ngw-rfilter-wrap {
    margin-left: 4px;
    margin-top: 4px;
  }
  .ngw-rfilter-menu {
    left: auto;
    right: 0;
  }
}
.ngw-sessions-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px 12px;
}
.ngw-sessions-header .card-title {
  flex: 1 1 auto;
  min-width: 0;
}
.ngw-add-router-btn {
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 6px 13px !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  line-height: 1.3 !important;
  border-radius: 6px !important;
  white-space: nowrap;
  text-decoration: none !important;
  flex-shrink: 0;
}
.ngw-sessions-empty {
  width: 100%;
  text-align: center;
  padding: 28px 16px;
  border-radius: 8px;
  border: 1px dashed rgba(148, 163, 184, 0.35);
  margin: 4px 0 8px;
}
@media screen and (max-width: 575px) {
  .ngw-add-router-btn {
    padding: 5px 10px !important;
    font-size: 12px !important;
  }
}
</style>
<?php
$ngwIsGlassOs = (function_exists('ngwResolveActiveUiStyle') && ngwResolveActiveUiStyle() === 'aurora');
$adminDelCsrf = function_exists('ngwCsrfToken') ? ngwCsrfToken('ngw_admin_csrf') : '';
$canManageRtr = hasResellerPermission('manage_router_settings');
$isBnSessions = (isset($langid) && $langid === 'bn');
?>
<?php if ($ngwIsGlassOs) { ?>
<div class="ngw-gl-hub">
  <div class="ngw-gl-hero">
    <div class="ngw-gl-hero-left">
      <div class="ngw-gl-hero-icon" aria-hidden="true"><i class="fa fa-server"></i></div>
      <div>
        <h2 class="ngw-gl-hero-title"><?= $_router_list ?></h2>
        <div class="ngw-gl-hero-sub">
          <span><i class="fa fa-microchip" aria-hidden="true"></i> <strong id="router-count">(<?= (int)$totalRoutersCount; ?>)</strong> <?= $isBnSessions ? 'রাউটার' : 'Routers'; ?></span>
          <span>&middot;</span>
          <span><i class="fa fa-folder-open-o" aria-hidden="true"></i> <strong><?= count($sessionGroups); ?></strong> <?= $isBnSessions ? 'গ্রুপ' : 'Groups'; ?></span>
        </div>
      </div>
    </div>
    <div class="ngw-gl-hero-actions">
      <button type="button" class="ngw-glass-btn" onclick="location.reload();" title="<?= $isBnSessions ? 'রিলোড করুন' : 'Reload data'; ?>">
        <i class="fa fa-refresh" aria-hidden="true"></i>
        <span><?= $isBnSessions ? 'রিলোড' : 'Refresh'; ?></span>
      </button>
      <?php if ($canManageRtr) { ?>
      <a href="./admin.php?id=settings&session=new" class="ngw-glass-btn is-primary">
        <i class="fa fa-plus" aria-hidden="true"></i>
        <span><?= $_add_router ?></span>
      </a>
      <?php } ?>
    </div>
  </div>

  <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
    <div class="alert alert-success" role="status">
      <i class="fa fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php unset($_SESSION['router_settings_notice']); ?>
  <?php } ?>

  <div class="ngw-gl-toolbar">
    <div class="ngw-gl-toolbar-top">
      <div class="ngw-gl-search-wrap">
        <i class="fa fa-search" aria-hidden="true"></i>
        <input type="search" id="router-search" placeholder="<?= $isBnSessions ? 'হটস্পট বা সেশন নাম দিয়ে রাউটার খুঁজুন...' : 'Search routers by hotspot or session group...'; ?>" aria-label="Search routers">
      </div>
      <?php if (!empty($sessionGroups)) { ?>
      <span class="ngw-rfilter-wrap" id="ngwRouterFilterWrap" style="margin-left:0;">
        <button type="button" class="ngw-rfilter-btn" id="ngwRouterFilterBtn" onclick="ngwToggleRouterFilterMenu(event)" title="<?= $isBnSessions ? 'সেশন গ্রুপ সিলেক্ট করুন' : 'Select Session Group'; ?>">
          <i class="fa fa-filter"></i>
          <span class="ngw-rfilter-btn-text" id="ngwRouterFilterLabel"><?= $isBnSessions ? 'সব গ্রুপ' : 'All Groups'; ?></span>
          <i class="fa fa-caret-down"></i>
        </button>
        <button type="button" class="ngw-rfilter-clear" id="ngwRouterFilterClear" onclick="ngwSelectRouterGroup('', '')" aria-label="Reset filter">&times;</button>
        <div class="ngw-rfilter-menu" id="ngwRouterFilterMenu" onclick="event.stopPropagation();">
          <?php if (count($sessionGroups) > 6) { ?>
          <input type="search" id="ngwRouterFilterSearch" class="ngw-rfilter-search" placeholder="<?= $isBnSessions ? 'গ্রুপ খুঁজুন...' : 'Search group...'; ?>" oninput="ngwFilterRouterGroupMenu()">
          <?php } ?>
          <button type="button" class="ngw-rfilter-item is-selected" data-group-option="" data-group-label="" onclick="ngwSelectRouterGroup('', '')">
            <span class="ngw-rfilter-item-name"><?= $isBnSessions ? 'সব রাউটার' : 'All Routers'; ?></span>
            <span class="ngw-rfilter-item-cnt"><?= (int)$totalRoutersCount; ?></span>
          </button>
          <?php foreach ($sessionGroups as $gKey => $gInfo) { ?>
          <button type="button" class="ngw-rfilter-item" data-group-option="<?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8'); ?>" data-group-label="<?= htmlspecialchars($gInfo['label'], ENT_QUOTES, 'UTF-8'); ?>" onclick='ngwSelectRouterGroup(<?= json_encode((string)$gKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?= json_encode((string)$gInfo['label'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
            <span class="ngw-rfilter-item-name"><?= htmlspecialchars($gInfo['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="ngw-rfilter-item-cnt"><?= (int)$gInfo['count']; ?></span>
          </button>
          <?php } ?>
        </div>
      </span>
      <?php } ?>
    </div>
  </div>

  <?php if (empty($routerCards)) { ?>
    <div class="ngw-gl-empty">
      <div class="ngw-gl-empty-ico"><i class="fa fa-server"></i></div>
      <h3 style="margin:0 0 6px;font-size:17px;"><?= $isBnSessions ? 'এখনও কোনো রাউটার যুক্ত করা হয়নি' : 'No routers configured yet'; ?></h3>
      <p style="margin:0 0 16px;font-size:13px;opacity:0.75;"><?= $isBnSessions ? 'শুরু করতে নিচের বাটনে ক্লিক করে আপনার প্রথম MikroTik রাউটার যুক্ত করুন।' : 'Add your first MikroTik router to start managing hotspot users and vouchers.'; ?></p>
      <?php if ($canManageRtr) { ?>
      <a href="./admin.php?id=settings&session=new" class="ngw-glass-btn is-primary">
        <i class="fa fa-plus"></i> <?= $_add_router ?>
      </a>
      <?php } ?>
    </div>
  <?php } else { ?>
    <div class="ngw-gl-router-grid">
      <?php foreach ($routerCards as $card) {
        $value = $card['key'];
        $hsName = $card['hs_name'];
        $dispSessionName = $card['display_session'];
        $groupKey = $card['group_key'];
        $ownerBadgeText = $card['owner_badge'];
        $hwImage = $card['hw_image'];
        $hwLabel = $card['hw_label'];
        $delSessionMsg = ($isBnSessions ? 'আপনি কি নিশ্চিত যে এই রাউটার সেশন ডিলিট করতে চান: ' : 'Are you sure to delete data ') . $dispSessionName . ' (' . $hsName . ')?';
      ?>
      <article class="ngw-gl-router-card" data-router-card data-session-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="ngw-gl-rcard-top">
          <div class="ngw-gl-rcard-ico<?= ($hwImage !== '') ? ' has-hw-img' : ''; ?>"<?php if ($hwLabel !== '') { ?> title="<?= htmlspecialchars($hwLabel, ENT_QUOTES, 'UTF-8'); ?>"<?php } ?>>
            <?php if ($hwImage !== '') { ?>
              <img class="ngw-rtr-hw-img" src="<?= htmlspecialchars($hwImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($hwLabel, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
            <?php } else { ?>
              <i class="fa fa-server" aria-hidden="true"></i>
            <?php } ?>
          </div>
          <div class="ngw-gl-rcard-meta">
            <div class="ngw-gl-rcard-name" title="<?= htmlspecialchars($hsName, ENT_QUOTES, 'UTF-8'); ?>">
              <?= htmlspecialchars($hsName, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="ngw-gl-rcard-sub">
              <span class="ngw-gl-chip is-accent">
                <i class="fa fa-tag" aria-hidden="true"></i>
                <?= htmlspecialchars($dispSessionName, ENT_QUOTES, 'UTF-8'); ?>
              </span>
              <?php if ($ownerBadgeText !== '') { ?>
              <span class="ngw-gl-chip" title="<?= htmlspecialchars($ownerBadgeText, ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fa fa-user-circle-o" aria-hidden="true"></i>
                <?= htmlspecialchars($ownerBadgeText, ENT_QUOTES, 'UTF-8'); ?>
              </span>
              <?php } ?>
            </div>
          </div>
        </div>

        <div class="ngw-gl-rcard-actions">
          <button type="button" class="ngw-glass-btn is-primary ngw-gl-open-btn connect" id="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa fa-external-link" aria-hidden="true"></i>
            <span><?= $_open ?></span>
          </button>
          <?php if ($canManageRtr) { ?>
          <a href="./admin.php?id=settings&session=<?= rawurlencode($value); ?>" class="ngw-glass-btn ngw-gl-icon-btn" title="<?= htmlspecialchars($_edit, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa fa-sliders" aria-hidden="true"></i>
            <span><?= $_edit ?></span>
          </a>
          <button type="button" class="ngw-glass-btn is-danger ngw-gl-icon-btn" title="<?= htmlspecialchars($_delete, ENT_QUOTES, 'UTF-8'); ?>" onclick='if(confirm(<?= json_encode($delSessionMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)){window.location.href="./admin.php?id=remove-session&session=<?= rawurlencode($value); ?>&csrf=<?= rawurlencode($adminDelCsrf); ?>";}'>
            <i class="fa fa-trash" aria-hidden="true"></i>
          </button>
          <?php } ?>
        </div>
      </article>
      <?php } ?>
    </div>

    <div id="ngwGlRouterEmpty" class="ngw-gl-empty" style="display:none;">
      <div class="ngw-gl-empty-ico"><i class="fa fa-search"></i></div>
      <h3 style="margin:0 0 6px;font-size:16px;"><?= $isBnSessions ? 'আপনার খোঁজের সাথে কোনো রাউটার মিলেনি' : 'No matching routers found'; ?></h3>
      <p style="margin:0 0 14px;font-size:12.5px;opacity:0.75;"><?= $isBnSessions ? 'অন্য নাম দিয়ে খুঁজুন অথবা ফিল্টার রিসেট করুন।' : 'Try another search keyword or reset the selected group filter.'; ?></p>
      <button type="button" class="ngw-glass-btn is-primary" onclick="if(document.getElementById('router-search'))document.getElementById('router-search').value=''; ngwSelectRouterGroup('', '');">
        <i class="fa fa-undo"></i> <?= $isBnSessions ? 'ফিল্টার রিসেট করুন' : 'Reset Filters'; ?>
      </button>
    </div>
  <?php } ?>
</div>
<?php } else { ?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header ngw-sessions-header">
        <h3 class="card-title" style="display:flex; flex-wrap:wrap; align-items:center; gap:4px;"><i class="fa fa-server"></i> <?= $_router_list ?> <small id="router-count" style="font-size:12px;font-weight:normal;">(<?= (int)$totalRoutersCount; ?>)</small> &nbsp;|&nbsp; <i onclick="location.reload();" class="fa fa-refresh pointer" title="<?= ($langid === 'bn') ? 'রিলোড করুন' : 'Reload data'; ?>"></i>
          <input type="search" id="router-search" placeholder="<?= ($langid === 'bn') ? 'অনুসন্ধান করুন...' : 'Search'; ?>" aria-label="Search routers" style="display:inline-block;width:165px;max-width:42%;margin-left:6px;padding:4px 7px;font-size:13px;font-weight:normal;">
          <?php if (!empty($sessionGroups)) { ?>
          <span class="ngw-rfilter-wrap" id="ngwRouterFilterWrap">
            <button type="button" class="ngw-rfilter-btn" id="ngwRouterFilterBtn" onclick="ngwToggleRouterFilterMenu(event)" title="<?= ($langid === 'bn') ? 'সেশন নাম দিয়ে ফিল্টার করুন' : 'Filter by Session Name'; ?>">
              <i class="fa fa-filter"></i>
              <span class="ngw-rfilter-btn-text" id="ngwRouterFilterLabel"><?= ($langid === 'bn') ? 'ফিল্টার' : 'Filter'; ?></span>
              <i class="fa fa-caret-down"></i>
            </button>
            <button type="button" class="ngw-rfilter-clear" id="ngwRouterFilterClear" onclick="ngwSelectRouterGroup('', '')" title="<?= ($langid === 'bn') ? 'ফিল্টার রিসেট করুন' : 'Reset filter'; ?>" aria-label="Reset filter">&times;</button>
            <div class="ngw-rfilter-menu" id="ngwRouterFilterMenu" onclick="event.stopPropagation();">
              <?php if (count($sessionGroups) > 6) { ?>
              <input type="search" id="ngwRouterFilterSearch" class="ngw-rfilter-search" placeholder="<?= ($langid === 'bn') ? 'গ্রুপ খুঁজুন...' : 'Search group...'; ?>" oninput="ngwFilterRouterGroupMenu()">
              <?php } ?>
              <button type="button" class="ngw-rfilter-item is-selected" data-group-option="" data-group-label="" onclick="ngwSelectRouterGroup('', '')">
                <span class="ngw-rfilter-item-name"><?= ($langid === 'bn') ? 'সব রাউটার' : 'All Routers'; ?></span>
                <span class="ngw-rfilter-item-cnt"><?= (int)$totalRoutersCount; ?></span>
              </button>
              <?php foreach ($sessionGroups as $gKey => $gInfo) { ?>
              <button type="button" class="ngw-rfilter-item" data-group-option="<?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8'); ?>" data-group-label="<?= htmlspecialchars($gInfo['label'], ENT_QUOTES, 'UTF-8'); ?>" onclick='ngwSelectRouterGroup(<?= json_encode((string)$gKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?= json_encode((string)$gInfo['label'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                <span class="ngw-rfilter-item-name"><?= htmlspecialchars($gInfo['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="ngw-rfilter-item-cnt"><?= (int)$gInfo['count']; ?></span>
              </button>
              <?php } ?>
            </div>
          </span>
          <?php } ?>
        </h3>
        <?php if ($canManageRtr) { ?>
        <a href="./admin.php?id=settings&session=new" class="btn bg-primary ngw-add-router-btn">
          <i class="fa fa-plus" aria-hidden="true"></i>
          <span><?= $_add_router ?></span>
        </a>
        <?php } ?>
      </div>
      <div class="card-body">
        <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status" style="margin-bottom:12px;">
            <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <?php unset($_SESSION['router_settings_notice']); ?>
        <?php } ?>
        <?php if (empty($routerCards)) { ?>
          <div class="ngw-sessions-empty">
            <div style="font-size:28px;margin-bottom:8px;opacity:0.75;"><i class="fa fa-server"></i></div>
            <h4 style="margin:0 0 6px;"><?= $isBnSessions ? 'এখনও কোনো রাউটার যুক্ত করা হয়নি' : 'No routers configured yet'; ?></h4>
            <p style="margin:0 0 14px;opacity:0.78;font-size:13px;"><?= $isBnSessions ? 'শুরু করতে নিচের বাটনে ক্লিক করে আপনার প্রথম রাউটার যুক্ত করুন।' : 'Click below to add your first MikroTik router.'; ?></p>
            <?php if ($canManageRtr) { ?>
            <a href="./admin.php?id=settings&session=new" class="btn bg-primary ngw-add-router-btn">
              <i class="fa fa-plus" aria-hidden="true"></i>
              <span><?= $_add_router ?></span>
            </a>
            <?php } ?>
          </div>
        <?php } ?>
        <div class="row">
          <?php
          $ownerBadgeIcon = 'fa-user-circle-o';
          foreach ($routerCards as $card) {
            $value = $card['key'];
            $hsName = $card['hs_name'];
            $dispSessionName = $card['display_session'];
            $groupKey = $card['group_key'];
            $ownerBadgeText = $card['owner_badge'];
            $hwImage = $card['hw_image'];
            $hwLabel = $card['hw_label'];
          ?>
              <div class="col-6" data-router-card data-session-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="box bmh-75 box-bordered <?= $card['color']; ?>" style="position:relative;">
                  <?php if ($ownerBadgeText !== '') { ?>
                    <span class="ngw-router-owner-corner" title="<?= htmlspecialchars($ownerBadgeText, ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="fa <?= $ownerBadgeIcon; ?>"></i>
                      <span><?= htmlspecialchars($ownerBadgeText, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                  <?php } ?>
                  <div class="box-group">
                    <div class="box-group-icon">
                      <?php if ($hwImage !== '') { ?>
                        <span class="ngw-rtr-hw-slot" title="<?= htmlspecialchars($hwLabel, ENT_QUOTES, 'UTF-8'); ?>">
                          <img class="ngw-rtr-hw-img" src="<?= htmlspecialchars($hwImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($hwLabel, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                        </span>
                      <?php } else { ?>
                        <span class="ngw-rtr-hw-slot">
                          <i class="fa fa-server"></i>
                        </span>
                      <?php } ?>
                    </div>
                    <div class="box-group-area">
                      <span>
                        <?= $_hotspot_name ?> : <?= htmlspecialchars($hsName, ENT_QUOTES, 'UTF-8'); ?><br>
                        <?= $_session_name ?> : <?= htmlspecialchars($dispSessionName, ENT_QUOTES, 'UTF-8'); ?><br>
                        <span class="connect pointer" id="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>&nbsp;
                        <?php if ($canManageRtr) {
                          $delSessionMsg = ($langid === 'bn' ? 'আপনি কি নিশ্চিত যে এই রাউটার সেশন ডিলিট করতে চান: ' : 'Are you sure to delete data ') . $dispSessionName . ' (' . $hsName . ')?';
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
<?php } ?>

<script>
(function () {
  var search = document.getElementById('router-search');
  var cards = document.querySelectorAll('[data-router-card]');
  var countEl = document.getElementById('router-count');
  var emptyGl = document.getElementById('ngwGlRouterEmpty');
  var defaultFilterText = <?= json_encode($ngwIsGlassOs ? ($isBnSessions ? 'সব গ্রুপ' : 'All Groups') : (($langid === 'bn') ? 'ফিল্টার' : 'Filter'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var activeGroup = '';
  var activeGroupLabel = '';
  var totalCount = cards.length;

  function applyRouterFilters() {
    var query = search ? search.value.trim().toLowerCase() : '';
    var shown = 0;
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      var cGroup = card.getAttribute('data-session-group') || '';
      var groupOk = (activeGroup === '') || (cGroup === activeGroup);
      var textOk = (query === '') || (card.textContent.toLowerCase().indexOf(query) !== -1);
      var visible = groupOk && textOk;
      card.style.display = visible ? '' : 'none';
      if (visible) shown++;
    }
    if (countEl) {
      countEl.textContent = (activeGroup !== '' || query !== '') ? ('(' + shown + '/' + totalCount + ')') : ('(' + totalCount + ')');
    }
    if (emptyGl) {
      emptyGl.style.display = (totalCount > 0 && shown === 0) ? 'block' : 'none';
    }
  }

  window.ngwToggleRouterFilterMenu = function (ev) {
    if (ev) ev.stopPropagation();
    var menu = document.getElementById('ngwRouterFilterMenu');
    if (!menu) return;
    menu.classList.toggle('open');
    if (menu.classList.contains('open')) {
      var gs = document.getElementById('ngwRouterFilterSearch');
      if (gs) {
        gs.value = '';
        ngwFilterRouterGroupMenu();
        setTimeout(function () { gs.focus(); }, 10);
      }
    }
  };

  window.ngwFilterRouterGroupMenu = function () {
    var gs = document.getElementById('ngwRouterFilterSearch');
    var q = gs ? gs.value.trim().toLowerCase() : '';
    var items = document.querySelectorAll('#ngwRouterFilterMenu .ngw-rfilter-item');
    for (var i = 0; i < items.length; i++) {
      var optVal = items[i].getAttribute('data-group-option') || '';
      if (optVal === '') {
        items[i].style.display = (q === '') ? '' : 'none';
        continue;
      }
      var lbl = (items[i].getAttribute('data-group-label') || '').toLowerCase();
      items[i].style.display = (q === '' || lbl.indexOf(q) !== -1) ? '' : 'none';
    }
  };

  window.ngwSelectRouterGroup = function (groupKey, groupLabel) {
    activeGroup = groupKey || '';
    activeGroupLabel = groupLabel || '';
    var btn = document.getElementById('ngwRouterFilterBtn');
    var lblEl = document.getElementById('ngwRouterFilterLabel');
    var clearBtn = document.getElementById('ngwRouterFilterClear');
    var menu = document.getElementById('ngwRouterFilterMenu');

    if (btn) btn.classList.toggle('is-active', activeGroup !== '');
    if (lblEl) lblEl.textContent = activeGroup !== '' ? activeGroupLabel : defaultFilterText;
    if (clearBtn) clearBtn.classList.toggle('is-visible', activeGroup !== '');
    if (menu) menu.classList.remove('open');

    var items = document.querySelectorAll('#ngwRouterFilterMenu .ngw-rfilter-item');
    for (var i = 0; i < items.length; i++) {
      var optVal = items[i].getAttribute('data-group-option') || '';
      items[i].classList.toggle('is-selected', optVal === activeGroup);
    }
    var glBtns = document.querySelectorAll('[data-gl-group]');
    for (var j = 0; j < glBtns.length; j++) {
      var gOpt = glBtns[j].getAttribute('data-gl-group') || '';
      glBtns[j].classList.toggle('is-active', gOpt === activeGroup);
    }
    applyRouterFilters();
  };

  document.addEventListener('click', function (ev) {
    var wrap = document.getElementById('ngwRouterFilterWrap');
    var menu = document.getElementById('ngwRouterFilterMenu');
    if (menu && wrap && !wrap.contains(ev.target)) {
      menu.classList.remove('open');
    }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      var menu = document.getElementById('ngwRouterFilterMenu');
      if (menu) menu.classList.remove('open');
    }
  });

  if (search) {
    search.addEventListener('input', applyRouterFilters);
  }
  applyRouterFilters();
})();
</script>
