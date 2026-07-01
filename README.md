# Squarespace Agent SEO — Agent Provisioning + Dashboard

A system for migrating real estate agents from a Squarespace roster to dedicated WordPress author pages with full SEO infrastructure, analytics dashboard, and review workflow.

## What It Does

**Agent Provisioning** — Takes agent data (name, bio, TREC license, phone, headshot, languages) and creates a complete WordPress author presence across 7 layers:

1. **WP User** — WordPress author account
2. **User Meta** — Role, profile URL, avatar, bio, languages
3. **Bio Card Registry** — Entry in the mu-plugin author registry for blog post byline cards
4. **Author Page** — Full `#lrgAuthorPage` profile with hero, bio, contact card, TREC stamp, JSON-LD schema, and dynamic article shortcode
5. **Headshot** — Pulled from Squarespace CDN, converted to PNG, saved locally
6. **Hub Card** — Injected into the Specialists hub page via marker-based safe insertion
7. **JSON-LD** — Person/RealEstateAgent structured data embedded in the page

**Review Queue** — Pages are created as DRAFT. A reviewer approves to publish + add to hub. Reject to keep as draft.

**Analytics Dashboard** — Command Center with GA4 + GSC data, real-time visitors, YoY comparison, range selector (7d/30d/90d), Chart.js visualizations.

**Agent Review Portal** — Scoped 7-day links for agents to review their draft articles. Highlight-to-suggest rewrites, inline story prompts, overall feedback. Annotations email to the operator.

**Multilingual Agents Page** — Auto-populated from `lrg_languages` user meta. Language filter pills, name search, Specialists-style card grid.

## Architecture

```
dashboard/
  index.php                 — Main app (router, auth, tabs, JS)
  agent-provisioning.php    — 7-layer provisioning engine + review queue
  agent-review.php          — Scoped agent review portal
  dashboard-auth.php        — Magic link + permanent key auth
  cc-google-auth.php        — Service account JWT for GSC/GA4
  lrg-analytics.php         — GA4 + GSC data fetching with caching
  lrg-worklog.php           — Work velocity tracking
  role-visibility.php       — Tab access control
  config.php                — Site-specific config (from config.example.php)
  data/
    auth-config.php          — Secrets (from auth-config.example.php, gitignored)
    gsc-credentials.json     — Service account JSON (gitignored)

mu-plugins/
  lrg-author-styles.php     — Author page CSS (scoped to #lrgAuthorPage)
  lrg-author-bio-card.php   — Blog post byline card with TREC + registry
  lrg-author-posts.php      — [lrg_author_posts] shortcode for article grid
  lrg-multilingual-agents.php — Multilingual agents page shortcode
```

## Accuracy Principles

- **Never compose claims.** If the agent's bio says "New Braunfels," use "New Braunfels" — do not add "and the Hill Country."
- **Pull real data or use neutral fallback.** H2 subheadings default to "Real estate services in San Antonio and Central Texas" unless explicitly set.
- **Phone numbers from source.** Only use phone numbers confirmed on the agent's Squarespace profile or provided by the operator.
- **Trust badges from bio.** Only claim what the agent's bio or credentials support.

## Hub Injection (Marker-Based)

The Specialists hub page uses `<!-- /LANE:LaneName -->` comment markers at the end of each lane's card grid. Card injection uses `str_replace` on the marker — no div-depth parsing, no risk of nesting cards inside each other.

To add a lane marker: insert `<!-- /LANE:YourLaneName -->` after the last card in the new lane's `<div class="dGrid">`.

## Auth

- **Magic link**: Email-based, 15-minute expiry, creates 365-day session
- **Permanent keys**: 64-char hex tokens in auth-config.php, one per user
- **Agent review**: 7-day scoped tokens bound to one user + one post
- **Varnish bypass**: `wordpress_lrg_dash=1` cookie set on all auth flows

## Requirements

- WordPress on WP Engine
- PHP 8.0+
- MySQL (wp_lrg_dashboard_sessions, wp_lrg_work_log, wp_lrg_agent_review_queue, wp_lrg_review_annotations, wp_lrg_review_tokens tables)
- Google service account with GSC + GA4 access
- WP Engine native email relay (for magic links + review feedback)
