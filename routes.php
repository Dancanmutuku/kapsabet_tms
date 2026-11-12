<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL']);
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        set_fare($pdo, (int)$_POST['route'], (float)$_POST['amount'], $_POST['from'], user()['id']);
        $msg = 'New fare saved. The previous fare is kept in the history.';
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$routes = q($pdo, "SELECT r.id, r.distance_km, CONCAT(o.stage_name,' to ',d.stage_name) AS name FROM route r JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id")->fetchAll();
$hist = q($pdo, "SELECT f.*, CONCAT(o.stage_name,' to ',d.stage_name) AS name, u.full_name FROM fare f JOIN route r ON r.id=f.route_id JOIN stage o ON o.id=r.origin_stage_id JOIN stage d ON d.id=r.dest_stage_id LEFT JOIN app_user u ON u.id=f.approved_by ORDER BY f.route_id, f.effective_from DESC")->fetchAll();
$title = 'Routes and Fares'; include 'includes/header.php'; ?>
<h1>Routes and fares</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<table><thead><tr><th>Route</th><th>Distance (km)</th><th>Current fare (KES)</th></tr></thead><tbody>
<?php foreach ($routes as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['distance_km']) ?></td><td><?= number_format((float)current_fare($pdo, (int)$r['id']), 2) ?></td></tr><?php endforeach; ?></tbody></table>
<h2>Set a new fare</h2>
<form method="post" data-confirm="Save this fare?"><?= csrf_field() ?>
<label>Route</label><select name="route"><?php foreach ($routes as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
<label>New fare (KES)</label><input type="number" step="0.01" min="1" name="amount" required>
<label>Effective from</label><input type="date" name="from" required><button>Save fare</button></form>
<h2>Fare history</h2>
<table><thead><tr><th>Route</th><th>Amount</th><th>From</th><th>To</th><th>Approved by</th></tr></thead><tbody>
<?php foreach ($hist as $h): ?><tr><td><?= e($h['name']) ?></td><td><?= e($h['amount']) ?></td><td><?= e($h['effective_from']) ?></td><td><?= e($h['effective_to'] ?? 'current') ?></td><td><?= e($h['full_name'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table>
<?php include 'includes/footer.php';
