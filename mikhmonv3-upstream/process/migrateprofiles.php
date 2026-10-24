<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  Extended 2026: Controlled RouterOS 6 / RouterOS 7 Profile & Scheduler Migration Utility
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
} else {
    include_once('../include/ros_version.php');
    include_once('../include/reseller_acl.php');

    if (!hasResellerPermission('manage_profiles')) {
        echo "<b>Access Denied: Only Administrators can run existing-router profile migration.</b>";
        exit;
    }

    $rosMajor = detectRouterOSMajorVersion($API);
    $profiles = $API->comm("/ip/hotspot/user/profile/print");
    $schedulers = $API->comm("/system/scheduler/print");

    $migrationReport = array();
    $backupExport = "# Mikhmon v3 Existing-Router Backup (" . date("Y-m-d H:i:s") . ")\n";

    if (isset($_POST['run_migration'])) {
        foreach ($profiles as $prof) {
            $pid = $prof['.id'];
            $pname = $prof['name'];
            $ponlogin = isset($prof['on-login']) ? $prof['on-login'] : '';

            if (strpos($ponlogin, ':put (",') !== false) {
                $parts = explode(",", $ponlogin);
                $expmode = isset($parts[1]) ? $parts[1] : '0';
                $price = isset($parts[2]) ? $parts[2] : '0';
                $validity = isset($parts[3]) ? $parts[3] : '';
                $sprice = isset($parts[4]) ? $parts[4] : '0';
                $getlock = isset($parts[6]) ? $parts[6] : 'Disable';

                $backupExport .= "/ip hotspot user profile set [find where name=\"$pname\"] on-login=" . json_encode($ponlogin) . "\n";

                $universal = buildUniversalMikhmonScripts($pname, $expmode, $price, $validity, $sprice, $getlock);
                $API->comm("/ip/hotspot/user/profile/set", array(
                    ".id" => $pid,
                    "on-login" => $universal['onlogin']
                ));

                $monSch = $API->comm("/system/scheduler/print", array("?name" => $pname));
                if ($expmode != "0" && $expmode != "") {
                    if (empty($monSch[0]['.id'])) {
                        $API->comm("/system/scheduler/add", array(
                            "name" => $pname,
                            "start-time" => "01:15:00",
                            "interval" => "00:02:30",
                            "on-event" => $universal['bgservice'],
                            "disabled" => "no",
                            "comment" => "Monitor Profile $pname"
                        ));
                    } else {
                        $API->comm("/system/scheduler/set", array(
                            ".id" => $monSch[0]['.id'],
                            "on-event" => $universal['bgservice'],
                            "disabled" => "no",
                            "comment" => "Monitor Profile $pname"
                        ));
                    }
                }
                $migrationReport[] = array(
                    'profile' => $pname,
                    'expmode' => $expmode,
                    'validity' => $validity,
                    'status' => 'Migrated to Universal ROS6/ROS7'
                );
            }
        }
    }
}
?>
