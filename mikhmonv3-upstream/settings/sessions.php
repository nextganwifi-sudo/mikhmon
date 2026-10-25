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

// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/reseller_acl.php');

// array color
  $color = array('1' => 'bg-blue', 'bg-indigo', 'bg-purple', 'bg-pink', 'bg-red', 'bg-yellow', 'bg-green', 'bg-teal', 'bg-cyan', 'bg-grey', 'bg-light-blue');

  if (isset($_POST['save'])) {
    if (!isMikhmonAdmin()) {
      echo "<script>alert('Access Denied: Only Administrator can modify Admin credentials.');window.location='./admin.php?id=sessions'</script>";
      exit;
    }

    $suseradm = ($_POST['useradm']);
    $spassadm = encrypt($_POST['passadm']);
    $logobt = ($_POST['logobt']);
    $qrbt = ($_POST['qrbt']);

    $cari = array('1' => "mikhmon<|<$useradm", "mikhmon>|>$passadm");
    $ganti = array('1' => "mikhmon<|<$suseradm", "mikhmon>|>$spassadm");

    for ($i = 1; $i < 3; $i++) {
      $file = file("./include/config.php");
      $content = file_get_contents("./include/config.php");
      $newcontent = str_replace((string)$cari[$i], (string)$ganti[$i], "$content");
      file_put_contents("./include/config.php", "$newcontent");
    }

  
  $gen = '<?php $qrbt="' . $qrbt . '";?>';
          $key = './include/quickbt.php';
          $handle = fopen($key, 'w') or die('Cannot open file:  ' . $key);
          fwrite($handle, $gen);
          fclose($handle);
    echo "<script>window.location='./admin.php?id=sessions'</script>";
  }

}
?>
<script>
  function Pass(id){
    var x = document.getElementById(id);
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
    }}
</script>

<div class="row">
	<div class="col-12">
  	<div class="card">
  		<div class="card-header">
  			<h3 class="card-title"><i class="fa fa-gear"></i> <?= $_admin_settings ?> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer " title="Reload data"></i></h3>
  		</div>
      <div class="card-body">
        <div class="row">
          <div class="col-6">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-server"></i> <?= $_router_list ?></h3>
              </div>
            <div class="card-body">
            <div class="row">
              <?php
              foreach (file('./include/config.php') as $line) {
                $value = explode("'", $line)[1];
                if ($value == "" || $value == "mikhmon" || !canAccessRouterSession($value)) {
                } else { ?>
                    <div class="col-12">
                        <div class="box bmh-75 box-bordered <?= $color[rand(1, 11)]; ?>">
                                <div class="box-group">
                                  
                                  <div class="box-group-icon">
                                    <span class="connect pointer" id="<?= $value; ?>">
                                    <i class="fa fa-server"></i>
                                    </span>
                                  </div>
                                
                                  <div class="box-group-area">
                                    <span>
                                      <?php
                                        $rIpRaw = explode('!', $data[$value][1])[1];
                                        $rIsSsh = (strpos($rIpRaw, 'ssh://') === 0);
                                      ?>
                                      <?= $_hotspot_name ?> : <?= explode('%', $data[$value][4])[1]; ?><br>
                                      <?= $_session_name ?> : <?= $value; ?> <span style="font-size:10px; padding:1px 5px; border-radius:3px; background:rgba(0,0,0,0.35); color:#fff; font-weight:bold;"><?= $rIsSsh ? 'SSH' : 'API'; ?></span><br>
                                      <span class="connect pointer"  id="<?= $value; ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>&nbsp;
                                      <?php if (hasResellerPermission('manage_router_settings')) { ?>
                                      <a href="./admin.php?id=settings&session=<?= $value; ?>"><i class="fa fa-edit"></i> <?= $_edit ?></a>&nbsp;
                                      <a href="javascript:void(0)" onclick="if(confirm('Are you sure to delete data <?= $value;
                                      echo " (" . explode('%', $data[$value][4])[1] . ")"; ?>?')){loadpage('./admin.php?id=remove-session&session=<?= $value; ?>')}else{}"><i class="fa fa-remove"></i> <?= $_delete ?></a>
                                      <?php } ?>
                                    </span>

                                  </div>
                                </div>
                              
                            </div>
                          </div>
              <?php
            }
          }
          ?>
              </div>
            </div>
          </div>
        </div>
			    <div class="col-6">
          <?php if (isMikhmonAdmin()) { ?>
          <form autocomplete="off" method="post" action="">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-user-circle"></i> <?= $_admin ?> &nbsp; | &nbsp; <a href="./admin.php?id=resellers"><i class="fa fa-users"></i> <?= isset($_reseller) ? $_reseller : 'Reseller'; ?></a> &nbsp; | &nbsp; <a href="./admin.php?id=system-update"><i class="fa fa-cloud-upload"></i> <?= isset($_system_update) ? $_system_update : 'System Update & VPS'; ?></a></h3>
              </div>
            <div class="card-body">
      <table class="table table-sm">
        <tr>
          <td class="align-middle"><?= $_user_name ?> </td><td><input class="form-control" id="useradm" type="text" size="10" name="useradm" title="User Admin" value="<?= $useradm; ?>" required="1"/></td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_password ?> </td>
          <td>
          <div class="input-group">
          <div class="input-group-11 col-box-10">
                <input class="group-item group-item-l" id="passadm" type="password" size="10" name="passadm" title="Password Admin" value="<?= decrypt($passadm); ?>" required="1"/>
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
          <td class="align-middle"><?= $_quick_print ?> QR</td>
          <td>
            <select class="form-control" name="qrbt">
            <option><?= $qrbt ?></option>
              <option>enable</option>
              <option>disable</option>
            </select>
          </td>
        </tr>
        <tr>
          <td></td><td class="text-right">
              <div class="input-group-4">
                  <input class="group-item group-item-l" type="submit" style="cursor: pointer;" name="save" value="<?= $_save ?>"/>
                </div>
                <div class="input-group-2">
                  <div style="cursor: pointer;" class="group-item group-item-r pd-2p5 text-center" onclick="location.reload();" title="Reload Data"><i class="fa fa-refresh"></i></div>
                </div>
                </div>
          </td>
        </tr>
        
      </table>
      <div id="loadV">v<?= $_SESSION['v']; ?> </div>
      <div><b id="newVer" class="text-green"></b></div>
    </div>
    </div>
    </form>
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
          <?php } else { ?>
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-user-circle"></i> Reseller Session: <?= htmlspecialchars($_SESSION['mikhmon']); ?></h3>
              </div>
              <div class="card-body">
                <table class="table table-sm">
                  <tr>
                    <td><?= $_user_name ?></td>
                    <td><b><?= htmlspecialchars($_SESSION['mikhmon']); ?></b></td>
                  </tr>
                  <tr>
                    <td>Role</td>
                    <td><b class="text-green">Reseller (Router Isolated)</b></td>
                  </tr>
                  <tr>
                    <td>Assigned Routers</td>
                    <td><?= htmlspecialchars(implode(', ', isset($_SESSION['mikhmon_routers']) ? $_SESSION['mikhmon_routers'] : array())); ?></td>
                  </tr>
                </table>
                <div id="loadV" style="margin-bottom:8px;">NextGan WiFi Solution v<?= $_SESSION['v']; ?> </div>
                <div>
                  <a class="btn bg-primary pd-5" href="./admin.php?id=about"><i class="fa fa-info-circle"></i> <?= isset($_about) ? $_about : 'About' ?></a>
                </div>
              </div>
            </div>
          <?php } ?>
  </div>
</div>
</div>
</div>
</div>
</div>
