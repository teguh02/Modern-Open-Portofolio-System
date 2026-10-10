# AGENTS.md

## Repo status

MOPS 0.1–0.4 are implemented and running: installer, auth, the full portfolio data model, projects,
blog, media library, settings, Section Registry homepage, theme manager, plugin manager with hooks,
backups/security (1.0 partial), and two bundled themes. Sources of truth, in priority order:
`plan.md` (original design doc, mixed Indonesian/English), `docs/superpowers/specs/` (approved feature
specs — read the relevant spec before implementing a feature), then `prototype/` (visual reference only
— if a prototype conflicts with a spec, the spec wins). Start from `docs/README.md` for an overview +
folder map, and `knowledge.md` for the current file map and gotchas.

## Commands

```bash
composer install                 # PHP deps (prefix COMPOSER_ALLOW_SUPERUSER=1 when running as root)
npm install                      # Tailwind + Alpine + self-hosted fonts
npm run build                    # build:js then build:css -> public/assets/
cp .env.example .env             # configuration (gitignored)

php bin/mops migrate             # run migrations
php bin/mops migrate:status      # exit 1 when a migration is pending
php bin/mops migrate:rollback    # roll back the last batch
php bin/mops status              # install state, active theme/plugins
php bin/mops user:create --email=you@example.com --password=secret123 [--name="Nama"]
php bin/mops user:password --email=you@example.com --password=newsecret
php bin/mops theme:list          # also: plugin:list, backup [--scope=full|database]

composer serve                   # dev server: php -S 127.0.0.1:8000 -t public dev/router.php
composer lint                    # php -l over app/, bin/, tests/
composer test                    # PHPUnit (unit + feature)
composer check                   # lint + test
php dev/smoke.php                # in-process end-to-end run against a throwaway database
```

First run: `cp .env.example .env`, `php bin/mops migrate`, then open `/install` (or create the admin with
`php bin/mops user:create` and log in at `/admin/login`).

Verification expectations: run `composer check` for any change under `app/`, `themes/`, `plugins/` or
`resources/`, and `php dev/smoke.php` when touching routing, middleware, controllers or templates.
`tests/Unit/PortabilityTest.php` enforces the architecture rules below (no driver-specific SQL in
repositories, themes never touch the data layer, both bundled themes stay complete, no CDN references).

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

## Web root and asset serving

Only `public/` is web-accessible. Uploads (`storage/uploads/YYYY/MM/`) and theme assets are streamed by
core routes (`/uploads/{path}`, `/theme/{slug}/assets/{path}`), never copied into `public/`. `dev/`
holds the dev router and smoke script and must stay outside the document root.

## Prototypes (visual reference)

- Workflow: `prototype/prompts/<name>/*.txt` are paragraph prompts pasted into DeepSeek to generate static HTML; output lives in `prototype/html/<name>/`. To change a prototype, edit the prompt and regenerate — don't hand-polish prototype code.
- `html/vantablo/` (dark, developer persona) and `html/lumica/` (light, designer persona) are the planned **two default bundled themes** — MOPS must ship both on fresh install and let users switch freely.
- `html/admin-template/` (`dashboard.html`, `table.html`, `form.html`) is the admin UI reference: flat material style, English UI, Tailwind CDN + Alpine + Chart.js + DataTables (DataTables/Chart.js are prototype-only aids, not core stack).
- Conversion to real code: CDN Tailwind/Alpine → local Tailwind build + bundled Alpine; `homepage.html` → theme home template. Prototype slugs differ per theme (`riwayat-kerja.html` vs `pengalaman.html`) — real routes stay English per spec (`/experience`, `/projects`, ...).
- Prototypes include `baca-blog.html` (single post view); blog detail rendering is scoped in MOPS 0.2, not yet specced.

## Build order

Per `plan.md`: start with **Portfolio Data Model + Theme API + Hook/Event API** before any blog or plugin features. Roadmap order: 0.1 installer/auth/profile/project/media/default theme → 0.2 blog → 0.3 themes → 0.4 plugins → 1.0 updates/backup/security.
