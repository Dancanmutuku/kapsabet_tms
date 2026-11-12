<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL', 'CLERK', 'CONDUCTOR']);
$pdo = db(); $msg = $err = ''; $uid = user()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'queue' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { register_queue($pdo, (int)$_POST['vehicle'], (int)$_POST['stage'], $uid); $msg = 'Vehicle added to the queue.'; }
        elseif ($a === 'open' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { open_trip($pdo, (int)$_POST['queue'], (int)$_POST['driver'], (int)$_POST['conductor'], (int)$_POST['route'], $_POST['sched'], $uid); $msg = 'Trip opened.'; }
        elseif ($a === 'depart' && has_role('ADMIN', 'OFFICIAL', 'CLERK')) { confirm_departure($pdo, (int)$_POST['trip'], (float)$_POST['fee'], $uid); $msg = 'Departure confirmed.'; }
        elseif ($a === 'board' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')) { $r = add_boarding($pdo, (int)$_POST['trip'], (int)$_POST['seat'], $_POST['method'], $uid, $_POST['mpesa'] ?? null); $msg = 'Passenger recorded. Receipt ' . $r['receipt_no'] . ', fare KES ' . number_format($r['fare'], 2); }
        elseif ($a === 'close' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')) { $s = close_trip($pdo, (int)$_POST['trip'], (float)$_POST['cash'], $uid); $msg = 'Trip closed. Reconciliation result: ' . $s; }
        else { throw new RuntimeException('Action not permitted for your role.'); }
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$stages = q($pdo, "SELECT * FROM stage")->fetchAll();
$vehicles = q($pdo, "SELECT id, reg_no FROM vehicle WHERE status='ACTIVE' ORDER BY reg_no")->fetchAll();
$routes = q($pdo, "SELECT r.id, CONCAT(o.stage_name,' to ',d.stage_name) AS name FROM route r JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id")->fetchAll();
$crew = q($pdo, "SELECT c.id, c.crew_type, u.full_name FROM crew_member c JOIN app_user u ON u.id=c.user_id")->fetchAll();
$queue = q($pdo, "SELECT qe.*, v.reg_no, s.stage_name FROM queue_entry qe JOIN vehicle v ON v.id=qe.vehicle_id JOIN stage s ON s.id=qe.stage_id WHERE qe.queue_date=CURDATE() AND qe.status='WAITING' ORDER BY qe.stage_id, qe.queue_position")->fetchAll();
$trips = q($pdo, "SELECT t.*, v.reg_no FROM trip t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.status IN ('LOADING','DEPARTED') ORDER BY t.id DESC")->fetchAll();
$title = 'Trips'; include 'includes/header.php'; ?>
<h1>Queue, trips and boarding</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<?php if (has_role('ADMIN', 'OFFICIAL', 'CLERK')): ?>
<h2>Queue register (today)</h2>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="queue">
<div><label>Vehicle</label><select name="vehicle"><?php foreach ($vehicles as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['reg_no']) ?></option><?php endforeach; ?></select></div>
<div><label>Stage</label><select name="stage"><?php foreach ($stages as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['stage_name']) ?></option><?php endforeach; ?></select></div>
<div><button>Register arrival</button></div></form>
<table><thead><tr><th>Stage</th><th>Position</th><th>Vehicle</th><th>Queued at</th><th>Open trip</th></tr></thead><tbody>
<?php foreach ($queue as $x): ?><tr><td><?= e($x['stage_name']) ?></td><td><?= (int)$x['queue_position'] ?></td><td><?= e($x['reg_no']) ?></td><td><?= e($x['queued_at']) ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="queue" value="<?= $x['id'] ?>">
<select name="driver"><?php foreach ($crew as $c) if ($c['crew_type'] === 'DRIVER'): ?><option value="<?= $c['id'] ?>"><?= e($c['full_name']) ?></option><?php endif; ?></select>
<select name="conductor"><?php foreach ($crew as $c) if ($c['crew_type'] === 'CONDUCTOR'): ?><option value="<?= $c['id'] ?>"><?= e($c['full_name']) ?></option><?php endif; ?></select>
<select name="route"><?php foreach ($routes as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
<input type="datetime-local" name="sched" value="<?= date('Y-m-d\TH:i') ?>" required><button>Open trip</button></form></td></tr>
<?php endforeach; if (!$queue): ?><tr><td colspan="5">Queue is empty.</td></tr><?php endif; ?></tbody></table>
<?php endif; ?>
<h2>Open trips</h2>
<?php foreach ($trips as $t): $taken = q($pdo, "SELECT seat_no FROM booking WHERE trip_id=? AND status IN ('HELD','CONFIRMED','BOARDED')", [$t['id']])->fetchAll(PDO::FETCH_COLUMN); ?>
<div style="border:1px solid #888;padding:8px;margin:8px 0">
<strong>Trip <?= $t['id'] ?>: <?= e($t['reg_no']) ?></strong> (<?= e($t['status']) ?>), seats left <?= seats_left($pdo, (int)$t['id']) ?>
<div class="seats"><?php for ($i = 1; $i <= 14; $i++): ?><span class="<?= in_array($i, $taken) ? 'taken' : '' ?>"><?= $i ?></span><?php endfor; ?></div>
<?php if ($t['status'] === 'LOADING' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="board"><input type="hidden" name="trip" value="<?= $t['id'] ?>">
<label>Seat</label><input type="number" name="seat" min="1" max="14" required>
<label>Payment method (fare comes from the fare table)</label><select name="method" id="method"><option>CASH</option><option>MPESA</option></select>
<div id="codebox"><label>M-Pesa code</label><input name="mpesa" maxlength="10"></div><button>Add passenger</button></form>
<?php endif; ?>
<?php if ($t['status'] === 'LOADING' && has_role('ADMIN', 'OFFICIAL', 'CLERK')): ?>
<form method="post" data-confirm="Confirm departure?"><?= csrf_field() ?><input type="hidden" name="action" value="depart"><input type="hidden" name="trip" value="<?= $t['id'] ?>">
<label>Stage fee (KES)</label><input type="number" step="0.01" min="0" name="fee" required><button>Confirm departure</button></form>
<?php endif; ?>
<?php if ($t['status'] === 'DEPARTED' && has_role('ADMIN', 'OFFICIAL', 'CONDUCTOR')): ?>
<form method="post" data-confirm="Close this trip?"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="trip" value="<?= $t['id'] ?>">
<label>Cash counted (KES)</label><input type="number" step="0.01" min="0" name="cash" required><button>Close trip</button></form>
<?php endif; ?></div>
<?php endforeach; if (!$trips): ?><p>No open trips.</p><?php endif; ?>
<?php include 'includes/footer.php';
