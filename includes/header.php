<?php require_once __DIR__ . '/auth.php'; ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Kapsabet Express TMS') ?></title><link rel="stylesheet" href="assets/style.css"></head>
<body><header><strong>Kapsabet Express TMS</strong>
<nav><a href="index.php">Departures</a><a href="feedback.php">Feedback</a>
<?php if (user()): ?>
<?php if (has_role('ADMIN','OFFICIAL')): ?><a href="vehicles.php">Vehicles</a><a href="routes.php">Routes and Fares</a><a href="complaints.php">Complaints</a><?php endif; ?>
<?php if (has_role('ADMIN','OFFICIAL','CLERK','CONDUCTOR')): ?><a href="trips.php">Trips</a><?php endif; ?>
<a href="logout.php">Log out (<?= e(user()['username']) ?>)</a>
<?php else: ?><a href="login.php">Staff login</a><?php endif; ?></nav></header><main>
