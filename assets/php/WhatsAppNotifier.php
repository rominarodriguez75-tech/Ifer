<?php
class WhatsAppNotifier
{
    private $sid;
    private $token;
    private $from;
    private $baseUrl;

    public function __construct(array $config)
    {
        $this->sid = trim((string) ($config['twilio_sid'] ?? ''));
        $this->token = trim((string) ($config['twilio_token'] ?? ''));
        $this->from = $this->formatAddress($config['twilio_from'] ?? '');
        $this->baseUrl = rtrim(trim((string) ($config['app_base_url'] ?? '')), '/');
    }

    public function isConfigured()
    {
        return $this->sid !== '' && $this->token !== '' && $this->from !== '' && $this->baseUrl !== '';
    }

    public function sendAppointmentRequest(array $appointment, $doctorName)
    {
        return $this->send($appointment['patient_phone'], $this->messageHeader('solicitud de turno') . "\n\nHola {$appointment['patient_name']}, recibimos tu solicitud con {$doctorName} para el " . $this->dateTime($appointment) . ".\n\nConfirmá: {$this->baseUrl}/assets/php/appointments.php?action=confirm&token={$appointment['token']}\nCancelá: {$this->baseUrl}/assets/php/appointments.php?action=cancel&token={$appointment['token']}");
    }

    public function sendConfirmed(array $appointment, $doctorName)
    {
        return $this->send($appointment['patient_phone'], $this->messageHeader('turno confirmado') . "\n\nHola {$appointment['patient_name']}, tu turno con {$doctorName} quedó confirmado para el " . $this->dateTime($appointment) . ".");
    }

    public function sendCancelled(array $appointment)
    {
        return $this->send($appointment['patient_phone'], $this->messageHeader('turno cancelado') . "\n\nHola {$appointment['patient_name']}, tu turno del " . $this->dateTime($appointment) . " fue cancelado.");
    }

    private function send($phone, $body)
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) return false;
        $handle = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($this->sid) . '/Messages.json');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $this->sid . ':' . $this->token,
            CURLOPT_POSTFIELDS => http_build_query(['From' => $this->from, 'To' => $this->formatAddress($phone), 'Body' => $body]),
            CURLOPT_TIMEOUT => 15
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return $status >= 200 && $status < 300 && is_string($response);
    }

    private function dateTime(array $appointment)
    {
        return date('d/m/Y', strtotime($appointment['appointment_date'])) . ' a las ' . $appointment['appointment_time'];
    }

    private function messageHeader($type)
    {
        return 'IFER - ' . ucfirst($type);
    }

    private function formatAddress($phone)
    {
        $phone = trim((string) $phone);
        if ($phone === '') return '';
        return strpos($phone, 'whatsapp:') === 0 ? $phone : 'whatsapp:+' . ltrim(preg_replace('/\D+/', '', $phone), '+');
    }
}
