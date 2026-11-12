<?php
// Core business functions. Each takes a PDO so they can be unit and integration tested.
function db(): PDO {
    static $p;
    if (!$p) {
        $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q(PDO $pdo, string $sql, array $a = []): PDOStatement { $s = $pdo->prepare($sql); $s->execute($a); return $s; }

function normalize_reg(string $r): string { return strtoupper(preg_replace('/\s+/', ' ', trim($r))); }
function valid_reg_no(string $r): bool { return (bool)preg_match('/^K[A-Z]{2} \d{3}[A-Z]$/', normalize_reg($r)); }
function normalize_phone(string $p): ?string {
    $p = preg_replace('/[\s-]/', '', $p);
    if (preg_match('/^07\d{8}$/', $p)) return '+254' . substr($p, 1);
    if (preg_match('/^\+2547\d{8}$/', $p)) return $p;
    return null;
}
function audit(PDO $pdo, ?int $uid, string $action, string $table, int $rid, $old = null, $new = null): void {
    q($pdo, "INSERT INTO audit_log (user_id,action,table_name,record_id,old_values,new_values) VALUES (?,?,?,?,?,?)",
      [$uid, $action, $table, $rid, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new)]);
}

// Fare management (FR-26, FR-47)
function current_fare(PDO $pdo, int $route_id, ?string $date = null): ?float {
    $date = $date ?: date('Y-m-d');
    $r = q($pdo, "SELECT amount FROM fare WHERE route_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1",
           [$route_id, $date, $date])->fetch();
    return $r ? (float)$r['amount'] : null;
}
function set_fare(PDO $pdo, int $route_id, float $amount, string $from, int $uid): void {
    if ($amount <= 0) throw new InvalidArgumentException('Fare must be greater than zero');
    $cur = q($pdo, "SELECT id, effective_from FROM fare WHERE route_id=? AND effective_to IS NULL ORDER BY effective_from DESC LIMIT 1", [$route_id])->fetch();
    if ($cur && $from <= $cur['effective_from']) throw new InvalidArgumentException('New fare must start after the current fare start date');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($cur) q($pdo, "UPDATE fare SET effective_to=DATE_SUB(?, INTERVAL 1 DAY) WHERE id=?", [$from, $cur['id']]);
        q($pdo, "INSERT INTO fare (route_id,amount,effective_from,approved_by) VALUES (?,?,?,?)", [$route_id, $amount, $from, $uid]);
        audit($pdo, $uid, 'CREATE', 'fare', (int)$pdo->lastInsertId(), null, ['route' => $route_id, 'amount' => $amount, 'from' => $from]);
        if ($own) $pdo->commit();
    } catch (Throwable $t) { if ($own) $pdo->rollBack(); throw $t; }
}

// Vehicle compliance (FR-35): VALID, EXPIRING (30 days), EXPIRED or MISSING
function compliance_status(PDO $pdo, int $vehicle_id, ?string $today = null): string {
    $today = $today ?: date('Y-m-d');
    $rows = q($pdo, "SELECT doc_type, MAX(expiry_date) AS e FROM vehicle_document WHERE vehicle_id=? GROUP BY doc_type", [$vehicle_id])->fetchAll();
    if (count($rows) < 2) return 'MISSING';
    $min = min(array_column($rows, 'e'));
    if ($min < $today) return 'EXPIRED';
    if ($min <= date('Y-m-d', strtotime($today . ' +30 days'))) return 'EXPIRING';
    return 'VALID';
}
function add_vehicle(PDO $pdo, int $owner, string $reg, string $model, int $seats, string $ins, string $insp, int $uid): int {
    if (!valid_reg_no($reg)) throw new InvalidArgumentException('Registration number format is invalid (example KDA 123X)');
    if ($seats < 1 || $seats > 14) throw new InvalidArgumentException('Seat capacity must be between 1 and 14');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        q($pdo, "INSERT INTO vehicle (owner_id,reg_no,make_model,seat_capacity) VALUES (?,?,?,?)", [$owner, normalize_reg($reg), $model, $seats]);
        $id = (int)$pdo->lastInsertId();
        q($pdo, "INSERT INTO vehicle_document (vehicle_id,doc_type,expiry_date) VALUES (?,?,?),(?,?,?)", [$id, 'INSURANCE', $ins, $id, 'INSPECTION', $insp]);
        audit($pdo, $uid, 'CREATE', 'vehicle', $id, null, ['reg' => normalize_reg($reg)]);
        if ($own) $pdo->commit();
        return $id;
    } catch (PDOException $x) {
        if ($own) $pdo->rollBack();
        if ($x->getCode() == 23000) throw new RuntimeException('Registration number already exists');
        throw $x;
    }
}

// Queue and dispatch (FR-07 to FR-11)
function register_queue(PDO $pdo, int $vehicle_id, int $stage_id, int $uid): int {
    $v = q($pdo, "SELECT status FROM vehicle WHERE id=?", [$vehicle_id])->fetch();
    if (!$v || $v['status'] !== 'ACTIVE') throw new RuntimeException('Vehicle is not active');
    $c = compliance_status($pdo, $vehicle_id);
    if ($c === 'EXPIRED' || $c === 'MISSING') throw new RuntimeException('Compliance documents are ' . strtolower($c));
    $pos = (int)q($pdo, "SELECT COALESCE(MAX(queue_position),0)+1 AS p FROM queue_entry WHERE stage_id=? AND queue_date=CURDATE()", [$stage_id])->fetch()['p'];
    try {
        q($pdo, "INSERT INTO queue_entry (vehicle_id,stage_id,registered_by,queue_date,queue_position) VALUES (?,?,?,CURDATE(),?)", [$vehicle_id, $stage_id, $uid, $pos]);
    } catch (PDOException $x) {
        if ($x->getCode() == 23000) throw new RuntimeException('Vehicle is already in the queue');
        throw $x;
    }
    $id = (int)$pdo->lastInsertId();
    audit($pdo, $uid, 'CREATE', 'queue_entry', $id, null, ['position' => $pos]);
    return $id;
}
function open_trip(PDO $pdo, int $queue_id, int $driver, int $conductor, int $route_id, string $sched, int $uid): int {
    $qe = q($pdo, "SELECT * FROM queue_entry WHERE id=? AND status='WAITING'", [$queue_id])->fetch();
    if (!$qe) throw new RuntimeException('Queue entry is not waiting');
    $front = q($pdo, "SELECT MIN(queue_position) AS m FROM queue_entry WHERE stage_id=? AND queue_date=? AND status='WAITING'", [$qe['stage_id'], $qe['queue_date']])->fetch()['m'];
    if ((int)$front !== (int)$qe['queue_position']) throw new RuntimeException('Only the vehicle at the front of the queue can open a trip');
    q($pdo, "INSERT INTO trip (queue_id,vehicle_id,route_id,driver_id,conductor_id,scheduled_departure,status) VALUES (?,?,?,?,?,?, 'LOADING')",
      [$queue_id, $qe['vehicle_id'], $route_id, $driver, $conductor, $sched]);
    $id = (int)$pdo->lastInsertId();
    audit($pdo, $uid, 'CREATE', 'trip', $id, null, ['queue' => $queue_id]);
    return $id;
}
function confirm_departure(PDO $pdo, int $trip_id, float $fee, int $uid): void {
    $t = q($pdo, "SELECT * FROM trip WHERE id=? AND status='LOADING'", [$trip_id])->fetch();
    if (!$t) throw new RuntimeException('Trip is not loading');
    if ($fee < 0) throw new InvalidArgumentException('Stage fee cannot be negative');
    q($pdo, "UPDATE trip SET status='DEPARTED', departure_time=NOW() WHERE id=?", [$trip_id]);
    q($pdo, "UPDATE queue_entry SET status='DISPATCHED', stage_fee=? WHERE id=?", [$fee, $t['queue_id']]);
    audit($pdo, $uid, 'UPDATE', 'trip', $trip_id, ['status' => 'LOADING'], ['status' => 'DEPARTED']);
}

// Boarding and payment (FR-14 to FR-16, FR-26, FR-29, FR-33)
function seats_left(PDO $pdo, int $trip_id): int {
    $r = q($pdo, "SELECT v.seat_capacity - (SELECT COUNT(*) FROM booking b WHERE b.trip_id=t.id AND b.status IN ('HELD','CONFIRMED','BOARDED')) AS s
                  FROM trip t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.id=?", [$trip_id])->fetch();
    return $r ? (int)$r['s'] : 0;
}
function add_boarding(PDO $pdo, int $trip_id, int $seat, string $method, int $uid, ?string $mpesa = null, ?string $uuid = null): array {
    $uuid = $uuid ?: bin2hex(random_bytes(16));
    if (q($pdo, "SELECT id FROM booking WHERE client_uuid=?", [$uuid])->fetch()) return ['duplicate' => true];
    $t = q($pdo, "SELECT t.*, v.seat_capacity FROM trip t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.id=?", [$trip_id])->fetch();
    if (!$t || $t['status'] !== 'LOADING') throw new RuntimeException('Trip is not open for boarding');
    if (seats_left($pdo, $trip_id) <= 0) {
        q($pdo, "INSERT INTO incident (vehicle_id,trip_id,reported_by,incident_type,description) VALUES (?,?,?,'OVERLOAD','Boarding beyond seat capacity blocked')", [$t['vehicle_id'], $trip_id, $uid]);
        throw new RuntimeException('Vehicle is full. Attempt logged as a possible overloading event');
    }
    if ($seat < 1 || $seat > (int)$t['seat_capacity']) throw new InvalidArgumentException('Invalid seat number');
    if (!in_array($method, ['MPESA', 'CASH'], true)) throw new InvalidArgumentException('Invalid payment method');
    if ($method === 'MPESA') {
        $mpesa = strtoupper(trim((string)$mpesa));
        if (!preg_match('/^[A-Z0-9]{10}$/', $mpesa)) throw new InvalidArgumentException('M-Pesa code must be 10 letters or digits');
        if (q($pdo, "SELECT id FROM payment WHERE mpesa_receipt_no=?", [$mpesa])->fetch()) throw new RuntimeException('This M-Pesa code has already been used');
    } else { $mpesa = null; }
    $fare = current_fare($pdo, (int)$t['route_id']);
    if ($fare === null) throw new RuntimeException('No approved fare for this route');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        q($pdo, "INSERT INTO booking (trip_id,seat_no,channel,fare_amount,status,created_by,client_uuid) VALUES (?,?,'CONDUCTOR',?,'BOARDED',?,?)", [$trip_id, $seat, $fare, $uid, $uuid]);
        $bid = (int)$pdo->lastInsertId();
        $rc = 'RC' . date('ymd') . str_pad((string)$bid, 6, '0', STR_PAD_LEFT);
        q($pdo, "INSERT INTO payment (trip_id,booking_id,amount,method,mpesa_receipt_no,receipt_no,recorded_by) VALUES (?,?,?,?,?,?,?)", [$trip_id, $bid, $fare, $method, $mpesa, $rc, $uid]);
        audit($pdo, $uid, 'CREATE', 'booking', $bid, null, ['seat' => $seat, 'fare' => $fare, 'method' => $method]);
        if ($own) $pdo->commit();
        return ['booking_id' => $bid, 'receipt_no' => $rc, 'fare' => $fare];
    } catch (PDOException $x) {
        if ($own) $pdo->rollBack();
        if ($x->getCode() == 23000) throw new RuntimeException('Seat is already taken or the entry is a duplicate');
        throw $x;
    }
}

// Reconciliation (FR-17)
function reconcile(float $expected, float $recorded, float $cash_recorded, float $counted_cash): string {
    return (abs($expected - $recorded) < 0.005 && abs($cash_recorded - $counted_cash) < 0.005) ? 'RECONCILED' : 'MISMATCH';
}
function close_trip(PDO $pdo, int $trip_id, float $counted_cash, int $uid): string {
    $t = q($pdo, "SELECT * FROM trip WHERE id=? AND status='DEPARTED'", [$trip_id])->fetch();
    if (!$t) throw new RuntimeException('Only a departed trip can be closed');
    $b = q($pdo, "SELECT COUNT(*) n, COALESCE(SUM(fare_amount),0) f FROM booking WHERE trip_id=? AND status='BOARDED'", [$trip_id])->fetch();
    $p = q($pdo, "SELECT COALESCE(SUM(amount),0) a, COALESCE(SUM(CASE WHEN method='CASH' THEN amount END),0) c FROM payment WHERE trip_id=? AND status='SUCCESS'", [$trip_id])->fetch();
    $st = reconcile((float)$b['f'], (float)$p['a'], (float)$p['c'], $counted_cash);
    q($pdo, "UPDATE trip SET status='CLOSED', arrival_time=NOW(), expected_revenue=?, counted_cash=?, recon_status=? WHERE id=?", [$b['f'], $counted_cash, $st, $trip_id]);
    audit($pdo, $uid, 'UPDATE', 'trip', $trip_id, ['status' => 'DEPARTED'], ['status' => 'CLOSED', 'recon' => $st]);
    return $st;
}

// Feedback (FR-44)
function submit_feedback(PDO $pdo, ?int $trip_id, ?string $reg, string $cat, ?int $rating, string $msg, ?string $phone, bool $consent): int {
    $vid = null;
    if ($reg) {
        $v = q($pdo, "SELECT id FROM vehicle WHERE reg_no=?", [normalize_reg($reg)])->fetch();
        if (!$v) throw new InvalidArgumentException('Vehicle registration number not found');
        $vid = (int)$v['id'];
    }
    if (!$trip_id && !$vid) throw new InvalidArgumentException('Give a trip number or a vehicle registration number');
    if (trim($msg) === '') throw new InvalidArgumentException('Message is required');
    if (!in_array($cat, ['COMPLIMENT', 'COMPLAINT', 'SUGGESTION'], true)) throw new InvalidArgumentException('Invalid category');
    if ($rating !== null && ($rating < 1 || $rating > 5)) throw new InvalidArgumentException('Rating must be 1 to 5');
    $pid = null;
    if ($phone !== null && trim($phone) !== '') {
        $n = normalize_phone($phone);
        if (!$n) throw new InvalidArgumentException('Phone number is invalid');
        if (!$consent) throw new InvalidArgumentException('Consent is required before a phone number is stored');
        q($pdo, "INSERT INTO passenger (phone_no,consent_given) VALUES (?,TRUE) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", [$n]);
        $pid = (int)$pdo->lastInsertId();
    }
    q($pdo, "INSERT INTO feedback (trip_id,vehicle_id,passenger_id,category,rating,message) VALUES (?,?,?,?,?,?)", [$trip_id ?: null, $vid, $pid, $cat, $rating, trim($msg)]);
    return (int)$pdo->lastInsertId();
}
