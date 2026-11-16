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

  $getprofile = $API->comm("/ip/hotspot/user/profile/print");
  $srvlist = $API->comm("/ip/hotspot/print");

  // One-time success message after POST/Redirect/GET.
  $addUserSuccess = '';
  if (isset($_SESSION['ngw_adduser_success'])) {
    $addUserSuccess = (string)$_SESSION['ngw_adduser_success'];
    unset($_SESSION['ngw_adduser_success']);
  }
  $addUserError = '';

  if (isset($_POST['save']) && isset($_POST['name'])) {
    $server = isset($_POST['server']) ? trim((string)$_POST['server']) : 'all';
    $name = trim((string)$_POST['name']);
    $password = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
    $profile = isset($_POST['profile']) ? trim((string)$_POST['profile']) : '';
    $timelimit = isset($_POST['timelimit']) ? trim((string)$_POST['timelimit']) : '';
    $datalimit = isset($_POST['datalimit']) ? trim((string)$_POST['datalimit']) : '';
    $comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : '';
    $mbgb = isset($_POST['mbgb']) ? (float)$_POST['mbgb'] : 1048576;

    if ($timelimit === '') $timelimit = '0';
    $datalimit = ($datalimit === '') ? '0' : (string)((float)$datalimit * $mbgb);

    if ($name === '' || $password === '' || $profile === '') {
      $addUserError = ($langid === 'bn') ? 'ইউজারনেম, পাসওয়ার্ড এবং প্রোফাইল আবশ্যক।' : 'Username, password and profile are required.';
    } else {
      $usermode = ($name === $password) ? 'vc-' : 'up-';
      $routerComment = $usermode . $comment;

      // Prevent a duplicate username from being mistaken for a successful new add.
      $existingUsers = $API->comm('/ip/hotspot/user/print', array('?name' => $name));
      $duplicate = false;
      if (is_array($existingUsers)) {
        foreach ($existingUsers as $existingUser) {
          if (isset($existingUser['name']) && (string)$existingUser['name'] === $name) {
            $duplicate = true;
            break;
          }
        }
      }

      if ($duplicate) {
        $addUserError = ($langid === 'bn') ? 'এই ইউজারনেমটি ইতিমধ্যেই রাউটারে বিদ্যমান আছে।' : 'This username already exists on the selected router.';
      } else {
        $addResult = $API->comm('/ip/hotspot/user/add', array(
          'server' => $server,
          'name' => $name,
          'password' => $password,
          'profile' => $profile,
          'disabled' => 'no',
          'limit-uptime' => $timelimit,
          'limit-bytes-total' => $datalimit,
          'comment' => $routerComment,
        ));

        $apiRejected = is_array($addResult) && (isset($addResult['!trap']) || isset($addResult['!fatal']));
        if ($apiRejected) {
          $addUserError = ($langid === 'bn') ? 'মাইক্রোটিক রাউটার ইউজার তৈরির অনুরোধ গ্রহণ করেনি। ইউজারনেম ও প্রোফাইল যাচাই করুন।' : 'MikroTik rejected the user creation request. Check the username, profile and router permissions.';
        } else {
          // Verify the account on the router before showing a success message.
          $verifiedUsers = $API->comm('/ip/hotspot/user/print', array('?name' => $name));
          $createdUser = false;
          if (is_array($verifiedUsers)) {
            foreach ($verifiedUsers as $verifiedUser) {
              if (isset($verifiedUser['name']) && (string)$verifiedUser['name'] === $name
                  && isset($verifiedUser['profile']) && (string)$verifiedUser['profile'] === $profile) {
                $createdUser = true;
                break;
              }
            }
          }

          if ($createdUser) {
            ngwRecordActivity('hotspot_user_created', array(
              'router' => $session,
              'profile' => $profile,
              'target' => $name
            ));
            $_SESSION['ngw_adduser_success'] = ($langid === 'bn') ? ('ইউজার "' . $name . '" সফলভাবে তৈরি হয়েছে।') : ('User "' . $name . '" added successfully.');
            // The main index template may already have emitted HTML, so use a
            // same-page client redirect rather than a PHP header redirect.
            $addUserReturnUrl = './?hotspot-user=add&session=' . rawurlencode((string)$session);
            echo '<script>window.location.replace(' . json_encode($addUserReturnUrl) . ');</script>';
            exit;
          }
          $addUserError = ($langid === 'bn') ? 'রাউটার নিশ্চিতকরণ ব্যর্থ হয়েছে। ইউজার লিস্ট চেক করে আবার চেষ্টা করুন।' : 'Router confirmation failed. Check the user list before trying again.';
        }
      }
    }
  }
}
?>
<script>
  function PassUser(){
    var x = document.getElementById('passUser');
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
    }}
</script>
<div class="row">
<div class="col-8">
<div class="card box-bordered">
  <div class="card-header">
  <h3><i class="fa fa-user-plus"></i> <?= $_add_user ?> <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small></h3> 
  </div>
  <div class="card-body">
  <?php if ($addUserSuccess !== '') { ?>
    <div class="alert alert-success" role="status"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($addUserSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php } ?>
  <?php if ($addUserError !== '') { ?>
    <div class="alert alert-danger" role="alert"><i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($addUserError, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php } ?>
<form autocomplete="off" method="post" action="">  
  <div>
  <?php if ($_SESSION['ubp'] != "") {
    echo "    <a class='btn bg-warning' href='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'> <i class='fa fa-close'></i> ".$_close."</a>";
  } else {
    echo "    <a class='btn bg-warning' href='./?hotspot=users&profile=all&session=" . $session . "'> <i class='fa fa-close'></i> ".$_close."</a>";
  }
  ?>
    <button type="submit" onclick="loader()" class="btn bg-primary" name="save"><i class="fa fa-save"></i> <?= $_save ?></button>
  </div>

<table class="table">
  <tr>
    <td class="align-middle" ><?= isset($_server) ? $_server : 'Server'; ?></td>
    <td>
			<select class="form-control" name="server" required="1">
				<option>all</option>
				<?php $TotalReg = count($srvlist);
    for ($i = 0; $i < $TotalReg; $i++) {
      echo "<option>" . $srvlist[$i]['name'] . "</option>";
    }
    ?>
			</select>
		</td>
	</tr>
  <tr>
    <td class="align-middle"><?= $_name ?></td><td><input class="form-control" type="text" autocomplete="off" name="name" value="<?= isset($name) ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : ''; ?>" required="1" autofocus></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_password ?></td><td>
        <div class="input-group">
          <div class="input-group-11 col-box-10">
            <input class="group-item group-item-l" id="passUser" type="password" name="pass" autocomplete="new-password" value="" required="1">
          </div>
            <div class="input-group-1 col-box-2">
              <div class="group-item group-item-r pd-2p5 text-center">
              <input title="Show/Hide Password" type="checkbox" onclick="PassUser()">
            </div>
            </div>
        </div>
		</td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_profile ?></td><td>
			<select class="form-control" onchange="GetVP();"  id="uprof" name="profile" required="1">
				<?php $TotalReg = count($getprofile);
    for ($i = 0; $i < $TotalReg; $i++) {
      echo "<option>" . $getprofile[$i]['name'] . "</option>";
    }
    ?>
			</select>
		</td>
	</tr>
	<tr>
    <td class="align-middle"><?= $_time_limit ?></td><td><input class="form-control" type="text"  autocomplete="off" name="timelimit" value=""></td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_data_limit ?></td><td>
      <div class="input-group">
        <div class="input-group-10 col-box-9">
          <input class="group-item group-item-l" type="number" min="0" max="9999" name="datalimit" value="<?= $udatalimit; ?>">
        </div>
          <div class="input-group-2 col-box-3">
              <select style="padding:4.2px;" class="group-item group-item-r" name="mbgb" required="1">
				        <option value=1048576>MB</option>
				        <option value=1073741824>GB</option>
			        </select>
          </div>
      </div>
    </td>
  </tr>
  <tr>
    <td class="align-middle"><?= $_comment ?></td><td><input class="form-control" type="text" title="No special characters" id="comment" autocomplete="off" name="comment" value="<?= isset($_POST['comment']) ? htmlspecialchars((string)$_POST['comment'], ENT_QUOTES, 'UTF-8') : ''; ?>"></td>
  </tr>
  <tr >
    <td  colspan="4" class="align-middle"  id="GetValidPrice"></td>
  </tr>
</table>
</form>
</div>
</div>
</div>
<div class="col-4">
  <div class="card">
    <div class="card-header">
      <h3><i class="fa fa-book"></i> <?= $_readme ?></h3>
    </div>
    <div class="card-body">
<table>
   <tr>
    <td colspan="2">
    <p style="padding:0px 5px;">
      <?= $_format_time_limit ?>
    </p>
    <p style="padding:0px 5px;">
      <?= $_details_add_user ?>
    </p>
    </td>
  </tr>
</table>
</div>
</div>
</div>
<script>
// get valid $ price
function GetVP(){
  var prof = document.getElementById('uprof').value;
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata");
}  
</script>
</div>