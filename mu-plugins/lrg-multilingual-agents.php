<?php
/**
 * Plugin Name: LRG Multilingual Agents Page
 * Description: Renders the multilingual agents hub via [lrg_multilingual_agents] shortcode.
 * Language meta key: lrg_languages (comma-separated, e.g. "Spanish, Dari, Pashto")
 * Styled to match the Specialists hub page (#lrgSpecialistsPage pattern).
 */
if (!defined('ABSPATH')) exit;

add_action('wp_head', function() {
    if (!is_page('multilingual-agents')) return;
    // Full-width override + meta header suppression (same as author pages)
    $pid = get_the_ID();
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
    echo "<style id=\"lrg-ml-overrides\">";
    echo ".page-id-{$pid} .entry-title.main_title{display:none !important}";
    echo ".page-id-{$pid} .container{width:100% !important;max-width:100% !important;padding:0 !important}";
    echo ".page-id-{$pid} #content-area{padding:0 !important}";
    echo ".page-id-{$pid} #left-area{padding-bottom:0 !important}";
    echo ".page-id-{$pid} .entry-content{padding:0 !important;margin:0 !important}";
    echo ".page-id-{$pid} [class*=\"rss-mh\"],.page-id-{$pid} .rss-meta-header{display:none !important}";
    echo "</style>\n";
    echo '<style>
#lrgMLPage{--navy-1:#091A35;--navy-2:#173B67;--red:#c8102e;--ink:#1a2233;--ink-soft:#475067;--line:#e4e7ee;--paper:#f6f5f1;--card:#fff;font-family:"DM Sans",system-ui,sans-serif;color:var(--ink);background:var(--paper);-webkit-font-smoothing:antialiased}
#lrgMLPage *{box-sizing:border-box;margin:0;padding:0}
#lrgMLPage .hero{position:relative;background:linear-gradient(135deg,var(--navy-1) 0%,var(--navy-2) 100%);color:#fff;padding:34px 0 64px;overflow:hidden}
#lrgMLPage .hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:4px;background:linear-gradient(90deg,var(--red) 0%,var(--red) 70%,#fff 70%,#fff 100%)}
#lrgMLPage .wrap{max-width:1080px;margin:0 auto;padding:0 28px}
#lrgMLPage .crumb{font-size:.82rem;color:rgba(255,255,255,.55);margin-bottom:18px}
#lrgMLPage .crumb a{color:rgba(255,255,255,.55);text-decoration:none}
#lrgMLPage .crumb a:hover{color:#fff}
#lrgMLPage .crumb .sep{margin:0 6px;opacity:.4}
#lrgMLPage .heroGrid{display:grid;grid-template-columns:auto 1fr;gap:28px;align-items:center}
#lrgMLPage .heroName{font-size:2.2rem;font-weight:700;font-family:"Source Serif 4",serif;letter-spacing:-.01em;line-height:1.15}
#lrgMLPage .heroName .red{color:var(--red)}
#lrgMLPage .heroSub{font-size:1rem;color:rgba(255,255,255,.7);margin-top:8px;max-width:600px;line-height:1.6}
#lrgMLPage .ml-controls-bar{background:var(--card);border-bottom:1px solid var(--line);padding:20px 0}
#lrgMLPage .ml-controls{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
#lrgMLPage .ml-search{flex:1;min-width:200px;padding:10px 16px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;background:#fff;color:var(--ink);transition:border-color .15s}
#lrgMLPage .ml-search:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(200,16,46,.08)}
#lrgMLPage .ml-search::placeholder{color:#94a3b8}
#lrgMLPage .ml-pills{display:flex;gap:6px;flex-wrap:wrap}
#lrgMLPage .ml-pill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--line);background:var(--paper);color:var(--ink-soft);transition:all .15s;font-family:inherit}
#lrgMLPage .ml-pill:hover{border-color:var(--red);color:var(--red)}
#lrgMLPage .ml-pill.active{background:var(--navy-1);color:#fff;border-color:var(--navy-1)}
#lrgMLPage .body{padding:36px 0 48px}
#lrgMLPage .dGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
#lrgMLPage .dCard{background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden;text-decoration:none;color:var(--ink);transition:all .2s;display:block}
#lrgMLPage .dCard:hover{border-color:var(--red);box-shadow:0 8px 24px rgba(9,26,53,.1);transform:translateY(-3px)}
#lrgMLPage .dHead{display:flex;align-items:center;gap:14px;padding:20px}
#lrgMLPage .dHead img{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--line)}
#lrgMLPage .dHead .initials{width:56px;height:56px;border-radius:50%;background:var(--navy-1);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700}
#lrgMLPage .nm{font-size:15px;font-weight:700;color:var(--navy-1)}
#lrgMLPage .rl{font-size:12px;color:var(--ink-soft);margin-top:2px}
#lrgMLPage .langs{padding:0 20px 16px;display:flex;gap:5px;flex-wrap:wrap}
#lrgMLPage .lang-tag{padding:3px 10px;border-radius:10px;font-size:11px;font-weight:600;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe}
#lrgMLPage .dLink{display:block;padding:12px 20px;border-top:1px solid var(--line);font-size:13px;font-weight:600;color:var(--red);text-align:center}
#lrgMLPage .dCard:hover .dLink{background:#fef2f2}
#lrgMLPage .empty{text-align:center;padding:48px 20px;color:var(--ink-soft)}
#lrgMLPage .empty p{font-size:15px;line-height:1.6}
#lrgMLPage .ml-cta{display:inline-block;padding:14px 32px;background:var(--red);color:#fff;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none;margin-top:32px;transition:all .15s}
#lrgMLPage .ml-cta:hover{background:#e31837;transform:translateY(-1px)}
@media(max-width:768px){
#lrgMLPage .heroGrid{grid-template-columns:1fr;text-align:center}
#lrgMLPage .heroName{font-size:1.7rem}
#lrgMLPage .ml-controls{flex-direction:column}
#lrgMLPage .dGrid{grid-template-columns:1fr}
}
</style>';
});

add_action('wp', function() {
    if (is_page('multilingual-agents')) {
        remove_filter('the_content', 'wpautop');
    }
});

add_shortcode('lrg_multilingual_agents', function() {
    global $wpdb;

    $rows = $wpdb->get_results("
        SELECT u.ID, u.display_name, u.user_nicename,
               MAX(CASE WHEN um.meta_key='lrg_languages' THEN um.meta_value END) as languages,
               MAX(CASE WHEN um.meta_key='rss_mh_role' THEN um.meta_value END) as role_title,
               MAX(CASE WHEN um.meta_key='rss_mh_profile_url' THEN um.meta_value END) as profile_url
        FROM {$wpdb->users} u
        JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
        WHERE u.ID IN (
            SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'lrg_languages' AND meta_value != ''
        )
        GROUP BY u.ID
        ORDER BY u.display_name ASC
    ");

    $all_langs = [];
    $agents = [];
    foreach ($rows as $r) {
        $langs = array_filter(array_map('trim', explode(',', $r->languages)));
        foreach ($langs as $l) $all_langs[$l] = ($all_langs[$l] ?? 0) + 1;

        $nick = $r->user_nicename;
        $photo_file = WP_CONTENT_DIR . "/uploads/authors/{$nick}.png";
        $photo_url = file_exists($photo_file) ? content_url("uploads/authors/{$nick}.png") : '';

        $name_parts = explode(' ', trim($r->display_name));
        $initials = strtoupper(substr($name_parts[0], 0, 1));
        if (count($name_parts) > 1) $initials .= strtoupper(substr(end($name_parts), 0, 1));

        $agents[] = [
            'name' => $r->display_name,
            'nick' => $nick,
            'role' => $r->role_title ?: 'REALTOR',
            'languages' => $langs,
            'photo' => $photo_url,
            'initials' => $initials,
            'profile' => $r->profile_url ?: "https://lrgrealty.com/authors/{$nick}/",
        ];
    }
    ksort($all_langs);

    ob_start();
    ?>
<div id="lrgMLPage">

<header class="hero">
<div class="wrap">
<nav class="crumb" aria-label="Breadcrumb">
<a href="https://lrgrealty.com/">Home</a><span class="sep">&rsaquo;</span>
<span style="color:rgba(255,255,255,.8)">Multilingual Agents</span>
</nav>
<div class="heroGrid">
<div>
<div class="heroName">Multilingual Real Estate <span class="red">Agents</span></div>
<div class="heroSub">Our team speaks your language. Whether you are relocating from overseas, a Military family returning from a duty station abroad, or a first-generation buyer navigating the process for the first time, LRG has agents who can guide you in the language you are most comfortable with.</div>
</div>
</div>
</div>
</header>

<div class="ml-controls-bar">
<div class="wrap">
<div class="ml-controls">
<input type="text" class="ml-search" id="ml-search" placeholder="Search by agent name..." oninput="mlFilter()">
<div class="ml-pills" id="ml-pills">
<button class="ml-pill active" onclick="mlLang(this,'')" data-lang="">All</button>
<?php foreach ($all_langs as $lang => $count): ?>
<button class="ml-pill" onclick="mlLang(this,'<?= esc_attr($lang) ?>')" data-lang="<?= esc_attr($lang) ?>"><?= esc_html($lang) ?></button>
<?php endforeach; ?>
</div>
</div>
</div>
</div>

<div class="body">
<div class="wrap">

<?php if (empty($agents)): ?>
<div class="empty">
<p>Our multilingual agent profiles are coming soon. Contact LRG and we will match you with an agent who speaks your language.</p>
</div>
<?php else: ?>
<div class="dGrid" id="ml-grid">
<?php foreach ($agents as $a): ?>
<a class="dCard" href="<?= esc_url($a['profile']) ?>" data-name="<?= esc_attr(strtolower($a['name'])) ?>" data-langs="<?= esc_attr(strtolower(implode(',', $a['languages']))) ?>">
<div class="dHead">
<?php if ($a['photo']): ?>
<img src="<?= esc_url($a['photo']) ?>" alt="<?= esc_attr($a['name']) ?>">
<?php else: ?>
<div class="initials"><?= esc_html($a['initials']) ?></div>
<?php endif; ?>
<div>
<div class="nm"><?= esc_html($a['name']) ?></div>
<div class="rl"><?= esc_html($a['role']) ?></div>
</div>
</div>
<div class="langs">
<?php foreach ($a['languages'] as $l): ?>
<span class="lang-tag"><?= esc_html($l) ?></span>
<?php endforeach; ?>
</div>
<span class="dLink">View profile &rarr;</span>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div style="text-align:center">
<a class="ml-cta" href="https://lrgrealty.com/lrg-blog/connect-with-lrg/?ref=multilingual-agents">Connect with a Multilingual Agent</a>
</div>

</div>
</div>

</div>

<script>
let _mlLang = '';
function mlLang(btn, lang) {
    _mlLang = lang;
    document.querySelectorAll('#lrgMLPage .ml-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    mlFilter();
}
function mlFilter() {
    const q = document.getElementById('ml-search').value.toLowerCase().trim();
    document.querySelectorAll('#lrgMLPage .dCard').forEach(c => {
        const name = c.dataset.name || '';
        const langs = c.dataset.langs || '';
        c.style.display = ((!q || name.includes(q)) && (!_mlLang || langs.includes(_mlLang.toLowerCase()))) ? '' : 'none';
    });
}
</script>
    <?php
    return ob_get_clean();
});
