<?php
/**
 * Marketing campaign trigger runner.
 *
 * Schedule every 15 minutes:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\www\mzigo\_server\cron\marketing_campaigns.php
 *
 * Evaluates time, event, condition and combined triggers for active campaigns.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__, 2) . '/api/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/api/_campaigns.php';
$results = marketing_campaign_run_due();
echo date('Y-m-d H:i:s') . ' marketing campaigns: ' . json_encode($results) . PHP_EOL;
