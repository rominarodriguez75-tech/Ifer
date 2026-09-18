<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

define('DATA_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
$db = new PDO('sqlite:' . DATA_DIR . DIRECTORY_SEPARATOR . 'appointments.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$adminPassword = getenv('IFER_ADMIN_PASSWORD') ?: '';

function reply($ok, $message = '', $data = []) { echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data)); exit; }
function requireAdmin() { if (empty($_SESSION['ifer_admin'])) reply(false, 'Sesión no autorizada.'); }
function setupTables(PDO $db) {
    $db->exec('CREATE TABLE IF NOT EXISTS specialties (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS doctors (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS doctor_specialties (doctor_id TEXT NOT NULL, specialty_id INTEGER NOT NULL, PRIMARY KEY (doctor_id, specialty_id))');
    $db->exec('CREATE TABLE IF NOT EXISTS doctor_schedules (doctor_id TEXT NOT NULL, weekday INTEGER NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL, slot_minutes INTEGER NOT NULL DEFAULT 30, active INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (doctor_id, weekday, start_time))');
}
setupTables($db);
$action = $_GET['action'] ?? '';
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($adminPassword === '' || !hash_equals($adminPassword, (string) ($_POST['password'] ?? ''))) reply(false, 'Configurá IFER_ADMIN_PASSWORD en el servidor antes de ingresar.');
    $_SESSION['ifer_admin'] = true;
    reply(true, 'Sesión iniciada.');
}
if ($action === 'logout') { session_destroy(); reply(true); }
requireAdmin();
if ($action === 'data') {
    $specialties = $db->query('SELECT id, name, active FROM specialties ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $doctors = $db->query('SELECT id, name, active FROM doctors ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $links = $db->query('SELECT doctor_id, specialty_id FROM doctor_specialties')->fetchAll(PDO::FETCH_ASSOC);
    $schedules = $db->query('SELECT doctor_id, weekday, start_time, end_time, slot_minutes, active FROM doctor_schedules ORDER BY doctor_id, weekday, start_time')->fetchAll(PDO::FETCH_ASSOC);
    reply(true, '', compact('specialties', 'doctors', 'links', 'schedules'));
}
if ($action === 'save-specialty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') reply(false, 'La especialidad es obligatoria.');
    $db->prepare('INSERT INTO specialties (name) VALUES (?) ON CONFLICT(name) DO UPDATE SET active = 1')->execute([$name]);
    reply(true, 'Especialidad guardada.');
}
if ($action === 'save-doctor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['id'] ?? '')));
    $name = trim($_POST['name'] ?? '');
    $specialtyId = (int) ($_POST['specialty_id'] ?? 0);
    if ($id === '' || $name === '' || $specialtyId < 1) reply(false, 'Completá médico y especialidad.');
    $db->prepare('INSERT INTO doctors (id, name) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET name = ?, active = 1')->execute([$id, $name, $name]);
    $db->prepare('INSERT OR IGNORE INTO doctor_specialties (doctor_id, specialty_id) VALUES (?, ?)')->execute([$id, $specialtyId]);
    reply(true, 'Médico guardado.');
}
if ($action === 'save-schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctorId = trim($_POST['doctor_id'] ?? ''); $weekday = (int) ($_POST['weekday'] ?? 0);
    $start = trim($_POST['start_time'] ?? ''); $end = trim($_POST['end_time'] ?? ''); $minutes = max(5, (int) ($_POST['slot_minutes'] ?? 30));
    if ($doctorId === '' || $weekday < 1 || $weekday > 7 || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) reply(false, 'Revisá día y horario.');
    $db->prepare('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, slot_minutes) VALUES (?, ?, ?, ?, ?) ON CONFLICT(doctor_id, weekday, start_time) DO UPDATE SET end_time = ?, slot_minutes = ?, active = 1')->execute([$doctorId, $weekday, $start, $end, $minutes, $end, $minutes]);
    reply(true, 'Horario guardado.');
}
reply(false, 'Acción no válida.');
