<?php
/**
 * Mzigo.online — public API v1: track a parcel inside the key's workspace.
 * GET /api/v1/track.php?q=TRK-…   (X-API-Key)
 */
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

$key = api_key_context();
$tenantId = (int) $key['tenant_id'];
record_api_usage($tenantId, (int) ($key['key_id'] ?? 0));
$q = isset($_GET['q']) ? strtoupper(trim((string) $_GET['q'])) : '';
if ($q === '') {
    fail_error('missing_query', 'Pass ?q= with a tracking number, Locker ID or errand ref.', 422);
}

$stmt = db()->prepare('SELECT * FROM parcels WHERE tenant_id = ? AND (tracking = ? OR locker = ?) LIMIT 1');
$stmt->execute([$tenantId, $q, $q]);
$row = $stmt->fetch();
if ($row) {
    json_out(['ok' => true, 'source' => 'mysql', 'found' => true, 'kind' => 'parcel', 'record' => parcel_api($row)]);
}

$stmt = db()->prepare('SELECT * FROM errands WHERE tenant_id = ? AND ref = ? LIMIT 1');
$stmt->execute([$tenantId, $q]);
$row = $stmt->fetch();
if ($row) {
    json_out(['ok' => true, 'source' => 'mysql', 'found' => true, 'kind' => 'errand', 'record' => errand_api($row)]);
}

json_out(['ok' => true, 'source' => 'mysql', 'found' => false, 'query' => $q]);
