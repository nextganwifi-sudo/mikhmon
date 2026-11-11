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
session_start();
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
} else {
	$isBn = (isset($langid) && $langid === 'bn');
	$canBindHost = (hasResellerPermission('bind_host_ip') || hasResellerPermission('manage_hosts') || hasResellerPermission('manage_ipbinding'));
	$canRemoveHost = hasResellerPermission('manage_hosts');

	$bindAlertMsg = '';
	$bindAlertType = '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ngw_host_to_binding'])) {
		if (!$canBindHost) {
			$bindAlertType = 'danger';
			$bindAlertMsg = $isBn ? 'আপনার হোস্ট থেকে IP বাইন্ডিং করার অনুমতি নেই।' : 'You do not have permission to create IP Bindings from Hosts.';
		} else {
			$postMac = strtoupper(trim((string)($_POST['mac_address'] ?? '')));
			$postAddr = trim((string)($_POST['address'] ?? ''));
			$postToAddr = trim((string)($_POST['to_address'] ?? ''));
			$postServer = trim((string)($_POST['server'] ?? 'all'));
			if ($postServer === '') {
				$postServer = 'all';
			}
			$postType = strtolower(trim((string)($_POST['binding_type'] ?? 'bypassed')));
			if (!in_array($postType, array('regular', 'bypassed', 'blocked'), true)) {
				$postType = 'bypassed';
			}
			$postComment = trim((string)($_POST['comment'] ?? ''));
			$postHostId = trim((string)($_POST['host_id'] ?? ''));

			if ($postMac === '' && $postAddr === '') {
				$bindAlertType = 'danger';
				$bindAlertMsg = $isBn ? 'ম্যাক অ্যাড্রেস অথবা আইপি অ্যাড্রেস প্রদান করা আবশ্যক।' : 'MAC Address or IP Address is required.';
			} else {
				$existingBindings = array();
				if ($postMac !== '') {
					$existingBindings = $API->comm("/ip/hotspot/ip-binding/print", array(
						"?mac-address" => $postMac,
					));
				}
				if (is_array($existingBindings) && !empty($existingBindings[0]['.id'])) {
					$existId = $existingBindings[0]['.id'];
					$setParams = array(
						".id"      => $existId,
						"server"   => $postServer,
						"type"     => $postType,
						"comment"  => $postComment,
						"disabled" => "no",
					);
					if ($postMac !== '') {
						$setParams["mac-address"] = $postMac;
					}
					if ($postAddr !== '') {
						$setParams["address"] = $postAddr;
					}
					if ($postToAddr !== '') {
						$setParams["to-address"] = $postToAddr;
					}
					$API->comm("/ip/hotspot/ip-binding/set", $setParams);
					if ($postAddr === '' && !empty($existingBindings[0]['address'])) {
						$API->comm("/ip/hotspot/ip-binding/unset", array(
							".id"        => $existId,
							"value-name" => "address",
						));
					}
					if ($postToAddr === '' && !empty($existingBindings[0]['to-address'])) {
						$API->comm("/ip/hotspot/ip-binding/unset", array(
							".id"        => $existId,
							"value-name" => "to-address",
						));
					}
				} else {
					$addParams = array(
						"server"   => $postServer,
						"type"     => $postType,
						"comment"  => $postComment,
						"disabled" => "no",
					);
					if ($postMac !== '') {
						$addParams["mac-address"] = $postMac;
					}
					if ($postAddr !== '') {
						$addParams["address"] = $postAddr;
					}
					if ($postToAddr !== '') {
						$addParams["to-address"] = $postToAddr;
					}
					$API->comm("/ip/hotspot/ip-binding/add", $addParams);
				}

				if ($postHostId !== '') {
					$API->comm("/ip/hotspot/host/remove", array(
						".id" => $postHostId,
					));
				}

				$typeLabelEn = ucfirst($postType);
				$typeLabelBn = ($postType === 'bypassed') ? 'Bypassed (বাইপাসড)' : (($postType === 'blocked') ? 'Blocked (ব্লকড)' : 'Regular (সাধারণ)');
				$modeDescEn = ($postAddr === '') ? 'MAC Only (' . $postMac . ')' : ($postMac . ' / ' . $postAddr);
				$modeDescBn = ($postAddr === '') ? 'শুধু MAC (' . $postMac . ')' : ($postMac . ' / ' . $postAddr);

				$bindAlertType = 'success';
				$bindAlertMsg = $isBn
					? ('সফলভাবে IP বাইন্ডিং সম্পন্ন হয়েছে: ' . htmlspecialchars($modeDescBn) . ' — স্ট্যাটাস: ' . $typeLabelBn)
					: ('IP Binding saved successfully: ' . htmlspecialchars($modeDescEn) . ' — Type: ' . $typeLabelEn);
			}
		}
	}

	$srvlist = $API->comm("/ip/hotspot/print");
	if (!is_array($srvlist)) {
		$srvlist = array();
	}

	if ($hotspot == "hostp") {
		$gethosts = $API->comm("/ip/hotspot/host/print", array(
			"?bypassed" => "yes",
		));
		$TotalReg = is_array($gethosts) ? count($gethosts) : 0;
		$counthosts = $TotalReg;

	} elseif ($hotspot == "hosta") {
		$gethosts = $API->comm("/ip/hotspot/host/print", array(
			"?authorized" => "yes",
		));
		$TotalReg = is_array($gethosts) ? count($gethosts) : 0;
		$counthosts = $TotalReg;

	} else {
		$gethosts = $API->comm("/ip/hotspot/host/print");
		$TotalReg = is_array($gethosts) ? count($gethosts) : 0;
		$counthosts = $TotalReg;
	}
}
?>
<style>
.ngw-compact-hosts-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 8px;
}
.ngw-compact-hosts-search {
	width: 240px;
	max-width: 100%;
	height: 30px !important;
	padding: 4px 10px !important;
	font-size: 12px !important;
	border-radius: 5px !important;
}
.ngw-compact-hosts-table {
	font-size: 12px !important;
	margin-bottom: 0 !important;
}
.ngw-compact-hosts-table thead th {
	padding: 6px 8px !important;
	font-size: 11.5px !important;
	vertical-align: middle !important;
	white-space: nowrap;
}
.ngw-compact-hosts-table tbody td {
	padding: 4px 8px !important;
	vertical-align: middle !important;
	line-height: 1.3 !important;
}
.ngw-host-action-cell {
	white-space: nowrap;
	text-align: center;
	width: 1%;
}
.ngw-host-status-badge {
	display: inline-block;
	padding: 1px 5px;
	font-size: 10.5px;
	font-weight: 700;
	border-radius: 4px;
	line-height: 1.2;
}
.ngw-host-bind-btn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 7px;
	font-size: 11px;
	font-weight: 600;
	line-height: 1.3;
	border-radius: 4px;
	border: 1px solid rgba(14, 165, 233, 0.45);
	background: rgba(14, 165, 233, 0.14);
	color: #38bdf8;
	cursor: pointer;
	transition: all 0.15s ease;
	text-decoration: none !important;
}
.ngw-host-bind-btn:hover {
	background: #0ea5e9;
	color: #fff !important;
	border-color: #0ea5e9;
}
.ngw-host-comment-col {
	max-width: 170px;
	width: 170px;
}
.ngw-host-comment-box {
	display: inline-block;
	max-width: 160px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	vertical-align: middle;
	font-size: 11.5px;
	padding: 1px 6px;
	border-radius: 4px;
	background: rgba(148, 163, 184, 0.1);
}
.ngw-bind-modal-backdrop {
	display: none;
	position: fixed;
	z-index: 9999;
	left: 0;
	top: 0;
	width: 100%;
	height: 100%;
	background: rgba(15, 23, 42, 0.72);
	align-items: center;
	justify-content: center;
	padding: 12px;
}
.ngw-bind-modal-card {
	background: #1e293b;
	color: #f8fafc;
	border: 1px solid rgba(148, 163, 184, 0.28);
	border-radius: 8px;
	width: 100%;
	max-width: 420px;
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.45);
	overflow: hidden;
}
.ngw-bind-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	background: rgba(15, 23, 42, 0.65);
	border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-bind-modal-header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
	color: #38bdf8;
}
.ngw-bind-modal-body {
	padding: 12px 14px;
}
.ngw-bind-field {
	margin-bottom: 9px;
}
.ngw-bind-field label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 11.5px;
	font-weight: 600;
	margin-bottom: 3px;
	color: #cbd5e1;
}
.ngw-bind-field input,
.ngw-bind-field select {
	width: 100%;
	height: 31px !important;
	padding: 4px 9px !important;
	font-size: 12px !important;
	border-radius: 5px !important;
	background: #0f172a !important;
	color: #f8fafc !important;
	border: 1px solid rgba(148, 163, 184, 0.32) !important;
}
.ngw-clear-ip-btn {
	padding: 1px 6px;
	font-size: 10.5px;
	font-weight: 600;
	border-radius: 4px;
	border: 1px solid rgba(245, 158, 11, 0.45);
	background: rgba(245, 158, 11, 0.15);
	color: #fbbf24;
	cursor: pointer;
}
.ngw-clear-ip-btn:hover {
	background: #f59e0b;
	color: #111827;
}
.ngw-type-pills {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 6px;
}
.ngw-type-pill {
	text-align: center;
	padding: 5px 6px;
	font-size: 11.5px;
	font-weight: 700;
	border-radius: 5px;
	border: 1px solid rgba(148, 163, 184, 0.3);
	background: #0f172a;
	color: #94a3b8;
	cursor: pointer;
	user-select: none;
}
.ngw-type-pill.active-regular {
	background: rgba(59, 130, 246, 0.2);
	border-color: #3b82f6;
	color: #60a5fa;
}
.ngw-type-pill.active-bypassed {
	background: rgba(16, 185, 129, 0.2);
	border-color: #10b981;
	color: #34d399;
}
.ngw-type-pill.active-blocked {
	background: rgba(239, 68, 68, 0.2);
	border-color: #ef4444;
	color: #f87171;
}
@media (max-width: 768px) {
	.ngw-compact-hosts-search {
		width: 100%;
	}
	.ngw-compact-hosts-table {
		font-size: 11px !important;
	}
	.ngw-compact-hosts-table thead th,
	.ngw-compact-hosts-table tbody td {
		padding: 3px 5px !important;
	}
	.ngw-host-comment-col {
		max-width: 110px;
		width: 110px;
	}
	.ngw-host-comment-box {
		max-width: 100px;
		font-size: 10.5px;
	}
	.ngw-host-bind-btn {
		padding: 2px 5px;
		font-size: 10px;
	}
}
</style>

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header align-middle">
	<h3><i class="fa fa-laptop"></i> <?= $_hosts ?>  
		<?php
	if ($counthosts < 2) {
		echo $counthosts . ($isBn ? " টি আইটেম" : " item");
	} elseif ($counthosts > 1) {
		echo $counthosts . ($isBn ? " টি আইটেম " : " items ");
	};
	?>&nbsp;
		 | <a class="text-info" title="All" href="./?hotspot=hosts&session=<?= $session; ?>">&nbsp;&nbsp;<?= $isBn ? 'সব' : 'All'; ?>&nbsp;&nbsp;</a>
		 | <a class="text-success" title="Authorized" href="./?hotspot=hosta&session=<?= $session; ?>">&nbsp;&nbsp;A&nbsp;&nbsp;</a>
		 | <a class="text-primary" title="Bypassed" href="./?hotspot=hostp&session=<?= $session; ?>">&nbsp;&nbsp;P&nbsp;&nbsp;</a>
		 | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer " title="Reload data"></i>
    </h3>
</div>
<!-- /.card-header -->
<div class="card-body" style="padding: 8px 10px;">
  <?php if ($bindAlertMsg !== '') { ?>
  <div style="padding: 6px 10px; margin-bottom: 8px; border-radius: 5px; font-size: 12px; font-weight: 600; background: <?= $bindAlertType === 'success' ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)'; ?>; border: 1px solid <?= $bindAlertType === 'success' ? '#10b981' : '#ef4444'; ?>; color: <?= $bindAlertType === 'success' ? '#34d399' : '#f87171'; ?>;">
    <i class="fa <?= $bindAlertType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?= $bindAlertMsg; ?>
  </div>
  <?php } ?>

  <div class="ngw-compact-hosts-toolbar">
    <input id="filterTable" type="text" class="form-control ngw-compact-hosts-search" placeholder="<?= $isBn ? 'হোস্ট খুঁজুন (MAC / IP / কমেন্ট)..' : 'Search host (MAC / IP / Comment)..'; ?>">
    <?php if ($canBindHost) { ?>
    <span style="font-size: 11px; opacity: 0.85;">
      <i class="fa fa-info-circle text-info"></i> <?= $isBn ? 'যেকোনো হোস্টকে সরাসরি IP বাইন্ডিং করতে "IP Binding" বাটনে ক্লিক করুন' : 'Click "IP Binding" on any host to bind by MAC+IP or MAC only'; ?>
    </span>
    <?php } ?>
  </div>

<div class="overflow box-bordered" style="max-height: 75vh"> 	   
<table id="dataTable" class="table table-bordered table-hover text-nowrap ngw-compact-hosts-table">
  <thead>
  <tr>
    <th style="width:1%; text-align:center;"><?= $isBn ? 'অ্যাকশন' : 'Action'; ?></th>
    <th style="width:1%; text-align:center;"><?= $isBn ? 'স্ট্যাটাস' : 'Status'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'ম্যাক অ্যাড্রেস' : 'MAC Address'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'অ্যাড্রেস' : 'Address'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'টু অ্যাড্রেস' : 'To Address'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'সার্ভার' : 'Server'; ?></th>
	<th class="pointer ngw-host-comment-col" title="Click to sort"><i class="fa fa-sort"></i> <?= $_comment ?></th>
  </tr>
  </thead>
  <tbody>  	
<?php
for ($i = 0; $i < $TotalReg; $i++) {
	$hosts = $gethosts[$i];
	$id = isset($hosts['.id']) ? $hosts['.id'] : '';

	$maca = isset($hosts['mac-address']) ? $hosts['mac-address'] : '';
	$addr = isset($hosts['address']) ? $hosts['address'] : '';
	$toaddr = isset($hosts['to-address']) ? $hosts['to-address'] : '';
	$server = isset($hosts['server']) ? $hosts['server'] : 'all';
	$commt = isset($hosts['comment']) ? $hosts['comment'] : '';

	$uriprocess = "'./?remove-host=" . $id . "&session=" . $session . "'";

	$jsHostData = htmlspecialchars(json_encode(array(
		'id'      => $id,
		'mac'     => $maca,
		'addr'    => $addr,
		'toaddr'  => $toaddr,
		'server'  => $server !== '' ? $server : 'all',
		'comment' => $commt,
	)), ENT_QUOTES, 'UTF-8');

	echo "<tr>";
	echo "<td class='ngw-host-action-cell'>";
	if ($canRemoveHost) {
		echo "<span class='pointer' style='margin-right:5px;' title='" . ($isBn ? 'হোস্ট রিমুভ করুন: ' : 'Remove ') . htmlspecialchars($maca, ENT_QUOTES) . "' onclick=loadpage(" . $uriprocess . ")><i class='fa fa-minus-square text-danger'></i></span>";
	}
	if ($canBindHost) {
		echo "<button type='button' class='ngw-host-bind-btn' onclick='ngwOpenHostBindModal(" . $jsHostData . ")' title='" . ($isBn ? 'সরাসরি আইপি বাইন্ডিং করুন' : 'Direct IP Binding') . "'><i class='fa fa-link'></i> " . ($isBn ? 'বাইন্ডিং' : 'Bind') . "</button>";
	}
	echo "</td>";
	echo "<td style='text-align:center; width:1%;'>";
	if (isset($hosts['authorized']) && $hosts['authorized'] == "true" && isset($hosts['DHCP']) && $hosts['DHCP'] == "true") {
		echo "<span class='ngw-host-status-badge text-success' title='A - Authorized, H - DHCP'>A H</span>";
	} elseif (isset($hosts['authorized']) && $hosts['authorized'] == "true" && isset($hosts['dynamic']) && $hosts['dynamic'] == "true") {
		echo "<span class='ngw-host-status-badge text-success' title='A - Authorized, D - Dynamic'>A D</span>";
	} elseif (isset($hosts['authorized']) && $hosts['authorized'] == "true") {
		echo "<span class='ngw-host-status-badge text-success' title='A - Authorized'>A</span>";
	} elseif (isset($hosts['DHCP']) && $hosts['DHCP'] == "true") {
		echo "<span class='ngw-host-status-badge text-success' title='H - DHCP'>H</span>";
	} elseif (isset($hosts['dynamic']) && $hosts['dynamic'] == "true") {
		echo "<span class='ngw-host-status-badge text-success' title='D - Dynamic'>D</span>";
	} elseif (isset($hosts['bypassed']) && $hosts['bypassed'] == "true") {
		echo "<span class='ngw-host-status-badge text-primary' title='P - Bypassed'>P</span>";
	}
	echo "</td>";
	if ($canBindHost) {
		echo "<td class='pointer' onclick='ngwOpenHostBindModal(" . $jsHostData . ")' title='" . ($isBn ? 'ক্লিক করে IP বাইন্ডিং করুন' : 'Click to create IP Binding') . "'><span style='font-family:monospace; font-weight:600;'>" . htmlspecialchars($maca) . "</span></td>";
		echo "<td class='pointer' onclick='ngwOpenHostBindModal(" . $jsHostData . ")' title='" . ($isBn ? 'ক্লিক করে IP বাইন্ডিং করুন' : 'Click to create IP Binding') . "'>" . htmlspecialchars($addr) . "</td>";
	} else {
		echo "<td><span style='font-family:monospace;'>" . htmlspecialchars($maca) . "</span></td>";
		echo "<td>" . htmlspecialchars($addr) . "</td>";
	}
	echo "<td>" . htmlspecialchars($toaddr) . "</td>";
	echo "<td>" . htmlspecialchars($server) . "</td>";
	echo "<td class='ngw-host-comment-col' title='" . htmlspecialchars($commt, ENT_QUOTES) . "'>";
	if ($commt !== '') {
		echo "<span class='ngw-host-comment-box'>" . htmlspecialchars($commt) . "</span>";
	} else {
		echo "<span style='opacity:0.35;'>-</span>";
	}
	echo "</td>";
	echo "</tr>";
}
?>
  </tbody>
</table>
</div>
</div>
</div>
</div>
</div>

<?php if ($canBindHost) { ?>
<div id="ngwHostBindModal" class="ngw-bind-modal-backdrop" onclick="if(event.target===this)ngwCloseHostBindModal();">
  <div class="ngw-bind-modal-card">
    <div class="ngw-bind-modal-header">
      <h4><i class="fa fa-link"></i> <?= $isBn ? 'হোস্ট থেকে সরাসরি আইপি বাইন্ডিং' : 'Direct Host to IP Binding'; ?></h4>
      <button type="button" onclick="ngwCloseHostBindModal()" style="background:none;border:none;color:#94a3b8;font-size:16px;cursor:pointer;">&times;</button>
    </div>
    <div class="ngw-bind-modal-body">
      <form method="post" action="">
        <input type="hidden" name="ngw_host_to_binding" value="1">
        <input type="hidden" name="host_id" id="ngwBindHostId" value="">
        <input type="hidden" name="binding_type" id="ngwBindTypeInput" value="bypassed">

        <div class="ngw-bind-field">
          <label>
            <span><?= $isBn ? 'ম্যাক অ্যাড্রেস (MAC Address)' : 'MAC Address'; ?></span>
          </label>
          <input type="text" name="mac_address" id="ngwBindMac" required placeholder="00:11:22:33:44:55" style="font-family:monospace;">
        </div>

        <div class="ngw-bind-field">
          <label>
            <span><?= $isBn ? 'আইপি অ্যাড্রেস (IP Address)' : 'IP Address'; ?> <small style="opacity:0.75;font-weight:400;">(<?= $isBn ? 'ঐচ্ছিক / রিমুভ করলে শুধু MAC বাইন্ডিং হবে' : 'Optional / Clear for MAC-only'; ?>)</small></span>
            <button type="button" class="ngw-clear-ip-btn" onclick="document.getElementById('ngwBindAddr').value=''; document.getElementById('ngwBindToAddr').value='';" title="<?= $isBn ? 'আইপি রিমুভ করে শুধু ম্যাক দিয়ে বাইন্ডিং করুন' : 'Remove IP to bind by MAC address only'; ?>">
              <i class="fa fa-eraser"></i> <?= $isBn ? 'শুধু MAC (IP মুছুন)' : 'MAC Only (Clear IP)'; ?>
            </button>
          </label>
          <input type="text" name="address" id="ngwBindAddr" placeholder="<?= $isBn ? 'ফাঁকা রাখলে শুধু MAC দিয়ে বাইন্ডিং হবে' : 'Leave blank to bind by MAC only'; ?>">
        </div>

        <div class="ngw-bind-field">
          <label>
            <span><?= $isBn ? 'টু অ্যাড্রেস (To Address)' : 'To Address'; ?> <small style="opacity:0.75;font-weight:400;">(<?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?>)</small></span>
            <button type="button" class="ngw-clear-ip-btn" style="padding:0px 5px;font-size:10px;" onclick="document.getElementById('ngwBindToAddr').value='';">
              <?= $isBn ? 'মুছুন' : 'Clear'; ?>
            </button>
          </label>
          <input type="text" name="to_address" id="ngwBindToAddr" placeholder="<?= $isBn ? 'ঐচ্ছিক (ফাঁকা রাখতে পারেন)' : 'Optional'; ?>">
        </div>

        <div class="ngw-bind-field">
          <label>
            <span><?= $isBn ? 'হটস্পট সার্ভার (Server)' : 'Hotspot Server'; ?></span>
          </label>
          <select name="server" id="ngwBindServer">
            <option value="all">all</option>
            <?php
            for ($s = 0; $s < count($srvlist); $s++) {
              $sName = isset($srvlist[$s]['name']) ? $srvlist[$s]['name'] : '';
              if ($sName !== '') {
                echo "<option value='" . htmlspecialchars($sName, ENT_QUOTES) . "'>" . htmlspecialchars($sName) . "</option>";
              }
            }
            ?>
          </select>
        </div>

        <div class="ngw-bind-field">
          <label>
            <span><?= $isBn ? 'বাইন্ডিং স্ট্যাটাস / টাইপ (Type)' : 'Binding Status / Type'; ?></span>
          </label>
          <div class="ngw-type-pills">
            <div id="ngwPillRegular" class="ngw-type-pill" onclick="ngwSelectBindType('regular')">
              <i class="fa fa-circle-o"></i> Regular
            </div>
            <div id="ngwPillBypassed" class="ngw-type-pill active-bypassed" onclick="ngwSelectBindType('bypassed')">
              <i class="fa fa-check-circle"></i> Bypassed
            </div>
            <div id="ngwPillBlocked" class="ngw-type-pill" onclick="ngwSelectBindType('blocked')">
              <i class="fa fa-ban"></i> Blocked
            </div>
          </div>
        </div>

        <div class="ngw-bind-field">
          <label>
            <span><?= $isBn ? 'কমেন্ট (Comment)' : 'Comment'; ?></span>
          </label>
          <input type="text" name="comment" id="ngwBindComment" placeholder="<?= $isBn ? 'ডিভাইস বা ইউজারের নাম / নোট লিখুন...' : 'Device or user note...'; ?>">
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
          <button type="button" class="btn bg-warning" style="padding:4px 12px; font-size:12px;" onclick="ngwCloseHostBindModal()">
            <i class="fa fa-close"></i> <?= $_close ?>
          </button>
          <button type="submit" class="btn bg-primary" style="padding:4px 14px; font-size:12px; font-weight:600;">
            <i class="fa fa-save"></i> <?= $isBn ? 'বাইন্ডিং সেভ করুন' : 'Save Binding'; ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ngwSelectBindType(typeVal) {
  document.getElementById('ngwBindTypeInput').value = typeVal;
  var pReg = document.getElementById('ngwPillRegular');
  var pByp = document.getElementById('ngwPillBypassed');
  var pBlk = document.getElementById('ngwPillBlocked');
  pReg.className = 'ngw-type-pill' + (typeVal === 'regular' ? ' active-regular' : '');
  pByp.className = 'ngw-type-pill' + (typeVal === 'bypassed' ? ' active-bypassed' : '');
  pBlk.className = 'ngw-type-pill' + (typeVal === 'blocked' ? ' active-blocked' : '');
}
function ngwOpenHostBindModal(host) {
  if (!host) return;
  document.getElementById('ngwBindHostId').value = host.id || '';
  document.getElementById('ngwBindMac').value = host.mac || '';
  document.getElementById('ngwBindAddr').value = host.addr || '';
  document.getElementById('ngwBindToAddr').value = host.toaddr || '';
  document.getElementById('ngwBindComment').value = host.comment || '';
  var srvSelect = document.getElementById('ngwBindServer');
  var targetSrv = host.server || 'all';
  var matched = false;
  for (var i = 0; i < srvSelect.options.length; i++) {
    if (srvSelect.options[i].value === targetSrv) {
      srvSelect.selectedIndex = i;
      matched = true;
      break;
    }
  }
  if (!matched) {
    srvSelect.value = 'all';
  }
  ngwSelectBindType('bypassed');
  document.getElementById('ngwHostBindModal').style.display = 'flex';
}
function ngwCloseHostBindModal() {
  document.getElementById('ngwHostBindModal').style.display = 'none';
}
</script>
<?php } ?>