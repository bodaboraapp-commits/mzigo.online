<?php
/** Platform-wide API usage aggregate from api_usage_daily. */
declare(strict_types=1);

function platform_api_usage(): array
{
    $empty = [
        'total_30d' => 0, 'last_7d' => 0, 'today' => 0, 'active_tenants' => 0, 'active_keys' => 0,
        'daily' => [], 'by_tenant' => [], 'by_key' => [],
    ];
    try {
        $pdo = db();
        $summary = $pdo->query('SELECT COALESCE(SUM(requests),0) AS total_30d,
                COALESCE(SUM(CASE WHEN day >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN requests ELSE 0 END),0) AS last_7d,
                COALESCE(SUM(CASE WHEN day = CURDATE() THEN requests ELSE 0 END),0) AS today,
                COUNT(DISTINCT CASE WHEN requests > 0 THEN tenant_id END) AS active_tenants,
                COUNT(DISTINCT CASE WHEN requests > 0 AND api_key_id > 0 THEN api_key_id END) AS active_keys
            FROM api_usage_daily WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)')->fetch() ?: [];

        $byTenant = $pdo->query('SELECT u.tenant_id, COALESCE(t.name, CONCAT("Workspace #", u.tenant_id)) AS tenant_name,
                SUM(u.requests) AS requests, COUNT(DISTINCT CASE WHEN u.api_key_id > 0 THEN u.api_key_id END) AS keys_used
            FROM api_usage_daily u LEFT JOIN tenants t ON t.id = u.tenant_id
            WHERE u.day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY u.tenant_id, tenant_name ORDER BY requests DESC LIMIT 20')->fetchAll();

        $byKey = $pdo->query('SELECT u.api_key_id, COALESCE(k.name, CONCAT("Key #", u.api_key_id)) AS key_name,
                k.prefix, COALESCE(t.name, CONCAT("Workspace #", u.tenant_id)) AS tenant_name, SUM(u.requests) AS requests,
                MAX(k.last_used_at) AS last_used_at
            FROM api_usage_daily u LEFT JOIN api_keys k ON k.id = u.api_key_id
            LEFT JOIN tenants t ON t.id = u.tenant_id
            WHERE u.day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND u.api_key_id > 0
            GROUP BY u.api_key_id, key_name, k.prefix, tenant_name ORDER BY requests DESC LIMIT 20')->fetchAll();

        $rows = $pdo->query('SELECT day, SUM(requests) AS requests FROM api_usage_daily
            WHERE day >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY day ORDER BY day ASC')->fetchAll();
        $map = [];
        foreach ($rows as $row) { $map[(string) $row['day']] = (int) $row['requests']; }
        $daily = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $daily[] = ['day' => $day, 'requests' => $map[$day] ?? 0];
        }
        return [
            'total_30d' => (int) ($summary['total_30d'] ?? 0),
            'last_7d' => (int) ($summary['last_7d'] ?? 0),
            'today' => (int) ($summary['today'] ?? 0),
            'active_tenants' => (int) ($summary['active_tenants'] ?? 0),
            'active_keys' => (int) ($summary['active_keys'] ?? 0),
            'daily' => $daily,
            'by_tenant' => $byTenant,
            'by_key' => $byKey,
        ];
    } catch (Throwable $e) {
        error_log('platform api usage aggregate failed: ' . $e->getMessage());
        return $empty;
    }
}
