<?php
require_once 'includes/auth.php'; require_role(['ADMIN', 'OFFICIAL']);
$pdo = db(); $msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (!in_array($_POST['status'], ['NEW', 'IN_PROGRESS', 'RESOLVED'], true)) throw new InvalidArgumentException('Invalid status');
        q($pdo, "UPDATE feedback SET status=?, response=?, responded_by=? WHERE id=?", [$_POST['status'], trim($_POST['response']), user()['id'], (int)$_POST['id']]);
        audit($pdo, user()['id'], 'UPDATE', 'feedback', (int)$_POST['id'], null, ['status' => $_POST['status']]);
        $msg = 'Complaint updated.';
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$rows = q($pdo, "SELECT f.*, v.reg_no FROM feedback f LEFT JOIN vehicle v ON v.id=f.vehicle_id ORDER BY f.status='RESOLVED', f.created_at DESC")->fetchAll();
$title = 'Complaints'; include 'includes/header.php'; ?>
<h1>Feedback and complaints</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<table><thead><tr><th>Date</th><th>Vehicle or trip</th><th>Category</th><th>Message</th><th>Handle</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['reg_no'] ?: 'Trip ' . $r['trip_id']) ?></td><td><?= e($r['category']) ?><?= $r['rating'] ? ' (' . (int)$r['rating'] . '/5)' : '' ?></td><td><?= e($r['message']) ?></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
<select name="status"><?php foreach (['NEW', 'IN_PROGRESS', 'RESOLVED'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
<textarea name="response" rows="2" placeholder="Response"><?= e($r['response']) ?></textarea><button>Save</button></form></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="5">No feedback yet.</td></tr>'; ?></tbody></table>
<?php include 'includes/footer.php';
