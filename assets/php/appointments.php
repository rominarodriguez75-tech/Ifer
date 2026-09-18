<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/GoogleCalendar.php';
$config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'turnos.php';
$calendar = new GoogleCalendar($config);

$dataDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
$db = new PDO('sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'appointments.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS appointments (id INTEGER PRIMARY KEY AUTOINCREMENT, doctor TEXT NOT NULL, appointment_date TEXT NOT NULL, appointment_time TEXT NOT NULL, patient_name TEXT NOT NULL, patient_email TEXT NOT NULL, patient_phone TEXT NOT NULL, token TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT "pending", google_event_id TEXT, created_at TEXT NOT NULL)');
$columns = $db->query('PRAGMA table_info(appointments)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('google_event_id', $columns, true)) $db->exec('ALTER TABLE appointments ADD COLUMN google_event_id TEXT');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS appointments_active_slot ON appointments (doctor, appointment_date, appointment_time) WHERE status <> "cancelled"');
$db->exec('CREATE TABLE IF NOT EXISTS specialties (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE IF NOT EXISTS doctors (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE IF NOT EXISTS doctor_specialties (doctor_id TEXT NOT NULL, specialty_id INTEGER NOT NULL, PRIMARY KEY (doctor_id, specialty_id))');
$db->exec('CREATE TABLE IF NOT EXISTS doctor_schedules (doctor_id TEXT NOT NULL, weekday INTEGER NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL, slot_minutes INTEGER NOT NULL DEFAULT 30, active INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (doctor_id, weekday, start_time))');

$defaultSpecialty = 'Reproducción asistida';
$db->prepare('INSERT OR IGNORE INTO specialties (name) VALUES (?)')->execute([$defaultSpecialty]);
$defaultDoctors = [
    'young' => 'Dr. Edgardo Young', 'viglierchio' => 'Dra. M. Inés Viglierchio',
    'sicaro' => 'Dra. Laura Sícaro', 'vilela' => 'Dr. Martin Vilela',
    'pablo' => 'Dra. Florencia Pablo', 'lorenzo' => 'Dr. Fabián Lorenzo',
    'auge' => 'Dr. Luís M. Augé'
];
foreach ($defaultDoctors as $id => $name) {
    $db->prepare('INSERT OR IGNORE INTO doctors (id, name) VALUES (?, ?)')->execute([$id, $name]);
    $specialtyId = $db->query('SELECT id FROM specialties WHERE name = ' . $db->quote($defaultSpecialty))->fetchColumn();
    $db->prepare('INSERT OR IGNORE INTO doctor_specialties (doctor_id, specialty_id) VALUES (?, ?)')->execute([$id, $specialtyId]);
    for ($weekday = 1; $weekday <= 5; $weekday++) {
        $db->prepare('INSERT OR IGNORE INTO doctor_schedules (doctor_id, weekday, start_time, end_time, slot_minutes) VALUES (?, ?, ?, ?, ?)')->execute([$id, $weekday, '08:00', '18:00', 30]);
    }
}

function response($ok, $message, $extra = []) { echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra)); exit; }
function validDate($date) { $parsed = DateTime::createFromFormat('Y-m-d', $date); return $parsed && $parsed->format('Y-m-d') === $date; }
function availableSlots(PDO $db, $doctor, $date) {
    $weekday = (int) date('N', strtotime($date));
    $query = $db->prepare('SELECT start_time, end_time, slot_minutes FROM doctor_schedules WHERE doctor_id = ? AND weekday = ? AND active = 1 ORDER BY start_time');
    $query->execute([$doctor, $weekday]);
    $slots = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $schedule) {
        $start = new DateTime($date . ' ' . $schedule['start_time']);
        $end = new DateTime($date . ' ' . $schedule['end_time']);
        $minutes = max(5, (int) $schedule['slot_minutes']);
        while ($start < $end) {
            $slots[] = $start->format('H:i');
            $start->modify('+' . $minutes . ' minutes');
        }
    }
    return $slots;
}

$action = $_GET['action'] ?? '';
if ($action === 'catalog') {
    $specialties = $db->query('SELECT id, name FROM specialties WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $doctorsQuery = $db->query('SELECT d.id, d.name, GROUP_CONCAT(ds.specialty_id) AS specialty_ids FROM doctors d LEFT JOIN doctor_specialties ds ON ds.doctor_id = d.id WHERE d.active = 1 GROUP BY d.id, d.name ORDER BY d.name');
    $doctors = array_map(function ($doctor) {
        $doctor['specialty_ids'] = $doctor['specialty_ids'] ? array_map('intval', explode(',', $doctor['specialty_ids'])) : [];
        return $doctor;
    }, $doctorsQuery->fetchAll(PDO::FETCH_ASSOC));
    response(true, '', ['specialties' => $specialties, 'doctors' => $doctors]);
}
if ($action === 'slots') {
    $doctor = $_GET['doctor'] ?? ''; $date = $_GET['date'] ?? '';
    $doctorExists = $db->prepare('SELECT COUNT(*) FROM doctors WHERE id = ? AND active = 1');
    $doctorExists->execute([$doctor]);
    if (!(int) $doctorExists->fetchColumn() || !validDate($date) || $date < date('Y-m-d')) response(false, 'Datos de fecha o profesional inválidos.', ['slots' => []]);
    $query = $db->prepare('SELECT appointment_time FROM appointments WHERE doctor = ? AND appointment_date = ? AND status <> "cancelled"');
    $query->execute([$doctor, $date]);
    $taken = array_flip($query->fetchAll(PDO::FETCH_COLUMN));
    response(true, '', ['slots' => array_values(array_filter(availableSlots($db, $doctor, $date), function ($slot) use ($taken) { return !isset($taken[$slot]); }))]);
}

if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor = trim($_POST['doctor'] ?? ''); $date = trim($_POST['date'] ?? ''); $time = trim($_POST['time'] ?? '');
    $name = trim($_POST['patient_name'] ?? ''); $email = trim($_POST['patient_email'] ?? ''); $phone = trim($_POST['patient_phone'] ?? '');
    $doctorQuery = $db->prepare('SELECT name FROM doctors WHERE id = ? AND active = 1');
    $doctorQuery->execute([$doctor]);
    $doctorName = $doctorQuery->fetchColumn();
    if (!$doctorName || !validDate($date) || $date < date('Y-m-d') || !in_array($time, availableSlots($db, $doctor, $date), true) || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') response(false, 'Completá todos los datos correctamente.');
    try {
        $token = bin2hex(random_bytes(24));
        $query = $db->prepare('INSERT INTO appointments (doctor, appointment_date, appointment_time, patient_name, patient_email, patient_phone, token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$doctor, $date, $time, $name, $email, $phone, $token, date('c')]);
    } catch (PDOException $error) {
        if ((int) $error->errorInfo[1] === 19) response(false, 'Ese horario acaba de ser reservado. Elegí otro.');
        response(false, 'No se pudo guardar el turno.');
    }
    response(true, 'Recibimos tu solicitud. Te enviaremos la confirmación al celular.', ['token' => $token, 'calendar_synced' => false]);
}

if (($action === 'confirm' || $action === 'cancel') && isset($_GET['token'])) {
    $status = $action === 'confirm' ? 'confirmed' : 'cancelled';
    $query = $db->prepare('SELECT id, doctor, appointment_date, appointment_time, patient_name, patient_email, patient_phone, google_event_id, status FROM appointments WHERE token = ?');
    $query->execute([$_GET['token']]);
    $appointment = $query->fetch(PDO::FETCH_ASSOC);
    if (!$appointment || $appointment['status'] === 'cancelled') response(false, 'El enlace no es válido o el turno ya fue cancelado.');
    if ($appointment['status'] === $status) response(true, $status === 'confirmed' ? 'Turno ya confirmado.' : 'Turno ya cancelado.');
    if ($status === 'confirmed') {
        $doctorQuery = $db->prepare('SELECT name FROM doctors WHERE id = ?');
        $doctorQuery->execute([$appointment['doctor']]);
        $doctorName = $doctorQuery->fetchColumn();
        $eventId = $calendar->createAppointment(array_merge($appointment, ['status' => 'confirmed']), $doctorName ?: $appointment['doctor']);
        if (!$eventId) response(false, 'No se pudo sincronizar el turno con Google Calendar. Intentá nuevamente.');
        $query = $db->prepare('UPDATE appointments SET status = ?, google_event_id = ? WHERE id = ? AND status = "pending"');
        $query->execute([$status, $eventId, $appointment['id']]);
    } else {
        $query = $db->prepare('UPDATE appointments SET status = ? WHERE id = ? AND status IN ("pending", "confirmed")');
        $query->execute([$status, $appointment['id']]);
        $calendar->updateAppointment($appointment['google_event_id'], $status);
    }
    response(true, $status === 'confirmed' ? 'Turno confirmado.' : 'Turno cancelado.');
}
response(false, 'Acción no válida.');
