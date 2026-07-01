<?php
/**
 * LRG Dashboard — Analytics Data Layer
 * Comprehensive GA4 + GSC fetching with file-based caching.
 * Mirrors VALN multi-property-analytics.php patterns.
 */

require_once __DIR__ . '/cc-google-auth.php';

// ─── File Cache ───

function lrg_cache_get(string $key, int $ttl_minutes): ?array {
    $file = __DIR__ . '/data/cache_' . $key . '.json';
    if (!file_exists($file)) return null;
    if ((time() - filemtime($file)) > $ttl_minutes * 60) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function lrg_cache_set(string $key, array $data): void {
    @file_put_contents(__DIR__ . '/data/cache_' . $key . '.json', json_encode($data));
}

// ─── GA4 API ───

function lrg_ga4_api(string $propertyId, string $endpoint, array $body): ?array {
    $token = cc_get_google_token();
    if (!$token) return null;
    $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:{$endpoint}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    return json_decode($resp, true);
}

// ─── GSC API ───

function lrg_gsc_api(string $gscProperty, array $body): ?array {
    $token = cc_get_google_token();
    if (!$token) return null;
    $url = 'https://www.googleapis.com/webmasters/v3/sites/' . urlencode($gscProperty) . '/searchAnalytics/query';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    return json_decode($resp, true);
}

// ─── Date Range Helper ───

function lrg_cc_date_ranges(string $range): array {
    $days = match($range) { '7d' => 7, '90d' => 90, default => 30 };
    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime("-{$days} days"));
    $pEnd = date('Y-m-d', strtotime("-" . ($days + 1) . " days"));
    $pStart = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
    $yEnd = date('Y-m-d', strtotime("-366 days"));
    $yStart = date('Y-m-d', strtotime("-" . (365 + $days) . " days"));
    return [
        'start' => $start, 'end' => $end,
        'pStart' => $pStart, 'pEnd' => $pEnd,
        'yStart' => $yStart, 'yEnd' => $yEnd,
        'days' => $days,
        'ga4Start' => "{$days}daysAgo", 'ga4End' => 'today',
        'ga4PStart' => ($days * 2) . "daysAgo", 'ga4PEnd' => ($days + 1) . "daysAgo",
        'ga4YStart' => (365 + $days) . "daysAgo", 'ga4YEnd' => "366daysAgo",
    ];
}

// ─── Full GA4 Data (8 API calls, cached 30min) ───

function lrg_cc_get_ga4_data(string $range = '30d', bool $force = false): array {
    global $config;
    $prop = $config['ga4_property_id'] ?? '';
    if (empty($prop)) return ['error' => 'GA4 property not configured'];

    $cacheKey = 'cc_ga4_' . $range;
    if (!$force) {
        $cached = lrg_cache_get($cacheKey, 30);
        if ($cached) return $cached;
    }

    $pct = fn($c, $p) => $p > 0 ? round(($c - $p) / $p * 100, 1) : 0;
    $dr = lrg_cc_date_ranges($range);

    // 1. Current overview
    $current = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4Start'], 'endDate' => $dr['ga4End']]],
        'metrics' => [['name'=>'activeUsers'],['name'=>'sessions'],['name'=>'screenPageViews'],['name'=>'newUsers'],['name'=>'averageSessionDuration'],['name'=>'bounceRate']]
    ]);
    if ($current === null) return ['error' => 'GA4 API failed'];

    // 2. Previous period
    $previous = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4PStart'], 'endDate' => $dr['ga4PEnd']]],
        'metrics' => [['name'=>'activeUsers'],['name'=>'sessions'],['name'=>'screenPageViews']]
    ]);

    // 3. Organic sessions (current)
    $organic = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4Start'], 'endDate' => $dr['ga4End']]],
        'dimensionFilter' => ['filter' => ['fieldName'=>'sessionDefaultChannelGroup','stringFilter'=>['value'=>'Organic Search']]],
        'metrics' => [['name'=>'sessions']]
    ]);

    // 4. Organic sessions (previous)
    $prev_organic = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4PStart'], 'endDate' => $dr['ga4PEnd']]],
        'dimensionFilter' => ['filter' => ['fieldName'=>'sessionDefaultChannelGroup','stringFilter'=>['value'=>'Organic Search']]],
        'metrics' => [['name'=>'sessions']]
    ]);

    // 5. Traffic sources
    $sources = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4Start'], 'endDate' => $dr['ga4End']]],
        'dimensions' => [['name'=>'sessionDefaultChannelGroup']],
        'metrics' => [['name'=>'sessions'],['name'=>'activeUsers']],
        'orderBys' => [['metric'=>['metricName'=>'sessions'],'desc'=>true]],
        'limit' => 6
    ]);

    // 6. Daily sessions
    $chartDays = min($dr['days'], 90);
    $daily = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $chartDays . 'daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name'=>'date']],
        'metrics' => [['name'=>'sessions'],['name'=>'activeUsers']],
        'orderBys' => [['dimension'=>['dimensionName'=>'date'],'desc'=>false]],
        'limit' => 30
    ]);

    // 7. Top landing pages
    $landing = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4Start'], 'endDate' => $dr['ga4End']]],
        'dimensions' => [['name'=>'landingPagePlusQueryString']],
        'metrics' => [['name'=>'sessions'],['name'=>'activeUsers'],['name'=>'bounceRate']],
        'orderBys' => [['metric'=>['metricName'=>'sessions'],'desc'=>true]],
        'limit' => 10
    ]);

    // 8. YoY comparison
    $yoy = lrg_ga4_api($prop, 'runReport', [
        'dateRanges' => [['startDate' => $dr['ga4YStart'], 'endDate' => $dr['ga4YEnd']]],
        'metrics' => [['name'=>'activeUsers'],['name'=>'sessions'],['name'=>'screenPageViews']]
    ]);

    $cur = $current['rows'][0]['metricValues'] ?? [];
    $prev = $previous['rows'][0]['metricValues'] ?? [];
    $org_cur = (int)($organic['rows'][0]['metricValues'][0]['value'] ?? 0);
    $org_prev = (int)($prev_organic['rows'][0]['metricValues'][0]['value'] ?? 0);

    $users = (int)($cur[0]['value'] ?? 0);
    $p_users = (int)($prev[0]['value'] ?? 0);
    $sessions = (int)($cur[1]['value'] ?? 0);
    $p_sessions = (int)($prev[1]['value'] ?? 0);
    $pv = (int)($cur[2]['value'] ?? 0);
    $p_pv = (int)($prev[2]['value'] ?? 0);

    $result = [
        'organic_sessions' => $org_cur,
        'organic_trend' => $pct($org_cur, $org_prev),
        'users' => $users,
        'users_trend' => $pct($users, $p_users),
        'sessions' => $sessions,
        'sessions_trend' => $pct($sessions, $p_sessions),
        'pageviews' => $pv,
        'pageviews_trend' => $pct($pv, $p_pv),
        'new_users' => (int)($cur[3]['value'] ?? 0),
        'avg_duration' => round((float)($cur[4]['value'] ?? 0), 0),
        'bounce_rate' => round((float)($cur[5]['value'] ?? 0) * 100, 1),
        'sources' => array_map(fn($r) => [
            'channel' => $r['dimensionValues'][0]['value'],
            'sessions' => (int)$r['metricValues'][0]['value'],
            'users' => (int)$r['metricValues'][1]['value']
        ], $sources['rows'] ?? []),
        'daily' => array_map(fn($r) => [
            'date' => $r['dimensionValues'][0]['value'],
            'sessions' => (int)$r['metricValues'][0]['value'],
            'users' => (int)$r['metricValues'][1]['value']
        ], $daily['rows'] ?? []),
        'top_landing' => array_map(fn($r) => [
            'page' => $r['dimensionValues'][0]['value'],
            'sessions' => (int)$r['metricValues'][0]['value'],
            'users' => (int)$r['metricValues'][1]['value'],
            'bounce' => round((float)$r['metricValues'][2]['value'] * 100, 1)
        ], $landing['rows'] ?? []),
        'yoy' => [
            'users_ly' => (int)($yoy['rows'][0]['metricValues'][0]['value'] ?? 0),
            'sessions_ly' => (int)($yoy['rows'][0]['metricValues'][1]['value'] ?? 0),
            'pageviews_ly' => (int)($yoy['rows'][0]['metricValues'][2]['value'] ?? 0),
            'users_yoy' => $pct($users, (int)($yoy['rows'][0]['metricValues'][0]['value'] ?? 0)),
            'sessions_yoy' => $pct($sessions, (int)($yoy['rows'][0]['metricValues'][1]['value'] ?? 0)),
            'pageviews_yoy' => $pct($pv, (int)($yoy['rows'][0]['metricValues'][2]['value'] ?? 0)),
        ],
        'range' => $range,
        'range_label' => match($range) { '7d' => 'Last 7 days', '90d' => 'Last 90 days', default => 'Last 30 days' },
        'fetched_at' => date('Y-m-d H:i:s'),
    ];

    lrg_cache_set($cacheKey, $result);
    return $result;
}

// ─── Full GSC Data (5 API calls, cached 60min) ───

function lrg_cc_get_gsc_data(string $range = '30d', bool $force = false): array {
    global $config;
    $gsc = $config['gsc_property'] ?? '';
    if (empty($gsc)) return ['error' => 'GSC property not configured'];

    $cacheKey = 'cc_gsc_' . $range;
    if (!$force) {
        $cached = lrg_cache_get($cacheKey, 60);
        if ($cached) return $cached;
    }

    $dr = lrg_cc_date_ranges($range);
    // GSC data is delayed ~2 days, so shift end to -2 days
    $end = date('Y-m-d', strtotime('-2 days'));
    $start = date('Y-m-d', strtotime('-' . ($dr['days'] + 1) . ' days'));
    $prev_end = date('Y-m-d', strtotime('-' . ($dr['days'] + 2) . ' days'));
    $prev_start = date('Y-m-d', strtotime('-' . ($dr['days'] * 2 + 1) . ' days'));

    $totals = lrg_gsc_api($gsc, ['startDate'=>$start,'endDate'=>$end,'dimensions'=>['date'],'rowLimit'=>1000]);
    if ($totals === null) return ['error' => 'GSC API failed'];

    $prev_totals = lrg_gsc_api($gsc, ['startDate'=>$prev_start,'endDate'=>$prev_end,'dimensions'=>['date'],'rowLimit'=>1000]);
    $queries = lrg_gsc_api($gsc, ['startDate'=>$start,'endDate'=>$end,'dimensions'=>['query'],'rowLimit'=>15]);
    $pages = lrg_gsc_api($gsc, ['startDate'=>$start,'endDate'=>$end,'dimensions'=>['page'],'rowLimit'=>15]);
    $all_q = lrg_gsc_api($gsc, ['startDate'=>$start,'endDate'=>$end,'dimensions'=>['query'],'rowLimit'=>5000]);

    $clicks = 0; $impressions = 0; $prev_clicks = 0; $prev_impressions = 0;
    $ctr_sum = 0; $pos_sum = 0; $day_count = 0; $daily = [];

    foreach ($totals['rows'] ?? [] as $row) {
        $clicks += $row['clicks']; $impressions += $row['impressions'];
        $ctr_sum += $row['ctr']; $pos_sum += $row['position']; $day_count++;
        $daily[] = ['date' => $row['keys'][0], 'clicks' => $row['clicks'], 'impressions' => $row['impressions']];
    }
    foreach (($prev_totals['rows'] ?? []) as $row) {
        $prev_clicks += $row['clicks']; $prev_impressions += $row['impressions'];
    }

    $pos_dist = ['1-3'=>0,'4-10'=>0,'11-20'=>0,'21-50'=>0,'51+'=>0];
    foreach (($all_q['rows'] ?? []) as $row) {
        $p = $row['position'];
        if ($p <= 3) $pos_dist['1-3']++;
        elseif ($p <= 10) $pos_dist['4-10']++;
        elseif ($p <= 20) $pos_dist['11-20']++;
        elseif ($p <= 50) $pos_dist['21-50']++;
        else $pos_dist['51+']++;
    }

    $pct = fn($c, $p) => $p > 0 ? round(($c - $p) / $p * 100, 1) : 0;

    $result = [
        'clicks' => $clicks, 'impressions' => $impressions,
        'avg_ctr' => $day_count > 0 ? round($ctr_sum / $day_count * 100, 1) : 0,
        'avg_position' => $day_count > 0 ? round($pos_sum / $day_count, 1) : 0,
        'clicks_trend' => $pct($clicks, $prev_clicks),
        'impressions_trend' => $pct($impressions, $prev_impressions),
        'position_distribution' => $pos_dist,
        'top_queries' => array_slice($queries['rows'] ?? [], 0, 15),
        'top_pages' => array_slice($pages['rows'] ?? [], 0, 15),
        'daily' => $daily,
        'range' => $range,
        'fetched_at' => date('Y-m-d H:i:s'),
    ];

    lrg_cache_set($cacheKey, $result);
    return $result;
}

// ─── GA4 Realtime (cached 2min) ───

function lrg_cc_get_realtime(): array {
    global $config;
    $prop = $config['ga4_property_id'] ?? '';
    if (empty($prop)) return ['users' => 0, 'pages' => []];

    $cached = lrg_cache_get('cc_realtime', 2);
    if ($cached) return $cached;

    $rt = lrg_ga4_api($prop, 'runRealtimeReport', [
        'metrics' => [['name' => 'activeUsers']],
        'dimensions' => [['name' => 'unifiedScreenName']],
        'limit' => 5
    ]);

    $total = 0; $pages = [];
    if ($rt) {
        foreach ($rt['rows'] ?? [] as $row) {
            $u = (int)$row['metricValues'][0]['value'];
            $total += $u;
            $pages[] = ['page' => $row['dimensionValues'][0]['value'], 'users' => $u];
        }
    }

    $result = ['users' => $total, 'pages' => array_slice($pages, 0, 5)];
    lrg_cache_set('cc_realtime', $result);
    return $result;
}

// ─── Lead Detail ───

function lrg_get_lead_detail(int $post_id): array {
    try {
        $pdo = lrg_get_pdo();
        $stmt = $pdo->prepare("SELECT ID, post_title, post_date, post_status, post_content FROM wp_posts WHERE ID = ? AND post_type = 'rss_lead'");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        if (!$post) return ['ok' => false, 'error' => 'Lead not found'];

        $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ? ORDER BY meta_key");
        $stmt->execute([$post_id]);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }

        return [
            'ok' => true,
            'id' => (int)$post['ID'],
            'title' => $post['post_title'],
            'date' => $post['post_date'],
            'status' => $post['post_status'],
            'content' => $post['post_content'],
            'meta' => $meta,
        ];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
