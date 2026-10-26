<?php
/**
 * WhatsApp queue worker.
 * Windows Task Scheduler (every minute):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\www\mzigo\_server\cron\whatsapp_queue.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__, 2) . '/api/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/api/_whatsapp.php';
$results = wa_process_queue(50);
echo date('Y-m-d H:i:s') . ' WhatsApp jobs: ' . json_encode($results) . PHP_EOL;
