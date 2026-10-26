<?php
/**
 * Admin marketing campaigns.
 *
 * Campaigns are deliberately limited to confirmed newsletter subscribers with
 * marketing consent. Transactional order and onboarding messages are never
 * sourced from this table.
 */
declare(strict_types=1);

require_once __DIR__ . '/_newsletter.php';
require_once __DIR__ . '/_notifications.php';

function marketing_campaign_audience_counts(): array
{
    $out = ['newsletter' => 0, 'alerts' => 0, 'all' => 0];
    try {
        $out['newsletter'] = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed' AND FIND_IN_SET('newsletter', interests)")->fetchColumn();
        $out['alerts'] = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed' AND FIND_IN_SET('alerts', interests)")->fetchColumn();
        $out['all'] = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed'")->fetchColumn();
    } catch (Throwable $e) {
        error_log('campaign audience counts failed: ' . $e->getMessage());
    }
    return $out;
}

function marketing_campaign_allowed_events(): array
{
    return ['subscriber_confirmed' => 'Subscriber confirmed after campaign creation'];
}

function marketing_campaign_allowed_conditions(): array
{
    return [
        'subscriber_age_days' => 'Subscriber age at least N days',
        'no_campaign_days' => 'No marketing campaign in last N days',
        'audience_at_least' => 'Audience has at least N confirmed subscribers',
    ];
}

function marketing_campaign_trigger_normalize(array $input, string $scheduledAt = ''): array
{
    $allowedEvents = marketing_campaign_allowed_events();
    $allowedConditions = marketing_campaign_allowed_conditions();
    $events = [];
    foreach (array_slice((array) ($input['events'] ?? []), 0, 5) as $row) {
        $event = (string) ($row['event'] ?? '');
        if (!isset($allowedEvents[$event])) { continue; }
        $days = max(0, min(3650, (int) ($row['days'] ?? 0)));
        $key = $event . ':' . $days;
        if (!isset($events[$key])) { $events[$key] = ['event' => $event, 'days' => $days]; }
    }
    $conditions = [];
    foreach (array_slice((array) ($input['conditions'] ?? []), 0, 5) as $row) {
        $condition = (string) ($row['condition'] ?? '');
        if (!isset($allowedConditions[$condition])) { continue; }
        $days = max(0, min(3650, (int) ($row['days'] ?? 0)));
        $key = $condition . ':' . $days;
        if (!isset($conditions[$key])) { $conditions[$key] = ['condition' => $condition, 'days' => $days]; }
    }
    return [
        'time' => !empty($input['time']),
        'scheduled_at' => $scheduledAt,
        'logic' => (($input['logic'] ?? 'all') === 'any') ? 'any' : 'all',
        'events' => array_values($events),
        'conditions' => array_values($conditions),
    ];
}

function marketing_campaign_config(array $campaign): array
{
    $fallback = ['time' => true, 'scheduled_at' => (string) ($campaign['scheduled_at'] ?? ''), 'logic' => 'all', 'events' => [], 'conditions' => []];
    $raw = (string) ($campaign['trigger_config'] ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return marketing_campaign_trigger_normalize($decoded, (string) ($campaign['scheduled_at'] ?? ''));
        }
    }
    $type = (string) ($campaign['trigger_type'] ?? 'time');
    $fallback['time'] = in_array($type, ['time', 'all'], true);
    if (in_array($type, ['event', 'all'], true) && ($campaign['trigger_event'] ?? '') !== '') {
        $fallback['events'][] = ['event' => (string) $campaign['trigger_event'], 'days' => 0];
    }
    if (in_array($type, ['condition', 'all'], true) && ($campaign['trigger_condition'] ?? '') !== '') {
        $fallback['conditions'][] = ['condition' => (string) $campaign['trigger_condition'], 'days' => (int) ($campaign['trigger_days'] ?? 0)];
    }
    return $fallback;
}

function marketing_campaign_recipients(array $campaign): array
{
    $audience = (string) ($campaign['audience'] ?? 'all');
    $sql = "SELECT * FROM newsletter_subscribers WHERE status='confirmed'";
    if ($audience === 'newsletter') { $sql .= " AND FIND_IN_SET('newsletter', interests)"; }
    elseif ($audience === 'alerts') { $sql .= " AND FIND_IN_SET('alerts', interests)"; }
    $base = db()->query($sql . ' ORDER BY id ASC')->fetchAll();
    $config = marketing_campaign_config($campaign);
    $ruleSets = [];
    $createdAt = strtotime((string) ($campaign['created_at'] ?? 'now'));
    $now = time();

    foreach ($config['events'] as $rule) {
        $ids = [];
        $delay = (int) $rule['days'] * 86400;
        if ($rule['event'] === 'subscriber_confirmed') {
            foreach ($base as $row) {
                $at = !empty($row['confirmed_at']) ? strtotime((string) $row['confirmed_at']) : 0;
                if ($at > 0 && $at >= $createdAt && $at <= $now - $delay) { $ids[(int) $row['id']] = true; }
            }
        }
        $ruleSets[] = $ids;
    }

    $recentCampaignIds = [];
    $hasNoCampaignCondition = false;
    foreach ($config['conditions'] as $rule) {
        if ($rule['condition'] === 'no_campaign_days') { $hasNoCampaignCondition = true; }
    }
    if ($hasNoCampaignCondition) {
        $maxDays = 0;
        foreach ($config['conditions'] as $rule) {
            if ($rule['condition'] === 'no_campaign_days') { $maxDays = max($maxDays, (int) $rule['days']); }
        }
        $stmt = db()->prepare("SELECT DISTINCT subscriber_id FROM marketing_campaign_recipients WHERE status='sent' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$maxDays]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) { $recentCampaignIds[(int) $sid] = true; }
    }

    foreach ($config['conditions'] as $rule) {
        $ids = [];
        $days = (int) $rule['days'];
        if ($rule['condition'] === 'subscriber_age_days') {
            $threshold = $now - $days * 86400;
            foreach ($base as $row) {
                if (strtotime((string) $row['created_at']) <= $threshold) { $ids[(int) $row['id']] = true; }
            }
        } elseif ($rule['condition'] === 'no_campaign_days') {
            foreach ($base as $row) {
                if (!isset($recentCampaignIds[(int) $row['id']])) { $ids[(int) $row['id']] = true; }
            }
        } elseif ($rule['condition'] === 'audience_at_least') {
            $count = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed'")->fetchColumn();
            if ($count >= $days) {
                foreach ($base as $row) { $ids[(int) $row['id']] = true; }
            }
        }
        $ruleSets[] = $ids;
    }

    if (!$ruleSets) { return $base; }
    $combined = array_shift($ruleSets);
    $logic = $config['logic'] === 'any' ? 'any' : 'all';
    foreach ($ruleSets as $set) {
        if ($logic === 'all') {
            foreach (array_keys($combined) as $id) { if (!isset($set[$id])) { unset($combined[$id]); } }
        } else {
            foreach ($set as $id => $_) { $combined[$id] = true; }
        }
    }
    $byId = [];
    foreach ($base as $row) { $byId[(int) $row['id']] = $row; }
    $out = [];
    foreach ($base as $row) {
        if (isset($combined[(int) $row['id']])) { $out[] = $row; }
    }
    return $out;
}

function marketing_campaign_ready(array $campaign): bool
{
    $config = marketing_campaign_config($campaign);
    if (!empty($config['time'])) {
        $scheduled = (string) ($campaign['scheduled_at'] ?? '');
        if ($scheduled === '' || strtotime($scheduled) > time()) { return false; }
    }
    if (empty($config['events']) && empty($config['conditions'])) { return true; }
    return count(marketing_campaign_recipients($campaign)) > 0;
}

function marketing_campaign_payload(int $limit = 100): array
{
    $channels = newsletter_channels();
    $rows = db()->query('SELECT * FROM marketing_campaigns ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['sent_count'] = (int) $row['sent_count'];
        $row['failed_count'] = (int) $row['failed_count'];
        $stmt = db()->prepare('SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id = ?');
        $stmt->execute([$row['id']]);
        $row['attempted'] = (int) $stmt->fetchColumn();
    }
    unset($row);
    return [
        'campaigns' => $rows,
        'audience_counts' => marketing_campaign_audience_counts(),
        'channels' => array_values($channels),
        'artwork_url' => (string) (newsletter_config()['artwork_url'] ?? ''),
        'trigger_events' => marketing_campaign_allowed_events(),
        'trigger_conditions' => marketing_campaign_allowed_conditions(),
    ];
}

function marketing_campaign_save(array $data, int $adminId): array
{
    $id = (int) ($data['id'] ?? 0);
    $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
    $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 160);
    $body = trim((string) ($data['body'] ?? ''));
    if ($name === '' || $subject === '' || mb_strlen($body) < 10) {
        fail('Name, subject and a message of at least 10 characters are required.', 422);
    }
    if (mb_strlen($body) > 10000) { $body = mb_substr($body, 0, 10000); }
    $audience = in_array(($data['audience'] ?? 'all'), ['newsletter', 'alerts', 'all'], true) ? (string) $data['audience'] : 'all';
    $ctaLabel = mb_substr(trim((string) ($data['cta_label'] ?? '')), 0, 80);
    $ctaUrl = mb_substr(trim((string) ($data['cta_url'] ?? '')), 0, 500);
    if ($ctaUrl !== '' && !preg_match('~^(https?://|/)~i', $ctaUrl)) { $ctaUrl = ''; }
    $artwork = mb_substr(trim((string) ($data['artwork_url'] ?? '')), 0, 500);
    if ($artwork !== '' && !preg_match('~^(https?://|/assets/img/newsletter/)~i', $artwork)) { $artwork = ''; }
    $scheduled = !empty($data['scheduled_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['scheduled_at'])) : null;
    $incomingConfig = is_array($data['trigger_config'] ?? null) ? $data['trigger_config'] : [];
    if (empty($incomingConfig) && isset($data['trigger_type'])) {
        $incomingConfig = [
            'time' => in_array($data['trigger_type'], ['time', 'all'], true),
            'logic' => 'all',
            'events' => !empty($data['trigger_event']) ? [['event' => (string) $data['trigger_event'], 'days' => 0]] : [],
            'conditions' => !empty($data['trigger_condition']) ? [['condition' => (string) $data['trigger_condition'], 'days' => (int) ($data['trigger_days'] ?? 0)]] : [],
        ];
    }
    $triggerConfig = marketing_campaign_trigger_normalize($incomingConfig, $scheduled ?: '');
    $scheduled = !empty($triggerConfig['time']) ? ($triggerConfig['scheduled_at'] ?: null) : null;
    $triggerConfig['scheduled_at'] = $scheduled ?: '';
    $triggerType = !empty($triggerConfig['time'])
        ? ((count($triggerConfig['events']) + count($triggerConfig['conditions']) > 0) ? 'all' : 'time')
        : (!empty($triggerConfig['events']) ? 'event' : 'condition');
    $triggerEvent = $triggerConfig['events'][0]['event'] ?? null;
    $triggerCondition = $triggerConfig['conditions'][0]['condition'] ?? null;
    $triggerDays = $triggerConfig['conditions'][0]['days'] ?? ($triggerConfig['events'][0]['days'] ?? 0);
    $triggerConfigJson = json_encode($triggerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($id > 0) {
        $stmt = db()->prepare('UPDATE marketing_campaigns SET name=?, subject=?, body=?, cta_label=?, cta_url=?, artwork_url=?,
            audience=?, scheduled_at=?, trigger_type=?, trigger_event=?, trigger_condition=?, trigger_days=?, trigger_config=?, status=IF(status="sent","sent","draft") WHERE id=?');
        $stmt->execute([$name, $subject, $body, $ctaLabel ?: null, $ctaUrl ?: null, $artwork ?: null, $audience, $scheduled,
            $triggerType, $triggerEvent, $triggerCondition, $triggerDays, $triggerConfigJson, $id]);
    } else {
        $stmt = db()->prepare('INSERT INTO marketing_campaigns (name, subject, body, cta_label, cta_url, artwork_url, audience, trigger_type, trigger_event, trigger_condition, trigger_days, trigger_config, status, scheduled_at, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, NOW())');
        $stmt->execute([$name, $subject, $body, $ctaLabel ?: null, $ctaUrl ?: null, $artwork ?: null, $audience,
            $triggerType, $triggerEvent, $triggerCondition, $triggerDays, $triggerConfigJson, $scheduled, $adminId]);
        $id = (int) db()->lastInsertId();
    }
    audit('admin.marketing_campaign_saved', 'marketing_campaign', (string) $id, ['audience' => $audience]);
    return ['saved_id' => $id] + marketing_campaign_payload();
}

function marketing_campaign_schedule(int $id, string $when): array
{
    $stmt = db()->prepare('SELECT * FROM marketing_campaigns WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    if (!$campaign) { fail_error('not_found', 'Campaign not found.', 404); }
    $isTime = in_array((string) ($campaign['trigger_type'] ?? 'time'), ['time', 'all'], true);
    $scheduledAt = null;
    if ($isTime) {
        if ($when === '' || strtotime($when) === false) { fail('Choose a valid schedule time for a time-triggered campaign.', 422); }
        $scheduledAt = date('Y-m-d H:i:s', strtotime($when));
    } elseif ($when !== '') {
        if (strtotime($when) === false) { fail('Choose a valid schedule time.', 422); }
        $scheduledAt = date('Y-m-d H:i:s', strtotime($when));
    }
    $stmt = db()->prepare('UPDATE marketing_campaigns SET status="scheduled", scheduled_at=? WHERE id=? AND status IN ("draft","scheduled")');
    $stmt->execute([$scheduledAt, $id]);
    if ($stmt->rowCount() < 1) { fail_error('not_found', 'Campaign cannot be scheduled.', 404); }
    audit('admin.marketing_campaign_scheduled', 'marketing_campaign', (string) $id, ['scheduled_at' => $scheduledAt]);
    return marketing_campaign_payload();
}

function marketing_campaign_cancel(int $id): array
{
    db()->prepare('UPDATE marketing_campaigns SET status="cancelled" WHERE id=? AND status IN ("draft","scheduled")')->execute([$id]);
    audit('admin.marketing_campaign_cancelled', 'marketing_campaign', (string) $id);
    return marketing_campaign_payload();
}

function marketing_campaign_delete(int $id): array
{
    db()->prepare('DELETE FROM marketing_campaign_recipients WHERE campaign_id=?')->execute([$id]);
    db()->prepare('DELETE FROM marketing_campaigns WHERE id=?')->execute([$id]);
    audit('admin.marketing_campaign_deleted', 'marketing_campaign', (string) $id);
    return marketing_campaign_payload();
}

/**
 * Claim and deliver a campaign. Only confirmed subscribers in the selected
 * audience are targeted. Each recipient is recorded once.
 */
function marketing_campaign_send(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM marketing_campaigns WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    if (!$campaign) { return ['ok' => false, 'error' => 'Campaign not found.']; }
    if (!count(marketing_campaign_recipients($campaign))) {
        return ['ok' => false, 'error' => 'No eligible recipients match this campaign trigger yet.'];
    }
    $claim = db()->prepare('UPDATE marketing_campaigns SET status="sending" WHERE id=? AND status IN ("draft","scheduled")');
    $claim->execute([$id]);
    if ($claim->rowCount() < 1) { return ['ok' => false, 'error' => 'Campaign is not sendable.']; }

    $channels = newsletter_channels();
    $subscribers = marketing_campaign_recipients($campaign);
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $sent = 0;
    $failed = 0;
    try {
        foreach ($subscribers as $subscriber) {
            $sid = (int) $subscriber['id'];
            $existing = db()->prepare('SELECT id FROM marketing_campaign_recipients WHERE campaign_id=? AND subscriber_id=? LIMIT 1');
            $existing->execute([$id, $sid]);
            if ($existing->fetch()) { continue; }

            $channel = (string) ($subscriber['channel'] ?? 'email');
            $provider = (string) ($channels[$channel]['provider'] ?? 'log');
            $active = !empty($channels[$channel]['active']);
            $recipient = $channel === 'email' ? (string) ($subscriber['email'] ?? '') : (string) ($subscriber['phone'] ?? '');
            $unsub = $base . '/newsletter-unsubscribe.php?id=' . $sid . '&token=' . newsletter_unsub_token($sid);
            $body = $campaign['body'] . "\n\nUnsubscribe: " . $unsub;
            $ctaAbsolute = (string) ($campaign['cta_url'] ?? '');
            if ($ctaAbsolute !== '' && str_starts_with($ctaAbsolute, '/')) { $ctaAbsolute = $base . $ctaAbsolute; }
            if ($ctaAbsolute !== '') {
                $body .= "\n\n" . ($campaign['cta_label'] ?: 'Open') . ': ' . $ctaAbsolute;
            }
            $artworkAbsolute = (string) ($campaign['artwork_url'] ?? '');
            if ($artworkAbsolute !== '' && str_starts_with($artworkAbsolute, '/')) { $artworkAbsolute = $base . $artworkAbsolute; }
            $status = 'sent';
            $error = null;
            $marketingAllowed = true;
            if (!empty($subscriber['user_id'])) {
                $prefs = notification_preferences((int) $subscriber['user_id']);
                $marketingAllowed = !empty($prefs['marketing']);
                if ($channel === 'email' && empty($prefs['email_enabled'])) { $marketingAllowed = false; }
            }
            if (!$marketingAllowed) {
                $status = 'skipped';
                $error = 'Marketing preferences opted out.';
            } elseif (!$active || $recipient === '') {
                $status = 'skipped';
                $error = !$active ? 'Channel inactive.' : 'No recipient contact.';
            } else {
                try {
                    notification_send($channel, [
                        'id' => 0, 'name' => 'Mzigo subscriber', 'email' => (string) ($subscriber['email'] ?? ''),
                        'phone' => (string) ($subscriber['phone'] ?? ''), 'comm_channel' => null,
                    ], (string) $campaign['subject'], $body, 'marketing_campaign', [], $provider,
                        'marketing_campaign', (string) $id, [
                            'campaign_name' => $campaign['name'],
                            'cta_label' => (string) ($campaign['cta_label'] ?? ''),
                            'cta_url' => $ctaAbsolute,
                            'artwork_url' => $artworkAbsolute,
                            'unsubscribe_url' => $unsub,
                        ]);
                } catch (Throwable $e) {
                    $status = 'failed';
                    $error = mb_substr($e->getMessage(), 0, 255);
                }
            }
            db()->prepare('INSERT INTO marketing_campaign_recipients (campaign_id, subscriber_id, channel, recipient, status, error, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())')->execute([$id, $sid, $channel, $recipient ?: 'n/a', $status, $error]);
            if ($status === 'sent') { $sent++; } else { $failed++; }
        }
        db()->prepare('UPDATE marketing_campaigns SET status="sent", sent_count=?, failed_count=?, sent_at=NOW() WHERE id=?')
            ->execute([$sent, $failed, $id]);
        audit('admin.marketing_campaign_sent', 'marketing_campaign', (string) $id, ['sent' => $sent, 'failed' => $failed]);
        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'campaigns' => marketing_campaign_payload()['campaigns']];
    } catch (Throwable $e) {
        db()->prepare('UPDATE marketing_campaigns SET status="draft", failed_count=failed_count+? WHERE id=?')
            ->execute([$failed, $id]);
        error_log('marketing campaign failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Campaign delivery stopped unexpectedly.'];
    }
}

function marketing_campaign_run_due(): array
{
    $rows = db()->query('SELECT * FROM marketing_campaigns WHERE status="scheduled" ORDER BY id ASC LIMIT 20')->fetchAll();
    $results = [];
    foreach ($rows as $campaign) {
        if (!marketing_campaign_ready($campaign)) { continue; }
        $results[] = ['id' => (int) $campaign['id']] + marketing_campaign_send((int) $campaign['id']);
    }
    return $results;
}
