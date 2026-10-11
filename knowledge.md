# knowledge.md

## What this project is

**MOPS — Modern Open Portfolio System**: a self-hosted portfolio CMS for professionals (developers,
designers, musicians). WordPress-like (themes + plugins) but scoped to portfolios. Not a LinkedIn
clone: no friend/follow, timeline, messaging, job marketplace, comments/reactions, notifications.

Status: **MOPS 0.1 + 0.2/0.3/0.4 foundations are implemented and running.** Installer, auth,
profile/experience/education/skills/certifications/awards, projects, blog, media library, settings,
homepage Section Registry, theme manager, plugin manager with hooks, backups, and two bundled themes
(`vantablo`, `lumica`).

## Commands (all verified)

```bash
# setup
composer install
npm install
npm run build                 # Tailwind CSS -> public/assets/css/app.css, Alpine -> public/assets/js/app.js
cp .env.example .env

# database
php bin/mops migrate          # run migrations
php bin/mops migrate:status   # exit 1 when something is pending
php bin/mops migrate:rollback # roll back the last batch
php bin/mops status           # installed? active theme/plugins

# users
php bin/mops user:create --email=me@example.com --password=secret123 --name="Nama"
php bin/mops user:password --email=me@example.com --password=newsecret

# inspect / backup
php bin/mops theme:list
php bin/mops plugin:list
php bin/mops backup --scope=full|database

# run
composer serve                # php -S 127.0.0.1:8000 -t public dev/router.php

# verify
composer lint                 # php -l over app/, bin/, tests/
composer test                 # PHPUnit (58 tests)
composer check                # lint + test
php dev/smoke.php             # in-process end-to-end run against a throwaway SQLite db
```

First run: `cp .env.example .env` → `php bin/mops migrate` → open `/install` (web installer), or skip the
installer with `php bin/mops user:create` and then log in at `/admin/login`.

Note: when running Composer as root (containers), prefix with `COMPOSER_ALLOW_SUPERUSER=1`.

## Where things live

| Path | Contents |
|---|---|
| `public/` | **The only web-accessible folder.** `index.php` front controller, `.htaccess`, built assets. |
| `app/Core/` | Kernel: `App`, `Container`, `Database`, `QueryBuilder`, `Schema`/`Blueprint`/`Migrator`, `Hook`, `Settings`, `Session`, `Csrf`, `Auth`, `View`, `Uploader`, `Validator`, `SectionRegistry`, `ThemeManager`, `PluginManager`, `AdminMenu`, `ZipInstaller`, `Navigation`, `Backup`, `ErrorHandler`, `Str`, `Paths`, `Env`, `Config`. |
| `app/Controllers/` | Public + installer + auth controllers; `Controllers/Admin/` holds `ResourceController` (generic CRUD) and the per-resource subclasses. |
| `app/Repositories/` | Data access. `Repository` base + one class per table. |
| `app/Middleware/` | Session → Install gate → CSRF → Security headers (order matters, see gotchas). |
| `app/Views/` | Admin + installer Twig templates, `fallback/missing.twig`, `errors/500.twig`. |
| `themes/<slug>/` | Bundled themes: `theme.json` manifest, `templates/`, `assets/theme.css` (palette tokens). |
| `plugins/<slug>/` | `plugin.json` + `plugin.php`; `plugins/mops-analytics-lite/` is the reference plugin. |
| `database/migrations/` | Ordered migration files returning anonymous `Migration` subclasses. |
| `config/config.php` | Config + registry of default settings (fresh-install behaviour lives here). |
| `resources/css/app.css`, `resources/js/components.js` | Tailwind entry with design tokens; CSP-safe Alpine components. |
| `tests/` | PHPUnit: `Unit/` (Hook, SectionRegistry, QueryBuilder, Validator, Portability) and `Feature/ApplicationTest.php` (full HTTP flow). |
| `dev/` | `router.php` (dev server, kept outside `public/`), `smoke.php` (in-process end-to-end check). |
| `plan.md`, `docs/`, `prototype/` | Original design doc, approved specs, static prototypes (visual reference only). |

## Locked stack (do not substitute without asking)

PHP 8.2+ (runs on 8.4), Slim 4, Twig 3, SQLite via PDO, Composer, Tailwind CSS + Alpine.js. No
React/Vue SPA. Runtime assets are self-hosted: no CDN at runtime, no `unsafe-eval` in the CSP.

## Architecture rules that are easy to violate

- Web root points **only** at `/public`. `app/`, `config/`, `storage/`, `themes/`, `plugins/`,
  `vendor/`, `database/` are never web-accessible. Uploads and theme assets are streamed by core
  routes (`/uploads/{path}`, `/theme/{slug}/assets/{path}`) instead.
- Data layer: Repository → PDO → `QueryBuilder` → SQLite. **Never write driver-specific SQL in
  repositories** — `tests/Unit/PortabilityTest.php` fails the build if `PRAGMA`, `strftime(`,
  `AUTOINCREMENT`, … shows up there. Introspection lives in `Database::tableColumns()`.
- Themes only render. Core hands them a prepared `portfolio` payload and a `sections` array
  (`id`, `title`, `items` pre-sliced, `total`, `more_url`). Themes never read tables/columns —
  also asserted by `PortabilityTest`.
- Plugins extend through `add_action` / `add_filter` / `do_action` / `apply_filters` and manifests,
  never by editing core.
- Blog/project images are files under `storage/uploads/YYYY/MM/` with only the path in the DB. Never
  base64.
- Admin users authenticate with sessions; every state-changing request needs a CSRF token.

## Homepage = Section Registry (core, not theme)

Spec: `docs/superpowers/specs/2026-10-10-homepage-sections-design.md`. A single ordered array in
`app/Core/SectionRegistry.php` is the only source of truth for order, label, item limit, default
visibility and detail route.

- `visible = (alwaysVisible || flag ON) && (total > 0 || alwaysVisible)` — admin toggle AND auto-hide.
- Limits are hard-coded (`experience` 5, `projects` 6, `skills` 12, `education` 3, `certifications` 5,
  `awards` 3, `blog` 4). Admin can only toggle visibility in `/admin/settings`; order/limit are
  read-only there.
- Fresh install shows only `profile` + `about`. No seeding code: missing `section_enabled_{id}`
  keys fall back to the registry defaults (`Settings` → `config/config.php`).
- Detail routes are fixed English slugs: `/experience`, `/projects`, `/skills`, `/education`,
  `/certifications`, `/awards`, `/blog` (+ `/projects/{slug}`). Only `/blog` paginates (10/page).
- `app/Core/Navigation.php` computes the public nav the same way (enabled + has data) and exposes it to
  every template as the `nav` global.

## Gotchas learned the hard way

- **Middleware order in `App::slim()` matters.** Slim runs LIFO: routing is added first (innermost),
  then security headers, then CSRF, then body parsing, then the install gate, then the session, then
  the error handler (outermost). CSRF needs the parsed body *and* a started session, which is why
  `SessionMiddleware` is added late. `CsrfMiddleware` additionally falls back to `$_POST` and to
  parsing the raw body so it cannot silently depend on that order.
- **Sessions are only started outside CLI.** `Session::start()` keeps the in-memory `$_SESSION` array
  under `cli`/`phpdbg`; calling `session_start()` there would wipe state set by tests/scripts.
- **After switching theme or (de)activating a plugin, call `View::refresh()`** — the Twig loader
  resolves template folders by path, so the environment must be rebuilt. The admin controllers do this.
- `Validator` treats blank values differently by type: typed rules (`date`, `integer`, `numeric`,
  `boolean`) become `NULL`, text fields stay `''` so NOT NULL varchar columns with defaults are safe.
  `ResourceController::applyDefaults()` then applies the field schema default (e.g. `sort_order` 0).
- `ResourceController` derives everything from the field schema: validation rules, labels, form
  widgets, table columns, list→JSON conversion for `list` fields, uploads for `media` fields.
- Public templates render through `View::renderPublic()`, which falls back to
  `app/Views/fallback/missing.twig` when a theme lacks a template, so a broken theme never white-screens.
- Prototypes are generated from prompts in `prototype/prompts/` — edit the prompt and regenerate; don't
  hand-polish prototype HTML. **If a prototype conflicts with a spec, the spec wins.**
- Public labels are Indonesian; **admin UI is English** (see `docs/admin-ui.md`).
- `.env` is gitignored; `config/config.php` reads it through `Mops\Core\Env`.
- Activation/deactivation hooks are named `activate_{slug}` / `deactivate_{slug}` and only fire from
  `PluginManager::activate()`/`deactivate()` — never `do_action()` them at boot.
- Theme `functions.php` runs after `plugins_loaded` (before `after_setup_theme`) and only after the
  site is installed (`isInstalled()`); load errors are collected in `ThemeManager::errors()` and shown
  on `/admin/themes` via `load_errors`, never fatal (language-level fatals still abort,
  WordPress-style).
- Child themes: `templatePaths()` and the asset chain are child-first; `assertActivatable()` enforces
  parent-exists and max 2 levels — call it after any programmatic theme install.
- Zip installs extract under `storage/tmp/` first (structure + slug detection happen there, then one
  atomic `rename()`); a half-finished install never lands in `plugins/`/`themes/`.

## Roadmap status

0.1 (installer, auth, profile/experience/education/skills/certifications, projects, media, default
theme, admin) · 0.2 blog · 0.3 themes · 0.4 plugins — plugin manager + hooks, plus lifecycle hooks,
`add_menu_page`, theme functions.php, child themes, zip install/delete (WordPress parity, spec
`docs/superpowers/specs/2026-10-11-wordpress-parity-design.md`) — all implemented. 1.0
backup/restore + security headers/CSP are implemented; **automatic updates** are not (no release
server), and page-view analytics deliberately lives in the sample plugin rather than core.
