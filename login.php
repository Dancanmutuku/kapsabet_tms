<?php
require_once 'includes/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    $u = q($pdo, "SELECT u.*, r.role_name FROM app_user u JOIN role r ON r.id=u.role_id WHERE u.username=? OR u.phone_no=?", [trim($_POST['username']), trim($_POST['username'])])->fetch();
    if (!$u || $u['status'] !== 'ACTIVE') { $err = 'Invalid login details.'; }
    elseif ($u['locked_until'] && strtotime($u['locked_until']) > time()) { $err = 'Account is locked. Try again later.'; }
    elseif (!password_verify($_POST['password'], $u['password_hash'])) {
        $f = $u['failed_logins'] + 1;
        q($pdo, "UPDATE app_user SET failed_logins=?, locked_until=? WHERE id=?", [$f >= 5 ? 0 : $f, $f >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id']]);
        $err = 'Invalid login details.';
    } else {
        q($pdo, "UPDATE app_user SET failed_logins=0, locked_until=NULL, last_login_at=NOW() WHERE id=?", [$u['id']]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['full_name'], 'role' => $u['role_name']];
        audit($pdo, (int)$u['id'], 'LOGIN', 'app_user', (int)$u['id']);
        header('Location: ' . (in_array($u['role_name'], ['CLERK', 'CONDUCTOR']) ? 'trips.php' : 'vehicles.php')); exit;
    }
}
$title = 'Staff login'; include 'includes/header.php'; ?>
<h1>Staff login</h1>
<?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Username or phone number</label><input name="username" required>
<label>Password</label><input type="password" name="password" required>
<button>Log in</button></form>
<p>The account locks for 15 minutes after 5 failed attempts. Sessions end after 15 minutes of inactivity.</p>
<?php include 'includes/footer.php';
