<?php
/**
 * Scheduled referral campaigns runner.
 * Windows Task Scheduler (every 15 minutes):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\www\mzigo\_server\cron\referral_campaigns.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__, 2) . '/api/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/api/_growth.php';
require_once dirname(__DIR__, 2) . '/api/_notifications.php';
$results = growth_campaign_run_due();
echo date('Y-m-d H:i:s') . ' referral campaigns: ' . json_encode($results) . PHP_EOL;
