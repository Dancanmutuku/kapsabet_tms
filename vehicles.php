<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL']);
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        add_vehicle($pdo, (int)$_POST['owner'], $_POST['reg_no'], trim($_POST['model']), (int)$_POST['seats'], $_POST['insurance'], $_POST['inspection'], user()['id']);
        $msg = 'Vehicle saved.';
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$owners = q($pdo, "SELECT u.id, u.full_name FROM app_user u JOIN role r ON r.id=u.role_id WHERE r.role_name='OWNER' AND u.status='ACTIVE'")->fetchAll();
$vehicles = q($pdo, "SELECT v.*, u.full_name AS owner FROM vehicle v JOIN app_user u ON u.id=v.owner_id ORDER BY v.reg_no")->fetchAll();
$title = 'Vehicles'; include 'includes/header.php'; ?>
<h1>Vehicle registration</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<div class="row"><div><form method="post"><?= csrf_field() ?>
<label>Registration number</label><input name="reg_no" data-reg placeholder="KDA 123X" required>
<label>Owner</label><select name="owner" required><?php foreach ($owners as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['full_name']) ?></option><?php endforeach; ?></select>
<label>Make and model</label><input name="model">
<label>Seat capacity (1 to 14)</label><input type="number" name="seats" value="14" min="1" max="14" required>
<label>Insurance expiry</label><input type="date" name="insurance" required>
<label>Inspection expiry</label><input type="date" name="inspection" required>
<button>Save vehicle</button></form></div>
<div><label>Search</label><input data-filter="vtable" placeholder="Registration or owner">
<table id="vtable"><thead><tr><th>Reg no</th><th>Owner</th><th>Seats</th><th>Compliance</th></tr></thead><tbody>
<?php foreach ($vehicles as $v): ?><tr><td><?= e($v['reg_no']) ?></td><td><?= e($v['owner']) ?></td><td><?= (int)$v['seat_capacity'] ?></td><td><?= e(compliance_status($pdo, (int)$v['id'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<p>An expired or missing document blocks queue registration.</p>
<?php include 'includes/footer.php';
