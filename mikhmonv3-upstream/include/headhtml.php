<?php
/*
 *		Copyright (C) 2018 Laksamadi Guko.
 *
 *		This program is free software; you can redistribute it and/or modify
 *		it under the terms of the GNU General Public License as published by
 *		the Free Software Foundation; either version 2 of the License, or
 *		(at your option) any later version.
 *
 *		This program is distributed in the hope that it will be useful,
 *		but WITHOUT ANY WARRANTY; without even the implied warranty of
 *		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.		See the
 *		GNU General Public License for more details.
 *
 *		You should have received a copy of the GNU General Public License
 *		along with this program.		If not, see <http://www.gnu.org/licenses/>.
 */
require_once __DIR__ . '/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}
 // hide all error
error_reporting(0);
include_once __DIR__ . '/ui_settings.php';
$ngwActiveUi = function_exists('ngwResolveActiveUiStyle') ? ngwResolveActiveUiStyle() : 'classic';
if (!empty($_SESSION['mikhmon']) && function_exists('ngwRememberBrowserUiCookie')) {
	ngwRememberBrowserUiCookie($ngwActiveUi);
}
$ngwIsModernFamily = ($ngwActiveUi === 'modern' || $ngwActiveUi === 'compact');
$ngwIsAuroraUi = ($ngwActiveUi === 'aurora');
?>
<!DOCTYPE html>
<html>
	<head>
		<title>NextGan WiFi Solution <?= $hotspotname; ?></title>
		<meta charset="utf-8">
		<meta http-equiv="cache-control" content="no-store, no-cache, must-revalidate, private" />
		<meta http-equiv="pragma" content="no-cache" />
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<!-- Tell the browser to be responsive to screen width -->
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<!-- Theme color -->
		<meta name="theme-color" content="<?= !empty($themecolor) ? htmlspecialchars((string)$themecolor, ENT_QUOTES, 'UTF-8') : '#03254a'; ?>" />
		<!-- Progressive Web App (PWA) Support -->
		<link rel="manifest" href="manifest.webmanifest" />
		<meta name="mobile-web-app-capable" content="yes" />
		<meta name="apple-mobile-web-app-capable" content="yes" />
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
		<meta name="apple-mobile-web-app-title" content="NextGan WiFi" />
		<link rel="apple-touch-icon" sizes="180x180" href="img/pwa/apple-touch-icon.png?v=20261125e" />
		<?php if (!empty($_SESSION['mikhmon']) && function_exists('ngwGetBrowserUiCookieJs')) { ?>
		<script><?= ngwGetBrowserUiCookieJs($ngwActiveUi); ?></script>
		<?php } ?>
		<?php if ($ngwIsModernFamily) { ?>
		<!-- Typography: Plus Jakarta Sans + Hind Siliguri + JetBrains Mono -->
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
		<?php } elseif ($ngwIsAuroraUi) { ?>
		<!-- Typography: Outfit + Manrope + Hind Siliguri + JetBrains Mono (Crystal Glass OS) -->
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap">
		<?php } ?>
		<!-- Font Awesome -->
		<link rel="stylesheet" type="text/css" href="css/font-awesome/css/font-awesome.min.css" />
		<!-- NextGen UI -->
		<link rel="stylesheet" href="css/mikhmon-ui.<?= $theme; ?>.min.css">
		<!-- Shared theme-aware responsive refinements for VPN and Reseller modules -->
		<link rel="stylesheet" href="css/nextgan-responsive.css?v=20261122j">
		<?php if ($ngwIsModernFamily) { ?>
		<!-- NextGan Modern Studio / Nova Command UI -->
		<link rel="stylesheet" href="css/nextgan-modern-ui.css?v=20261125e">
		<?php } elseif ($ngwIsAuroraUi) { ?>
		<!-- NextGan Crystal Glass OS (4th UI) -->
		<link rel="stylesheet" href="css/nextgan-aurora-ui.css?v=20261125e">
		<?php } ?>
		<!-- favicon & app icons -->
		<link rel="icon" type="image/svg+xml" href="./img/pwa/icon.svg?v=20261125e" />
		<link rel="icon" type="image/png" sizes="192x192" href="./img/pwa/pwa-192x192.png?v=20261125e" />
		<link rel="alternate icon" type="image/png" href="./img/favicon.png?v=20261125e" />
		<!-- jQuery -->
		<script src="js/jquery.min.js"></script>
		<!-- pace -->
		<link href="css/pace.<?= $theme; ?>.css" rel="stylesheet" />
		<script src="js/pace.min.js"></script>
		<!-- NextGan WiFi PWA Runtime -->
		<script defer src="js/ngw-pwa.js?v=20261125h"></script>

		
	</head>
	<?php
	$ngwBodyTheme = (isset($theme) && in_array((string)$theme, array('dark', 'light', 'blue', 'green', 'pink'), true)) ? (string)$theme : 'dark';
	$ngwBodyLang = (isset($langid) && $langid === 'bn') ? 'bn' : 'en';
	?>
	<?php if ($ngwIsAuroraUi) { ?>
	<body data-ngw-ui="aurora" data-ngw-variant="aurora" data-ngw-theme="<?= htmlspecialchars($ngwBodyTheme, ENT_QUOTES, 'UTF-8'); ?>" data-ngw-lang="<?= $ngwBodyLang; ?>">
	<?php } elseif ($ngwIsModernFamily) { ?>
	<body data-ngw-ui="modern" data-ngw-variant="<?= htmlspecialchars($ngwActiveUi, ENT_QUOTES, 'UTF-8'); ?>" data-ngw-theme="<?= htmlspecialchars($ngwBodyTheme, ENT_QUOTES, 'UTF-8'); ?>" data-ngw-lang="<?= $ngwBodyLang; ?>">
	<?php } else { ?>
	<body data-ngw-ui="classic" data-ngw-theme="<?= htmlspecialchars($ngwBodyTheme, ENT_QUOTES, 'UTF-8'); ?>" data-ngw-lang="<?= $ngwBodyLang; ?>">
	<?php } ?>
		<script>try{if(window.navigator.standalone===true||(window.matchMedia&&window.matchMedia('(display-mode:standalone)').matches))document.body.classList.add('ngw-pwa-standalone');}catch(e){}</script>
		<div class="wrapper">

			
