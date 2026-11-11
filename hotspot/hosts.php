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
	position: relative;
}
.ngw-host-status-badge {
	display: inline-block;
	padding: 1px 5px;
	font-size: 10.5px;
	font-weight: 700;
	border-radius: 4px;
	line-height: 1.2;
}
.ngw-dots-btn {
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
.ngw-dots-btn:hover {
	background: #0ea5e9;
	color: #fff;
	border-color: #0ea5e9;
}
.ngw-action-dropdown-menu {
	display: none;
	position: fixed;
	z-index: 10050;
	min-width: 140px;
	background: #1e293b;
	color: #f8fafc;
	border: 1px solid rgba(148, 163, 184, 0.35);
	border-radius: 6px;
	box-shadow: 0 10px 25px rgba(0,0,0,0.45);
	padding: 4px 0;
	text-align: left;
}
.ngw-action-dropdown-item {
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
.ngw-action-dropdown-item:hover {
	background: rgba(14, 165, 233, 0.22);
	color: #38bdf8 !important;
}
.ngw-action-dropdown-item.is-danger {
	color: #f87171 !important;
}
.ngw-action-dropdown-item.is-danger:hover {
	background: rgba(239, 68, 68, 0.18);
	color: #ef4444 !important;
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
.ngw-card-header-flex {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 6px 12px;
	width: 100%;
}
.ngw-card-header-flex h3 {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}
.ngw-header-filter-links {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	font-weight: 600;
}
.ngw-header-filter-links a {
	padding: 2px 6px;
	border-radius: 4px;
	text-decoration: none;
}
.ngw-bind-modal-backdrop {
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
.ngw-bind-modal-card {
	background: #1e293b;
	color: #f8fafc;
	border: 1px solid rgba(148, 163, 184, 0.28);
	border-top: 3px solid #0ea5e9;
	border-radius: 12px;
	width: 100%;
	max-width: 475px;
	box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.65);
	overflow: hidden;
	animation: ngwModalFadeIn 0.18s ease-out;
}
@keyframes ngwModalFadeIn {
	from { opacity: 0; transform: scale(0.97) translateY(-6px); }
	to { opacity: 1; transform: scale(1) translateY(0); }
}
.ngw-bind-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: linear-gradient(180deg, rgba(30, 41, 59, 0.95) 0%, rgba(15, 23, 42, 0.85) 100%);
	border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}
.ngw-modal-title-wrap {
	display: flex;
	align-items: center;
	gap: 10px;
}
.ngw-modal-icon-badge {
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
.ngw-bind-modal-header h4 {
	margin: 0;
	font-size: 13.5px;
	font-weight: 700;
	color: #f1f5f9;
	line-height: 1.25;
}
.ngw-modal-sub {
	font-size: 11px;
	color: #94a3b8;
	display: block;
	margin-top: 1px;
}
.ngw-modal-close-btn {
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
.ngw-modal-close-btn:hover {
	background: rgba(239, 68, 68, 0.2);
	color: #f87171;
}
.ngw-bind-modal-body {
	padding: 14px 16px;
	max-height: calc(88vh - 70px);
	overflow-y: auto;
}
.ngw-panel-box {
	border-radius: 8px;
	padding: 10px 12px;
	margin-bottom: 10px;
	background: rgba(15, 23, 42, 0.45);
	border: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-panel-blue {
	border-left: 3px solid #0284c7;
	background: linear-gradient(90deg, rgba(2, 132, 199, 0.08) 0%, rgba(15, 23, 42, 0.5) 100%);
}
.ngw-panel-amber {
	border-left: 3px solid #f59e0b;
	background: linear-gradient(90deg, rgba(245, 158, 11, 0.08) 0%, rgba(15, 23, 42, 0.5) 100%);
}
.ngw-panel-purple {
	border-left: 3px solid #a855f7;
	background: linear-gradient(90deg, rgba(168, 85, 247, 0.08) 0%, rgba(15, 23, 42, 0.5) 100%);
}
.ngw-panel-header {
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
.ngw-panel-badge {
	font-size: 9.5px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 3px;
	background: rgba(148, 163, 184, 0.15);
	color: #94a3b8;
	text-transform: uppercase;
}
.ngw-field-group {
	margin-bottom: 6px;
}
.ngw-field-label {
	font-size: 11px;
	font-weight: 600;
	color: #94a3b8;
	margin-bottom: 3px;
	display: block;
}
.ngw-input-with-icon {
	display: flex;
	align-items: stretch;
	border-radius: 6px;
	border: 1px solid rgba(148, 163, 184, 0.3);
	background: #0f172a;
	overflow: hidden;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.ngw-input-with-icon:focus-within {
	border-color: #38bdf8;
	box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.22);
}
.ngw-input-addon {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0 10px;
	background: rgba(30, 41, 59, 0.6);
	color: #64748b;
	font-size: 12px;
	border-right: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-input-with-icon input,
.ngw-input-with-icon select {
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
.ngw-input-with-icon select option {
	background: #1e293b;
	color: #f8fafc;
}
.ngw-btn-clear-ip {
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
.ngw-btn-clear-ip:hover {
	background: #ef4444;
	color: #fff;
}
.ngw-type-pill-cards {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 6px;
	margin-top: 4px;
}
.ngw-type-card {
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
.ngw-type-card:hover {
	transform: translateY(-1px);
}
.ngw-card-indicator {
	font-size: 13px;
	display: flex;
	align-items: center;
	justify-content: center;
}
.ngw-card-text {
	display: flex;
	flex-direction: column;
	line-height: 1.1;
}
.ngw-card-name {
	font-size: 11px;
	font-weight: 700;
}
.ngw-card-text small {
	font-size: 9px;
	opacity: 0.7;
}
.ngw-card-regular { color: #94a3b8; }
.ngw-card-regular.active-regular {
	border-color: #3b82f6;
	background: rgba(59, 130, 246, 0.2);
	color: #60a5fa;
	box-shadow: 0 0 8px rgba(59, 130, 246, 0.35);
}
.ngw-card-bypassed { color: #94a3b8; }
.ngw-card-bypassed.active-bypassed {
	border-color: #10b981;
	background: rgba(16, 185, 129, 0.22);
	color: #34d399;
	box-shadow: 0 0 8px rgba(16, 185, 129, 0.35);
}
.ngw-card-blocked { color: #94a3b8; }
.ngw-card-blocked.active-blocked {
	border-color: #ef4444;
	background: rgba(239, 68, 68, 0.22);
	color: #f87171;
	box-shadow: 0 0 8px rgba(239, 68, 68, 0.35);
}
.ngw-modal-footer {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 8px;
	margin-top: 12px;
	padding-top: 10px;
	border-top: 1px solid rgba(148, 163, 184, 0.18);
}
@media (max-width: 768px) {
	.card-header.align-middle {
		padding: 8px 10px !important;
	}
	.ngw-card-header-flex {
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
	}
	.ngw-card-header-flex h3 {
		font-size: 13px !important;
		line-height: 1.3;
	}
	.ngw-compact-hosts-search {
		width: 100%;
	}
	.ngw-compact-hosts-table {
		font-size: 11px !important;
	}
	.ngw-compact-hosts-table thead th,
	.ngw-compact-hosts-table tbody td {
		padding: 4px 6px !important;
	}
	.ngw-host-comment-col {
		max-width: 110px;
		width: 110px;
	}
	.ngw-host-comment-box {
		max-width: 100px;
		font-size: 10.5px;
	}
}
</style>

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header align-middle">
	<div class="ngw-card-header-flex">
		<h3>
			<span><i class="fa fa-laptop"></i> <?= $_hosts ?></span>
			<span style="font-size:12px; opacity:0.85; font-weight:normal;">
				(<?= $counthosts . ($isBn ? " টি আইটেম" : " items"); ?>)
			</span>
		</h3>
		<div class="ngw-header-filter-links">
			<a class="text-info" title="All" href="./?hotspot=hosts&session=<?= $session; ?>"><i class="fa fa-list"></i> <?= $isBn ? 'সব' : 'All'; ?></a>
			<span style="opacity:0.4;">|</span>
			<a class="text-success" title="Authorized" href="./?hotspot=hosta&session=<?= $session; ?>"><b>A</b> <?= $isBn ? 'অথরাইজড' : 'Auth'; ?></a>
			<span style="opacity:0.4;">|</span>
			<a class="text-primary" title="Bypassed" href="./?hotspot=hostp&session=<?= $session; ?>"><b>P</b> <?= $isBn ? 'বাইপাসড' : 'Bypassed'; ?></a>
			<span style="opacity:0.4;">|</span>
			<span class="pointer text-warning" onclick="location.reload();" title="Reload data" style="padding:2px 6px;"><i class="fa fa-refresh"></i></span>
		</div>
	</div>
</div>
<!-- /.card-header -->
<div class="card-body" style="padding: 8px 10px;">
  <?php if ($bindAlertMsg !== '') { ?>
  <div style="padding: 6px 10px; margin-bottom: 8px; border-radius: 5px; font-size: 12px; font-weight: 600; background: <?= $bindAlertType === 'success' ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)'; ?>; border: 1px solid <?= $bindAlertType === 'success' ? '#10b981' : '#ef4444'; ?>; color: <?= $bindAlertType === 'success' ? '#34d399' : '#f87171'; ?>;">
    <i class="fa <?= $bindAlertType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?= $bindAlertMsg; ?>
  </div>
  <?php } ?>

  <div class="ngw-compact-hosts-toolbar">
    <input id="filterTable" type="text" class="form-control ngw-compact-hosts-search" placeholder="<?= $isBn ? 'হোস্ট খুঁজুন (MAC / IP / কমেন্ট)...' : 'Search host (MAC / IP / Comment)...'; ?>">
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
	if ($canBindHost || $canRemoveHost) {
		echo "<button type='button' class='ngw-dots-btn' onclick='ngwToggleHostDropdown(event, this)' title='" . ($isBn ? 'অ্যাকশন অপশন' : 'Action Options') . "'><i class='fa fa-ellipsis-v'></i></button>";
		echo "<div class='ngw-action-dropdown-menu'>";
		if ($canBindHost) {
			echo "<button type='button' class='ngw-action-dropdown-item' onclick='ngwCloseAllDropdowns(); ngwOpenHostBindModal(" . $jsHostData . ")'><i class='fa fa-link text-info'></i> " . ($isBn ? 'IP বাইন্ডিং' : 'IP Binding') . "</button>";
		}
		if ($canRemoveHost) {
			$delHostConfirm = $isBn ? ('আপনি কি এই হোস্টটি মুছে ফেলতে নিশ্চিত (' . ($maca !== '' ? $maca : $addr) . ')?') : ('Are you sure you want to remove host (' . ($maca !== '' ? $maca : $addr) . ')?');
			echo "<button type='button' class='ngw-action-dropdown-item is-danger' onclick='ngwCloseAllDropdowns(); if(confirm(" . json_encode($delHostConfirm) . ")){loadpage(" . $uriprocess . ")}'><i class='fa fa-trash'></i> " . ($isBn ? 'রিমুভ' : 'Remove') . "</button>";
		}
		echo "</div>";
	} else {
		echo "<span style='opacity:0.4;'>-</span>";
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
      <div class="ngw-modal-title-wrap">
        <div class="ngw-modal-icon-badge"><i class="fa fa-link"></i></div>
        <div>
          <h4><?= $isBn ? 'হোস্ট থেকে সরাসরি IP বাইন্ডিং' : 'Direct Host to IP Binding'; ?></h4>
          <span class="ngw-modal-sub"><?= $isBn ? 'ডিভাইসের MAC/IP কনফিগারেশন ও এক্সেস পলিসি সংরক্ষণ করুন' : 'Configure device MAC/IP & access policy'; ?></span>
        </div>
      </div>
      <button type="button" class="ngw-modal-close-btn" onclick="ngwCloseHostBindModal()" title="<?= $_close ?>">&times;</button>
    </div>

    <div class="ngw-bind-modal-body">
      <form method="post" action="">
        <input type="hidden" name="ngw_host_to_binding" value="1">
        <input type="hidden" name="host_id" id="ngwBindHostId" value="">
        <input type="hidden" name="binding_type" id="ngwBindTypeInput" value="bypassed">

        <!-- Box 1: Device & Network (নীল প্যানেল বক্স) -->
        <div class="ngw-panel-box ngw-panel-blue">
          <div class="ngw-panel-header">
            <span><i class="fa fa-hdd-o text-info"></i> <?= $isBn ? 'ডিভাইস ও আইপি তথ্য' : 'Device & IP Information'; ?></span>
            <span class="ngw-panel-badge"><?= $isBn ? 'নেটওয়ার্ক' : 'Network'; ?></span>
          </div>

          <div class="ngw-field-group">
            <label class="ngw-field-label">
              <span><?= $isBn ? 'ম্যাক অ্যাড্রেস (MAC Address)' : 'MAC Address'; ?> <span class="text-danger">*</span></span>
            </label>
            <div class="ngw-input-with-icon">
              <span class="ngw-input-addon"><i class="fa fa-laptop"></i></span>
              <input type="text" name="mac_address" id="ngwBindMac" required placeholder="00:11:22:33:44:55" style="font-family:monospace; font-weight:600; letter-spacing:0.5px;">
            </div>
          </div>

          <div class="ngw-field-group" style="margin-top:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
              <label class="ngw-field-label" style="margin-bottom:0;">
                <span><?= $isBn ? 'আইপি অ্যাড্রেস (IP Address)' : 'IP Address'; ?></span>
              </label>
              <button type="button" class="ngw-btn-clear-ip" onclick="document.getElementById('ngwBindAddr').value=''; document.getElementById('ngwBindToAddr').value='';" title="<?= $isBn ? 'আইপি মুছে শুধু MAC দিয়ে বাইন্ডিং করুন' : 'Clear IP to bind by MAC only'; ?>">
                <i class="fa fa-eraser"></i> <?= $isBn ? 'শুধু MAC (IP মুছুন)' : 'MAC Only (Clear IP)'; ?>
              </button>
            </div>
            <div class="ngw-input-with-icon">
              <span class="ngw-input-addon"><i class="fa fa-globe"></i></span>
              <input type="text" name="address" id="ngwBindAddr" placeholder="<?= $isBn ? 'ফাঁকা রাখলে শুধু MAC দিয়ে বাইন্ডিং হবে' : 'Leave blank for MAC-only'; ?>">
            </div>
          </div>

          <div class="ngw-field-group" style="margin-top:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
              <label class="ngw-field-label" style="margin-bottom:0;">
                <span><?= $isBn ? 'টু অ্যাড্রেস (To Address)' : 'To Address'; ?> <small style="opacity:0.65; font-weight:normal;">(<?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?>)</small></span>
              </label>
              <button type="button" class="ngw-btn-clear-ip" style="padding:1px 5px; font-size:10px;" onclick="document.getElementById('ngwBindToAddr').value='';">
                <?= $isBn ? 'মুছুন' : 'Clear'; ?>
              </button>
            </div>
            <div class="ngw-input-with-icon">
              <span class="ngw-input-addon"><i class="fa fa-arrow-right"></i></span>
              <input type="text" name="to_address" id="ngwBindToAddr" placeholder="<?= $isBn ? 'ঐচ্ছিক (ফাঁকা রাখতে পারেন)' : 'Optional'; ?>">
            </div>
          </div>
        </div>

        <!-- Box 2: Server & Policy (অ্যাম্বার/হলুদ প্যানেল বক্স) -->
        <div class="ngw-panel-box ngw-panel-amber">
          <div class="ngw-panel-header">
            <span><i class="fa fa-sliders text-warning"></i> <?= $isBn ? 'সার্ভার ও পলিসি নির্ধারণ' : 'Server & Policy Options'; ?></span>
            <span class="ngw-panel-badge"><?= $isBn ? 'পলিসি' : 'Policy'; ?></span>
          </div>

          <div class="ngw-field-group">
            <label class="ngw-field-label">
              <span><?= $isBn ? 'হটস্পট সার্ভার (Server)' : 'Hotspot Server'; ?></span>
            </label>
            <div class="ngw-input-with-icon">
              <span class="ngw-input-addon"><i class="fa fa-server"></i></span>
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
          </div>

          <div class="ngw-field-group" style="margin-top:10px;">
            <label class="ngw-field-label">
              <span><?= $isBn ? 'বাইন্ডিং স্ট্যাটাস / টাইপ (Type)' : 'Binding Status / Type'; ?></span>
            </label>
            <div class="ngw-type-pill-cards">
              <div id="ngwPillRegular" class="ngw-type-card ngw-card-regular" onclick="ngwSelectBindType('regular')">
                <div class="ngw-card-indicator"><i class="fa fa-circle-o"></i></div>
                <div class="ngw-card-text">
                  <span class="ngw-card-name">Regular</span>
                  <small><?= $isBn ? 'লগইন প্রয়োজন' : 'Needs Login'; ?></small>
                </div>
              </div>

              <div id="ngwPillBypassed" class="ngw-type-card ngw-card-bypassed active-bypassed" onclick="ngwSelectBindType('bypassed')">
                <div class="ngw-card-indicator"><i class="fa fa-check-circle"></i></div>
                <div class="ngw-card-text">
                  <span class="ngw-card-name">Bypassed</span>
                  <small><?= $isBn ? 'সরাসরি চালু' : 'No Login'; ?></small>
                </div>
              </div>

              <div id="ngwPillBlocked" class="ngw-type-card ngw-card-blocked" onclick="ngwSelectBindType('blocked')">
                <div class="ngw-card-indicator"><i class="fa fa-ban"></i></div>
                <div class="ngw-card-text">
                  <span class="ngw-card-name">Blocked</span>
                  <small><?= $isBn ? 'ব্লক করা' : 'Blocked'; ?></small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Box 3: Note / Description (পার্পল প্যানেল বক্স) -->
        <div class="ngw-panel-box ngw-panel-purple">
          <div class="ngw-panel-header">
            <span><i class="fa fa-commenting-o text-purple"></i> <?= $isBn ? 'ডিভাইসের নোট বা কমেন্ট' : 'Device Note / Comment'; ?></span>
            <small style="opacity:0.65;"><?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?></small>
          </div>

          <div class="ngw-input-with-icon">
            <span class="ngw-input-addon"><i class="fa fa-tag"></i></span>
            <input type="text" name="comment" id="ngwBindComment" placeholder="<?= $isBn ? 'ডিভাইস বা ইউজারের নাম / নোট লিখুন...' : 'Device or user note...'; ?>">
          </div>
        </div>

        <!-- Modal Footer Actions -->
        <div class="ngw-modal-footer">
          <button type="button" class="btn bg-warning" style="padding:5px 14px; font-size:12px;" onclick="ngwCloseHostBindModal()">
            <i class="fa fa-close"></i> <?= $_close ?>
          </button>
          <button type="submit" class="btn bg-primary" style="font-weight:700; padding:5px 18px; font-size:12px;">
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
  if (pReg) pReg.className = 'ngw-type-card ngw-card-regular' + (typeVal === 'regular' ? ' active-regular' : '');
  if (pByp) pByp.className = 'ngw-type-card ngw-card-bypassed' + (typeVal === 'bypassed' ? ' active-bypassed' : '');
  if (pBlk) pBlk.className = 'ngw-type-card ngw-card-blocked' + (typeVal === 'blocked' ? ' active-blocked' : '');
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
function ngwCloseAllDropdowns() {
  var menus = document.querySelectorAll('.ngw-action-dropdown-menu');
  for (var i = 0; i < menus.length; i++) {
    menus[i].style.display = 'none';
  }
}
function ngwToggleHostDropdown(e, btn) {
  e.stopPropagation();
  var menu = btn.nextElementSibling;
  if (!menu || !menu.classList.contains('ngw-action-dropdown-menu')) return;
  var isOpen = menu.style.display === 'block';
  ngwCloseAllDropdowns();
  if (!isOpen) {
    var rect = btn.getBoundingClientRect();
    menu.style.display = 'block';
    var menuWidth = menu.offsetWidth || 140;
    var menuHeight = menu.offsetHeight || 80;
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
  if (!e.target.closest('.ngw-action-dropdown-menu') && !e.target.closest('.ngw-dots-btn')) {
    ngwCloseAllDropdowns();
  }
});
window.addEventListener('resize', ngwCloseAllDropdowns);
window.addEventListener('scroll', ngwCloseAllDropdowns, true);
</script>
<?php } ?>