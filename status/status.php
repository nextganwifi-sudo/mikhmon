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
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	http_response_code(403);
	exit('Access denied.');
}
$session = isset($_GET['session']) ? (string)$_GET['session'] : '';
$uname = isset($_GET['name']) ? (string)$_GET['name'] : '';
include_once('../include/reseller_acl.php');
if ((function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked()) ||
	(isset($_SESSION['mikhmon_last_activity']) && (time() - (int)$_SESSION['mikhmon_last_activity']) > 300)) {
	$_SESSION = array();
	@session_unset();
	@session_destroy();
	ngwClearSessionCookie();
	http_response_code(401);
	exit('Session expired.');
}
if ($session === '' || !canAccessRouterSession($session)) {
	session_write_close();
	http_response_code(403);
	exit('Access denied.');
}
session_write_close();

include_once('../include/config.php');
if (!isset($data[$session])) {
	http_response_code(404);
	exit('Session not found.');
}
$iphost=explode('!',$data[$session][1])[1]; 
$userhost=explode('@|@',$data[$session][2])[1];
$passwdhost=explode('#|#',$data[$session][3])[1]; 
$hotspotname=explode('%',$data[$session][4])[1]; 
$dnsname=explode('^',$data[$session][5])[1]; 
$curency=explode('&',$data[$session][6])[1]; 


include_once('../lib/routeros_api.class.php');

$API = new RouterosAPI();
$API->debug = false;
$API->connect( $iphost, $userhost, decrypt($passwdhost));

$exp = '';
if($uname != ""){
	$getname = $API->comm("/ip/hotspot/user/print", array("?name" => "$uname"));
  	$exp = isset($getname[0]['comment']) ? (string)$getname[0]['comment'] : '';
	if(substr($exp,3,1) == "/" && substr($exp,6,1) == "/"){
		$exp = $exp;
	}else{
	$getname = $API->comm("/sys/sch/print", array("?name" => "$uname"));
	  $exp = isset($getname[0]['next-run']) ? (string)$getname[0]['next-run'] : '';
	}
  
}
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Voucher-<?= htmlspecialchars((string)$hotspotname . "-" . $uname, ENT_QUOTES, 'UTF-8');?></title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta http-equiv="pragma" content="no-cache" />
		<style>
		body {
  			font-family: 'Helvetica', arial, sans-serif;
			font-size: 15px;
			margin:0px;
  		}
		</style>
	</head>
	<body>
		<div style="padding:5px;" id="exp" ><?= htmlspecialchars((string)$exp, ENT_QUOTES, 'UTF-8');?></div>	
	</body>
</html>