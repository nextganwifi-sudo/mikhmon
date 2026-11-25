# Security Summary

A full codebase audit and end-to-end functional verification was completed across all frontend views, backend PHP endpoints, role-based access boundaries (Super Admin, Regular Admin, and Reseller), configuration handlers, and web-server routing rules of NextGan WiFi Solution.

## Key Fixes Applied

1. **Multi-Admin & Reseller Router Isolation (`hotspot/hotspotactive.php`, `settings/settings.php`, `voucher/vpreview.php`, `dashboard/aload.php`, `vpn/vpn_helpers.php`)**
   - Enforced router-session ownership checks (`canAccessRouterSession`) and action permissions across Hotspot Active AJAX endpoints, Router Settings save/rename handlers, Voucher Preview, Dashboard live resource widgets, and VPN router helpers (changed VPN fallback from fail-open to fail-closed).
   - Added case-insensitive duplicate session-name collision protection in Router Settings so a Regular Admin cannot overwrite another Admin's router by renaming an existing router to match another session name.

2. **Global Template, Logo & Permission Group Protection (`settings/vouchereditor.php`, `settings/uplogo.php`, `settings/resellers.php`, `admin.php`, `index.php`, `include/reseller_acl.php`)**
   - Restricted global voucher template editing/resetting and global permission-group saving/resetting strictly to Super Admin accounts (`isMikhmonAdminStore()`).
   - Scoped Regular Admin logo uploads, logo listings, and logo deletion strictly to their own accessible router sessions so they can never view, overwrite, or delete the global logo or another Admin's router logo.
   - Enforced ownership validation (`canCurrentAdminAccessReseller`) whenever a `reseller_id` override is passed to Voucher Preview or template/logo resolvers.
   - Prevented Regular Admins with Hotspot disabled from accessing template editor or logo upload routes.

3. **Granular Profile, Activity & User Transfer Permissions (`hotspot/usertransfer.php`, `settings/my_profile.php`, `settings/activity.php`, `include/menu.php`)**
   - Enforced per-action permissions on User Transfer (JSON export, `.rsc` export, import, and auto-activate) on both server-side POST handlers and UI controls.
   - Removed legacy hardcoded fallback password checks in My Profile, prevented Regular Admins from deleting the shared Super Admin avatar directory, and enforced `manage_own_profile` / `change_own_password` for Resellers in both My Profile and the top-bar Profile menu.
   - Restricted the Activity History clear action button to Super Admin accounts.

4. **Output Escaping, Session Timeout & Route Hardening (`status/index.php`, `status/status.php`, `status/ping-test.php`, `process/getvalidprice.php`, `server.ts`, `nginx.conf`, `deploy/vps-install.sh`)**
   - Validated router session existence in the public voucher status check (`HTTP 404` for unknown sessions) and HTML-escaped all rendered fields in both `status/index.php` and `hotspot/hotspotactive.php`.
   - Enforced the 5-minute idle session timeout and revoked-session check consistently across background AJAX endpoints (`status.php`, `ping-test.php`, `getvalidprice.php`, `hotspotactive.php`).
   - Refined web-server voucher rules so legitimate application entry points (`voucher/vpreview.php`, `voucher/print.php`, `voucher/printbt.php`, `voucher/index.php`) work reliably while direct HTTP access to voucher template fragments (`voucher/template*.php`, `voucher/default*.php`) and `/uploads/*.php` remains blocked (`HTTP 404`).

5. **Previously Verified Core Safeguards**
   - Backup bundle restoration strictly allows only the 9 valid JSON/config data files under `include/`.
   - Git update URLs strictly require public `https://` URLs without malformed flags or private/local hosts.
   - Full server ZIP export (`settings/export_package.php`) is restricted to Super Admin and excludes sensitive environment, runtime, and mirror directories.
   - Theme and language redirects ignore external hosts and allow only local relative paths.

## Safeguards Verified

- Direct web access to private paths (`/include/config.php`, `/include/admin_users.json`, `/.env`, `/data/*`, `/deploy/*`, `/voucher/template.php`) returns `HTTP 404`.
- Super Admin's Router List shows only the Regular Admin's name badge (without extra prefix text), and Regular Admins do not see owner badges or other Admins' routers.
- All 5 built-in themes (`dark`, `light`, `blue`, `green`, `pink`) switch cleanly from the Profile dropdown and persist per user.
- Router sessions whose names end with `p` (such as `sub_camp`) stay logged in when saving or editing.
- All modified PHP files pass `php -l` with zero syntax errors and are mirrored identically across all deployment directories.

## PoC Verification

All 35 automated end-to-end functional and access-control checks passed (`PASS=35, FAIL=0`):

- **Router List & Theme Switcher**: Super Admin Router List rendered `<span>Kamrul Hasan</span>` with zero prefix text; Regular Admin saw only their own router and no owner corner badge; all 5 themes (`dark`, `light`, `blue`, `green`, `pink`) loaded their respective stylesheets cleanly.
- **Session Naming Fix**: Editing a router session ending in `p` (`sub_camp`) stayed authenticated and loaded router settings cleanly (`HTTP 200`).
- **Cross-Admin & Reseller Isolation**:
  - `hotspot/hotspotactive.php` returned `HTTP 403` for a Regular Admin requesting a Super Admin router and for a Reseller lacking `view_active`.
  - Renaming a Regular Admin's router onto an existing Super Admin router name in `settings.php` was rejected with Super Admin config left untouched.
  - Global template overwrite via `vouchereditor.php` and global permission-group mutation via `resellers.php` were rejected for Regular Admins, and a Regular Admin with Hotspot disabled was blocked from the editor.
  - `uplogo.php` hid Super Admin router logos from Regular Admins and `remove-logo` refused to delete another Admin's router logo.
  - `voucher/vpreview.php` rendered `HTTP 200` for a Regular Admin's own router, returned `HTTP 403` for a Super Admin router and for an out-of-scope `reseller_id`, while `voucher/template.php` stayed blocked with `HTTP 404`.
  - `status/index.php` returned `HTTP 404` for an invalid session and HTML-escaped injected script tags.
  - `usertransfer.php`, `my_profile.php`, `activity.php`, and `aload.php` enforced all granular role permissions.
- **Private Paths**: 5/5 private paths (`/include/config.php`, `/include/admin_users.json`, `/.env`, `/data/test`, `/deploy/safe-update.sh`) returned `HTTP 404`.
