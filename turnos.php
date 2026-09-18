<?php ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Turnos | IFER</title>
  <link rel="icon" href="assets/images/favicon/favicon.png">
  <link rel="stylesheet" href="assets/css/libraries.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .appointment-page { background: #f5f8f9; min-height: 100vh; padding: 80px 0; }
    .appointment-card { background: #fff; box-shadow: 0 12px 36px rgba(20, 64, 78, .12); padding: 36px; }
    .appointment-card h1 { color: #164c5b; }
    .appointment-step { border-left: 3px solid #2c9a9a; padding-left: 18px; margin: 26px 0; }
    .slot-list { display: flex; flex-wrap: wrap; gap: 10px; }
    .slot-list button { border: 1px solid #d7e1e4; background: #fff; color: #164c5b; padding: 10px 16px; cursor: pointer; }
    .slot-list button.selected, .slot-list button:hover { background: #2c9a9a; border-color: #2c9a9a; color: #fff; }
    .slot-list button:disabled { color: #a8b4b8; background: #f1f3f4; cursor: not-allowed; }
    .appointment-message { margin-top: 20px; }
  </style>
</head>
<body>
  <main class="appointment-page">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <section class="appointment-card">
            <a href="index.php" class="btn btn__link mb-20"><i class="icon-arrow-left"></i> Volver a IFER</a>
            <h1>Solicitá tu turno</h1>
            <p>Elegí profesional, día y horario. Recibirás la confirmación y los recordatorios en tu celular.</p>
            <form id="appointmentForm">
              <div class="appointment-step">
                <h4>1. Especialidad y profesional</h4>
                <select class="form-control mb-10" name="specialty" id="specialty" required>
                  <option value="">Seleccioná una especialidad</option>
                </select>
                <select class="form-control" name="doctor" id="doctor" required>
                  <option value="">Seleccioná primero una especialidad</option>
                </select>
              </div>
              <div class="appointment-step">
                <h4>2. Día y horario</h4>
                <input class="form-control mb-20" type="date" name="date" id="date" min="<?= date('Y-m-d') ?>" required>
                <div id="slots" class="slot-list" aria-live="polite"><p>Elegí un profesional y un día.</p></div>
                <input type="hidden" name="time" id="time" required>
              </div>
              <div class="appointment-step">
                <h4>3. Tus datos</h4>
                <input class="form-control mb-10" type="text" name="patient_name" placeholder="Nombre y apellido" required>
                <input class="form-control mb-10" type="email" name="patient_email" placeholder="Email" required>
                <input class="form-control mb-10" type="tel" name="patient_phone" placeholder="Celular con código de país" required>
                <label><input type="checkbox" required> Acepto recibir mensajes relacionados con este turno.</label>
              </div>
              <button class="btn btn__secondary btn__rounded btn__block" type="submit">Reservar turno <i class="icon-arrow-right"></i></button>
            </form>
            <div id="appointmentMessage" class="appointment-message" role="status"></div>
          </section>
        </div>
      </div>
    </div>
  </main>
  <script>
    const form = document.getElementById('appointmentForm');
    const specialty = document.getElementById('specialty');
    const doctor = document.getElementById('doctor');
    const date = document.getElementById('date');
    const slots = document.getElementById('slots');
    const time = document.getElementById('time');
    const message = document.getElementById('appointmentMessage');

    let doctors = [];

    async function loadCatalog() {
      const response = await fetch('assets/php/appointments.php?action=catalog');
      const result = await response.json();
      if (!result.ok) return;
      result.specialties.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.name;
        specialty.appendChild(option);
      });
      doctors = result.doctors;
    }

    function loadDoctors() {
      doctor.innerHTML = '<option value="">Seleccioná un profesional</option>';
      doctors.filter((item) => item.specialty_ids.includes(Number(specialty.value))).forEach((item) => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.name;
        doctor.appendChild(option);
      });
      loadSlots();
    }

    async function loadSlots() {
      time.value = '';
      if (!doctor.value || !date.value) {
        slots.innerHTML = '<p>Elegí un profesional y un día.</p>';
        return;
      }
      slots.innerHTML = '<p>Buscando horarios...</p>';
      const response = await fetch(`assets/php/appointments.php?action=slots&doctor=${encodeURIComponent(doctor.value)}&date=${encodeURIComponent(date.value)}`);
      const result = await response.json();
      slots.innerHTML = '';
      if (!result.ok || !result.slots.length) {
        slots.innerHTML = '<p>No hay horarios disponibles para ese día.</p>';
        return;
      }
      result.slots.forEach((slot) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = slot;
        button.addEventListener('click', () => {
          document.querySelectorAll('#slots button').forEach((item) => item.classList.remove('selected'));
          button.classList.add('selected');
          time.value = slot;
        });
        slots.appendChild(button);
      });
    }

    specialty.addEventListener('change', loadDoctors);
    doctor.addEventListener('change', loadSlots);
    date.addEventListener('change', loadSlots);
    loadCatalog();
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!time.value) {
        message.innerHTML = '<div class="alert alert-warning">Seleccioná un horario.</div>';
        return;
      }
      const response = await fetch('assets/php/appointments.php?action=book', { method: 'POST', body: new FormData(form) });
      const result = await response.json();
      message.innerHTML = `<div class="alert alert-${result.ok ? 'success' : 'danger'}">${result.message}</div>`;
      if (result.ok) form.reset();
    });
  </script>
</body>
</html>
