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
ngwSendNoCacheHeaders();
session_start();

error_reporting(0);

ob_start("ob_gzhandler");

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
} else {

// load session MikroTik
  $session = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
  include_once('../include/reseller_acl.php');
  enforceMikhmonSessionSecurity(false);
  $isAdminPreview = isMikhmonAdmin();
  $isSuperAdminPreview = isMikhmonAdminStore();
  $canPreviewTemplates = $isSuperAdminPreview ||
    hasResellerPermission('manage_global_templates') ||
    hasResellerPermission('preview_assigned_template') ||
    hasResellerPermission('use_assigned_template') ||
    hasResellerPermission('print_voucher') ||
    hasResellerPermission('edit_own_template') ||
    hasResellerPermission('save_own_template');

  if ($session !== '' && !canAccessRouterSession($session)) {
    session_write_close();
    http_response_code(403);
    echo "Access Denied: You do not have permission to preview voucher templates for this router session.";
    exit;
  }
  // If opened from the template editor without a session, select the first accessible router
  // session so the preview can resolve router-specific voucher variables.
  if ($session === '') {
    include_once('../include/config.php');
    if (isset($data) && is_array($data)) {
      foreach (array_keys($data) as $candidateSession) {
        if ($candidateSession === 'mikhmon' || !isset($data[$candidateSession]) || !is_array($data[$candidateSession])) continue;
        if (canAccessRouterSession((string)$candidateSession)) {
          $session = (string)$candidateSession;
          break;
        }
      }
    }
  }
  if ($session === '' || !canAccessRouterSession($session) || !$canPreviewTemplates) {
    session_write_close();
    http_response_code(403);
    echo "Access Denied: You do not have permission to preview voucher templates for this router session.";
    exit;
  }

  $overrideResId = null;
  if ($isAdminPreview && isset($_GET['reseller_id']) && trim((string)$_GET['reseller_id']) !== '' && $_GET['reseller_id'] !== 'global') {
    $candResPreview = findResellerByIdOrUsername((string)$_GET['reseller_id']);
    if (!$candResPreview || !canCurrentAdminAccessReseller($candResPreview)) {
      session_write_close();
      http_response_code(403);
      echo "Access Denied: You do not have permission to preview this reseller's template.";
      exit;
    }
    $overrideResId = (string)$candResPreview['id'];
  }
  session_write_close();

// load config
  include('../include/config.php');
  include('../include/readcfg.php');

  include('../lib/formatbytesbites.php');

  $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
  $qr = isset($_GET['qr']) ? (string)$_GET['qr'] : '';
  $usermode = isset($_GET['usermode']) ? (string)$_GET['usermode'] : '';
  $small = isset($_GET['small']) ? (string)$_GET['small'] : '';
  $userp = isset($_GET['user']) ? (string)$_GET['user'] : '';

  $logo = resolveActiveVoucherLogo($session, '../', true, $overrideResId);

  $requestedVariant = 'assigned';
  if ($userp != "") {
    $requestedVariant = 'thermal';
  } elseif ($small == "yes") {
    $requestedVariant = 'small';
  } elseif (isset($_GET['variant']) && in_array($_GET['variant'], array('default', 'thermal', 'small'), true)) {
    $requestedVariant = $_GET['variant'];
  }
  $resolvedTemplatePath = resolveActiveVoucherTemplatePath($requestedVariant, $overrideResId);

 
  $username = "NGW-7892";
  $password = "5842";
  $timelimit = "6h";
  $getdatalimit = "1073741824";
  $comment = "test";
  $validity = "1d";
  $profile = "6Jam";

  
if ($currency == in_array($currency, $cekindo['indo'])) {
    $getprice = "5000";
    $price = $currency . " " . number_format((float)$getprice, 0, ",", ".");
} else {
    $getprice = "10";
    $price = $currency . " " . number_format((float)$getprice, 2);
}
  
  
  if ($getdatalimit == 0) {
    $datalimit = "";
  } else {
    $datalimit = formatBytes($getdatalimit, 2);
  }
  
  $urilogin = "http://$dnsname/login?username=$username&password=$password";
  $qrcode = "
	<canvas class='qrcode' id='qr'></canvas>
    <script>
      (function() {
        var qr = new QRious({
          element: document.getElementById('qr'),
          value: ".json_encode($urilogin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).",
          size:'256'
        });

      })();
    </script>
	";

  $num = 1;

}
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Voucher-<?= htmlspecialchars((string)$hotspotname . "-" . (isset($getuprofile) ? $getuprofile : '') . "-" . $id, ENT_QUOTES, 'UTF-8'); ?></title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta http-equiv="pragma" content="no-cache" />
    <link rel="icon" type="image/png" href="../img/favicon.png?v=nextgan" />
    <script src="../js/qrious.min.js"></script>
		<style>
body {
  color: #000000;
  background-color: #FFFFFF;
  font-size: 14px;
  font-family:  'Helvetica', arial, sans-serif;
  margin: 0px;
  -webkit-print-color-adjust: exact;
}
table.voucher {
  display: inline-block;
  border: 2px solid black;
  margin: 2px;
}
@page
{
  size: auto;
  margin-left: 7mm;
  margin-right: 3mm;
  margin-top: 9mm;
  margin-bottom: 3mm;
}
@media print
{
  table { page-break-after:auto }
  tr    { page-break-inside:avoid; page-break-after:auto }
  td    { page-break-inside:avoid; page-break-after:auto }
  thead { display:table-header-group }
  tfoot { display:table-footer-group }
}
#num {
  float:right;
  display:inline-block;
}
.qrc {
  width:30px;
  height:30px;
  margin-top:1px;
}
		</style>
	</head>
	<body>

<?php
include($resolvedTemplatePath);
?>


	
</body>
</html>
