<?php
/**
 * Plugin Name: LRG Author Page Styles
 * Description: Styles for author profile pages (.lrgAuthor layout, pills, sidebar, credentials).
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) { exit; }

add_action('wp_head', function() {
    if (!is_page()) { return; }
    $post = get_post();
    if (!$post || (strpos($post->post_content, 'lrgAuthor') === false && strpos($post->post_content, 'lrgAuthorPage') === false)) { return; }
    ?>
<style id="lrg-author-styles">
.lrgAuthor{--ink:#1a2b4a;--ink-soft:#46577a;--accent:#b3122a;--gold:#c9a24a;--line:#e7ebf2;--bg-soft:#f6f8fc;--bg-band:#0f1d38;--radius:16px;--maxw:1080px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);line-height:1.6;-webkit-font-smoothing:antialiased;}
.lrgAuthor *{box-sizing:border-box;}
.lrgAuthor .laWrap{max-width:var(--maxw);margin:0 auto;padding:0 22px;}
.lrgAuthor .laHero{background:radial-gradient(1200px 400px at 85% -20%,rgba(201,162,74,.18),transparent 60%),linear-gradient(160deg,#14264a 0%,#0f1d38 60%,#0b1730 100%);color:#fff;border-radius:var(--radius);margin-top:18px;padding:38px 34px;display:grid;grid-template-columns:230px 1fr;gap:34px;align-items:center;box-shadow:0 18px 50px -24px rgba(11,23,48,.65);}
.lrgAuthor .laPhoto{width:230px;height:230px;border-radius:18px;overflow:hidden;border:3px solid rgba(255,255,255,.16);box-shadow:0 12px 34px -14px rgba(0,0,0,.6);background:#22345a;}
.lrgAuthor .laPhoto img{width:100%;height:100%;object-fit:cover;display:block;}
.lrgAuthor .laPhoto .laPhotoFallback{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9fb0d0;font-size:.85rem;text-align:center;padding:14px;}
.lrgAuthor .laName{font-family:Georgia,"Times New Roman",serif;font-size:2.3rem;line-height:1.1;margin:0 0 6px;color:#fff;font-weight:700;}
.lrgAuthor .laRole{text-transform:uppercase;letter-spacing:.12em;font-size:.82rem;font-weight:700;color:#c9d6ee;margin:0 0 16px;}
.lrgAuthor .laCred{display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;background:rgba(255,255,255,.08);border:1px solid rgba(201,162,74,.45);border-left:4px solid var(--gold);border-radius:12px;padding:11px 15px;margin:0 0 14px;}
.lrgAuthor .laCred .laBadge{font-weight:800;color:#fff;font-size:.92rem;letter-spacing:.01em;}
.lrgAuthor .laCred .laCredType{color:#cfd9ef;font-size:.86rem;}
.lrgAuthor .laCred .laTrec{display:block;width:100%;font-size:.74rem;color:#aebbd6;margin-top:2px;}
.lrgAuthor .laCred .laTrec a{color:#e3c987;text-decoration:underline;}
.lrgAuthor .laHeroCtas{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;}
.lrgAuthor .laBtn{display:inline-block;font-weight:700;font-size:.92rem;padding:12px 20px;border-radius:11px;text-decoration:none;cursor:pointer;transition:transform .12s ease,box-shadow .12s ease;}
.lrgAuthor .laBtn:hover{transform:translateY(-1px);}
.lrgAuthor .laBtn-primary{background:var(--accent);color:#fff;box-shadow:0 10px 22px -10px rgba(179,18,42,.8);}
.lrgAuthor .laBtn-ghost{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.4);}
.lrgAuthor .laBody{display:grid;grid-template-columns:1fr 330px;gap:36px;margin-top:30px;}
.lrgAuthor .laMain h2{font-family:Georgia,serif;font-size:1.5rem;margin:0 0 14px;border-bottom:2px solid var(--line);padding-bottom:10px;color:var(--ink);}
.lrgAuthor .laMain p{max-width:680px;font-size:.95rem;color:var(--ink-soft);margin:0 0 18px;}
.lrgAuthor .laFocus{display:flex !important;flex-wrap:wrap !important;gap:9px !important;margin:4px 0 8px;}
.lrgAuthor .laFocus span,.lrgAuthor span.laFocusChip{display:inline-block !important;background:#e8edf5 !important;border:1px solid #c2cce0 !important;color:#1a2b4a !important;font-size:.86rem !important;font-weight:600 !important;padding:8px 13px !important;border-radius:999px !important;margin:0 !important;}
.lrgAuthor .laArticles{list-style:none;margin:6px 0 0;padding:0;}
.lrgAuthor .laArticles li{border-bottom:1px solid var(--line);}
.lrgAuthor .laArticles a{display:block;padding:13px 4px;text-decoration:none;color:var(--ink);font-weight:600;transition:padding .12s ease,color .12s ease;}
.lrgAuthor .laArticles a:hover{padding-left:10px;color:var(--accent);}
.lrgAuthor .laArticles a span{display:block;font-weight:400;font-size:.84rem;color:var(--ink-soft);margin-top:2px;}
.lrgAuthor .laSide{display:flex;flex-direction:column;gap:18px;}
.lrgAuthor .laSideCard{background:var(--bg-soft);border:1px solid var(--line);border-radius:var(--radius);padding:20px;}
.lrgAuthor .laSideCard h3{font-size:1.05rem;margin:0 0 14px;}
.lrgAuthor .laFacts{list-style:none;margin:0;padding:0;}
.lrgAuthor .laFacts li{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed var(--line);font-size:.9rem;}
.lrgAuthor .laFacts li:last-child{border-bottom:0;}
.lrgAuthor .laFacts .k{color:var(--ink-soft);}
.lrgAuthor .laFacts .v{color:var(--ink);font-weight:700;text-align:right;}
.lrgAuthor .laSideCta{background:linear-gradient(160deg,#14264a,#0f1d38);color:#fff;border:0;}
.lrgAuthor .laSideCta h3{color:#fff;}
.lrgAuthor .laSideCta p{color:#c9d6ee;font-size:.9rem;margin:0 0 14px;}
.lrgAuthor .laSideCta .laBtn-primary{width:100%;text-align:center;}
.lrgAuthor .laTrust{display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--ink-soft);margin-top:12px;}
.lrgAuthor .laTrust svg{flex:none;}
@media(max-width:860px){.lrgAuthor .laHero{grid-template-columns:1fr;text-align:center;justify-items:center;padding:30px 22px;}.lrgAuthor .laCred{justify-content:center;}.lrgAuthor .laHeroCtas{justify-content:center;}.lrgAuthor .laBody{grid-template-columns:1fr;}.lrgAuthor .laMain p{max-width:none;}}

/* === Dark-surface text overrides (beats lrg-heading-enforce !important) === */
.lrgAuthor .laHero,
.lrgAuthor .laHero h1,
.lrgAuthor .laHero h2,
.lrgAuthor .laHero h3,
.lrgAuthor .laHero h4 {
  color: #ffffff !important;
}
.lrgAuthor .laHero a {
  color: #e3c987 !important;
}
.lrgAuthor .laHero a:hover {
  color: #ffffff !important;
}
.lrgAuthor .laHero .laRole,
.lrgAuthor .laHero .laTrec {
  color: rgba(255,255,255,.7) !important;
}
.lrgAuthor .laSideCta,
.lrgAuthor .laSideCta h2,
.lrgAuthor .laSideCta h3,
.lrgAuthor .laSideCta h4 {
  color: #ffffff !important;
}
.lrgAuthor .laSideCta p {
  color: rgba(255,255,255,.85) !important;
}
.lrgAuthor .laSideCta a {
  color: #e3c987 !important;
}
.lrgAuthor .laBtn-primary {
  color: #ffffff !important;
}
.lrgAuthor .laBtn-ghost {
  color: #ffffff !important;
}


/* Hide theme H1 on author pages — the card .laName IS the page heading */
.lrgAuthor ~ .entry-title, .entry-title.main_title { display: none !important; }
body.page-child.parent-pageid-5480 .entry-title.main_title { display: none !important; }

/* ============================================================
   LRG AUTHOR PAGE v2 — scoped to #lrgAuthorPage
   navy gradient hero · red/white accents · Source Serif 4 + DM Sans
   ============================================================ */
#lrgAuthorPage{--navy-1:#091A35;--navy-2:#173B67;--red:#c8102e;--red-dark:#a50d26;--red-soft:#fbe9ec;--ink:#1a2233;--ink-soft:#475067;--line:#e4e7ee;--paper:#f6f5f1;--card:#ffffff;font-family:"DM Sans",system-ui,-apple-system,sans-serif !important;color:var(--ink) !important;background:var(--paper) !important;line-height:1.6 !important;-webkit-font-smoothing:antialiased !important}
#lrgAuthorPage *{box-sizing:border-box !important}
#lrgAuthorPage h1,#lrgAuthorPage h2,#lrgAuthorPage h3,#lrgAuthorPage h4,#lrgAuthorPage h5,#lrgAuthorPage p{margin:0 !important;padding:0 !important;max-width:none !important}
#lrgAuthorPage img{max-width:100% !important;display:block !important}
#lrgAuthorPage a{color:inherit !important;text-decoration:none !important}
#lrgAuthorPage .wrap{max-width:1080px !important;margin:0 auto !important;padding:0 28px !important}
#lrgAuthorPage .hero{position:relative !important;background:linear-gradient(135deg,var(--navy-1) 0%,var(--navy-2) 100%) !important;color:#fff !important;padding:34px 0 64px !important;overflow:hidden !important}
#lrgAuthorPage .hero::after{content:"" !important;position:absolute !important;left:0 !important;right:0 !important;bottom:0 !important;height:4px !important;background:linear-gradient(90deg,var(--red) 0%,var(--red) 70%,#fff 70%,#fff 100%) !important}
#lrgAuthorPage .crumb{font-size:.78rem !important;letter-spacing:.02em !important;color:#9fb2cd !important;margin-bottom:30px !important}
#lrgAuthorPage .crumb a:hover{color:#fff !important}
#lrgAuthorPage .crumb .sep{margin:0 8px !important;color:#54688a !important}
#lrgAuthorPage .heroGrid{display:grid !important;grid-template-columns:200px 1fr !important;gap:42px !important;align-items:center !important}
#lrgAuthorPage .shot{width:200px !important;height:200px !important;border-radius:50% !important;border:3px solid var(--red) !important;padding:5px !important;background:var(--navy-1) !important;box-shadow:0 14px 44px rgba(0,0,0,.4) !important}
#lrgAuthorPage .shot img{width:100% !important;height:100% !important;border-radius:50% !important;object-fit:cover !important}
#lrgAuthorPage .eyebrow{font-size:.76rem !important;font-weight:700 !important;letter-spacing:.2em !important;text-transform:uppercase !important;color:var(--red) !important;margin-bottom:14px !important}
#lrgAuthorPage .heroName{font-family:"Source Serif 4",Georgia,serif !important;font-weight:700 !important;font-size:clamp(2.3rem,5vw,3.6rem) !important;line-height:1.02 !important;letter-spacing:-.015em !important;margin-bottom:14px !important;color:#fff !important}
#lrgAuthorPage .heroName .red{color:var(--red) !important}
#lrgAuthorPage .lane{display:inline-block !important;font-family:"Source Serif 4",Georgia,serif !important;font-size:clamp(1.25rem,2.3vw,1.6rem) !important;font-weight:500 !important;font-style:italic !important;color:#fff !important;margin-bottom:24px !important;padding-bottom:6px !important;border-bottom:2px solid var(--red) !important}
#lrgAuthorPage .trust{display:flex !important;flex-wrap:wrap !important;gap:11px 24px !important;font-size:.85rem !important;color:#bccadf !important}
#lrgAuthorPage .trust span{display:inline-flex !important;align-items:center !important;gap:8px !important}
#lrgAuthorPage .trust .dot{width:6px !important;height:6px !important;border-radius:50% !important;background:var(--red) !important}
#lrgAuthorPage .body{padding:54px 0 70px !important}
#lrgAuthorPage .cols{display:grid !important;grid-template-columns:1fr 320px !important;gap:46px !important;align-items:start !important}
#lrgAuthorPage .stamp{display:flex !important;align-items:center !important;gap:13px !important;background:var(--red-soft) !important;border:1px solid #f3ccd3 !important;border-left:4px solid var(--red) !important;border-radius:10px !important;padding:15px 18px !important;margin-bottom:30px !important}
#lrgAuthorPage .stamp svg{flex:0 0 auto !important;color:var(--red) !important}
#lrgAuthorPage .stamp .lic{font-weight:700 !important;font-size:.95rem !important;color:var(--navy-1) !important}
#lrgAuthorPage .stamp .type{color:var(--ink-soft) !important;font-size:.86rem !important}
#lrgAuthorPage .stamp .verify{margin-left:auto !important;font-size:.82rem !important;font-weight:600 !important;color:var(--red) !important;border-bottom:1px solid transparent !important;transition:border-color .15s !important}
#lrgAuthorPage .stamp .verify:hover{border-color:var(--red) !important}
#lrgAuthorPage .kicker{font-size:.74rem !important;font-weight:600 !important;letter-spacing:.16em !important;text-transform:uppercase !important;color:var(--red) !important;margin-bottom:12px !important}
#lrgAuthorPage h2{font-family:"Source Serif 4",Georgia,serif !important;font-weight:600 !important;font-size:1.85rem !important;line-height:1.12 !important;color:var(--navy-1) !important;margin-bottom:20px !important;letter-spacing:-.01em !important}
#lrgAuthorPage .bio p{margin-bottom:18px !important;color:var(--ink-soft) !important;font-size:1.04rem !important}
#lrgAuthorPage .bio p:last-child{margin-bottom:0 !important}
#lrgAuthorPage .listings{margin-top:40px !important;padding:30px 32px !important;border-radius:14px !important;background:linear-gradient(135deg,var(--navy-1),var(--navy-2)) !important;color:#fff !important;position:relative !important;overflow:hidden !important}
#lrgAuthorPage .listings::before{content:"" !important;position:absolute !important;top:0 !important;left:0 !important;width:5px !important;height:100% !important;background:var(--red) !important}
#lrgAuthorPage .listings h3{font-family:"Source Serif 4",serif !important;font-weight:600 !important;font-size:1.4rem !important;margin-bottom:8px !important;color:#fff !important;display:inline-block !important;padding-bottom:4px !important;border-bottom:2px solid var(--red) !important}
#lrgAuthorPage .listings p{color:#bccadf !important;font-size:.95rem !important;margin-bottom:20px !important;max-width:46ch !important}
#lrgAuthorPage .btn{display:inline-flex !important;align-items:center !important;gap:9px !important;font-family:"DM Sans",sans-serif !important;font-weight:600 !important;font-size:.98rem !important;padding:13px 26px !important;border-radius:9px !important;cursor:pointer !important;transition:transform .12s,background .15s !important}
#lrgAuthorPage .btn-red{background:var(--red) !important;color:#fff !important}
#lrgAuthorPage .btn-red:hover{background:var(--red-dark) !important;transform:translateY(-1px) !important}
#lrgAuthorPage .btn-red:focus-visible{outline:3px solid #ff9aa8 !important;outline-offset:2px !important}
#lrgAuthorPage .btn .arw{transition:transform .15s !important}
#lrgAuthorPage .btn:hover .arw{transform:translateX(3px) !important}
#lrgAuthorPage .side{position:sticky !important;top:24px !important}
#lrgAuthorPage .ccard{background:var(--card) !important;border:1px solid var(--line) !important;border-top:3px solid var(--red) !important;border-radius:12px !important;padding:26px 24px !important;box-shadow:0 8px 28px rgba(16,28,54,.06) !important}
#lrgAuthorPage .ccard h4{font-family:"Source Serif 4",serif !important;font-weight:600 !important;font-size:1.15rem !important;color:var(--navy-1) !important;margin-bottom:4px !important}
#lrgAuthorPage .ccard .sub{font-size:.82rem !important;color:var(--ink-soft) !important;margin-bottom:20px !important}
#lrgAuthorPage .crow{display:flex !important;align-items:flex-start !important;gap:12px !important;padding:11px 0 !important;border-top:1px solid var(--line) !important}
#lrgAuthorPage .crow:first-of-type{border-top:none !important}
#lrgAuthorPage .crow svg{flex:0 0 auto !important;color:var(--red) !important;margin-top:2px !important}
#lrgAuthorPage .crow .lbl{font-size:.72rem !important;letter-spacing:.06em !important;text-transform:uppercase !important;color:#8a93a6 !important}
#lrgAuthorPage .crow .val{font-weight:600 !important;font-size:.97rem !important;color:var(--ink) !important}
#lrgAuthorPage .crow a.val:hover{color:var(--red) !important}
#lrgAuthorPage .ccard .work{display:block !important;text-align:center !important;margin-top:22px !important;width:100% !important;background:var(--red) !important;color:#fff !important;font-weight:600 !important;font-size:.97rem !important;padding:14px !important;border-radius:9px !important;transition:background .15s,transform .12s !important}
#lrgAuthorPage .ccard .work:hover{background:var(--red-dark) !important;transform:translateY(-1px) !important}
#lrgAuthorPage .ccard .work:focus-visible{outline:3px solid #ff9aa8 !important;outline-offset:2px !important}
#lrgAuthorPage .articles{border-top:1px solid var(--line) !important;padding:48px 0 0 !important;margin-top:54px !important}
#lrgAuthorPage .articles .kicker{margin-bottom:10px !important}
#lrgAuthorPage .aGrid{display:grid !important;grid-template-columns:repeat(3,1fr) !important;gap:20px !important;margin-top:24px !important}
#lrgAuthorPage .aCard{background:var(--card) !important;border:1px solid var(--line) !important;border-radius:11px !important;padding:22px 20px !important;transition:transform .14s,box-shadow .14s,border-color .14s !important}
#lrgAuthorPage .aCard:hover{transform:translateY(-3px) !important;box-shadow:0 12px 30px rgba(16,28,54,.09) !important;border-color:#d4dae6 !important}
#lrgAuthorPage .aCard .tag{font-size:.68rem !important;font-weight:700 !important;letter-spacing:.1em !important;text-transform:uppercase !important;color:var(--red) !important;margin-bottom:11px !important}
#lrgAuthorPage .aCard h5{font-family:"Source Serif 4",serif !important;font-weight:600 !important;font-size:1.08rem !important;line-height:1.25 !important;color:var(--navy-1) !important;margin-bottom:10px !important}
#lrgAuthorPage .aCard .more{font-size:.85rem !important;font-weight:600 !important;color:var(--red) !important}
@media(max-width:860px){
  #lrgAuthorPage .cols{grid-template-columns:1fr !important;gap:34px !important}
  #lrgAuthorPage .side{position:static !important}
  #lrgAuthorPage .heroGrid{grid-template-columns:1fr !important;gap:24px !important;text-align:center !important;justify-items:center !important}
  #lrgAuthorPage .trust{justify-content:center !important}
  #lrgAuthorPage .aGrid{grid-template-columns:1fr !important}
}
@media(max-width:520px){
  #lrgAuthorPage .wrap{padding:0 18px !important}
  #lrgAuthorPage .stamp{flex-wrap:wrap !important}
  #lrgAuthorPage .stamp .verify{margin-left:0 !important;width:100% !important}
}
@media(prefers-reduced-motion:reduce){
  #lrgAuthorPage *{transition:none !important}
}
</style>
    <?php
});

// v2 author pages: disable wpautop, hide theme H1, import fonts
add_action('template_redirect', function () {
    if (!is_page()) return;
    $post = get_post();
    if ($post && strpos($post->post_content, 'lrgAuthorPage') !== false) {
        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'shortcode_unautop');
    }
});

add_action('wp_head', function () {
    if (!is_page()) return;
    $post = get_post();
    if (!$post || strpos($post->post_content, 'lrgAuthorPage') === false) return;
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,500;0,8..60,600;0,8..60,700;1,8..60,400&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
    $pid = $post->ID;
    echo "<style id=\"lrg-author-v2-overrides\">";
    echo ".page-id-{$pid} .entry-title.main_title{display:none !important}";
    echo ".page-id-{$pid} .container{width:100% !important;max-width:100% !important;padding:0 !important}";
    echo ".page-id-{$pid} #content-area{padding:0 !important}";
    echo ".page-id-{$pid} #left-area{padding-bottom:0 !important}";
    echo ".page-id-{$pid} .entry-content{padding:0 !important;margin:0 !important}";
    echo ".page-id-{$pid} [class*=\"rss-mh\"],.page-id-{$pid} .rss-meta-header{display:none !important}";echo "</style>\n";
}, 4);
