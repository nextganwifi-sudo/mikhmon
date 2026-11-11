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
	position: relative;
}
.ngw-ipb-dots-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	border-radius: 4px;
	background: rgba(148, 163, 184, 0.16);
	border: 1px solid rgba(148, 163, 184, 0.32);
	color: #94a3b8;
	cursor: pointer;
	padding: 0;
	font-size: 13px;
	line-height: 1;
	transition: background 0.15s ease, color 0.15s ease;
}
.ngw-ipb-dots-btn:hover {
	background: #0ea5e9;
	color: #fff;
	border-color: #0ea5e9;
}
.ngw-ipb-dropdown-menu {
	display: none;
	position: fixed;
	z-index: 10050;
	min-width: 145px;
	background: #1e293b;
	color: #f8fafc;
	border: 1px solid rgba(148, 163, 184, 0.35);
	border-radius: 6px;
	box-shadow: 0 10px 25px rgba(0,0,0,0.45);
	padding: 4px 0;
	text-align: left;
}
.ngw-ipb-dropdown-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 7px 12px;
	font-size: 12px;
	font-weight: 600;
	color: #e2e8f0 !important;
	text-decoration: none !important;
	cursor: pointer;
	background: none;
	border: none;
	width: 100%;
	transition: background 0.12s ease;
}
.ngw-ipb-dropdown-item:hover {
	background: rgba(14, 165, 233, 0.22);
	color: #38bdf8 !important;
}
.ngw-ipb-dropdown-item.is-danger {
	color: #f87171 !important;
}
.ngw-ipb-dropdown-item.is-danger:hover {
	background: rgba(239, 68, 68, 0.18);
	color: #ef4444 !important;
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
.ngw-ipb-card-header-flex {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 6px 12px;
	width: 100%;
}
.ngw-ipb-card-header-flex h3 {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}
.ngw-ipb-modal-backdrop {
	display: none;
	position: fixed;
	z-index: 10060;
	left: 0;
	top: 0;
	width: 100%;
	height: 100%;
	background: rgba(15, 23, 42, 0.78);
	backdrop-filter: blur(4px);
	align-items: center;
	justify-content: center;
	padding: 12px;
	overflow-y: auto;
}
.ngw-ipb-modal-card {
	background: #1e293b;
	color: #f8fafc;
	border: 1px solid rgba(148, 163, 184, 0.28);
	border-top: 3px solid #0ea5e9;
	border-radius: 12px;
	width: 100%;
	max-width: 485px;
	box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.65);
	overflow: hidden;
	animation: ngwModalFadeIn 0.18s ease-out;
}
.ngw-ipb-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: linear-gradient(180deg, rgba(30, 41, 59, 0.95) 0%, rgba(15, 23, 42, 0.85) 100%);
	border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}
.ngw-ipb-modal-title-wrap {
	display: flex;
	align-items: center;
	gap: 10px;
}
.ngw-ipb-modal-icon-badge {
	width: 32px;
	height: 32px;
	border-radius: 8px;
	background: linear-gradient(135deg, rgba(14, 165, 233, 0.25) 0%, rgba(2, 132, 199, 0.15) 100%);
	border: 1px solid rgba(14, 165, 233, 0.4);
	color: #38bdf8;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 14px;
	flex-shrink: 0;
}
.ngw-ipb-modal-header h4 {
	margin: 0;
	font-size: 13.5px;
	font-weight: 700;
	color: #f1f5f9;
	line-height: 1.25;
}
.ngw-ipb-modal-sub {
	font-size: 11px;
	color: #94a3b8;
	display: block;
	margin-top: 1px;
}
.ngw-ipb-modal-close-btn {
	background: transparent;
	border: none;
	color: #94a3b8;
	font-size: 20px;
	line-height: 1;
	cursor: pointer;
	padding: 3px 8px;
	border-radius: 6px;
	transition: background 0.15s, color 0.15s;
}
.ngw-ipb-modal-close-btn:hover {
	background: rgba(239, 68, 68, 0.2);
	color: #f87171;
}
.ngw-ipb-modal-body {
	padding: 14px 16px;
	max-height: calc(88vh - 70px);
	overflow-y: auto;
}
.ngw-ipb-panel-box {
	border-radius: 8px;
	padding: 10px 12px;
	margin-bottom: 10px;
	background: rgba(15, 23, 42, 0.45);
	border: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-ipb-panel-blue {
	border-left: 3px solid #0284c7;
	background: linear-gradient(90deg, rgba(2, 132, 199, 0.08) 0%, rgba(15, 23, 42, 0.5) 100%);
}
.ngw-ipb-panel-amber {
	border-left: 3px solid #f59e0b;
	background: linear-gradient(90deg, rgba(245, 158, 11, 0.08) 0%, rgba(15, 23, 42, 0.5) 100%);
}
.ngw-ipb-panel-purple {
	border-left: 3px solid #a855f7;
	background: linear-gradient(90deg, rgba(168, 85, 247, 0.08) 0%, rgba(15, 23, 42, 0.5) 100%);
}
.ngw-ipb-panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 11.5px;
	font-weight: 700;
	color: #cbd5e1;
	margin-bottom: 8px;
	padding-bottom: 5px;
	border-bottom: 1px dashed rgba(148, 163, 184, 0.2);
}
.ngw-ipb-panel-badge {
	font-size: 9.5px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 3px;
	background: rgba(148, 163, 184, 0.15);
	color: #94a3b8;
	text-transform: uppercase;
}
.ngw-ipb-field-group {
	margin-bottom: 6px;
}
.ngw-ipb-field-label {
	font-size: 11px;
	font-weight: 600;
	color: #94a3b8;
	margin-bottom: 3px;
	display: block;
}
.ngw-ipb-input-with-icon {
	display: flex;
	align-items: stretch;
	border-radius: 6px;
	border: 1px solid rgba(148, 163, 184, 0.3);
	background: #0f172a;
	overflow: hidden;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.ngw-ipb-input-with-icon:focus-within {
	border-color: #38bdf8;
	box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.22);
}
.ngw-ipb-input-addon {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0 10px;
	background: rgba(30, 41, 59, 0.6);
	color: #64748b;
	font-size: 12px;
	border-right: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-ipb-input-with-icon input,
.ngw-ipb-input-with-icon select {
	flex: 1;
	background: transparent !important;
	border: none !important;
	outline: none !important;
	color: #f8fafc !important;
	padding: 6px 10px !important;
	font-size: 12px !important;
	height: 32px !important;
	margin: 0 !important;
	box-shadow: none !important;
}
.ngw-ipb-input-with-icon select option {
	background: #1e293b;
	color: #f8fafc;
}
.ngw-ipb-clear-btn {
	background: rgba(239, 68, 68, 0.18);
	border: 1px solid rgba(239, 68, 68, 0.4);
	color: #fca5a5;
	font-size: 10.5px;
	font-weight: 600;
	padding: 2px 7px;
	border-radius: 4px;
	cursor: pointer;
	line-height: 1.2;
	transition: background 0.15s;
}
.ngw-ipb-clear-btn:hover {
	background: #ef4444;
	color: #fff;
}
.ngw-ipb-type-pill-cards {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 6px;
	margin-top: 4px;
}
.ngw-ipb-type-card {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 8px;
	border-radius: 6px;
	border: 1px solid rgba(148, 163, 184, 0.25);
	background: rgba(30, 41, 59, 0.4);
	cursor: pointer;
	transition: all 0.15s ease;
	user-select: none;
}
.ngw-ipb-type-card:hover {
	transform: translateY(-1px);
}
.ngw-ipb-card-indicator {
	font-size: 13px;
	display: flex;
	align-items: center;
	justify-content: center;
}
.ngw-ipb-card-text {
	display: flex;
	flex-direction: column;
	line-height: 1.1;
}
.ngw-ipb-card-name {
	font-size: 11px;
	font-weight: 700;
}
.ngw-ipb-card-text small {
	font-size: 9px;
	opacity: 0.7;
}
.ngw-ipb-card-regular { color: #94a3b8; }
.ngw-ipb-card-regular.active-regular {
	border-color: #3b82f6;
	background: rgba(59, 130, 246, 0.2);
	color: #60a5fa;
	box-shadow: 0 0 8px rgba(59, 130, 246, 0.35);
}
.ngw-ipb-card-bypassed { color: #94a3b8; }
.ngw-ipb-card-bypassed.active-bypassed {
	border-color: #10b981;
	background: rgba(16, 185, 129, 0.22);
	color: #34d399;
	box-shadow: 0 0 8px rgba(16, 185, 129, 0.35);
}
.ngw-ipb-card-blocked { color: #94a3b8; }
.ngw-ipb-card-blocked.active-blocked {
	border-color: #ef4444;
	background: rgba(239, 68, 68, 0.22);
	color: #f87171;
	box-shadow: 0 0 8px rgba(239, 68, 68, 0.35);
}
.ngw-ipb-modal-footer {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 8px;
	margin-top: 12px;
	padding-top: 10px;
	border-top: 1px solid rgba(148, 163, 184, 0.18);
}
@media (max-width: 768px) {
	.card-header {
		padding: 8px 10px !important;
	}
	.ngw-ipb-card-header-flex {
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
	}
	.ngw-ipb-card-header-flex h3 {
		font-size: 13px !important;
		line-height: 1.3;
	}
	.ngw-compact-ipb-search {
		width: 100%;
	}
	.ngw-compact-ipb-table {
		font-size: 11px !important;
	}
	.ngw-compact-ipb-table thead th,
	.ngw-compact-ipb-table tbody td {
		padding: 4px 6px !important;
	}
	.ngw-ipb-comment-col {
		max-width: 110px;
		width: 110px;
	}
	.ngw-ipb-comment-box {
		max-width: 100px;
		font-size: 10.5px;
	}
}
</style>

<div class="row">
<div id="reloadbinding">
<div class="col-12">
<div class="card">
<div class="card-header">
	<div class="ngw-ipb-card-header-flex">
		<h3>
			<span><i class="fa fa-address-book"></i> <?= $_ip_bindings ?></span>
			<span style="font-size:12px; opacity:0.85; font-weight:normal;">
				(<?= $countbinding . ($isBn ? " টি আইটেম" : " items"); ?>)
			</span>
		</h3>
		<div>
			<span class="pointer text-warning" onclick="location.reload();" title="Reload data" style="padding:2px 6px;"><i class="fa fa-refresh"></i> <?= $isBn ? 'রিলোড' : 'Reload'; ?></span>
		</div>
	</div>
</div>
<div class="card-body" style="padding: 8px 10px;">
  <?php if ($ipbAlertMsg !== '') { ?>
  <div style="padding: 6px 10px; margin-bottom: 8px; border-radius: 5px; font-size: 12px; font-weight: 600; background: <?= $ipbAlertType === 'success' ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)'; ?>; border: 1px solid <?= $ipbAlertType === 'success' ? '#10b981' : '#ef4444'; ?>; color: <?= $ipbAlertType === 'success' ? '#34d399' : '#f87171'; ?>;">
    <i class="fa <?= $ipbAlertType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?= $ipbAlertMsg; ?>
  </div>
  <?php } ?>

  <div class="ngw-compact-ipb-toolbar">
    <input id="filterTable" type="text" class="form-control ngw-compact-ipb-search" placeholder="<?= $isBn ? 'খুঁজুন (MAC / IP / কমেন্ট)...' : 'Search (MAC / IP / Comment)...'; ?>">
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
  	<?php if ($canManageBinding || $canEditBinding) { ?>
		<button type="button" class="ngw-ipb-dots-btn" onclick="ngwToggleIpbDropdown(event, this)" title="<?= $isBn ? 'অ্যাকশন অপশন' : 'Action Options'; ?>"><i class="fa fa-ellipsis-v"></i></button>
		<div class="ngw-ipb-dropdown-menu">
			<?php if ($canEditBinding) { ?>
			<button type="button" class="ngw-ipb-dropdown-item" onclick="ngwCloseAllIpbDropdowns(); ngwOpenIpbModal(<?= $jsBindData; ?>)">
				<i class="fa fa-edit text-info"></i> <?= $isBn ? 'এডিট' : 'Edit'; ?>
			</button>
			<?php } ?>
			<?php if ($canManageBinding) {
				if ($bdisabled == "true") {
					$uriprocess = "'./?enable-ip-binding=" . $id . "&session=" . $session . "'";
					echo '<button type="button" class="ngw-ipb-dropdown-item text-warning" onclick="ngwCloseAllIpbDropdowns(); loadpage(' . $uriprocess . ')"><i class="fa fa-lock"></i> ' . ($isBn ? 'ইনেবল করুন' : 'Enable') . '</button>';
				} else {
					$uriprocess = "'./?disable-ip-binding=" . $id . "&session=" . $session . "'";
					echo '<button type="button" class="ngw-ipb-dropdown-item" onclick="ngwCloseAllIpbDropdowns(); loadpage(' . $uriprocess . ')"><i class="fa fa-unlock"></i> ' . ($isBn ? 'ডিজেবল করুন' : 'Disable') . '</button>';
				}
				$delConfirmMsg = $isBn
					? ('আপনি কি নিশ্চিত যে এই বাইন্ডিং (' . ($maca !== '' ? $maca : $addr) . ') ডিলিট করতে চান?')
					: ('Are you sure to delete (' . ($maca !== '' ? $maca : $addr) . ')?');
				$delUrl = './?remove-ip-binding=' . $id . '&mac=' . rawurlencode($maca) . '&addr=' . rawurlencode($addr) . '&session=' . rawurlencode($session);
			?>
			<button type="button" class="ngw-ipb-dropdown-item is-danger" onclick="ngwCloseAllIpbDropdowns(); if(confirm(<?= json_encode($delConfirmMsg); ?>)){loadpage(<?= json_encode($delUrl); ?>)}">
				<i class="fa fa-trash"></i> <?= $isBn ? 'রিমুভ' : 'Remove'; ?>
			</button>
			<?php } ?>
		</div>
  	<?php } else {
		echo "<span style='opacity:0.4;'>-</span>";
	} ?>
  	</td>
	<?php
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
      <div class="ngw-ipb-modal-title-wrap">
        <div class="ngw-ipb-modal-icon-badge"><i class="fa fa-sliders"></i></div>
        <div>
          <h4 id="ngwIpbModalTitle"><i class="fa fa-edit"></i> <?= $isBn ? 'IP বাইন্ডিং এডিট করুন' : 'Edit IP Binding'; ?></h4>
          <span class="ngw-ipb-modal-sub"><?= $isBn ? 'ডিভাইসের MAC/IP কনফিগারেশন ও এক্সেস পলিসি সংরক্ষণ করুন' : 'Configure device MAC/IP & access policy'; ?></span>
        </div>
      </div>
      <button type="button" class="ngw-ipb-modal-close-btn" onclick="ngwCloseIpbModal()" title="<?= $_close ?>">&times;</button>
    </div>

    <div class="ngw-ipb-modal-body">
      <form method="post" action="">
        <input type="hidden" name="ngw_save_ipbinding" value="1">
        <input type="hidden" name="binding_id" id="ngwIpbId" value="">
        <input type="hidden" name="binding_type" id="ngwIpbTypeInput" value="bypassed">

        <!-- Box 1: Device & Network (নীল প্যানেল বক্স) -->
        <div class="ngw-ipb-panel-box ngw-ipb-panel-blue">
          <div class="ngw-ipb-panel-header">
            <span><i class="fa fa-hdd-o text-info"></i> <?= $isBn ? 'ডিভাইস ও আইপি তথ্য' : 'Device & IP Information'; ?></span>
            <span class="ngw-ipb-panel-badge"><?= $isBn ? 'নেটওয়ার্ক' : 'Network'; ?></span>
          </div>

          <div class="ngw-ipb-field-group">
            <label class="ngw-ipb-field-label">
              <span><?= $isBn ? 'ম্যাক অ্যাড্রেস (MAC Address)' : 'MAC Address'; ?></span>
            </label>
            <div class="ngw-ipb-input-with-icon">
              <span class="ngw-ipb-input-addon"><i class="fa fa-laptop"></i></span>
              <input type="text" name="mac_address" id="ngwIpbMac" placeholder="00:11:22:33:44:55" style="font-family:monospace; font-weight:600; letter-spacing:0.5px;">
            </div>
          </div>

          <div class="ngw-ipb-field-group" style="margin-top:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
              <label class="ngw-ipb-field-label" style="margin-bottom:0;">
                <span><?= $isBn ? 'অ্যাড্রেস / আইপি (Address)' : 'Address (IP)'; ?> <small style="opacity:0.65; font-weight:normal;">(<?= $isBn ? 'ফাঁকা = শুধু MAC' : 'Blank = MAC Only'; ?>)</small></span>
              </label>
              <button type="button" class="ngw-ipb-clear-btn" onclick="document.getElementById('ngwIpbAddr').value=''; document.getElementById('ngwIpbToAddr').value='';" title="<?= $isBn ? 'আইপি মুছে শুধু MAC দিয়ে বাইন্ডিং করুন' : 'Clear IP to bind by MAC only'; ?>">
                <i class="fa fa-eraser"></i> <?= $isBn ? 'শুধু MAC (IP মুছুন)' : 'MAC Only (Clear IP)'; ?>
              </button>
            </div>
            <div class="ngw-ipb-input-with-icon">
              <span class="ngw-ipb-input-addon"><i class="fa fa-globe"></i></span>
              <input type="text" name="address" id="ngwIpbAddr" placeholder="<?= $isBn ? 'যেমন: 10.5.50.10 অথবা ফাঁকা রাখুন' : 'e.g. 10.5.50.10 or leave blank'; ?>">
            </div>
          </div>

          <div class="ngw-ipb-field-group" style="margin-top:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
              <label class="ngw-ipb-field-label" style="margin-bottom:0;">
                <span><?= $isBn ? 'টু অ্যাড্রেস (To Address)' : 'To Address'; ?> <small style="opacity:0.65; font-weight:normal;">(<?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?>)</small></span>
              </label>
              <button type="button" class="ngw-ipb-clear-btn" style="padding:1px 5px; font-size:10px;" onclick="document.getElementById('ngwIpbToAddr').value='';">
                <?= $isBn ? 'মুছুন' : 'Clear'; ?>
              </button>
            </div>
            <div class="ngw-ipb-input-with-icon">
              <span class="ngw-ipb-input-addon"><i class="fa fa-arrow-right"></i></span>
              <input type="text" name="to_address" id="ngwIpbToAddr" placeholder="<?= $isBn ? 'ঐচ্ছিক (ফাঁকা রাখতে পারেন)' : 'Optional'; ?>">
            </div>
          </div>
        </div>

        <!-- Box 2: Server & Policy (অ্যাম্বার/হলুদ প্যানেল বক্স) -->
        <div class="ngw-ipb-panel-box ngw-ipb-panel-amber">
          <div class="ngw-ipb-panel-header">
            <span><i class="fa fa-sliders text-warning"></i> <?= $isBn ? 'সার্ভার ও পলিসি নির্ধারণ' : 'Server & Policy Options'; ?></span>
            <span class="ngw-ipb-panel-badge"><?= $isBn ? 'পলিসি' : 'Policy'; ?></span>
          </div>

          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
            <div class="ngw-ipb-field-group">
              <label class="ngw-ipb-field-label"><span><?= $isBn ? 'হটস্পট সার্ভার' : 'Hotspot Server'; ?></span></label>
              <div class="ngw-ipb-input-with-icon">
                <span class="ngw-ipb-input-addon"><i class="fa fa-server"></i></span>
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
            </div>

            <div class="ngw-ipb-field-group">
              <label class="ngw-ipb-field-label"><span><?= $isBn ? 'সক্রিয় (Enabled)' : 'Enabled'; ?></span></label>
              <div class="ngw-ipb-input-with-icon">
                <span class="ngw-ipb-input-addon"><i class="fa fa-power-off"></i></span>
                <select name="disabled" id="ngwIpbDisabled">
                  <option value="no"><?= $_yes ?></option>
                  <option value="yes"><?= $_no ?></option>
                </select>
              </div>
            </div>
          </div>

          <div class="ngw-ipb-field-group" style="margin-top:10px;">
            <label class="ngw-ipb-field-label">
              <span><?= $isBn ? 'বাইন্ডিং স্ট্যাটাস / টাইপ (Type)' : 'Binding Status / Type'; ?></span>
            </label>
            <div class="ngw-ipb-type-pill-cards">
              <div id="ngwIpbPillRegular" class="ngw-ipb-type-card ngw-ipb-card-regular" onclick="ngwSelectIpbType('regular')">
                <div class="ngw-ipb-card-indicator"><i class="fa fa-circle-o"></i></div>
                <div class="ngw-ipb-card-text">
                  <span class="ngw-ipb-card-name">Regular</span>
                  <small><?= $isBn ? 'লগইন প্রয়োজন' : 'Needs Login'; ?></small>
                </div>
              </div>

              <div id="ngwIpbPillBypassed" class="ngw-ipb-type-card ngw-ipb-card-bypassed active-bypassed" onclick="ngwSelectIpbType('bypassed')">
                <div class="ngw-ipb-card-indicator"><i class="fa fa-check-circle"></i></div>
                <div class="ngw-ipb-card-text">
                  <span class="ngw-ipb-card-name">Bypassed</span>
                  <small><?= $isBn ? 'সরাসরি চালু' : 'No Login'; ?></small>
                </div>
              </div>

              <div id="ngwIpbPillBlocked" class="ngw-ipb-type-card ngw-ipb-card-blocked" onclick="ngwSelectIpbType('blocked')">
                <div class="ngw-ipb-card-indicator"><i class="fa fa-ban"></i></div>
                <div class="ngw-ipb-card-text">
                  <span class="ngw-ipb-card-name">Blocked</span>
                  <small><?= $isBn ? 'ব্লক করা' : 'Blocked'; ?></small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Box 3: Note / Description (পার্পল প্যানেল বক্স) -->
        <div class="ngw-ipb-panel-box ngw-ipb-panel-purple">
          <div class="ngw-ipb-panel-header">
            <span><i class="fa fa-commenting-o text-purple"></i> <?= $isBn ? 'ডিভাইসের নোট বা কমেন্ট' : 'Device Note / Comment'; ?></span>
            <small style="opacity:0.65;"><?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?></small>
          </div>

          <div class="ngw-ipb-input-with-icon">
            <span class="ngw-ipb-input-addon"><i class="fa fa-tag"></i></span>
            <input type="text" name="comment" id="ngwIpbComment" placeholder="<?= $isBn ? 'ডিভাইস বা ইউজারের নাম / নোট লিখুন...' : 'Device or user note...'; ?>">
          </div>
        </div>

        <!-- Modal Footer Actions -->
        <div class="ngw-ipb-modal-footer">
          <button type="button" class="btn bg-warning" style="padding:5px 14px; font-size:12px;" onclick="ngwCloseIpbModal()">
            <i class="fa fa-close"></i> <?= $_close ?>
          </button>
          <button type="submit" class="btn bg-primary" style="font-weight:700; padding:5px 18px; font-size:12px;">
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
  if (pReg) pReg.className = 'ngw-ipb-type-card ngw-ipb-card-regular' + (typeVal === 'regular' ? ' active-regular' : '');
  if (pByp) pByp.className = 'ngw-ipb-type-card ngw-ipb-card-bypassed' + (typeVal === 'bypassed' ? ' active-bypassed' : '');
  if (pBlk) pBlk.className = 'ngw-ipb-type-card ngw-ipb-card-blocked' + (typeVal === 'blocked' ? ' active-blocked' : '');
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
function ngwCloseAllIpbDropdowns() {
  var menus = document.querySelectorAll('.ngw-ipb-dropdown-menu');
  for (var i = 0; i < menus.length; i++) {
    menus[i].style.display = 'none';
  }
}
function ngwToggleIpbDropdown(e, btn) {
  e.stopPropagation();
  var menu = btn.nextElementSibling;
  if (!menu || !menu.classList.contains('ngw-ipb-dropdown-menu')) return;
  var isOpen = menu.style.display === 'block';
  ngwCloseAllIpbDropdowns();
  if (!isOpen) {
    var rect = btn.getBoundingClientRect();
    menu.style.display = 'block';
    var menuWidth = menu.offsetWidth || 145;
    var menuHeight = menu.offsetHeight || 90;
    var left = rect.left;
    if (left + menuWidth > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - menuWidth - 8);
    }
    var top = rect.bottom + 4;
    if (top + menuHeight > window.innerHeight - 8) {
      top = Math.max(8, rect.top - menuHeight - 4);
    }
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  }
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.ngw-ipb-dropdown-menu') && !e.target.closest('.ngw-ipb-dots-btn')) {
    ngwCloseAllIpbDropdowns();
  }
});
window.addEventListener('resize', ngwCloseAllIpbDropdowns);
window.addEventListener('scroll', ngwCloseAllIpbDropdowns, true);
</script>
<?php } ?>
