<?php
return [
    'timezone' => getenv('IFER_TIMEZONE') ?: 'America/Argentina/Buenos_Aires',
    'google_calendar_id' => getenv('IFER_GOOGLE_CALENDAR_ID') ?: 'ifer.alvear2259@gmail.com',
    'google_service_account_json' => getenv('IFER_GOOGLE_SERVICE_ACCOUNT_JSON') ?: 'C:\\Users\\romina.rodriguez\\Downloads\\ifer-508916-2348970c4648.json',
    'twilio_sid' => '',
    'twilio_token' => '',
    'twilio_from' => '',
    'reminder_cron_token' => ''
];
