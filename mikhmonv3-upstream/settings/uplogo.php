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
  $isAdminUser = isMikhmonAdmin();
  $currentReseller = getCurrentResellerRecord();

  if (!$isAdminUser && $currentReseller !== null) {
    // Reseller-isolated logo upload handler
    if (isset($_POST["submit"]) && isset($_FILES["UploadLogo"])) {
      $upRes = handleResellerLogoUpload($currentReseller['id'], $_FILES["UploadLogo"]);
      if ($upRes['ok']) {
        $allRes = loadResellers();
        foreach ($allRes as $idx => $rItem) {
          if ((string)$rItem['id'] === (string)$currentReseller['id']) {
            $allRes[$idx]['logo_filename'] = $upRes['filename'];
          }
        }
        saveResellers($allRes);
        $currentReseller = getCurrentResellerRecord();
        $galat = '<div class="box bg-success"><i class="fa fa-check-circle"></i> Success! Reseller logo (' . htmlspecialchars($upRes['filename']) . ') uploaded for ' . htmlspecialchars($currentReseller['name']) . '.</div>';
      } else {
        $galat = '<div class="box bg-danger"><i class="fa fa-ban"></i> Alert!<br> ' . htmlspecialchars($upRes['error']) . '</div>';
      }
    } elseif (isset($_POST["reset_reseller_logo"])) {
      removeResellerCustomLogo($currentReseller['id']);
      $currentReseller = getCurrentResellerRecord();
      $galat = '<div class="box bg-success"><i class="fa fa-check-circle"></i> Reseller custom logo removed. Reverting to default session/global logo.</div>';
    }
  } else {
    // Original Administrator session logo upload handler (img/logo-{session}.png)
    if (isset($_POST["submit"])) {
      $logo_dir = "./img/";
      $logo_file = $logo_dir . basename($_FILES["UploadLogo"]["name"]);
      $uploadOk = 1;
      $imageFileType = strtolower(pathinfo($logo_file, PATHINFO_EXTENSION));

      $check = getimagesize($_FILES["UploadLogo"]["tmp_name"]);
      if ($check !== false) {
        $galat = '<div class="box bg-danger"></i> Alert!<br> File name is : ' . basename($_FILES["UploadLogo"]["name"]) . '. </div>';
        $uploadOk = 1;
      } else {
        if ($currency == in_array($currency, $cekindo['indo'])) {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  File bukan gambar. </div>';
        } else {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  File is not an image. </div>';
        }
        $uploadOk = 0;
      }

      if ($_FILES["UploadLogo"]["size"] > 2000000) {
        if ($currency == in_array($currency, $cekindo['indo'])) {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  Ukuran file terlalu besar. </div>';
        } else {
          $galat = '<div class="box bg-danger"></i> Alert!<br> File is too large. </div>';
        }
        $uploadOk = 0;
      }

      if (basename($_FILES["UploadLogo"]["name"] != "logo-" . $session . ".png")) {
        if ($currency == in_array($currency, $cekindo['indo'])) {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  Hanya bisa upload logo-' . $session . '.png. </div>';
        } else {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  Only logo-' . $session . '.png are allowed. </div>';
        }
        $uploadOk = 0;
      }

      if ($uploadOk == 0) {
        if ($currency == in_array($currency, $cekindo['indo'])) {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  File tidak diupload. Nama file harus logo-'.$session.'.png</div>';
        } else {
          $galat = '<div class="box bg-danger"></i> Alert!<br>  File was not uploaded. File name must be  logo-'.$session.'.png</div>';
        }
      } else {
        if (move_uploaded_file($_FILES["UploadLogo"]["tmp_name"], $logo_file)) {
          // Also keep images/ synchronized when a session logo is uploaded
          if (is_dir('./images')) {
            @copy($logo_file, './images/' . basename($logo_file));
          }
          if ($currency == in_array($currency, $cekindo['indo'])) {
            $galat = '<div class="box bg-success"></i> Alert!<br>  Success!</h5> File ' . basename($_FILES["UploadLogo"]["name"]) . ' telah diupload. </div>';
          } else {
            $galat = '<div class="box bg-success"></i> Alert!<br>  Success!</h5> The File ' . basename($_FILES["UploadLogo"]["name"]) . ' has been uploaded. </div>';
          }
        } else {
          if ($currency == in_array($currency, $cekindo['indo'])) {
            $galat = '<div class="box bg-danger"></i> Alert!<br>  Terjadi masalah ketika upload file. Nama file harus logo-'.$session.'.png </div>';
          } else {
            $galat = '<div class="box bg-danger"></i> Alert!<br>  There was an error uploading your file. File name must be  logo-'.$session.'.png </div>';
          }
        }
      }
    }
  }
}
?>
<div class="row">
<div class="col-12">
  <div class="card">
    <div class="card-header">
        <h3 class="card-title">
          <i class="fa fa-upload"></i> <?= $_upload_logo ?>
          <?php if (!$isAdminUser && $currentReseller !== null) { ?>
            <span style="font-size:12px; margin-left:8px; padding:2px 8px; border-radius:3px; background:#1f6feb; color:#fff;">
              Reseller #<?= htmlspecialchars($currentReseller['id']); ?> (<?= htmlspecialchars($currentReseller['name']); ?>)
            </span>
          <?php } ?>
        </h3>
    </div>
    <div class="card-body">
      <div>
    <?= $galat; ?>
      <form action="" method="post" enctype="multipart/form-data">
          <?php if (!$isAdminUser && $currentReseller !== null) { ?>
            <div class="pd-10">Upload Reseller Logo (PNG, JPG, WebP, max 2 MB) — stored in <code>uploads/reseller-logos/<?= htmlspecialchars($currentReseller['id']); ?>/</code></div>
          <?php } else { ?>
            <div class="pd-10"><?= $_format_file_name ?> : logo-<?= $session; ?>.png </div>
          <?php } ?>
          <div class="input-group">
            <div class="input-group-4 col-box-8">
                <input style="cursor: pointer; " type="file" class="group-item group-item-l" name="UploadLogo" accept="image/png,image/jpeg,image/webp">
            </div>
            <div class="input-group-2 col-box-4">
                <input style="cursor: pointer; font-size: 14px; padding:8px;" class="group-item group-item-r" type="submit" value="<?= $_upload ?>" title="Upload logo" name="submit">
            </div>
          </div>
      </form>
    </div>
      <div class="mr-t-10">
      <?php if (!$isAdminUser && $currentReseller !== null) {
        $activeLogoUrl = resolveActiveVoucherLogo($session, './', true);
      ?>
      <table class="table table-bordered table-hover">
        <thead>
        <tr>
          <th>Active Reseller Voucher Logo</th>
          <th>Source</th>
          <th><?= $_action ?></th>
        </tr>
        </thead>
        <tbody>
          <tr>
            <td><img height="45px" src="<?= htmlspecialchars($activeLogoUrl); ?>" alt="Reseller Logo"></td>
            <td>
              <?php if (!empty($currentReseller['logo_filename'])) { ?>
                <code>uploads/reseller-logos/<?= htmlspecialchars($currentReseller['id']); ?>/<?= htmlspecialchars($currentReseller['logo_filename']); ?></code>
              <?php } else { ?>
                <span>Default Session / Global Logo Fallback</span>
              <?php } ?>
            </td>
            <td>
              <?php if (!empty($currentReseller['logo_filename'])) { ?>
                <form action="" method="post" style="display:inline;">
                  <button type="submit" name="reset_reseller_logo" value="1" class="btn bg-danger"><i class="fa fa-trash"></i> Reset to Default Logo</button>
                </form>
              <?php } else { ?>
                <span style="color:#8b949e;">Using Default</span>
              <?php } ?>
            </td>
          </tr>
        </tbody>
      </table>
      <?php } else { ?>
      <table class="table table-bordered table-hover">
        <thead>
        <tr>
          <th><?= $_list_logo ?></th>
          <th><?= $_action ?></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $dir = "./img";
        if (is_dir($dir)) {
          if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
              if ($file != "." && $file != "..") {
                if (substr($file, 0, 5) != "logo-" ||
                  substr($file, -5) == ".html" ||
                  substr($file, -4) == ".php" ||
                  substr($file, -4) == ".jpg" ||
                  substr($file, -4) == ".bak") {
                } else { ?>
              <tr>
                <td><a href="javascript:window.open('./img/<?= $file; ?>','_blank','width=300,height=300')"><img height="30px" src="./img/<?= $file; ?>" title="Open <?= $file; ?>"></a><br><span><?= $file; ?></span></td>
                <td><a class="btn bg-danger" href="javascript:void(0)" onclick="if(confirm('Sure to delete <?= $file; ?> ?')){window.location='./admin.php?id=remove-logo&logo=<?= $file; ?>&session=<?= $session ?>'}else{}"><i class="fa fa-trash"></i> <?= $_delete ?></a>
                </td>
              </tr>
          <?php 
        }
      }
    }
    closedir($dh);
  }
}
?>
      </tbody>
    </table>
    <?php } ?>
  </div>
  
  </div>
</div>
</div>
</div>