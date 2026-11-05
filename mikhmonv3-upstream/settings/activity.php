<?php
session_start();
error_reporting(0);
require_once __DIR__ . '/../include/security.php';
if (empty($_SESSION['mikhmon'])) {
    header('Location:../admin.php?id=login');
    exit;
}
if (!isset($_SESSION['mikhmon_role']) || $_SESSION['mikhmon_role'] !== 'admin') {
    http_response_code(403);
    echo '<div class="card"><div class="card-body">Access denied.</div></div>';
    exit;
}
$events = ngwReadActivityLog(100);
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-history"></i> Activity History <small style="font-size:12px;">Last 100 events</small>
          <a class="btn bg-primary" style="float:right;" href="./admin.php?id=activity"><i class="fa fa-refresh"></i></a>
        </h3>
      </div>
      <div class="card-body">
        <div class="overflow" style="max-height:70vh;">
          <table class="table table-sm table-bordered table-hover">
            <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Activity</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$events) { ?><tr><td colspan="5" class="text-center">No activity recorded yet.</td></tr><?php } ?>
            <?php foreach ($events as $event) {
              $details = array();
              if (!empty($event['details']) && is_array($event['details'])) {
                foreach ($event['details'] as $key => $value) $details[] = ucfirst($key) . ': ' . $value;
              }
            ?>
              <tr>
                <td><?= htmlspecialchars($event['time'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($event['actor'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars(ucfirst($event['role']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $event['action']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars(implode(' · ', $details), ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>