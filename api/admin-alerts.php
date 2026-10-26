<?php
/** GET /api/admin-alerts.php — ops alerts for stuck jobs, payouts, KYC and notifications. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_platform_admin();
$pdo = db();
$count = function (string $sql): int { return (int) (db()->query($sql)->fetchColumn() ?: 0); };
$alerts = [
    ['key' => 'stuck_jobs', 'label' => 'Active jobs older than 24h', 'count' => $count("SELECT COUNT(*) FROM runner_jobs WHERE status IN ('accepted','picked_up') AND accepted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 'href' => '/admin#runnerjobs'],
    ['key' => 'expired_jobs', 'label' => 'Expired available jobs', 'count' => $count("SELECT COUNT(*) FROM runner_jobs WHERE status = 'available' AND expires_at IS NOT NULL AND expires_at < NOW()"), 'href' => '/admin#runnerjobs'],
    ['key' => 'pending_payouts', 'label' => 'Pending runner payouts', 'count' => $count("SELECT COUNT(*) FROM runner_payouts WHERE status = 'pending'"), 'href' => '/admin#settings'],
    ['key' => 'failed_payouts', 'label' => 'Failed runner payouts', 'count' => $count("SELECT COUNT(*) FROM runner_payouts WHERE status = 'failed'"), 'href' => '/admin#settings'],
    ['key' => 'pending_kyc', 'label' => 'KYC documents awaiting review', 'count' => $count("SELECT COUNT(*) FROM kyc_documents WHERE status = 'pending'"), 'href' => '/admin#kyc'],
    ['key' => 'failed_notifications', 'label' => 'Failed notifications (24h)', 'count' => $count("SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 'href' => '/admin#communications'],
];
$tokenStmt = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('seo_search_console_token','seo_bing_token')");
$tokens = [];
foreach ($tokenStmt->fetchAll() as $row) { $tokens[$row['setting_key']] = trim((string) $row['setting_value']); }
if (($tokens['seo_search_console_token'] ?? '') === '') {
    $alerts[] = ['key' => 'gsc_token', 'label' => 'Google Search Console token not configured', 'count' => 1, 'href' => '/admin#seo'];
}
if (($tokens['seo_bing_token'] ?? '') === '') {
    $alerts[] = ['key' => 'bing_token', 'label' => 'Bing verification token not configured', 'count' => 1, 'href' => '/admin#seo'];
}
try {
    $socialStmt = $pdo->query("SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE 'social_%' AND setting_value IS NOT NULL AND setting_value <> ''");
    if ((int) $socialStmt->fetchColumn() === 0) {
        $alerts[] = ['key' => 'social_links', 'label' => 'Official social links still using defaults', 'count' => 1, 'href' => '/admin#cms'];
    }
} catch (Throwable $e) { error_log('social readiness check failed: ' . $e->getMessage()); }

json_out(['ok' => true, 'alerts' => $alerts]);
