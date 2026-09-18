<?php
return [
    'timezone' => getenv('IFER_TIMEZONE') ?: 'America/Argentina/Buenos_Aires',
    'google_calendar_id' => getenv('IFER_GOOGLE_CALENDAR_ID') ?: 'ifer.alvear2259@gmail.com',
    'google_service_account_json' => getenv('IFER_GOOGLE_SERVICE_ACCOUNT_JSON') ?: '',
    'app_base_url' => getenv('IFER_APP_BASE_URL') ?: '',
    'twilio_sid' => getenv('IFER_TWILIO_SID') ?: '',
    'twilio_token' => getenv('IFER_TWILIO_TOKEN') ?: '',
    'twilio_from' => getenv('IFER_TWILIO_FROM') ?: '',
    'reminder_cron_token' => ''
];
