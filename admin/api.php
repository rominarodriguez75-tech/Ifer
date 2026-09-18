<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

define('DATA_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
$db = new PDO('sqlite:' . DATA_DIR . DIRECTORY_SEPARATOR . 'appointments.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$adminPassword = getenv('IFER_ADMIN_PASSWORD') ?: '';

function reply($ok, $message = '', $data = []) { echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data)); exit; }
function currentUser() { return $_SESSION['ifer_user'] ?? null; }
function requireUser() { if (!currentUser()) reply(false, 'Sesión no autorizada.'); }
function requireAdmin() { requireUser(); if (currentUser()['role'] !== 'admin') reply(false, 'Se requieren permisos de administrador.'); }
function audit(PDO $db, $action) {
    $user = currentUser();
    $query = $db->prepare('INSERT INTO admin_audit (user_id, action, created_at) VALUES (?, ?, ?)');
    $query->execute([$user['id'] ?? null, $action, date('c')]);
}
function setupTables(PDO $db) {
    $db->exec('CREATE TABLE IF NOT EXISTS specialties (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS doctors (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS doctor_specialties (doctor_id TEXT NOT NULL, specialty_id INTEGER NOT NULL, PRIMARY KEY (doctor_id, specialty_id))');
    $db->exec('CREATE TABLE IF NOT EXISTS doctor_schedules (doctor_id TEXT NOT NULL, weekday INTEGER NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL, slot_minutes INTEGER NOT NULL DEFAULT 30, active INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (doctor_id, weekday, start_time))');
    $db->exec('CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "operator", active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS admin_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, created_at TEXT NOT NULL)');
}
setupTables($db);
$userCount = (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($userCount === 0 && $adminPassword !== '') {
    $query = $db->prepare('INSERT INTO admin_users (username, display_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
    $query->execute(['admin', 'Administrador principal', password_hash($adminPassword, PASSWORD_DEFAULT), 'admin', date('c')]);
}
$action = $_GET['action'] ?? '';
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $query = $db->prepare('SELECT id, username, display_name, password_hash, role FROM admin_users WHERE username = ? AND active = 1');
    $query->execute([$username]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) reply(false, 'Usuario o contraseña incorrectos.');
    session_regenerate_id(true);
    unset($user['password_hash']);
    $_SESSION['ifer_user'] = $user;
    reply(true, 'Sesión iniciada.');
}
if ($action === 'logout') { session_destroy(); reply(true); }
requireUser();
if ($action === 'data') {
    $specialties = $db->query('SELECT id, name, active FROM specialties ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $doctors = $db->query('SELECT id, name, active FROM doctors ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $links = $db->query('SELECT doctor_id, specialty_id FROM doctor_specialties')->fetchAll(PDO::FETCH_ASSOC);
    $schedules = $db->query('SELECT doctor_id, weekday, start_time, end_time, slot_minutes, active FROM doctor_schedules ORDER BY doctor_id, weekday, start_time')->fetchAll(PDO::FETCH_ASSOC);
    $users = [];
    if (currentUser()['role'] === 'admin') {
        $users = $db->query('SELECT id, username, display_name, role, active, created_at FROM admin_users ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC);
    }
    reply(true, '', ['specialties' => $specialties, 'doctors' => $doctors, 'links' => $links, 'schedules' => $schedules, 'users' => $users, 'current_user' => currentUser()]);
}
if ($action === 'save-specialty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') reply(false, 'La especialidad es obligatoria.');
    $db->prepare('INSERT INTO specialties (name) VALUES (?) ON CONFLICT(name) DO UPDATE SET active = 1')->execute([$name]);
    audit($db, 'Guardó especialidad: ' . $name);
    reply(true, 'Especialidad guardada.');
}
if ($action === 'save-doctor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['id'] ?? '')));
    $name = trim($_POST['name'] ?? '');
    $specialtyId = (int) ($_POST['specialty_id'] ?? 0);
    if ($id === '' || $name === '' || $specialtyId < 1) reply(false, 'Completá médico y especialidad.');
    $db->prepare('INSERT INTO doctors (id, name) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET name = ?, active = 1')->execute([$id, $name, $name]);
    $db->prepare('INSERT OR IGNORE INTO doctor_specialties (doctor_id, specialty_id) VALUES (?, ?)')->execute([$id, $specialtyId]);
    audit($db, 'Guardó médico: ' . $name);
    reply(true, 'Médico guardado.');
}
if ($action === 'save-schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctorId = trim($_POST['doctor_id'] ?? ''); $weekday = (int) ($_POST['weekday'] ?? 0);
    $start = trim($_POST['start_time'] ?? ''); $end = trim($_POST['end_time'] ?? ''); $minutes = max(5, (int) ($_POST['slot_minutes'] ?? 30));
    if ($doctorId === '' || $weekday < 1 || $weekday > 7 || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) reply(false, 'Revisá día y horario.');
    $db->prepare('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, slot_minutes) VALUES (?, ?, ?, ?, ?) ON CONFLICT(doctor_id, weekday, start_time) DO UPDATE SET end_time = ?, slot_minutes = ?, active = 1')->execute([$doctorId, $weekday, $start, $end, $minutes, $end, $minutes]);
    audit($db, 'Guardó horario: ' . $doctorId . ', día ' . $weekday);
    reply(true, 'Horario guardado.');
}
if ($action === 'save-user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $username = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower(trim($_POST['username'] ?? '')));
    $displayName = trim($_POST['display_name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? 'operator') === 'admin' ? 'admin' : 'operator';
    if ($username === '' || $displayName === '' || strlen($password) < 10) reply(false, 'Usá nombre, usuario y una contraseña de al menos 10 caracteres.');
    $query = $db->prepare('INSERT INTO admin_users (username, display_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(username) DO UPDATE SET display_name = ?, password_hash = ?, role = ?, active = 1');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $query->execute([$username, $displayName, $hash, $role, date('c'), $displayName, $hash, $role]);
    audit($db, 'Guardó usuario: ' . $username);
    reply(true, 'Usuario guardado.');
}
if ($action === 'toggle-user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $userId = (int) ($_POST['id'] ?? 0);
    if ($userId === (int) currentUser()['id']) reply(false, 'No podés desactivar tu propio usuario.');
    $query = $db->prepare('UPDATE admin_users SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id = ?');
    $query->execute([$userId]);
    audit($db, 'Cambió estado del usuario: ' . $userId);
    reply(true, 'Estado del usuario actualizado.');
}
reply(false, 'Acción no válida.');
