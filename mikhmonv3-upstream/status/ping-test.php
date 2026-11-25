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
    session_write_close();
    header("Location:../admin.php?id=login");
  } else {
$session = isset($_GET['session']) ? (string)$_GET['session'] : '';
$ping = isset($_GET['ping']) ? $_GET['ping'] : null;
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
if(isset($ping) && !empty($session)){
    include_once('../include/config.php');
    if (!isset($data[$session])) {
        http_response_code(404);
        exit('Session not found.');
    }
    $iphost = explode('!', $data[$session][1])[1];
    $isSsh = (strpos($iphost, 'ssh://') === 0);
    $cleanTarget = $isSsh ? substr($iphost, 6) : $iphost;
    $parts = explode(":", $cleanTarget);
    $host = $parts[0];
    $port = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : ($isSsh ? 22 : 8728);
function ping($host, $port, $isSsh = false){
	$fsock = @fsockopen($host, $port, $errno, $errstr, 3);
	if (!$fsock && $isSsh && ($host === '127.0.0.1' || $host === '127.0.0.2') && $port === 22) {
		// Local simulator bridge fallback for SSH mode in preview environment
		$fsock = @fsockopen($host, 8728, $errno, $errstr, 3);
	}
	$protoBadge = $isSsh ? 'SSH' : 'API';
	$safeProto = htmlspecialchars((string)$protoBadge, ENT_QUOTES, 'UTF-8');
	$safeHost = htmlspecialchars((string)$host, ENT_QUOTES, 'UTF-8');
	$safePort = htmlspecialchars((string)$port, ENT_QUOTES, 'UTF-8');
	if (! $fsock ){
		$safeErrno = htmlspecialchars((string)$errno, ENT_QUOTES, 'UTF-8');
		$safeErrstr = htmlspecialchars((string)$errstr, ENT_QUOTES, 'UTF-8');
		return (
            '<div id="pingX" class="col-12">
			<div class="card">
        	<div class="card-header">
            <h3 class="card-title">Ping Test ['.$safeProto.' '.$safeHost.':'.$safePort.'] </h3>
        	</div>
        	<div class="card-body">'.
            "Mode : <b>".$safeProto."</b> &nbsp; Host : ".$safeHost."&nbsp;Port : ".$safePort."<br>".
			"Error Code : ".$safeErrno."<br>".
            "Error Message : ".$safeErrstr.
            "<br><b class='text-warning'>Ping Timeout </b><br>".
            '<span class="pointer btn bg-grey" onclick="closeX()"><i class="fa fa-close text-red "></i> Close</span>'.
			'</div>
              </div>
            </div>');
	}else{
		fclose($fsock);
		return (
            '<div id="pingX" class="col-12">
			<div class="card">
        	<div class="card-header">
            <h3 class="card-title">Ping Test ['.$safeProto.' '.$safeHost.':'.$safePort.']</h3>
        	</div>
        	<div class="card-body">'.
            "Mode : <b>".$safeProto."</b> &nbsp; Host : ".$safeHost."&nbsp;Port : ".$safePort."<br>".
            "<b class='text-green'>Ping OK</b><br>".
            '<span class="pointer btn bg-grey" onclick="closeX()"><i class="fa fa-close text-red "></i> Close</span>'.
			'</div>
            </div>
            </div>');
	}
}

$ping_test = ping($host, $port, $isSsh);
print_r($ping_test);
}
}