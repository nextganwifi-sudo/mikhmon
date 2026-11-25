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
require_once __DIR__ . '/security.php';
ngwConfigureSecureSession();
ngwSendNoCacheHeaders();
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
$isBnLoginCard = (isset($langid) && $langid === 'bn');
include_once __DIR__ . '/ui_settings.php';
$ngwLoginUi = function_exists('ngwResolveActiveUiStyle') ? ngwResolveActiveUiStyle() : 'classic';
$ngwLoginBgMap = array(
  'classic' => 'img/login/bg-classic.jpg?v=20261122k',
  'modern'  => 'img/login/bg-modern.jpg?v=20261122k',
  'compact' => 'img/login/bg-compact.jpg?v=20261122k',
  'aurora'  => 'img/login/bg-aurora.jpg?v=20261122k',
);
$ngwLoginBgUrl = isset($ngwLoginBgMap[$ngwLoginUi]) ? $ngwLoginBgMap[$ngwLoginUi] : $ngwLoginBgMap['classic'];
?>
<style>
  /* UI-Matched Login Backdrop & Card Artwork System (All 4 UIs) */
  .ngw-login-bg-stage {
    position: fixed;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
    background: #0b1120;
  }
  .ngw-login-bg-stage.is-classic {
    background: radial-gradient(circle at 50% 35%, #1e293b 0%, #0f172a 100%);
  }
  .ngw-login-bg-stage.is-modern {
    background: radial-gradient(circle at 50% 35%, #102a56 0%, #070d1c 100%);
  }
  .ngw-login-bg-stage.is-compact {
    background: radial-gradient(circle at 50% 35%, #272015 0%, #09090b 100%);
  }
  .ngw-login-bg-stage.is-aurora {
    background: radial-gradient(circle at 50% 35%, #0c3547 0%, #050d1a 100%);
  }
  .ngw-login-bg-stage .ngw-login-bg-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    display: block;
    transform: scale(1.02);
    filter: saturate(1.08) contrast(1.04);
  }
  .ngw-login-bg-stage .ngw-login-bg-scrim {
    position: absolute;
    inset: 0;
    background:
      radial-gradient(circle at 50% 48%, rgba(6, 11, 23, 0.25) 0%, rgba(4, 8, 18, 0.72) 100%),
      linear-gradient(180deg, rgba(4, 8, 18, 0.30) 0%, rgba(4, 8, 18, 0.58) 100%);
  }

  /* Login Box / Card Inner Background Image Layer */
  .login-box {
    position: relative;
    z-index: 2;
  }
  .ngw-login-card-surface {
    position: relative !important;
    overflow: hidden !important;
    isolation: isolate;
  }
  .ngw-login-card-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
    border-radius: inherit;
  }
  .ngw-login-card-bg img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    display: block;
    transform: scale(1.08);
    opacity: 0.52;
    filter: saturate(1.18) contrast(1.06);
  }
  .ngw-login-card-bg .ngw-login-card-scrim {
    position: absolute;
    inset: 0;
  }
  .ngw-login-card-surface > *:not(.ngw-login-card-bg) {
    position: relative;
    z-index: 1;
  }

  /* 1. Classic UI Login Box Styling with Matched Image */
  .ngw-classic-login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px !important;
    margin: 0 !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
  }
  .ngw-classic-login-card {
    width: 100%;
    max-width: 416px;
    border-radius: 16px !important;
    border: 1px solid rgba(148, 163, 184, 0.32) !important;
    background: rgba(15, 23, 42, 0.72) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 24px 55px rgba(2, 6, 23, 0.65), inset 0 1px 0 rgba(255, 255, 255, 0.14) !important;
    color: #f8fafc !important;
  }
  .ngw-classic-login-card .ngw-login-card-scrim {
    background: linear-gradient(165deg, rgba(15, 23, 42, 0.68) 0%, rgba(15, 23, 42, 0.84) 100%);
  }
  .ngw-classic-login-card .card-header {
    background: rgba(15, 23, 42, 0.52) !important;
    border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
    padding: 14px 18px !important;
    color: #f8fafc !important;
  }
  .ngw-classic-login-card .card-header h3 {
    color: #ffffff !important;
    font-weight: 700 !important;
  }
  .ngw-classic-login-card .card-body {
    background: transparent !important;
    padding: 20px 18px 22px !important;
    color: #f8fafc !important;
  }
  .ngw-classic-login-card .table,
  .ngw-classic-login-card .table tr,
  .ngw-classic-login-card .table td {
    background: transparent !important;
    border: none !important;
    color: #f8fafc !important;
  }
  .ngw-classic-login-card input.form-control {
    background: rgba(15, 23, 42, 0.72) !important;
    border: 1px solid rgba(148, 163, 184, 0.35) !important;
    color: #ffffff !important;
    border-radius: 8px !important;
    padding: 0 12px !important;
    height: 40px !important;
    box-sizing: border-box !important;
  }
  .ngw-classic-login-card input.form-control:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important;
  }
  .ngw-classic-login-card input.form-control::placeholder {
    color: rgba(203, 213, 225, 0.68) !important;
  }

  /* 2. Modern Studio UI Login Box Styling with Matched Image (2-col Desktop / Centered Round Mobile) */
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface {
    max-width: 780px;
    background: rgba(8, 17, 36, 0.72) !important;
    border: 1px solid rgba(96, 165, 250, 0.34) !important;
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    box-shadow: 0 26px 60px rgba(2, 6, 23, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.16) !important;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-login-card-scrim {
    background: linear-gradient(165deg, rgba(8, 18, 40, 0.64) 0%, rgba(10, 20, 44, 0.84) 100%);
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-brand {
    background: rgba(6, 14, 32, 0.56) !important;
    border-right: 1px solid rgba(148, 163, 184, 0.2);
    padding: 36px 32px !important;
    justify-content: center;
    gap: 22px;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-form-pane {
    background: transparent !important;
    padding: 34px 32px !important;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 20px;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-top h2,
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-label,
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-remember {
    color: #f1f5f9 !important;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-lang-seg {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.16);
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-lang-seg a {
    color: rgba(226, 232, 240, 0.85) !important;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-input-wrap .form-control {
    background: rgba(7, 14, 30, 0.68) !important;
    border-color: rgba(148, 163, 184, 0.32) !important;
    color: #ffffff !important;
  }
  body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-input-wrap .form-control::placeholder {
    color: rgba(203, 213, 225, 0.62) !important;
  }

  /* 3. Nova Command (Compact) UI Login Box Styling with Matched Image */
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card.ngw-login-card-surface {
    background: rgba(14, 13, 12, 0.72) !important;
    border: 2px solid rgba(245, 158, 11, 0.55) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 24px 55px rgba(0, 0, 0, 0.75), 6px 6px 0 rgba(245, 158, 11, 0.22) !important;
  }
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-login-card-scrim {
    background: linear-gradient(165deg, rgba(16, 14, 12, 0.66) 0%, rgba(18, 16, 13, 0.84) 100%);
  }
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-nova-banner {
    background: rgba(15, 14, 12, 0.58) !important;
  }
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-nova-body {
    background: transparent !important;
  }
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card h2,
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-auth-label,
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-auth-remember {
    color: #f8fafc !important;
  }
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-auth-input-wrap .form-control {
    background: rgba(12, 12, 14, 0.72) !important;
    border-color: rgba(245, 158, 11, 0.35) !important;
    color: #ffffff !important;
  }
  body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-login-card .ngw-auth-input-wrap .form-control::placeholder {
    color: rgba(212, 212, 216, 0.62) !important;
  }

  /* 4. Crystal Glass OS (Aurora) UI Login Box Styling with Matched Image */
  body[data-ngw-ui="aurora"] .ngw-gl-login-card.ngw-login-card-surface {
    background: rgba(7, 18, 34, 0.62) !important;
    border: 1px solid rgba(125, 211, 252, 0.38) !important;
    backdrop-filter: blur(22px) saturate(185%);
    -webkit-backdrop-filter: blur(22px) saturate(185%);
    box-shadow: 0 28px 64px rgba(2, 8, 23, 0.72), inset 0 1px 0 rgba(255, 255, 255, 0.24) !important;
  }
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-login-card-scrim {
    background: linear-gradient(165deg, rgba(6, 18, 36, 0.58) 0%, rgba(8, 22, 42, 0.78) 100%);
  }
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-gl-login-brand-name,
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-gl-login-head h1,
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-gl-field label,
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-gl-remember {
    color: #f8fafc !important;
  }
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-gl-input-box input.form-control {
    background: rgba(6, 15, 30, 0.65) !important;
    border-color: rgba(125, 211, 252, 0.32) !important;
    color: #ffffff !important;
  }
  body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-gl-input-box input.form-control::placeholder {
    color: rgba(186, 230, 253, 0.62) !important;
  }

  /* Shared Round Medallion Badge & Mobile Centered Round Logo Above Inputs */
  .ngw-round-medallion {
    border-radius: 50% !important;
    object-fit: cover !important;
    background: #ffffff !important;
    padding: 2px !important;
    display: block;
    box-sizing: border-box !important;
    flex-shrink: 0;
  }
  .ngw-classic-desk-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 4px 8px 16px;
  }
  .ngw-classic-desk-brand img {
    width: 68px;
    height: 68px;
    border: 2px solid rgba(56, 189, 248, 0.65);
    box-shadow: 0 8px 22px rgba(2, 6, 23, 0.45);
  }
  .ngw-classic-desk-text {
    text-align: left;
  }
  .ngw-classic-desk-title {
    font-size: 17px;
    font-weight: 800;
    color: #ffffff;
    line-height: 1.2;
  }
  .ngw-classic-desk-sub {
    font-size: 12px;
    color: #93c5fd;
    margin-top: 2px;
  }
  .ngw-login-mob-brand-block {
    display: none;
    flex-direction: column;
    align-items: center;
    text-align: center;
    margin-bottom: 16px;
    position: relative;
    padding-top: 24px;
  }
  .ngw-login-mob-title-corner {
    position: absolute;
    top: -2px;
    left: 0;
    font-size: 15px;
    font-weight: 800;
    color: #ffffff;
    text-align: left;
  }
  .ngw-login-mob-brand-block img.ngw-round-medallion {
    width: 86px !important;
    height: 86px !important;
    border: 2.5px solid rgba(56, 189, 248, 0.75);
    box-shadow: 0 12px 28px rgba(2, 6, 23, 0.55);
    margin: 8px auto 10px;
  }
  .ngw-login-mob-company-name {
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: -0.01em;
    margin-bottom: 2px;
    line-height: 1.2;
  }
  .ngw-login-mob-company-sub {
    font-size: 13px;
    font-weight: 600;
    color: #93c5fd;
  }

  @media screen and (max-width: 767px) {
    body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface {
      grid-template-columns: 1fr !important;
      max-width: 400px !important;
    }
    body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-brand {
      display: none !important;
    }
    body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-form-pane {
      padding: 20px 18px 22px !important;
    }
    body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-login-mob-brand-block {
      display: flex !important;
    }
    body[data-ngw-ui="modern"] .ngw-auth-frame.ngw-login-card-surface .ngw-auth-top {
      display: none !important;
    }
  }

  @media screen and (max-width: 640px) {
    .ngw-classic-desk-brand {
      display: none !important;
    }
    .ngw-classic-login-card .card-header h3 {
      display: none !important;
    }
    .ngw-classic-login-card .ngw-login-mob-brand-block {
      display: flex !important;
    }
    body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-banner {
      display: none !important;
    }
    body[data-ngw-ui="modern"][data-ngw-variant="compact"] .ngw-nova-body .ngw-login-mob-brand-block {
      display: flex !important;
    }
    body[data-ngw-ui="aurora"] .ngw-gl-card-topbar {
      justify-content: flex-end !important;
    }
    body[data-ngw-ui="aurora"] .ngw-gl-card-topbar .ngw-gl-login-brand {
      display: none !important;
    }
    body[data-ngw-ui="aurora"] .ngw-gl-login-head {
      display: none !important;
    }
    body[data-ngw-ui="aurora"] .ngw-gl-login-card .ngw-login-mob-brand-block {
      display: flex !important;
    }
    body[data-ngw-ui="aurora"] .ngw-gl-login-card {
      padding: 20px 18px 22px !important;
    }
  }
</style>

<div class="ngw-login-bg-stage is-<?= htmlspecialchars($ngwLoginUi, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
  <img class="ngw-login-bg-img" src="<?= htmlspecialchars($ngwLoginBgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="NextGan WiFi Login Wallpaper" referrerpolicy="no-referrer">
  <div class="ngw-login-bg-scrim"></div>
</div>

<?php if ($ngwLoginUi === 'classic') { ?>
<div class="ngw-classic-login-wrap login-box">
  <div class="card ngw-classic-login-card ngw-login-card-surface">
    <div class="ngw-login-card-bg" aria-hidden="true">
      <img src="<?= htmlspecialchars($ngwLoginBgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Classic Login Card Background" referrerpolicy="no-referrer">
      <span class="ngw-login-card-scrim"></span>
    </div>
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
      <h3 style="margin:0;"><?= $_please_login ?></h3>
      <div style="display:inline-flex;gap:4px;">
        <a href="./admin.php?id=login&setlang=en" class="btn <?= !$isBnLoginCard ? 'bg-primary' : 'bg-secondary'; ?>" style="padding:2px 8px;font-size:11px;">EN</a>
        <a href="./admin.php?id=login&setlang=bn" class="btn <?= $isBnLoginCard ? 'bg-primary' : 'bg-secondary'; ?>" style="padding:2px 8px;font-size:11px;">বাংলা</a>
      </div>
    </div>
    <div class="card-body">
      <div class="ngw-classic-desk-brand">
        <img class="ngw-round-medallion" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo">
        <div class="ngw-classic-desk-text">
          <div class="ngw-classic-desk-title">NextGan WiFi</div>
          <div class="ngw-classic-desk-sub"><?= $isBnLoginCard ? 'হটস্পট ও রিসেলার পোর্টাল' : 'Hotspot & Reseller Portal'; ?></div>
        </div>
      </div>
      <div class="ngw-login-mob-brand-block">
        <div class="ngw-login-mob-title-corner"><?= $_please_login ?></div>
        <img class="ngw-round-medallion" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo">
        <div class="ngw-login-mob-company-name"><?= $isBnLoginCard ? 'নেক্সজেন ওয়াইফাই' : 'NextGen WiFi'; ?></div>
        <div class="ngw-login-mob-company-sub"><?= $isBnLoginCard ? 'সলিউশন' : 'Solution'; ?></div>
      </div>
      <center>
        <form autocomplete="off" action="" method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(isset($loginCsrfToken) ? $loginCsrfToken : '', ENT_QUOTES, 'UTF-8'); ?>">
          <table class="table" style="width:92%;margin-bottom:0;">
            <tr>
              <td class="align-middle text-center">
                <input style="width: 100%; font-size: 15px;" class="form-control" type="text" name="user" id="_username" placeholder="<?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?>" required="1" autofocus autocomplete="username">
              </td>
            </tr>
            <tr>
              <td class="align-middle text-center">
                <input style="width: 100%; font-size: 15px;" class="form-control" type="password" name="pass" id="_password" placeholder="<?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?>" required="1" autocomplete="current-password">
              </td>
            </tr>
            <tr>
              <td class="align-middle" style="padding-top:4px;padding-bottom:4px;">
                <label for="ngw_remember_username" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                  <input type="checkbox" id="ngw_remember_username">
                  <span><?= $isBnLoginCard ? 'ইউজারনেম মনে রাখুন' : 'Remember username'; ?></span>
                </label>
              </td>
            </tr>
            <tr>
              <td class="align-middle text-center">
                <input style="width: 100%; margin-top:8px; height: 40px; font-weight: bold; font-size: 16px; border-radius: 8px;" class="btn-login bg-primary pointer" type="submit" name="login" value="<?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login', ENT_QUOTES, 'UTF-8'); ?>">
              </td>
            </tr>
            <?php if (!empty($error)) { ?>
            <tr>
              <td class="align-middle text-center">
                <?= $error; ?>
              </td>
            </tr>
            <?php } ?>
          </table>
        </form>
      </center>
    </div>
  </div>
</div>
<?php } elseif ($ngwLoginUi === 'compact') { ?>
<div class="ngw-nova-login-wrap login-box" style="width:100%; max-width:none; margin:0;">
  <div class="ngw-nova-login-card ngw-login-card-surface">
    <div class="ngw-login-card-bg" aria-hidden="true">
      <img src="<?= htmlspecialchars($ngwLoginBgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Nova Command Login Card Background" referrerpolicy="no-referrer">
      <span class="ngw-login-card-scrim"></span>
    </div>
    <div class="ngw-nova-banner">
      <div style="display:flex;align-items:center;gap:12px;min-width:0;">
        <img class="ngw-round-medallion ngw-nova-desk-logo" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo" style="width:52px;height:52px;border:2px solid var(--ngw-accent);box-shadow:0 4px 14px rgba(0,0,0,0.4);">
        <div style="min-width:0;">
          <div style="font-family:var(--ngw-font-mono);font-size:13.5px;font-weight:800;letter-spacing:0.06em;color:#f8fafc;">NEXTGAN WIFI</div>
          <div style="font-size:11px;color:#fbbf24;font-weight:600;">Command Portal</div>
        </div>
      </div>
      <div class="ngw-auth-lang-seg">
        <a href="./admin.php?id=login&setlang=en" class="<?= !$isBnLoginCard ? 'is-active' : ''; ?>">EN</a>
        <a href="./admin.php?id=login&setlang=bn" class="<?= $isBnLoginCard ? 'is-active' : ''; ?>">বাংলা</a>
      </div>
    </div>
    <div class="ngw-nova-body">
      <div class="ngw-login-mob-brand-block">
        <div class="ngw-login-mob-title-corner"><?= $_please_login ?></div>
        <img class="ngw-round-medallion" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo">
        <div class="ngw-login-mob-company-name"><?= $isBnLoginCard ? 'নেক্সজেন ওয়াইফাই' : 'NextGen WiFi'; ?></div>
        <div class="ngw-login-mob-company-sub"><?= $isBnLoginCard ? 'সলিউশন' : 'Solution'; ?></div>
      </div>
      <h2 style="margin:0 0 18px;font-size:18px;font-weight:800;"><?= $_please_login ?></h2>
      <form autocomplete="off" action="" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(isset($loginCsrfToken) ? $loginCsrfToken : '', ENT_QUOTES, 'UTF-8'); ?>">
        <div class="ngw-auth-field">
          <label class="ngw-auth-label" for="_username"><?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?></label>
          <div class="ngw-auth-input-wrap">
            <i class="fa fa-user ngw-auth-input-icon" aria-hidden="true"></i>
            <input class="form-control" type="text" name="user" id="_username" placeholder="<?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?>" required="1" autofocus autocomplete="username">
          </div>
        </div>
        <div class="ngw-auth-field">
          <label class="ngw-auth-label" for="_password"><?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?></label>
          <div class="ngw-auth-input-wrap">
            <i class="fa fa-lock ngw-auth-input-icon" aria-hidden="true"></i>
            <input class="form-control" type="password" name="pass" id="_password" placeholder="<?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?>" required="1" autocomplete="current-password">
            <button type="button" class="ngw-auth-eye" id="ngw_toggle_login_pass" aria-label="Toggle password visibility">
              <i class="fa fa-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <label class="ngw-auth-remember" for="ngw_remember_username">
          <input type="checkbox" id="ngw_remember_username" style="width:16px;height:16px;">
          <span><?= $isBnLoginCard ? 'ইউজারনেম মনে রাখুন' : 'Remember username'; ?></span>
        </label>
        <input class="btn-login bg-primary pointer ngw-auth-submit" type="submit" name="login" value="<?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login', ENT_QUOTES, 'UTF-8'); ?>">
        <?php if (!empty($error)) { ?>
        <div class="ngw-auth-error"><?= $error; ?></div>
        <?php } ?>
      </form>
    </div>
  </div>
</div>
<?php } elseif ($ngwLoginUi === 'aurora') { ?>
<div class="ngw-gl-login-stage login-box">
  <main class="ngw-gl-login-card ngw-login-card-surface">
    <div class="ngw-login-card-bg" aria-hidden="true">
      <img src="<?= htmlspecialchars($ngwLoginBgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Crystal Glass Login Card Background" referrerpolicy="no-referrer">
      <span class="ngw-login-card-scrim"></span>
    </div>
    <div class="ngw-gl-card-topbar">
      <div class="ngw-gl-login-brand">
        <img class="ngw-round-medallion ngw-gl-desk-logo" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo">
        <span class="ngw-gl-login-brand-name">NextGan WiFi</span>
      </div>
      <div class="ngw-gl-lang-pills">
        <a href="./admin.php?id=login&setlang=en" class="<?= !$isBnLoginCard ? 'is-active' : ''; ?>">EN</a>
        <a href="./admin.php?id=login&setlang=bn" class="<?= $isBnLoginCard ? 'is-active' : ''; ?>">বাংলা</a>
      </div>
    </div>

    <div class="ngw-login-mob-brand-block">
      <div class="ngw-login-mob-title-corner"><?= $_please_login ?></div>
      <img class="ngw-round-medallion" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo">
      <div class="ngw-login-mob-company-name"><?= $isBnLoginCard ? 'নেক্সজেন ওয়াইফাই' : 'NextGen WiFi'; ?></div>
      <div class="ngw-login-mob-company-sub"><?= $isBnLoginCard ? 'সলিউশন' : 'Solution'; ?></div>
    </div>

    <div class="ngw-gl-login-head">
      <h1><?= $_please_login ?></h1>
    </div>

    <form autocomplete="off" action="" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(isset($loginCsrfToken) ? $loginCsrfToken : '', ENT_QUOTES, 'UTF-8'); ?>">

      <div class="ngw-gl-field">
        <label for="_username"><?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?></label>
        <div class="ngw-gl-input-box">
          <i class="fa fa-user" aria-hidden="true"></i>
          <input class="form-control" type="text" name="user" id="_username" placeholder="<?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?>" required="1" autofocus autocomplete="username">
        </div>
      </div>

      <div class="ngw-gl-field">
        <label for="_password"><?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?></label>
        <div class="ngw-gl-input-box">
          <i class="fa fa-lock" aria-hidden="true"></i>
          <input class="form-control" type="password" name="pass" id="_password" placeholder="<?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?>" required="1" autocomplete="current-password">
          <button type="button" class="ngw-gl-eye-btn" id="ngw_toggle_login_pass" aria-label="Toggle password visibility">
            <i class="fa fa-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <label class="ngw-gl-remember" for="ngw_remember_username">
        <input type="checkbox" id="ngw_remember_username">
        <span><?= $isBnLoginCard ? 'ইউজারনেম মনে রাখুন' : 'Remember username'; ?></span>
      </label>

      <input class="btn-login bg-primary pointer ngw-gl-submit-btn" type="submit" name="login" value="<?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login', ENT_QUOTES, 'UTF-8'); ?>">

      <?php if (!empty($error)) { ?>
      <div class="ngw-gl-login-error"><?= $error; ?></div>
      <?php } ?>
    </form>
  </main>
</div>
<?php } else { ?>
<div class="ngw-auth-shell login-box" style="width:100%; max-width:none; margin:0;">
  <div class="ngw-auth-frame ngw-login-card-surface">
    <div class="ngw-login-card-bg" aria-hidden="true">
      <img src="<?= htmlspecialchars($ngwLoginBgUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Modern Studio Login Card Background" referrerpolicy="no-referrer">
      <span class="ngw-login-card-scrim"></span>
    </div>
    <div class="ngw-auth-brand">
      <div style="display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px;">
        <img class="ngw-round-medallion" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo" style="width:108px;height:108px;border:3px solid rgba(56,189,248,0.65);box-shadow:0 14px 34px rgba(2,6,23,0.55);">
        <div>
          <div style="font-size:21px;font-weight:800;letter-spacing:-0.02em;color:#ffffff;">NextGan WiFi</div>
          <div style="font-size:12.5px;color:#93c5fd;margin-top:4px;font-weight:500;">
            <?= $isBnLoginCard ? 'স্মার্ট মাইক্রোটিক হটস্পট ও ভাউচার সলিউশন' : 'Smart MikroTik Hotspot &amp; Voucher Solution'; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="ngw-auth-form-pane">
      <div class="ngw-auth-top">
        <h2 style="margin:0;"><?= $_please_login ?></h2>
        <div class="ngw-auth-lang-seg">
          <a href="./admin.php?id=login&setlang=en" class="<?= !$isBnLoginCard ? 'is-active' : ''; ?>">EN</a>
          <a href="./admin.php?id=login&setlang=bn" class="<?= $isBnLoginCard ? 'is-active' : ''; ?>">বাংলা</a>
        </div>
      </div>

      <div class="ngw-login-mob-brand-block">
        <div class="ngw-login-mob-title-corner"><?= $_please_login ?></div>
        <img class="ngw-round-medallion" src="img/ngw-login-round.png?v=20261125g" alt="NextGan WiFi Solution Logo">
        <div class="ngw-login-mob-company-name"><?= $isBnLoginCard ? 'নেক্সজেন ওয়াইফাই' : 'NextGen WiFi'; ?></div>
        <div class="ngw-login-mob-company-sub"><?= $isBnLoginCard ? 'সলিউশন' : 'Solution'; ?></div>
      </div>

      <form autocomplete="off" action="" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(isset($loginCsrfToken) ? $loginCsrfToken : '', ENT_QUOTES, 'UTF-8'); ?>">

        <div class="ngw-auth-field">
          <label class="ngw-auth-label" for="_username"><?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?></label>
          <div class="ngw-auth-input-wrap">
            <i class="fa fa-user ngw-auth-input-icon" aria-hidden="true"></i>
            <input class="form-control" type="text" name="user" id="_username" placeholder="<?= htmlspecialchars(isset($_user_name) ? $_user_name : 'Username', ENT_QUOTES, 'UTF-8'); ?>" required="1" autofocus autocomplete="username">
          </div>
        </div>

        <div class="ngw-auth-field">
          <label class="ngw-auth-label" for="_password"><?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?></label>
          <div class="ngw-auth-input-wrap">
            <i class="fa fa-lock ngw-auth-input-icon" aria-hidden="true"></i>
            <input class="form-control" type="password" name="pass" id="_password" placeholder="<?= htmlspecialchars(isset($_password) ? $_password : 'Password', ENT_QUOTES, 'UTF-8'); ?>" required="1" autocomplete="current-password">
            <button type="button" class="ngw-auth-eye" id="ngw_toggle_login_pass" aria-label="Toggle password visibility">
              <i class="fa fa-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <label class="ngw-auth-remember" for="ngw_remember_username">
          <input type="checkbox" id="ngw_remember_username" style="width:16px;height:16px;">
          <span><?= $isBnLoginCard ? 'ইউজারনেম মনে রাখুন' : 'Remember username'; ?></span>
        </label>

        <input class="btn-login bg-primary pointer ngw-auth-submit" type="submit" name="login" value="<?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login', ENT_QUOTES, 'UTF-8'); ?>">

        <?php if (!empty($error)) { ?>
        <div class="ngw-auth-error"><?= $error; ?></div>
        <?php } ?>
      </form>
    </div>
  </div>
</div>
<?php } ?>

<script>
try {
  if (document.body) {
    document.body.setAttribute('data-ngw-page', 'login');
    document.body.setAttribute('data-ngw-lang', <?= $isBnLoginCard ? "'bn'" : "'en'"; ?>);
  }
} catch (e) {}
(function () {
  var form = document.querySelector('.login-box form');
  var username = document.getElementById('_username');
  var passInput = document.getElementById('_password');
  var toggleBtn = document.getElementById('ngw_toggle_login_pass');
  var remember = document.getElementById('ngw_remember_username');
  var storageKey = 'ngw_remembered_username';
  if (toggleBtn && passInput) {
    toggleBtn.addEventListener('click', function () {
      var isPwd = passInput.type === 'password';
      passInput.type = isPwd ? 'text' : 'password';
      var ic = toggleBtn.querySelector('i');
      if (ic) {
        ic.classList.toggle('fa-eye', !isPwd);
        ic.classList.toggle('fa-eye-slash', isPwd);
      }
    });
  }
  if (!form || !username || !remember) return;
  try {
    var saved = localStorage.getItem(storageKey);
    if (saved) { username.value = saved; remember.checked = true; }
  } catch (e) {}
  form.addEventListener('submit', function () {
    try {
      if (remember.checked) localStorage.setItem(storageKey, username.value.trim());
      else localStorage.removeItem(storageKey);
    } catch (e) {}
  });
})();
</script>

</body>
</html>
