<?php
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
error_reporting(0);
require_once __DIR__ . '/../include/reseller_acl.php';

if (empty($_SESSION['mikhmon'])) {
    header('Location:../admin.php?id=login');
    exit;
}
if (!isMikhmonAdmin() || !hasResellerPermission('view_logs')) {
    http_response_code(403);
    echo '<div class="card"><div class="card-body">Access denied.</div></div>';
    exit;
}

$actCsrf = ngwCsrfToken('ngw_activity_csrf');
$actMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['clear_activity_log']) && isMikhmonAdminStore()) {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (ngwValidateCsrf($postedCsrf, 'ngw_activity_csrf')) {
        ngwClearActivityLog();
        $actMsg = 'Activity history cleared.';
    }
}

$todayDate = date('Y-m-d');
$filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : 'all';
$validFilters = array('all', 'users_vouchers', 'profiles_system', 'auth', 'login_failed', 'router_error');
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$roleFilter = isset($_GET['role']) ? trim((string)$_GET['role']) : 'all';
if (!in_array($roleFilter, array('all', 'reseller', 'admin'), true)) {
    $roleFilter = 'all';
}
$actorFilter = isset($_GET['actor']) ? trim((string)$_GET['actor']) : '';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$rangePreset = isset($_GET['range']) ? trim((string)$_GET['range']) : '';
$rawDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$rawFrom = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$rawTo = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
$reqMode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';

if ($rangePreset === '15d') {
    $rawFrom = date('Y-m-d', strtotime('-14 days'));
    $rawTo = $todayDate;
    $reqMode = 'range';
} elseif ($rangePreset === '30d' || $rangePreset === '1m') {
    $rawFrom = date('Y-m-d', strtotime('-29 days'));
    $rawTo = $todayDate;
    $reqMode = 'range';
} elseif ($rangePreset === 'all') {
    $rawFrom = '';
    $rawTo = '';
    $reqMode = 'range';
} elseif ($rangePreset === 'today') {
    $rawDate = $todayDate;
    $rawFrom = '';
    $rawTo = '';
    $reqMode = 'day';
}

$validSingleDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) ? $rawDate : $todayDate;
$validFrom = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom)) ? $rawFrom : '';
$validTo = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)) ? $rawTo : '';

// Default screen shows ONLY 1 day's history unless custom range is explicitly chosen
$viewMode = ($reqMode === 'range' || $validFrom !== '' || $validTo !== '' || $rangePreset === 'all') ? 'range' : 'day';

$minDate = $validFrom;
$maxDate = $validTo;
if ($viewMode === 'day') {
    $minDate = $validSingleDate;
    $maxDate = $validSingleDate;
} else {
    if ($validFrom !== '' && $validTo !== '' && strcmp($validFrom, $validTo) > 0) {
        $minDate = $validTo;
        $maxDate = $validFrom;
    }
}

$prevDayDate = date('Y-m-d', strtotime($validSingleDate . ' -1 day'));
$nextDayDate = date('Y-m-d', strtotime($validSingleDate . ' +1 day'));
$canGoNextDay = (strcmp($validSingleDate, $todayDate) < 0);

$rawAllEvents = ngwReadActivityLog(12000);
$isStoreAdmin = isMikhmonAdminStore();
$allowedWorkspaceActors = array();
if (!$isStoreAdmin) {
    $selfAdminUser = isset($_SESSION['mikhmon']) ? strtolower(trim((string)$_SESSION['mikhmon'])) : '';
    if ($selfAdminUser !== '') {
        $allowedWorkspaceActors[$selfAdminUser] = (string)$_SESSION['mikhmon'];
    }
    foreach (getAccessibleResellers() as $rOwn) {
        if (!empty($rOwn['username'])) {
            $allowedWorkspaceActors[strtolower((string)$rOwn['username'])] = (string)$rOwn['username'];
        }
    }
}

$allEvents = array();
foreach ($rawAllEvents as $evItem) {
    if (!$isStoreAdmin) {
        $evActLower = isset($evItem['actor']) ? strtolower((string)$evItem['actor']) : '';
        $evTargetLower = isset($evItem['details']['target']) ? strtolower((string)$evItem['details']['target']) : '';
        if (!isset($allowedWorkspaceActors[$evActLower]) && !isset($allowedWorkspaceActors[$evTargetLower])) {
            continue;
        }
    }
    $allEvents[] = $evItem;
}

// Collect unique actors for dropdown filter (resellers + admins + any recorded actor)
$actorOptions = array();
foreach (getAccessibleResellers() as $rItem) {
    if (!empty($rItem['username'])) {
        $actorOptions[(string)$rItem['username']] = true;
    }
}
if ($isStoreAdmin) {
    foreach (loadAdminUsers() as $aItem) {
        if (!empty($aItem['username'])) {
            $actorOptions[(string)$aItem['username']] = true;
        }
    }
} elseif (!empty($_SESSION['mikhmon'])) {
    $actorOptions[(string)$_SESSION['mikhmon']] = true;
}
foreach ($allEvents as $ev) {
    if (!empty($ev['actor']) && $ev['actor'] !== 'unknown') {
        $actorOptions[(string)$ev['actor']] = true;
    }
}
$actorNames = array_keys($actorOptions);
natcasesort($actorNames);

function ngwActivityCategory($action) {
    if (in_array($action, array('login', 'logout', 'session_force_logout', 'reseller_impersonation'), true)) {
        return 'auth';
    }
    if ($action === 'login_failed') {
        return 'login_failed';
    }
    if (in_array($action, array('router_connect_failed', 'router_connected'), true)) {
        return 'router_error';
    }
    if (in_array($action, array(
        'voucher_batch_created',
        'hotspot_user_created',
        'hotspot_user_updated',
        'hotspot_user_deleted',
        'hotspot_users_batch_deleted',
        'hotspot_expired_deleted',
        'hotspot_voucher_reset',
        'hotspot_user_reset',
        'hotspot_bulk_reset_mac',
        'hotspot_user_enabled',
        'hotspot_user_disabled',
        'hotspot_voucher_printed',
        'hotspot_users_exported',
        'hotspot_users_transferred',
        'hotspot_active_disconnected',
        'hotspot_host_removed',
        'hotspot_cookie_removed',
        'pppoe_user_created',
        'pppoe_user_updated',
        'pppoe_user_deleted',
        'pppoe_user_enabled',
        'pppoe_user_disabled',
        'pppoe_status_changed',
        'pppoe_user_expired',
        'pppoe_active_disconnected',
        'pppoe_caller_id_cleared',
        'pppoe_renewed',
        'pppoe_full_reset',
    ), true)) {
        return 'users_vouchers';
    }
    return 'profiles_system';
}

// 1) Filter by date + role + actor + keyword first (so category tab counts accurately match!)
$baseFilteredEvents = array();
$searchLower = strtolower($searchQuery);
foreach ($allEvents as $ev) {
    $evDate = isset($ev['time']) ? substr((string)$ev['time'], 0, 10) : '';
    if ($minDate !== '' && ($evDate === '' || strcmp($evDate, $minDate) < 0)) {
        continue;
    }
    if ($maxDate !== '' && ($evDate === '' || strcmp($evDate, $maxDate) > 0)) {
        continue;
    }
    $evActor = isset($ev['actor']) ? (string)$ev['actor'] : '';
    $evRole = isset($ev['role']) ? (string)$ev['role'] : 'admin';
    if ($roleFilter !== 'all' && $evRole !== $roleFilter) {
        continue;
    }
    if ($actorFilter !== '' && strcasecmp($evActor, $actorFilter) !== 0) {
        continue;
    }
    if ($searchLower !== '') {
        $detStr = isset($ev['details']) && is_array($ev['details']) ? implode(' ', array_values($ev['details'])) : '';
        $haystack = strtolower($evActor . ' ' . $evRole . ' ' . (isset($ev['action']) ? $ev['action'] : '') . ' ' . $detStr);
        if (strpos($haystack, $searchLower) === false) {
            continue;
        }
    }
    $baseFilteredEvents[] = $ev;
}

$counts = array(
    'all' => count($baseFilteredEvents),
    'users_vouchers' => 0,
    'profiles_system' => 0,
    'auth' => 0,
    'login_failed' => 0,
    'router_error' => 0,
);
foreach ($baseFilteredEvents as $ev) {
    $cat = ngwActivityCategory(isset($ev['action']) ? (string)$ev['action'] : '');
    if (isset($counts[$cat])) {
        $counts[$cat]++;
    }
}

$filteredEvents = array();
foreach ($baseFilteredEvents as $ev) {
    if ($filter !== 'all') {
        $cat = ngwActivityCategory(isset($ev['action']) ? (string)$ev['action'] : '');
        if ($cat !== $filter) {
            continue;
        }
    }
    $filteredEvents[] = $ev;
}

// Per-page pagination (supports smooth Next / Prev stepping on busy days)
$perPage = 60;
$totalMatched = count($filteredEvents);
$totalPages = max(1, (int)ceil($totalMatched / $perPage));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$events = array_slice($filteredEvents, ($page - 1) * $perPage, $perPage);

function ngwBuildActUrl($overrides = array()) {
    global $filter, $roleFilter, $actorFilter, $searchQuery, $viewMode, $validSingleDate, $validFrom, $validTo, $rangePreset, $page;
    $params = array(
        'id' => 'activity',
        'filter' => $filter,
        'mode' => $viewMode,
    );
    if ($viewMode === 'day') {
        $params['date'] = $validSingleDate;
    } else {
        if ($rangePreset !== '') $params['range'] = $rangePreset;
        if ($validFrom !== '') $params['from'] = $validFrom;
        if ($validTo !== '') $params['to'] = $validTo;
    }
    if ($roleFilter !== 'all') $params['role'] = $roleFilter;
    if ($actorFilter !== '') $params['actor'] = $actorFilter;
    if ($searchQuery !== '') $params['q'] = $searchQuery;
    if ($page > 1) $params['page'] = $page;

    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return './admin.php?' . http_build_query($params);
}

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
            return array('label' => $isBn ? 'রাউটার ওপেন ব্যর্থ' : 'Router Open Failed', 'bg' => '#f85149', 'icon' => 'fa-plug');
        case 'router_connected':
            return array('label' => $isBn ? 'রাউটার ওপেন' : 'Router Opened', 'bg' => '#1f6feb', 'icon' => 'fa-server');
        case 'router_settings_saved':
            return array('label' => $isBn ? 'রাউটার সেটিংস সেভ' : 'Router Saved', 'bg' => '#1f6feb', 'icon' => 'fa-sliders');
        case 'router_deleted':
            return array('label' => $isBn ? 'রাউটার ডিলিট' : 'Router Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'router_reboot':
            return array('label' => $isBn ? 'রাউটার রিবুট' : 'Router Reboot', 'bg' => '#d29922', 'icon' => 'fa-refresh');
        case 'router_shutdown':
            return array('label' => $isBn ? 'রাউটার শাটডাউন' : 'Router Shutdown', 'bg' => '#f85149', 'icon' => 'fa-power-off');
        case 'voucher_batch_created':
            return array('label' => $isBn ? 'ভাউচার জেনারেট' : 'Vouchers Generated', 'bg' => '#2ea043', 'icon' => 'fa-ticket');
        case 'hotspot_user_created':
            return array('label' => $isBn ? 'ইউজার অ্যাড' : 'User Added', 'bg' => '#2ea043', 'icon' => 'fa-user-plus');
        case 'hotspot_user_updated':
            return array('label' => $isBn ? 'ইউজার এডিট' : 'User Edited', 'bg' => '#1f6feb', 'icon' => 'fa-edit');
        case 'hotspot_user_deleted':
            return array('label' => $isBn ? 'ইউজার ডিলিট' : 'User Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'hotspot_users_batch_deleted':
            return array('label' => $isBn ? 'ব্যাচ ডিলিট' : 'Batch Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'hotspot_expired_deleted':
            return array('label' => $isBn ? 'মেয়াদোত্তীর্ণ ডিলিট' : 'Expired Deleted', 'bg' => '#d29922', 'icon' => 'fa-clock-o');
        case 'hotspot_voucher_reset':
            return array('label' => $isBn ? 'ভাউচার রিসেট' : 'Voucher Reset', 'bg' => '#8957e5', 'icon' => 'fa-refresh');
        case 'hotspot_user_reset':
            return array('label' => $isBn ? 'ইউজার রিসেট' : 'User Reset', 'bg' => '#8957e5', 'icon' => 'fa-retweet');
        case 'hotspot_bulk_reset_mac':
            return array('label' => $isBn ? 'বাল্ক MAC রিসেট' : 'Bulk MAC Reset', 'bg' => '#8957e5', 'icon' => 'fa-refresh');
        case 'hotspot_user_enabled':
            return array('label' => $isBn ? 'ইউজার এনাবল' : 'User Enabled', 'bg' => '#2ea043', 'icon' => 'fa-unlock');
        case 'hotspot_user_disabled':
            return array('label' => $isBn ? 'ইউজার ডিজেবল' : 'User Disabled', 'bg' => '#d29922', 'icon' => 'fa-lock');
        case 'hotspot_voucher_printed':
            return array('label' => $isBn ? 'ভাউচার প্রিন্ট' : 'Voucher Printed', 'bg' => '#0969da', 'icon' => 'fa-print');
        case 'hotspot_users_exported':
            return array('label' => $isBn ? 'ইউজার এক্সপোর্ট' : 'Users Exported', 'bg' => '#0969da', 'icon' => 'fa-download');
        case 'hotspot_users_transferred':
            return array('label' => $isBn ? 'ইউজার ট্রান্সফার' : 'User Transfer', 'bg' => '#8957e5', 'icon' => 'fa-exchange');
        case 'hotspot_active_disconnected':
            return array('label' => $isBn ? 'অ্যাক্টিভ ডিসকানেক্ট' : 'User Disconnected', 'bg' => '#d29922', 'icon' => 'fa-chain-broken');
        case 'hotspot_host_removed':
            return array('label' => $isBn ? 'হোস্ট রিমুভ' : 'Host Removed', 'bg' => '#6e7681', 'icon' => 'fa-laptop');
        case 'hotspot_cookie_removed':
            return array('label' => $isBn ? 'কুকি রিমুভ' : 'Cookie Removed', 'bg' => '#6e7681', 'icon' => 'fa-hourglass-end');
        case 'hotspot_ipbinding_changed':
            return array('label' => $isBn ? 'IP বাইন্ডিং পরিবর্তন' : 'IP Binding', 'bg' => '#1f6feb', 'icon' => 'fa-address-book');
        case 'hotspot_scheduler_changed':
            return array('label' => $isBn ? 'শিডিউলার পরিবর্তন' : 'Scheduler', 'bg' => '#1f6feb', 'icon' => 'fa-clock-o');
        case 'hotspot_profile_created':
            return array('label' => $isBn ? 'প্রোফাইল তৈরি' : 'Profile Created', 'bg' => '#2ea043', 'icon' => 'fa-pie-chart');
        case 'hotspot_profile_updated':
            return array('label' => $isBn ? 'প্রোফাইল আপডেট' : 'Profile Updated', 'bg' => '#1f6feb', 'icon' => 'fa-pie-chart');
        case 'hotspot_profile_deleted':
            return array('label' => $isBn ? 'প্রোফাইল ডিলিট' : 'Profile Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'hotspot_profile_transfer':
            return array('label' => $isBn ? 'প্রোফাইল ট্রান্সফার' : 'Profile Transfer', 'bg' => '#8957e5', 'icon' => 'fa-exchange');
        case 'template_saved':
            return array('label' => $isBn ? 'টেমপ্লেট সেভ' : 'Template Saved', 'bg' => '#1f6feb', 'icon' => 'fa-code');
        case 'template_reset':
            return array('label' => $isBn ? 'টেমপ্লেট রিসেট' : 'Template Reset', 'bg' => '#d29922', 'icon' => 'fa-undo');
        case 'logo_uploaded':
            return array('label' => $isBn ? 'লোগো আপলোড' : 'Logo Uploaded', 'bg' => '#2ea043', 'icon' => 'fa-image');
        case 'logo_removed':
            return array('label' => $isBn ? 'লোগো রিমুভ' : 'Logo Removed', 'bg' => '#6e7681', 'icon' => 'fa-image');
        case 'reseller_created':
            return array('label' => $isBn ? 'রিসেলার যুক্ত' : 'Reseller Added', 'bg' => '#2ea043', 'icon' => 'fa-users');
        case 'reseller_updated':
            return array('label' => $isBn ? 'রিসেলার আপডেট' : 'Reseller Edited', 'bg' => '#1f6feb', 'icon' => 'fa-edit');
        case 'reseller_deleted':
            return array('label' => $isBn ? 'রিসেলার ডিলিট' : 'Reseller Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'reseller_status_changed':
            return array('label' => $isBn ? 'রিসেলার স্ট্যাটাস' : 'Status Changed', 'bg' => '#d29922', 'icon' => 'fa-exchange');
        case 'reseller_impersonation':
            return array('label' => $isBn ? 'রিসেলার সুইচ' : 'Reseller Login', 'bg' => '#8957e5', 'icon' => 'fa-user-secret');
        case 'admin_user_created':
            return array('label' => $isBn ? 'অ্যাডমিন যুক্ত' : 'Admin Added', 'bg' => '#2ea043', 'icon' => 'fa-user-plus');
        case 'admin_user_updated':
            return array('label' => $isBn ? 'অ্যাডমিন আপডেট' : 'Admin Updated', 'bg' => '#1f6feb', 'icon' => 'fa-user');
        case 'admin_user_deleted':
            return array('label' => $isBn ? 'অ্যাডমিন ডিলিট' : 'Admin Deleted', 'bg' => '#f85149', 'icon' => 'fa-trash');
        case 'profile_updated':
            return array('label' => $isBn ? 'প্রোফাইল/পাসওয়ার্ড আপডেট' : 'Profile Updated', 'bg' => '#1f6feb', 'icon' => 'fa-key');
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
.ngw-act-filterbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
  padding: 6px 8px;
  border-radius: 4px;
  background: rgba(128, 128, 128, 0.08);
  border: 1px solid rgba(128, 128, 128, 0.16);
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
  background: rgba(128, 128, 128, 0.12);
  border: 1px solid rgba(128, 128, 128, 0.24);
  color: inherit;
  box-sizing: border-box;
}
.ngw-act-tab:hover {
  background: rgba(128, 128, 128, 0.22);
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
.ngw-act-date-input,
.ngw-act-select,
.ngw-act-search {
  height: 24px;
  padding: 1px 6px;
  font-size: 11px;
  border-radius: 3px;
  border: 1px solid rgba(128, 128, 128, 0.3);
  background: rgba(0, 0, 0, 0.14);
  color: inherit;
  box-sizing: border-box;
}
.ngw-act-search {
  width: 155px;
}
.ngw-act-mini-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  height: 24px;
  padding: 0 8px;
  font-size: 11px;
  font-weight: 600;
  border-radius: 3px;
  border: 1px solid rgba(128, 128, 128, 0.28);
  background: rgba(128, 128, 128, 0.12);
  color: inherit;
  text-decoration: none !important;
  cursor: pointer;
  box-sizing: border-box;
}
.ngw-act-mini-btn:hover:not(.is-disabled) {
  background: rgba(128, 128, 128, 0.24);
}
.ngw-act-mini-btn.is-active {
  background: #2ea043;
  border-color: #2ea043;
  color: #fff !important;
}
.ngw-act-mini-btn.is-disabled {
  opacity: 0.45;
  pointer-events: none;
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
.ngw-act-fault-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 700;
  background: rgba(248, 81, 73, 0.18);
  border: 1px solid rgba(248, 81, 73, 0.48);
  color: #ff7b72;
  margin-right: 5px;
}
.ngw-act-err-box {
  color: #ff7b72;
  font-weight: 600;
}
.ngw-act-pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
  font-size: 11px;
}
.ngw-show-xs {
  display: none;
}
.ngw-col-time { width: 138px; white-space: nowrap; font-size: 12px; }
.ngw-col-user { width: 120px; }
.ngw-col-role { width: 78px; }
.ngw-col-act  { width: 145px; }
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
  .ngw-act-date-input,
  .ngw-act-select {
    height: 22px;
    padding: 1px 3px;
    font-size: 10px;
    max-width: 112px;
  }
  .ngw-act-search {
    width: 115px;
    height: 22px;
    font-size: 10px;
  }
  .ngw-act-mini-btn {
    height: 22px;
    padding: 0 6px;
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
          <small style="font-size:11px; opacity:0.85; font-weight:normal; margin-left:6px;">
            <?php if ($viewMode === 'day') { ?>
              (<?= htmlspecialchars($validSingleDate, ENT_QUOTES, 'UTF-8'); ?> &middot; <?= (int)$totalMatched; ?>)
            <?php } else { ?>
              (<?= $validFrom !== '' || $validTo !== '' ? htmlspecialchars(($minDate !== '' ? $minDate : '...') . ' → ' . ($maxDate !== '' ? $maxDate : '...'), ENT_QUOTES, 'UTF-8') : ($isBn ? 'সব তারিখ' : 'All Dates'); ?> &middot; <?= (int)$totalMatched; ?>)
            <?php } ?>
          </small>
        </h3>
        <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./admin.php?id=active-sessions"><i class="fa fa-desktop"></i> <?= $isBn ? 'অ্যাক্টিভ সেশন' : 'Active Sessions'; ?></a>
          <?php if ($isStoreAdmin && !empty($allEvents)) { ?>
          <form method="post" action="./admin.php?id=activity" style="margin:0; display:inline;" onsubmit="return confirm('<?= $isBn ? 'সব অ্যাক্টিভিটি হিস্টরি মুছে ফেলতে চান?' : 'Clear all recorded activity history?'; ?>');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($actCsrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="clear_activity_log" value="1">
            <button type="submit" class="btn bg-danger pd-2p5 radius-3" style="cursor:pointer; font-size:11px;"><i class="fa fa-trash"></i> <?= $isBn ? 'মুছে ফেলুন' : 'Clear'; ?></button>
          </form>
          <?php } ?>
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="<?= htmlspecialchars(ngwBuildActUrl(), ENT_QUOTES, 'UTF-8'); ?>" title="Refresh"><i class="fa fa-refresh"></i></a>
        </div>
      </div>

      <div class="card-body">
        <?php if ($actMsg !== '') { ?>
          <div class="bg-green pd-5 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= htmlspecialchars($actMsg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>

        <!-- Row 1: Category Filter Tabs & Single-Day Stepper / Custom Date Selector -->
        <div class="ngw-act-toolbar">
          <div class="ngw-act-tabs">
            <a href="<?= htmlspecialchars(ngwBuildActUrl(array('filter' => 'all', 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-tab <?= $filter === 'all' ? 'is-active' : ''; ?>">
              <?= $isBn ? 'সব' : 'All'; ?> (<?= (int)$counts['all']; ?>)
            </a>
            <a href="<?= htmlspecialchars(ngwBuildActUrl(array('filter' => 'users_vouchers', 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-tab <?= $filter === 'users_vouchers' ? 'is-active' : ''; ?>">
              <i class="fa fa-ticket"></i> <?= $isBn ? 'ভাউচার ও ইউজার' : 'Vouchers & Users'; ?> (<?= (int)$counts['users_vouchers']; ?>)
            </a>
            <a href="<?= htmlspecialchars(ngwBuildActUrl(array('filter' => 'profiles_system', 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-tab <?= $filter === 'profiles_system' ? 'is-active' : ''; ?>">
              <i class="fa fa-sliders"></i> <?= $isBn ? 'প্রোফাইল ও সেটিংস' : 'Profiles & System'; ?> (<?= (int)$counts['profiles_system']; ?>)
            </a>
            <a href="<?= htmlspecialchars(ngwBuildActUrl(array('filter' => 'auth', 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-tab <?= $filter === 'auth' ? 'is-active' : ''; ?>">
              <i class="fa fa-sign-in"></i> <?= $isBn ? 'লগইন/লগআউট' : 'Login/Logout'; ?> (<?= (int)$counts['auth']; ?>)
            </a>
            <a href="<?= htmlspecialchars(ngwBuildActUrl(array('filter' => 'login_failed', 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-tab <?= $filter === 'login_failed' ? 'is-active' : ''; ?>">
              <i class="fa fa-warning text-danger"></i> <?= $isBn ? 'লগইন ব্যর্থ' : 'Failed Login'; ?> (<?= (int)$counts['login_failed']; ?>)
            </a>
            <a href="<?= htmlspecialchars(ngwBuildActUrl(array('filter' => 'router_error', 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-tab <?= $filter === 'router_error' ? 'is-active' : ''; ?>">
              <i class="fa fa-plug text-warning"></i> <?= $isBn ? 'রাউটার ও ত্রুটি' : 'Router Open/Error'; ?> (<?= (int)$counts['router_error']; ?>)
            </a>
          </div>

          <!-- Single-Day Stepper (Default) + Switch to Custom Date Range -->
          <div class="ngw-act-datebar">
            <?php if ($viewMode === 'day') { ?>
              <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'day', 'date' => $prevDayDate, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn" title="<?= $isBn ? 'আগের দিন দেখুন' : 'Previous Day'; ?>">
                <i class="fa fa-chevron-left"></i> <?= $isBn ? 'আগের দিন' : 'Prev'; ?>
              </a>
              <form method="get" action="./admin.php" style="margin:0; display:inline-flex; align-items:center; gap:3px;">
                <input type="hidden" name="id" value="activity">
                <input type="hidden" name="mode" value="day">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($roleFilter !== 'all') { ?><input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <?php if ($actorFilter !== '') { ?><input type="hidden" name="actor" value="<?= htmlspecialchars($actorFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <?php if ($searchQuery !== '') { ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <input type="date" name="date" value="<?= htmlspecialchars($validSingleDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?= htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-date-input" onchange="this.form.submit()" title="<?= $isBn ? 'এক দিনের তারিখ বাছাই করুন' : 'Select Single Day'; ?>">
              </form>
              <a href="<?= $canGoNextDay ? htmlspecialchars(ngwBuildActUrl(array('mode' => 'day', 'date' => $nextDayDate, 'page' => 1)), ENT_QUOTES, 'UTF-8') : 'javascript:void(0)'; ?>" class="ngw-act-mini-btn <?= $canGoNextDay ? '' : 'is-disabled'; ?>" title="<?= $isBn ? 'পরের দিন দেখুন' : 'Next Day'; ?>">
                <?= $isBn ? 'পরের দিন' : 'Next'; ?> <i class="fa fa-chevron-right"></i>
              </a>
              <?php if ($validSingleDate !== $todayDate) { ?>
              <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'day', 'date' => $todayDate, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn is-active" title="<?= $isBn ? 'আজকের হিস্টরি' : 'Today'; ?>">
                <?= $isBn ? 'আজ' : 'Today'; ?>
              </a>
              <?php } ?>
              <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'range', 'from' => date('Y-m-d', strtotime('-7 days')), 'to' => $todayDate, 'date' => null, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn" title="<?= $isBn ? 'ব্যাকএন্ডের কাস্টম তারিখ অনুযায়ী দেখুন' : 'Search custom backend date range'; ?>">
                <i class="fa fa-calendar"></i> <?= $isBn ? 'কাস্টম তারিখ' : 'Custom Date'; ?>
              </a>
            <?php } else { ?>
              <form method="get" action="./admin.php" class="ngw-act-datebar">
                <input type="hidden" name="id" value="activity">
                <input type="hidden" name="mode" value="range">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($roleFilter !== 'all') { ?><input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <?php if ($actorFilter !== '') { ?><input type="hidden" name="actor" value="<?= htmlspecialchars($actorFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <?php if ($searchQuery !== '') { ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'range', 'range' => '15d', 'from' => null, 'to' => null, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn <?= $rangePreset === '15d' ? 'is-active' : ''; ?>" title="Last 15 Days">15D</a>
                <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'range', 'range' => '1m', 'from' => null, 'to' => null, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn <?= ($rangePreset === '1m' || $rangePreset === '30d') ? 'is-active' : ''; ?>" title="Last 1 Month">1M</a>
                <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'range', 'range' => 'all', 'from' => null, 'to' => null, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn <?= $rangePreset === 'all' ? 'is-active' : ''; ?>" title="All Recorded History"><?= $isBn ? 'সব তারিখ' : 'All'; ?></a>
                <input type="date" name="from" value="<?= htmlspecialchars($validFrom, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-date-input" title="From Date">
                <span style="font-size:10px; opacity:0.7;"><?= $isBn ? 'থেকে' : 'to'; ?></span>
                <input type="date" name="to" value="<?= htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-date-input" title="To Date">
                <button type="submit" class="btn bg-primary pd-2p5 radius-3" style="height:24px; font-size:10px; line-height:1; cursor:pointer; margin:0;">
                  <i class="fa fa-filter"></i> <?= $isBn ? 'দেখুন' : 'Apply'; ?>
                </button>
                <a href="<?= htmlspecialchars(ngwBuildActUrl(array('mode' => 'day', 'date' => $todayDate, 'range' => null, 'from' => null, 'to' => null, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn is-active" title="<?= $isBn ? '১ দিনের ভিউতে ফিরুন' : 'Back to 1-Day View'; ?>">
                  <i class="fa fa-calendar-check-o"></i> <?= $isBn ? '১ দিনের ভিউ' : '1-Day View'; ?>
                </a>
              </form>
            <?php } ?>
          </div>
        </div>

        <!-- Row 2: Role, User/Reseller, and Keyword Filtering -->
        <form method="get" action="./admin.php" class="ngw-act-filterbar">
          <input type="hidden" name="id" value="activity">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8'); ?>">
          <?php if ($viewMode === 'day') { ?>
            <input type="hidden" name="date" value="<?= htmlspecialchars($validSingleDate, ENT_QUOTES, 'UTF-8'); ?>">
          <?php } else { ?>
            <?php if ($rangePreset !== '') { ?><input type="hidden" name="range" value="<?= htmlspecialchars($rangePreset, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
            <?php if ($validFrom !== '') { ?><input type="hidden" name="from" value="<?= htmlspecialchars($validFrom, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
            <?php if ($validTo !== '') { ?><input type="hidden" name="to" value="<?= htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
          <?php } ?>

          <div style="display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
            <select name="role" class="ngw-act-select" onchange="this.form.submit()" title="<?= $isBn ? 'রোল ফিল্টার' : 'Filter by Role'; ?>">
              <option value="all" <?= $roleFilter === 'all' ? 'selected' : ''; ?>><?= $isBn ? 'সব রোল (Admin+Reseller)' : 'All Roles (Admin + Reseller)'; ?></option>
              <option value="reseller" <?= $roleFilter === 'reseller' ? 'selected' : ''; ?>><?= $isBn ? 'শুধু রিসেলার' : 'Reseller Only'; ?></option>
              <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : ''; ?>><?= $isBn ? 'শুধু অ্যাডমিন' : 'Admin Only'; ?></option>
            </select>

            <select name="actor" class="ngw-act-select" onchange="this.form.submit()" title="<?= $isBn ? 'নির্দিষ্ট ইউজার/রিসেলার বাছাই করুন' : 'Filter by Specific User / Reseller'; ?>">
              <option value=""><?= $isBn ? 'সব ইউজার / রিসেলার' : 'All Users / Resellers'; ?></option>
              <?php foreach ($actorNames as $aName) { ?>
                <option value="<?= htmlspecialchars($aName, ENT_QUOTES, 'UTF-8'); ?>" <?= strcasecmp($actorFilter, $aName) === 0 ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($aName, ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php } ?>
            </select>

            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-search" placeholder="<?= $isBn ? 'ভাউচার, রাউটার, ত্রুটি খুঁজুন...' : 'Search voucher, router, fault...'; ?>">
            <button type="submit" class="ngw-act-mini-btn"><i class="fa fa-search"></i> <?= $isBn ? 'খুঁজুন' : 'Search'; ?></button>
            <?php if ($roleFilter !== 'all' || $actorFilter !== '' || $searchQuery !== '') { ?>
              <a href="<?= htmlspecialchars(ngwBuildActUrl(array('role' => 'all', 'actor' => null, 'q' => null, 'page' => 1)), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-act-mini-btn" title="<?= $isBn ? 'ফিল্টার রিসেট' : 'Reset Filters'; ?>">
                <i class="fa fa-times text-danger"></i> <?= $isBn ? 'রিসেট' : 'Reset'; ?>
              </a>
            <?php } ?>
          </div>

          <div style="font-size:11px; opacity:0.85;">
            <?php if ($viewMode === 'day') { ?>
              <i class="fa fa-calendar-o"></i> <?= $isBn ? '১ দিনের হিস্টরি:' : 'Showing 1 Day:'; ?> <b><?= htmlspecialchars($validSingleDate, ENT_QUOTES, 'UTF-8'); ?></b>
            <?php } else { ?>
              <i class="fa fa-calendar"></i> <?= $isBn ? 'কাস্টম হিস্টরি' : 'Custom Backend History'; ?>
            <?php } ?>
          </div>
        </form>

        <div class="overflow box-bordered" style="max-height:66vh;">
          <table class="table table-sm table-bordered table-hover">
            <thead>
              <tr>
                <th class="ngw-col-time"><?= $isBn ? 'সময়' : 'Time'; ?></th>
                <th class="ngw-col-user"><?= $isBn ? 'ইউজার' : 'User'; ?></th>
                <th class="ngw-col-role ngw-hide-xs"><?= $isBn ? 'রোল' : 'Role'; ?></th>
                <th class="ngw-col-act"><?= $isBn ? 'কার্যক্রম' : 'Activity'; ?></th>
                <th class="ngw-col-dev ngw-hide-xs"><?= $isBn ? 'ডিভাইস ও আইপি' : 'Device &amp; IP'; ?></th>
                <th><?= $isBn ? 'বিস্তারিত / নির্দিষ্ট ফল্ট' : 'Details / Fault Diagnosis'; ?></th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($events)) { ?>
              <tr><td colspan="6" class="text-center pd-10">
                <?= $isBn
                  ? ($viewMode === 'day'
                      ? ('এই তারিখে (' . htmlspecialchars($validSingleDate, ENT_QUOTES, 'UTF-8') . ') কোনো অ্যাক্টিভিটি পাওয়া যায়নি। আগের দিন বা কাস্টম তারিখ ব্যবহার করে দেখুন।')
                      : 'নির্বাচিত ফিল্টার বা তারিখের মধ্যে কোনো অ্যাক্টিভিটি পাওয়া যায়নি।')
                  : ($viewMode === 'day'
                      ? ('No activity recorded on ' . htmlspecialchars($validSingleDate, ENT_QUOTES, 'UTF-8') . '. Use Prev Day or Custom Date to inspect backend history.')
                      : 'No activity events found for the selected filter/date range.'); ?>
              </td></tr>
            <?php } else {
              foreach ($events as $event) {
                $action = isset($event['action']) ? (string)$event['action'] : '';
                $badge = ngwFormatActivityBadge($action);
                $det = isset($event['details']) && is_array($event['details']) ? $event['details'] : array();
                $ipVal = isset($det['ip']) ? (string)$det['ip'] : '-';
                $devVal = isset($det['device']) ? (string)$det['device'] : '-';

                $detailParts = array();
                if (!empty($det['fault'])) {
                  $detailParts[] = '<span class="ngw-act-fault-tag"><i class="fa fa-bolt"></i> ' . htmlspecialchars((string)$det['fault'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
                if (!empty($det['error'])) {
                  $detailParts[] = '<span class="ngw-act-err-box"><i class="fa fa-warning"></i> ' . htmlspecialchars((string)$det['error'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
                if (!empty($det['router'])) {
                  $detailParts[] = '<b>Router:</b> ' . htmlspecialchars((string)$det['router'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['host'])) {
                  $detailParts[] = '<b>Host/IP:</b> <code>' . htmlspecialchars((string)$det['host'], ENT_QUOTES, 'UTF-8') . '</code>';
                }
                if (!empty($det['user'])) {
                  $detailParts[] = '<b>API User:</b> <code>' . htmlspecialchars((string)$det['user'], ENT_QUOTES, 'UTF-8') . '</code>';
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
                if (!empty($det['detail'])) {
                  $detailParts[] = htmlspecialchars((string)$det['detail'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($det['area'])) {
                  $detailParts[] = '<b>Area:</b> ' . htmlspecialchars((string)$det['area'], ENT_QUOTES, 'UTF-8');
                }
                if (empty($detailParts)) {
                  if ($action === 'login') {
                    $detailParts[] = '<span class="text-green">' . ($isBn ? 'সফলভাবে লগইন করেছেন' : 'Logged in successfully') . '</span>';
                  } elseif ($action === 'logout') {
                    $detailParts[] = $isBn ? 'সেশন থেকে লগআউট করেছেন' : 'Logged out of session';
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

        <!-- Pagination + Day Quick Footer -->
        <div class="ngw-act-pager">
          <div>
            <?= $isBn
              ? ('মোট <b>' . (int)$totalMatched . '</b> টি এন্ট্রি (পেজ <b>' . (int)$page . '</b> / ' . (int)$totalPages . ')')
              : ('Total <b>' . (int)$totalMatched . '</b> entries (Page <b>' . (int)$page . '</b> / ' . (int)$totalPages . ')'); ?>
          </div>
          <div style="display:flex; align-items:center; gap:4px;">
            <?php if ($totalPages > 1) { ?>
              <a href="<?= $page > 1 ? htmlspecialchars(ngwBuildActUrl(array('page' => $page - 1)), ENT_QUOTES, 'UTF-8') : 'javascript:void(0)'; ?>" class="ngw-act-mini-btn <?= $page > 1 ? '' : 'is-disabled'; ?>">
                <i class="fa fa-angle-left"></i> <?= $isBn ? 'পূর্ববর্তী' : 'Prev Page'; ?>
              </a>
              <a href="<?= $page < $totalPages ? htmlspecialchars(ngwBuildActUrl(array('page' => $page + 1)), ENT_QUOTES, 'UTF-8') : 'javascript:void(0)'; ?>" class="ngw-act-mini-btn <?= $page < $totalPages ? '' : 'is-disabled'; ?>">
                <?= $isBn ? 'পরবর্তী' : 'Next Page'; ?> <i class="fa fa-angle-right"></i>
              </a>
            <?php } ?>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>