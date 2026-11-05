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

<div style="padding-top: 5%;"  class="login-box">
  <div class="card">
    <div class="card-header">
      <h3><?= $_please_login ?></h3>
    </div>
    <div class="card-body">
      <div class="text-center pd-5">
        <img src="img/favicon.png?v=nextgan" alt="NextGan WiFi Solution Logo" style="width:96px; height:96px; border-radius:50%; object-fit:cover;">
      </div>
      <div class="text-center">
      <span style="font-size: 23px; margin: 10px; font-weight: bold;">NextGan WiFi Solution</span>
      </div>
      <center>
      <form autocomplete="off" action="" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(isset($loginCsrfToken) ? $loginCsrfToken : '', ENT_QUOTES, 'UTF-8'); ?>">
      <table class="table" style="width:90%">
        <tr>
          <td class="align-middle text-center">
            <input style="width: 100%; height: 35px; font-size: 16px;" class="form-control" type="text" name="user" id="_username" placeholder="Username" required="1" autofocus autocomplete="username">
          </td>
        </tr>
        <tr>
          <td class="align-middle text-center">
            <input style="width: 100%; height: 35px; font-size: 16px;" class="form-control" type="password" name="pass" id="_password" placeholder="Password" required="1" autocomplete="current-password">
          </td>
        </tr>
        <tr>
          <td class="align-middle" style="text-align:left; padding:8px 5%;">
            <label for="ngw_remember_username" style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
              <input type="checkbox" id="ngw_remember_username" style="width:16px;height:16px;">
              <span>Remember username</span>
            </label>
          </td>
        </tr>
        <tr>
          <td class="align-middle text-center">
            <input style="width: 100%; margin-top:20px; height: 35px; font-weight: bold; font-size: 17px;" class="btn-login bg-primary pointer" type="submit" name="login" value="Login">
          </td>
        </tr>
        <tr>
          <td class="align-middle text-center">
            <?= $error; ?>
          </td>
        </tr>
      </table>
      </form>
      <script>
      (function () {
        var form = document.querySelector('.login-box form');
        var username = document.getElementById('_username');
        var remember = document.getElementById('ngw_remember_username');
        var storageKey = 'ngw_remembered_username';
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
      </center>
    </div>
  </div>
</div>

</body>
</html>
