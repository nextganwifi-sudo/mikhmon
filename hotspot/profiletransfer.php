<?php
/*
 *  NextGan WiFi Solution — Same-Router Hotspot Profile Transfer
 *  Compact, colorful 2-column theme-matched UI (matching User Transfer)
 *  1. Entire Source Profile -> Destination Profile
 *  2. Specific Batch Comment -> Destination Profile
 *  3. Selected Individual Users -> Destination Profile
 */
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
error_reporting(0);
ini_set('max_execution_time', 300);

chdir(dirname(__DIR__));
require_once './include/reseller_acl.php';
require_once './include/user_transfer.php';
require_once './include/user_transfer_ui.php';
require_once './include/ros_version.php';
include './include/theme.php';

$transferTheme = $_SESSION['theme'] ?? $theme;
if (!in_array($transferTheme, array('dark', 'light', 'blue', 'green', 'pink'), true)) {
  $transferTheme = 'dark';
}

$session = is_string($_GET['session'] ?? null) ? trim($_GET['session']) : '';
if (!isset($_SESSION['mikhmon']) || $session === '' || !canAccessRouterSession($session)) {
  http_response_code(403);
  exit(transferText('Access denied'));
}

if (!hasResellerPermission('same_router_profile_transfer') && !hasResellerPermission('transfer_users_access') && !hasResellerPermission('edit_user') && !hasResellerPermission('generate_voucher') && !hasResellerPermission('add_user') && !hasResellerPermission('export_users')) {
  http_response_code(403);
  exit(transferText('Access denied'));
}

if (empty($_SESSION['transfer_csrf'])) {
  $_SESSION['transfer_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['transfer_csrf'];

$isBn = ($transferLanguage === 'bn');

require './include/config.php';
require_once './lib/routeros_api.class.php';

$_GET['session'] = $session;
$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 8;
$API->attempts = 1;

$isRouterConnected = false;
if (isset($data[$session])) {
  $iphost = explode('!', $data[$session][1], 2)[1] ?? '';
  $userhost = explode('@|@', $data[$session][2], 2)[1] ?? '';
  $passwdhost = explode('#|#', $data[$session][3], 2)[1] ?? '';
  $isRouterConnected = $API->connect($iphost, $userhost, decrypt($passwdhost));
}

// Fast JSON reply helper for chunked AJAX profile transfer (prevents proxy/backend timeouts on 200+ users)
$ptReplyJson = function ($payload, $statusCode = 200) {
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  session_write_close();
  echo json_encode($payload);
  exit;
};

// Handle step_pt BEFORE loading full router user list so each chunk step is ultra-fast (<3.2s)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'step_pt') {
  $_SESSION['mikhmon_last_activity'] = time();
  if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
    $ptReplyJson(array('error' => $isBn ? 'পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Please reload this page and try again.'), 400);
  }
  $jobId = isset($_POST['job']) ? trim((string)$_POST['job']) : '';
  if ($jobId === '' || !isset($_SESSION['profile_transfer'][$jobId]) || ($_SESSION['profile_transfer'][$jobId]['router'] ?? '') !== $session) {
    $ptReplyJson(array('error' => $isBn ? 'ট্রান্সফার জব পাওয়া যায়নি। আবার শুরু করুন।' : 'Transfer job not found. Please start again.'), 400);
  }
  if (!$isRouterConnected) {
    $ptReplyJson(array('error' => $isBn ? 'রাউটার সংযোগ বিচ্ছিন্ন হয়েছে। রিজিউম বাটনে ক্লিক করুন।' : 'Router connection interrupted. Click Resume.'), 400);
  }

  $job = &$_SESSION['profile_transfer'][$jobId];
  $total = count($job['uids']);
  $destProfile = (string)$job['dest_profile'];
  $clearMac = !empty($job['clear_mac']);
  $startedAt = microtime(true);

  for ($i = 0; $i < 15 && $job['done'] < $total; $i++) {
    if ($i > 0 && (microtime(true) - $startedAt) >= 3.2) {
      break;
    }
    $uid = $job['uids'][$job['done']];
    if ($uid !== '') {
      $setParams = array(
        '.id' => $uid,
        'profile' => $destProfile
      );
      if ($clearMac) {
        $setParams['mac-address'] = '00:00:00:00:00:00';
      }
      $API->comm("/ip/hotspot/user/set", $setParams);
      $job['moved']++;
    }
    $job['done']++;
  }

  $isFinished = ($job['done'] >= $total);
  if ($isFinished && empty($job['logged'])) {
    $job['logged'] = true;
    if (function_exists('ngwRecordActivity')) {
      ngwRecordActivity('hotspot_profile_transfer', array(
        'router' => $session,
        'mode' => $job['mode'] ?? 'profile',
        'dest_profile' => $destProfile,
        'count' => $job['moved']
      ));
    }
  }

  $ptReplyJson(array(
    'ok' => true,
    'done' => (int)$job['done'],
    'total' => (int)$total,
    'moved' => (int)$job['moved'],
    'dest_profile' => $destProfile,
    'finished' => $isFinished
  ));
}

// Helper to load current profiles, users, and counts from router
$loadRouterHotspotData = function () use ($API, $isRouterConnected) {
  $rawProfiles = $isRouterConnected ? $API->comm("/ip/hotspot/user/profile/print") : array();
  $rawUsers = $isRouterConnected ? $API->comm("/ip/hotspot/user/print") : array();

  $profiles = array();
  if (is_array($rawProfiles)) {
    foreach ($rawProfiles as $p) {
      if (isset($p['name']) && $p['name'] !== '') {
        $profiles[] = (string)$p['name'];
      }
    }
  }

  $users = array();
  $profileCounts = array();
  $batchCounts = array();
  $batchProfiles = array();

  if (is_array($rawUsers)) {
    foreach ($rawUsers as $u) {
      if (!is_array($u) || !isset($u['name'])) continue;
      $uName = (string)$u['name'];
      if ($uName === 'default-trial' || (isset($u['dynamic']) && $u['dynamic'] === 'true')) continue;

      $uProf = isset($u['profile']) && $u['profile'] !== '' ? (string)$u['profile'] : 'default';
      $uComm = isset($u['comment']) ? trim((string)$u['comment']) : '';

      if (!isset($profileCounts[$uProf])) $profileCounts[$uProf] = 0;
      $profileCounts[$uProf]++;

      $batchKey = '';
      if (preg_match('/^(?:vc|up)-[^\s]+/i', $uComm)) {
        $batchKey = $uComm;
      } elseif (preg_match('/((?:vc|up)-.*)$/i', $uComm, $bm)) {
        $batchKey = trim($bm[1]);
      } elseif ($uComm !== '' && (!function_exists('isUsedActivatedVoucherComment') || !isUsedActivatedVoucherComment($uComm))) {
        $batchKey = $uComm;
      }

      if ($batchKey !== '') {
        if (!isset($batchCounts[$batchKey])) {
          $batchCounts[$batchKey] = 0;
          $batchProfiles[$batchKey] = $uProf;
        }
        $batchCounts[$batchKey]++;
      }

      $u['_batch_key'] = $batchKey;
      $users[] = $u;
    }
  }

  return array($profiles, $users, $profileCounts, $batchCounts, $batchProfiles);
};

list($profiles, $users, $profileCounts, $batchCounts, $batchProfiles) = $loadRouterHotspotData();

$transferSuccess = '';
$transferError = '';
$movedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['do_profile_transfer']) || (isset($_POST['action']) && $_POST['action'] === 'prepare_pt'))) {
  $isAjaxPrepare = (isset($_POST['action']) && $_POST['action'] === 'prepare_pt');
  $_SESSION['mikhmon_last_activity'] = time();

  if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
    $transferError = $isBn ? 'পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Please reload this page and try again.';
  } elseif (!$isRouterConnected) {
    $transferError = $isBn ? 'রাউটার সংযোগ ব্যর্থ হয়েছে।' : 'Router connection failed.';
  } else {
    $transferMode = isset($_POST['transfer_mode']) ? trim((string)$_POST['transfer_mode']) : 'profile';
    $destProfile = isset($_POST['dest_profile']) ? trim((string)$_POST['dest_profile']) : '';
    $clearMac = !empty($_POST['clear_mac']);

    if ($destProfile === '' || !in_array($destProfile, $profiles, true)) {
      $transferError = $isBn ? 'অনুগ্রহ করে একটি সঠিক গন্তব্য প্রোফাইল নির্বাচন করুন।' : 'Please select a valid destination profile.';
    } else {
      $targetUsers = array();

      if ($transferMode === 'profile') {
        $srcProfile = isset($_POST['source_profile']) ? trim((string)$_POST['source_profile']) : '';
        if ($srcProfile === '') {
          $transferError = $isBn ? 'অনুগ্রহ করে সোর্স প্রোফাইল নির্বাচন করুন।' : 'Please select a source profile.';
        } elseif ($srcProfile === $destProfile) {
          $transferError = $isBn ? 'সোর্স প্রোফাইল এবং গন্তব্য প্রোফাইল একই হতে পারে না।' : 'Source profile and destination profile cannot be the same.';
        } else {
          foreach ($users as $u) {
            $up = isset($u['profile']) ? (string)$u['profile'] : 'default';
            if ($up === $srcProfile) {
              $targetUsers[] = $u;
            }
          }
        }
      } elseif ($transferMode === 'batch') {
        $srcBatch = isset($_POST['source_batch']) ? trim((string)$_POST['source_batch']) : '';
        if ($srcBatch === '') {
          $transferError = $isBn ? 'অনুগ্রহ করে একটি ব্যাচ কমেন্ট নির্বাচন করুন।' : 'Please select a batch comment.';
        } else {
          foreach ($users as $u) {
            $uComm = isset($u['comment']) ? trim((string)$u['comment']) : '';
            if ($uComm === $srcBatch || $u['_batch_key'] === $srcBatch) {
              $targetUsers[] = $u;
            }
          }
        }
      } elseif ($transferMode === 'selected') {
        $selectedIds = isset($_POST['selected_uids']) && is_array($_POST['selected_uids']) ? $_POST['selected_uids'] : array();
        if (empty($selectedIds)) {
          $transferError = $isBn ? 'ট্রান্সফার করার জন্য অন্তত একজন ইউজার সিলেক্ট করুন।' : 'Please select at least one user to transfer.';
        } else {
          $idMap = array_flip($selectedIds);
          foreach ($users as $u) {
            if (isset($u['.id']) && isset($idMap[$u['.id']])) {
              $targetUsers[] = $u;
            } elseif (isset($u['name']) && isset($idMap[$u['name']])) {
              $targetUsers[] = $u;
            }
          }
        }
      }

      if ($transferError === '' && empty($targetUsers)) {
        $transferError = $isBn ? 'ট্রান্সফার করার জন্য কোনো ম্যাচিং ইউজার পাওয়া যায়নি।' : 'No matching users found to transfer.';
      }

      if ($isAjaxPrepare) {
        if ($transferError !== '') {
          $ptReplyJson(array('error' => $transferError), 400);
        }
        $targetUids = array();
        foreach ($targetUsers as $tu) {
          if (!empty($tu['.id'])) {
            $targetUids[] = (string)$tu['.id'];
          }
        }
        if (empty($targetUids)) {
          $ptReplyJson(array('error' => $isBn ? 'ট্রান্সফার করার জন্য কোনো ইউজার পাওয়া যায়নি।' : 'No valid users found to transfer.'), 400);
        }
        $jobId = bin2hex(random_bytes(16));
        $_SESSION['profile_transfer'] = array(
          $jobId => array(
            'router' => $session,
            'mode' => $transferMode,
            'dest_profile' => $destProfile,
            'clear_mac' => $clearMac,
            'uids' => $targetUids,
            'done' => 0,
            'moved' => 0,
            'logged' => false
          )
        );
        $ptReplyJson(array(
          'ok' => true,
          'job' => $jobId,
          'total' => count($targetUids),
          'dest_profile' => $destProfile
        ));
      }

      if ($transferError === '' && !empty($targetUsers)) {
        foreach ($targetUsers as $tu) {
          if (empty($tu['.id'])) continue;
          $setParams = array(
            '.id' => $tu['.id'],
            'profile' => $destProfile
          );
          if ($clearMac) {
            $setParams['mac-address'] = '00:00:00:00:00:00';
          }
          $API->comm("/ip/hotspot/user/set", $setParams);
          $movedCount++;
        }

        if (function_exists('ngwRecordActivity')) {
          ngwRecordActivity('hotspot_profile_transfer', array(
            'router' => $session,
            'mode' => $transferMode,
            'dest_profile' => $destProfile,
            'count' => $movedCount
          ));
        }

        $transferSuccess = $isBn
          ? "সফলভাবে {$movedCount} জন হটস্পট ইউজারকে '{$destProfile}' প্রোফাইলে ট্রান্সফার করা হয়েছে!"
          : "Successfully transferred {$movedCount} Hotspot user(s) to profile '{$destProfile}'!";

        // Reload updated counts
        list($profiles, $users, $profileCounts, $batchCounts, $batchProfiles) = $loadRouterHotspotData();
      }
    }
  }
  if ($isAjaxPrepare && $transferError !== '') {
    $ptReplyJson(array('error' => $transferError), 400);
  }
}

$initialMode = isset($_POST['transfer_mode']) && in_array($_POST['transfer_mode'], array('profile', 'batch', 'selected'), true)
  ? $_POST['transfer_mode']
  : (isset($_GET['mode']) && in_array($_GET['mode'], array('profile', 'batch', 'selected'), true) ? $_GET['mode'] : 'profile');

$preSelectedUsers = array();
if (!empty($_GET['users'])) {
  $preSelectedUsers = array_filter(array_map('trim', explode(',', (string)$_GET['users'])));
  if (!empty($preSelectedUsers)) {
    $initialMode = 'selected';
  }
}
$preSourceProfile = isset($_GET['src_profile']) ? trim((string)$_GET['src_profile']) : '';
$preSourceBatch = isset($_GET['src_batch']) ? trim((string)$_GET['src_batch']) : '';
if ($preSourceBatch !== '') {
  $initialMode = 'batch';
}

header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="<?= $isBn ? 'bn' : 'en' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= transferHtml('Same-Router Profile Transfer') ?></title>
<link rel="stylesheet" href="../css/mikhmon-ui.<?= $transferTheme ?>.min.css">
<link rel="stylesheet" href="../css/font-awesome/css/font-awesome.min.css">
<style>
.ngw-transfer{max-width:880px;margin:0 auto;padding:10px;box-sizing:border-box}
.ngw-transfer .transfer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.ngw-transfer .card{box-sizing:border-box;margin-bottom:10px;min-width:0;border-radius:4px;overflow:hidden}
.ngw-transfer .card-header{padding:8px 10px}
.ngw-transfer .card-header h3{margin:0;font-size:15px}
.ngw-transfer .card-body{padding:10px}
.ngw-transfer .btn{padding:7px 10px;font-size:13px;cursor:pointer;border:none;border-radius:3px;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.ngw-transfer .form-control{font-size:13px;padding:6px 8px;width:100%;box-sizing:border-box;min-height:32px}
.ngw-transfer .transfer-grid .card{border-top:0}
.ngw-transfer .page-bar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.ngw-transfer .field{margin-bottom:10px}
.ngw-transfer label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
.ngw-transfer .transfer-help{font-size:12px;line-height:1.5;opacity:.85;margin-top:6px}
.ngw-transfer .transfer-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.ngw-transfer .transfer-status{margin-top:12px;padding:8px 10px;border:1px solid currentColor;border-radius:4px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px}
.ngw-transfer .user-pick-box{max-height:145px;overflow-y:auto;border:1px solid rgba(128,128,128,.3);border-radius:4px;padding:4px 6px;margin-top:6px}
.ngw-transfer .user-pick-row{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:3px 2px;border-bottom:1px solid rgba(128,128,128,.14);font-size:12px}
.ngw-transfer .user-pick-row:last-child{border-bottom:0}
.ngw-transfer .user-pick-row label{margin:0;font-weight:500;font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;flex:1;min-width:0}
.ngw-transfer .user-pick-prof{font-size:10px;padding:1px 6px;border-radius:3px;font-weight:600;white-space:nowrap}
.ngw-transfer progress{width:100%;margin-top:10px;accent-color:currentColor}
.ngw-transfer [hidden]{display:none!important}
@media(max-width:720px){.ngw-transfer .transfer-grid{grid-template-columns:1fr}.ngw-transfer{padding:10px}}
</style>
</head>
<body>
<main class="ngw-transfer">
  <div class="card">
    <div class="card-header page-bar">
      <h3><i class="fa fa-random"></i> <?= transferHtml('Same-Router Profile Transfer') ?></h3>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <a class="btn bg-primary" href="./usertransfer.php?session=<?= htmlspecialchars(rawurlencode($session), ENT_QUOTES) ?>">
          <i class="fa fa-exchange"></i> <?= transferHtml('User Transfer') ?>
        </a>
        <a class="btn bg-secondary" href="../?session=<?= htmlspecialchars(rawurlencode($session), ENT_QUOTES) ?>">
          <i class="fa fa-arrow-left"></i> <?= transferHtml('Back to router') ?>
        </a>
      </div>
    </div>
  </div>

  <form method="post" action="./profiletransfer.php?session=<?= htmlspecialchars(rawurlencode($session), ENT_QUOTES) ?>" id="profileTransferForm">
    <input type="hidden" name="do_profile_transfer" value="1">
    <input type="hidden" name="csrf" id="ptCsrfInput" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <div class="transfer-grid">
      <!-- Left Colored Card: Source Selection -->
      <section class="card box-bordered">
        <div class="card-header bg-primary">
          <h3><i class="fa fa-filter"></i> <?= $isBn ? 'সোর্স ইউজার নির্বাচন' : 'Select Source Users' ?></h3>
        </div>
        <div class="card-body">
          <div class="field">
            <label for="transferModeSelect"><?= $isBn ? 'ট্রান্সফার মোড' : 'Transfer mode' ?></label>
            <select class="form-control" id="transferModeSelect" name="transfer_mode" onchange="switchTransferMode(this.value)">
              <option value="profile" <?= $initialMode === 'profile' ? 'selected' : '' ?>><?= $isBn ? 'পুরো প্রোফাইল অনুযায়ী (Entire Profile)' : 'Entire profile' ?></option>
              <option value="batch" <?= $initialMode === 'batch' ? 'selected' : '' ?>><?= $isBn ? 'ব্যাচ কমেন্ট অনুযায়ী (Batch)' : 'Batch' ?></option>
              <option value="selected" <?= $initialMode === 'selected' ? 'selected' : '' ?>><?= $isBn ? 'সিলেক্টেড ইউজার (Selected Users)' : 'Selected users' ?></option>
            </select>
          </div>

          <!-- Mode 1: Profile -->
          <div class="field" id="modePanelProfile" <?= $initialMode === 'profile' ? '' : 'hidden' ?>>
            <label for="sourceProfileSelect"><?= $isBn ? 'সোর্স প্রোফাইল' : 'Source profile' ?></label>
            <select class="form-control" name="source_profile" id="sourceProfileSelect" onchange="updatePreviewCount()">
              <option value=""><?= $isBn ? '-- সোর্স প্রোফাইল বাছুন --' : '-- Choose source profile --' ?></option>
              <?php foreach ($profiles as $pName) {
                $cnt = isset($profileCounts[$pName]) ? (int)$profileCounts[$pName] : 0;
                $sel = ($preSourceProfile === $pName) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($pName, ENT_QUOTES) ?>" data-count="<?= $cnt ?>" <?= $sel ?>>
                  <?= htmlspecialchars($pName) ?> (<?= $cnt ?> <?= $isBn ? 'জন' : 'users' ?>)
                </option>
              <?php } ?>
            </select>
          </div>

          <!-- Mode 2: Batch -->
          <div class="field" id="modePanelBatch" <?= $initialMode === 'batch' ? '' : 'hidden' ?>>
            <label for="sourceBatchSelect"><?= $isBn ? 'ব্যাচ কমেন্ট' : 'Voucher batch' ?></label>
            <select class="form-control" name="source_batch" id="sourceBatchSelect" onchange="updatePreviewCount()">
              <option value=""><?= $isBn ? '-- ব্যাচ কমেন্ট বাছুন --' : '-- Choose voucher batch --' ?></option>
              <?php foreach ($batchCounts as $bKey => $bCnt) {
                $bProf = isset($batchProfiles[$bKey]) ? $batchProfiles[$bKey] : '';
                $sel = ($preSourceBatch === $bKey) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($bKey, ENT_QUOTES) ?>" data-profile="<?= htmlspecialchars($bProf, ENT_QUOTES) ?>" data-count="<?= (int)$bCnt ?>" <?= $sel ?>>
                  <?= htmlspecialchars($bKey) ?> [<?= htmlspecialchars($bProf) ?>] (<?= (int)$bCnt ?> <?= $isBn ? 'জন' : 'users' ?>)
                </option>
              <?php } ?>
            </select>
          </div>

          <!-- Mode 3: Selected Users -->
          <div class="field" id="modePanelSelected" <?= $initialMode === 'selected' ? '' : 'hidden' ?>>
            <label for="ptUserSearch"><?= $isBn ? 'ইউজার সিলেক্ট করুন' : 'Select users' ?></label>
            <div style="display:flex;gap:4px;align-items:center;">
              <input type="text" id="ptUserSearch" class="form-control" style="flex:1;min-height:28px;padding:4px 7px;font-size:12px;" placeholder="<?= $isBn ? 'ইউজারনেম বা প্রোফাইল খুঁজুন...' : 'Search user or profile...' ?>" onkeyup="filterPtUsers()">
              <button type="button" class="btn bg-primary" style="padding:4px 7px;font-size:11px;" onclick="toggleAllVisiblePtUsers(true)"><?= $isBn ? 'সব' : 'All' ?></button>
              <button type="button" class="btn bg-secondary" style="padding:4px 7px;font-size:11px;" onclick="toggleAllVisiblePtUsers(false)"><?= $isBn ? 'মুছুন' : 'Clear' ?></button>
            </div>
            <div class="user-pick-box" id="ptUsersList">
              <?php if (empty($users)) { ?>
                <div style="padding:6px;text-align:center;font-size:12px;opacity:.75;"><?= $isBn ? 'কোনো হটস্পট ইউজার পাওয়া যায়নি।' : 'No Hotspot users found.' ?></div>
              <?php } else {
                foreach ($users as $u) {
                  $uid = isset($u['.id']) ? (string)$u['.id'] : '';
                  $uname = isset($u['name']) ? (string)$u['name'] : '';
                  $uprof = isset($u['profile']) ? (string)$u['profile'] : 'default';
                  $ucomm = isset($u['comment']) ? (string)$u['comment'] : '';
                  $isChecked = in_array($uid, $preSelectedUsers, true) || in_array($uname, $preSelectedUsers, true);
              ?>
                <div class="user-pick-row pt-user-row">
                  <label>
                    <input type="checkbox" class="pt-user-cb" name="selected_uids[]" value="<?= htmlspecialchars($uid, ENT_QUOTES) ?>" data-profile="<?= htmlspecialchars($uprof, ENT_QUOTES) ?>" <?= $isChecked ? 'checked' : '' ?> onchange="updatePreviewCount()">
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><b><?= htmlspecialchars($uname) ?></b><?= $ucomm !== '' ? ' <small style="opacity:.75;">(' . htmlspecialchars($ucomm) . ')</small>' : '' ?></span>
                  </label>
                  <span class="user-pick-prof bg-primary"><?= htmlspecialchars($uprof) ?></span>
                </div>
              <?php } } ?>
            </div>
          </div>

          <div class="transfer-help">
            <?= $isBn
              ? '• পুরো প্রোফাইল: এক প্রোফাইলের সব ইউজার অন্য প্রোফাইলে যাবে।' . "\n<br>" . '• ব্যাচ / সিলেক্টেড: নির্দিষ্ট ভাউচার ব্যাচ বা বাছাইকৃত ইউজার স্থানান্তর করুন।'
              : '• Entire profile: Move all users from one profile to another.' . "\n<br>" . '• Batch / Selected: Move a specific voucher batch or hand-picked users.' ?>
          </div>
        </div>
      </section>

      <!-- Right Colored Card: Destination Profile & Action -->
      <section class="card box-bordered">
        <div class="card-header bg-success">
          <h3><i class="fa fa-arrow-circle-right"></i> <?= $isBn ? 'গন্তব্য প্রোফাইল ও ট্রান্সফার' : 'Destination Profile' ?></h3>
        </div>
        <div class="card-body">
          <div class="field">
            <label for="destProfileSelect"><?= $isBn ? 'গন্তব্য প্রোফাইল (Destination Profile)' : 'Target profile' ?></label>
            <select class="form-control" name="dest_profile" id="destProfileSelect" required onchange="updatePreviewCount()">
              <option value=""><?= $isBn ? '-- গন্তব্য প্রোফাইল বাছুন --' : '-- Select destination profile --' ?></option>
              <?php foreach ($profiles as $pName) { ?>
                <option value="<?= htmlspecialchars($pName, ENT_QUOTES) ?>">
                  <?= htmlspecialchars($pName) ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="field">
            <label style="display:flex;align-items:flex-start;gap:6px;cursor:pointer;font-weight:600;font-size:12px;">
              <input type="checkbox" name="clear_mac" value="1" style="margin-top:2px;">
              <span><?= $isBn ? 'ট্রান্সফারের সময় ম্যাক অ্যাড্রেস লক রিসেট করুন (ঐচ্ছিক)' : 'Reset MAC address lock during transfer (Optional)' ?></span>
            </label>
          </div>

          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button class="btn bg-primary" type="submit" id="ptSubmitBtn">
              <i class="fa fa-exchange"></i> <?= $isBn ? 'প্রোফাইল ট্রান্সফার করুন' : 'Transfer Profile' ?>
            </button>
            <button class="btn bg-warning" type="button" id="ptResumeBtn" hidden onclick="runPtJobSteps()">
              <i class="fa fa-refresh"></i> <?= $isBn ? 'ট্রান্সফার চালিয়ে যান' : 'Resume Transfer' ?>
            </button>
          </div>

          <div class="transfer-help">
            <?= $isBn
              ? '• ইউজারদের ইউজারনেম, পাসওয়ার্ড, ব্যবহৃত আপটাইম এবং মেয়াদের কমেন্ট অক্ষুণ্ণ রেখে শুধুমাত্র প্রোফাইল পরিবর্তন হবে।'
              : '• Preserves username, password, used uptime, and expiry comment while switching users to the new profile.' ?>
          </div>
        </div>
      </section>
    </div>
  </form>

  <div class="transfer-status" role="status" aria-live="polite">
    <span id="ptStatusText"><?php
      if ($transferSuccess !== '') {
        echo '✓ ' . htmlspecialchars($transferSuccess);
      } elseif ($transferError !== '') {
        echo '⚠ ' . htmlspecialchars($transferError);
      } elseif (!$isRouterConnected) {
        echo '⚠ ' . ($isBn ? 'রাউটার অফলাইন অথবা সংযোগ স্থাপন করা যায়নি।' : 'Router is offline or unreachable.');
      } else {
        echo $isBn ? 'প্রোফাইল ট্রান্সফারের জন্য প্রস্তুত।' : 'Ready for same-router profile transfer.';
      }
    ?></span>
    <progress id="ptProgress" hidden></progress>
  </div>
</main>

<script>
var hasServerMessage = <?= ($transferSuccess !== '' || $transferError !== '') ? 'true' : 'false' ?>;
var ptCurrentJob = '';
var ptBusy = false;
var isBnLang = <?= $isBn ? 'true' : 'false' ?>;
var allRouterProfiles = <?= json_encode(array_values($profiles)) ?>;
var destPlaceholderText = <?= json_encode($isBn ? '-- গন্তব্য প্রোফাইল বাছুন --' : '-- Select destination profile --') ?>;

function getExcludedSourceProfile() {
  var modeEl = document.getElementById('transferModeSelect');
  var mode = modeEl ? modeEl.value : 'profile';
  if (mode === 'profile') {
    var sel = document.getElementById('sourceProfileSelect');
    return (sel && sel.value) ? sel.value : '';
  } else if (mode === 'batch') {
    var bsel = document.getElementById('sourceBatchSelect');
    if (bsel && bsel.selectedIndex > 0) {
      return bsel.options[bsel.selectedIndex].getAttribute('data-profile') || '';
    }
  } else if (mode === 'selected') {
    var checked = document.querySelectorAll('.pt-user-cb:checked');
    if (checked.length > 0) {
      var firstProf = checked[0].getAttribute('data-profile') || '';
      var allSame = true;
      for (var i = 1; i < checked.length; i++) {
        if ((checked[i].getAttribute('data-profile') || '') !== firstProf) {
          allSame = false;
          break;
        }
      }
      if (allSame) return firstProf;
    }
  }
  return '';
}

function syncDestProfileOptions() {
  var destEl = document.getElementById('destProfileSelect');
  if (!destEl) return;
  var currentVal = destEl.value || '';
  var excluded = getExcludedSourceProfile();

  destEl.innerHTML = '';
  var opt0 = document.createElement('option');
  opt0.value = '';
  opt0.textContent = destPlaceholderText;
  destEl.appendChild(opt0);

  var restored = false;
  for (var i = 0; i < allRouterProfiles.length; i++) {
    var p = allRouterProfiles[i];
    if (excluded !== '' && p === excluded) continue;
    var opt = document.createElement('option');
    opt.value = p;
    opt.textContent = p;
    if (currentVal !== '' && p === currentVal) {
      opt.selected = true;
      restored = true;
    }
    destEl.appendChild(opt);
  }
  if (!restored) {
    destEl.value = '';
  }
}

function switchTransferMode(mode) {
  var pProf = document.getElementById('modePanelProfile');
  var pBatch = document.getElementById('modePanelBatch');
  var pSel = document.getElementById('modePanelSelected');
  if (pProf) pProf.hidden = (mode !== 'profile');
  if (pBatch) pBatch.hidden = (mode !== 'batch');
  if (pSel) pSel.hidden = (mode !== 'selected');
  hasServerMessage = false;
  syncDestProfileOptions();
  updatePreviewCount();
}

function filterPtUsers() {
  var q = (document.getElementById('ptUserSearch').value || '').toLowerCase();
  var rows = document.querySelectorAll('#ptUsersList .pt-user-row');
  for (var i = 0; i < rows.length; i++) {
    var txt = (rows[i].innerText || rows[i].textContent || '').toLowerCase();
    rows[i].style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
  }
}

function toggleAllVisiblePtUsers(state) {
  var rows = document.querySelectorAll('#ptUsersList .pt-user-row');
  for (var i = 0; i < rows.length; i++) {
    if (rows[i].style.display !== 'none') {
      var cb = rows[i].querySelector('.pt-user-cb');
      if (cb) cb.checked = state;
    }
  }
  hasServerMessage = false;
  syncDestProfileOptions();
  updatePreviewCount();
}

function updatePreviewCount() {
  if (ptBusy) return;
  syncDestProfileOptions();
  if (hasServerMessage) {
    hasServerMessage = false;
    return;
  }
  var modeEl = document.getElementById('transferModeSelect');
  var mode = modeEl ? modeEl.value : 'profile';
  var count = 0;
  if (mode === 'profile') {
    var sel = document.getElementById('sourceProfileSelect');
    if (sel && sel.selectedIndex > 0) {
      count = parseInt(sel.options[sel.selectedIndex].getAttribute('data-count'), 10) || 0;
    }
  } else if (mode === 'batch') {
    var bsel = document.getElementById('sourceBatchSelect');
    if (bsel && bsel.selectedIndex > 0) {
      count = parseInt(bsel.options[bsel.selectedIndex].getAttribute('data-count'), 10) || 0;
    }
  } else if (mode === 'selected') {
    count = document.querySelectorAll('.pt-user-cb:checked').length;
  }

  var destEl = document.getElementById('destProfileSelect');
  var destName = (destEl && destEl.value) ? destEl.value : '';
  var statusEl = document.getElementById('ptStatusText');
  if (!statusEl) return;

  if (isBnLang) {
    if (destName !== '') {
      statusEl.textContent = 'নির্বাচিত ' + count + ' জন ইউজার "' + destName + '" প্রোফাইলে ট্রান্সফারের জন্য প্রস্তুত।';
    } else {
      statusEl.textContent = 'নির্বাচিত ইউজার সংখ্যা: ' + count + ' জন। গন্তব্য প্রোফাইল নির্বাচন করুন।';
    }
  } else {
    if (destName !== '') {
      statusEl.textContent = count + ' user(s) selected to transfer into profile "' + destName + '".';
    } else {
      statusEl.textContent = count + ' user(s) selected. Choose a destination profile to transfer.';
    }
  }
}

document.addEventListener('DOMContentLoaded', function() {
  syncDestProfileOptions();
});

async function ptPostCall(formData) {
  var res = await fetch(window.location.href, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  });
  var data;
  try {
    data = await res.json();
  } catch (err) {
    throw new Error(isBnLang ? 'সার্ভার রেসপন্স বিচ্ছিন্ন হয়েছে। রিজিউম বাটনে ক্লিক করুন।' : 'Response interrupted. Click Resume Transfer.');
  }
  if (!res.ok || data.error) {
    throw new Error(data.error || (isBnLang ? 'অনুরোধ ব্যর্থ হয়েছে।' : 'Request failed.'));
  }
  return data;
}

async function runPtJobSteps() {
  if (ptBusy || !ptCurrentJob) return;
  ptBusy = true;
  var statusEl = document.getElementById('ptStatusText');
  var progEl = document.getElementById('ptProgress');
  var submitBtn = document.getElementById('ptSubmitBtn');
  var resumeBtn = document.getElementById('ptResumeBtn');
  var csrfVal = document.getElementById('ptCsrfInput').value;

  if (submitBtn) submitBtn.disabled = true;
  if (resumeBtn) resumeBtn.hidden = true;

  var retries = 0;
  try {
    while (true) {
      var fd = new FormData();
      fd.set('action', 'step_pt');
      fd.set('job', ptCurrentJob);
      fd.set('csrf', csrfVal);

      var stepRes;
      try {
        stepRes = await ptPostCall(fd);
        retries = 0;
      } catch (stepErr) {
        retries++;
        if (retries <= 2) {
          await new Promise(function(r) { setTimeout(r, 600); });
          continue;
        }
        throw stepErr;
      }

      if (progEl) {
        progEl.hidden = false;
        progEl.max = stepRes.total || 1;
        progEl.value = stepRes.done || 0;
      }

      if (statusEl) {
        statusEl.textContent = isBnLang
          ? ('প্রোফাইল ট্রান্সফার চলছে: ' + stepRes.done + ' / ' + stepRes.total + ' জন ইউজার ("' + stepRes.dest_profile + '")...')
          : ('Transferring profiles: ' + stepRes.done + ' / ' + stepRes.total + ' users to "' + stepRes.dest_profile + '"...');
      }

      if (stepRes.done >= stepRes.total || stepRes.finished) {
        if (statusEl) {
          statusEl.textContent = isBnLang
            ? ('✓ সফলভাবে ' + stepRes.moved + ' জন হটস্পট ইউজারকে "' + stepRes.dest_profile + '" প্রোফাইলে ট্রান্সফার করা হয়েছে!')
            : ('✓ Successfully transferred ' + stepRes.moved + ' Hotspot user(s) to profile "' + stepRes.dest_profile + '"!');
        }
        ptCurrentJob = '';
        setTimeout(function() {
          window.location.href = window.location.pathname + window.location.search;
        }, 1200);
        break;
      }
    }
  } catch (err) {
    if (statusEl) statusEl.textContent = '⚠ ' + err.message;
    if (resumeBtn) resumeBtn.hidden = false;
  } finally {
    ptBusy = false;
    if (submitBtn) submitBtn.disabled = false;
  }
}

var ptForm = document.getElementById('profileTransferForm');
if (ptForm) {
  ptForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    if (ptBusy) return;
    ptBusy = true;

    var statusEl = document.getElementById('ptStatusText');
    var progEl = document.getElementById('ptProgress');
    var submitBtn = document.getElementById('ptSubmitBtn');
    var resumeBtn = document.getElementById('ptResumeBtn');

    if (submitBtn) submitBtn.disabled = true;
    if (resumeBtn) resumeBtn.hidden = true;
    if (progEl) {
      progEl.hidden = false;
      progEl.value = 0;
      progEl.max = 100;
    }
    if (statusEl) {
      statusEl.textContent = isBnLang ? 'ট্রান্সফার প্রস্তুত করা হচ্ছে…' : 'Preparing profile transfer…';
    }

    try {
      var fd = new FormData(ptForm);
      fd.set('action', 'prepare_pt');
      var prep = await ptPostCall(fd);
      ptCurrentJob = prep.job;
      if (progEl) {
        progEl.max = prep.total || 1;
        progEl.value = 0;
      }
      ptBusy = false;
      await runPtJobSteps();
    } catch (err) {
      if (progEl) progEl.hidden = true;
      if (statusEl) statusEl.textContent = '⚠ ' + err.message;
      ptBusy = false;
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}
</script>
</body>
</html>
