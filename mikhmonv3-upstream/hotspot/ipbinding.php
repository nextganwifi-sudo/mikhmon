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
	$canManageBinding = hasResellerPermission('manage_ipbinding');
	$canEditBinding = (hasResellerPermission('edit_ipbinding') || $canManageBinding);

	$ipbAlertMsg = '';
	$ipbAlertType = '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ngw_save_ipbinding'])) {
		if (!$canEditBinding) {
			$ipbAlertType = 'danger';
			$ipbAlertMsg = $isBn ? 'আপনার IP বাইন্ডিং এডিট বা যোগ করার অনুমতি নেই।' : 'You do not have permission to add or edit IP Bindings.';
		} else {
			$bindingId = trim((string)($_POST['binding_id'] ?? ''));
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
			$postDisabled = (isset($_POST['disabled']) && $_POST['disabled'] === 'yes') ? 'yes' : 'no';
			$postComment = trim((string)($_POST['comment'] ?? ''));

			if ($postMac === '' && $postAddr === '') {
				$ipbAlertType = 'danger';
				$ipbAlertMsg = $isBn ? 'ম্যাক অ্যাড্রেস অথবা আইপি অ্যাড্রেস প্রদান করা আবশ্যক।' : 'MAC Address or IP Address is required.';
			} else {
				if ($bindingId !== '') {
					$setParams = array(
						".id"      => $bindingId,
						"server"   => $postServer,
						"type"     => $postType,
						"comment"  => $postComment,
						"disabled" => $postDisabled,
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

					if ($postAddr === '') {
						$API->comm("/ip/hotspot/ip-binding/unset", array(
							".id"        => $bindingId,
							"value-name" => "address",
						));
					}
					if ($postToAddr === '') {
						$API->comm("/ip/hotspot/ip-binding/unset", array(
							".id"        => $bindingId,
							"value-name" => "to-address",
						));
					}
					if ($postMac === '') {
						$API->comm("/ip/hotspot/ip-binding/unset", array(
							".id"        => $bindingId,
							"value-name" => "mac-address",
						));
					}

					$ipbAlertType = 'success';
					$ipbAlertMsg = $isBn
						? ('IP বাইন্ডিং সফলভাবে আপডেট হয়েছে (' . htmlspecialchars($postMac !== '' ? $postMac : $postAddr) . ')।')
						: ('IP Binding updated successfully (' . htmlspecialchars($postMac !== '' ? $postMac : $postAddr) . ').');
				} else {
					$addParams = array(
						"server"   => $postServer,
						"type"     => $postType,
						"comment"  => $postComment,
						"disabled" => $postDisabled,
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

					$ipbAlertType = 'success';
					$ipbAlertMsg = $isBn
						? ('নতুন IP বাইন্ডিং সফলভাবে যুক্ত হয়েছে (' . htmlspecialchars($postMac !== '' ? $postMac : $postAddr) . ')।')
						: ('New IP Binding added successfully (' . htmlspecialchars($postMac !== '' ? $postMac : $postAddr) . ').');
				}
			}
		}
	}

	$srvlist = $API->comm("/ip/hotspot/print");
	if (!is_array($srvlist)) {
		$srvlist = array();
	}

	$getbinding = $API->comm("/ip/hotspot/ip-binding/print");
	$TotalReg = is_array($getbinding) ? count($getbinding) : 0;
	$countbinding = $TotalReg;
}
?>
<style>
.ngw-compact-ipb-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 8px;
}
.ngw-compact-ipb-search {
	width: 240px;
	max-width: 100%;
	height: 30px !important;
	padding: 4px 10px !important;
	font-size: 12px !important;
	border-radius: 5px !important;
}
.ngw-compact-ipb-table {
	font-size: 12px !important;
	margin-bottom: 0 !important;
}
.ngw-compact-ipb-table thead th {
	padding: 6px 8px !important;
	font-size: 11.5px !important;
	vertical-align: middle !important;
	white-space: nowrap;
}
.ngw-compact-ipb-table tbody td {
	padding: 4px 8px !important;
	vertical-align: middle !important;
	line-height: 1.3 !important;
}
.ngw-ipb-action-cell {
	white-space: nowrap;
	text-align: center;
	width: 1%;
}
.ngw-ipb-edit-btn {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 2px 7px;
	font-size: 11px;
	font-weight: 600;
	line-height: 1.3;
	border-radius: 4px;
	border: 1px solid rgba(59, 130, 246, 0.45);
	background: rgba(59, 130, 246, 0.14);
	color: #60a5fa;
	cursor: pointer;
	transition: all 0.15s ease;
}
.ngw-ipb-edit-btn:hover {
	background: #2563eb;
	color: #fff !important;
	border-color: #2563eb;
}
.ngw-ipb-type-badge {
	display: inline-block;
	padding: 1px 6px;
	font-size: 10.5px;
	font-weight: 700;
	border-radius: 4px;
	line-height: 1.25;
}
.ngw-ipb-type-bypassed {
	background: rgba(16, 185, 129, 0.16);
	color: #34d399;
	border: 1px solid rgba(16, 185, 129, 0.35);
}
.ngw-ipb-type-blocked {
	background: rgba(239, 68, 68, 0.16);
	color: #f87171;
	border: 1px solid rgba(239, 68, 68, 0.35);
}
.ngw-ipb-type-regular {
	background: rgba(59, 130, 246, 0.16);
	color: #60a5fa;
	border: 1px solid rgba(59, 130, 246, 0.35);
}
.ngw-ipb-comment-col {
	max-width: 180px;
	width: 180px;
}
.ngw-ipb-comment-box {
	display: inline-block;
	max-width: 170px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	vertical-align: middle;
	font-size: 11.5px;
	padding: 1px 6px;
	border-radius: 4px;
	background: rgba(148, 163, 184, 0.1);
}
.ngw-ipb-modal-backdrop {
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
.ngw-ipb-modal-card {
	background: #1e293b;
	color: #f8fafc;
	border: 1px solid rgba(148, 163, 184, 0.28);
	border-radius: 8px;
	width: 100%;
	max-width: 430px;
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.45);
	overflow: hidden;
}
.ngw-ipb-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	background: rgba(15, 23, 42, 0.65);
	border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-ipb-modal-header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
	color: #38bdf8;
}
.ngw-ipb-modal-body {
	padding: 12px 14px;
}
.ngw-ipb-field {
	margin-bottom: 9px;
}
.ngw-ipb-field label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 11.5px;
	font-weight: 600;
	margin-bottom: 3px;
	color: #cbd5e1;
}
.ngw-ipb-field input,
.ngw-ipb-field select {
	width: 100%;
	height: 31px !important;
	padding: 4px 9px !important;
	font-size: 12px !important;
	border-radius: 5px !important;
	background: #0f172a !important;
	color: #f8fafc !important;
	border: 1px solid rgba(148, 163, 184, 0.32) !important;
}
.ngw-ipb-clear-btn {
	padding: 1px 6px;
	font-size: 10.5px;
	font-weight: 600;
	border-radius: 4px;
	border: 1px solid rgba(245, 158, 11, 0.45);
	background: rgba(245, 158, 11, 0.15);
	color: #fbbf24;
	cursor: pointer;
}
.ngw-ipb-clear-btn:hover {
	background: #f59e0b;
	color: #111827;
}
.ngw-ipb-type-pills {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 6px;
}
.ngw-ipb-type-pill {
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
.ngw-ipb-type-pill.active-regular {
	background: rgba(59, 130, 246, 0.2);
	border-color: #3b82f6;
	color: #60a5fa;
}
.ngw-ipb-type-pill.active-bypassed {
	background: rgba(16, 185, 129, 0.2);
	border-color: #10b981;
	color: #34d399;
}
.ngw-ipb-type-pill.active-blocked {
	background: rgba(239, 68, 68, 0.2);
	border-color: #ef4444;
	color: #f87171;
}
@media (max-width: 768px) {
	.ngw-compact-ipb-search {
		width: 100%;
	}
	.ngw-compact-ipb-table {
		font-size: 11px !important;
	}
	.ngw-compact-ipb-table thead th,
	.ngw-compact-ipb-table tbody td {
		padding: 3px 5px !important;
	}
	.ngw-ipb-comment-col {
		max-width: 110px;
		width: 110px;
	}
	.ngw-ipb-comment-box {
		max-width: 100px;
		font-size: 10.5px;
	}
	.ngw-ipb-edit-btn {
		padding: 2px 5px;
		font-size: 10px;
	}
}
</style>

<div class="row">
<div id="reloadbinding">
<div class="col-12">
<div class="card">
<div class="card-header">
	<h3><i class="fa fa-address-book"></i> <?= $_ip_bindings ?> 
<?php
if ($countbinding < 2) {
	echo $countbinding . ($isBn ? " টি আইটেম" : " item");
} elseif ($countbinding > 1) {
	echo $countbinding . ($isBn ? " টি আইটেম" : " items");
};
?>
		 | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="Reload data"></i>
    </h3>
</div>
<div class="card-body" style="padding: 8px 10px;">
  <?php if ($ipbAlertMsg !== '') { ?>
  <div style="padding: 6px 10px; margin-bottom: 8px; border-radius: 5px; font-size: 12px; font-weight: 600; background: <?= $ipbAlertType === 'success' ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)'; ?>; border: 1px solid <?= $ipbAlertType === 'success' ? '#10b981' : '#ef4444'; ?>; color: <?= $ipbAlertType === 'success' ? '#34d399' : '#f87171'; ?>;">
    <i class="fa <?= $ipbAlertType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?= $ipbAlertMsg; ?>
  </div>
  <?php } ?>

  <div class="ngw-compact-ipb-toolbar">
    <input id="filterTable" type="text" class="form-control ngw-compact-ipb-search" placeholder="<?= $isBn ? 'খুঁজুন (MAC / IP / কমেন্ট)..' : 'Search (MAC / IP / Comment)..'; ?>">
    <?php if ($canEditBinding) { ?>
    <button type="button" class="btn bg-primary" style="padding: 4px 10px; font-size: 11.5px; font-weight: 600; margin: 0;" onclick="ngwOpenIpbModal(null)">
      <i class="fa fa-plus-circle"></i> <?= $isBn ? 'নতুন IP বাইন্ডিং' : 'Add IP Binding'; ?>
    </button>
    <?php } ?>
  </div>

<div class="overflow box-bordered" style="max-height: 75vh">  	   
<table id="dataTable" class="table table-bordered table-hover text-nowrap ngw-compact-ipb-table"> 
 <thead>
  <tr>
    <th style="width:1%; text-align:center;"><?= $isBn ? 'অ্যাকশন' : 'Action'; ?></th>
    <th style="width:1%; text-align:center;"><?= $isBn ? 'স্ট্যাটাস' : 'Type'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'ম্যাক অ্যাড্রেস' : 'MAC Address'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'অ্যাড্রেস' : 'Address'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'টু অ্যাড্রেস' : 'To Address'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $isBn ? 'সার্ভার' : 'Server'; ?></th>
    <th class="pointer ngw-ipb-comment-col" title="Click to sort"><i class="fa fa-sort"></i> <?= $_comment ?></th>
  </tr>
  </thead>
  <tbody> 
<?php
for ($i = 0; $i < $TotalReg; $i++) {
	$binding = $getbinding[$i];
	$id = isset($binding['.id']) ? $binding['.id'] : '';

	$maca = isset($binding['mac-address']) ? $binding['mac-address'] : '';
	$addr = isset($binding['address']) ? $binding['address'] : '';
	$toaddr = isset($binding['to-address']) ? $binding['to-address'] : '';
	$server = isset($binding['server']) ? $binding['server'] : 'all';
	$commt = isset($binding['comment']) ? $binding['comment'] : '';
	$bdisabled = isset($binding['disabled']) ? $binding['disabled'] : 'false';

	$rawType = isset($binding['type']) ? strtolower(trim((string)$binding['type'])) : '';
	if ($rawType === '') {
		if (isset($binding['bypassed']) && $binding['bypassed'] == 'true') {
			$rawType = 'bypassed';
		} elseif (isset($binding['blocked']) && $binding['blocked'] == 'true') {
			$rawType = 'blocked';
		} else {
			$rawType = 'regular';
		}
	}

	$jsBindData = htmlspecialchars(json_encode(array(
		'id'       => $id,
		'mac'      => $maca,
		'addr'     => $addr,
		'toaddr'   => $toaddr,
		'server'   => $server !== '' ? $server : 'all',
		'type'     => $rawType,
		'disabled' => ($bdisabled == 'true') ? 'yes' : 'no',
		'comment'  => $commt,
	)), ENT_QUOTES, 'UTF-8');

	echo "<tr>";
	?>
  	<td class="ngw-ipb-action-cell">
  	<?php if ($canManageBinding) {
  		$delConfirmMsg = $isBn
  			? ('আপনি কি নিশ্চিত যে এই বাইন্ডিং (' . ($maca !== '' ? $maca : $addr) . ') ডিলিট করতে চান?')
  			: ('Are you sure to delete (' . ($maca !== '' ? $maca : $addr) . ')?');
  		$delUrl = './?remove-ip-binding=' . $id . '&mac=' . rawurlencode($maca) . '&addr=' . rawurlencode($addr) . '&session=' . rawurlencode($session);
  	?>
  	<i class='fa fa-minus-square text-danger pointer' style='margin-right:6px;' onclick='if(confirm(<?= json_encode($delConfirmMsg); ?>)){loadpage(<?= json_encode($delUrl); ?>)}' title='Remove <?= htmlspecialchars($maca !== '' ? $maca : $addr, ENT_QUOTES); ?>'></i>
  	<?php
		if ($bdisabled == "true") {
			$uriprocess = "'./?enable-ip-binding=" . $id . "&session=" . $session . "'";
			echo "<span class='text-warning btnsmall pointer' style='margin-right:6px;' title='Enable Binding " . htmlspecialchars($addr, ENT_QUOTES) . "' onclick=loadpage(".$uriprocess.")><i class='fa fa-lock'></i></span>";
		} else {
			$uriprocess = "'./?disable-ip-binding=" . $id . "&session=" . $session . "'";
			echo "<span title='Disable Binding " . htmlspecialchars($addr, ENT_QUOTES) . "' class='btnsmall pointer' style='margin-right:6px;' onclick=loadpage(".$uriprocess.")><i class='fa fa-unlock'></i></span>";
		}
	}
	if ($canEditBinding) {
		echo "<button type='button' class='ngw-ipb-edit-btn' onclick='ngwOpenIpbModal(" . $jsBindData . ")' title='" . ($isBn ? 'এডিট ও কমেন্ট পরিবর্তন করুন' : 'Edit Binding & Comment') . "'><i class='fa fa-edit'></i> " . ($isBn ? 'এডিট' : 'Edit') . "</button>";
	}
	echo "</td>";
	echo "<td style='text-align:center; width:1%;'>";
	if ($rawType === 'bypassed') {
		echo "<span class='ngw-ipb-type-badge ngw-ipb-type-bypassed' title='Bypassed'>Bypassed</span>";
	} elseif ($rawType === 'blocked') {
		echo "<span class='ngw-ipb-type-badge ngw-ipb-type-blocked' title='Blocked'>Blocked</span>";
	} else {
		echo "<span class='ngw-ipb-type-badge ngw-ipb-type-regular' title='Regular'>Regular</span>";
	}
	echo "</td>";
	if ($canEditBinding) {
		echo "<td class='pointer' onclick='ngwOpenIpbModal(" . $jsBindData . ")' title='" . ($isBn ? 'ক্লিক করে এডিট করুন' : 'Click to edit') . "'><span style='font-family:monospace; font-weight:600;'>" . htmlspecialchars($maca !== '' ? $maca : '-') . "</span></td>";
		echo "<td class='pointer' onclick='ngwOpenIpbModal(" . $jsBindData . ")' title='" . ($isBn ? 'ক্লিক করে এডিট করুন' : 'Click to edit') . "'>" . ($addr !== '' ? htmlspecialchars($addr) : "<span style='opacity:0.45;font-size:11px;'>" . ($isBn ? 'শুধু MAC' : 'MAC Only') . "</span>") . "</td>";
	} else {
		echo "<td><span style='font-family:monospace;'>" . htmlspecialchars($maca !== '' ? $maca : '-') . "</span></td>";
		echo "<td>" . ($addr !== '' ? htmlspecialchars($addr) : '-') . "</td>";
	}
	echo "<td>" . ($toaddr !== '' ? htmlspecialchars($toaddr) : '<span style="opacity:0.35;">-</span>') . "</td>";
	echo "<td>" . htmlspecialchars($server !== '' ? $server : 'all') . "</td>";
	echo "<td class='ngw-ipb-comment-col' title='" . htmlspecialchars($commt, ENT_QUOTES) . "'>";
	if ($commt !== '') {
		echo "<span class='ngw-ipb-comment-box'>" . htmlspecialchars($commt) . "</span>";
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
</div>

<?php if ($canEditBinding) { ?>
<div id="ngwIpbModal" class="ngw-ipb-modal-backdrop" onclick="if(event.target===this)ngwCloseIpbModal();">
  <div class="ngw-ipb-modal-card">
    <div class="ngw-ipb-modal-header">
      <h4 id="ngwIpbModalTitle"><i class="fa fa-edit"></i> <?= $isBn ? 'IP বাইন্ডিং এডিট করুন' : 'Edit IP Binding'; ?></h4>
      <button type="button" onclick="ngwCloseIpbModal()" style="background:none;border:none;color:#94a3b8;font-size:16px;cursor:pointer;">&times;</button>
    </div>
    <div class="ngw-ipb-modal-body">
      <form method="post" action="">
        <input type="hidden" name="ngw_save_ipbinding" value="1">
        <input type="hidden" name="binding_id" id="ngwIpbId" value="">
        <input type="hidden" name="binding_type" id="ngwIpbTypeInput" value="bypassed">

        <div class="ngw-ipb-field">
          <label>
            <span><?= $isBn ? 'ম্যাক অ্যাড্রেস (MAC Address)' : 'MAC Address'; ?></span>
          </label>
          <input type="text" name="mac_address" id="ngwIpbMac" placeholder="00:11:22:33:44:55" style="font-family:monospace;">
        </div>

        <div class="ngw-ipb-field">
          <label>
            <span><?= $isBn ? 'অ্যাড্রেস / আইপি (Address)' : 'Address (IP)'; ?> <small style="opacity:0.75;font-weight:400;">(<?= $isBn ? 'ফাঁকা রাখলে শুধু MAC বাইন্ডিং' : 'Blank = MAC Only'; ?>)</small></span>
            <button type="button" class="ngw-ipb-clear-btn" onclick="document.getElementById('ngwIpbAddr').value=''; document.getElementById('ngwIpbToAddr').value='';" title="<?= $isBn ? 'আইপি মুছে শুধু ম্যাক দিয়ে বাইন্ডিং করুন' : 'Clear IP to bind by MAC only'; ?>">
              <i class="fa fa-eraser"></i> <?= $isBn ? 'শুধু MAC (IP মুছুন)' : 'MAC Only (Clear IP)'; ?>
            </button>
          </label>
          <input type="text" name="address" id="ngwIpbAddr" placeholder="<?= $isBn ? 'যেমন: 10.5.50.10 অথবা ফাঁকা রাখুন' : 'e.g. 10.5.50.10 or leave blank'; ?>">
        </div>

        <div class="ngw-ipb-field">
          <label>
            <span><?= $isBn ? 'টু অ্যাড্রেস (To Address)' : 'To Address'; ?> <small style="opacity:0.75;font-weight:400;">(<?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?>)</small></span>
            <button type="button" class="ngw-ipb-clear-btn" style="padding:0px 5px;font-size:10px;" onclick="document.getElementById('ngwIpbToAddr').value='';">
              <?= $isBn ? 'মুছুন' : 'Clear'; ?>
            </button>
          </label>
          <input type="text" name="to_address" id="ngwIpbToAddr" placeholder="<?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?>">
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
          <div class="ngw-ipb-field">
            <label><span><?= $isBn ? 'সার্ভার (Server)' : 'Server'; ?></span></label>
            <select name="server" id="ngwIpbServer">
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
          <div class="ngw-ipb-field">
            <label><span><?= $isBn ? 'সক্রিয় (Enabled)' : 'Enabled'; ?></span></label>
            <select name="disabled" id="ngwIpbDisabled">
              <option value="no"><?= $_yes ?></option>
              <option value="yes"><?= $_no ?></option>
            </select>
          </div>
        </div>

        <div class="ngw-ipb-field">
          <label>
            <span><?= $isBn ? 'বাইন্ডিং স্ট্যাটাস / টাইপ (Type)' : 'Binding Status / Type'; ?></span>
          </label>
          <div class="ngw-ipb-type-pills">
            <div id="ngwIpbPillRegular" class="ngw-ipb-type-pill" onclick="ngwSelectIpbType('regular')">
              <i class="fa fa-circle-o"></i> Regular
            </div>
            <div id="ngwIpbPillBypassed" class="ngw-ipb-type-pill active-bypassed" onclick="ngwSelectIpbType('bypassed')">
              <i class="fa fa-check-circle"></i> Bypassed
            </div>
            <div id="ngwIpbPillBlocked" class="ngw-ipb-type-pill" onclick="ngwSelectIpbType('blocked')">
              <i class="fa fa-ban"></i> Blocked
            </div>
          </div>
        </div>

        <div class="ngw-ipb-field">
          <label>
            <span><?= $isBn ? 'কমেন্ট (Comment)' : 'Comment'; ?></span>
          </label>
          <input type="text" name="comment" id="ngwIpbComment" placeholder="<?= $isBn ? 'কমেন্ট বা নোট লিখুন...' : 'Enter comment or note...'; ?>">
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
          <button type="button" class="btn bg-warning" style="padding:4px 12px; font-size:12px;" onclick="ngwCloseIpbModal()">
            <i class="fa fa-close"></i> <?= $_close ?>
          </button>
          <button type="submit" class="btn bg-primary" style="padding:4px 14px; font-size:12px; font-weight:600;">
            <i class="fa fa-save"></i> <?= $_save ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ngwSelectIpbType(typeVal) {
  document.getElementById('ngwIpbTypeInput').value = typeVal;
  var pReg = document.getElementById('ngwIpbPillRegular');
  var pByp = document.getElementById('ngwIpbPillBypassed');
  var pBlk = document.getElementById('ngwIpbPillBlocked');
  pReg.className = 'ngw-ipb-type-pill' + (typeVal === 'regular' ? ' active-regular' : '');
  pByp.className = 'ngw-ipb-type-pill' + (typeVal === 'bypassed' ? ' active-bypassed' : '');
  pBlk.className = 'ngw-ipb-type-pill' + (typeVal === 'blocked' ? ' active-blocked' : '');
}
function ngwOpenIpbModal(item) {
  var titleEl = document.getElementById('ngwIpbModalTitle');
  if (item && item.id) {
    titleEl.innerHTML = '<i class="fa fa-edit"></i> <?= $isBn ? "IP বাইন্ডিং এডিট করুন" : "Edit IP Binding"; ?>';
    document.getElementById('ngwIpbId').value = item.id || '';
    document.getElementById('ngwIpbMac').value = item.mac || '';
    document.getElementById('ngwIpbAddr').value = item.addr || '';
    document.getElementById('ngwIpbToAddr').value = item.toaddr || '';
    document.getElementById('ngwIpbComment').value = item.comment || '';
    document.getElementById('ngwIpbDisabled').value = item.disabled || 'no';
    var srvSelect = document.getElementById('ngwIpbServer');
    var targetSrv = item.server || 'all';
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
    ngwSelectIpbType(item.type || 'bypassed');
  } else {
    titleEl.innerHTML = '<i class="fa fa-plus-circle"></i> <?= $isBn ? "নতুন IP বাইন্ডিং যোগ করুন" : "Add IP Binding"; ?>';
    document.getElementById('ngwIpbId').value = '';
    document.getElementById('ngwIpbMac').value = '';
    document.getElementById('ngwIpbAddr').value = '';
    document.getElementById('ngwIpbToAddr').value = '';
    document.getElementById('ngwIpbComment').value = '';
    document.getElementById('ngwIpbDisabled').value = 'no';
    document.getElementById('ngwIpbServer').value = 'all';
    ngwSelectIpbType('bypassed');
  }
  document.getElementById('ngwIpbModal').style.display = 'flex';
}
function ngwCloseIpbModal() {
  document.getElementById('ngwIpbModal').style.display = 'none';
}
</script>
<?php } ?>
