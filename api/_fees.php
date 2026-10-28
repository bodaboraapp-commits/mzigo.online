<?php
declare(strict_types=1);

/**
 * Platform-controlled fixed fee components used when a workspace rate card does
 * not override them.
 */
function fee_config(): array
{
    $config = ['holding_fee_kes' => 30, 'platform_fee_kes' => 80];
    $raw = platform_setting('fee_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $config['holding_fee_kes'] = max(0, min(1000000, (int) ($decoded['holding_fee_kes'] ?? 30)));
            $config['platform_fee_kes'] = max(0, min(1000000, (int) ($decoded['platform_fee_kes'] ?? 80)));
        }
    }
    return $config;
}

function fee_config_save(array $input): array
{
    $holding = max(0, min(1000000, (int) ($input['holding_fee_kes'] ?? 0)));
    $platform = max(0, min(1000000, (int) ($input['platform_fee_kes'] ?? 0)));
    $config = ['holding_fee_kes' => $holding, 'platform_fee_kes' => $platform];
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("fee_config", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($config, JSON_UNESCAPED_SLASHES)]);
    return $config;
}
