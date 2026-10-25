<?php
/*
 *  NextGan WiFi Solution — Dedicated Admin Settings Page
 *  Houses Admin Username, Password, Quick Print QR, and Standalone Server (.zip) Download.
 */
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/reseller_acl.php');

if (!isMikhmonAdmin()) {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
  exit;
}

$backupAdm = getBackupAdminCredentials();
$useradm2 = isset($backupAdm['username']) ? $backupAdm['username'] : '';
$passadm2_plain = isset($backupAdm['password']) ? $backupAdm['password'] : '';

if (isset($_POST['save'])) {
  $suseradm = trim((string)$_POST['useradm']);
  $spassadm = encrypt($_POST['passadm']);
  $suseradm2 = isset($_POST['useradm2']) ? trim((string)$_POST['useradm2']) : '';
  $spassadm2_plain = isset($_POST['passadm2']) ? (string)$_POST['passadm2'] : '';
  $qrbt = ($_POST['qrbt']);

  $cari = array('1' => "mikhmon<|<$useradm", "mikhmon>|>$passadm");
  $ganti = array('1' => "mikhmon<|<$suseradm", "mikhmon>|>$spassadm");

  for ($i = 1; $i < 3; $i++) {
    $file = file("./include/config.php");
    $content = file_get_contents("./include/config.php");
    $newcontent = str_replace((string)$cari[$i], (string)$ganti[$i], "$content");
    file_put_contents("./include/config.php", "$newcontent");
  }

  saveBackupAdminCredentials($suseradm2, $spassadm2_plain);

  $gen = '<?php $qrbt="' . $qrbt . '";?>';
  $key = './include/quickbt.php';
  $handle = fopen($key, 'w') or die('Cannot open file:  ' . $key);
  fwrite($handle, $gen);
  fclose($handle);
  echo "<script>window.location='./admin.php?id=admin-settings&saved=1'</script>";
  exit;
}
?>
<script>
  function Pass(id){
    var x = document.getElementById(id);
    if (x.type === 'password') {
      x.type = 'text';
    } else {
      x.type = 'password';
    }
  }
</script>

<div class="row">
  <div class="col-6">
    <form autocomplete="off" method="post" action="">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="fa fa-gear"></i> <?= htmlspecialchars(isset($_settings) ? $_settings : 'Settings'); ?></h3>
        </div>
        <div class="card-body">
          <?php if (isset($_GET['saved']) && $_GET['saved'] == '1') { ?>
          <div style="padding:8px 12px; margin-bottom:10px; border-radius:4px; background:rgba(46,160,67,0.18); border:1px solid #2ea043; color:#2ea043; font-weight:bold;">
            <i class="fa fa-check-circle"></i> <?= htmlspecialchars(isset($_admin_settings_saved) ? $_admin_settings_saved : 'Admin settings saved successfully!'); ?>
          </div>
          <?php } ?>
          <table class="table table-sm">
            <tr>
              <td colspan="2" style="padding-top:6px; padding-bottom:4px; font-weight:bold; color:#3c8dbc;">
                <i class="fa fa-user-secret"></i> <?= htmlspecialchars(isset($_primary_admin_title) ? $_primary_admin_title : 'Primary Admin Account'); ?>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_user_name ?> (1)</td>
              <td><input class="form-control" id="useradm" type="text" size="10" name="useradm" title="Primary Admin Username" value="<?= htmlspecialchars($useradm); ?>" required="1"/></td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_password ?> (1)</td>
              <td>
                <div class="input-group">
                  <div class="input-group-11 col-box-10">
                    <input class="group-item group-item-l" id="passadm" type="password" size="10" name="passadm" title="Primary Admin Password" value="<?= htmlspecialchars(decrypt($passadm)); ?>" required="1"/>
                  </div>
                  <div class="input-group-1 col-box-2">
                    <div class="group-item group-item-r pd-2p5 text-center align-middle">
                      <input title="Show/Hide Password" type="checkbox" onclick="Pass('passadm')">
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <tr>
              <td colspan="2" style="padding-top:10px; padding-bottom:4px; font-weight:bold; color:#2ea043;">
                <i class="fa fa-shield"></i> <?= htmlspecialchars(isset($_backup_admin_title) ? $_backup_admin_title : 'Backup / Secondary Admin (Security Recovery)'); ?>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_user_name ?> (2)</td>
              <td><input class="form-control" id="useradm2" type="text" size="10" name="useradm2" placeholder="<?= htmlspecialchars(isset($_backup_user_placeholder) ? $_backup_user_placeholder : 'Second Admin Username'); ?>" title="Secondary / Backup Admin Username" value="<?= htmlspecialchars($useradm2); ?>"/></td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_password ?> (2)</td>
              <td>
                <div class="input-group">
                  <div class="input-group-11 col-box-10">
                    <input class="group-item group-item-l" id="passadm2" type="password" size="10" name="passadm2" placeholder="<?= htmlspecialchars(isset($_backup_pass_placeholder) ? $_backup_pass_placeholder : 'Second Admin Password'); ?>" title="Secondary / Backup Admin Password" value="<?= htmlspecialchars($passadm2_plain); ?>"/>
                  </div>
                  <div class="input-group-1 col-box-2">
                    <div class="group-item group-item-r pd-2p5 text-center align-middle">
                      <input title="Show/Hide Password" type="checkbox" onclick="Pass('passadm2')">
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_quick_print ?> QR</td>
              <td>
                <select class="form-control" name="qrbt">
                  <option><?= htmlspecialchars($qrbt); ?></option>
                  <option>enable</option>
                  <option>disable</option>
                </select>
              </td>
            </tr>
            <tr>
              <td></td>
              <td class="text-right">
                <div class="input-group-4">
                  <input class="group-item group-item-l" type="submit" style="cursor: pointer;" name="save" value="<?= $_save ?>"/>
                </div>
                <div class="input-group-2">
                  <div style="cursor: pointer;" class="group-item group-item-r pd-2p5 text-center" onclick="location.reload();" title="Reload Data"><i class="fa fa-refresh"></i></div>
                </div>
              </td>
            </tr>
          </table>
          <div id="loadV">v<?= $_SESSION['v']; ?> </div>
          <div><b id="newVer" class="text-green"></b></div>
        </div>
      </div>
    </form>
  </div>

  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-windows"></i> <?= htmlspecialchars(isset($_srv_dl_title) ? $_srv_dl_title : 'Download Complete Standalone Mikhmon Server (with MikhmonServer.exe)'); ?></h3>
      </div>
      <div class="card-body">
        <p style="margin-top:0; margin-bottom:10px;">
          <?= isset($_srv_dl_desc) ? $_srv_dl_desc : 'Click the button below to download the complete ready-made portable server:'; ?>
        </p>
        <div style="margin-bottom:12px;">
          <a class="btn bg-green pd-10" href="./settings/export_package.php" style="display:inline-block; font-weight:bold;">
            <i class="fa fa-download"></i> <?= htmlspecialchars(isset($_srv_dl_btn) ? $_srv_dl_btn : 'Download Complete Mikhmon Server (.zip)'); ?>
          </a>
        </div>
        <table class="table table-bordered table-sm" style="margin-bottom:0;">
          <tr>
            <td style="width:28px;"><b>1.</b></td>
            <td><?= isset($_srv_dl_step1) ? $_srv_dl_step1 : ''; ?></td>
          </tr>
          <tr>
            <td><b>2.</b></td>
            <td><?= isset($_srv_dl_step2) ? $_srv_dl_step2 : ''; ?></td>
          </tr>
          <tr>
            <td><b>3.</b></td>
            <td><?= isset($_srv_dl_step3) ? $_srv_dl_step3 : ''; ?></td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</div>
