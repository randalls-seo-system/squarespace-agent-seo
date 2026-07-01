# Deploy Guide

## Prerequisites

- WP Engine SSH access (`ssh -i ~/.ssh/key INSTALL@INSTALL.ssh.wpengine.net`)
- Google service account JSON with GSC + GA4 Viewer access
- The WPE install's MySQL credentials (from wp-config.php)

## Install Steps

### 1. Dashboard Directory

```bash
ssh INSTALL@INSTALL.ssh.wpengine.net 'mkdir -p /nas/content/live/INSTALL/dashboard/data'
```

Upload all files from `dashboard/` to `/nas/content/live/INSTALL/dashboard/`.

### 2. Configuration

Copy the example configs and fill in real values:

```bash
cp dashboard/data/auth-config.example.php dashboard/data/auth-config.php
cp dashboard/config.example.php dashboard/config.php
```

**auth-config.php** — Set:
- `LRG_DB_NAME`, `LRG_DB_USER`, `LRG_DB_PASS` (from wp-config.php)
- `LRG_WP_LOAD_PATH` (`/nas/content/live/INSTALL/wp-load.php`)
- `LRG_DASHBOARD_ALLOWED_EMAILS` (operator email addresses)
- `LRG_PERMANENT_LOGIN_KEYS` (generate with `openssl rand -hex 32`)

**config.php** — Set:
- `gsc_property` (e.g., `sc-domain:yourdomain.com`)
- `ga4_property_id` (numeric GA4 property ID)
- `site_url` and `site_name`

### 3. Protect Data Directory

```bash
echo 'Deny from all' > /nas/content/live/INSTALL/dashboard/data/.htaccess
```

### 4. Service Account Credentials

Upload your Google service account JSON to `dashboard/data/gsc-credentials.json`.

### 5. MySQL Tables

Run via `wp eval-file` or `wp db query`:

```sql
CREATE TABLE IF NOT EXISTS wp_lrg_dashboard_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token VARCHAR(64) NOT NULL,
  purpose ENUM('login','session') NOT NULL DEFAULT 'login',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  used_at TIMESTAMP NULL,
  ip_address VARCHAR(45),
  user_agent VARCHAR(500),
  KEY idx_token (token),
  KEY idx_email_purpose (email, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_lrg_work_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_date DATE NOT NULL,
  category VARCHAR(50) NOT NULL DEFAULT 'content',
  title VARCHAR(255) NOT NULL,
  description TEXT,
  metric_type VARCHAR(50),
  metric_value INT,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_lrg_agent_review_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page_id BIGINT UNSIGNED,
  nicename VARCHAR(100) NOT NULL,
  status ENUM('pending_review','approved','rejected') DEFAULT 'pending_review',
  created_by VARCHAR(100),
  reviewed_by VARCHAR(100),
  review_note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_lrg_review_annotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  reviewer_uid INT NOT NULL,
  annotation_type ENUM('highlight','rewrite','story_fill','story_decision','overall') DEFAULT 'highlight',
  selected_text TEXT,
  comment_text TEXT NOT NULL,
  meta_key VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wp_lrg_review_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token VARCHAR(64) NOT NULL UNIQUE,
  user_id INT NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  KEY idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 6. MU-Plugins

Upload files from `mu-plugins/` to `/nas/content/live/INSTALL/wp-content/mu-plugins/`.

### 7. Routing

The dashboard needs `/dashboard/` routed to the WP Engine origin. If the domain uses Cloudflare Workers (split routing between Squarespace and WPE), add `/dashboard/*` to the Worker route patterns.

The `.htaccess` `RewriteCond %{REQUEST_FILENAME} !-d` rule serves the physical directory directly, bypassing WordPress's `/dashboard/ -> /wp-admin/` redirect.

### 8. Author Pages Parent

Create a parent page (e.g., "Our Authors" at `/authors/`) and note its ID. Set `LRG_AUTHOR_PARENT_PAGE` in `agent-provisioning.php`.

### 9. Hub Page Markers

On the Specialists hub page, add `<!-- /LANE:LaneName -->` markers at the end of each lane's card grid so the provisioning system can safely inject new agent cards.

## WP Engine-Specific Notes

- **`~/` is session-ephemeral.** Use `/nas/content/live/INSTALL/` for persistent files.
- **`/tmp` is session-local.** Pipe files via stdin in single SSH sessions.
- **OPcache**: After editing mu-plugins, touch the file and wait 30s, or call `WpeCommon::purge_varnish_cache_all()`.
- **Email**: Use `wp_mail()` via WPE's native relay. Do NOT install WP Mail SMTP.
- **Registry guard**: The bio-card registry (`lrg-author-bio-card.php`) must stay above 100 bytes after any edit. The provisioning engine enforces this.

## Install-Specific Values

| Value | Where | Example |
|-------|-------|---------|
| Install name | auth-config.php, wp-load path | `lrgrealtyblog` |
| DB credentials | auth-config.php | From wp-config.php |
| GSC property | config.php | `sc-domain:lrgrealty.com` |
| GA4 property ID | config.php | `478166523` |
| Author parent page ID | agent-provisioning.php `LRG_AUTHOR_PARENT_PAGE` | `5480` |
| Hub page ID | agent-provisioning.php `LRG_HUB_PAGE` | `7816` |
| Office phone | agent-provisioning.php `LRG_OFFICE_PHONE` | `(210) 879-8220` |
| Brand colors | mu-plugins CSS | Navy `#091A35`, Red `#c8102e` |
