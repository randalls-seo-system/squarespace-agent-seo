<?php
/**
 * LRG Dashboard — Work Velocity Log
 * Tracks content output (auto from WP posts) and infra/build work (manual entries).
 */

/**
 * Get work log entries (manual) + auto-derived published posts, merged by date.
 */
function lrg_worklog_list(int $days = 30): array {
    try {
        $pdo = lrg_get_pdo();
        $since = date('Y-m-d', strtotime("-{$days} days"));

        // Manual entries
        $stmt = $pdo->prepare("SELECT id, work_date, category, title, description, source, created_at FROM wp_lrg_work_log WHERE work_date >= ? ORDER BY work_date DESC, id DESC");
        $stmt->execute([$since]);
        $manual = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Auto: published posts by date
        $stmt = $pdo->prepare("
            SELECT DATE(p.post_date) as work_date, p.post_title, p.post_name, p.ID,
                   u.display_name as author
            FROM wp_posts p
            LEFT JOIN wp_users u ON p.post_author = u.ID
            WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_date >= ?
            ORDER BY p.post_date DESC
        ");
        $stmt->execute([$since]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Merge into a day-grouped structure
        $days_map = [];

        foreach ($manual as $m) {
            $d = $m['work_date'];
            if (!isset($days_map[$d])) $days_map[$d] = [];
            $days_map[$d][] = [
                'type' => 'manual',
                'id' => (int)$m['id'],
                'category' => $m['category'],
                'title' => $m['title'],
                'description' => $m['description'],
                'source' => $m['source'],
            ];
        }

        foreach ($posts as $p) {
            $d = $p['work_date'];
            if (!isset($days_map[$d])) $days_map[$d] = [];
            $days_map[$d][] = [
                'type' => 'post',
                'id' => (int)$p['ID'],
                'category' => 'content',
                'title' => $p['post_title'],
                'description' => 'Published by ' . ($p['author'] ?: 'Unknown'),
                'source' => 'auto',
            ];
        }

        // Sort by date desc
        krsort($days_map);

        // Stats
        $total_posts = count($posts);
        $total_manual = count($manual);
        $total = $total_posts + $total_manual;

        // Per-day counts for velocity chart
        $daily_counts = [];
        foreach ($days_map as $date => $items) {
            $daily_counts[] = ['date' => $date, 'count' => count($items), 'posts' => 0, 'manual' => 0];
            foreach ($items as $item) {
                $idx = count($daily_counts) - 1;
                if ($item['type'] === 'post') $daily_counts[$idx]['posts']++;
                else $daily_counts[$idx]['manual']++;
            }
        }

        // This week vs last week
        $this_week_start = date('Y-m-d', strtotime('monday this week'));
        $last_week_start = date('Y-m-d', strtotime('monday last week'));
        $last_week_end = date('Y-m-d', strtotime('sunday last week'));
        $this_week = 0;
        $last_week = 0;
        foreach ($days_map as $date => $items) {
            if ($date >= $this_week_start) $this_week += count($items);
            elseif ($date >= $last_week_start && $date <= $last_week_end) $last_week += count($items);
        }

        return [
            'ok' => true,
            'days' => $days_map,
            'daily_counts' => array_reverse($daily_counts),
            'stats' => [
                'total' => $total,
                'posts' => $total_posts,
                'manual' => $total_manual,
                'this_week' => $this_week,
                'last_week' => $last_week,
            ],
        ];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Add a manual work log entry. Auth-gated by the caller.
 */
function lrg_worklog_add(array $data): array {
    $required = ['title', 'category'];
    foreach ($required as $f) {
        if (empty($data[$f])) return ['ok' => false, 'error' => "Missing required field: $f"];
    }

    $allowed_categories = ['content', 'infra', 'fix', 'build', 'seo', 'deploy', 'audit'];
    $category = in_array($data['category'], $allowed_categories) ? $data['category'] : 'infra';
    $work_date = $data['work_date'] ?? date('Y-m-d');
    $source = $data['source'] ?? 'manual';

    try {
        $pdo = lrg_get_pdo();
        $stmt = $pdo->prepare("INSERT INTO wp_lrg_work_log (work_date, category, title, description, source) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $work_date,
            $category,
            mb_substr($data['title'], 0, 255),
            $data['description'] ?? '',
            $source,
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
