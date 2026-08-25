<?php
return [
    'printer_name' => 'kyocera-escola',
    'upload_path' => __DIR__ . '/../storage/uploads/',
    'share_target_path' => __DIR__ . '/../storage/share-target/',
    'print_temp_path' => __DIR__ . '/../storage/print-temp/',
    'max_upload_bytes' => 100 * 1024 * 1024,
    'print_preview_max_sheets' => 3,
    'print_preview_ttl_seconds' => 1800,
    'print_preview_timeout_seconds' => 30,
    'log_path' => __DIR__ . '/../storage/logs/app.log',
    'app_timezone' => 'America/Cuiaba'
];
