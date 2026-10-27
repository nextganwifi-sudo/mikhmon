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
}
?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-server"></i> <?= $_router_list ?> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="Reload data"></i></h3>
      </div>
      <div class="card-body">
        <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status" style="margin-bottom:12px;">
            <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <?php unset($_SESSION['router_settings_notice']); ?>
        <?php } ?>
        <div class="row">
          <?php
          foreach (file('./include/config.php') as $line) {
            $value = explode("'", $line)[1];
            if ($value == "" || $value == "mikhmon" || !canAccessRouterSession($value)) {
            } else { ?>
              <div class="col-6">
                <div class="box bmh-75 box-bordered <?= $color[rand(1, 11)]; ?>">
                  <div class="box-group">
                    <div class="box-group-icon">
                      <span class="connect pointer" id="<?= $value; ?>">
                        <i class="fa fa-server"></i>
                      </span>
                    </div>
                    <div class="box-group-area">
                      <span>
                        <?= $_hotspot_name ?> : <?= explode('%', $data[$value][4])[1]; ?><br>
                        <?= $_session_name ?> : <?= $value; ?><br>
                        <span class="connect pointer" id="<?= $value; ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>&nbsp;
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
</div>
