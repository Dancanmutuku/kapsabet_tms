<?php
// Run from the project folder:  C:\xampp\php\php.exe tests\run_tests.php
// Uses the real database inside a transaction that is rolled back, so no data is left behind.
require_once __DIR__ . '/../config.php';
$pdo = db(); $pass = 0; $fail = 0;
function check(string $name, bool $ok): void { global $pass, $fail; echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; $ok ? $pass++ : $fail++; }
function throws(callable $f): bool { try { $f(); return false; } catch (Throwable $t) { return true; } }

echo "UNIT TESTS (no database)\n";
check('valid_reg_no accepts KDA 123X', valid_reg_no('kda 123x'));
check('valid_reg_no rejects 12ABC', !valid_reg_no('12ABC'));
check('normalize_phone 07 format', normalize_phone('0712 345 678') === '+254712345678');
check('normalize_phone rejects short number', normalize_phone('0712') === null);
check('reconcile matching totals', reconcile(500, 500, 250, 250) === 'RECONCILED');
check('reconcile cash short', reconcile(500, 500, 250, 200) === 'MISMATCH');
check('reconcile recorded below expected', reconcile(500, 250, 250, 250) === 'MISMATCH');

$pdo->beginTransaction();
$uid = (int)q($pdo, "SELECT id FROM app_user WHERE username='admin'")->fetch()['id'];
$owner = (int)q($pdo, "SELECT id FROM app_user WHERE username='owner'")->fetch()['id'];
$crew = q($pdo, "SELECT id, crew_type FROM crew_member")->fetchAll(PDO::FETCH_KEY_PAIR);
$drv = array_search('DRIVER', $crew); $cnd = array_search('CONDUCTOR', $crew);
$sfx = strtoupper(substr(md5((string)microtime(true)), 0, 1));

echo "\nUNIT TESTS (database functions)\n";
check('current_fare returns seeded 250', current_fare($pdo, 1) === 250.0);
set_fare($pdo, 1, 300, date('Y-m-d', strtotime('+10 days')), $uid);
check('old fare stays effective before new date', current_fare($pdo, 1) === 250.0);
check('new fare effective on its date', current_fare($pdo, 1, date('Y-m-d', strtotime('+10 days'))) === 300.0);
check('set_fare rejects earlier start date', throws(fn() => set_fare($pdo, 1, 310, '2020-01-01', $uid)));
check('set_fare rejects zero amount', throws(fn() => set_fare($pdo, 1, 0, '2030-01-01', $uid)));
$v1 = add_vehicle($pdo, $owner, "KZ{$sfx} 111T", 'Test', 14, date('Y-m-d', strtotime('+90 days')), date('Y-m-d', strtotime('+90 days')), $uid);
check('compliance VALID', compliance_status($pdo, $v1) === 'VALID');
$v2 = add_vehicle($pdo, $owner, "KZ{$sfx} 222T", 'Test', 14, date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('+90 days')), $uid);
check('compliance EXPIRING', compliance_status($pdo, $v2) === 'EXPIRING');
$v3 = add_vehicle($pdo, $owner, "KZ{$sfx} 333T", 'Test', 14, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+90 days')), $uid);
check('compliance EXPIRED', compliance_status($pdo, $v3) === 'EXPIRED');
check('add_vehicle rejects duplicate registration', throws(fn() => add_vehicle($pdo, $owner, "KZ{$sfx} 111T", 'T', 14, '2030-01-01', '2030-01-01', $uid)));
check('add_vehicle rejects bad registration', throws(fn() => add_vehicle($pdo, $owner, 'BAD', 'T', 14, '2030-01-01', '2030-01-01', $uid)));
check('add_vehicle rejects 20 seats', throws(fn() => add_vehicle($pdo, $owner, "KZ{$sfx} 444T", 'T', 20, '2030-01-01', '2030-01-01', $uid)));

echo "\nINTEGRATION TESTS (queue, trip, boarding, payment, closure)\n";
check('expired vehicle cannot join queue', throws(fn() => register_queue($pdo, $v3, 1, $uid)));
$q1 = register_queue($pdo, $v1, 1, $uid); $q2 = register_queue($pdo, $v2, 1, $uid);
check('queue positions increase', (int)q($pdo, "SELECT queue_position FROM queue_entry WHERE id=?", [$q2])->fetch()['queue_position'] > (int)q($pdo, "SELECT queue_position FROM queue_entry WHERE id=?", [$q1])->fetch()['queue_position']);
check('vehicle cannot be queued twice', throws(fn() => register_queue($pdo, $v1, 1, $uid)));
check('second vehicle cannot open trip first', throws(fn() => open_trip($pdo, $q2, $drv, $cnd, 1, date('Y-m-d H:i:s'), $uid)));
$trip = open_trip($pdo, $q1, $drv, $cnd, 1, date('Y-m-d H:i:s'), $uid);
check('open trip makes 14 seats available', seats_left($pdo, $trip) === 14);
$b1 = add_boarding($pdo, $trip, 1, 'CASH', $uid, null, 'uuid-test-0000000000000000000001');
check('fare comes from fare table (250)', $b1['fare'] === 250.0);
$dup = add_boarding($pdo, $trip, 1, 'CASH', $uid, null, 'uuid-test-0000000000000000000001');
check('offline resend with same client uuid is ignored', !empty($dup['duplicate']) && seats_left($pdo, $trip) === 13);
check('same seat cannot be booked twice', throws(fn() => add_boarding($pdo, $trip, 1, 'CASH', $uid)));
add_boarding($pdo, $trip, 2, 'MPESA', $uid, 'QWE1234567');
check('duplicate M-Pesa code rejected', throws(fn() => add_boarding($pdo, $trip, 3, 'MPESA', $uid, 'QWE1234567')));
check('invalid M-Pesa code rejected', throws(fn() => add_boarding($pdo, $trip, 3, 'MPESA', $uid, 'abc')));
for ($s = 3; $s <= 14; $s++) add_boarding($pdo, $trip, $s, 'CASH', $uid);
check('vehicle full after 14 passengers', seats_left($pdo, $trip) === 0);
check('15th passenger blocked', throws(fn() => add_boarding($pdo, $trip, 1, 'CASH', $uid)));
check('overload attempt logged as incident', (int)q($pdo, "SELECT COUNT(*) c FROM incident WHERE trip_id=?", [$trip])->fetch()['c'] === 1);
check('cannot close a trip before departure', throws(fn() => close_trip($pdo, $trip, 3250, $uid)));
confirm_departure($pdo, $trip, 100, $uid);
check('departure removes vehicle from queue', q($pdo, "SELECT status FROM queue_entry WHERE id=?", [$q1])->fetch()['status'] === 'DISPATCHED');
check('boarding blocked after departure', throws(fn() => add_boarding($pdo, $trip, 1, 'CASH', $uid)));
check('wrong cash count gives MISMATCH', close_trip($pdo, $trip, 3000, $uid) === 'MISMATCH');
$q3 = register_queue($pdo, $v1, 2, $uid);
$t2 = open_trip($pdo, $q3, $drv, $cnd, 2, date('Y-m-d H:i:s'), $uid);
add_boarding($pdo, $t2, 5, 'CASH', $uid); confirm_departure($pdo, $t2, 100, $uid);
check('correct cash count gives RECONCILED', close_trip($pdo, $t2, 250, $uid) === 'RECONCILED');
check('audit log recorded actions', (int)q($pdo, "SELECT COUNT(*) c FROM audit_log WHERE user_id=?", [$uid])->fetch()['c'] > 10);
$fid = submit_feedback($pdo, null, "KZ{$sfx} 111T", 'COMPLAINT', 2, 'Overcharged', '0722000000', true);
check('feedback saved against vehicle', $fid > 0);
check('phone without consent rejected', throws(fn() => submit_feedback($pdo, null, "KZ{$sfx} 111T", 'COMPLAINT', 2, 'x', '0722000001', false)));
check('feedback without trip or vehicle rejected', throws(fn() => submit_feedback($pdo, null, null, 'COMPLAINT', 2, 'x', null, false)));
$pdo->rollBack();
echo "\nResult: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
