<?php
/**
 * NextGen WiFi Solution — Multi-Tier Admin Store & License Quota Manager
 *
 * Implements 3-Tier Hierarchy:
 *   1. Main Admin Store (Super Admin — adm_1)
 *   2. Licensed Sub-Admin (Quota-managed Admin with Router, Voucher, Active User, Reseller & Expiry limits)
 *   3. Reseller (Scoped under their parent Admin and assigned Routers)
 */

if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -19) === "license_manager.php") {
    header("Location:./");
    exit;
}

if (!function_exists('ngwGetRouterOwnersStoragePath')) {
    function ngwGetRouterOwnersStoragePath() {
        return __DIR__ . '/router_owners.json';
    }
}

if (!function_exists('ngwGetLicenseUsageStoragePath')) {
    function ngwGetLicenseUsageStoragePath() {
        return __DIR__ . '/license_usage.json';
    }
}

if (!function_exists('ngwNormalizeAdminLicenseRecord')) {
    /**
     * Normalize an admin user record with default License & Store fields
     */
    function ngwNormalizeAdminLicenseRecord($row, $index = 0) {
        if (!is_array($row)) {
            $row = array();
        }
        $id = !empty($row['id']) ? (string)$row['id'] : ('adm_' . ($index + 1));
        $isPrimary = ($id === 'adm_1' || $index === 0);
        $tier = isset($row['admin_tier']) && in_array($row['admin_tier'], array('super_admin', 'licensed_admin'), true)
            ? (string)$row['admin_tier']
            : ($isPrimary ? 'super_admin' : 'licensed_admin');
        if ($id === 'adm_1') {
            $tier = 'super_admin';
        }
        $status = isset($row['status']) && in_array($row['status'], array('active', 'suspended'), true)
            ? (string)$row['status']
            : 'active';
        if ($tier === 'super_admin') {
            $status = 'active';
        }

        $expiresAt = isset($row['license_expires_at']) ? trim((string)$row['license_expires_at']) : '';
        if ($tier === 'super_admin') {
            $expiresAt = '';
        }

        return array(
            'id'                   => $id,
            'name'                 => !empty($row['name']) ? trim((string)$row['name']) : (!empty($row['username']) ? trim((string)$row['username']) : 'Administrator'),
            'username'             => !empty($row['username']) ? trim((string)$row['username']) : '',
            'password_enc'         => isset($row['password_enc']) ? (string)$row['password_enc'] : '',
            'password_hash'        => isset($row['password_hash']) ? (string)$row['password_hash'] : '',
            'avatar'               => isset($row['avatar']) ? (string)$row['avatar'] : '',
            'admin_tier'           => $tier,
            'status'               => $status,
            'license_expires_at'   => $expiresAt,
            'max_routers'          => ($tier === 'super_admin') ? 0 : max(0, (int)(isset($row['max_routers']) ? $row['max_routers'] : 0)),
            'max_total_users'      => ($tier === 'super_admin') ? 0 : max(0, (int)(isset($row['max_total_users']) ? $row['max_total_users'] : 0)),
            'max_batch_qty'        => ($tier === 'super_admin') ? 0 : max(0, (int)(isset($row['max_batch_qty']) ? $row['max_batch_qty'] : 0)),
            'max_monthly_vouchers' => ($tier === 'super_admin') ? 0 : max(0, (int)(isset($row['max_monthly_vouchers']) ? $row['max_monthly_vouchers'] : 0)),
            'max_active_users'     => ($tier === 'super_admin') ? 0 : max(0, (int)(isset($row['max_active_users']) ? $row['max_active_users'] : 0)),
            'max_resellers'        => ($tier === 'super_admin') ? 0 : max(0, (int)(isset($row['max_resellers']) ? $row['max_resellers'] : 0)),
            'phone'                => isset($row['phone']) ? trim((string)$row['phone']) : '',
            'notes'                => isset($row['notes']) ? trim((string)$row['notes']) : '',
            'created_at'           => !empty($row['created_at']) ? (string)$row['created_at'] : date('Y-m-d H:i:s'),
            'updated_at'           => !empty($row['updated_at']) ? (string)$row['updated_at'] : date('Y-m-d H:i:s'),
        );
    }
}

if (!function_exists('isSuperAdminStore')) {
    /**
     * Check if the currently logged-in user is the Main Admin Store (Super Admin)
     */
    function isSuperAdminStore() {
        if (!function_exists('isMikhmonAdmin') || !isMikhmonAdmin()) {
            return false;
        }
        $adm = function_exists('getCurrentAdminRecord') ? getCurrentAdminRecord() : null;
        if (!is_array($adm)) {
            return true;
        }
        if (isset($adm['id']) && (string)$adm['id'] === 'adm_1') {
            return true;
        }
        if (isset($adm['admin_tier']) && (string)$adm['admin_tier'] === 'super_admin') {
            return true;
        }
        return false;
    }
}

if (!function_exists('isLicensedSubAdmin')) {
    /**
     * Check if the currently logged-in user is a Licensed Sub-Admin
     */
    function isLicensedSubAdmin() {
        if (!function_exists('isMikhmonAdmin') || !isMikhmonAdmin()) {
            return false;
        }
        return !isSuperAdminStore();
    }
}

if (!function_exists('ngwIsAdminLicenseExpired')) {
    /**
     * Check if an admin record's license is expired by date
     */
    function ngwIsAdminLicenseExpired($adminRecord) {
        if (!is_array($adminRecord)) {
            return false;
        }
        if (isset($adminRecord['admin_tier']) && $adminRecord['admin_tier'] === 'super_admin') {
            return false;
        }
        if (isset($adminRecord['id']) && (string)$adminRecord['id'] === 'adm_1') {
            return false;
        }
        $exp = isset($adminRecord['license_expires_at']) ? trim((string)$adminRecord['license_expires_at']) : '';
        if ($exp === '') {
            return false;
        }
        $today = date('Y-m-d');
        return ($exp < $today);
    }
}

if (!function_exists('ngwIsAdminAccountLocked')) {
    /**
     * Check if an admin account is suspended or license-expired
     */
    function ngwIsAdminAccountLocked($adminRecord, &$reasonCode = null) {
        $reasonCode = '';
        if (!is_array($adminRecord)) {
            return false;
        }
        if ((isset($adminRecord['admin_tier']) && $adminRecord['admin_tier'] === 'super_admin') ||
            (isset($adminRecord['id']) && (string)$adminRecord['id'] === 'adm_1')) {
            return false;
        }
        if (isset($adminRecord['status']) && $adminRecord['status'] === 'suspended') {
            $reasonCode = 'suspended';
            return true;
        }
        if (ngwIsAdminLicenseExpired($adminRecord)) {
            $reasonCode = 'expired';
            return true;
        }
        return false;
    }
}

if (!function_exists('ngwGetLicenseDaysRemaining')) {
    /**
     * Return number of days remaining on license, or null if lifetime
     */
    function ngwGetLicenseDaysRemaining($adminRecord) {
        if (!is_array($adminRecord)) {
            return null;
        }
        $exp = isset($adminRecord['license_expires_at']) ? trim((string)$adminRecord['license_expires_at']) : '';
        if ($exp === '' || (isset($adminRecord['admin_tier']) && $adminRecord['admin_tier'] === 'super_admin')) {
            return null;
        }
        $expTs = strtotime($exp . ' 23:59:59');
        if ($expTs === false) {
            return null;
        }
        $diffDays = (int)floor(($expTs - time()) / 86400);
        return $diffDays;
    }
}

if (!function_exists('ngwLoadRouterOwners')) {
    /**
     * Load router ownership map: sessionName => array(owner_admin_id, owner_admin_username, created_by_role, created_by_username, updated_at)
     */
    function ngwLoadRouterOwners($forceReload = false) {
        if (!$forceReload && isset($GLOBALS['NGW_CACHE_ROUTER_OWNERS']) && is_array($GLOBALS['NGW_CACHE_ROUTER_OWNERS'])) {
            return $GLOBALS['NGW_CACHE_ROUTER_OWNERS'];
        }
        $path = ngwGetRouterOwnersStoragePath();
        if (!is_file($path)) {
            $GLOBALS['NGW_CACHE_ROUTER_OWNERS'] = array();
            return array();
        }
        $raw = @file_get_contents($path);
        $decoded = @json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }
        $GLOBALS['NGW_CACHE_ROUTER_OWNERS'] = $decoded;
        return $decoded;
    }
}

if (!function_exists('ngwSaveRouterOwners')) {
    /**
     * Save router ownership map to include/router_owners.json
     */
    function ngwSaveRouterOwners($ownersMap) {
        if (!is_array($ownersMap)) {
            $ownersMap = array();
        }
        $path = ngwGetRouterOwnersStoragePath();
        $ok = @file_put_contents($path, json_encode($ownersMap, JSON_PRETTY_PRINT), LOCK_EX) !== false;
        if ($ok) {
            $GLOBALS['NGW_CACHE_ROUTER_OWNERS'] = $ownersMap;
        }
        return $ok;
    }
}

if (!function_exists('ngwAssignRouterOwner')) {
    /**
     * Assign or update the owner Admin of a router session
     */
    function ngwAssignRouterOwner($sessionName, $ownerAdminId = '', $ownerAdminUsername = '', $createdByRole = '', $createdByUsername = '') {
        $sessionName = trim((string)$sessionName);
        if ($sessionName === '' || $sessionName === 'mikhmon' || strpos($sessionName, 'new-') === 0) {
            return false;
        }
        $owners = ngwLoadRouterOwners(true);
        if ($ownerAdminId === '') {
            if (isMikhmonAdmin()) {
                $adm = getCurrentAdminRecord();
                $ownerAdminId = is_array($adm) && !empty($adm['id']) ? (string)$adm['id'] : 'adm_1';
                $ownerAdminUsername = is_array($adm) && !empty($adm['username']) ? (string)$adm['username'] : (string)$_SESSION['mikhmon'];
                $createdByRole = isSuperAdminStore() ? 'super_admin' : 'licensed_admin';
                $createdByUsername = $ownerAdminUsername;
            } else {
                $res = getCurrentResellerRecord();
                $ownerAdminId = is_array($res) && !empty($res['parent_admin_id']) ? (string)$res['parent_admin_id'] : 'adm_1';
                $parentAdm = findAdminUserByIdOrUsername($ownerAdminId);
                $ownerAdminUsername = is_array($parentAdm) && !empty($parentAdm['username']) ? (string)$parentAdm['username'] : 'admin';
                $createdByRole = 'reseller';
                $createdByUsername = is_array($res) && !empty($res['username']) ? (string)$res['username'] : (string)$_SESSION['mikhmon'];
            }
        } elseif ($ownerAdminUsername === '') {
            $adm = findAdminUserByIdOrUsername($ownerAdminId);
            $ownerAdminUsername = is_array($adm) && !empty($adm['username']) ? (string)$adm['username'] : $ownerAdminId;
        }

        $owners[$sessionName] = array(
            'owner_admin_id'       => (string)$ownerAdminId,
            'owner_admin_username' => (string)$ownerAdminUsername,
            'created_by_role'      => $createdByRole !== '' ? (string)$createdByRole : 'admin',
            'created_by_username'  => $createdByUsername !== '' ? (string)$createdByUsername : (string)$ownerAdminUsername,
            'updated_at'           => date('Y-m-d H:i:s'),
        );
        return ngwSaveRouterOwners($owners);
    }
}

if (!function_exists('ngwRenameRouterOwnerSession')) {
    /**
     * Move router ownership entry when a router session is renamed in settings.php,
     * or initialize it for the current Admin/Reseller if not yet recorded.
     */
    function ngwRenameRouterOwnerSession($oldSession, $newSession) {
        $oldSession = trim((string)$oldSession);
        $newSession = trim((string)$newSession);
        if ($newSession === '' || $newSession === 'mikhmon') {
            return false;
        }
        $owners = ngwLoadRouterOwners(true);
        if ($oldSession !== '' && $oldSession !== $newSession && isset($owners[$oldSession])) {
            $entry = $owners[$oldSession];
            unset($owners[$oldSession]);
            $entry['updated_at'] = date('Y-m-d H:i:s');
            $owners[$newSession] = $entry;
            ngwSaveRouterOwners($owners);
        } elseif (!isset($owners[$newSession])) {
            ngwAssignRouterOwner($newSession);
        }

        // Also update reseller allowed_routers if a reseller or sub-admin created/renamed this router
        if ($oldSession !== '' && $oldSession !== $newSession) {
            $resellers = loadResellers(true);
            $changedRes = false;
            foreach ($resellers as $idx => $r) {
                if (isset($r['allowed_routers']) && is_array($r['allowed_routers'])) {
                    $pos = array_search($oldSession, $r['allowed_routers'], true);
                    if ($pos !== false) {
                        $resellers[$idx]['allowed_routers'][$pos] = $newSession;
                        $changedRes = true;
                    }
                }
            }
            if ($changedRes) {
                saveResellers($resellers);
            }
        }

        // If a Reseller just created a new router, ensure it's in their allowed_routers
        if (!isMikhmonAdmin() && strpos($oldSession, 'new-') === 0) {
            $res = getCurrentResellerRecord();
            if (is_array($res) && !empty($res['id'])) {
                $resellers = loadResellers(true);
                foreach ($resellers as $idx => $r) {
                    if ((string)$r['id'] === (string)$res['id']) {
                        $allowed = isset($r['allowed_routers']) && is_array($r['allowed_routers']) ? $r['allowed_routers'] : array();
                        if (!in_array($newSession, $allowed, true)) {
                            $allowed[] = $newSession;
                            $resellers[$idx]['allowed_routers'] = array_values($allowed);
                            saveResellers($resellers);
                            $_SESSION['mikhmon_routers'] = $resellers[$idx]['allowed_routers'];
                        }
                        break;
                    }
                }
            }
        }
        return true;
    }
}

if (!function_exists('ngwRemoveRouterOwnerSession')) {
    /**
     * Remove router ownership metadata when a router session is deleted
     */
    function ngwRemoveRouterOwnerSession($sessionName) {
        $sessionName = trim((string)$sessionName);
        if ($sessionName === '') {
            return false;
        }
        $owners = ngwLoadRouterOwners(true);
        if (isset($owners[$sessionName])) {
            unset($owners[$sessionName]);
            return ngwSaveRouterOwners($owners);
        }
        return true;
    }
}

if (!function_exists('ngwGetRouterOwnerInfo')) {
    /**
     * Get ownership info for a router session (defaults to Super Admin adm_1 if unassigned)
     */
    function ngwGetRouterOwnerInfo($sessionName) {
        $sessionName = trim((string)$sessionName);
        $owners = ngwLoadRouterOwners();
        if ($sessionName !== '' && isset($owners[$sessionName]) && is_array($owners[$sessionName])) {
            $info = $owners[$sessionName];
            $admId = !empty($info['owner_admin_id']) ? (string)$info['owner_admin_id'] : 'adm_1';
            $adm = findAdminUserByIdOrUsername($admId);
            if (is_array($adm)) {
                $info['owner_admin_name'] = !empty($adm['name']) ? (string)$adm['name'] : (string)$adm['username'];
                $info['owner_admin_username'] = (string)$adm['username'];
                $info['owner_admin_tier'] = isset($adm['admin_tier']) ? (string)$adm['admin_tier'] : 'super_admin';
            } else {
                $info['owner_admin_name'] = !empty($info['owner_admin_username']) ? (string)$info['owner_admin_username'] : 'Main Admin Store';
                $info['owner_admin_tier'] = ($admId === 'adm_1') ? 'super_admin' : 'licensed_admin';
            }
            return $info;
        }
        $admins = loadAdminUsers();
        $primary = !empty($admins[0]) ? $admins[0] : array('id' => 'adm_1', 'username' => 'admin', 'name' => 'Main Admin Store');
        return array(
            'owner_admin_id'       => (string)$primary['id'],
            'owner_admin_username' => (string)$primary['username'],
            'owner_admin_name'     => !empty($primary['name']) ? (string)$primary['name'] : (string)$primary['username'],
            'owner_admin_tier'     => 'super_admin',
            'created_by_role'      => 'super_admin',
            'created_by_username'  => (string)$primary['username'],
        );
    }
}

if (!function_exists('ngwGetRoutersForAdmin')) {
    /**
     * Get list of router session names owned by a specific Admin ID (or all routers if Super Admin Store)
     */
    function ngwGetRoutersForAdmin($adminId, $includeAllForSuperAdmin = false) {
        $allRouters = getConfiguredRouterSessions();
        $adm = findAdminUserByIdOrUsername($adminId);
        if ($includeAllForSuperAdmin && is_array($adm) && ($adm['id'] === 'adm_1' || (isset($adm['admin_tier']) && $adm['admin_tier'] === 'super_admin'))) {
            return $allRouters;
        }
        $owners = ngwLoadRouterOwners();
        $matched = array();
        foreach ($allRouters as $sess) {
            if (strpos($sess, 'new-') === 0) {
                continue;
            }
            $ownerId = isset($owners[$sess]['owner_admin_id']) ? (string)$owners[$sess]['owner_admin_id'] : 'adm_1';
            if ($ownerId === (string)$adminId) {
                $matched[] = $sess;
            }
        }
        return $matched;
    }
}

if (!function_exists('ngwGetResellersForAdmin')) {
    /**
     * Get list of reseller records belonging to a specific Admin ID
     */
    function ngwGetResellersForAdmin($adminId) {
        $resellers = loadResellers();
        $matched = array();
        foreach ($resellers as $r) {
            $parentId = !empty($r['parent_admin_id']) ? (string)$r['parent_admin_id'] : 'adm_1';
            if ($parentId === (string)$adminId) {
                $matched[] = $r;
            }
        }
        return $matched;
    }
}

if (!function_exists('ngwCanAdminAddNewRouter')) {
    /**
     * Check if the current Admin (or Reseller's parent Admin) is allowed to add another router
     */
    function ngwCanAdminAddNewRouter(&$errorMessage = null, $langid = 'en') {
        $isBn = ($langid === 'bn');
        $errorMessage = '';
        if (isSuperAdminStore()) {
            return true;
        }
        $targetAdmin = null;
        if (isMikhmonAdmin()) {
            $targetAdmin = getCurrentAdminRecord();
        } else {
            $res = getCurrentResellerRecord();
            $parentId = is_array($res) && !empty($res['parent_admin_id']) ? (string)$res['parent_admin_id'] : 'adm_1';
            $targetAdmin = findAdminUserByIdOrUsername($parentId);
        }
        if (!is_array($targetAdmin)) {
            return true;
        }
        if ($targetAdmin['id'] === 'adm_1' || (isset($targetAdmin['admin_tier']) && $targetAdmin['admin_tier'] === 'super_admin')) {
            return true;
        }
        $lockReason = '';
        if (ngwIsAdminAccountLocked($targetAdmin, $lockReason)) {
            $errorMessage = $isBn
                ? 'আপনার অ্যাডমিন লাইসেন্সের মেয়াদ শেষ হয়েছে বা অ্যাকাউন্ট লক করা হয়েছে। নতুন রাউটার যুক্ত করা যাবে না।'
                : 'Your Admin license has expired or is locked. Cannot add new routers.';
            return false;
        }
        $maxRouters = isset($targetAdmin['max_routers']) ? (int)$targetAdmin['max_routers'] : 0;
        if ($maxRouters > 0) {
            $ownedRouters = ngwGetRoutersForAdmin($targetAdmin['id'], false);
            $usedCount = count($ownedRouters);
            if ($usedCount >= $maxRouters) {
                $errorMessage = $isBn
                    ? ('আপনার রাউটার লিমিট পূর্ণ হয়ে গেছে (' . $usedCount . '/' . $maxRouters . ' টি রাউটার)। নতুন রাউটার যোগ করতে মেইন অ্যাডমিন স্টোর থেকে লিমিট বাড়িয়ে নিন।')
                    : ('Router limit reached (' . $usedCount . '/' . $maxRouters . ' routers). Please contact the Main Admin Store to upgrade your router limit.');
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('ngwCanAdminCreateReseller')) {
    /**
     * Check if the current Licensed Sub-Admin can create another Reseller
     */
    function ngwCanAdminCreateReseller(&$errorMessage = null, $langid = 'en') {
        $isBn = ($langid === 'bn');
        $errorMessage = '';
        if (isSuperAdminStore()) {
            return true;
        }
        $adm = getCurrentAdminRecord();
        if (!is_array($adm)) {
            return false;
        }
        $lockReason = '';
        if (ngwIsAdminAccountLocked($adm, $lockReason)) {
            $errorMessage = $isBn
                ? 'আপনার অ্যাডমিন লাইসেন্সের মেয়াদ শেষ হয়েছে বা অ্যাকাউন্ট লক করা হয়েছে।'
                : 'Your Admin license has expired or is locked.';
            return false;
        }
        $maxResellers = isset($adm['max_resellers']) ? (int)$adm['max_resellers'] : 0;
        if ($maxResellers > 0) {
            $ownedResellers = ngwGetResellersForAdmin($adm['id']);
            $usedCount = count($ownedResellers);
            if ($usedCount >= $maxResellers) {
                $errorMessage = $isBn
                    ? ('আপনার রিসেলার তৈরির লিমিট পূর্ণ হয়ে গেছে (' . $usedCount . '/' . $maxResellers . ' জন রিসেলার)। নতুন রিসেলার যোগ করতে মেইন অ্যাডমিন স্টোরের সাথে যোগাযোগ করুন।')
                    : ('Reseller limit reached (' . $usedCount . '/' . $maxResellers . ' resellers). Please contact the Main Admin Store to increase your limit.');
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('ngwLoadLicenseUsage')) {
    /**
     * Load monthly voucher generation counters from include/license_usage.json
     */
    function ngwLoadLicenseUsage($forceReload = false) {
        if (!$forceReload && isset($GLOBALS['NGW_CACHE_LICENSE_USAGE']) && is_array($GLOBALS['NGW_CACHE_LICENSE_USAGE'])) {
            return $GLOBALS['NGW_CACHE_LICENSE_USAGE'];
        }
        $path = ngwGetLicenseUsageStoragePath();
        if (!is_file($path)) {
            $GLOBALS['NGW_CACHE_LICENSE_USAGE'] = array();
            return array();
        }
        $raw = @file_get_contents($path);
        $decoded = @json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }
        $GLOBALS['NGW_CACHE_LICENSE_USAGE'] = $decoded;
        return $decoded;
    }
}

if (!function_exists('ngwGetAdminMonthlyVoucherUsage')) {
    /**
     * Get the number of vouchers generated by an Admin (and their Resellers) in the given YYYY-MM month
     */
    function ngwGetAdminMonthlyVoucherUsage($adminId, $yearMonth = null) {
        if ($yearMonth === null || $yearMonth === '') {
            $yearMonth = date('Y-m');
        }
        $usage = ngwLoadLicenseUsage();
        if (isset($usage[$yearMonth][$adminId])) {
            return max(0, (int)$usage[$yearMonth][$adminId]);
        }
        return 0;
    }
}

if (!function_exists('ngwIncrementAdminMonthlyVoucherUsage')) {
    /**
     * Increment the monthly voucher counter for an Admin ID
     */
    function ngwIncrementAdminMonthlyVoucherUsage($adminId, $count = 1) {
        $adminId = trim((string)$adminId);
        $count = max(0, (int)$count);
        if ($adminId === '' || $count <= 0) {
            return false;
        }
        $yearMonth = date('Y-m');
        $usage = ngwLoadLicenseUsage(true);
        if (!isset($usage[$yearMonth]) || !is_array($usage[$yearMonth])) {
            $usage[$yearMonth] = array();
        }
        $curr = isset($usage[$yearMonth][$adminId]) ? (int)$usage[$yearMonth][$adminId] : 0;
        $usage[$yearMonth][$adminId] = $curr + $count;

        // Keep only the last 12 months of counters to keep the JSON file small
        if (count($usage) > 12) {
            ksort($usage);
            $usage = array_slice($usage, -12, 12, true);
        }

        $path = ngwGetLicenseUsageStoragePath();
        $ok = @file_put_contents($path, json_encode($usage, JSON_PRETTY_PRINT), LOCK_EX) !== false;
        if ($ok) {
            $GLOBALS['NGW_CACHE_LICENSE_USAGE'] = $usage;
        }
        return $ok;
    }
}

if (!function_exists('ngwResolveQuotaAdminForSession')) {
    /**
     * Resolve the Licensed Admin record whose quotas govern a given router session or current user
     */
    function ngwResolveQuotaAdminForSession($routerSession = '') {
        if (isLicensedSubAdmin()) {
            return getCurrentAdminRecord();
        }
        if (!isMikhmonAdmin()) {
            $res = getCurrentResellerRecord();
            if (is_array($res) && !empty($res['parent_admin_id'])) {
                $adm = findAdminUserByIdOrUsername($res['parent_admin_id']);
                if (is_array($adm)) {
                    return $adm;
                }
            }
        }
        if ($routerSession !== '') {
            $ownerInfo = ngwGetRouterOwnerInfo($routerSession);
            if (!empty($ownerInfo['owner_admin_id'])) {
                $adm = findAdminUserByIdOrUsername($ownerInfo['owner_admin_id']);
                if (is_array($adm)) {
                    return $adm;
                }
            }
        }
        return getCurrentAdminRecord();
    }
}

if (!function_exists('ngwValidateVoucherGenerationQuota')) {
    /**
     * Validate all license & quota limits before adding Hotspot users or generating batch vouchers.
     *
     * Checks:
     *   1. License status & expiration
     *   2. Single-batch quantity limit (max_batch_qty)
     *   3. Active Hotspot user limit on the router (max_active_users)
     *   4. Total Hotspot user limit on the router (max_total_users)
     *   5. Monthly voucher generation limit (max_monthly_vouchers)
     *
     * Returns array('allowed' => bool, 'error' => string, 'admin' => array|null)
     */
    function ngwValidateVoucherGenerationQuota($routerSession, $requestedQty = 1, $apiOrCounts = null, $langid = 'en') {
        $isBn = ($langid === 'bn');
        $requestedQty = max(1, (int)$requestedQty);
        $adm = ngwResolveQuotaAdminForSession($routerSession);

        // Super Admin Store working on their own router has no restrictions
        if (!is_array($adm) || $adm['id'] === 'adm_1' || (isset($adm['admin_tier']) && $adm['admin_tier'] === 'super_admin' && isSuperAdminStore())) {
            return array('allowed' => true, 'error' => '', 'admin' => $adm);
        }

        $lockReason = '';
        if (ngwIsAdminAccountLocked($adm, $lockReason)) {
            $err = $isBn
                ? 'এই অ্যাকাউন্টের লাইসেন্সের মেয়াদ শেষ হয়েছে বা স্থগিত করা হয়েছে। নতুন ইউজার বা ভাউচার তৈরি করা যাবে না।'
                : 'The Admin license for this router has expired or is suspended. Cannot create new users or vouchers.';
            return array('allowed' => false, 'error' => $err, 'admin' => $adm);
        }

        // 1. Check Single-Batch Quantity Limit
        $maxBatchQty = isset($adm['max_batch_qty']) ? (int)$adm['max_batch_qty'] : 0;
        if ($maxBatchQty > 0 && $requestedQty > $maxBatchQty) {
            $err = $isBn
                ? ('একবারে সর্বোচ্চ ' . $maxBatchQty . ' টি ভাউচার জেনারেট করার লিমিট দেওয়া আছে (আপনি দিয়েছেন ' . $requestedQty . ' টি)।')
                : ('Your license allows generating at most ' . $maxBatchQty . ' vouchers per batch (requested ' . $requestedQty . ').');
            return array('allowed' => false, 'error' => $err, 'admin' => $adm);
        }

        // 2. Check Monthly Voucher Limit
        $maxMonthly = isset($adm['max_monthly_vouchers']) ? (int)$adm['max_monthly_vouchers'] : 0;
        if ($maxMonthly > 0) {
            $usedMonthly = ngwGetAdminMonthlyVoucherUsage($adm['id']);
            if (($usedMonthly + $requestedQty) > $maxMonthly) {
                $remaining = max(0, $maxMonthly - $usedMonthly);
                $err = $isBn
                    ? ('আপনার মাসিক ভাউচার জেনারেট লিমিট শেষ পর্যায়ে (' . $usedMonthly . '/' . $maxMonthly . ' ব্যবহৃত, বাকি আছে ' . $remaining . ' টি)। মেইন অ্যাডমিন স্টোর থেকে লিমিট বাড়িয়ে নিন।')
                    : ('Monthly voucher generation limit reached (' . $usedMonthly . '/' . $maxMonthly . ' used, ' . $remaining . ' remaining this month). Contact Main Admin Store.');
                return array('allowed' => false, 'error' => $err, 'admin' => $adm);
            }
        }

        // 3. Check Router Active Users & Total Hotspot Users if limits are set
        $maxActive = isset($adm['max_active_users']) ? (int)$adm['max_active_users'] : 0;
        $maxTotalUsers = isset($adm['max_total_users']) ? (int)$adm['max_total_users'] : 0;

        if ($maxActive > 0 || $maxTotalUsers > 0) {
            $currentActive = null;
            $currentTotal = null;

            if (is_array($apiOrCounts)) {
                if (isset($apiOrCounts['active_users'])) $currentActive = (int)$apiOrCounts['active_users'];
                if (isset($apiOrCounts['total_users'])) $currentTotal = (int)$apiOrCounts['total_users'];
            } elseif (is_object($apiOrCounts) && method_exists($apiOrCounts, 'comm')) {
                if ($maxActive > 0) {
                    $actRes = $apiOrCounts->comm('/ip/hotspot/active/print', array('count-only' => ''));
                    if (is_numeric($actRes)) {
                        $currentActive = (int)$actRes;
                    } elseif (is_array($actRes)) {
                        $currentActive = count($actRes);
                    }
                }
                if ($maxTotalUsers > 0) {
                    $usrRes = $apiOrCounts->comm('/ip/hotspot/user/print', array('count-only' => ''));
                    if (is_numeric($usrRes)) {
                        $currentTotal = max(0, (int)$usrRes - 1);
                    } elseif (is_array($usrRes)) {
                        $currentTotal = max(0, count($usrRes) - 1);
                    }
                }
            }

            if ($maxActive > 0 && $currentActive !== null && $currentActive >= $maxActive) {
                $err = $isBn
                    ? ('অ্যাক্টিভ ইউজার লিমিট পূর্ণ হয়ে গেছে (' . $currentActive . '/' . $maxActive . ' জন অনলাইনে আছে)। নতুন ভাউচার জেনারেট ব্লক করা হয়েছে।')
                    : ('Active Hotspot user limit reached (' . $currentActive . '/' . $maxActive . ' active online). New voucher generation is blocked.');
                return array('allowed' => false, 'error' => $err, 'admin' => $adm);
            }

            if ($maxTotalUsers > 0 && $currentTotal !== null && ($currentTotal + $requestedQty) > $maxTotalUsers) {
                $remUsers = max(0, $maxTotalUsers - $currentTotal);
                $err = $isBn
                    ? ('রাউটারের মোট হটস্পট ইউজার লিমিট পূর্ণ হয়ে গেছে (বর্তমানে আছে ' . $currentTotal . '/' . $maxTotalUsers . ' টি, আর যোগ করা যাবে সর্বোচ্চ ' . $remUsers . ' টি)।')
                    : ('Router total Hotspot user limit reached (' . $currentTotal . '/' . $maxTotalUsers . ' users on router, only ' . $remUsers . ' more allowed).');
                return array('allowed' => false, 'error' => $err, 'admin' => $adm);
            }
        }

        return array('allowed' => true, 'error' => '', 'admin' => $adm);
    }
}
