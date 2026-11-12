<?php
require_once 'includes/auth.php';
$pdo = db();
$routes = q($pdo, "SELECT r.id, CONCAT(o.stage_name,' to ',d.stage_name) AS name FROM route r JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id")->fetchAll();
$rid = (int)($_GET['route'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');
$sql = "SELECT t.id, t.scheduled_departure, t.status, v.reg_no, v.seat_capacity, t.route_id, CONCAT(o.stage_name,' to ',d.stage_name) AS rname
        FROM trip t JOIN vehicle v ON v.id=t.vehicle_id JOIN route r ON r.id=t.route_id
        JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id
        WHERE t.status IN ('LOADING','SCHEDULED') AND DATE(t.scheduled_departure)=?" . ($rid ? " AND t.route_id=?" : "") . " ORDER BY t.scheduled_departure";
$trips = q($pdo, $sql, $rid ? [$date, $rid] : [$date])->fetchAll();
$title = 'Departures'; include 'includes/header.php'; ?>
<h1>Departures and seat availability</h1>
<form method="get" class="row">
<div><label>Direction</label><select name="route"><option value="0">All</option>
<?php foreach ($routes as $r): ?><option value="<?= $r['id'] ?>" <?= $rid == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Date</label><input type="date" name="date" value="<?= e($date) ?>"></div></form>
<script>document.querySelectorAll('form select, form input[type=date]').forEach(function(x){x.addEventListener('change',function(){x.form.submit();});});</script>
<table id="dep"><thead><tr><th>Time</th><th>Route</th><th>Vehicle</th><th>Fare (KES)</th><th>Seats left</th></tr></thead><tbody>
<?php foreach ($trips as $t): $left = seats_left($pdo, (int)$t['id']); ?>
<tr><td><?= e(date('H:i', strtotime($t['scheduled_departure']))) ?></td><td><?= e($t['rname']) ?></td><td><?= e($t['reg_no']) ?></td>
<td><?= number_format((float)current_fare($pdo, (int)$t['route_id'], $date), 2) ?></td><td><?= $left > 0 ? $left : 'Full' ?></td></tr>
<?php endforeach; if (!$trips): ?><tr><td colspan="5">No departures found for the chosen filters.</td></tr><?php endif; ?></tbody></table>
<p>Seat reservation and M-Pesa payment online are described in the design and are not part of this prototype. Seats are recorded by the conductor at boarding.</p>
<?php include 'includes/footer.php';
