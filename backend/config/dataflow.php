<?php

return [
    'demo_admin_email' => env('DATAFLOW_DEMO_ADMIN_EMAIL'),
    'demo_password' => env('DATAFLOW_DEMO_PASSWORD'),
    'max_input_bytes' => (int) env('MAX_INPUT_BYTES', 10485760),
];
