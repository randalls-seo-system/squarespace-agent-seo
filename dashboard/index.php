<?php
/**
 * LRG Dashboard — Executive Dashboard for LRG Realty
 * Forked from VALN Dashboard architecture.
 *
 * Tabs: Command Center, Search Console, Analytics, Leads, Agent Roster
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/role-visibility.php';
require_once __DIR__ . '/cc-google-auth.php';
require_once __DIR__ . '/dashboard-auth.php';
require_once __DIR__ . '/agent-provisioning.php';
require_once __DIR__ . '/lrg-analytics.php';
require_once __DIR__ . '/lrg-worklog.php';
require_once __DIR__ . '/agent-review.php';
date_default_timezone_set($config['timezone']);

// Agent provisioning writes are live. Pages created as DRAFT (pending review).

$base_path = '/dashboard/';

// Prevent WP Engine Varnish from caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ═══════════════════════════════════════════════════════════
// DATA FUNCTIONS
// ═══════════════════════════════════════════════════════════

function lrg_gsc_query(string $token, string $property, array $body): ?array {
    $url = 'https://www.googleapis.com/webmasters/v3/sites/' . urlencode($property) . '/searchAnalytics/query';
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

function lrg_get_gsc_data(): array {
    global $config;
    $cache_file = __DIR__ . '/data/gsc_cache.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
        return json_decode(file_get_contents($cache_file), true) ?: [];
    }

    $token = cc_get_google_token();
    if (!$token) return ['error' => 'No Google token'];

    $prop = $config['gsc_property'];
    $end = date('Y-m-d', strtotime('-1 day'));
    $start = date('Y-m-d', strtotime('-29 days'));
    $prev_end = date('Y-m-d', strtotime('-30 days'));
    $prev_start = date('Y-m-d', strtotime('-59 days'));

    $totals = lrg_gsc_query($token, $prop, ['startDate'=>$start, 'endDate'=>$end, 'dimensions'=>['date'], 'rowLimit'=>1000]);
    $prev_totals = lrg_gsc_query($token, $prop, ['startDate'=>$prev_start, 'endDate'=>$prev_end, 'dimensions'=>['date'], 'rowLimit'=>1000]);
    $queries = lrg_gsc_query($token, $prop, ['startDate'=>$start, 'endDate'=>$end, 'dimensions'=>['query'], 'rowLimit'=>25]);
    $pages = lrg_gsc_query($token, $prop, ['startDate'=>$start, 'endDate'=>$end, 'dimensions'=>['page'], 'rowLimit'=>25]);
    $all_queries = lrg_gsc_query($token, $prop, ['startDate'=>$start, 'endDate'=>$end, 'dimensions'=>['query'], 'rowLimit'=>5000]);

    $clicks = 0; $impressions = 0; $prev_clicks = 0; $prev_impressions = 0;
    $ctr_sum = 0; $pos_sum = 0; $day_count = 0;

    foreach (($totals['rows'] ?? []) as $row) {
        $clicks += $row['clicks']; $impressions += $row['impressions'];
        $ctr_sum += $row['ctr']; $pos_sum += $row['position']; $day_count++;
    }
    foreach (($prev_totals['rows'] ?? []) as $row) {
        $prev_clicks += $row['clicks']; $prev_impressions += $row['impressions'];
    }

    $pos_dist = ['1-3'=>0, '4-10'=>0, '11-20'=>0, '21-50'=>0, '51+'=>0];
    foreach (($all_queries['rows'] ?? []) as $row) {
        $p = $row['position'];
        if ($p <= 3) $pos_dist['1-3']++;
        elseif ($p <= 10) $pos_dist['4-10']++;
        elseif ($p <= 20) $pos_dist['11-20']++;
        elseif ($p <= 50) $pos_dist['21-50']++;
        else $pos_dist['51+']++;
    }

    $result = [
        'clicks' => $clicks, 'impressions' => $impressions,
        'avg_ctr' => $day_count > 0 ? round($ctr_sum / $day_count * 100, 1) : 0,
        'avg_position' => $day_count > 0 ? round($pos_sum / $day_count, 1) : 0,
        'clicks_trend' => $prev_clicks > 0 ? round(($clicks - $prev_clicks) / $prev_clicks * 100, 1) : 0,
        'impressions_trend' => $prev_impressions > 0 ? round(($impressions - $prev_impressions) / $prev_impressions * 100, 1) : 0,
        'top_queries' => array_slice($queries['rows'] ?? [], 0, 25),
        'top_pages' => array_slice($pages['rows'] ?? [], 0, 25),
        'position_distribution' => $pos_dist,
        'daily_clicks' => array_map(fn($r) => ['date'=>$r['keys'][0], 'clicks'=>$r['clicks'], 'impressions'=>$r['impressions']], $totals['rows'] ?? []),
        'fetched_at' => date('Y-m-d H:i:s'),
    ];

    @file_put_contents($cache_file, json_encode($result));
    return $result;
}

function lrg_ga4_run_report(string $token, string $propertyId, array $body): ?array {
    $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";
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

function lrg_get_ga4_data(): array {
    global $config;
    $prop = $config['ga4_property_id'] ?? '';
    if (empty($prop)) return ['error' => 'GA4 property ID not configured. Set ga4_property_id in config.php.'];

    $cache_file = __DIR__ . '/data/ga4_cache.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
        return json_decode(file_get_contents($cache_file), true) ?: [];
    }

    $token = cc_get_google_token();
    if (!$token) return ['error' => 'No Google token'];

    try {
        $overview = lrg_ga4_run_report($token, $prop, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'yesterday']],
            'metrics' => [['name'=>'sessions'], ['name'=>'totalUsers'], ['name'=>'screenPageViews'], ['name'=>'bounceRate'], ['name'=>'averageSessionDuration']],
        ]);

        $top_pages = lrg_ga4_run_report($token, $prop, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'yesterday']],
            'dimensions' => [['name' => 'pagePath']],
            'metrics' => [['name'=>'screenPageViews'], ['name'=>'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit' => 20,
        ]);

        $sources = lrg_ga4_run_report($token, $prop, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'yesterday']],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [['name'=>'sessions'], ['name'=>'totalUsers']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => 10,
        ]);

        $result = ['overview' => $overview, 'top_pages' => $top_pages, 'sources' => $sources, 'fetched_at' => date('Y-m-d H:i:s')];
        @file_put_contents($cache_file, json_encode($result));
        return $result;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function lrg_get_content_stats(): array {
    try {
        $pdo = lrg_get_pdo();
        $posts = (int)$pdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type='post' AND post_status='publish'")->fetchColumn();
        $pages = (int)$pdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type='page' AND post_status='publish'")->fetchColumn();
        $scheduled = (int)$pdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type='post' AND post_status='future'")->fetchColumn();
        $leads_total = (int)$pdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type='rss_lead'")->fetchColumn();
        $leads_30d = (int)$pdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type='rss_lead' AND post_date > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $authors = (int)$pdo->query("SELECT COUNT(DISTINCT post_author) FROM wp_posts WHERE post_type='post' AND post_status='publish'")->fetchColumn();
        return compact('posts', 'pages', 'scheduled', 'leads_total', 'leads_30d', 'authors');
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function lrg_get_leads(int $page = 1, int $per_page = 25): array {
    try {
        $pdo = lrg_get_pdo();
        $offset = ($page - 1) * $per_page;

        $total = (int)$pdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type='rss_lead'")->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT p.ID, p.post_title, p.post_date, p.post_status,
                   MAX(CASE WHEN pm.meta_key='_rss_lf_email' THEN pm.meta_value END) as email,
                   MAX(CASE WHEN pm.meta_key='_rss_lf_phone' THEN pm.meta_value END) as phone,
                   MAX(CASE WHEN pm.meta_key='_rss_lf_path' THEN pm.meta_value END) as source,
                   MAX(CASE WHEN pm.meta_key='_fub_person_id' THEN pm.meta_value END) as fub_synced,
                   MAX(CASE WHEN pm.meta_key='_rss_lf_firstname' THEN pm.meta_value END) as firstname,
                   MAX(CASE WHEN pm.meta_key='_rss_lf_lastname' THEN pm.meta_value END) as lastname
            FROM wp_posts p
            LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type='rss_lead'
            GROUP BY p.ID
            ORDER BY p.post_date DESC
            LIMIT " . (int)$per_page . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $per_page)];
    } catch (Exception $e) {
        return ['error' => $e->getMessage(), 'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
    }
}

function lrg_get_roster(): array {
    $roster = [];
    try {
        $pdo = lrg_get_pdo();
        // Dynamic: all pages under the authors parent (5480) that contain the author page template
        $stmt = $pdo->query("SELECT ID, post_title, post_name, post_content, post_status FROM wp_posts WHERE post_type='page' AND post_parent=" . LRG_AUTHOR_PARENT_PAGE . " AND post_content LIKE '%lrgAuthorPage%' ORDER BY post_title ASC");
        $pages = $stmt->fetchAll();

        // Get hub page (7816) to check who's on it
        $hub = $pdo->query("SELECT post_content FROM wp_posts WHERE ID=7816")->fetchColumn();

        foreach ($pages as $p) {
            $c = $p['post_content'];
            // Extract bio from "About ..." section
            $bio = '';
            if (preg_match('/About\s+\w+/i', $c, $m, PREG_OFFSET_CAPTURE)) {
                $ps = strpos($c, '<p', $m[0][1]);
                if ($ps !== false) {
                    $pe = strpos($c, '</p>', $ps);
                    if ($pe !== false) {
                        $openEnd = strpos($c, '>', $ps) + 1;
                        $bio = strip_tags(substr($c, $openEnd, $pe - $openEnd));
                    }
                }
            }

            // Extract title from .sub div
            $title = '';
            if (preg_match('/<div class="sub">([^<]+)<\/div>/', $c, $tm)) {
                $title = html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8');
            }

            // Extract JSON-LD jobTitle
            $job_title = '';
            if (preg_match('/"jobTitle":"([^"]+)"/', $c, $jm)) {
                $job_title = $jm[1];
            }

            // TREC from content
            $trec = '';
            if (preg_match('/TREC\s*#?\s*(\d+)/', $c, $trcm)) {
                $trec = $trcm[1];
            }

            // On hub page?
            $on_hub = ($hub && strpos($hub, 'post_name="' . $p['post_name'] . '"') !== false)
                   || ($hub && strpos($hub, '/' . $p['post_name'] . '/') !== false)
                   || ($hub && strpos($hub, $p['post_title']) !== false);

            // Has shortcode?
            $has_shortcode = strpos($c, '[lrg_author_posts]') !== false;

            $roster[] = [
                'id' => (int)$p['ID'],
                'name' => $p['post_title'],
                'slug' => $p['post_name'],
                'status' => $p['post_status'],
                'title' => $title,
                'job_title' => $job_title,
                'bio' => $bio,
                'trec' => $trec,
                'on_hub' => $on_hub,
                'has_shortcode' => $has_shortcode,
                'bio_words' => str_word_count($bio),
            ];
        }
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
    return $roster;
}

function lrg_roster_compute_diff(array $data): array {
    $diffs = [];
    try {
        $pdo = lrg_get_pdo();
        foreach (($data['agents'] ?? []) as $agent) {
            $id = (int)($agent['id'] ?? 0);
            if (!$id) continue;
            $current = $pdo->query("SELECT post_content FROM wp_posts WHERE ID=$id")->fetchColumn();
            if (!$current) continue;

            $changes = [];

            // Bio change
            if (!empty($agent['bio'])) {
                $new_bio = trim($agent['bio']);
                // Extract current bio
                if (preg_match('/About\s+\w+/i', $current, $m, PREG_OFFSET_CAPTURE)) {
                    $ps = strpos($current, '<p', $m[0][1]);
                    if ($ps !== false) {
                        $pe = strpos($current, '</p>', $ps);
                        if ($pe !== false) {
                            $openEnd = strpos($current, '>', $ps) + 1;
                            $old_bio = strip_tags(substr($current, $openEnd, $pe - $openEnd));
                            if (trim($old_bio) !== $new_bio) {
                                $changes[] = [
                                    'field' => 'bio',
                                    'old' => mb_substr(trim($old_bio), 0, 100) . '...',
                                    'new' => mb_substr($new_bio, 0, 100) . '...',
                                ];
                            }
                        }
                    }
                }
            }

            // Title change
            if (!empty($agent['title'])) {
                $new_title = trim($agent['title']);
                if (preg_match('/<div class="sub">([^<]+)<\/div>/', $current, $tm)) {
                    $old_title = html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8');
                    if ($old_title !== $new_title) {
                        $changes[] = ['field' => 'title', 'old' => $old_title, 'new' => $new_title];
                    }
                }
            }

            if (!empty($changes)) {
                $diffs[] = ['id' => $id, 'name' => $agent['name'] ?? "Post $id", 'changes' => $changes];
            }
        }
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'diffs' => $diffs, 'write_enabled' => true];
}

// ═══════════════════════════════════════════════════════════
// SVG ICON HELPER
// ═══════════════════════════════════════════════════════════

function ico(string $name, int $size = 20): string {
    $s = $size;
    $icons = [
        'command'       => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\" rx=\"1\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\" rx=\"1\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\" rx=\"1\"/><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\" rx=\"1\"/></svg>",
        'search'        => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"11\" cy=\"11\" r=\"8\"/><line x1=\"21\" y1=\"21\" x2=\"16.65\" y2=\"16.65\"/></svg>",
        'bar-chart'     => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"12\" y1=\"20\" x2=\"12\" y2=\"10\"/><line x1=\"18\" y1=\"20\" x2=\"18\" y2=\"4\"/><line x1=\"6\" y1=\"20\" x2=\"6\" y2=\"16\"/></svg>",
        'users'         => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M22 21v-2a4 4 0 0 0-3-3.87\"/><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"/></svg>",
        'mail'          => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z\"/><polyline points=\"22,6 12,13 2,6\"/></svg>",
        'trending-up'   => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"23 6 13.5 15.5 8.5 10.5 1 18\"/><polyline points=\"17 6 23 6 23 12\"/></svg>",
        'file-text'     => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/><line x1=\"16\" y1=\"13\" x2=\"8\" y2=\"13\"/><line x1=\"16\" y1=\"17\" x2=\"8\" y2=\"17\"/></svg>",
        'sun'           => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"5\"/><line x1=\"12\" y1=\"1\" x2=\"12\" y2=\"3\"/><line x1=\"12\" y1=\"21\" x2=\"12\" y2=\"23\"/><line x1=\"4.22\" y1=\"4.22\" x2=\"5.64\" y2=\"5.64\"/><line x1=\"18.36\" y1=\"18.36\" x2=\"19.78\" y2=\"19.78\"/><line x1=\"1\" y1=\"12\" x2=\"3\" y2=\"12\"/><line x1=\"21\" y1=\"12\" x2=\"23\" y2=\"12\"/><line x1=\"4.22\" y1=\"19.78\" x2=\"5.64\" y2=\"18.36\"/><line x1=\"18.36\" y1=\"5.64\" x2=\"19.78\" y2=\"4.22\"/></svg>",
        'moon'          => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z\"/></svg>",
        'log-out'       => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4\"/><polyline points=\"16 17 21 12 16 7\"/><line x1=\"21\" y1=\"12\" x2=\"9\" y2=\"12\"/></svg>",
        'menu'          => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"3\" y1=\"12\" x2=\"21\" y2=\"12\"/><line x1=\"3\" y1=\"6\" x2=\"21\" y2=\"6\"/><line x1=\"3\" y1=\"18\" x2=\"21\" y2=\"18\"/></svg>",
        'edit'          => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7\"/><path d=\"M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z\"/></svg>",
        'check'         => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg>",
        'check-circle'  => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"/><polyline points=\"22 4 12 14.01 9 11.01\"/></svg>",
        'alert-triangle'=> "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z\"/><line x1=\"12\" y1=\"9\" x2=\"12\" y2=\"13\"/><line x1=\"12\" y1=\"17\" x2=\"12.01\" y2=\"17\"/></svg>",
        'clipboard'     => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2\"/><rect x=\"8\" y=\"2\" width=\"8\" height=\"4\" rx=\"1\" ry=\"1\"/></svg>",
        'refresh'       => "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"23 4 23 10 17 10\"/><path d=\"M20.49 15a9 9 0 1 1-2.12-9.36L23 10\"/></svg>",
    ];
    return $icons[$name] ?? '';
}

// ═══════════════════════════════════════════════════════════
// LOGIN PAGE
// ═══════════════════════════════════════════════════════════

function showLogin(?string $error, ?string $message = null): void {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LRG Dashboard — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#060e1f;--surface:#0d1a30;--border:#1a2e4a;--text:#f8fafc;--text-muted:#94a3b8;--text-dim:#64748b;--accent:#c8102e;--accent-bright:#e31837;--danger:#ef4444;--green:#22c55e}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;-webkit-font-smoothing:antialiased;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-bg{position:fixed;inset:0;background:radial-gradient(circle at 20% 30%,rgba(200,16,46,.12) 0%,transparent 50%),radial-gradient(circle at 80% 70%,rgba(9,26,53,.3) 0%,transparent 50%);pointer-events:none}
.login-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:40px;width:100%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,.5);position:relative;z-index:1}
.login-logo{height:42px;margin-bottom:28px;display:block}
.login-title{font-size:22px;font-weight:800;letter-spacing:-.02em;margin:0 0 6px}
.login-sub{font-size:14px;color:var(--text-muted);margin:0 0 28px}
.login-input{width:100%;padding:12px 16px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:15px;font-family:inherit;transition:border-color .15s,box-shadow .15s}
.login-input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(200,16,46,.15)}
.login-input::placeholder{color:var(--text-dim)}
.login-btn{width:100%;padding:12px;margin-top:16px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s;box-shadow:0 2px 8px rgba(200,16,46,.3)}
.login-btn:hover{background:var(--accent-bright);box-shadow:0 4px 16px rgba(200,16,46,.45);transform:translateY(-1px)}
.login-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 14px;color:var(--danger);font-size:13px;margin-bottom:16px}
.login-message{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);border-radius:8px;padding:10px 14px;color:var(--green);font-size:13px;margin-bottom:16px}
</style>
</head>
<body>
<div class="login-bg"></div>
<div class="login-card">
<div style="text-align:center;margin-bottom:28px;font-size:28px;font-weight:800;letter-spacing:-.03em;color:#fff">LRG <span style="color:var(--accent)">Dashboard</span></div>
<h1 class="login-title">Sign In</h1>
<p class="login-sub">Enter your email to receive a login link</p>
<?php if ($error): ?><div class="login-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="login-message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form method="POST">
<input type="email" name="login_email" class="login-input" placeholder="Email address" autofocus required>
<button type="submit" class="login-btn">Send login link</button>
</form>
</div>
</body>
</html>
<?php
}

// ═══════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════

function showDashboard(): void {
global $config, $lrg_current_user, $lrg_effective_role, $_gsc_data, $_content_stats, $_ga4_data, $_page;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LRG Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>(function(){var t=localStorage.getItem('lrg-dash-theme');if(!t)t=window.matchMedia('(prefers-color-scheme:light)').matches?'light':'dark';document.documentElement.setAttribute('data-theme',t)})()</script>
<style>
:root{
--navy:#091A35;--navy-light:#1e3a5f;--navy-dark:#050f1f;--red:#c8102e;--red-bright:#e31837;
--bg:#0a0e1a;--surface:#121827;--surface-el:#1a2234;--surface-hover:#1f2937;
--border:#1f2937;--border-sub:#151c2c;
--text:#f8fafc;--text-m:#94a3b8;--text-d:#64748b;
--ok:#10b981;--ok-bg:rgba(16,185,129,.1);
--warn:#f59e0b;--warn-bg:rgba(245,158,11,.1);
--err:#ef4444;--err-bg:rgba(239,68,68,.1);
--info:#3b82f6;--info-bg:rgba(59,130,246,.1);
--purple:#8b5cf6;--purple-bg:rgba(139,92,246,.1);
--accent:#c8102e;--accent-bg:rgba(200,16,46,.1);
--sh-sm:0 1px 2px rgba(0,0,0,.3);--sh-md:0 4px 12px rgba(0,0,0,.4);--sh-lg:0 10px 40px rgba(0,0,0,.5);
--sh-glow:0 0 24px rgba(200,16,46,.15);
--r:8px;--r-lg:12px;
}
html[data-theme="light"]{
--bg:#f8fafc;--surface:#ffffff;--surface-el:#ffffff;--surface-hover:#f1f5f9;
--border:#e2e8f0;--border-sub:#f1f5f9;
--text:#0f172a;--text-m:#475569;--text-d:#94a3b8;
--ok-bg:rgba(16,185,129,.08);--warn-bg:rgba(245,158,11,.08);--err-bg:rgba(239,68,68,.08);--info-bg:rgba(59,130,246,.08);--purple-bg:rgba(139,92,246,.08);--accent-bg:rgba(200,16,46,.06);
--sh-sm:0 1px 2px rgba(15,23,42,.06);--sh-md:0 4px 12px rgba(15,23,42,.08);--sh-lg:0 10px 40px rgba(15,23,42,.12);--sh-glow:0 0 24px rgba(200,16,46,.1);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;-webkit-font-smoothing:antialiased;background:var(--bg);color:var(--text);font-size:14px;transition:background .2s,color .2s}
::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:var(--bg)}::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.sb{position:fixed;left:0;top:0;bottom:0;width:260px;background:linear-gradient(180deg,var(--navy-dark) 0%,var(--navy) 100%);color:#fff;z-index:100;display:flex;flex-direction:column;border-right:1px solid rgba(255,255,255,.06)}
.sb-hdr{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);text-align:center}
.sb-brand{font-size:22px;font-weight:800;letter-spacing:-.02em;color:#fff}
.sb-brand span{color:var(--red)}
.sb-nav{flex:1;padding:12px 0;overflow-y:auto}
.sb-nav a{display:flex;align-items:center;gap:12px;padding:10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:14px;font-weight:500;transition:all .15s;border-left:3px solid transparent;margin:1px 0}
.sb-nav a:hover{color:rgba(255,255,255,.9);background:rgba(255,255,255,.04)}
.sb-nav a.active{color:#fff;background:rgba(200,16,46,.15);border-left-color:var(--red)}
.sb-nav a svg{flex-shrink:0;opacity:.7}.sb-nav a.active svg{opacity:1}
.sb-foot{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08)}
.sb-foot a{color:rgba(255,255,255,.4);text-decoration:none;font-size:13px;display:flex;align-items:center;gap:6px;transition:color .15s}
.sb-foot a:hover{color:rgba(255,255,255,.7)}
.mob-hdr{display:none;position:fixed;top:0;left:0;right:0;height:56px;background:var(--navy-dark);border-bottom:1px solid var(--border);z-index:99;padding:0 16px;align-items:center;gap:12px}
.mob-burger{background:none;border:none;color:#fff;cursor:pointer;padding:4px}
.mob-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99}
.main{margin-left:260px;padding:32px;min-height:100vh}
.page-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;gap:16px;flex-wrap:wrap}
.page-hdr h1{font-size:26px;font-weight:800;letter-spacing:-.02em}
.page-hdr .sub{font-size:13px;color:var(--text-m);margin-top:2px}
.hdr-actions{display:flex;align-items:center;gap:12px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:28px}
.st{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:20px;display:flex;align-items:flex-start;gap:14px;transition:all .2s}
.st:hover{border-color:var(--red);box-shadow:var(--sh-glow);transform:translateY(-2px)}
.st-ico{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.st-ico.blue{background:var(--info-bg);color:var(--info)}.st-ico.green{background:var(--ok-bg);color:var(--ok)}
.st-ico.orange{background:var(--warn-bg);color:var(--warn)}.st-ico.red{background:var(--accent-bg);color:var(--red)}
.st-ico.purple{background:var(--purple-bg);color:var(--purple)}
.st-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-m);font-weight:600;margin-bottom:4px}
.st-num{font-size:30px;font-weight:800;line-height:1;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.st-sub{font-size:11px;color:var(--text-d);margin-top:4px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;margin-bottom:24px}
.card-hdr{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);gap:12px}
.card-hdr h2{font-size:15px;font-weight:700}
.card-body{padding:20px}.card-body.np{padding:0}
table{width:100%;border-collapse:collapse;font-size:13px}
thead{background:var(--surface-el)}
th{text-align:left;padding:11px 20px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-m);border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:12px 20px;border-bottom:1px solid var(--border-sub);color:var(--text)}
tbody tr{transition:background .1s}tbody tr:hover{background:var(--surface-hover)}
tbody tr:last-child td{border-bottom:none}
td a{color:var(--info);text-decoration:none;font-weight:600}td a:hover{text-decoration:underline}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
.badge-ok{background:var(--ok-bg);color:var(--ok)}.badge-warn{background:var(--warn-bg);color:var(--warn)}
.badge-err{background:var(--err-bg);color:var(--err)}.badge-info{background:var(--info-bg);color:var(--info)}
.badge-neutral{background:rgba(148,163,184,.1);color:var(--text-m)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 16px;border-radius:var(--r);font-weight:600;font-size:13px;cursor:pointer;border:1px solid transparent;transition:all .15s;font-family:inherit;text-decoration:none;white-space:nowrap}
.btn-primary{background:var(--red);color:#fff;box-shadow:0 2px 8px rgba(200,16,46,.3)}
.btn-primary:hover{background:var(--red-bright);box-shadow:0 4px 16px rgba(200,16,46,.45);transform:translateY(-1px)}
.btn-secondary{background:var(--surface);color:var(--text);border-color:var(--border)}
.btn-secondary:hover{background:var(--surface-hover);border-color:var(--red)}
.btn-ghost{background:transparent;color:var(--text-m)}.btn-ghost:hover{background:var(--surface-hover);color:var(--text)}
.btn-sm{padding:5px 10px;font-size:12px}
.btn[disabled]{opacity:.4;cursor:not-allowed;pointer-events:none}
.input,.select,.textarea{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:9px 14px;border-radius:var(--r);font-size:14px;font-family:inherit;width:100%;transition:all .15s}
.input:focus,.textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(200,16,46,.15)}
.textarea{resize:vertical;min-height:80px}
.empty{text-align:center;padding:48px 20px;color:var(--text-d)}.empty p{font-size:14px}
.section{display:none}.section.active{display:block}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.interlock-banner{background:var(--warn-bg);border:1px solid rgba(245,158,11,.3);border-radius:var(--r);padding:14px 20px;margin-bottom:20px;font-size:13px;color:var(--warn);display:flex;align-items:center;gap:10px}
.diff-preview{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:16px;margin-top:16px;font-size:13px}
.diff-old{color:var(--err);text-decoration:line-through}.diff-new{color:var(--ok)}
.toasts{position:fixed;bottom:20px;right:20px;z-index:300;display:flex;flex-direction:column-reverse;gap:8px}
.toast{padding:12px 20px;border-radius:var(--r);color:#fff;font-size:13px;font-weight:500;animation:tIn .3s;box-shadow:var(--sh-md);max-width:400px}
.toast-success{background:var(--ok)}.toast-error{background:var(--err)}
@keyframes tIn{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(5,10,20,.75);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:24px}
.modal-overlay.show{display:flex}
.modal{background:var(--surface-el);border:1px solid var(--border);border-radius:16px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--sh-lg)}
.modal-hdr{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-hdr h3{font-size:17px;font-weight:700}
.modal-close{background:none;border:none;color:var(--text-d);cursor:pointer;padding:4px;border-radius:6px;font-size:20px;line-height:1;transition:all .15s;display:flex}
.modal-close:hover{background:var(--surface-hover);color:var(--text)}
.modal-body{padding:24px}
.modal-foot{padding:16px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.form-group{margin-bottom:10px}.form-group label{display:block;font-size:12px;font-weight:600;color:var(--text-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
.form-group small{color:var(--text-d);font-size:11px;margin-top:3px;display:block}
.btn-warn{background:var(--warn);color:#000;font-size:11px;padding:4px 8px;border-radius:4px;border:none;cursor:pointer;font-weight:600}
.btn-warn:hover{background:#d97706}
.layer-card{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:14px;margin-bottom:12px}
.layer-card h4{font-size:13px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.layer-card .action{font-size:11px;padding:2px 6px;border-radius:4px;font-weight:600}
.layer-card pre{font-size:12px;color:var(--text-m);line-height:1.5;white-space:pre-wrap;word-break:break-word;margin:0}
.pos-bar{display:flex;height:24px;border-radius:4px;overflow:hidden;gap:1px;margin-top:8px}
.pos-bar div{display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;min-width:30px}
.theme-toggle{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-m);transition:all .2s;flex-shrink:0}
.theme-toggle:hover{background:var(--surface-hover);color:var(--text);border-color:var(--red)}
.pag{display:flex;align-items:center;justify-content:center;gap:4px;padding:16px}
.pag button{padding:6px 12px;border:1px solid var(--border);background:var(--surface);border-radius:var(--r);cursor:pointer;font-size:12px;font-family:inherit;color:var(--text-m);transition:all .15s}
.pag button:hover:not(:disabled){background:var(--surface-hover);color:var(--text)}
.pag button.active{background:var(--red);color:#fff;border-color:var(--red)}
.pag button:disabled{opacity:.3;cursor:default}
@media(max-width:768px){
.sb{transform:translateX(-100%);transition:transform .25s;width:280px}.sb.open{transform:translateX(0)}
.mob-hdr{display:flex}.mob-overlay.show{display:block}
.main{margin-left:0;padding:16px;padding-top:72px}
.page-hdr h1{font-size:20px}.stats{grid-template-columns:1fr 1fr}.two-col{grid-template-columns:1fr}
}
.mp-hero{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.mp-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:22px;position:relative;overflow:hidden;transition:all .2s}
.mp-card:hover{border-color:rgba(200,16,46,.3);box-shadow:0 4px 16px rgba(0,0,0,.15);transform:translateY(-2px)}
.mp-card-accent{position:absolute;top:0;left:0;right:0;height:3px}
.mp-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-m);font-weight:600;margin-bottom:8px}
.mp-val{font-size:32px;font-weight:800;letter-spacing:-.02em;line-height:1.1;font-variant-numeric:tabular-nums}
.mp-sub{font-size:12px;color:var(--text-m);margin-top:8px;display:flex;align-items:center;gap:6px}
.mp-up{color:#10b981;font-weight:700}.mp-up::before{content:'↑ '}
.mp-dn{color:#ef4444;font-weight:700}.mp-dn::before{content:'↓ '}
.mp-flat{color:var(--text-d);font-weight:600}
.mp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px}
.mp-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;margin-bottom:24px}
.mp-panel-hdr{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border)}
.mp-panel-hdr h3{font-size:14px;font-weight:700}
.mp-panel-body{padding:16px 20px}.mp-panel-body.np{padding:0}
.mp-tbl{width:100%;border-collapse:collapse;font-size:13px}
.mp-tbl th{text-align:left;padding:10px 16px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-d);border-bottom:1px solid var(--border);background:var(--surface-el)}
.mp-tbl td{padding:10px 16px;border-bottom:1px solid var(--border-sub)}.mp-tbl tr:last-child td{border-bottom:none}.mp-tbl tr:hover{background:var(--surface-hover)}
.mp-dist{display:flex;gap:6px;margin:16px 0 4px}
.mp-dist-bar{flex:1;text-align:center;border-radius:var(--r);padding:12px 4px}
.mp-dist-n{font-size:11px;font-weight:700;opacity:.85;margin-bottom:4px}.mp-dist-v{font-size:20px;font-weight:800}
.mp-live{display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px 20px;margin-bottom:24px}
.mp-live-dot{width:10px;height:10px;background:#22c55e;border-radius:50%;animation:mp-pulse 2s ease-in-out infinite;flex-shrink:0}
@keyframes mp-pulse{0%,100%{opacity:1}50%{opacity:.3}}
.mp-live-lbl{font-size:11px;font-weight:800;letter-spacing:.06em;color:#22c55e;text-transform:uppercase}
.mp-live-ct{font-size:22px;font-weight:800}.mp-live-pg{font-size:12px;color:var(--text-m);margin-left:auto;max-width:50%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mp-chart{height:240px;position:relative}
.mp-yoy{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.mp-yoy-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:20px;text-align:center}
.mp-yoy-val{font-size:28px;font-weight:800;margin:8px 0 4px}.mp-yoy-sub{font-size:11px;color:var(--text-d)}
.mp-loading{text-align:center;padding:80px 20px;color:var(--text-d)}
.mp-spinner{display:inline-block;width:28px;height:28px;border:3px solid var(--border);border-top-color:var(--red);border-radius:50%;animation:mp-spin .8s linear infinite;margin-bottom:12px}
@keyframes mp-spin{to{transform:rotate(360deg)}}
.mp-error{background:var(--warn-bg);border:1px solid rgba(245,158,11,.3);border-radius:var(--r-lg);padding:16px 20px;color:var(--warn);font-size:13px;margin-bottom:20px}
.lead-row{cursor:pointer}
@media(max-width:1100px){.mp-hero{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.mp-grid2{grid-template-columns:1fr}.mp-yoy{grid-template-columns:1fr}}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- Mobile Header -->
<div class="mob-hdr">
<button class="mob-burger" onclick="document.querySelector('.sb').classList.add('open');document.querySelector('.mob-overlay').classList.add('show')"><?= ico('menu',22) ?></button>
<span style="font-weight:800;font-size:16px;color:#fff">LRG <span style="color:var(--red)">Dashboard</span></span>
<button class="theme-toggle" onclick="toggleTheme()" style="margin-left:auto"><?= ico('sun',18) ?></button>
</div>
<div class="mob-overlay" onclick="document.querySelector('.sb').classList.remove('open');this.classList.remove('show')"></div>

<!-- Sidebar -->
<aside class="sb">
<div class="sb-hdr">
<div class="sb-brand">LRG <span>Dashboard</span></div>
</div>
<nav class="sb-nav">
<?php if (lrg_user_can_see_section('command-center', $lrg_effective_role)): ?>
<a href="#" data-section="command-center" class="<?= $_page === 'command-center' ? 'active' : '' ?>"><?= ico('command',18) ?><span>Command Center</span></a>
<?php endif; ?>
<?php if (lrg_user_can_see_section('search-console', $lrg_effective_role)): ?>
<a href="#" data-section="search-console" class="<?= $_page === 'search-console' ? 'active' : '' ?>"><?= ico('search',18) ?><span>Search Console</span></a>
<?php endif; ?>
<?php if (lrg_user_can_see_section('analytics', $lrg_effective_role)): ?>
<a href="#" data-section="analytics" class="<?= $_page === 'analytics' ? 'active' : '' ?>"><?= ico('bar-chart',18) ?><span>Analytics</span></a>
<?php endif; ?>
<div style="border-top:1px solid rgba(255,255,255,.08);margin:8px 0"></div>
<?php if (lrg_user_can_see_section('leads', $lrg_effective_role)): ?>
<a href="#" data-section="leads" class="<?= $_page === 'leads' ? 'active' : '' ?>"><?= ico('mail',18) ?><span>Leads</span></a>
<?php endif; ?>
<?php if (lrg_user_can_see_section('worklog', $lrg_effective_role)): ?>
<a href="#" data-section="worklog" class="<?= $_page === 'worklog' ? 'active' : '' ?>"><?= ico('clipboard',18) ?><span>Work Velocity</span></a>
<?php endif; ?>
<?php if (lrg_user_can_see_section('roster', $lrg_effective_role)): ?>
<a href="#" data-section="roster" class="<?= $_page === 'roster' ? 'active' : '' ?>"><?= ico('users',18) ?><span>Agent Roster</span></a>
<?php endif; ?>
</nav>
<div class="sb-foot">
<div style="display:flex;flex-direction:column;gap:4px;width:100%">
<span style="font-size:11px;color:rgba(255,255,255,.4)">Logged in as: <?= htmlspecialchars($lrg_current_user['name']) ?></span>
<div style="display:flex;align-items:center;justify-content:space-between">
<a href="?logout=1"><?= ico('log-out',16) ?> Logout</a>
<span style="font-size:11px;color:rgba(255,255,255,.2)">v1.0</span>
</div>
</div>
</div>
</aside>

<main class="main">
<div class="toasts" id="toasts"></div>

<!-- ═══ COMMAND CENTER ═══ -->
<div class="section <?= $_page === 'command-center' ? 'active' : '' ?>" id="sec-command-center">
<div class="page-hdr">
<div><h1>Command Center</h1><div class="sub">LRG Realty — lrgrealty.com</div></div>
<div class="hdr-actions">
<select class="input" id="cc-range" onchange="ccSwitchRange(this.value)" style="width:auto;padding:6px 12px;font-size:12px;font-weight:600;min-width:140px">
<option value="7d">Last 7 days</option>
<option value="30d" selected>Last 30 days</option>
<option value="90d">Last 90 days</option>
</select>
<button class="btn btn-secondary btn-sm" onclick="ccRefresh()" id="cc-refresh-btn"><?= ico('refresh',14) ?> Refresh</button><button class="theme-toggle" onclick="toggleTheme()"><?= ico('sun',18) ?></button></div>
</div>
<div id="cc-content"><div class="mp-loading"><div class="mp-spinner"></div><div>Loading analytics...</div></div></div>
</div>

<!-- ═══ SEARCH CONSOLE ═══ -->
<?php $gsc = !empty($_gsc_data) ? $_gsc_data : lrg_get_gsc_data(); ?>
<div class="section <?= $_page === 'search-console' ? 'active' : '' ?>" id="sec-search-console">
<div class="page-hdr">
<div><h1>Search Console</h1><div class="sub">GSC data for <?= htmlspecialchars($config['gsc_property']) ?></div></div>
<div class="hdr-actions"><button class="theme-toggle" onclick="toggleTheme()"><?= ico('sun',18) ?></button></div>
</div>

<div class="stats">
<div class="st"><div class="st-ico blue"><?= ico('trending-up',20) ?></div><div><div class="st-lbl">Clicks</div><div class="st-num"><?= number_format($gsc['clicks'] ?? 0) ?></div></div></div>
<div class="st"><div class="st-ico purple"><?= ico('search',20) ?></div><div><div class="st-lbl">Impressions</div><div class="st-num"><?= number_format($gsc['impressions'] ?? 0) ?></div></div></div>
<div class="st"><div class="st-ico green"><?= ico('check-circle',20) ?></div><div><div class="st-lbl">Avg CTR</div><div class="st-num"><?= $gsc['avg_ctr'] ?? 0 ?>%</div></div></div>
<div class="st"><div class="st-ico orange"><?= ico('bar-chart',20) ?></div><div><div class="st-lbl">Avg Position</div><div class="st-num"><?= $gsc['avg_position'] ?? 0 ?></div></div></div>
</div>

<?php if (!empty($gsc['daily_clicks'])): ?>
<div class="card">
<div class="card-hdr"><h2>Daily Organic Clicks</h2></div>
<div class="card-body">
<div style="display:flex;align-items:flex-end;gap:2px;height:120px">
<?php
$max_c = max(array_column($gsc['daily_clicks'], 'clicks') ?: [1]);
foreach ($gsc['daily_clicks'] as $d):
$h = max(2, round($d['clicks'] / $max_c * 100));
$day = date('j', strtotime($d['date']));
?>
<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px" title="<?= $d['date'] ?>: <?= $d['clicks'] ?> clicks">
<div style="width:100%;height:<?= $h ?>px;background:var(--red);border-radius:2px 2px 0 0;min-width:4px"></div>
<span style="font-size:9px;color:var(--text-d)"><?= $day ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
<?php endif; ?>

<div class="two-col">
<div class="card">
<div class="card-hdr"><h2>Top Queries (25)</h2></div>
<div class="card-body np">
<?php if (!empty($gsc['top_queries'])): ?>
<table><thead><tr><th>#</th><th>Query</th><th>Clicks</th><th>Impr</th><th>CTR</th><th>Pos</th></tr></thead><tbody>
<?php foreach ($gsc['top_queries'] as $i => $q): ?>
<tr><td><?= $i+1 ?></td><td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($q['keys'][0] ?? '') ?></td><td><?= $q['clicks'] ?></td><td><?= number_format($q['impressions']) ?></td><td><?= round($q['ctr']*100,1) ?>%</td><td><?= round($q['position'],1) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<div class="empty"><p>No data</p></div>
<?php endif; ?>
</div>
</div>
<div class="card">
<div class="card-hdr"><h2>Top Pages (25)</h2></div>
<div class="card-body np">
<?php if (!empty($gsc['top_pages'])): ?>
<table><thead><tr><th>#</th><th>Page</th><th>Clicks</th><th>Impr</th><th>Pos</th></tr></thead><tbody>
<?php foreach ($gsc['top_pages'] as $i => $p):
$path = parse_url($p['keys'][0] ?? '', PHP_URL_PATH) ?: $p['keys'][0];
?>
<tr><td><?= $i+1 ?></td><td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($p['keys'][0] ?? '') ?>"><?= htmlspecialchars($path) ?></td><td><?= $p['clicks'] ?></td><td><?= number_format($p['impressions']) ?></td><td><?= round($p['position'],1) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<div class="empty"><p>No data</p></div>
<?php endif; ?>
</div>
</div>
</div>
</div>

<!-- ═══ ANALYTICS (GA4) ═══ -->
<div class="section <?= $_page === 'analytics' ? 'active' : '' ?>" id="sec-analytics">
<div class="page-hdr">
<div><h1>Analytics</h1><div class="sub">GA4 Property: <?= htmlspecialchars($config['ga4_measurement_id'] ?? 'Not configured') ?></div></div>
<div class="hdr-actions"><button class="theme-toggle" onclick="toggleTheme()"><?= ico('sun',18) ?></button></div>
</div>

<?php $ga = $_ga4_data; ?>
<?php if (!empty($ga['error'])): ?>
<div class="card"><div class="card-body">
<div class="interlock-banner"><?= ico('alert-triangle',18) ?> <?= htmlspecialchars($ga['error']) ?></div>
<p style="color:var(--text-m);font-size:13px">To enable GA4 data:<br>1. Add the service account as a Viewer in GA4 Admin<br>2. Set the numeric property ID in config.php (ga4_property_id)</p>
</div></div>
<?php else: ?>
<?php
$ov = $ga['overview']['rows'][0]['metricValues'] ?? [];
$sessions = $ov[0]['value'] ?? 0;
$users = $ov[1]['value'] ?? 0;
$pageviews = $ov[2]['value'] ?? 0;
$bounce = round(($ov[3]['value'] ?? 0) * 100, 1);
?>
<div class="stats">
<div class="st"><div class="st-ico blue"><?= ico('trending-up',20) ?></div><div><div class="st-lbl">Sessions (30d)</div><div class="st-num"><?= number_format((int)$sessions) ?></div></div></div>
<div class="st"><div class="st-ico green"><?= ico('users',20) ?></div><div><div class="st-lbl">Users (30d)</div><div class="st-num"><?= number_format((int)$users) ?></div></div></div>
<div class="st"><div class="st-ico purple"><?= ico('file-text',20) ?></div><div><div class="st-lbl">Pageviews (30d)</div><div class="st-num"><?= number_format((int)$pageviews) ?></div></div></div>
<div class="st"><div class="st-ico orange"><?= ico('refresh',20) ?></div><div><div class="st-lbl">Bounce Rate</div><div class="st-num"><?= $bounce ?>%</div></div></div>
</div>

<div class="two-col">
<div class="card">
<div class="card-hdr"><h2>Top Pages by Views</h2></div>
<div class="card-body np">
<?php if (!empty($ga['top_pages']['rows'])): ?>
<table><thead><tr><th>#</th><th>Page</th><th>Views</th><th>Sessions</th></tr></thead><tbody>
<?php foreach (array_slice($ga['top_pages']['rows'], 0, 15) as $i => $row): ?>
<tr><td><?= $i+1 ?></td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($row['dimensionValues'][0]['value'] ?? '') ?></td><td><?= number_format((int)($row['metricValues'][0]['value'] ?? 0)) ?></td><td><?= number_format((int)($row['metricValues'][1]['value'] ?? 0)) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<div class="empty"><p>No data</p></div>
<?php endif; ?>
</div>
</div>
<div class="card">
<div class="card-hdr"><h2>Traffic Sources</h2></div>
<div class="card-body np">
<?php if (!empty($ga['sources']['rows'])): ?>
<table><thead><tr><th>Channel</th><th>Sessions</th><th>Users</th></tr></thead><tbody>
<?php foreach ($ga['sources']['rows'] as $row): ?>
<tr><td><?= htmlspecialchars($row['dimensionValues'][0]['value'] ?? '') ?></td><td><?= number_format((int)($row['metricValues'][0]['value'] ?? 0)) ?></td><td><?= number_format((int)($row['metricValues'][1]['value'] ?? 0)) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?>
<div class="empty"><p>No data</p></div>
<?php endif; ?>
</div>
</div>
</div>
<?php if (!empty($ga['fetched_at'])): ?>
<div style="text-align:right;font-size:11px;color:var(--text-d)">Data fetched: <?= $ga['fetched_at'] ?> CT</div>
<?php endif; ?>
<?php endif; ?>
</div>

<!-- ═══ LEADS ═══ -->
<div class="section <?= $_page === 'leads' ? 'active' : '' ?>" id="sec-leads">
<div class="page-hdr">
<div><h1>Leads</h1><div class="sub">First-party submissions from Connect with LRG + tool forms</div></div>
<div class="hdr-actions"><button class="theme-toggle" onclick="toggleTheme()"><?= ico('sun',18) ?></button></div>
</div>
<div id="leads-content">
<div class="empty"><p>Loading leads...</p></div>
</div>
</div>

<!-- ═══ WORK VELOCITY ═══ -->
<div class="section <?= $_page === 'worklog' ? 'active' : '' ?>" id="sec-worklog">
<div class="page-hdr">
<div><h1>Work Velocity</h1><div class="sub">Content output + infrastructure work over time</div></div>
<div class="hdr-actions"><button class="btn btn-secondary btn-sm" onclick="wlRefresh()"><?= ico('refresh',14) ?> Refresh</button><button class="btn btn-primary btn-sm" onclick="wlShowAdd()"><?= ico('edit',14) ?> Log Entry</button><button class="theme-toggle" onclick="toggleTheme()"><?= ico('sun',18) ?></button></div>
</div>
<div id="wl-content"><div class="mp-loading"><div class="mp-spinner"></div><div>Loading work log...</div></div></div>
</div>

<!-- Work Log Add Entry Modal -->
<div class="modal-overlay" id="modal-wl-add">
<div class="modal" style="max-width:520px">
<div class="modal-hdr"><h3>Log Work Entry</h3><button class="modal-close" onclick="closeModal('modal-wl-add')">&times;</button></div>
<div class="modal-body">
<div class="form-group"><label>Category</label><select class="input" id="wl-cat"><option value="infra">Infrastructure</option><option value="build">Build</option><option value="fix">Fix</option><option value="seo">SEO</option><option value="deploy">Deploy</option><option value="audit">Audit</option><option value="content">Content</option></select></div>
<div class="form-group"><label>Title *</label><input class="input" id="wl-title" placeholder="What was done"></div>
<div class="form-group"><label>Detail</label><textarea class="textarea" id="wl-desc" rows="3" placeholder="More context (optional)"></textarea></div>
<div class="form-group"><label>Date</label><input class="input" id="wl-date" type="date" value="<?= date('Y-m-d') ?>"></div>
</div>
<div class="modal-foot">
<button class="btn btn-secondary" onclick="closeModal('modal-wl-add')">Cancel</button>
<button class="btn btn-primary" onclick="wlSubmitAdd()"><?= ico('check',14) ?> Save</button>
</div>
</div>
</div>

<!-- ═══ AGENT ROSTER ═══ -->
<div class="section <?= $_page === 'roster' ? 'active' : '' ?>" id="sec-roster">
<div class="page-hdr">
<div><h1>Agent Roster</h1><div class="sub">17 author pages + Specialists hub</div></div>
<div class="hdr-actions"><button class="theme-toggle" onclick="toggleTheme()"><?= ico('sun',18) ?></button></div>
</div>

<div style="background:var(--ok-bg);border:1px solid rgba(16,185,129,.3);border-radius:var(--r);padding:14px 20px;margin-bottom:20px;font-size:13px;color:var(--ok);display:flex;align-items:center;gap:10px">
<?= ico('check-circle',18) ?>
<div><strong>Provisioning Active</strong> — Add Agent creates a DRAFT page for review. Approve to publish and add to hub.</div>
</div>

<?php $roster = lrg_get_roster(); ?>
<?php if (is_array($roster) && !isset($roster['error'])): ?>
<div class="card">
<div class="card-hdr"><h2>Agents (<?= count($roster) ?>)</h2><div style="display:flex;gap:8px"><button class="btn btn-sm btn-secondary" onclick="rosterToggleAll()">Expand/Collapse</button><button class="btn btn-sm btn-primary" onclick="showAgentForm()"><?= ico('users',14) ?> Add Agent</button></div></div>
<div class="card-body np">
<table><thead><tr><th style="width:30px"></th><th>Name</th><th>Title</th><th>Bio Words</th><th>TREC</th><th>On Hub</th><th>Articles</th><th style="width:70px"></th></tr></thead>
<tbody id="roster-tbody">
<?php foreach ($roster as $agent): ?>
<tr class="roster-row" data-id="<?= $agent['id'] ?>" onclick="rosterToggle(<?= $agent['id'] ?>)" style="cursor:pointer">
<td><?= ico('edit',14) ?></td>
<td><strong><?= htmlspecialchars($agent['name']) ?></strong><br><span style="font-size:11px;color:var(--text-d)">/authors/<?= htmlspecialchars($agent['slug']) ?>/</span></td>
<td><?= htmlspecialchars($agent['title'] ?: '—') ?></td>
<td><?= $agent['bio_words'] ?></td>
<td><?= htmlspecialchars($agent['trec'] ?: '—') ?></td>
<td><?= $agent['on_hub'] ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-neutral">No</span>' ?></td>
<td><?= $agent['has_shortcode'] ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-warn">No</span>' ?></td>
<td><button class="btn btn-sm btn-secondary" onclick="event.stopPropagation();loadExistingAgent(<?= $agent['id'] ?>)" title="Edit all fields"><?= ico('edit',12) ?> Edit</button></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty"><p>Error loading roster: <?= htmlspecialchars($roster['error'] ?? 'Unknown error') ?></p></div></div></div>
<?php endif; ?>
</div>

<!-- ═══ LEAD DETAIL MODAL ═══ -->
<div class="modal-overlay" id="modal-lead-detail">
<div class="modal" style="max-width:600px">
<div class="modal-hdr">
<h3 id="lead-detail-title">Lead Detail</h3>
<button class="modal-close" onclick="closeModal('modal-lead-detail')">&times;</button>
</div>
<div class="modal-body" id="lead-detail-body" style="max-height:75vh;overflow-y:auto">
</div>
<div class="modal-foot">
<button class="btn btn-secondary" onclick="closeModal('modal-lead-detail')">Close</button>
</div>
</div>
</div>

<!-- ═══ ADD AGENT MODAL ═══ -->
<div class="modal-overlay" id="modal-agent">
<div class="modal" style="max-width:720px">
<div class="modal-hdr">
<h3 id="agent-form-title">Add Agent</h3>
<button class="modal-close" onclick="hideAgentForm()">&times;</button>
</div>
<div class="modal-body" style="max-height:75vh;overflow-y:auto">
<div id="agent-form-gaps" style="display:none" class="interlock-banner"></div>
<input type="hidden" id="af-existing-uid" value="">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
<div class="form-group"><label>First Name *</label><input class="input" id="af-first" oninput="afAutoNick()"></div>
<div class="form-group"><label>Last Name *</label><input class="input" id="af-last" oninput="afAutoNick()"></div>
<div class="form-group"><label>Email *</label><input class="input" id="af-email" type="email"></div>
<div class="form-group"><label>Cell / Direct Phone</label><input class="input" id="af-phone" type="tel" placeholder="(210) 555-1234"><small>Agent's personal number. Office (210) 879-8220 is added automatically.</small></div>
<div class="form-group"><label>Nicename (slug)</label><input class="input" id="af-nick" placeholder="auto-generated"><small>Used for URL + headshot filename</small></div>
<div class="form-group"><label>Role / Title *</label><input class="input" id="af-role" placeholder="REALTOR, Agent Mentor, etc."></div>
<div class="form-group"><label>TREC License #</label><input class="input" id="af-trec" placeholder="617273"></div>
<div class="form-group"><label>License Type</label><select class="input" id="af-trectype"><option value="Sales Agent">Sales Agent</option><option value="Broker">Broker</option></select></div>
<div class="form-group"><label>Role Type</label><select class="input" id="af-roletype"><option value="Specialist">Specialist</option><option value="Mentor">Agent Mentor</option><option value="Leadership">Leadership</option></select></div>
<div class="form-group"><label>Service Area</label><input class="input" id="af-area" value="San Antonio"></div>
<div class="form-group"><label>Lane</label><select class="input" id="af-lane" onchange="afAutoEyebrow()"><option value="" selected>— Select lane —</option><option value="Multilingual">Multilingual</option><option value="Veteran &amp; Military">Veteran &amp; Military</option><option value="First-Time Buyers">First-Time Buyers</option><option value="Home Selling">Home Selling</option><option value="Hill Country">Hill Country</option><option value="Agent Mentors">Agent Mentors</option><option value="Leadership">Leadership</option></select><small>Determines eyebrow text + hub placement. Leave blank for neutral.</small></div>
<div class="form-group"><label>Eyebrow Topic</label><input class="input" id="af-eyebrow" placeholder="auto from lane"><small>Shows after "LRG Realty &middot;" in hero</small></div>
</div>
<div class="form-group" style="margin-top:14px"><label>Bio *</label><textarea class="textarea" id="af-bio" rows="5" placeholder="Full bio paragraph..."></textarea></div>
<div class="form-group"><label>About-Section Subheading</label><input class="input" id="af-h2" placeholder="Real estate services in San Antonio"><small>Appears under "About [Name]" on their profile. Example: Real estate services in San Antonio.</small></div>
<div class="form-group"><label>Trust Badges</label><input class="input" id="af-badges" placeholder="Decade of Experience, Six Languages, International Clients"><small>Comma-separated. Shows in hero.</small></div>
<div class="form-group" style="margin-top:14px"><label>Languages Spoken</label>
<div id="af-languages-grid" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
<?php foreach(['Spanish','Persian/Farsi','Pashto','Urdu','Hindi','Gujarati','Portuguese','French','Kinyarwanda','Swahili','Arabic','Vietnamese','Chinese','Tagalog','German'] as $lang): ?>
<label style="display:flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;cursor:pointer;transition:all .15s"><input type="checkbox" class="af-lang-cb" value="<?= $lang ?>" style="width:14px;height:14px"> <?= $lang ?></label>
<?php endforeach; ?>
</div>
<input class="input" id="af-languages-other" placeholder="Other languages (comma-separated)" style="margin-top:4px"><small>Check known languages above and/or type additional ones. Populates the multilingual agents page.</small>
</div>
<div class="form-group" style="margin-top:14px"><label>TREC Verify URL</label><input class="input" id="af-trec-url" placeholder="https://www.trec.texas.gov/apps/license-holder-search/"><small>License lookup link for the TREC stamp on their profile page.</small></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
<div class="form-group"><label>LinkedIn URL</label><input class="input" id="af-linkedin" type="url"></div>
<div class="form-group"><label>Facebook URL</label><input class="input" id="af-facebook" type="url"></div>
<div class="form-group"><label>Instagram URL</label><input class="input" id="af-instagram" type="url"></div>
<div class="form-group"><label>TikTok URL</label><input class="input" id="af-tiktok" type="url"></div>
</div>
<div class="form-group" style="margin-top:14px"><label>Headshot</label><input type="file" id="af-headshot" accept="image/png,image/jpeg" onchange="afHeadshotPreview(this)" style="font-size:13px"><small>PNG or JPG. Will be saved as {nicename}.png</small><div id="af-headshot-preview" style="margin-top:8px"></div></div>
<div class="form-group"><label>Lane Keywords (for AUTHOR_LANE_MAP)</label><input class="input" id="af-lanekw" placeholder="boerne,hill country,kendall county"><small>Comma-separated keywords for auto-assigning articles. Leave blank if N/A.</small></div>
</div>
<div class="modal-foot" style="flex-wrap:wrap;gap:8px">
<span style="font-size:12px;color:var(--ok);display:flex;align-items:center;gap:6px;margin-right:auto"><?= ico('check',14) ?> Creates draft page for review</span>
<button class="btn btn-secondary" onclick="hideAgentForm()">Cancel</button>
<button class="btn btn-primary" onclick="submitAgentPreview()"><?= ico('check',14) ?> Preview All Layers</button>
</div>
</div>
</div>

<!-- ═══ AGENT PREVIEW MODAL ═══ -->
<div class="modal-overlay" id="modal-agent-preview">
<div class="modal" style="max-width:800px">
<div class="modal-hdr">
<h3>Provisioning Preview</h3>
<button class="modal-close" onclick="closeModal('modal-agent-preview')">&times;</button>
</div>
<div class="modal-body" style="max-height:75vh;overflow-y:auto" id="agent-preview-body">
</div>
<div class="modal-foot">
<span style="font-size:12px;color:var(--text-m);margin-right:auto">Page created as draft, pending review</span>
<button class="btn btn-secondary" onclick="closeModal('modal-agent-preview')">Close</button>
</div>
</div>
</div>

</main>

<!-- ═══ JAVASCRIPT ═══ -->
<script>
const _srcColors = ['#c8102e','#8b5cf6','#f59e0b','#10b981','#3b82f6','#ec4899','#6366f1','#14b8a6'];
const CLR = '#c8102e';

// Section toggle
document.querySelectorAll('.sb-nav a[data-section]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const name = a.dataset.section;
        document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
        document.getElementById('sec-' + name)?.classList.add('active');
        document.querySelectorAll('.sb-nav a').forEach(x => x.classList.remove('active'));
        a.classList.add('active');
        history.replaceState(null, '', '?page=' + name);
        document.querySelector('.sb')?.classList.remove('open');
        document.querySelector('.mob-overlay')?.classList.remove('show');
        if (name === 'leads' && !_leadsLoaded) loadLeads();
        if (name === 'command-center' && !_ccLoaded) ccFetch();
        if (name === 'worklog' && !_wlLoaded) wlFetch();
    });
});

// Theme toggle
function toggleTheme() {
    const html = document.documentElement;
    const current = html.getAttribute('data-theme');
    const next = current === 'light' ? 'dark' : 'light';
    html.setAttribute('data-theme', next);
    localStorage.setItem('lrg-dash-theme', next);
}
function toast(msg, type = 'success') {
    const div = document.createElement('div');
    div.className = 'toast toast-' + type;
    div.textContent = msg;
    document.getElementById('toasts').appendChild(div);
    setTimeout(() => div.remove(), 4000);
}
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function mpN(n) { return Number(n||0).toLocaleString(); }
function mpT(v) {
    if (v === null || v === undefined) return '<span class="mp-flat">—</span>';
    if (v > 0) return `<span class="mp-up">${v}%</span>`;
    if (v < 0) return `<span class="mp-dn">${Math.abs(v)}%</span>`;
    return '<span class="mp-flat">0%</span>';
}

// ═══ COMMAND CENTER ═══
let _ccLoaded = false;
let _ccRtI = null;
let _ccRange = '30d';
let _ccCache = {};

function ccFetch(force) {
    const range = _ccRange;
    if (!force && _ccCache[range]) { ccRender(_ccCache[range]); return; }
    const url = '?route=api&action=cc-data&range=' + range + (force ? '&refresh=1' : '');
    document.getElementById('cc-content').innerHTML = '<div class="mp-loading"><div class="mp-spinner"></div><div>Loading analytics...</div></div>';
    fetch(url).then(r=>r.json()).then(data => { _ccLoaded = true; _ccCache[range] = data; ccRender(data); }).catch(e => {
        document.getElementById('cc-content').innerHTML = '<div class="mp-error">Error: '+esc(e.message)+'</div>';
    });
}
function ccSwitchRange(range) {
    _ccRange = range;
    ccFetch(false);
}
function ccRefresh() {
    const btn = document.getElementById('cc-refresh-btn');
    btn.disabled = true; btn.textContent = 'Refreshing...';
    delete _ccCache[_ccRange];
    ccFetch(true);
    setTimeout(() => { btn.disabled = false; btn.innerHTML = '<?= ico("refresh",14) ?> Refresh'; }, 3000);
}

function ccRender(data) {
    const ga = data.ga4 || {};
    const gsc = data.gsc || {};
    const stats = data.stats || {};
    let h = '';

    if (ga.error) h += `<div class="mp-error"><strong>GA4 Unavailable:</strong> ${esc(ga.error)}</div>`;
    if (gsc.error) h += `<div class="mp-error"><strong>GSC Unavailable:</strong> ${esc(gsc.error)}</div>`;

    // Row 1: Live Now
    h += `<div class="mp-live"><span class="mp-live-dot"></span><span class="mp-live-lbl">LIVE</span><span class="mp-live-ct" id="cc-rt-ct">...</span><span style="font-size:13px;color:var(--text-m)">on lrgrealty.com</span><span class="mp-live-pg" id="cc-rt-pg"></span></div>`;

    // Row 2: KPI cards
    if (!ga.error) {
        h += `<div class="mp-hero">
            <div class="mp-card"><div class="mp-card-accent" style="background:${CLR}"></div><div class="mp-lbl">Organic Traffic</div><div class="mp-val">${mpN(ga.organic_sessions)}</div><div class="mp-sub">sessions ${mpT(ga.organic_trend)}</div></div>
            <div class="mp-card"><div class="mp-card-accent" style="background:#8b5cf6"></div><div class="mp-lbl">Users</div><div class="mp-val">${mpN(ga.users)}</div><div class="mp-sub">${esc(ga.range_label||'')} ${mpT(ga.users_trend)}</div></div>
            <div class="mp-card"><div class="mp-card-accent" style="background:#f59e0b"></div><div class="mp-lbl">Pageviews</div><div class="mp-val">${mpN(ga.pageviews)}</div><div class="mp-sub">${esc(ga.range_label||'')} ${mpT(ga.pageviews_trend)}</div></div>
            <div class="mp-card"><div class="mp-card-accent" style="background:#10b981"></div><div class="mp-lbl">Bounce Rate</div><div class="mp-val">${ga.bounce_rate||0}%</div><div class="mp-sub">Avg session ${Math.floor((ga.avg_duration||0)/60)}m ${Math.round((ga.avg_duration||0)%60)}s</div></div>
        </div>`;
    }

    // Row 3: YoY
    if (!ga.error) {
        const yoy = ga.yoy || {};
        const rangeLabel = ga.range_label || _ccRange;
        if (yoy.sessions_ly > 0 || yoy.users_ly > 0) {
            h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Year-over-Year Comparison</h3><span style="font-size:11px;color:var(--text-d)">Same period vs last year</span></div><div class="mp-panel-body"><div class="mp-yoy">
                <div class="mp-yoy-card"><div class="mp-lbl">Sessions YoY</div><div class="mp-yoy-val">${mpT(yoy.sessions_yoy)}</div><div class="mp-yoy-sub">${mpN(ga.sessions)} vs ${mpN(yoy.sessions_ly)}</div></div>
                <div class="mp-yoy-card"><div class="mp-lbl">Users YoY</div><div class="mp-yoy-val">${mpT(yoy.users_yoy)}</div><div class="mp-yoy-sub">${mpN(ga.users)} vs ${mpN(yoy.users_ly)}</div></div>
                <div class="mp-yoy-card"><div class="mp-lbl">Pageviews YoY</div><div class="mp-yoy-val">${mpT(yoy.pageviews_yoy)}</div><div class="mp-yoy-sub">${mpN(ga.pageviews)} vs ${mpN(yoy.pageviews_ly)}</div></div>
            </div></div></div>`;
        } else {
            h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Year-over-Year Comparison</h3></div><div class="mp-panel-body"><div style="text-align:center;padding:20px;color:var(--text-d);font-size:13px">Insufficient history for YoY comparison. GA4 data begins September 2025 — YoY available from September 2026.</div></div></div>`;
        }
    }

    // Row 4: GSC overview + ranking bars
    if (!gsc.error) {
        h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Search Console — Last 28 Days</h3>${gsc.fetched_at?'<span style="font-size:11px;color:var(--text-d)">'+esc(gsc.fetched_at)+'</span>':''}</div><div class="mp-panel-body">`;
        h += `<div class="mp-hero" style="margin-bottom:0">
            <div class="mp-card" style="border:none;padding:16px;box-shadow:none"><div class="mp-lbl">Clicks</div><div class="mp-val" style="font-size:26px;color:${CLR}">${mpN(gsc.clicks)}</div><div class="mp-sub">${mpT(gsc.clicks_trend)}</div></div>
            <div class="mp-card" style="border:none;padding:16px;box-shadow:none"><div class="mp-lbl">Impressions</div><div class="mp-val" style="font-size:26px">${mpN(gsc.impressions)}</div><div class="mp-sub">${mpT(gsc.impressions_trend)}</div></div>
            <div class="mp-card" style="border:none;padding:16px;box-shadow:none"><div class="mp-lbl">Avg CTR</div><div class="mp-val" style="font-size:26px">${gsc.avg_ctr||0}%</div></div>
            <div class="mp-card" style="border:none;padding:16px;box-shadow:none"><div class="mp-lbl">Avg Position</div><div class="mp-val" style="font-size:26px">${gsc.avg_position||0}</div></div>
        </div>`;
        const dist = gsc.position_distribution || {};
        const dClr = ['#10b981','#3b82f6','#f59e0b','#f97316','#ef4444'];
        const dK = Object.keys(dist);
        h += `<div class="mp-dist">`;
        dK.forEach((k,i) => { h += `<div class="mp-dist-bar" style="background:${dClr[i]}22;border:1px solid ${dClr[i]}44"><div class="mp-dist-n" style="color:${dClr[i]}">#${esc(k)}</div><div class="mp-dist-v" style="color:${dClr[i]}">${dist[k]}</div></div>`; });
        h += `</div></div></div>`;

        // Row 5: Daily Clicks chart
        if ((gsc.daily||[]).length) {
            h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Daily Clicks & Impressions</h3></div><div class="mp-panel-body"><div class="mp-chart"><canvas id="cc-gsc-chart"></canvas></div></div></div>`;
        }

        // Row 6: Top queries + pages
        h += `<div class="mp-grid2">`;
        h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Top Queries</h3></div><div class="mp-panel-body np">`;
        if ((gsc.top_queries||[]).length) {
            h += `<table class="mp-tbl"><thead><tr><th>Query</th><th>Clicks</th><th>Impr</th><th>CTR</th><th>Pos</th></tr></thead><tbody>`;
            for (const q of gsc.top_queries) h += `<tr><td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(q.keys?.[0]||'')}</td><td style="font-weight:600;color:${CLR}">${q.clicks}</td><td>${mpN(q.impressions)}</td><td>${(q.ctr*100).toFixed(1)}%</td><td>${q.position.toFixed(1)}</td></tr>`;
            h += `</tbody></table>`;
        } else h += `<div style="padding:24px;color:var(--text-d);text-align:center">No data</div>`;
        h += `</div></div>`;

        h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Top Pages</h3></div><div class="mp-panel-body np">`;
        if ((gsc.top_pages||[]).length) {
            h += `<table class="mp-tbl"><thead><tr><th>Page</th><th>Clicks</th><th>Impr</th><th>CTR</th><th>Pos</th></tr></thead><tbody>`;
            for (const p of gsc.top_pages) { const path=(p.keys?.[0]||'').replace(/^https?:\/\/[^/]+/,''); h += `<tr><td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.keys?.[0]||'')}">${esc(path||'/')}</td><td style="font-weight:600;color:${CLR}">${p.clicks}</td><td>${mpN(p.impressions)}</td><td>${(p.ctr*100).toFixed(1)}%</td><td>${p.position.toFixed(1)}</td></tr>`; }
            h += `</tbody></table>`;
        } else h += `<div style="padding:24px;color:var(--text-d);text-align:center">No data</div>`;
        h += `</div></div></div>`;
    }

    // Row 7+8: Traffic sources + landing pages
    if (!ga.error) {
        h += `<div class="mp-grid2">`;
        if ((ga.sources||[]).length) {
            const totalSess = ga.sources.reduce((s,x) => s+x.sessions, 0) || 1;
            h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Traffic Sources</h3></div><div class="mp-panel-body">`;
            ga.sources.forEach((s,i) => {
                const pct = Math.round(s.sessions/totalSess*100);
                const c = _srcColors[i%_srcColors.length];
                h += `<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:13px"><span style="font-weight:600">${esc(s.channel)}</span><span style="color:var(--text-m)">${mpN(s.sessions)} (${pct}%)</span></div><div style="background:var(--surface-el);border-radius:4px;height:8px;margin-top:4px;overflow:hidden"><div style="background:${c};height:100%;border-radius:4px;width:${pct}%;transition:width .6s ease"></div></div></div>`;
            });
            h += `</div></div>`;
        }
        if ((ga.top_landing||[]).length) {
            h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Top Landing Pages</h3></div><div class="mp-panel-body np"><table class="mp-tbl"><thead><tr><th>Page</th><th>Sessions</th><th>Users</th><th>Bounce</th></tr></thead><tbody>`;
            for (const p of ga.top_landing) h += `<tr><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.page)}">${esc(p.page)}</td><td>${mpN(p.sessions)}</td><td>${mpN(p.users)}</td><td>${p.bounce}%</td></tr>`;
            h += `</tbody></table></div></div>`;
        }
        h += `</div>`;

        // Row 9: Daily sessions chart
        if ((ga.daily||[]).length) {
            h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Daily Sessions — 28 Days</h3></div><div class="mp-panel-body"><div class="mp-chart"><canvas id="cc-ga4-chart"></canvas></div></div></div>`;
        }
    }

    // Content stats footer
    h += `<div style="display:flex;gap:24px;padding:12px 0;font-size:12px;color:var(--text-d)">`;
    h += `<span>${mpN(stats.posts||0)} published posts</span><span>${mpN(stats.pages||0)} pages</span><span>${stats.scheduled||0} scheduled</span><span>${mpN(stats.leads_total||0)} total leads (${mpN(stats.leads_30d||0)} last 30d)</span>`;
    h += `</div>`;

    document.getElementById('cc-content').innerHTML = h;

    // Render charts
    if (typeof Chart !== 'undefined') {
        if (!gsc.error && (gsc.daily||[]).length) ccGscChart(gsc.daily);
        if (!ga.error && (ga.daily||[]).length) ccGa4Chart(ga.daily);
    }
    ccStartRt();
}

function ccGscChart(daily) {
    const el = document.getElementById('cc-gsc-chart'); if (!el) return;
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    const grid = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const txt = isDark ? '#94a3b8' : '#64748b';
    new Chart(el, {type:'line',data:{labels:daily.map(d=>{const dt=new Date(d.date);return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'});}),datasets:[{label:'Clicks',data:daily.map(d=>d.clicks),borderColor:CLR,backgroundColor:CLR+'1a',fill:true,tension:.3,pointRadius:0,pointHitRadius:10,borderWidth:2,yAxisID:'y'},{label:'Impressions',data:daily.map(d=>d.impressions),borderColor:'#8b5cf6',backgroundColor:'rgba(139,92,246,.05)',fill:true,tension:.3,pointRadius:0,pointHitRadius:10,borderWidth:2,yAxisID:'y1'}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:txt,font:{size:11}}}},scales:{x:{grid:{color:grid},ticks:{color:txt,font:{size:10},maxTicksLimit:10}},y:{position:'left',grid:{color:grid},ticks:{color:CLR,font:{size:10}},title:{display:true,text:'Clicks',color:CLR,font:{size:11}}},y1:{position:'right',grid:{drawOnChartArea:false},ticks:{color:'#8b5cf6',font:{size:10}},title:{display:true,text:'Impressions',color:'#8b5cf6',font:{size:11}}}}}});
}

function ccGa4Chart(daily) {
    const el = document.getElementById('cc-ga4-chart'); if (!el) return;
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    const grid = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const txt = isDark ? '#94a3b8' : '#64748b';
    new Chart(el, {type:'line',data:{labels:daily.map(d=>{const dt=d.date;return dt.substring(4,6)+'/'+dt.substring(6,8);}),datasets:[{label:'Sessions',data:daily.map(d=>d.sessions),borderColor:CLR,backgroundColor:CLR+'1a',fill:true,tension:.3,pointRadius:0,pointHitRadius:10,borderWidth:2},{label:'Users',data:daily.map(d=>d.users),borderColor:'#8b5cf6',backgroundColor:'rgba(139,92,246,.05)',fill:true,tension:.3,pointRadius:0,pointHitRadius:10,borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:txt,font:{size:11}}}},scales:{x:{grid:{color:grid},ticks:{color:txt,font:{size:10},maxTicksLimit:10}},y:{grid:{color:grid},ticks:{color:txt,font:{size:10}}}}}});
}

function ccStartRt() {
    if (_ccRtI) clearInterval(_ccRtI);
    const poll = () => { fetch('?route=api&action=cc-realtime').then(r=>r.json()).then(d=>{const c=document.getElementById('cc-rt-ct');const p=document.getElementById('cc-rt-pg');if(c)c.textContent=d.users||0;if(p&&d.pages)p.innerHTML=d.pages.slice(0,3).map(x=>'<strong>'+esc(x.page)+'</strong> ('+x.users+')').join(', ');}).catch(()=>{}); };
    poll();
    _ccRtI = setInterval(poll, 120000);
}

// ═══ LEADS ═══
let _leadsLoaded = false;

function loadLeads(page = 1) {
    const container = document.getElementById('leads-content');
    container.innerHTML = '<div class="empty"><p>Loading...</p></div>';
    fetch('?route=api&action=leads&p=' + page).then(r=>r.json()).then(data => {
        if (data.error) { container.innerHTML = '<div class="card"><div class="card-body"><div class="empty"><p>'+esc(data.error)+'</p></div></div></div>'; return; }
        _leadsLoaded = true;
        let html = '<div class="card"><div class="card-hdr"><h2>Leads ('+data.total+' total)</h2></div><div class="card-body np">';
        if (data.rows.length === 0) { html += '<div class="empty"><p>No leads found</p></div>'; }
        else {
            html += '<table><thead><tr><th>Date</th><th>Name</th><th>Email</th><th>Phone</th><th>Source</th><th>FUB</th></tr></thead><tbody>';
            data.rows.forEach(r => {
                const d = new Date(r.post_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
                html += '<tr class="lead-row" onclick="showLeadDetail('+r.ID+')">';
                html += '<td>'+esc(d)+'</td><td>'+esc(r.post_title||'—')+'</td><td>'+esc(r.email||'—')+'</td><td>'+esc(r.phone||'—')+'</td>';
                html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(r.source||'—')+'</td>';
                html += '<td>'+(r.fub_synced?'<span class="badge badge-ok">FUB #'+esc(r.fub_synced)+'</span>':'<span class="badge badge-neutral">—</span>')+'</td></tr>';
            });
            html += '</tbody></table>';
        }
        html += '</div></div>';
        if (data.pages > 1) {
            html += '<div class="pag">';
            html += '<button '+(page<=1?'disabled':'onclick="loadLeads('+(page-1)+')"')+'>&laquo;</button>';
            for (let i=1;i<=data.pages;i++) {
                if (data.pages>10&&Math.abs(i-page)>3&&i!==1&&i!==data.pages){if(i===page-4||i===page+4)html+='<button disabled>...</button>';continue;}
                html += '<button class="'+(i===page?'active':'')+'" onclick="loadLeads('+i+')">'+i+'</button>';
            }
            html += '<button '+(page>=data.pages?'disabled':'onclick="loadLeads('+(page+1)+')"')+'>&raquo;</button></div>';
        }
        container.innerHTML = html;
    }).catch(err => { container.innerHTML = '<div class="card"><div class="card-body"><div class="empty"><p>Error: '+esc(err.message)+'</p></div></div></div>'; });
}

function showLeadDetail(id) {
    const body = document.getElementById('lead-detail-body');
    body.innerHTML = '<div class="empty"><p>Loading...</p></div>';
    document.getElementById('lead-detail-title').textContent = 'Lead #' + id;
    openModal('modal-lead-detail');
    fetch('?route=api&action=lead-detail&id='+id).then(r=>r.json()).then(data => {
        if (!data.ok) { body.innerHTML = '<div style="color:var(--err)">'+esc(data.error)+'</div>'; return; }
        let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;font-size:13px;margin-bottom:20px">';
        html += '<div><strong style="color:var(--text-m)">Name</strong><br>'+esc(data.title)+'</div>';
        html += '<div><strong style="color:var(--text-m)">Date</strong><br>'+esc(data.date)+'</div>';
        const m = data.meta || {};
        const fields = [['First Name','_rss_lf_firstname'],['Last Name','_rss_lf_lastname'],['Email','_rss_lf_email'],['Phone','_rss_lf_phone'],['Source Path','_rss_lf_path'],['Message','_rss_lf_message'],['Referrer','_rss_lf_referrer'],['Ref Param','_rss_lf_ref_param'],['FUB Person ID','_fub_person_id'],['IP Address','_rss_lf_ip'],['User Agent','_rss_lf_user_agent'],['Submitted At','_rss_lf_submitted_at']];
        fields.forEach(([label,key]) => { const v = m[key]; if (v) html += '<div><strong style="color:var(--text-m)">'+esc(label)+'</strong><br>'+esc(v)+'</div>'; });
        html += '</div>';
        if (m._rss_lf_answers) {
            html += '<div style="margin-top:12px"><strong style="color:var(--text-m)">Form Answers</strong><pre style="margin-top:4px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);font-size:12px;white-space:pre-wrap">'+esc(m._rss_lf_answers)+'</pre></div>';
        }
        body.innerHTML = html;
    }).catch(err => { body.innerHTML = '<div style="color:var(--err)">Error: '+esc(err.message)+'</div>'; });
}

// ═══ WORK VELOCITY ═══
let _wlLoaded = false;
const _catColors = {content:'#10b981',infra:'#3b82f6',build:'#8b5cf6',fix:'#f59e0b',seo:'#ec4899',deploy:'#14b8a6',audit:'#6366f1'};

function wlFetch() {
    const el = document.getElementById('wl-content');
    el.innerHTML = '<div class="mp-loading"><div class="mp-spinner"></div><div>Loading...</div></div>';
    fetch('?route=api&action=worklog-list&days=30').then(r=>r.json()).then(data => {
        if (!data.ok) { el.innerHTML = '<div class="mp-error">'+esc(data.error)+'</div>'; return; }
        _wlLoaded = true;
        wlRender(data, el);
    }).catch(e => { el.innerHTML = '<div class="mp-error">Error: '+esc(e.message)+'</div>'; });
}
function wlRefresh() { _wlLoaded = false; wlFetch(); }

function wlRender(data, el) {
    const s = data.stats || {};
    let h = '';
    // Stat cards
    const wkTrend = s.last_week > 0 ? Math.round((s.this_week - s.last_week) / s.last_week * 100) : 0;
    h += `<div class="mp-hero">
        <div class="mp-card"><div class="mp-card-accent" style="background:${CLR}"></div><div class="mp-lbl">Total (30d)</div><div class="mp-val">${s.total||0}</div><div class="mp-sub">items logged</div></div>
        <div class="mp-card"><div class="mp-card-accent" style="background:#10b981"></div><div class="mp-lbl">Posts Published</div><div class="mp-val">${s.posts||0}</div><div class="mp-sub">auto-tracked</div></div>
        <div class="mp-card"><div class="mp-card-accent" style="background:#8b5cf6"></div><div class="mp-lbl">Infra / Build</div><div class="mp-val">${s.manual||0}</div><div class="mp-sub">manually logged</div></div>
        <div class="mp-card"><div class="mp-card-accent" style="background:#f59e0b"></div><div class="mp-lbl">This Week</div><div class="mp-val">${s.this_week||0}</div><div class="mp-sub">vs ${s.last_week||0} last week ${wkTrend > 0 ? '<span class="mp-up">'+wkTrend+'%</span>' : wkTrend < 0 ? '<span class="mp-dn">'+Math.abs(wkTrend)+'%</span>' : ''}</div></div>
    </div>`;

    // Velocity chart
    const dc = data.daily_counts || [];
    if (dc.length > 0) {
        h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Daily Velocity</h3></div><div class="mp-panel-body"><div style="display:flex;align-items:flex-end;gap:3px;height:120px">`;
        const maxC = Math.max(...dc.map(d=>d.count), 1);
        dc.forEach(d => {
            const ht = Math.max(4, Math.round(d.count / maxC * 100));
            const day = new Date(d.date + 'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'});
            const pPct = d.count > 0 ? Math.round(d.posts / d.count * 100) : 0;
            h += `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px" title="${d.date}: ${d.count} items (${d.posts} posts, ${d.manual} manual)">
                <div style="font-size:10px;color:var(--text-d);font-weight:600">${d.count||''}</div>
                <div style="width:100%;height:${ht}px;border-radius:3px 3px 0 0;overflow:hidden;display:flex;flex-direction:column">
                    <div style="flex:${d.posts};background:#10b981"></div>
                    <div style="flex:${d.manual||0.001};background:#8b5cf6"></div>
                </div>
                <span style="font-size:9px;color:var(--text-d)">${day.split(' ')[1]}</span>
            </div>`;
        });
        h += `</div><div style="display:flex;gap:16px;margin-top:10px;font-size:11px;color:var(--text-m)"><span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#10b981;vertical-align:middle;margin-right:4px"></span>Posts</span><span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#8b5cf6;vertical-align:middle;margin-right:4px"></span>Manual</span></div></div></div>`;
    }

    // Timeline by day
    h += `<div class="mp-panel"><div class="mp-panel-hdr"><h3>Work Log</h3></div><div class="mp-panel-body np">`;
    const days = data.days || {};
    const dayKeys = Object.keys(days);
    if (dayKeys.length === 0) {
        h += '<div style="padding:40px;text-align:center;color:var(--text-d)">No work logged yet.</div>';
    } else {
        dayKeys.forEach(date => {
            const items = days[date];
            const dayLabel = new Date(date + 'T12:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
            h += `<div style="padding:12px 20px;background:var(--surface-el);border-bottom:1px solid var(--border);font-size:12px;font-weight:700;color:var(--text-m);display:flex;justify-content:space-between">${esc(dayLabel)}<span style="font-weight:400">${items.length} item${items.length>1?'s':''}</span></div>`;
            items.forEach(item => {
                const cc = _catColors[item.category] || '#94a3b8';
                const icon = item.type === 'post' ? '📄' : '🔧';
                h += `<div style="padding:10px 20px;border-bottom:1px solid var(--border-sub);display:flex;align-items:flex-start;gap:10px">
                    <span style="font-size:14px">${icon}</span>
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <span style="background:${cc}22;color:${cc};padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase">${esc(item.category)}</span>
                            <span style="font-size:13px;font-weight:600">${esc(item.title)}</span>
                        </div>
                        ${item.description ? '<div style="font-size:12px;color:var(--text-m);margin-top:3px">'+esc(item.description)+'</div>' : ''}
                    </div>
                    <span style="font-size:10px;color:var(--text-d);white-space:nowrap">${esc(item.source)}</span>
                </div>`;
            });
        });
    }
    h += '</div></div>';
    el.innerHTML = h;
}

function wlShowAdd() {
    document.getElementById('wl-title').value = '';
    document.getElementById('wl-desc').value = '';
    document.getElementById('wl-date').value = new Date().toISOString().split('T')[0];
    openModal('modal-wl-add');
}

function wlSubmitAdd() {
    const data = {
        category: document.getElementById('wl-cat').value,
        title: document.getElementById('wl-title').value.trim(),
        description: document.getElementById('wl-desc').value.trim(),
        work_date: document.getElementById('wl-date').value,
        source: 'manual',
    };
    if (!data.title) { toast('Title is required', 'error'); return; }
    fetch('?route=api&action=worklog-add', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
    .then(r=>r.json()).then(result => {
        if (!result.ok) { toast(result.error || 'Failed', 'error'); return; }
        toast('Entry logged');
        closeModal('modal-wl-add');
        wlRefresh();
    }).catch(e => toast('Error: '+e.message, 'error'));
}

// ═══ AGENT ROSTER ═══
function showAgentForm() {
    document.getElementById('agent-form-title').textContent = 'Add Agent';
    document.getElementById('af-existing-uid').value = '';
    document.getElementById('agent-form-gaps').style.display = 'none';
    ['af-first','af-last','af-email','af-phone','af-nick','af-role','af-trec','af-bio','af-h2','af-badges','af-linkedin','af-facebook','af-instagram','af-tiktok','af-lanekw','af-eyebrow','af-languages-other','af-trec-url'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    document.querySelectorAll('.af-lang-cb').forEach(cb => cb.checked = false);
    document.getElementById('af-area').value = 'San Antonio';
    document.getElementById('af-trec-url').value = 'https://www.trec.texas.gov/apps/license-holder-search/';
    document.getElementById('af-headshot-preview').innerHTML = '';
    openModal('modal-agent');
}
function hideAgentForm() { closeModal('modal-agent'); }
function afAutoNick() {
    const f = document.getElementById('af-first').value.trim().toLowerCase();
    const l = document.getElementById('af-last').value.trim().toLowerCase();
    if (f && l) document.getElementById('af-nick').value = (f+'-'+l).replace(/[^a-z0-9-]/g,'').replace(/-+/g,'-');
}
function afAutoEyebrow() { document.getElementById('af-eyebrow').value = document.getElementById('af-lane').value; }
function afHeadshotPreview(input) {
    const preview = document.getElementById('af-headshot-preview');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 5*1024*1024) { preview.innerHTML = '<span style="color:var(--err)">File too large (max 5MB)</span>'; input.value=''; return; }
        if (!file.type.match(/^image\/(png|jpeg)$/)) { preview.innerHTML = '<span style="color:var(--err)">Must be PNG or JPG</span>'; input.value=''; return; }
        const nick = document.getElementById('af-nick').value || 'agent';
        preview.innerHTML = '<span style="color:var(--ok)">Valid: '+esc(file.name)+' ('+Math.round(file.size/1024)+'KB) → '+esc(nick)+'.png</span>';
    }
}

function loadExistingAgent(pageId) {
    const uidMap = <?= json_encode(array_flip(lrg_get_agent_page_map())) ?>;
    const uid = uidMap[pageId];
    if (!uid) { toast('Unknown page ID '+pageId, 'error'); return; }
    fetch('?route=api&action=agent-load&uid='+uid).then(r=>r.json()).then(data => {
        if (!data.ok) { toast(data.error||'Load failed', 'error'); return; }
        document.getElementById('agent-form-title').textContent = 'Edit Agent: ' + data.display_name;
        document.getElementById('af-existing-uid').value = data.user_id;
        document.getElementById('af-first').value = data.first_name || '';
        document.getElementById('af-last').value = data.last_name || '';
        document.getElementById('af-email').value = data.page_email || data.email || '';
        document.getElementById('af-phone').value = data.phone || '';
        document.getElementById('af-nick').value = data.nicename || '';
        document.getElementById('af-role').value = data.role_title || '';
        document.getElementById('af-trec').value = data.trec || '';
        document.getElementById('af-bio').value = data.bio || '';
        document.getElementById('af-area').value = data.area || 'San Antonio';
        document.getElementById('af-eyebrow').value = data.eyebrow || '';
        document.getElementById('af-h2').value = data.h2_text || '';
        document.getElementById('af-badges').value = data.trust_badges || '';
        document.getElementById('af-linkedin').value = data.linkedin || '';
        document.getElementById('af-facebook').value = data.facebook || '';
        document.getElementById('af-instagram').value = data.instagram || '';
        document.getElementById('af-tiktok').value = data.tiktok || '';
        document.getElementById('af-lanekw').value = data.lane_keywords || '';
        document.getElementById('af-trec-url').value = data.trec_url || 'https://www.trec.texas.gov/apps/license-holder-search/';
        // Pre-fill languages
        const agentLangs = (data.languages || '').split(',').map(l => l.trim()).filter(Boolean);
        document.querySelectorAll('.af-lang-cb').forEach(cb => { cb.checked = agentLangs.includes(cb.value); });
        const knownLangs = Array.from(document.querySelectorAll('.af-lang-cb')).map(cb => cb.value);
        const otherLangs = agentLangs.filter(l => !knownLangs.includes(l));
        document.getElementById('af-languages-other').value = otherLangs.join(', ');
        document.getElementById('af-headshot-preview').innerHTML = data.headshot_in_page ? '<span style="color:var(--ok)">Headshot exists on page</span>' : '<span style="color:var(--warn)">No headshot on page</span>';
        const gapsDiv = document.getElementById('agent-form-gaps');
        if (data.gaps && data.gaps.length > 0) {
            gapsDiv.style.display = 'flex';
            gapsDiv.innerHTML = '<div><strong>'+data.gap_count+' gap'+(data.gap_count>1?'s':'')+' detected:</strong><ul style="margin:6px 0 0 16px;font-size:12px">'+data.gaps.map(g=>'<li>'+esc(g)+'</li>').join('')+'</ul></div>';
        } else { gapsDiv.style.display = 'none'; }
        openModal('modal-agent');
    }).catch(err => toast('Error: '+err.message, 'error'));
}

function submitAgentPreview() {
    const data = {
        first_name: document.getElementById('af-first').value.trim(),
        last_name: document.getElementById('af-last').value.trim(),
        email: document.getElementById('af-email').value.trim(),
        phone: document.getElementById('af-phone').value.trim(),
        nicename: document.getElementById('af-nick').value.trim(),
        role_title: document.getElementById('af-role').value.trim(),
        trec: document.getElementById('af-trec').value.trim(),
        trec_type: document.getElementById('af-trectype').value,
        role_type: document.getElementById('af-roletype').value,
        area: document.getElementById('af-area').value.trim(),
        lane: document.getElementById('af-lane').value,
        eyebrow: document.getElementById('af-eyebrow').value.trim() || document.getElementById('af-lane').value,
        bio: document.getElementById('af-bio').value.trim(),
        h2_text: document.getElementById('af-h2').value.trim(),
        trust_badges: document.getElementById('af-badges').value.trim(),
        linkedin: document.getElementById('af-linkedin').value.trim(),
        facebook: document.getElementById('af-facebook').value.trim(),
        instagram: document.getElementById('af-instagram').value.trim(),
        tiktok: document.getElementById('af-tiktok').value.trim(),
        lane_keywords: document.getElementById('af-lanekw').value.trim(),
        trec_url: document.getElementById('af-trec-url').value.trim(),
        languages: (function() {
            const checked = Array.from(document.querySelectorAll('.af-lang-cb:checked')).map(cb => cb.value);
            const other = document.getElementById('af-languages-other').value.split(',').map(s => s.trim()).filter(Boolean);
            return [...checked, ...other].join(', ');
        })(),
    };
    const existingUid = document.getElementById('af-existing-uid').value;
    if (existingUid) data.existing_user_id = parseInt(existingUid);

    const body = document.getElementById('agent-preview-body');
    body.innerHTML = '<div class="empty"><p>Computing preview...</p></div>';
    openModal('modal-agent-preview');
    fetch('?route=api&action=agent-preview',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}).then(r=>r.json()).then(result => {
        if (!result.ok) {
            let errHtml = '<div style="color:var(--err)">';
            if (result.errors) { errHtml += '<strong>Validation errors:</strong><ul style="margin:8px 0 0 16px">'; result.errors.forEach(e => errHtml += '<li>'+esc(e)+'</li>'); errHtml += '</ul>'; }
            else errHtml += esc(result.error||'Unknown error');
            body.innerHTML = errHtml + '</div>'; return;
        }
        let html = '<div style="margin-bottom:16px"><strong style="font-size:15px">'+esc(result.summary)+'</strong>';
        html += '<div style="font-size:12px;color:var(--warn);margin-top:4px">Mode: '+esc(result.mode)+' | Writes: '+(result.write_enabled?'<span style="color:var(--ok)">ENABLED</span>':'<span style="color:var(--err)">DISABLED</span>')+'</div></div>';
        result.layers.forEach((layer,i) => {
            const ac = layer.action.startsWith('CREATE')?'var(--ok)':layer.action.startsWith('SKIP')?'var(--text-d)':'var(--info)';
            html += '<div class="layer-card"><h4>Layer '+(i+1)+': '+esc(layer.name)+' <span class="action" style="background:'+ac+';color:#fff">'+esc(layer.action)+'</span></h4>';
            if (layer.html_preview) html += '<pre>'+esc(layer.html_preview)+'</pre>';
            else if (typeof layer.data==='object') html += '<pre>'+esc(JSON.stringify(layer.data,null,2))+'</pre>';
            else html += '<pre>'+esc(String(layer.data))+'</pre>';
            html += '</div>';
        });
        if (result.page_html_full) {
            html += '<details style="margin-top:16px"><summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-m)">Full Page HTML ('+result.page_html_full.length+' chars)</summary>';
            html += '<pre style="max-height:300px;overflow-y:auto;margin-top:8px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);font-size:11px;color:var(--text-d)">'+esc(result.page_html_full)+'</pre></details>';
        }
        body.innerHTML = html;
    }).catch(err => { body.innerHTML = '<div style="color:var(--err)">Error: '+esc(err.message)+'</div>'; });
}

// ═══ INIT ═══
if (document.querySelector('#sec-leads.active')) loadLeads();
if (document.querySelector('#sec-command-center.active')) ccFetch();
if (document.querySelector('#sec-worklog.active')) wlFetch();
</script>

</body>
</html>
<?php
}

// ═══════════════════════════════════════════════════════════
// ROUTING (after all functions are defined)
// ═══════════════════════════════════════════════════════════

// ─── AGENT REVIEW ROUTES ───

// Handle review link (?review=TOKEN)
if (isset($_GET['review']) && strlen($_GET['review']) === 64) {
    $review_info = lrg_handle_review_login($_GET['review']);
    if ($review_info) {
        header('Location: ' . $base_path . '?page=review');
        exit;
    } else {
        $loginError = 'This review link is invalid or has expired.';
    }
}

// Review API (annotate endpoint — scoped to reviewer's post)
if (($_GET['route'] ?? '') === 'review-api') {
    header('Content-Type: application/json');
    $scope = lrg_get_review_session();
    if (!$scope) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated as reviewer']);
        exit;
    }
    $action = $_GET['action'] ?? '';
    if ($action === 'annotate') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }
        echo json_encode(lrg_save_annotation($scope['post_id'], $scope['user_id'], $data));
        exit;
    }
    if ($action === 'annotations') {
        echo json_encode(['ok' => true, 'annotations' => lrg_get_annotations($scope['post_id'])]);
        exit;
    }
    if ($action === 'send-email') {
        $sent = lrg_email_feedback($scope['post_id'], $scope['user_id']);
        echo json_encode(['ok' => true, 'emailed' => $sent]);
        exit;
    }
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Review page render (scoped session)
if (($_GET['page'] ?? '') === 'review') {
    $scope = lrg_get_review_session();
    if ($scope) {
        // Agent reviewer — render ONLY their article, block everything else
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        lrg_render_review_page($scope['post_id'], $scope['user_id']);
        exit;
    }
    // Also let operators view annotations
    $lrg_current_user = lrg_get_current_user();
    if ($lrg_current_user && $lrg_current_user['role'] === 'operator' && isset($_GET['post'])) {
        $post_id = (int)$_GET['post'];
        lrg_render_review_page($post_id, (int)(get_post_field('post_author', $post_id) ?? 0));
        exit;
    }
}

// Handle permanent login key (?key=TOKEN)
if (isset($_GET['key']) && strlen($_GET['key']) === 64) {
    $pkey = $_GET['key'];
    $pkey_emails = defined('LRG_PERMANENT_LOGIN_KEYS') ? LRG_PERMANENT_LOGIN_KEYS : [];
    if (isset($pkey_emails[$pkey])) {
        $pkey_email = $pkey_emails[$pkey];
        $allowed = LRG_DASHBOARD_ALLOWED_EMAILS;
        if (isset($allowed[$pkey_email])) {
            // Create session (same as magic link)
            $session_token = lrg_generate_token();
            $pdo = lrg_get_pdo();
            $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';
            $stmt = $pdo->prepare("INSERT INTO $table (email, token, purpose, expires_at, ip_address, user_agent) VALUES (?, ?, 'session', DATE_ADD(NOW(), INTERVAL " . LRG_SESSION_TOKEN_EXPIRY . " SECOND), ?, ?)");
            $stmt->execute([$pkey_email, $session_token, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
            setcookie('LRG_DASH_SESSION', $session_token, [
                'expires' => time() + LRG_SESSION_TOKEN_EXPIRY,
                'path' => '/',
                'httponly' => true,
                'secure' => true,
                'samesite' => 'Lax',
            ]);
            setcookie('wordpress_lrg_dash', '1', [
                'expires' => time() + LRG_SESSION_TOKEN_EXPIRY,
                'path' => '/',
                'secure' => true,
                'samesite' => 'Lax',
            ]);
            $user = $allowed[$pkey_email];
            lrg_auth_log("PKEY_LOGIN email=$pkey_email name={$user['name']}");
            header('Location: ' . $base_path . '?page=' . $user['default_page']);
            exit;
        }
    }
    // Invalid key — fall through to login page
    $loginError = 'Invalid login key.';
}

// Handle magic link click (?auth=TOKEN)
if (isset($_GET['auth']) && strlen($_GET['auth']) === 64) {
    $auth_result = lrg_handle_magic_link($_GET['auth'], $base_path);
    if ($auth_result) {
        header('Location: ' . $base_path . '?page=' . $auth_result['default_page']);
        exit;
    } else {
        $loginError = 'This login link is invalid or has expired. Please request a new one.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    lrg_handle_logout();
    header('Location: ' . $base_path);
    exit;
}

// Check session
$lrg_current_user = lrg_get_current_user();

// Handle login form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_email']) && !$lrg_current_user) {
    $email = strtolower(trim($_POST['login_email']));
    $result = lrg_send_magic_link($email, $base_path);
    if ($result['success']) {
        $loginMessage = 'Check your inbox — login link sent.';
    } elseif (!empty($result['rate_limited'])) {
        $loginError = 'Too many login attempts. Try again later.';
    } else {
        $loginMessage = 'If this email is registered, a login link has been sent.';
    }
}

// API route (must be authenticated)
if (($_GET['route'] ?? '') === 'api') {
    header('Content-Type: application/json');
    if (!$lrg_current_user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'leads') {
        $page = max(1, (int)($_GET['p'] ?? 1));
        echo json_encode(lrg_get_leads($page));
        exit;
    }

    if ($action === 'roster-diff') {
        $data = json_decode(file_get_contents('php://input'), true);
        echo json_encode(lrg_roster_compute_diff($data ?: []));
        exit;
    }

    if ($action === 'roster-save') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }
        $data['_created_by'] = $lrg_current_user['name'] ?? 'unknown';
        echo json_encode(lrg_agent_execute_provision($data));
        exit;
    }

    if ($action === 'review-queue') {
        echo json_encode(lrg_get_review_queue());
        exit;
    }

    if ($action === 'approve-agent') {
        $qid = (int)($_GET['qid'] ?? 0);
        if (!$qid) { echo json_encode(['ok' => false, 'error' => 'Missing qid']); exit; }
        echo json_encode(lrg_approve_agent($qid, $lrg_current_user['name'] ?? 'unknown'));
        exit;
    }

    if ($action === 'reject-agent') {
        $qid = (int)($_GET['qid'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        $note = $data['note'] ?? '';
        if (!$qid) { echo json_encode(['ok' => false, 'error' => 'Missing qid']); exit; }
        echo json_encode(lrg_reject_agent($qid, $lrg_current_user['name'] ?? 'unknown', $note));
        exit;
    }

    if ($action === 'agent-load') {
        $uid = (int)($_GET['uid'] ?? 0);
        if (!$uid) { echo json_encode(['ok' => false, 'error' => 'Missing uid']); exit; }
        echo json_encode(lrg_load_existing_agent($uid));
        exit;
    }

    if ($action === 'agent-preview') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }
        echo json_encode(lrg_agent_preview($data));
        exit;
    }

    if ($action === 'cc-data') {
        $force = isset($_GET['refresh']);
        $range = in_array($_GET['range'] ?? '30d', ['7d','30d','90d']) ? $_GET['range'] : '30d';
        $ga4 = lrg_cc_get_ga4_data($range, $force);
        $gsc = lrg_cc_get_gsc_data($range, $force);
        $stats = lrg_get_content_stats();
        echo json_encode(['ga4' => $ga4, 'gsc' => $gsc, 'stats' => $stats, 'range' => $range]);
        exit;
    }

    if ($action === 'cc-realtime') {
        echo json_encode(lrg_cc_get_realtime());
        exit;
    }

    if ($action === 'review-annotations' || $action === 'agent-feedback') {
        $pid = (int)($_GET['post'] ?? $_GET['post_id'] ?? 0);
        if (!$pid) { echo json_encode(['ok' => false, 'error' => 'Missing post/post_id']); exit; }
        echo json_encode(['ok' => true, 'post_id' => $pid, 'annotations' => lrg_get_annotations($pid)]);
        exit;
    }

    if ($action === 'create-review-token') {
        $uid = (int)($_GET['uid'] ?? 0);
        $pid = (int)($_GET['post'] ?? 0);
        if (!$uid || !$pid) { echo json_encode(['ok' => false, 'error' => 'Missing uid or post']); exit; }
        $token = lrg_create_review_token($uid, $pid);
        echo json_encode(['ok' => true, 'token' => $token, 'url' => 'https://lrgrealty.com/dashboard/?review=' . $token]);
        exit;
    }

    if ($action === 'worklog-list') {
        $days = min(90, max(7, (int)($_GET['days'] ?? 30)));
        echo json_encode(lrg_worklog_list($days));
        exit;
    }

    if ($action === 'worklog-add') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }
        echo json_encode(lrg_worklog_add($data));
        exit;
    }

    if ($action === 'lead-detail') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); exit; }
        echo json_encode(lrg_get_lead_detail($id));
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Show login or dashboard
if (!$lrg_current_user) {
    showLogin($loginError ?? null, $loginMessage ?? null);
    exit;
}

$lrg_effective_role = $lrg_current_user['role'];

// Pre-fetch data for server-side rendered tabs (SC + Analytics only)
$_page = $_GET['page'] ?? 'command-center';
$_content_stats = lrg_get_content_stats();
$_gsc_data = ($_page === 'search-console') ? lrg_get_gsc_data() : [];
$_ga4_data = ($_page === 'analytics') ? lrg_get_ga4_data() : [];

showDashboard();
