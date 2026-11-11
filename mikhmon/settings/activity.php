<?php
session_start();
error_reporting(0);
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/reseller_acl.php';

if (empty($_SESSION['mikhmon'])) {
    header('Location:../admin.php?id=login');
    exit;
}
if (!isMikhmonAdmin()) {
    http_response_code(403);
    echo '<div class="card"><div class="card-body">Access denied.</div></div>';
    exit;
}

$actCsrf = ngwCsrfToken('ngw_activity_csrf');
$actMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['clear_activity_log'])) {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (ngwValidateCsrf($postedCsrf, 'ngw_activity_csrf')) {
        ngwClearActivityLog();
        $actMsg = 'Activity history cleared.';
    }
}

$filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : 'all';
if (!in_array($filter, array('all', 'auth', 'login_failed', 'router_error'), true)) {
    $filter = 'all';
}

$rangePreset = isset($_GET['range']) ? trim((string)$_GET['range']) : '';
$rawFrom = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$rawTo = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

if ($rangePreset === '15d') {
    $rawFrom = date('Y-m-d', strtotime('-14 days'));
    $rawTo = date('Y-m-d');
} elseif ($rangePreset === '30d' || $rangePreset === '1m') {
    $rawFrom = date('Y-m-d', strtotime('-29 days'));
    $rawTo = date('Y-m-d');
} elseif ($rangePreset === 'today') {
    $rawFrom = date('Y-m-d');
    $rawTo = date('Y-m-d');
} elseif ($rangePreset === 'all') {
    $rawFrom = '';
    $rawTo = '';
}

$validFrom = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom)) ? $rawFrom : '';
$validTo = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)) ? $rawTo : '';

// Support both forward (e.g., 1st to 10th) and reverse (e.g., 5th to 3rd) date selections seamlessly
$minDate = $validFrom;
$maxDate = $validTo;
if ($validFrom !== '' && $validTo !== '' && strcmp($validFrom, $validTo) > 0) {
    $minDate = $validTo;
    $maxDate = $validFrom;
}

$allEvents = ngwReadActivityLog(3000);

// Apply date filter first so category counts reflect the selected date window
$dateFilteredEvents = array();
foreach ($allEvents as $ev) {
    $evDate = isset($ev['time']) ? substr((string)$ev['time'], 0, 10) : '';
    if ($minDate !== '' && ($evDate === '' || strcmp($evDate, $minDate) < 0)) {
        continue;
    }
    if ($maxDate !== '' && ($evDate === '' || strcmp($evDate, $maxDate) > 0)) {
        continue;
    }
    $dateFilteredEvents[] = $ev;
}

$counts = array(
    'all' => count($dateFilteredEvents),
    'auth' => 0,
    'login_failed' => 0,
    'router_error' => 0,
);
foreach ($dateFilteredEvents as $ev) {
    $a = isset($ev['action']) ? (string)$ev['action'] : '';
    if (in_array($a, array('login', 'logout', 'session_force_logout'), true)) {
        $counts['auth']++;
    } elseif ($a === 'login_failed') {
        $counts['login_failed']++;
    } elseif ($a === 'router_connect_failed') {
        $counts['router_error']++;
    }
}

$events = array();
foreach ($dateFilteredEvents as $ev) {
    $a = isset($ev['action']) ? (string)$ev['action'] : '';
    if ($filter === 'auth' && !in_array($a, array('login', 'logout', 'session_force_logout'), true)) {
        continue;
    }
    if ($filter === 'login_failed' && $a !== 'login_failed') {
        continue;
    }
    if ($filter === 'router_error' && $a !== 'router_connect_failed') {
        continue;
    }
    $events[] = $ev;
}

$dateQuerySuffix = '';
if ($validFrom !== '') $dateQuerySuffix .= '&from=' . urlencode($validFrom);
if ($validTo !== '') $dateQuerySuffix .= '&to=' . urlencode($validTo);
if ($rangePreset !== '' && $validFrom === '' && $validTo === '') $dateQuerySuffix .= '&range=' . urlencode($rangePreset);

$isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');
if ($actMsg !== '' && $isBn) {
    $actMsg = 'অ্যাক্টিভিটি হিস্টরি মুছে ফেলা হয়েছে।';
}

function ngwFormatActivityBadge($action) {
    global $isBn;
    switch ($action) {
        case 'login':
            return array('label' => $isBn ? 'সফল লগইন' : 'Login Success', 'bg' => '#2ea043', 'icon' => 'fa-sign-in');
        case 'logout':
            return array('label' => $isBn ? 'লগআউট' : 'Logout', 'bg' => '#6e7681', 'icon' => 'fa-sign-out');
        case 'session_force_logout':
            return array('label' => $isBn ? 'ফোর্স লগআউট' : 'Force Logout', 'bg' => '#d29922', 'icon' => 'fa-power-off');
        case 'login_failed':
            return array('label' => $isBn ? 'লগইন ব্যর্থ' : 'Login Failed', 'bg' => '#f85149', 'icon' => 'fa-exclamation-triangle');
        case 'router_connect_failed':
            return array('label' => $isBn ? 'রাউটার ত্রুটি' : 'Router Error', 'bg' => '#f85149', 'icon' => 'fa-plug');
        case 'router_connected':
            return array('label' => $isBn ? 'রাউটার সংযুক্ত' : 'Router Connected', 'bg' => '#1f6feb', 'icon' => 'fa-server');
        case 'voucher_batch_created':
            return array('label' => $isBn ? 'ভাউচার ব্যাচ তৈরি' : 'Voucher Batch', 'bg' => '#1f6feb', 'icon' => 'fa-ticket');
        case 'hotspot_user_created':
            return array('label' => $isBn ? 'ইউজার যুক্ত' : 'User Added', 'bg' => '#1f6feb', 'icon' => 'fa-user-plus');
        case 'reseller_created':
            return array('label' => $isBn ? 'রিসেলার যুক্ত' : 'Reseller Added', 'bg' => '#2ea043', 'icon' => 'fa-users');
        case 'reseller_updated':
            return array('label' => $isBn ? 'রিসেলার আপডেট' : 'Reseller Edited', 'bg' => '#1f6feb', 'icon' => 'fa-edit');
        case 'reseller_deleted':
            return array('label' => $isBn ? 'রিসেলার মুছে ফেলা' : 'Reseller Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'reseller_status_changed':
            return array('label' => $isBn ? 'স্ট্যাটাস পরিবর্তন' : 'Status Changed', 'bg' => '#d29922', 'icon' => 'fa-exchange');
        case 'reseller_impersonation':
            return array('label' => $isBn ? 'রিসেলার লগইন' : 'Reseller Login', 'bg' => '#8957e5', 'icon' => 'fa-user-secret');
        default:
            return array('label' => ucwords(str_replace('_', ' ', (string)$action)), 'bg' => '#484f58', 'icon' => 'fa-info-circle');
    }
}
?>
<style>
.ngw-act-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}
.ngw-act-tabs {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
}
.ngw-act-tab {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  height: 24px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
  text-decoration: none !important;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: inherit;
  box-sizing: border-box;
}
.ngw-act-tab:hover {
  background: rgba(255, 255, 255, 0.12);
}
.ngw-act-tab.is-active {
  background: #1f6feb;
  border-color: #388bfd;
  color: #ffffff !important;
}
.ngw-act-datebar {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin: 0;
}
.ngw-act-date-input {
  height: 24px;
  padding: 1px 5px;
  font-size: 11px;
  border-radius: 3px;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(0, 0, 0, 0.22);
  color: inherit;
  box-sizing: border-box;
}
.ngw-act-mini-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 24px;
  padding: 0 7px;
  font-size: 11px;
  font-weight: 600;
  border-radius: 3px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  background: rgba(255, 255, 255, 0.06);
  color: inherit;
  text-decoration: none !important;
  cursor: pointer;
  box-sizing: border-box;
}
.ngw-act-mini-btn.is-active {
  background: #2ea043;
  border-color: #2ea043;
  color: #fff !important;
}
.ngw-act-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 700;
  color: #ffffff;
  white-space: nowrap;
}
.ngw-act-err-box {
  color: #ff7b72;
  font-weight: 600;
}
.ngw-show-xs {
  display: none;
}
.ngw-col-time { width: 138px; white-space: nowrap; font-size: 12px; }
.ngw-col-user { width: 125px; }
.ngw-col-role { width: 80px; }
.ngw-col-act  { width: 135px; }
.ngw-col-dev  { width: 185px; font-size: 11px; }
@media screen and (max-width: 767px) {
  .ngw-hide-xs {
    display: none !important;
  }
  .ngw-show-xs {
    display: block !important;
  }
  .ngw-act-tab {
    padding: 2px 5px;
    height: 22px;
    font-size: 10px;
    gap: 3px;
  }
  .ngw-act-date-input {
    height: 22px;
    padding: 1px 3px;
    font-size: 10px;
    max-width: 112px;
  }
  .ngw-act-mini-btn {
    height: 22px;
    padding: 0 5px;
    font-size: 10px;
  }
  .ngw-act-badge {
    padding: 1px 4px;
    font-size: 9px;
    white-space: normal;
  }
  .ngw-col-time,
  .ngw-col-user,
  .ngw-col-act {
    width: auto !important;
    white-space: normal !important;
    font-size: 10px !important;
  }
}
</style>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
        <h3 style="margin:0;">
          <i class="fa fa-history"></i> <?= $isBn ? 'অ্যাক্টিভিটি হিস্টরি' : 'Activity History'; ?>
          <small style="font-size:11px; opacity:0.8; font-weight:normal; margin-left:4px;">(<?= count($events); ?>)</small>
        </h3>
        <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./admin.php?id=active-sessions"><i class="fa fa-desktop"></i> <?= $isBn ? 'অ্যাক্টিভ সেশন' : 'Active Sessions'; ?></a>
          <?php if (!empty($allEvents)) { ?>
          <form method="post" action="./admin.php?id=activity" style="margin:0; display:inline;" onsubmit="return confirm('<?= $isBn ? 'সব অ্যাক্টিভিটি হিস্টরি মুছে ফেলতে চান?' : 'Clear all recorded activity history?'; ?>');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($actCsrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="clear_activity_log" value="1">
            <button type="submit" class="btn bg-danger pd-2p5 radius-3" style="cursor:pointer; font-size:11px;"><i class="fa fa-trash"></i> <?= $isBn ? 'মুছে ফেলুন' : 'Clear'; ?></button>
          </form>
          <?php } ?>
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./admin.php?id=activity&filter=<?= urlencode($filter) . $dateQuerySuffix; ?>" title="Refresh"><i class="fa fa-refresh"></i></a>
        </div>
      </div>

      <div class="card-body">
        <?php if ($actMsg !== '') { ?>
          <div class="bg-green pd-5 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= htmlspecialchars($actMsg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>

        <div class="ngw-act-toolbar">
          <!-- Compact Category Filter Tabs -->
          <div class="ngw-act-tabs">
            <a href="./admin.php?id=activity&filter=all<?= $dateQuerySuffix; ?>" class="ngw-act-tab <?= $filter === 'all' ? 'is-active' : ''; ?>">
              <?= $isBn ? 'সব' : 'All'; ?> (<?= (int)$counts['all']; ?>)
            </a>
            <a href="./admin.php?id=activity&filter=auth<?= $dateQuerySuffix; ?>" class="ngw-act-tab <?= $filter === 'auth' ? 'is-active' : ''; ?>">
              <i class="fa fa-sign-in"></i> <?= $isBn ? 'লগইন/লগআউট' : 'Login/Logout'; ?> (<?= (int)$counts['auth']; ?>)
            </a>
            <a href="./admin.php?id=activity&filter=login_failed<?= $dateQuerySuffix; ?>" class="ngw-act-tab <?= $filter === 'login_failed' ? 'is-active' : ''; ?>">
              <i class="fa fa-warning text-danger"></i> <?= $isBn ? 'লগইন ব্যর্থ' : 'Failed Login'; ?> (<?= (int)$counts['login_failed']; ?>)
            </a>
            <a href="./admin.php?id=activity&filter=router_error<?= $dateQuerySuffix; ?>" class="ngw-act-tab <?= $filter === 'router_error' ? 'is-active' : ''; ?>">
              <i class="fa fa-plug text-warning"></i> <?= $isBn ? 'রাউটার ত্রুটি' : 'Router Error'; ?> (<?= (int)$counts['router_error']; ?>)
            </a>
          </div>

          <!-- Compact Date Range & Quick Presets (15 Days / 1 Month / Custom From-To) -->
          <form method="get" action="./admin.php" class="ngw-act-datebar">
            <input type="hidden" name="id" value="activity">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?>">
            <a href="./admin.php?id=activity&filter=<?= urlencode($filter); ?>&range=15d" class="ngw-act-mini-btn <?= $rangePreset === '15d' ? 'is-active' : ''; ?>" title="Last 15 Days">15D</a>
            <a href="./admin.php?id=activity&filter=<?= urlencode($filter); ?>&range=1m" class="ngw-act-mini-btn <?= ($rangePreset === '1m' || $rangePreset === '30d') ? 'is-active' : ''; ?>" title="Last 1 Month (30 Days)">1M</a>
            <input type="date" name="from" value="<?= htmlspecialchars($validFrom, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-date-input" title="From Date">
            <span style="font-size:10px; opacity:0.7;"><?= $isBn ? 'থেকে' : 'to'; ?></span>
            <input type="date" name="to" value="<?= htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-date-input" title="To Date">
            <button type="submit" class="btn bg-primary pd-2p5 radius-3" style="height:22px; font-size:10px; line-height:1; cursor:pointer; margin:0;" title="Filter by Date">
              <i class="fa fa-filter"></i> <?= $isBn ? 'ফিল্টার' : 'Filter'; ?>
            </button>
            <?php if ($validFrom !== '' || $validTo !== '') { ?>
              <a href="./admin.php?id=activity&filter=<?= urlencode($filter); ?>" class="ngw-act-mini-btn" title="Reset Date Filter"><i class="fa fa-times text-danger"></i></a>
            <?php } ?>
          </form>
        </div>

        <div class="overflow box-bordered" style="max-height:70vh;">
          <table class="table table-sm table-bordered table-hover">
            <thead>
              <tr>
                <th class="ngw-col-time"><?= $isBn ? 'সময়' : 'Time'; ?></th>
                <th class="ngw-col-user"><?= $isBn ? 'ইউজার' : 'User'; ?></th>
                <th class="ngw-col-role ngw-hide-xs"><?= $isBn ? 'রোল' : 'Role'; ?></th>
                <th class="ngw-col-act"><?= $isBn ? 'কার্যক্রম' : 'Activity'; ?></th>
                <th class="ngw-col-dev ngw-hide-xs"><?= $isBn ? 'ডিভাইস ও আইপি' : 'Device &amp; IP'; ?></th>
                <th><?= $isBn ? 'বিস্তারিত / ত্রুটির কারণ' : 'Details / Error Reason'; ?></th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($events)) { ?>
              <tr><td colspan="6" class="text-center pd-10"><?= $isBn ? 'নির্বাচিত ফিল্টার বা তারিখের মধ্যে কোনো অ্যাক্টিভিটি পাওয়া যায়নি।' : 'No activity events found for the selected filter/date range.'; ?></td></tr>
            <?php } else {
              foreach ($events as $event) {
                $action = isset($event['action']) ? (string)$event['action'] : '';
                $badge = ngwFormatActivityBadge($action);
                $det = isset($event['details']) && is_array($event['details']) ? $event['details'] : array();
                $ipVal = isset($det['ip']) ? (string)$det['ip'] : '-';
                $devVal = isset($det['device']) ? (string)$det['device'] : '-';

                $detailParts = array();
                if (!empty($det['error'])) {
                  $detailParts[] = '<span class="ngw-act-err-box"><i class="fa fa-warning"></i> ' . htmlspecialchars((string)$det['error'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
                if (!empty($det['router'])) {
                  $detailParts[] = '<b>Router:</b> ' . htmlspecialchars((string)$det['router'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['host'])) {
                  $detailParts[] = '<b>Host/IP:</b> <code>' . htmlspecialchars((string)$det['host'], ENT_QUOTES, 'UTF-8') . '</code>';
                }
                if (!empty($det['target'])) {
                  $detailParts[] = '<b>Target:</b> ' . htmlspecialchars((string)$det['target'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['profile'])) {
                  $detailParts[] = '<b>Profile:</b> ' . htmlspecialchars((string)$det['profile'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['quantity'])) {
                  $detailParts[] = '<b>Qty:</b> ' . htmlspecialchars((string)$det['quantity'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['status'])) {
                  $detailParts[] = '<b>Status:</b> ' . htmlspecialchars((string)$det['status'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['area'])) {
                  $detailParts[] = '<b>Area:</b> ' . htmlspecialchars((string)$det['area'], ENT_QUOTES, 'UTF-8');
                }
                if (empty($detailParts)) {
                  if ($action === 'login') {
                    $detailParts[] = '<span class="text-green">Logged in successfully</span>';
                  } elseif ($action === 'logout') {
                    $detailParts[] = 'Logged out of session';
                  } else {
                    $detailParts[] = '-';
                  }
                }
                $roleStr = isset($event['role']) ? (string)$event['role'] : 'admin';
                $roleColor = $roleStr === 'reseller' ? '#2ea043' : ($roleStr === 'admin' ? '#1f6feb' : '#6e7681');
            ?>
              <tr>
                <td class="align-middle ngw-col-time"><?= htmlspecialchars($event['time'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="align-middle ngw-col-user">
                  <b><?= htmlspecialchars($event['actor'], ENT_QUOTES, 'UTF-8'); ?></b>
                  <div class="ngw-show-xs" style="margin-top:2px;">
                    <span style="padding:1px 4px; border-radius:3px; font-size:9px; font-weight:700; text-transform:uppercase; color:#fff; background:<?= $roleColor; ?>;">
                      <?= htmlspecialchars(ucfirst($roleStr), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                  </div>
                </td>
                <td class="align-middle ngw-col-role ngw-hide-xs">
                  <span style="padding:1px 5px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; color:#fff; background:<?= $roleColor; ?>;">
                    <?= htmlspecialchars(ucfirst($roleStr), ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td class="align-middle ngw-col-act">
                  <span class="ngw-act-badge" style="background:<?= $badge['bg']; ?>;">
                    <i class="fa <?= $badge['icon']; ?>"></i>
                    <?= htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td class="align-middle ngw-col-dev ngw-hide-xs">
                  <div><?= htmlspecialchars($devVal, ENT_QUOTES, 'UTF-8'); ?></div>
                  <div style="opacity:0.75;">IP: <code><?= htmlspecialchars($ipVal, ENT_QUOTES, 'UTF-8'); ?></code></div>
                </td>
                <td class="align-middle" style="font-size:11px;">
                  <div><?= implode(' &middot; ', $detailParts); ?></div>
                  <div class="ngw-show-xs" style="font-size:10px; opacity:0.75; margin-top:2px;">
                    <?= htmlspecialchars($devVal, ENT_QUOTES, 'UTF-8'); ?> &middot; IP: <code><?= htmlspecialchars($ipVal, ENT_QUOTES, 'UTF-8'); ?></code>
                  </div>
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