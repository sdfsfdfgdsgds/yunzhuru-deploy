<?php
header('Content-Type: text/plain');
$logs = [
    '/var/log/supervisor/worker-error.log',
    '/var/log/supervisor/worker.log',
    '/var/log/supervisor/php-error.log',
];
foreach ($logs as $f) {
    echo "=== $f ===\n";
    if (file_exists($f)) {
        echo shell_exec("tail -30 " . escapeshellarg($f)) . "\n";
    } else {
        echo "(not found)\n\n";
    }
}
