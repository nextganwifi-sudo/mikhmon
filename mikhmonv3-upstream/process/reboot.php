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
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
session_start();
// hide all error
if (!isset($_SESSION['mikhmon']) || empty($session)) {
  header('Location:../admin.php?id=login');
  exit;
}
include_once __DIR__ . '/../include/reseller_acl.php';
if (!canAccessRouterSession($session) || !hasResellerPermission('reboot_shutdown')) {
  http_response_code(403);
  exit('Access denied.');
}
$routerActionCsrf = ngwCsrfToken('ngw_router_action_csrf');
$actionError = '';
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {

  if (isset($_POST['submit'])) {
    if (!ngwValidateCsrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '', 'ngw_router_action_csrf')) {
      $actionError = 'Security token expired or invalid. Reload this page and try again.';
    } else {
      $API = new RouterosAPI();
      $API->debug = false;
      if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        $API->write('/system/reboot');
        $API->read();
      }
      $_SESSION = array();
      @session_unset();
      @session_destroy();
      echo "<script>window.location='./admin.php?id=login'</script>";
      exit;
    }
  }
}
?>
<div style="padding-top:10%;" class="register-box">
  <div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fa fa-power-off"></i> Reboot MikroTik</h3>
    </div>
  	<div class="card-body text-center">
  		<form action="" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($routerActionCsrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div>
          <h3><?= $_reboot.' '. $session; ?>?</h3>
        </div>
  	  <button class="btn bg-warning" type="submit" title="Yes" name="submit"><?= $_yes ?></button>
      <a class="btn bg-primary" href="./?hotspot=dashboard&session=<?= $session; ?>" title="No"> <?= $_no ?> </a>
      
    </form>
  </div>
</div>
</div>