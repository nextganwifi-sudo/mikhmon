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
?>
<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
} else {
// load session MikroTik
	$session = $_GET['session'];

// load config
include('../include/config.php');
include('../include/readcfg.php');
include_once('./include/reseller_acl.php');

$url = $_SERVER['REQUEST_URI'];
$telplate = isset($_GET['template']) && $_GET['template'] !== '' ? $_GET['template'] : 'default';

$isAdminUser = isMikhmonAdmin();
$currentReseller = getCurrentResellerRecord();
$targetReseller = null;

if (!$isAdminUser) {
	if (!hasResellerPermission('edit_own_template') && !hasResellerPermission('preview_assigned_template')) {
		requireResellerPermission('edit_own_template', './?session=' . urlencode($session));
	}
	$targetReseller = $currentReseller;
} elseif (!empty($_GET['reseller_id']) && $_GET['reseller_id'] !== 'global') {
	$targetReseller = findResellerByIdOrUsername($_GET['reseller_id']);
}

$targetResId = ($targetReseller !== null && isset($targetReseller['id']))
	? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$targetReseller['id'])
	: '';

if ($targetResId !== '') {
	ensureResellerTemplateFiles($targetResId);
}

$resQuerySuffix = ($isAdminUser && $targetResId !== '') ? ('&reseller_id=' . urlencode($targetResId)) : '';
$editorRouteBase = (isset($_GET['hotspot']) && $_GET['hotspot'] === 'template-editor')
	? './?hotspot=template-editor'
	: './admin.php?id=editor';

if ($telplate == "default" || $telplate == "rdefault") {
	$telplatet = "template";
	$defaultSource = "default";
	$popup = "javascript:window.open('./voucher/vpreview.php?usermode=up&qr=no&variant=default&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
	$popupQR = "javascript:window.open('./voucher/vpreview.php?usermode=up&qr=yes&variant=default&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
} elseif ($telplate == "thermal" || $telplate == "rthermal") {
	$telplatet = "template-thermal";
	$defaultSource = "default-thermal";
	$popup = "javascript:window.open('./voucher/vpreview.php?usermode=up&user=m&qr=no&variant=thermal&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
	$popupQR = "javascript:window.open('./voucher/vpreview.php?usermode=up&user=m&qr=yes&variant=thermal&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
} elseif ($telplate == "small" || $telplate == "rsmall") {
	$telplatet = "template-small";
	$defaultSource = "default-small";
	$popup = "javascript:window.open('./voucher/vpreview.php?usermode=up&small=yes&qr=no&variant=small&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
	$popupQR = "javascript:window.open('./voucher/vpreview.php?usermode=up&small=yes&qr=yes&variant=small&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
} else {
	$telplate = "default";
	$telplatet = "template";
	$defaultSource = "default";
	$popup = "javascript:window.open('./voucher/vpreview.php?usermode=up&qr=no&variant=default&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
	$popupQR = "javascript:window.open('./voucher/vpreview.php?usermode=up&qr=yes&variant=default&session=" . $session . $resQuerySuffix . "','_blank','width=310,height=310')";
}

$editorMsg = '';
$editorErr = '';

if (isset($_POST['save']) || isset($_POST['publish'])) {
	$data = isset($_POST['editor']) ? $_POST['editor'] : '';
	if ($targetResId !== '') {
		// Saving or publishing isolated reseller template
		$canSave = $isAdminUser || hasResellerPermission('save_own_template') || hasResellerPermission('edit_own_template');
		$canPublish = $isAdminUser || hasResellerPermission('publish_own_template') || hasResellerPermission('save_own_template');
		if (isset($_POST['publish']) && !$canPublish) {
			$editorErr = 'Access Denied: You do not have publish_own_template permission.';
		} elseif (!$canSave) {
			$editorErr = 'Access Denied: You do not have save_own_template permission.';
		} else {
			$resTplDir = './uploads/reseller-templates/' . $targetResId;
			if (!is_dir($resTplDir)) {
				@mkdir($resTplDir, 0755, true);
			}
			$activePath = $resTplDir . '/' . $telplatet . '.php';
			$bakPath = $resTplDir . '/' . $telplatet . '.bak.php';
			$pubPath = $resTplDir . '/' . $telplatet . '.published.php';
			if (is_file($activePath)) {
				@copy($activePath, $bakPath);
			}
			if (@file_put_contents($activePath, $data) !== false) {
				if (isset($_POST['publish'])) {
					@file_put_contents($pubPath, $data);
					$editorMsg = 'Template saved and published for reseller ' . htmlspecialchars($targetReseller['name']) . ' (' . htmlspecialchars($targetReseller['template_code']) . ').';
				} else {
					$editorMsg = 'Template saved for reseller ' . htmlspecialchars($targetReseller['name']) . ' (' . htmlspecialchars($targetReseller['template_code']) . ').';
				}
			} else {
				$editorErr = 'Cannot write template file: ' . htmlspecialchars($activePath);
			}
		}
	} else {
		// Saving global template (Admin only)
		if (!$isAdminUser || !hasResellerPermission('manage_global_templates')) {
			$editorErr = 'Access Denied: Only Administrator can modify global voucher templates.';
		} else {
			$template = './voucher/' . $telplatet . '.php';
			$handle = @fopen($template, 'w');
			if ($handle) {
				fwrite($handle, $data);
				fclose($handle);
				$editorMsg = 'Global template (' . htmlspecialchars($telplatet) . '.php) saved.';
			} else {
				$editorErr = 'Cannot open file: ' . htmlspecialchars($template);
			}
		}
	}
} elseif (isset($_POST['restore_backup'])) {
	if ($targetResId !== '') {
		$canRestore = $isAdminUser || hasResellerPermission('restore_own_template');
		if (!$canRestore) {
			$editorErr = 'Access Denied: You do not have restore_own_template permission.';
		} else {
			$resTplDir = './uploads/reseller-templates/' . $targetResId;
			$activePath = $resTplDir . '/' . $telplatet . '.php';
			$bakPath = $resTplDir . '/' . $telplatet . '.bak.php';
			$fallbackPath = './voucher/' . $defaultSource . '.php';
			if (is_file($bakPath)) {
				@copy($bakPath, $activePath);
				$editorMsg = 'Restored previous backup for ' . htmlspecialchars($targetReseller['template_code']) . ' (' . htmlspecialchars($telplatet) . ').';
			} elseif (is_file($fallbackPath)) {
				@copy($fallbackPath, $activePath);
				$editorMsg = 'Restored default template for ' . htmlspecialchars($targetReseller['template_code']) . ' (' . htmlspecialchars($telplatet) . ').';
			}
		}
	}
}

// Resolve content to load into textarea
$loadedTemplateContent = '';
if ($telplate == "rdefault" || $telplate == "rthermal" || $telplate == "rsmall") {
	if (!$isAdminUser && !hasResellerPermission('restore_own_template')) {
		$editorErr = 'Access Denied: You do not have restore_own_template permission to reset templates.';
	}
	$loadedTemplateContent = @file_get_contents('./voucher/' . $defaultSource . '.php');
} elseif ($targetResId !== '') {
	$resFile = './uploads/reseller-templates/' . $targetResId . '/' . $telplatet . '.php';
	if (is_file($resFile)) {
		$loadedTemplateContent = @file_get_contents($resFile);
	} else {
		$loadedTemplateContent = @file_get_contents('./voucher/' . $telplatet . '.php');
	}
} else {
	$loadedTemplateContent = @file_get_contents('./voucher/' . $telplatet . '.php');
}

}
?>
<!-- Create a simple CodeMirror instance -->
<link rel="stylesheet" href="./css/editor.min.css">
<script src="./js/editor.min.js"></script>	

<style>
.CodeMirror {
  border: 1px solid #2f353a;
  height: 505px;
}
textarea{
  font-size:12px;
  border: 1px solid #2f353a;
}
</style>


		<div class="row">
	    	<div class="col-9">
	    		<div class="card">
					<div class="card-header">
						<h3>
							<i class="fa fa-edit"></i> <?= $_template_editor ?>
							<?php if ($targetReseller !== null) { ?>
								<span style="font-size:12px; margin-left:8px; padding:2px 8px; border-radius:3px; background:#1f6feb; color:#fff;">
									Reseller #<?= htmlspecialchars($targetReseller['id']); ?> | <?= htmlspecialchars($targetReseller['name']); ?> (<?= htmlspecialchars($targetReseller['template_code']); ?>)
								</span>
							<?php } else { ?>
								<span style="font-size:12px; margin-left:8px; padding:2px 8px; border-radius:3px; background:#2ea043; color:#fff;">
									Global Default Templates
								</span>
							<?php } ?>
						</h3>
					</div>
			<div class="card-body">
				<?php if ($editorMsg !== '') { ?>
					<div style="padding:8px 12px; margin-bottom:8px; border-radius:4px;" class="bg-success"><i class="fa fa-check-circle"></i> <?= $editorMsg; ?></div>
				<?php } ?>
				<?php if ($editorErr !== '') { ?>
					<div style="padding:8px 12px; margin-bottom:8px; border-radius:4px;" class="bg-danger"><i class="fa fa-ban"></i> <?= $editorErr; ?></div>
				<?php } ?>
				<form autocomplete="off" method="post" action="">
					<table class="table">
						<tr>
							<td>
							<div class="row">
								<div class="col-5 col-box-12">
								<?php if ($isAdminUser || hasResellerPermission('save_own_template') || hasResellerPermission('edit_own_template')) { ?>
								<button type="submit" title="Save template" class="btn bg-primary" name="save"><i class="fa fa-save"></i> <?= $_save ?></button>
								<?php } ?>
								<?php if ($targetResId !== '' && ($isAdminUser || hasResellerPermission('publish_own_template'))) { ?>
								<button type="submit" title="Publish reseller template" class="btn bg-blue" name="publish"><i class="fa fa-check"></i> Publish</button>
								<?php } ?>
								<?php if ($targetResId !== '' && ($isAdminUser || hasResellerPermission('restore_own_template'))) { ?>
								<button type="submit" title="Restore previous backup" class="btn bg-warning" name="restore_backup"><i class="fa fa-undo"></i> Restore</button>
								<?php } ?>
								<a class="btn bg-green" href="<?= $popup?>" title="View voucher with Logo"><i class="fa fa-image"></i> </a>
								<a class="btn bg-green" href="<?= $popupQR?>" title="View voucher with QR"><i class="fa fa-qrcode"></i> </a>
								</div>
								<div class="col-7 pd-t-5 pd-b-5 col-box-12">
								<div class="input-group">
								<?php if ($isAdminUser) {
									$allResList = loadResellers();
								?>
								<div class="input-group-4">
									<select style="padding:4.2px;" class="group-item group-item-l" onchange="window.location.href=this.value;">
										<option value="<?= $editorRouteBase; ?>&template=<?= urlencode($telplate); ?>&session=<?= urlencode($session); ?>&reseller_id=global" <?= $targetResId === '' ? 'selected' : ''; ?>>Scope: Global</option>
										<?php foreach ($allResList as $rOpt) { ?>
											<option value="<?= $editorRouteBase; ?>&template=<?= urlencode($telplate); ?>&session=<?= urlencode($session); ?>&reseller_id=<?= urlencode($rOpt['id']); ?>" <?= (string)$rOpt['id'] === $targetResId ? 'selected' : ''; ?>>
												#<?= htmlspecialchars($rOpt['id']); ?> <?= htmlspecialchars($rOpt['username']); ?> (<?= htmlspecialchars($rOpt['template_code']); ?>)
											</option>
										<?php } ?>
									</select>
								</div>
								<?php } ?>
            					<div class="input-group-2">
            						<div class="group-item group-item-m pd-2p5 text-center">Template</div>
            					</div>
								<div class="input-group-2">
									<select style="padding:4.2px;" class="group-item group-item-m" onchange="window.location.href=this.value+'&session=<?= $session . $resQuerySuffix; ?>';">
	    								<option><?= ucfirst($telplate); ?></option>
	    								<option value="<?= $editorRouteBase; ?>&template=default">Default</option>
	    								<option value="<?= $editorRouteBase; ?>&template=thermal">Thermal</option>
	    								<option value="<?= $editorRouteBase; ?>&template=small">Small</option>
	    							</select>
	    						</div>
								
								<div class="input-group-2">
            						<div class="group-item group-item-m pd-2p5 text-center">Reset</div>
            					</div>
	    						<div class="input-group-2">
	    							<select style="padding:4.2px;" class="group-item group-item-r" onchange="window.location.href=this.value+'&session=<?= $session . $resQuerySuffix; ?>';">
	    								<option><?= ucfirst($telplate); ?></option>
	    								<option value="<?= $editorRouteBase; ?>&template=rdefault">Default</option>
	    								<option value="<?= $editorRouteBase; ?>&template=rthermal">Thermal</option>
	    								<option value="<?= $editorRouteBase; ?>&template=rsmall">Small</option>
	    							</select>
	    						</div>
								</div>
								</div>
							</div>
	    					</td>
						</tr>
						</table>
	        	<textarea class="bg-dark" id="editorMikhmon" name="editor" style="width:100%" height="700"><?= htmlspecialchars($loadedTemplateContent); ?></textarea>
			</form>
			</div>
		</div>
		</div>
		<div class="col-3">
			<div class="card">
				<div class="card-header">
					<h3>Variable</h3>
				</div>
			<div class="card-body">
				<textarea id="var" class="bg-dark" readonly rows=39 style="width:100%" disabled><?= htmlspecialchars(file_get_contents('./voucher/variable.php')); ?></textarea>
			</div>
			</div>
		</div>
</div>

<script>
var _0x5b73=["\x75\x6E\x64\x65\x66\x69\x6E\x65\x64","\x4D\x69\x6B\x68\x6D\x6F\x6E\x53\x65\x73\x73\x69\x6F\x6E","\x69\x6E\x6E\x65\x72\x48\x54\x4D\x4C","\x67\x65\x74\x45\x6C\x65\x6D\x65\x6E\x74\x42\x79\x49\x64","\x73\x65\x74\x49\x74\x65\x6D","\x50\x6C\x65\x61\x73\x65\x20\x75\x73\x65\x20\x47\x6F\x6F\x67\x6C\x65\x20\x43\x68\x72\x6F\x6D\x65","\x67\x65\x74\x49\x74\x65\x6D","\x6E\x75\x6C\x6C","","\x4D\x69\x6B\x68\x6D\x6F\x6E\x20\x62\x61\x6A\x61\x6B\x61\x6E\x21\x20\x3A\x29","\x65\x64\x69\x74\x6F\x72\x4D\x69\x6B\x68\x6D\x6F\x6E","\x61\x70\x70\x6C\x69\x63\x61\x74\x69\x6F\x6E\x2F\x78\x2D\x68\x74\x74\x70\x64\x2D\x70\x68\x70","\x74\x6F\x4D\x61\x74\x63\x68\x69\x6E\x67\x54\x61\x67","\x66\x72\x6F\x6D\x54\x65\x78\x74\x41\x72\x65\x61","\x74\x68\x65\x6D\x65","\x6D\x61\x74\x65\x72\x69\x61\x6C","\x73\x65\x74\x4F\x70\x74\x69\x6F\x6E"];if( typeof (Storage)!== _0x5b73[0]){var _msEl=document[_0x5b73[3]](_0x5b73[1]);if(_msEl){sessionStorage[_0x5b73[4]](_0x5b73[1],_msEl[_0x5b73[2]])}}else {alert(_0x5b73[5])};var editor=CodeMirror[_0x5b73[13]](document[_0x5b73[3]](_0x5b73[10]),{lineNumbers:true,matchBrackets:true,mode:_0x5b73[11],indentUnit:4,indentWithTabs:true,lineWrapping:true,viewportMargin:Infinity,matchTags:{bothTags:true},extraKeys:{"\x43\x74\x72\x6C\x2D\x4A":_0x5b73[12]}});editor[_0x5b73[16]](_0x5b73[14],_0x5b73[15])
</script>
