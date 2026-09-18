<?php
class GoogleCalendar
{
    private $calendarId;
    private $serviceAccount;
    private $timezone;

    public function __construct(array $config)
    {
        $this->calendarId = trim((string) ($config['google_calendar_id'] ?? ''));
        $this->timezone = new DateTimeZone($config['timezone'] ?? 'America/Argentina/Buenos_Aires');
        $credentials = $config['google_service_account_json'] ?? '';
        if ($credentials !== '' && is_file($credentials)) {
            $credentials = file_get_contents($credentials);
        }
        $this->serviceAccount = json_decode((string) $credentials, true);
    }

    public function isConfigured()
    {
        return $this->calendarId !== '' && is_array($this->serviceAccount)
            && !empty($this->serviceAccount['client_email']) && !empty($this->serviceAccount['private_key']);
    }

    public function createAppointment(array $appointment, $doctorName)
    {
        $start = new DateTime($appointment['appointment_date'] . ' ' . $appointment['appointment_time'], $this->timezone);
        $end = clone $start;
        $end->modify('+30 minutes');
        $event = [
            'summary' => 'Turno IFER - ' . $doctorName,
            'description' => "Paciente: {$appointment['patient_name']}\nTeléfono: {$appointment['patient_phone']}\nEstado: {$appointment['status']}",
            'start' => ['dateTime' => $start->format(DateTime::RFC3339), 'timeZone' => $this->timezone->getName()],
            'end' => ['dateTime' => $end->format(DateTime::RFC3339), 'timeZone' => $this->timezone->getName()],
            'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 1440]]]
        ];
        $result = $this->request('POST', '/calendars/' . rawurlencode($this->calendarId) . '/events', $event);
        return $result['id'] ?? null;
    }

    public function updateAppointment($eventId, $status)
    {
        if (!$eventId) return false;
        $result = $this->request('PATCH', '/calendars/' . rawurlencode($this->calendarId) . '/events/' . rawurlencode($eventId), [
            'status' => $status === 'cancelled' ? 'cancelled' : 'confirmed'
        ]);
        return isset($result['id']);
    }

    private function request($method, $path, array $body = [])
    {
        $token = $this->accessToken();
        if (!$token) return [];
        $handle = curl_init('https://www.googleapis.com/calendar/v3' . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 15
        ]);
        $response = curl_exec($handle);
        curl_close($handle);
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function accessToken()
    {
        if (!$this->isConfigured() || !function_exists('openssl_sign') || !function_exists('curl_init')) return null;
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64Url(json_encode([
            'iss' => $this->serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => time(),
            'exp' => time() + 3600
        ]));
        $unsigned = $header . '.' . $claim;
        if (!openssl_sign($unsigned, $signature, $this->serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) return null;
        $jwt = $unsigned . '.' . $this->base64Url($signature);
        $handle = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            CURLOPT_TIMEOUT => 15
        ]);
        $response = json_decode((string) curl_exec($handle), true);
        curl_close($handle);
        return $response['access_token'] ?? null;
    }

    private function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}