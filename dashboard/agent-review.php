<?php
/**
 * LRG Dashboard — Agent Review Portal v2
 * Scoped read+annotate access for agents reviewing their drafts.
 * Modern UX with inline highlight-to-suggest, contextual story prompts.
 */

require_once __DIR__ . '/dashboard-auth.php';

function lrg_create_review_token(int $user_id, int $post_id): string {
    $token = bin2hex(random_bytes(32));
    $pdo = lrg_get_pdo();
    $stmt = $pdo->prepare("INSERT INTO wp_lrg_review_tokens (token, user_id, post_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
    $stmt->execute([$token, $user_id, $post_id]);
    lrg_auth_log("REVIEW_TOKEN_CREATED user=$user_id post=$post_id token=" . substr($token, 0, 8) . "...");
    return $token;
}

function lrg_validate_review_token(string $token): ?array {
    if (strlen($token) !== 64) return null;
    $pdo = lrg_get_pdo();
    $stmt = $pdo->prepare("SELECT user_id, post_id, expires_at FROM wp_lrg_review_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['user_id' => (int)$row['user_id'], 'post_id' => (int)$row['post_id'], 'expires_at' => $row['expires_at']] : null;
}

function lrg_handle_review_login(string $review_token): ?array {
    $info = lrg_validate_review_token($review_token);
    if (!$info) return null;
    $session_token = lrg_generate_token();
    $pdo = lrg_get_pdo();
    $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';
    $scope = json_encode(['role' => 'agent_reviewer', 'post_id' => $info['post_id'], 'user_id' => $info['user_id']]);
    $stmt = $pdo->prepare("INSERT INTO $table (email, token, purpose, expires_at, ip_address, user_agent) VALUES (?, ?, 'session', DATE_ADD(NOW(), INTERVAL 7 DAY), ?, ?)");
    $stmt->execute(['reviewer-' . $info['user_id'] . '@review', $session_token, $_SERVER['REMOTE_ADDR'] ?? null, $scope]);
    setcookie('LRG_DASH_SESSION', $session_token, ['expires' => time() + 604800, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
    setcookie('wordpress_lrg_dash', '1', ['expires' => time() + 604800, 'path' => '/', 'secure' => true, 'samesite' => 'Lax']);
    lrg_auth_log("REVIEW_LOGIN user={$info['user_id']} post={$info['post_id']}");
    return $info;
}

function lrg_get_review_session(): ?array {
    $token = $_COOKIE['LRG_DASH_SESSION'] ?? '';
    if (empty($token) || strlen($token) !== 64) return null;
    $pdo = lrg_get_pdo();
    $table = LRG_DB_PREFIX . 'lrg_dashboard_sessions';
    $stmt = $pdo->prepare("SELECT email, user_agent FROM $table WHERE token = ? AND purpose = 'session' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || strpos($row['email'], 'reviewer-') !== 0) return null;
    $scope = json_decode($row['user_agent'], true);
    if (!$scope || ($scope['role'] ?? '') !== 'agent_reviewer') return null;
    return $scope;
}

function lrg_save_annotation(int $post_id, int $user_id, array $data): array {
    $type = in_array($data['type'] ?? '', ['highlight', 'rewrite', 'story_fill', 'story_decision', 'overall']) ? $data['type'] : 'highlight';
    $pdo = lrg_get_pdo();
    $stmt = $pdo->prepare("INSERT INTO wp_lrg_review_annotations (post_id, reviewer_uid, annotation_type, selected_text, comment_text, meta_key) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$post_id, $user_id, $type, mb_substr($data['selected_text'] ?? '', 0, 2000), mb_substr($data['comment_text'] ?? '', 0, 5000), $data['meta_key'] ?? null]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
}

function lrg_get_annotations(int $post_id): array {
    $pdo = lrg_get_pdo();
    $stmt = $pdo->prepare("SELECT id, annotation_type, selected_text, comment_text, meta_key, created_at FROM wp_lrg_review_annotations WHERE post_id = ? ORDER BY id ASC");
    $stmt->execute([$post_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function lrg_email_feedback(int $post_id, int $user_id): bool {
    $pdo = lrg_get_pdo();
    $stmt = $pdo->prepare("SELECT display_name FROM wp_users WHERE ID = ?");
    $stmt->execute([$user_id]);
    $name = $stmt->fetchColumn() ?: 'Agent';

    $stmt = $pdo->prepare("SELECT post_title FROM wp_posts WHERE ID = ?");
    $stmt->execute([$post_id]);
    $title = $stmt->fetchColumn() ?: "Post $post_id";

    $annotations = lrg_get_annotations($post_id);
    if (empty($annotations)) return false;

    $body = "REVIEW FEEDBACK from $name\nArticle: $title (post $post_id)\n";
    $body .= str_repeat('-', 60) . "\n\n";

    foreach ($annotations as $a) {
        $type = strtoupper($a['annotation_type']);
        $body .= "[$type]";
        if ($a['selected_text']) $body .= "\n  ORIGINAL: \"" . mb_substr($a['selected_text'], 0, 300) . "\"";
        $body .= "\n  " . ($a['annotation_type'] === 'rewrite' ? 'REWRITE' : 'NOTE') . ": " . $a['comment_text'] . "\n\n";
    }

    if (!function_exists('wp_mail')) {
        define('SHORTINIT', false);
        require_once LRG_WP_LOAD_PATH;
    }

    return wp_mail('randallyates82@gmail.com', "Review feedback: $title", $body);
}

function lrg_render_review_page(int $post_id, int $user_id): void {
    $pdo = lrg_get_pdo();
    $stmt = $pdo->prepare("SELECT post_title, post_content FROM wp_posts WHERE ID = ? AND post_author = ?");
    $stmt->execute([$post_id, $user_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) { echo '<p>Article not found.</p>'; return; }

    $stmt = $pdo->prepare("SELECT display_name FROM wp_users WHERE ID = ?");
    $stmt->execute([$user_id]);
    $name = ($stmt->fetch(PDO::FETCH_ASSOC))['display_name'] ?? 'Agent';
    $first = explode(' ', $name)[0];

    $content = $post['post_content'];

    // Extract story prompts from HTML comments + inject inline cards
    $prompt_count = 0;
    $content = preg_replace_callback('/<!--\s*(SOPHIA:[^>]+)\s*-->/', function($m) use (&$prompt_count) {
        $prompt_count++;
        $key = 'story_' . $prompt_count;
        $text = trim($m[1]);
        return '<div class="rv-inline-prompt" data-key="' . htmlspecialchars($key) . '" data-type="story_fill" data-meta="' . htmlspecialchars($text) . '">
<div class="rv-ip-icon">&#9998;</div>
<div class="rv-ip-body"><strong>Add your details here</strong><p>' . htmlspecialchars($text) . '</p>
<textarea class="rv-ip-input" placeholder="Write your specific details here..."></textarea>
<button class="rv-ip-btn" onclick="submitInlinePrompt(this)">Save</button><span class="rv-ip-saved"></span></div></div>';
    }, $content);

    $content = preg_replace_callback('/<!--\s*(OPTIONAL:[^>]+)\s*-->/', function($m) use (&$prompt_count) {
        $prompt_count++;
        $key = 'decision_' . $prompt_count;
        $text = trim($m[1]);
        return '<div class="rv-inline-decision" data-key="' . htmlspecialchars($key) . '" data-type="story_decision" data-meta="' . htmlspecialchars($text) . '">
<div class="rv-id-icon">?</div>
<div class="rv-id-body"><strong>Your decision needed</strong><p>' . htmlspecialchars($text) . '</p>
<div class="rv-id-btns"><button class="rv-id-yes" onclick="submitDecision(this,\'yes\')">Yes, include this</button><button class="rv-id-no" onclick="submitDecision(this,\'no\')">No, leave it out</button></div>
<span class="rv-id-saved"></span></div></div>';
    }, $content);

    $annotations = lrg_get_annotations($post_id);
    $ann_count = count($annotations);

    // Expiry
    $stmt = $pdo->prepare("SELECT expires_at FROM wp_lrg_review_tokens WHERE user_id = ? AND post_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id, $post_id]);
    $exp = $stmt->fetchColumn();
    $days_left = $exp ? max(0, (int)((strtotime($exp) - time()) / 86400)) : '?';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review: <?= htmlspecialchars($post['post_title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">
<style>
:root{--navy:#091A35;--red:#c8102e;--red-h:#e31837;--bg:#f7f8fa;--surface:#fff;--border:#e5e7eb;--text:#1a1a2e;--text-m:#6b7280;--text-d:#9ca3af;--ok:#059669;--warn:#d97706;--r:10px;--shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04)}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:15px;-webkit-font-smoothing:antialiased}

/* Top bar */
.rv-bar{position:sticky;top:0;z-index:100;background:var(--navy);padding:0 24px;height:56px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.rv-bar-brand{font-family:'Inter';font-size:17px;font-weight:800;color:#fff;letter-spacing:-.02em}
.rv-bar-brand span{color:var(--red)}
.rv-bar-sep{width:1px;height:24px;background:rgba(255,255,255,.15)}
.rv-bar-label{font-size:13px;color:rgba(255,255,255,.7);font-weight:500}
.rv-bar-pill{margin-left:auto;background:rgba(255,255,255,.1);color:rgba(255,255,255,.6);padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600}

/* Instruction banner */
.rv-banner{max-width:740px;margin:24px auto 0;background:linear-gradient(135deg,#eef2ff 0%,#e0e7ff 100%);border:1px solid #c7d2fe;border-radius:var(--r);padding:20px 24px;display:flex;align-items:flex-start;gap:16px}
.rv-banner-icon{width:40px;height:40px;background:var(--navy);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;flex-shrink:0}
.rv-banner h2{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:4px}
.rv-banner p{font-size:13px;color:#4338ca;line-height:1.5}
.rv-banner .rv-demo{display:inline-block;background:#fef08a;padding:1px 6px;border-radius:3px;font-weight:600;font-size:12px;color:#92400e;margin-top:4px}

/* Article column */
.rv-col{max-width:740px;margin:24px auto;padding:0 20px}

/* Article body */
.rv-body{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:40px 48px;box-shadow:var(--shadow);font-family:'Source Serif 4','Georgia',serif;font-size:16px;line-height:1.8;color:#2d2d3a}
.rv-body h1{font-family:'Inter',system-ui,sans-serif;font-size:24px;font-weight:800;line-height:1.3;margin-bottom:24px;color:var(--navy)}
.rv-body h2{font-family:'Inter',system-ui,sans-serif;font-size:19px;font-weight:700;color:var(--navy);margin:32px 0 12px;padding-top:20px;border-top:1px solid #f3f4f6}
.rv-body h3{font-family:'Inter',system-ui,sans-serif;font-size:15px;font-weight:700;margin:20px 0 8px}
.rv-body p{margin:0 0 16px}
.rv-body table{width:100%;border-collapse:collapse;font-family:'Inter',system-ui,sans-serif;font-size:13px;margin:16px 0}
.rv-body th{background:#f8fafc;text-align:left;padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-m);border-bottom:2px solid var(--border)}
.rv-body td{padding:10px 14px;border-bottom:1px solid #f3f4f6}
.rv-body ul{margin:0 0 16px 24px}.rv-body li{margin-bottom:8px}
.rv-body details{background:#fafbfc;border:1px solid var(--border);border-radius:8px;margin:8px 0;padding:14px 18px}
.rv-body summary{font-family:'Inter',system-ui,sans-serif;font-weight:600;cursor:pointer;font-size:14px}
.rv-body p:hover{background:rgba(99,102,241,.04);border-radius:4px;cursor:text;transition:background .15s}

/* Inline story prompt */
.rv-inline-prompt{background:#fffbeb;border:2px solid #fbbf24;border-radius:var(--r);padding:18px 20px;margin:20px 0;display:flex;gap:14px;align-items:flex-start}
.rv-ip-icon{width:32px;height:32px;background:#f59e0b;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0}
.rv-ip-body{flex:1;font-family:'Inter',system-ui,sans-serif;font-size:13px}
.rv-ip-body strong{display:block;color:#92400e;margin-bottom:4px;font-size:14px}
.rv-ip-body p{color:#78350f;margin-bottom:10px;font-size:13px;line-height:1.5}
.rv-ip-input{width:100%;border:1px solid #fbbf24;border-radius:6px;padding:10px 12px;font-size:14px;font-family:'Source Serif 4',serif;min-height:80px;resize:vertical;background:#fffef5}
.rv-ip-input:focus{outline:none;border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.15)}
.rv-ip-btn{margin-top:8px;padding:8px 18px;background:#92400e;color:#fff;border:none;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;font-family:'Inter',sans-serif}
.rv-ip-btn:hover{background:#78350f}
.rv-ip-saved{color:var(--ok);font-size:12px;font-weight:600;margin-left:10px;display:none}

/* Inline decision card */
.rv-inline-decision{background:#fef2f2;border:2px solid #fca5a5;border-radius:var(--r);padding:18px 20px;margin:20px 0;display:flex;gap:14px;align-items:flex-start}
.rv-id-icon{width:32px;height:32px;background:var(--red);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;flex-shrink:0}
.rv-id-body{flex:1;font-family:'Inter',system-ui,sans-serif;font-size:13px}
.rv-id-body strong{display:block;color:#991b1b;margin-bottom:4px;font-size:14px}
.rv-id-body p{color:#7f1d1d;margin-bottom:12px;font-size:13px;line-height:1.5}
.rv-id-btns{display:flex;gap:8px}
.rv-id-yes,.rv-id-no{padding:8px 18px;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;border:none;font-family:'Inter',sans-serif}
.rv-id-yes{background:var(--ok);color:#fff}.rv-id-yes:hover{background:#047857}
.rv-id-no{background:#e5e7eb;color:var(--text)}.rv-id-no:hover{background:#d1d5db}
.rv-id-saved{color:var(--ok);font-size:12px;font-weight:600;margin-top:8px;display:none}

/* Highlight popover */
.rv-popover{display:none;position:fixed;z-index:200;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.15);width:420px;max-width:90vw;overflow:hidden}
.rv-popover.show{display:block}
.rv-pop-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.rv-pop-head h4{font-size:14px;font-weight:700}
.rv-pop-close{background:none;border:none;font-size:18px;color:var(--text-d);cursor:pointer;padding:4px}
.rv-pop-body{padding:18px}
.rv-pop-orig{background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:10px 14px;font-size:13px;color:var(--text-m);line-height:1.5;margin-bottom:14px;font-style:italic;max-height:100px;overflow-y:auto}
.rv-pop-tabs{display:flex;gap:4px;margin-bottom:12px}
.rv-pop-tab{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--bg);color:var(--text-m);font-family:'Inter',sans-serif}
.rv-pop-tab.active{background:var(--navy);color:#fff;border-color:var(--navy)}
.rv-pop-textarea{width:100%;border:1px solid var(--border);border-radius:8px;padding:12px;font-size:14px;font-family:'Source Serif 4',serif;min-height:80px;resize:vertical}
.rv-pop-textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(200,16,46,.1)}
.rv-pop-foot{padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.rv-pop-foot button{padding:8px 18px;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;border:none;font-family:'Inter',sans-serif}
.rv-pop-cancel{background:#f3f4f6;color:var(--text)}.rv-pop-cancel:hover{background:#e5e7eb}
.rv-pop-submit{background:var(--red);color:#fff}.rv-pop-submit:hover{background:var(--red-h)}

/* Overall + submit */
.rv-overall{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;margin-top:24px;box-shadow:var(--shadow)}
.rv-overall h3{font-size:15px;font-weight:700;margin-bottom:4px}
.rv-overall .rv-sub{font-size:13px;color:var(--text-m);margin-bottom:14px}
.rv-overall textarea{width:100%;border:1px solid var(--border);border-radius:8px;padding:14px;font-size:14px;font-family:'Inter',sans-serif;min-height:100px;resize:vertical}
.rv-overall textarea:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(200,16,46,.1)}

/* Sticky submit footer */
.rv-footer{position:sticky;bottom:0;background:var(--surface);border-top:1px solid var(--border);padding:12px 0;margin-top:32px;z-index:50}
.rv-footer-inner{max-width:740px;margin:0 auto;padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.rv-count{font-size:13px;color:var(--text-m)}<strong id="rv-ct">0</strong> suggestions so far</span>
.rv-submit-all{padding:12px 28px;background:var(--red);color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;font-family:'Inter',sans-serif;box-shadow:0 2px 8px rgba(200,16,46,.25)}
.rv-submit-all:hover{background:var(--red-h);box-shadow:0 4px 16px rgba(200,16,46,.35);transform:translateY(-1px)}
.rv-submit-all:disabled{opacity:.5;cursor:default;transform:none}

/* Existing notes */
.rv-notes{background:#f0fdf4;border:1px solid #86efac;border-radius:var(--r);padding:16px 20px;margin-bottom:20px}
.rv-notes h4{font-size:13px;font-weight:700;color:#166534;margin-bottom:8px}
.rv-note{padding:8px 0;border-bottom:1px solid #d1fae5;font-size:13px}
.rv-note:last-child{border-bottom:none}
.rv-note-type{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-d);letter-spacing:.04em}
.rv-note-quote{font-style:italic;color:#475569;font-size:12px;margin:2px 0}
.rv-note-text{color:var(--text)}

/* Toast */
.rv-toast{position:fixed;bottom:80px;right:24px;background:var(--ok);color:#fff;padding:12px 20px;border-radius:var(--r);font-size:13px;font-weight:600;display:none;z-index:400;box-shadow:0 4px 12px rgba(0,0,0,.15)}

@media(max-width:768px){
.rv-body{padding:24px 20px}
.rv-banner{flex-direction:column;gap:10px;margin:16px 12px 0}
.rv-col{padding:0 12px;margin:16px auto}
.rv-popover{width:calc(100vw - 24px);left:12px !important;right:12px !important}
.rv-footer-inner{flex-direction:column;text-align:center}
}
</style>
</head>
<body>

<div class="rv-bar">
<div class="rv-bar-brand">LRG <span>Review</span></div>
<div class="rv-bar-sep"></div>
<div class="rv-bar-label">Review your draft</div>
<div class="rv-bar-pill"><?= $days_left ?> day<?= $days_left != 1 ? 's' : '' ?> left</div>
</div>

<div class="rv-banner">
<div class="rv-banner-icon">&#9998;</div>
<div>
<h2>Hi <?= htmlspecialchars($first) ?>, welcome to your article review</h2>
<p><strong>Select any sentence</strong> to rewrite it in your own words or leave a note. Look for the <span class="rv-demo">yellow cards</span> where we need your specific details. When you are done, hit "Submit all feedback" at the bottom.</p>
</div>
</div>

<div class="rv-col">

<?php if ($ann_count > 0): ?>
<div class="rv-notes">
<h4>Your previous notes (<?= $ann_count ?>)</h4>
<?php foreach ($annotations as $a): ?>
<div class="rv-note">
<span class="rv-note-type"><?= htmlspecialchars($a['annotation_type']) ?></span>
<?php if ($a['selected_text']): ?><div class="rv-note-quote">"<?= htmlspecialchars(mb_substr($a['selected_text'], 0, 120)) ?>"</div><?php endif; ?>
<div class="rv-note-text"><?= htmlspecialchars($a['comment_text']) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="rv-body" id="rv-body">
<h1><?= htmlspecialchars($post['post_title']) ?></h1>
<?= $content ?>
</div>

<div class="rv-overall">
<h3>Overall feedback</h3>
<div class="rv-sub">Anything else to change, add, or remove? General direction, tone, facts to correct?</div>
<textarea id="rv-overall" placeholder="Your overall thoughts on the draft..."></textarea>
</div>

</div>

<div class="rv-footer">
<div class="rv-footer-inner">
<span class="rv-count"><strong id="rv-ct"><?= $ann_count ?></strong> suggestion<?= $ann_count != 1 ? 's' : '' ?> so far</span>
<button class="rv-submit-all" onclick="submitAll()">Submit all feedback</button>
</div>
</div>

<!-- Highlight popover -->
<div class="rv-popover" id="rv-popover">
<div class="rv-pop-head"><h4>Suggest a change</h4><button class="rv-pop-close" onclick="closePop()">&times;</button></div>
<div class="rv-pop-body">
<div class="rv-pop-orig" id="rv-pop-orig"></div>
<div class="rv-pop-tabs">
<button class="rv-pop-tab active" onclick="popTab(this,'rewrite')">Rewrite it</button>
<button class="rv-pop-tab" onclick="popTab(this,'note')">Just a note</button>
</div>
<textarea class="rv-pop-textarea" id="rv-pop-text" placeholder="Rewrite this in your own words..."></textarea>
</div>
<div class="rv-pop-foot">
<button class="rv-pop-cancel" onclick="closePop()">Cancel</button>
<button class="rv-pop-submit" onclick="submitPop()">Save suggestion</button>
</div>
</div>

<div class="rv-toast" id="rv-toast"></div>

<script>
const POST_ID = <?= $post_id ?>;
let _selectedText = '';
let _popMode = 'rewrite';
let _count = <?= $ann_count ?>;

// Highlight-to-suggest
document.getElementById('rv-body').addEventListener('mouseup', function(e) {
    const sel = window.getSelection();
    const text = sel.toString().trim();
    if (text.length < 8) return;
    _selectedText = text;
    const pop = document.getElementById('rv-popover');
    const orig = document.getElementById('rv-pop-orig');
    const ta = document.getElementById('rv-pop-text');
    orig.textContent = text.length > 300 ? text.substring(0, 300) + '...' : text;
    _popMode = 'rewrite';
    ta.value = text;
    ta.placeholder = 'Rewrite this in your own words...';
    document.querySelectorAll('.rv-pop-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.rv-pop-tab').classList.add('active');
    // Position near selection
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    pop.style.top = Math.min(rect.bottom + 8, window.innerHeight - 350) + 'px';
    pop.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - 440)) + 'px';
    pop.classList.add('show');
    setTimeout(() => ta.focus(), 100);
});

function closePop() { document.getElementById('rv-popover').classList.remove('show'); }

function popTab(btn, mode) {
    _popMode = mode;
    document.querySelectorAll('.rv-pop-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    const ta = document.getElementById('rv-pop-text');
    if (mode === 'rewrite') { ta.value = _selectedText; ta.placeholder = 'Rewrite this in your own words...'; }
    else { ta.value = ''; ta.placeholder = 'What should change about this?'; }
}

function submitPop() {
    const text = document.getElementById('rv-pop-text').value.trim();
    if (!text) return;
    postAnnotation({
        type: _popMode === 'rewrite' ? 'rewrite' : 'highlight',
        selected_text: _selectedText,
        comment_text: text
    });
    closePop();
    window.getSelection().removeAllRanges();
}

function submitInlinePrompt(btn) {
    const card = btn.closest('.rv-inline-prompt');
    const text = card.querySelector('.rv-ip-input').value.trim();
    if (!text) return;
    postAnnotation({type: card.dataset.type, comment_text: text, meta_key: card.dataset.meta});
    card.querySelector('.rv-ip-saved').textContent = 'Saved';
    card.querySelector('.rv-ip-saved').style.display = 'inline';
}

function submitDecision(btn, choice) {
    const card = btn.closest('.rv-inline-decision');
    postAnnotation({type: 'story_decision', comment_text: choice.toUpperCase() + ' - ' + card.dataset.meta, meta_key: card.dataset.meta});
    card.querySelector('.rv-id-saved').textContent = choice === 'yes' ? 'Including' : 'Leaving out';
    card.querySelector('.rv-id-saved').style.display = 'block';
    card.querySelectorAll('button').forEach(b => b.disabled = true);
}

function submitAll() {
    const overall = document.getElementById('rv-overall').value.trim();
    if (overall) {
        postAnnotation({type: 'overall', comment_text: overall}, true);
    } else {
        sendEmail();
    }
}

function postAnnotation(data, thenEmail) {
    fetch('?route=review-api&action=annotate', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    }).then(r => r.json()).then(d => {
        if (d.ok) {
            _count++;
            document.getElementById('rv-ct').textContent = _count;
            if (thenEmail) sendEmail();
            else toast('Saved');
        } else toast('Error: ' + (d.error || 'unknown'), true);
    }).catch(e => toast('Error', true));
}

function sendEmail() {
    fetch('?route=review-api&action=send-email', {method:'POST'})
    .then(r => r.json()).then(d => {
        if (d.ok) toast('All feedback submitted and emailed to Randall');
        else toast('Saved but email failed: ' + (d.error || ''), true);
    }).catch(() => toast('Saved but email may have failed', true));
}

function toast(msg, err) {
    const t = document.getElementById('rv-toast');
    t.textContent = msg;
    t.style.background = err ? '#ef4444' : '#059669';
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 4000);
}
</script>

</body>
</html>
<?php
}
