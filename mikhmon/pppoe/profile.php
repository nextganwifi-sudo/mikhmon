<?php
/*
 *  NextGan WiFi Solution — Add / Edit PPPoE Profile
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  echo "<script>window.location='./admin.php?id=login'</script>";
  return;
}

include_once('./include/config.php');
include_once('./include/ros_version.php');
include_once('./include/reseller_acl.php');
include_once('./include/pppoe_helper.php');

requireResellerPermission('manage_pppoe_profiles', './?pppoe=profiles&session=' . $session);

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));
$profileId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$isEdit = ($profileId !== '');

// Fetch IP pools for dropdown
$pools = $isRouterConnected ? $API->comm("/ip/pool/print") : array();

$profileError = '';
$existingProfile = null;

if (!$isRouterConnected) {
  $profileError = 'Router is offline or unreachable via API port 8728.';
} elseif ($isEdit) {
  $found = $API->comm("/ppp/profile/print", array("?.id" => $profileId));
  if (is_array($found) && !empty($found)) {
    $existingProfile = $found[0];
  } else {
    $profileError = 'PPPoE Profile not found on router.';
  }
}

// Handle Form Submission
if (isset($_POST['save_profile'])) {
  $name = trim((string)$_POST['name']);
  $localAddress = trim((string)$_POST['local_address']);
  $remoteAddress = trim((string)$_POST['remote_address']);
  $rateLimit = trim((string)$_POST['rate_limit']);
  $dnsServer = trim((string)$_POST['dns_server']);
  $onlyOne = trim((string)$_POST['only_one']);

  if ($name === '') {
    $profileError = 'Profile name is required.';
  } else {
    // Check duplicates on add
    if (!$isEdit) {
      $dup = $API->comm("/ppp/profile/print", array("?name" => $name));
      if (is_array($dup) && !empty($dup)) {
        $profileError = 'Profile with this name already exists.';
      }
    }

    if ($profileError === '') {
      $profileData = array(
        'name' => $name,
        'only-one' => $onlyOne !== '' ? $onlyOne : 'yes',
      );

      if ($localAddress !== '') {
        $profileData['local-address'] = $localAddress;
      } else {
        $profileData['local-address'] = '';
      }

      if ($remoteAddress !== '') {
        $profileData['remote-address'] = $remoteAddress;
      } else {
        $profileData['remote-address'] = '';
      }

      if ($rateLimit !== '') {
        $profileData['rate-limit'] = $rateLimit;
      } else {
        $profileData['rate-limit'] = '';
      }

      if ($dnsServer !== '') {
        $profileData['dns-server'] = $dnsServer;
      } else {
        $profileData['dns-server'] = '';
      }

      if ($isEdit) {
        $profileData['.id'] = $existingProfile['.id'];
        $res = $API->comm("/ppp/profile/set", $profileData);
        if (is_array($res) && (isset($res['!trap']) || isset($res['!fatal']))) {
          $profileError = 'Failed to update PPPoE profile: ' . (isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'MikroTik error');
        } else {
          ngwRecordActivity('pppoe_profile_updated', array('router' => $session, 'profile' => $name));
          echo "<script>window.location='./?pppoe=profiles&session=" . $session . "&msg=updated';</script>";
          exit;
        }
      } else {
        $res = $API->comm("/ppp/profile/add", $profileData);
        if (is_array($res) && (isset($res['!trap']) || isset($res['!fatal']))) {
          $profileError = 'Failed to create PPPoE profile: ' . (isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'MikroTik error');
        } else {
          ngwRecordActivity('pppoe_profile_created', array('router' => $session, 'profile' => $name));
          echo "<script>window.location='./?pppoe=profiles&session=" . $session . "&msg=created';</script>";
          exit;
        }
      }
    }
  }
}

// Current values
$valName = $isEdit ? (isset($existingProfile['name']) ? $existingProfile['name'] : '') : '';
$valLocal = $isEdit ? (isset($existingProfile['local-address']) ? $existingProfile['local-address'] : '') : '';
$valRemote = $isEdit ? (isset($existingProfile['remote-address']) ? $existingProfile['remote-address'] : '') : '';
$valRate = $isEdit ? (isset($existingProfile['rate-limit']) ? $existingProfile['rate-limit'] : '') : '';
$valDns = $isEdit ? (isset($existingProfile['dns-server']) ? $existingProfile['dns-server'] : '') : '';
$valOnlyOne = $isEdit ? (isset($existingProfile['only-one']) ? $existingProfile['only-one'] : 'yes') : 'yes';
$isSystem = ($valName === 'default' || $valName === 'default-encryption');
$isBn = (isset($langid) && $langid === 'bn');
?>

<div class="row">
  <div class="col-12 col-md-8 col-lg-7" style="max-width: 700px; margin: 0 auto;">
    <div class="card box-bordered">
      <div class="card-header bg-navy text-white d-flex justify-content-between align-items-center" style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px;">
        <h3 style="margin:0; font-size:16px; font-weight:600;">
          <i class="fa fa-pie-chart"></i> <?= $isEdit ? ($isBn ? 'পিপিপিওই প্রোফাইল এডিট' : 'Edit PPPoE Profile') : ($isBn ? 'নতুন পিপিপিওই প্রোফাইল যোগ' : 'Add New PPPoE Profile'); ?>
        </h3>
        <a class="btn btn-sm bg-warning text-white" href="./?pppoe=profiles&session=<?= $session; ?>">
          <i class="fa fa-arrow-left"></i> <?= $isBn ? 'ফিরে যান' : 'Back'; ?>
        </a>
      </div>

      <div class="card-body" style="padding:14px;">
        <?php if ($profileError !== '') { ?>
          <div class="alert alert-danger" style="margin-bottom:12px; padding:8px 12px; border-radius:4px;">
            <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <table class="table" style="margin-bottom:0; width:100%;">
            <tr>
              <td style="width:35%; vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'প্রোফাইল নাম *' : 'Profile Name *'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($valName); ?>" placeholder="<?= $isBn ? 'উদাঃ 10Mbps-Monthly' : 'e.g. 10Mbps-Monthly'; ?>" <?= ($isSystem ? 'readonly' : 'autofocus'); ?>>
                <?php if ($isSystem) { ?>
                  <small class="text-muted"><?= $isBn ? 'সিস্টেম ডিফল্ট প্রোফাইল নাম পরিবর্তন করা যাবে না।' : 'System default profile name cannot be changed.'; ?></small>
                <?php } ?>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">
                <?= $isBn ? 'রেট লিমিট (Rx/Tx)' : 'Rate Limit (Rx/Tx)'; ?>
                <div style="font-size:11px; font-weight:normal; color:#6b7280;"><?= $isBn ? 'ব্যান্ডউইথ লিমিট' : 'Bandwidth Limit'; ?></div>
              </td>
              <td style="padding:8px 4px;">
                <input type="text" name="rate_limit" class="form-control" value="<?= htmlspecialchars($valRate); ?>" placeholder="<?= $isBn ? 'উদাঃ 5M/10M বা 2M/2M' : 'e.g. 5M/10M or 2M/2M'; ?>">
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'ফরমেটঃ [rx-rate]/[tx-rate] (আপলোড/ডাউনলোড) উদাঃ 5M/10M' : 'Format: [rx-rate]/[tx-rate] (Upload/Download) e.g. 5M/10M'; ?></small>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'লোকাল আইপি (গেটওয়ে)' : 'Local Address (Gateway)'; ?></td>
              <td style="padding:8px 4px;">
                <input list="localAddressList" type="text" name="local_address" class="form-control" value="<?= htmlspecialchars($valLocal); ?>" placeholder="<?= $isBn ? 'আইপি অথবা পুল নির্বাচন করুন' : 'IP or select pool'; ?>">
                <datalist id="localAddressList">
                  <?php if (is_array($pools)) {
                    foreach ($pools as $pl) {
                      $plName = isset($pl['name']) ? $pl['name'] : '';
                      echo "<option value=\"" . htmlspecialchars($plName) . "\">";
                    }
                  } ?>
                </datalist>
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'ক্লায়েন্টদের জন্য গেটওয়ে আইপি (উদাঃ 192.168.10.1 বা পুল নাম)' : 'Gateway IP address for clients (e.g. 192.168.10.1 or pool name)'; ?></small>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'রিমোট আইপি (পুল)' : 'Remote Address (Pool)'; ?></td>
              <td style="padding:8px 4px;">
                <input list="remoteAddressList" type="text" name="remote_address" class="form-control" value="<?= htmlspecialchars($valRemote); ?>" placeholder="<?= $isBn ? 'আইপি পুল বা স্ট্যাটিক আইপি নির্বাচন করুন' : 'Select IP Pool or static IP'; ?>">
                <datalist id="remoteAddressList">
                  <?php if (is_array($pools)) {
                    foreach ($pools as $pl) {
                      $plName = isset($pl['name']) ? $pl['name'] : '';
                      echo "<option value=\"" . htmlspecialchars($plName) . "\">";
                    }
                  } ?>
                </datalist>
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'পিপিপিওই ক্লায়েন্টদের আইপি প্রদানের জন্য ব্যবহৃত আইপি পুল' : 'IP Pool used to assign IP addresses to PPPoE clients'; ?></small>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'ডিএনএস সার্ভার (DNS)' : 'DNS Server'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="dns_server" class="form-control" value="<?= htmlspecialchars($valDns); ?>" placeholder="e.g. 8.8.8.8,1.1.1.1">
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'একক সেশন (Only One)' : 'Only One'; ?></td>
              <td style="padding:8px 4px;">
                <select name="only_one" class="form-control" style="max-width:200px;">
                  <option value="yes" <?= ($valOnlyOne === 'yes') ? 'selected' : ''; ?>><?= $isBn ? 'হ্যাঁ (একক সেশন)' : 'Yes (Single Session)'; ?></option>
                  <option value="no" <?= ($valOnlyOne === 'no') ? 'selected' : ''; ?>><?= $isBn ? 'না (একাধিক সেশন)' : 'No (Multiple Sessions)'; ?></option>
                  <option value="default" <?= ($valOnlyOne === 'default') ? 'selected' : ''; ?>><?= $isBn ? 'ডিফল্ট' : 'Default'; ?></option>
                </select>
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'প্রতিটি ইউজারনেমকে একসাথে একটি সংযোগে সীমাবদ্ধ রাখে।' : 'Restricts each username to one concurrent connection.'; ?></small>
              </td>
            </tr>
          </table>

          <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
            <a class="btn bg-secondary text-white" href="./?pppoe=profiles&session=<?= $session; ?>">
              <i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?>
            </a>
            <button type="submit" name="save_profile" class="btn bg-primary text-white" style="font-weight:600;">
              <i class="fa fa-save"></i> <?= $isBn ? ($isEdit ? 'প্রোফাইল আপডেট করুন' : 'প্রোফাইল সেভ করুন') : ($isEdit ? 'Update Profile' : 'Save Profile'); ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
