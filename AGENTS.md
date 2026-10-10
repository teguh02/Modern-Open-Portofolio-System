# AGENTS.md

## Repo status

No code, no `composer.json`, no git history, no build/test/lint commands yet. Sources of truth, in priority order: `plan.md` (original design doc, mixed Indonesian/English), `docs/superpowers/specs/` (approved feature specs — read the relevant spec before implementing a feature), then `prototype/` (visual reference only — if a prototype conflicts with a spec, the spec wins). Start from `docs/README.md` for an overview + folder map. When you add tooling, record the exact commands here.

MOPS = self-hosted portfolio CMS (WordPress-like plugins/themes, but focused on professional portfolios for developers, designers, musicians).

## Locked stack decisions

From `plan.md` — do not substitute without asking:

- PHP 8.2+, Slim 4, Twig 3, SQLite (via PDO), Composer
- Tailwind CSS + Alpine.js (no React/Vue SPA)
- Security: CSRF, escaping, prepared statements, upload MIME/extension whitelist, CSP; `defined('MOPS') || exit` guards are convention only, not real security

## Scope guardrails

- Not a LinkedIn clone: no friend/follow, timeline, messaging, job marketplace, comments/reactions, notifications.
- Core stays small: auth, profile/experience/education/skills/certifications, projects, blog, media library, settings, theme + plugin managers. Everything else (GitHub/Spotify integrations, analytics, contact form, SEO extras, PDF export) is a plugin.
- Profession-specific fields (developer/designer/musician) belong in plugins (`mops-developer`, etc.), never in core.

## Architecture rules that are easy to violate

- Web server points only at `/public`; `app/`, `config/`, `storage/`, `vendor/` must never be web-accessible.
- Data layer: Repository → PDO → SQLite. Never write SQLite-specific SQL in repositories; keep it portable to MySQL/PostgreSQL.
- Themes only render. All portfolio data is structured in the DB and passed to Twig as a `portfolio` schema (`profile`, `experiences`, `skills`, `projects`, ...). Themes must not know table/column names, and swapping a theme must never lose data.
- Plugins extend via hooks/events (`do_action` / `add_action` / `add_filter`) and manifests, never by editing core.
- Blog images are files under `storage/uploads/YYYY/MM/` (DB stores the path only) — never base64.
- Homepage is one LinkedIn-style page driven by a core **Section Registry** (`docs/superpowers/specs/2026-10-10-homepage-sections-design.md`): section order, per-section item limits, visibility (admin toggle AND auto-hide when empty), and "Lihat selengkapnya" URLs are computed in core and passed pre-sliced to the theme. Only identity + bio are enabled on fresh install. Never move limit/visibility logic into themes.

## Prototypes (visual reference)

- Workflow: `prototype/prompts/<name>/*.txt` are paragraph prompts pasted into DeepSeek to generate static HTML; output lives in `prototype/html/<name>/`. To change a prototype, edit the prompt and regenerate — don't hand-polish prototype code.
- `html/vantablo/` (dark, developer persona) and `html/lumica/` (light, designer persona) are the planned **two default bundled themes** — MOPS must ship both on fresh install and let users switch freely.
- `html/admin-template/` (`dashboard.html`, `table.html`, `form.html`) is the admin UI reference: flat material style, English UI, Tailwind CDN + Alpine + Chart.js + DataTables (DataTables/Chart.js are prototype-only aids, not core stack).
- Conversion to real code: CDN Tailwind/Alpine → local Tailwind build + bundled Alpine; `homepage.html` → theme home template. Prototype slugs differ per theme (`riwayat-kerja.html` vs `pengalaman.html`) — real routes stay English per spec (`/experience`, `/projects`, ...).
- Prototypes include `baca-blog.html` (single post view); blog detail rendering is scoped in MOPS 0.2, not yet specced.

## Build order

Per `plan.md`: start with **Portfolio Data Model + Theme API + Hook/Event API** before any blog or plugin features. Roadmap order: 0.1 installer/auth/profile/project/media/default theme → 0.2 blog → 0.3 themes → 0.4 plugins → 1.0 updates/backup/security.
