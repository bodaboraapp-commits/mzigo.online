<?php
declare(strict_types=1);

/**
 * Admin-configurable wording and ordering for partner scan actions.
 *
 * The action keys stay fixed because they drive parcel status transitions;
 * only the labels, descriptions and display order are editable.
 */
function scan_action_defaults(): array
{
    return [
        ['key' => 'dropoff', 'label' => 'Drop off', 'description' => 'Origin office', 'order' => 1],
        ['key' => 'handover', 'label' => 'Hand over', 'description' => 'To transporter', 'order' => 2],
        ['key' => 'arrival', 'label' => 'Arrival', 'description' => 'Destination office', 'order' => 3],
        ['key' => 'collection', 'label' => 'Collect', 'description' => 'Customer picked up', 'order' => 4],
    ];
}

function scan_action_config(): array
{
    $defaults = scan_action_defaults();
    $raw = platform_setting('scan_actions_config');
    if ($raw === null || $raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    $byKey = [];
    foreach ($decoded as $item) {
        if (!is_array($item) || empty($item['key'])) {
            continue;
        }
        $key = (string) $item['key'];
        if (!in_array($key, array_column($defaults, 'key'), true)) {
            continue;
        }
        $byKey[$key] = [
            'key' => $key,
            'label' => mb_substr(trim((string) ($item['label'] ?? '')), 0, 40),
            'description' => mb_substr(trim((string) ($item['description'] ?? '')), 0, 80),
            'order' => (int) ($item['order'] ?? 0),
        ];
    }
    $result = [];
    foreach ($defaults as $fallback) {
        $item = $byKey[$fallback['key']] ?? $fallback;
        if ($item['label'] === '') {
            $item['label'] = $fallback['label'];
        }
        if ($item['order'] <= 0) {
            $item['order'] = $fallback['order'];
        }
        $result[] = $item;
    }
    usort($result, static function (array $a, array $b): int {
        return [$a['order'], $a['key']] <=> [$b['order'], $b['key']];
    });
    return $result;
}

function scan_action_save(array $items): array
{
    $allowed = array_column(scan_action_defaults(), 'key');
    $byKey = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = (string) ($item['key'] ?? '');
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 40);
        $description = mb_substr(trim((string) ($item['description'] ?? '')), 0, 80);
        if ($label === '') {
            fail_error('invalid_scan_action', 'Each scan action needs a label.', 422);
        }
        $byKey[$key] = [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'order' => max(1, (int) ($item['order'] ?? count($byKey) + 1)),
        ];
    }
    if (count($byKey) !== count($allowed)) {
        fail_error('invalid_scan_action', 'All four scan actions must be included.', 422);
    }
    $ordered = array_values($byKey);
    usort($ordered, static function (array $a, array $b): int {
        return [$a['order'], $a['key']] <=> [$b['order'], $b['key']];
    });
    // Normalize order values after sorting so the next edit starts cleanly.
    foreach ($ordered as $index => &$row) {
        $row['order'] = $index + 1;
    }
    unset($row);
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("scan_actions_config", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($ordered, JSON_UNESCAPED_UNICODE)]);
    return $ordered;
}
