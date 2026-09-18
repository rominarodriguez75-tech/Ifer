<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/GoogleCalendar.php';
$config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'turnos.php';
$calendar = new GoogleCalendar($config);

$doctors = [
    'young' => 'Dr. Edgardo Young',
    'viglierchio' => 'Dra. M. Inés Viglierchio',
    'sicaro' => 'Dra. Laura Sícaro',
    'vilela' => 'Dr. Martin Vilela',
    'pablo' => 'Dra. Florencia Pablo',
    'lorenzo' => 'Dr. Fabián Lorenzo',
    'auge' => 'Dr. Luís M. Augé'
];
$dataDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
$db = new PDO('sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'appointments.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS appointments (id INTEGER PRIMARY KEY AUTOINCREMENT, doctor TEXT NOT NULL, appointment_date TEXT NOT NULL, appointment_time TEXT NOT NULL, patient_name TEXT NOT NULL, patient_email TEXT NOT NULL, patient_phone TEXT NOT NULL, token TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT "pending", google_event_id TEXT, created_at TEXT NOT NULL)');
$columns = $db->query('PRAGMA table_info(appointments)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('google_event_id', $columns, true)) $db->exec('ALTER TABLE appointments ADD COLUMN google_event_id TEXT');

function response($ok, $message, $extra = []) { echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra)); exit; }
function validDate($date) { $parsed = DateTime::createFromFormat('Y-m-d', $date); return $parsed && $parsed->format('Y-m-d') === $date; }
function availableSlots($date) {
    $day = (int) date('N', strtotime($date));
    if ($day > 5) return [];
    $slots = [];
    for ($hour = 8; $hour < 18; $hour++) { $slots[] = sprintf('%02d:00', $hour); $slots[] = sprintf('%02d:30', $hour); }
    return $slots;
}

$action = $_GET['action'] ?? '';
if ($action === 'slots') {
    $doctor = $_GET['doctor'] ?? ''; $date = $_GET['date'] ?? '';
    if (!isset($doctors[$doctor]) || !validDate($date) || $date < date('Y-m-d')) response(false, 'Datos de fecha o profesional inválidos.', ['slots' => []]);
    $query = $db->prepare('SELECT appointment_time FROM appointments WHERE doctor = ? AND appointment_date = ? AND status <> "cancelled"');
    $query->execute([$doctor, $date]);
    $taken = array_flip($query->fetchAll(PDO::FETCH_COLUMN));
    response(true, '', ['slots' => array_values(array_filter(availableSlots($date), function ($slot) use ($taken) { return !isset($taken[$slot]); }))]);
}

if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor = trim($_POST['doctor'] ?? ''); $date = trim($_POST['date'] ?? ''); $time = trim($_POST['time'] ?? '');
    $name = trim($_POST['patient_name'] ?? ''); $email = trim($_POST['patient_email'] ?? ''); $phone = trim($_POST['patient_phone'] ?? '');
    if (!isset($doctors[$doctor]) || !validDate($date) || $date < date('Y-m-d') || !in_array($time, availableSlots($date), true) || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') response(false, 'Completá todos los datos correctamente.');
    try {
        $token = bin2hex(random_bytes(24));
        $query = $db->prepare('INSERT INTO appointments (doctor, appointment_date, appointment_time, patient_name, patient_email, patient_phone, token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$doctor, $date, $time, $name, $email, $phone, $token, date('c')]);
    } catch (PDOException $error) {
        if ((int) $error->errorInfo[1] === 19) response(false, 'Ese horario acaba de ser reservado. Elegí otro.');
        response(false, 'No se pudo guardar el turno.');
    }
    $appointment = ['appointment_date' => $date, 'appointment_time' => $time, 'patient_name' => $name, 'patient_email' => $email, 'patient_phone' => $phone, 'status' => 'pending'];
    $eventId = $calendar->createAppointment($appointment, $doctors[$doctor]);
    if ($eventId) {
        $update = $db->prepare('UPDATE appointments SET google_event_id = ? WHERE token = ?');
        $update->execute([$eventId, $token]);
    }
    response(true, 'Recibimos tu solicitud. Te enviaremos la confirmación al celular.', ['token' => $token, 'calendar_synced' => (bool) $eventId]);
}

if (($action === 'confirm' || $action === 'cancel') && isset($_GET['token'])) {
    $status = $action === 'confirm' ? 'confirmed' : 'cancelled';
    $query = $db->prepare('SELECT id, google_event_id FROM appointments WHERE token = ? AND status <> "cancelled"');
    $query->execute([$_GET['token']]);
    $appointment = $query->fetch(PDO::FETCH_ASSOC);
    if (!$appointment) response(false, 'El enlace no es válido o el turno ya fue cancelado.');
    $query = $db->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $query->execute([$status, $appointment['id']]);
    $calendar->updateAppointment($appointment['google_event_id'], $status);
    response(true, $status === 'confirmed' ? 'Turno confirmado.' : 'Turno cancelado.');
}
response(false, 'Acción no válida.');
