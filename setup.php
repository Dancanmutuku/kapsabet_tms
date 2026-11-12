<?php
// Run once after importing sql/database.sql: http://localhost/kapsabet_tms/setup.php  (delete this file afterwards)
require_once __DIR__ . '/config.php';
$pdo = db();
if (q($pdo, "SELECT COUNT(*) c FROM app_user")->fetch()['c'] > 0) exit('Setup already done. Delete setup.php.');
$role = fn($n) => (int)q($pdo, "SELECT id FROM role WHERE role_name=?", [$n])->fetch()['id'];
$mk = function ($r, $name, $user, $phone) use ($pdo, $role) {
    q($pdo, "INSERT INTO app_user (role_id,full_name,username,phone_no,password_hash) VALUES (?,?,?,?,?)", [$role($r), $name, $user, $phone, password_hash('Pass@1234', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};
$mk('ADMIN', 'System Administrator', 'admin', '+254700000001');
$off = $mk('OFFICIAL', 'J. Kamau', 'official', '+254700000002');
$mk('CLERK', 'Queue Clerk', 'clerk', '+254700000003');
$mk('CONDUCTOR', 'L. Njeri', 'conductor', '+254700000004');
$drv = $mk('DRIVER', 'P. Mutai', 'driver', '+254700000005');
$cnd = (int)q($pdo, "SELECT id FROM app_user WHERE username='conductor'")->fetch()['id'];
$own = $mk('OWNER', 'W. Kiprono', 'owner', '+254700000006');
q($pdo, "INSERT INTO crew_member (user_id,crew_type,licence_no) VALUES (?,'DRIVER','DL-1001'),(?,'CONDUCTOR','PSV-2001')", [$drv, $cnd]);
add_vehicle($pdo, $own, 'KDA 123X', 'Toyota HiAce', 14, date('Y-m-d', strtotime('+6 months')), date('Y-m-d', strtotime('+8 months')), $off);
add_vehicle($pdo, $own, 'KCB 456A', 'Toyota HiAce', 14, date('Y-m-d', strtotime('+3 months')), date('Y-m-d', strtotime('+5 months')), $off);
add_vehicle($pdo, $own, 'KDD 789B', 'Toyota HiAce', 14, date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+5 months')), $off);
echo 'Setup complete. Users: admin, official, clerk, conductor, driver, owner. Password for all: Pass@1234. Delete setup.php now.';
