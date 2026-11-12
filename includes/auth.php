<?php
require_once __DIR__ . '/../config.php';
session_start();
if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 900) { session_unset(); session_destroy(); session_start(); }
$_SESSION['last'] = time();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_role(array $roles): void {
    if (!user()) { header('Location: login.php'); exit; }
    if (!in_array(user()['role'], $roles, true)) { http_response_code(403); exit('Access denied for your role.'); }
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function check_csrf(): void { if (($_POST['csrf'] ?? '') !== $_SESSION['csrf']) { http_response_code(400); exit('Invalid form token.'); } }
function has_role(string ...$r): bool { return user() && in_array(user()['role'], $r, true); }
