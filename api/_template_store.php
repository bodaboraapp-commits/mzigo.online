<?php
/** Editable transactional email templates and placeholder rendering. */
declare(strict_types=1);
require_once __DIR__ . '/_comms_catalog.php';

function email_template_ensure_seeded(): void
{
    try {
        $count = (int) db()->query('SELECT COUNT(*) FROM email_templates')->fetchColumn();
        if ($count > 0) { return; }
        $ins = db()->prepare('INSERT IGNORE INTO email_templates (template_key, label, stage, recipient, trigger_text, is_system)
            VALUES (?, ?, ?, ?, ?, 1)');
        foreach (comms_template_catalog() as $key => $meta) {
            $ins->execute([$key, $meta['label'], $meta['stage'], $meta['recipient'], $meta['trigger']]);
        }
    } catch (Throwable $e) {
        error_log('email template seed failed: ' . $e->getMessage());
    }
}

function email_template_rows(): array
{
    email_template_ensure_seeded();
    try {
        return db()->query('SELECT id, template_key, label, stage, recipient, trigger_text, subject, body_text, body_html,
            is_active, is_system, updated_at FROM email_templates ORDER BY stage ASC, id ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function email_template_override(string $key): ?array
{
    try {
        $stmt = db()->prepare('SELECT subject, body_text, body_html, is_active FROM email_templates WHERE template_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['is_active'] !== 1) { return null; }
        if (trim((string) $row['subject']) === '' && trim((string) $row['body_text']) === '' && trim((string) $row['body_html']) === '') {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function email_template_apply(string $text, array $user, array $parcel = [], array $data = []): string
{
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $tracking = (string) ($data['tracking'] ?? $parcel['tracking'] ?? '');
    $amount = isset($data['amount']) ? (int) $data['amount'] : (int) ($parcel['fee_total'] ?? 0);
    $replacements = [
        '{{name}}' => (string) ($user['name'] ?? $data['name'] ?? 'there'),
        '{{email}}' => (string) ($user['email'] ?? $data['email'] ?? ''),
        '{{tracking}}' => $tracking,
        '{{status}}' => (string) ($data['status_label'] ?? $data['status'] ?? ''),
        '{{amount}}' => $amount > 0 ? number_format($amount) : '',
        '{{fee}}' => $amount > 0 ? number_format($amount) : '',
        '{{reference}}' => (string) ($data['reference'] ?? ''),
        '{{link}}' => (string) ($data['link'] ?? ''),
        '{{claim_ref}}' => (string) ($data['ref'] ?? ''),
        '{{note}}' => (string) ($data['note'] ?? ''),
        '{{business}}' => (string) ($data['business'] ?? ''),
        '{{code}}' => (string) ($data['code'] ?? ''),
        '{{collect_code}}' => (string) ($data['collect_code'] ?? ''),
        '{{invoice}}' => (string) ($data['invoice'] ?? ''),
        '{{password}}' => (string) ($data['password'] ?? ''),
        '{{date}}' => date('d M Y, H:i'),
        '{{support_email}}' => (string) (config()['app']['support_email'] ?? 'hello@mzigo.online'),
        '{{admin_url}}' => $base . '/admin#communications',
        '{{from}}' => (string) ($parcel['from_city'] ?? $parcel['from'] ?? ''),
        '{{to}}' => (string) ($parcel['to_city'] ?? $parcel['to'] ?? ''),
        '{{carrier}}' => (string) ($parcel['carrier'] ?? ''),
        '{{track_url}}' => $tracking !== '' ? $base . '/track-mzigo?num=' . rawurlencode($tracking) : $base . '/track-mzigo',
        '{{customer_url}}' => $base . '/customer',
        '{{claims_url}}' => $base . '/claims',
        '{{billing_url}}' => $base . '/billing',
        '{{login_url}}' => $base . '/login',
        '{{profile_url}}' => $base . '/profile',
    ];
    return strtr($text, $replacements);
}
