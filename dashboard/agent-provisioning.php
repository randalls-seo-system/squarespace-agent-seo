<?php
/**
 * LRG Dashboard — Agent Provisioning Engine
 * Generates author page HTML, preview, and complete-existing logic.
 * Pages created as DRAFT, pending review before publish.
 */

// SVG constants for contact card
define('LRG_SVG_PHONE', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>');
define('LRG_SVG_OFFICE', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v5"/></svg>');
define('LRG_SVG_EMAIL', '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>');
define('LRG_SVG_SHIELD', '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>');

define('LRG_AUTHOR_PARENT_PAGE', 5480);
define('LRG_HUB_PAGE', 7816);
define('LRG_OFFICE_PHONE', '(210) 879-8220');
define('LRG_OFFICE_PHONE_TEL', '+12108798220');

/**
 * Dynamic User ID → Page ID map. Queries all author pages under parent 5480.
 * Cached per-request via static variable.
 */
function lrg_get_agent_page_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    try {
        $pdo = lrg_get_pdo();
        $stmt = $pdo->query("SELECT ID, post_author FROM wp_posts WHERE post_type='page' AND post_parent=" . LRG_AUTHOR_PARENT_PAGE . " AND post_content LIKE '%lrgAuthorPage%'");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['post_author']] = (int)$row['ID'];
        }
        return $map;
    } catch (Exception $e) {
        return [];
    }
}

// Backward-compat constant (still used in JS for the loadExistingAgent uid lookup)
// Will be populated dynamically at runtime via the function above.

/**
 * Dynamic registry check: which user IDs are in lrg-author-bio-card.php?
 * Parses the registry file for numeric keys.
 */
function lrg_get_registry_ids(): array {
    static $ids = null;
    if ($ids !== null) return $ids;
    $file = LRG_INSTALL_ROOT . '/wp-content/mu-plugins/lrg-author-bio-card.php';
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    preg_match_all('/^\s+(\d+)\s*=>\s*\[/m', $content, $m);
    $ids = array_map('intval', $m[1] ?? []);
    return $ids;
}

/**
 * Format a 10-digit phone number as (XXX) XXX-XXXX
 */
function lrg_format_phone(string $raw): array {
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
    if (strlen($digits) !== 10) return ['display' => $raw, 'tel' => '+1' . $digits];
    $d = $digits;
    return [
        'display' => "($d[0]$d[1]$d[2]) $d[3]$d[4]$d[5]-$d[6]$d[7]$d[8]$d[9]",
        'tel' => '+1' . $digits,
    ];
}

/**
 * Generate a nicename (slug) from first + last name.
 */
function lrg_make_nicename(string $first, string $last): string {
    $slug = strtolower(trim($first) . '-' . trim($last));
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Generate the complete #lrgAuthorPage HTML from data array.
 * Matches the Karishma (5483) template structure exactly.
 */
function lrg_generate_author_page_html(array $d): string {
    $first = htmlspecialchars($d['first_name']);
    $last = htmlspecialchars($d['last_name']);
    $full = "$first $last";
    $nick = htmlspecialchars($d['nicename']);
    $role = htmlspecialchars($d['role_title']);
    $trec = $d['trec'] ?? '';
    $trec_type = $d['trec_type'] ?? 'Sales Agent';
    $bio = htmlspecialchars($d['bio']);
    $phone = lrg_format_phone($d['phone'] ?? '');
    $email_addr = htmlspecialchars($d['email']);
    $area = htmlspecialchars($d['area'] ?? 'San Antonio');
    $eyebrow = htmlspecialchars($d['eyebrow'] ?? $d['lane'] ?? 'San Antonio');
    $h2 = htmlspecialchars(!empty($d['h2_text']) ? $d['h2_text'] : 'Real estate services in San Antonio and Central Texas');

    // Trust badges
    $trust_html = '';
    $badges = array_filter(array_map('trim', explode(',', $d['trust_badges'] ?? '')));
    if (!empty($badges)) {
        $parts = [];
        foreach ($badges as $b) {
            $parts[] = '<span><span class="dot"></span>' . htmlspecialchars($b) . '</span>';
        }
        $trust_html = '<div class="trust">' . implode('', $parts) . '</div>';
    }

    // TREC stamp
    $trec_html = '';
    if (!empty($trec)) {
        $trec_verify_url = !empty($d['trec_url']) ? $d['trec_url'] : 'https://www.trec.texas.gov/apps/license-holder-search/';
        $trec_html = '<div class="stamp">' . LRG_SVG_SHIELD . '
<div>
<span class="lic">TREC #' . htmlspecialchars($trec) . '-' . ($trec_type === 'Broker' ? 'BR' : 'SA') . '</span>
<span class="type"> &middot; ' . htmlspecialchars($trec_type) . '</span>
</div>
<a class="verify" href="' . htmlspecialchars($trec_verify_url) . '" target="_blank" rel="noopener">Verify on TREC &rarr;</a>
</div>';
    }

    // Contact card phone row
    $phone_row = '';
    if (!empty($d['phone'])) {
        $phone_row = '<div class="crow">' . LRG_SVG_PHONE . '<div><div class="lbl">Direct</div><a class="val" href="tel:' . $phone['tel'] . '">' . htmlspecialchars($phone['display']) . '</a></div></div>';
    }

    // JSON-LD
    $jsonld = [
        '@context' => 'https://schema.org',
        '@type' => 'RealEstateAgent',
        'name' => $d['first_name'] . ' ' . $d['last_name'],
        'jobTitle' => $d['role_title'],
        'image' => "https://lrgrealty.com/wp-content/uploads/authors/{$d['nicename']}.png",
        'url' => "https://lrgrealty.com/authors/{$d['nicename']}/",
        'areaServed' => [['@type' => 'City', 'name' => $d['area'] ?? 'San Antonio', 'containedInPlace' => ['@type' => 'State', 'name' => 'Texas']]],
        'worksFor' => ['@type' => 'RealEstateAgent', '@id' => 'https://lrgrealty.com/#organization', 'name' => 'NFLO, LLC dba Levi Rodgers Real Estate Group', 'url' => 'https://lrgrealty.com'],
    ];
    if (!empty($trec)) {
        $jsonld['hasCredential'] = [
            '@type' => 'EducationalOccupationalCredential',
            'credentialCategory' => 'Real Estate License',
            'name' => 'Texas Real Estate ' . $trec_type . ' License',
            'identifier' => '#' . $trec . '-' . ($trec_type === 'Broker' ? 'BR' : 'SA'),
            'recognizedBy' => ['@type' => 'Organization', 'name' => 'Texas Real Estate Commission', 'url' => 'https://www.trec.texas.gov/'],
        ];
    }
    $sameAs = [];
    foreach (['linkedin','facebook','instagram','tiktok'] as $platform) {
        if (!empty($d[$platform])) $sameAs[] = $d[$platform];
    }
    $jsonld['sameAs'] = $sameAs;
    if (!empty($d['phone'])) $jsonld['telephone'] = $phone['tel'];
    if (!empty($d['email'])) $jsonld['email'] = $d['email'];

    $jsonld_str = json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<div id="lrgAuthorPage">

<header class="hero">
<div class="wrap">
<nav class="crumb" aria-label="Breadcrumb">
<a href="https://lrgrealty.com/">Home</a><span class="sep">&rsaquo;</span>
<a href="https://lrgrealty.com/specialists/">Specialists</a><span class="sep">&rsaquo;</span>
<span aria-current="page" style="color:#dbe4f0">' . $full . '</span>
</nav>
<div class="heroGrid">
<div class="shot">
<img src="https://lrgrealty.com/wp-content/uploads/authors/' . $nick . '.png" alt="' . $full . ', ' . $role . ' at LRG Realty">
</div>
<div>
<div class="eyebrow">LRG Realty &middot; ' . $eyebrow . '</div>
<div class="heroName">' . $first . ' <span class="red">' . $last . '</span></div>
<div class="lane">' . $role . '</div>
' . $trust_html . '
</div>
</div>
</div>
</header>

<div class="body">
<div class="wrap">
<div class="cols">

<main>
' . $trec_html . '
<div class="kicker">About ' . $first . '</div>
<h2>' . $h2 . '</h2>
<div class="bio">
<p>' . $bio . '</p>
</div>


<section class="articles"><div class="kicker">Guides &amp; Insights</div><h2 style="font-size:1.5rem !important;margin-bottom:0 !important">Written by ' . $first . '</h2>[lrg_author_posts]</section>
</main>

<aside class="side">
<div class="ccard">
<h4>Contact ' . $first . '</h4>
<div class="sub">' . $role . '</div>
' . $phone_row . '<div class="crow">' . LRG_SVG_OFFICE . '<div><div class="lbl">Office</div><a class="val" href="tel:' . LRG_OFFICE_PHONE_TEL . '">' . LRG_OFFICE_PHONE . '</a></div></div><div class="crow">' . LRG_SVG_EMAIL . '<div><div class="lbl">Email</div><a class="val" href="mailto:' . $email_addr . '" style="font-size:.86rem !important;word-break:break-all !important">' . $email_addr . '</a></div></div>
<a class="work" href="https://lrgrealty.com/lrg-blog/connect-with-lrg/?ref=author-' . $nick . '">Work with ' . $first . '</a>
</div>
</aside>

</div>
</div>
</div>

</div>

<script type="application/ld+json">
' . $jsonld_str . '
</script>';
}

/**
 * Load an existing agent's data for the Complete-Existing form.
 * Returns all known data + a list of gaps.
 */
function lrg_load_existing_agent(int $user_id): array {
    try {
        $pdo = lrg_get_pdo();

        // WP User
        $stmt = $pdo->prepare("SELECT ID, user_login, user_email, user_nicename, display_name FROM wp_users WHERE ID = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) return ['ok' => false, 'error' => "User $user_id not found"];

        // User meta
        $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key IN ('rss_mh_role','rss_mh_profile_url','rss_mh_avatar','description','lrg_languages')");
        $stmt->execute([$user_id]);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) $meta[$row['meta_key']] = $row['meta_value'];

        // WP description (bio) is stored differently
        $stmt = $pdo->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'description'");
        $stmt->execute([$user_id]);
        $desc_row = $stmt->fetch();
        $bio_text = $desc_row ? $desc_row['meta_value'] : '';

        // Author page
        $page_id = lrg_get_agent_page_map()[$user_id] ?? null;
        $page_content = '';
        $page_title = '';
        $page_slug = '';
        if ($page_id) {
            $stmt = $pdo->prepare("SELECT post_title, post_name, post_content FROM wp_posts WHERE ID = ?");
            $stmt->execute([$page_id]);
            $page = $stmt->fetch();
            if ($page) {
                $page_content = $page['post_content'];
                $page_title = $page['post_title'];
                $page_slug = $page['post_name'];
            }
        }

        // Parse existing page content for fields
        $page_bio = '';
        if ($page_content && preg_match('/About\s+\w+/i', $page_content, $m, PREG_OFFSET_CAPTURE)) {
            $ps = strpos($page_content, '<p', $m[0][1]);
            if ($ps !== false) {
                $pe = strpos($page_content, '</p>', $ps);
                if ($pe !== false) {
                    $openEnd = strpos($page_content, '>', $ps) + 1;
                    $page_bio = strip_tags(substr($page_content, $openEnd, $pe - $openEnd));
                }
            }
        }

        $page_title_sub = '';
        if (preg_match('/<div class="sub">([^<]+)<\/div>/', $page_content, $tm)) {
            $page_title_sub = html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8');
        }

        $page_trec = '';
        if (preg_match('/TREC\s*#?\s*(\d+)/', $page_content, $trcm)) {
            $page_trec = $trcm[1];
        }

        $page_job_title = '';
        if (preg_match('/"jobTitle":"([^"]+)"/', $page_content, $jm)) {
            $page_job_title = $jm[1];
        }

        $page_phone = '';
        if (preg_match('/class="val" href="tel:\+1(\d{10})"/', $page_content, $pm)) {
            $page_phone = $pm[1];
        }

        $page_email = '';
        if (preg_match('/href="mailto:([^"]+)"/', $page_content, $em)) {
            $page_email = $em[1];
        }

        // Eyebrow
        $page_eyebrow = '';
        if (preg_match('/class="eyebrow">LRG Realty &middot; ([^<]+)</', $page_content, $ebm)) {
            $page_eyebrow = html_entity_decode(trim($ebm[1]), ENT_QUOTES, 'UTF-8');
        }

        // Trust badges
        $page_badges = '';
        if (preg_match_all('/<span class="dot"><\/span>([^<]+)<\/span>/', $page_content, $bdm)) {
            $page_badges = implode(', ', array_map('trim', $bdm[1]));
        }

        // H2 text (under About section)
        $page_h2 = '';
        if (preg_match('/About\s+\w+/i', $page_content, $_m, PREG_OFFSET_CAPTURE)) {
            $h2pos = strpos($page_content, '<h2>', $_m[0][1]);
            if ($h2pos !== false) {
                $h2end = strpos($page_content, '</h2>', $h2pos);
                if ($h2end !== false) $page_h2 = strip_tags(substr($page_content, $h2pos + 4, $h2end - $h2pos - 4));
            }
        }

        // Area from JSON-LD
        $page_area = 'San Antonio';
        if (preg_match('/"name":"([^"]+)","containedInPlace"/', $page_content, $am)) {
            $page_area = $am[1];
        }

        // Socials from JSON-LD sameAs
        $page_socials = ['linkedin'=>'','facebook'=>'','instagram'=>'','tiktok'=>''];
        if (preg_match('/"sameAs":\[([^\]]*)\]/', $page_content, $sam)) {
            $urls = json_decode('[' . $sam[1] . ']', true) ?: [];
            foreach ($urls as $u) {
                if (strpos($u, 'linkedin') !== false) $page_socials['linkedin'] = $u;
                elseif (strpos($u, 'facebook') !== false) $page_socials['facebook'] = $u;
                elseif (strpos($u, 'instagram') !== false) $page_socials['instagram'] = $u;
                elseif (strpos($u, 'tiktok') !== false) $page_socials['tiktok'] = $u;
            }
        }

        // TREC verify URL
        $page_trec_url = 'https://www.trec.texas.gov/apps/license-holder-search/';
        if (preg_match('/class="verify" href="([^"]+)"/', $page_content, $tum)) {
            $page_trec_url = $tum[1];
        }

        // Check headshot
        $headshot_exists = false;
        $headshot_path = '/wp-content/uploads/authors/' . $user['user_nicename'] . '.png';
        // Can't check file_exists from standalone PHP easily; check via content
        $headshot_in_page = strpos($page_content, $user['user_nicename'] . '.png') !== false;

        // In registry?
        $in_registry = in_array($user_id, lrg_get_registry_ids());

        // On hub?
        $hub_content = $pdo->query("SELECT post_content FROM wp_posts WHERE ID = " . LRG_HUB_PAGE)->fetchColumn();
        $on_hub = $hub_content && strpos($hub_content, $user['display_name']) !== false;

        // Parse name
        $name_parts = explode(' ', trim($user['display_name']), 2);
        $first = $name_parts[0] ?? '';
        $last = $name_parts[1] ?? '';

        // Detect gaps
        $gaps = [];
        if (empty($meta['rss_mh_role'])) $gaps[] = 'rss_mh_role (no role/title in user meta)';
        if (empty($meta['rss_mh_profile_url'])) $gaps[] = 'rss_mh_profile_url (no profile URL)';
        if (empty($meta['rss_mh_avatar'])) $gaps[] = 'rss_mh_avatar (no avatar URL)';
        if (empty($bio_text)) $gaps[] = 'description (no WP user bio)';
        if (empty($page_bio) && !empty($page_content)) $gaps[] = 'page bio (page exists but bio section empty)';
        if (empty($page_content)) $gaps[] = 'author page (no page content)';
        if (!$page_id) $gaps[] = 'author page (no page exists at all)';
        if (!$in_registry) $gaps[] = 'bio-card registry (not in lrg-author-bio-card.php)';
        if (empty($page_trec)) $gaps[] = 'TREC license (not on page)';
        if (!$on_hub) $gaps[] = 'hub page (not on Specialists hub 7816)';
        if (!$headshot_in_page && !empty($page_content)) $gaps[] = 'headshot (not referenced in page)';
        $has_shortcode = strpos($page_content, '[lrg_author_posts]') !== false;
        if (!$has_shortcode && !empty($page_content)) $gaps[] = 'articles shortcode missing';

        return [
            'ok' => true,
            'user_id' => $user_id,
            'page_id' => $page_id,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $user['display_name'],
            'nicename' => $user['user_nicename'],
            'email' => $user['user_email'],
            'role_title' => $meta['rss_mh_role'] ?? $page_title_sub ?? '',
            'profile_url' => $meta['rss_mh_profile_url'] ?? '',
            'bio' => $bio_text ?: $page_bio,
            'trec' => $page_trec,
            'job_title' => $page_job_title,
            'phone' => $page_phone ? lrg_format_phone($page_phone)['display'] : '',
            'page_email' => $page_email,
            'eyebrow' => $page_eyebrow,
            'trust_badges' => $page_badges,
            'h2_text' => $page_h2,
            'area' => $page_area,
            'linkedin' => $page_socials['linkedin'],
            'facebook' => $page_socials['facebook'],
            'instagram' => $page_socials['instagram'],
            'tiktok' => $page_socials['tiktok'],
            'languages' => $meta['lrg_languages'] ?? '',
            'trec_url' => $page_trec_url,
            'in_registry' => $in_registry,
            'on_hub' => $on_hub,
            'has_page' => !empty($page_content),
            'has_shortcode' => $has_shortcode,
            'headshot_in_page' => $headshot_in_page,
            'gaps' => $gaps,
            'gap_count' => count($gaps),
            'mode' => 'complete',
        ];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Compute a full preview of what agent provisioning would create/update.
 * Returns all 7 layers as preview data. Does NOT write anything.
 */
function lrg_agent_preview(array $d): array {
    $errors = [];

    // Validate required fields
    if (empty($d['first_name'])) $errors[] = 'First name is required';
    if (empty($d['last_name'])) $errors[] = 'Last name is required';
    if (empty($d['email'])) $errors[] = 'Email is required';
    if (empty($d['role_title'])) $errors[] = 'Role/title is required';
    if (empty($d['bio'])) $errors[] = 'Bio is required';
    if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';

    $nick = $d['nicename'] ?? lrg_make_nicename($d['first_name'] ?? '', $d['last_name'] ?? '');
    $d['nicename'] = $nick;

    // Nicename collision check (unless completing an existing agent)
    if (empty($d['existing_user_id'])) {
        try {
            $pdo = lrg_get_pdo();
            $stmt = $pdo->prepare("SELECT ID FROM wp_users WHERE user_nicename = ?");
            $stmt->execute([$nick]);
            if ($stmt->fetch()) $errors[] = "Nicename '$nick' already exists — choose a different one";

            $stmt = $pdo->prepare("SELECT ID FROM wp_posts WHERE post_name = ? AND post_type = 'page'");
            $stmt->execute([$nick]);
            if ($stmt->fetch()) $errors[] = "Page slug '$nick' already exists";
        } catch (Exception $e) {
            $errors[] = 'DB check failed: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    // Layer 1: WP User
    $wp_user = [
        'user_login' => $nick,
        'user_nicename' => $nick,
        'display_name' => trim($d['first_name'] . ' ' . $d['last_name']),
        'user_email' => $d['email'],
        'role' => 'author',
    ];

    // Layer 2: User Meta
    $user_meta = [
        'rss_mh_role' => $d['role_title'],
        'rss_mh_profile_url' => "https://lrgrealty.com/authors/{$nick}/",
        'rss_mh_avatar' => "https://lrgrealty.com/wp-content/uploads/authors/{$nick}.png",
        'description' => $d['bio'],
        'lrg_languages' => $d['languages'] ?? '',
    ];

    // Layer 3: Bio card registry entry
    $registry_entry = [
        'trec' => $d['trec'] ?? '',
        'area' => $d['area'] ?? 'San Antonio',
        'socials' => [],
    ];
    foreach (['facebook','instagram','tiktok'] as $p) {
        if (!empty($d[$p])) $registry_entry['socials'][$p] = $d[$p];
    }

    // Layer 4: Author page HTML
    $page_html = lrg_generate_author_page_html($d);

    // Layer 5: Headshot path
    $headshot = [
        'path' => "/wp-content/uploads/authors/{$nick}.png",
        'full_path' => LRG_INSTALL_ROOT . "/wp-content/uploads/authors/{$nick}.png",
    ];

    // Layer 6: Hub card HTML (dCard for Specialists hub)
    $first_esc = htmlspecialchars($d['first_name']);
    $last_esc = htmlspecialchars($d['last_name']);
    $role_esc = htmlspecialchars($d['role_title']);
    $hub_card = '<a class="dCard" href="https://lrgrealty.com/authors/' . htmlspecialchars($nick) . '/">
<img src="https://lrgrealty.com/wp-content/uploads/authors/' . htmlspecialchars($nick) . '.png" alt="' . $first_esc . ' ' . $last_esc . '">
<div class="dName">' . $first_esc . ' ' . $last_esc . '</div>
<div class="dRole">' . $role_esc . '</div>
</a>';

    // Layer 7: lrg.conf AUTHOR_LANE_MAP entry (if applicable)
    $lane_map_entry = '';
    if (!empty($d['lane_keywords'])) {
        $lane_map_entry = $d['lane_keywords'] . '|USER_ID|' . $d['first_name'] . ' ' . $d['last_name'];
    }

    // Summary
    $is_new = empty($d['existing_user_id']);
    $summary = $is_new
        ? "Would CREATE new agent: {$d['first_name']} {$d['last_name']}"
        : "Would COMPLETE existing agent (user {$d['existing_user_id']}): {$d['first_name']} {$d['last_name']}";

    return [
        'ok' => true,
        'write_enabled' => true,
        'mode' => $is_new ? 'create' : 'complete',
        'summary' => $summary,
        'layers' => [
            ['name' => 'WP User', 'data' => $wp_user, 'action' => $is_new ? 'CREATE' : 'UPDATE'],
            ['name' => 'User Meta', 'data' => $user_meta, 'action' => 'SET'],
            ['name' => 'Bio Card Registry', 'data' => $registry_entry, 'action' => 'ADD entry to _lrg_author_registry()'],
            ['name' => 'Author Page', 'data' => ['slug' => $nick, 'parent' => LRG_AUTHOR_PARENT_PAGE, 'word_count' => str_word_count(strip_tags($page_html))], 'html_preview' => mb_substr(strip_tags($page_html), 0, 500) . '...', 'action' => $is_new ? 'CREATE page' : 'UPDATE content'],
            ['name' => 'Headshot', 'data' => $headshot, 'action' => 'UPLOAD to ' . $headshot['path']],
            ['name' => 'Hub Card', 'data' => ['lane' => $d['lane'] ?? ''], 'html_preview' => $hub_card, 'action' => 'ADD dCard to hub 7816'],
            ['name' => 'Lane Map', 'data' => $lane_map_entry ?: '(no lane keywords specified)', 'action' => $lane_map_entry ? 'ADD to lrg.conf AUTHOR_LANE_MAP' : 'SKIP'],
        ],
        'page_html_full' => $page_html,
    ];
}

// ═══════════════════════════════════════════════════════════
// WRITE EXECUTION LAYER
// ═══════════════════════════════════════════════════════════

/**
 * Execute agent provisioning across all 7 layers.
 * Pages created as DRAFT (pending review). Hub card held until approval.
 * Transactional: rollback all completed layers on any failure.
 */
function lrg_agent_execute_provision(array $data): array {

    // Run the preview first to validate + generate HTML
    $preview = lrg_agent_preview($data);
    if (!$preview['ok']) return $preview;

    $nick = $data['nicename'] ?? lrg_make_nicename($data['first_name'], $data['last_name']);
    $is_new = empty($data['existing_user_id']);
    $results = [];
    $rollback = []; // Stack of undo operations

    // Pre-flight: load WP functions
    if (!function_exists('wp_insert_user')) {
        define('SHORTINIT', false);
        require_once LRG_WP_LOAD_PATH;
    }

    $ts = date('Ymd-His');

    try {
        // ─── Layer 1: WP User ───
        if ($is_new) {
            $uid = wp_insert_user([
                'user_login' => $nick,
                'user_nicename' => $nick,
                'display_name' => trim($data['first_name'] . ' ' . $data['last_name']),
                'user_email' => $data['email'],
                'user_pass' => wp_generate_password(24),
                'role' => 'author',
            ]);
            if (is_wp_error($uid)) {
                return ['ok' => false, 'error' => 'Layer 1 (WP User): ' . $uid->get_error_message(), 'rolled_back' => []];
            }
            $rollback[] = ['type' => 'delete_user', 'uid' => $uid];
            $results[] = "Layer 1: Created user $uid ($nick)";
        } else {
            $uid = (int)$data['existing_user_id'];
            $results[] = "Layer 1: Using existing user $uid";
        }

        // ─── Layer 2: User Meta ───
        $meta_keys = [
            'rss_mh_role' => $data['role_title'],
            'rss_mh_profile_url' => "https://lrgrealty.com/authors/{$nick}/",
            'rss_mh_avatar' => "https://lrgrealty.com/wp-content/uploads/authors/{$nick}.png",
            'description' => $data['bio'],
            'lrg_languages' => $data['languages'] ?? '',
        ];
        foreach ($meta_keys as $key => $value) {
            if ($value !== '') update_user_meta($uid, $key, $value);
        }
        $results[] = "Layer 2: Set " . count($meta_keys) . " meta keys for user $uid";

        // ─── Layer 3: Bio-card Registry ───
        $registry_file = LRG_INSTALL_ROOT . '/wp-content/mu-plugins/lrg-author-bio-card.php';
        $registry_bak = $registry_file . '.bak.' . $ts;

        // Backup first
        if (!copy($registry_file, $registry_bak)) {
            throw new Exception('Layer 3: Failed to backup registry file');
        }
        $rollback[] = ['type' => 'restore_file', 'from' => $registry_bak, 'to' => $registry_file];

        $reg_content = file_get_contents($registry_file);
        $reg_size_before = strlen($reg_content);

        // Build new entry
        $trec_val = !empty($data['trec']) ? "'{$data['trec']}'" : "''";
        $area_val = !empty($data['area']) ? "'" . addslashes($data['area']) . "'" : "'San Antonio'";
        $socials = [];
        foreach (['facebook', 'instagram', 'tiktok'] as $p) {
            if (!empty($data[$p])) $socials[] = "'$p' => '" . addslashes($data[$p]) . "'";
        }
        $socials_str = $socials ? implode(",\n                   ", $socials) : '';

        $new_entry = "\n        $uid => ['trec' => $trec_val, 'area' => $area_val,\n               'socials' => [$socials_str]],";

        // Insert before the closing ]; of the return array
        $insert_pos = strrpos($reg_content, '];');
        if ($insert_pos === false) {
            throw new Exception('Layer 3: Could not find ]; in registry file');
        }
        $reg_content = substr($reg_content, 0, $insert_pos) . $new_entry . "\n    " . substr($reg_content, $insert_pos);

        // Size guard: must stay above 100 bytes (registry is ~6KB; this catches catastrophic truncation)
        if (strlen($reg_content) < 100) {
            throw new Exception('Layer 3: Registry content suspiciously small (' . strlen($reg_content) . ' bytes), aborting');
        }

        file_put_contents($registry_file, $reg_content);

        // Verify
        $reg_size_after = strlen(file_get_contents($registry_file));
        if ($reg_size_after < $reg_size_before) {
            throw new Exception("Layer 3: Registry shrank from $reg_size_before to $reg_size_after bytes");
        }

        // Lint check
        $lint_output = [];
        exec("php -l " . escapeshellarg($registry_file) . " 2>&1", $lint_output, $lint_code);
        if ($lint_code !== 0) {
            throw new Exception('Layer 3: Registry file has syntax errors after edit: ' . implode(' ', $lint_output));
        }

        $results[] = "Layer 3: Registry entry added for uid $uid ($reg_size_before → $reg_size_after bytes, lint clean)";

        // ─── Layer 4: Author Page ───
        $page_html = $preview['page_html_full'];

        if ($is_new) {
            $page_id = wp_insert_post([
                'post_title' => trim($data['first_name'] . ' ' . $data['last_name']) . ' — LRG Realty',
                'post_content' => wp_slash($page_html),
                'post_status' => 'draft',
                'post_type' => 'page',
                'post_name' => $nick,
                'post_author' => $uid,
                'post_parent' => LRG_AUTHOR_PARENT_PAGE,
            ]);
            if (is_wp_error($page_id)) {
                throw new Exception('Layer 4: ' . $page_id->get_error_message());
            }
            $rollback[] = ['type' => 'delete_post', 'post_id' => $page_id];
            update_post_meta($page_id, '_et_pb_page_layout', 'et_full_width_page');
            $results[] = "Layer 4: Created author page $page_id (/$nick/, full-width layout)";
        } else {
            $page_id = lrg_get_agent_page_map()[$uid] ?? null;
            if ($page_id) {
                // Backup current content
                update_post_meta($page_id, '_pre_provision_' . $ts, get_post($page_id)->post_content);
                $rollback[] = ['type' => 'restore_post', 'post_id' => $page_id, 'meta_key' => '_pre_provision_' . $ts];
                wp_update_post(['ID' => $page_id, 'post_content' => wp_slash($page_html)]);
                $results[] = "Layer 4: Updated author page $page_id content";
            } else {
                $results[] = "Layer 4: SKIPPED (no existing page mapped for uid $uid)";
            }
        }

        // ─── Layer 5: Headshot ───
        $headshot_dir = LRG_INSTALL_ROOT . '/wp-content/uploads/authors/';
        $headshot_path = $headshot_dir . $nick . '.png';
        if (!empty($data['headshot_data'])) {
            // headshot_data is base64-encoded image from form upload
            $img_data = base64_decode($data['headshot_data']);
            if ($img_data && strlen($img_data) > 100 && strlen($img_data) < 10 * 1024 * 1024) {
                // Validate it's a real image
                $tmp = tempnam(sys_get_temp_dir(), 'hs');
                file_put_contents($tmp, $img_data);
                $img_info = @getimagesize($tmp);
                if ($img_info && in_array($img_info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG])) {
                    if (!is_dir($headshot_dir)) mkdir($headshot_dir, 0755, true);
                    copy($tmp, $headshot_path);
                    $rollback[] = ['type' => 'delete_file', 'path' => $headshot_path];
                    $results[] = "Layer 5: Headshot saved ({$img_info[0]}x{$img_info[1]}, " . strlen($img_data) . " bytes)";
                } else {
                    $results[] = "Layer 5: SKIPPED (invalid image data)";
                }
                @unlink($tmp);
            } else {
                $results[] = "Layer 5: SKIPPED (no valid headshot data)";
            }
        } else {
            $results[] = "Layer 5: SKIPPED (no headshot uploaded)";
        }

        // ─── Layer 6: Hub Card — HELD until approval ───
        $results[] = "Layer 6: Hub card HELD — will be injected when page is approved";

        // ─── Layer 7: JSON-LD (already in page HTML from Layer 4) ───
        $results[] = "Layer 7: JSON-LD included in author page HTML";

        // ─── Add to review queue ───
        $pdo = lrg_get_pdo();
        $stmt = $pdo->prepare("INSERT INTO wp_lrg_agent_review_queue (user_id, page_id, nicename, status, created_by) VALUES (?, ?, ?, 'pending_review', ?)");
        $stmt->execute([$uid, $page_id ?? 0, $nick, $data['_created_by'] ?? 'dashboard']);
        $results[] = "Added to review queue (pending_review)";

        // ─── Flush caches ───
        wp_cache_flush();
        if (class_exists('WpeCommon')) {
            WpeCommon::purge_varnish_cache_all();
        }
        $results[] = "Caches flushed + Varnish purged";

        return [
            'ok' => true,
            'user_id' => $uid,
            'page_id' => $page_id ?? null,
            'nick' => $nick,
            'results' => $results,
            'review_status' => 'pending_review',
            'draft_preview_url' => "https://lrgrealty.com/lrg-blog/?p=" . ($page_id ?? 0) . "&preview=true",
        ];

    } catch (Exception $e) {
        // ─── ROLLBACK ───
        $rolled_back = [];
        foreach (array_reverse($rollback) as $rb) {
            switch ($rb['type']) {
                case 'delete_user':
                    if (function_exists('wp_delete_user')) {
                        wp_delete_user($rb['uid']);
                        $rolled_back[] = "Deleted user {$rb['uid']}";
                    }
                    break;
                case 'delete_post':
                    wp_delete_post($rb['post_id'], true);
                    $rolled_back[] = "Deleted post {$rb['post_id']}";
                    break;
                case 'restore_file':
                    if (file_exists($rb['from'])) {
                        copy($rb['from'], $rb['to']);
                        $rolled_back[] = "Restored {$rb['to']} from backup";
                    }
                    break;
                case 'restore_post':
                    $backup_content = get_post_meta($rb['post_id'], $rb['meta_key'], true);
                    if ($backup_content) {
                        wp_update_post(['ID' => $rb['post_id'], 'post_content' => wp_slash($backup_content)]);
                        $rolled_back[] = "Restored post {$rb['post_id']} content from {$rb['meta_key']}";
                    }
                    break;
                case 'delete_file':
                    if (file_exists($rb['path'])) {
                        @unlink($rb['path']);
                        $rolled_back[] = "Deleted {$rb['path']}";
                    }
                    break;
            }
        }

        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'results_before_failure' => $results,
            'rolled_back' => $rolled_back,
        ];
    }
}

/**
 * Get the review queue (pending + recent).
 */
function lrg_get_review_queue(): array {
    try {
        $pdo = lrg_get_pdo();
        $stmt = $pdo->query("
            SELECT q.*, u.display_name, p.post_title, p.post_status as page_status
            FROM wp_lrg_agent_review_queue q
            LEFT JOIN wp_users u ON q.user_id = u.ID
            LEFT JOIN wp_posts p ON q.page_id = p.ID
            ORDER BY q.id DESC LIMIT 50
        ");
        return ['ok' => true, 'queue' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Approve an agent: publish their page + inject hub card.
 */
function lrg_approve_agent(int $queue_id, string $reviewer): array {
    if (!function_exists('wp_update_post')) {
        define('SHORTINIT', false);
        require_once LRG_WP_LOAD_PATH;
    }

    try {
        $pdo = lrg_get_pdo();
        $stmt = $pdo->prepare("SELECT * FROM wp_lrg_agent_review_queue WHERE id = ? AND status = 'pending_review'");
        $stmt->execute([$queue_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) return ['ok' => false, 'error' => 'Queue item not found or already processed'];

        $page_id = (int)$item['page_id'];
        $uid = (int)$item['user_id'];
        $nick = $item['nicename'];
        $results = [];
        $ts = date('Ymd-His');

        // 1. Publish the page
        $post = get_post($page_id);
        if (!$post) return ['ok' => false, 'error' => "Page $page_id not found"];
        wp_update_post(['ID' => $page_id, 'post_status' => 'publish']);
        $results[] = "Published page $page_id";

        // 2. Hub card — marker-based injection (safe, no div-depth parsing)
        $hub_id = LRG_HUB_PAGE;
        $hub_content = get_post($hub_id)->post_content;
        update_post_meta($hub_id, '_pre_approve_hub_' . $ts, $hub_content);

        $display = get_user_by('id', $uid)->display_name ?? $nick;
        $name_parts = explode(' ', $display, 2);
        $first_esc = htmlspecialchars($name_parts[0] ?? '');
        $last_esc = htmlspecialchars($name_parts[1] ?? '');
        $role = get_user_meta($uid, 'rss_mh_role', true) ?: 'REALTOR';
        $role_esc = htmlspecialchars($role);

        // Build card matching the ACTUAL hub format (<div class="dCard">, not <a>)
        $page_content_for_bio = get_post($page_id)->post_content;
        $card_bio = '';
        if (preg_match('/class="bio">\s*<p>([^<]+)/', $page_content_for_bio, $bm)) {
            $card_bio = mb_substr(trim($bm[1]), 0, 280);
        }
        $card_trec = '';
        if (preg_match('/TREC #(\d+-\w+)/', $page_content_for_bio, $tm)) {
            $card_trec = '<div class="stamp"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg><span class="lic">#' . htmlspecialchars($tm[1]) . '</span><a class="verify" href="https://www.trec.texas.gov/apps/license-holder-search/" target="_blank" rel="noopener">Verify &rarr;</a></div>' . "\n";
        }
        $first_name_only = $name_parts[0] ?? $nick;

        $hub_card = '<div class="dCard">' . "\n" .
            '<div class="dHead">' . "\n" .
            '<img src="https://lrgrealty.com/wp-content/uploads/authors/' . htmlspecialchars($nick) . '.png" alt="' . $first_esc . ' ' . $last_esc . '">' . "\n" .
            '<div><div class="nm">' . $first_esc . ' ' . $last_esc . '</div><div class="ln">' . $role_esc . '</div></div>' . "\n" .
            '</div>' . "\n" .
            ($card_bio ? '<p class="desc">' . htmlspecialchars($card_bio) . '</p>' . "\n" : '') .
            $card_trec .
            '<a class="readmore" href="https://lrgrealty.com/authors/' . htmlspecialchars($nick) . '/">Read more about ' . htmlspecialchars($first_name_only) . ' <span class="arw">&rarr;</span></a>' . "\n" .
            '</div>';

        // Find the target lane from the agent's page eyebrow
        $agent_page_content = $post->post_content;
        $lane_search = '';
        if (preg_match('/class="eyebrow">LRG Realty &middot; ([^<]+)</', $agent_page_content, $em)) {
            $lane_search = html_entity_decode(trim($em[1]), ENT_QUOTES, 'UTF-8');
        }

        // Map lane names to marker names (must match <!-- /LANE:MarkerName --> in 7816)
        $lane_to_marker = [
            'Veteran & Military' => 'VeteranampMilitaryBuyers',
            'First-Time Buyers' => 'FirstTimeBuyers',
            'Home Selling' => 'HomeSellingNeighborhoodsampHillCountry',
            'Hill Country' => 'HomeSellingNeighborhoodsampHillCountry',
            'Agent Mentors' => 'AgentMentors',
            'Multilingual' => 'FirstTimeBuyers', // default lane for multilingual agents
        ];
        $marker_name = $lane_to_marker[$lane_search] ?? '';

        $injected = false;
        if ($marker_name) {
            $marker = "<!-- /LANE:$marker_name -->";
            if (strpos($hub_content, $marker) !== false) {
                // Insert card RIGHT BEFORE the marker
                $hub_content = str_replace($marker, $hub_card . "\n" . $marker, $hub_content);

                // Update lane count — find the h3 for this lane, then the cnt span
                // Map marker back to lane heading text for count lookup
                $marker_to_heading = [
                    'AgentMentors' => 'Agent Mentors',
                    'VeteranampMilitaryBuyers' => 'Veteran &amp; Military Buyers',
                    'FirstTimeBuyers' => 'First-Time Buyers',
                    'HomeSellingNeighborhoodsampHillCountry' => 'Home Selling, Neighborhoods &amp; Hill Country',
                ];
                $heading = $marker_to_heading[$marker_name] ?? '';
                if ($heading) {
                    $h3_pos = strpos($hub_content, "<h3>$heading</h3>");
                    if ($h3_pos !== false) {
                        $cnt_pos = strpos($hub_content, 'class="cnt"', $h3_pos);
                        if ($cnt_pos !== false) {
                            $cnt_start = strpos($hub_content, '>', $cnt_pos) + 1;
                            $cnt_end = strpos($hub_content, '<', $cnt_start);
                            $old_cnt = substr($hub_content, $cnt_start, $cnt_end - $cnt_start);
                            if (preg_match('/(\d+)/', $old_cnt, $cm)) {
                                $new_cnt = str_replace($cm[1], (string)((int)$cm[1] + 1), $old_cnt);
                                $hub_content = substr($hub_content, 0, $cnt_start) . $new_cnt . substr($hub_content, $cnt_end);
                            }
                        }
                    }
                }

                wp_update_post(['ID' => $hub_id, 'post_content' => wp_slash($hub_content)]);
                $injected = true;
                $results[] = "Hub card injected into '$lane_search' lane (marker: $marker_name)";
            } else {
                $results[] = "Hub card: marker <!-- /LANE:$marker_name --> not found on hub page";
            }
        } else {
            $results[] = "Hub card: no marker mapping for lane '$lane_search' — manual placement needed";
        }

        // 3. Update queue status
        $stmt = $pdo->prepare("UPDATE wp_lrg_agent_review_queue SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$reviewer, $queue_id]);
        $results[] = "Queue item approved";

        wp_cache_flush();
        if (class_exists('WpeCommon')) WpeCommon::purge_varnish_cache_all();

        return ['ok' => true, 'results' => $results];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Reject an agent in the review queue.
 */
function lrg_reject_agent(int $queue_id, string $reviewer, string $note): array {
    try {
        $pdo = lrg_get_pdo();
        $stmt = $pdo->prepare("UPDATE wp_lrg_agent_review_queue SET status = 'rejected', reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending_review'");
        $stmt->execute([$reviewer, $note, $queue_id]);
        return ['ok' => true, 'affected' => $stmt->rowCount()];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
