# MOPS Docs

Dokumentasi Modern Open Portfolio System — self-hosted portfolio CMS (developer, desainer, musisi).

## Stack (terkunci, lihat `plan.md`)

PHP 8.2+ · Slim 4 · Twig 3 · SQLite (PDO) · Tailwind CSS + Alpine.js · Composer.
Aset runtime di-self-host (tanpa CDN, tanpa `unsafe-eval`).

## Peta folder

| Path | Isi |
|---|---|
| `plan.md` (root) | Design doc asli (mixed Indonesian/English) |
| `knowledge.md` (root) | Peta file, perintah, dan gotcha implementasi terkini |
| `AGENTS.md` (root) | Aturan repo: stack terkunci, guardrail scope, perintah |
| `docs/superpowers/specs/` | Spesifikasi fitur yang sudah di-approve |
| `docs/admin-ui.md` | Konvensi & referensi UI admin |
| `public/` | Satu-satunya folder yang boleh diakses web (`index.php`, assets hasil build) |
| `app/` | Core: kernel, repositories, controllers, middleware, view admin |
| `themes/` | Tema bawaan: `vantablo` (gelap/developer), `lumica` (terang/desainer) |
| `plugins/` | Plugin: `mops-analytics-lite` sebagai contoh resmi |
| `database/migrations/` | Migrasi database (portable) |
| `tests/`, `dev/` | PHPUnit + smoke test in-process, router dev |
| `prototype/prompts/` | Prompt paragraf untuk DeepSeek (`<nama-tema>/*.txt`) |
| `prototype/html/` | Hasil prototype statis: `vantablo/`, `lumica/`, `admin-template/` |

## Spesifikasi

- [Homepage Sections (LinkedIn-style)](superpowers/specs/2026-10-10-homepage-sections-design.md) — Section Registry di core, limit per bagian, toggle admin + auto-hide, fresh install = identitas + bio saja.

## Menjalankan

```bash
composer install && npm install && npm run build
cp .env.example .env
php bin/mops migrate
composer serve        # http://127.0.0.1:8000
```

Buka `/install` untuk wizard web (persyaratan sistem + akun admin), atau buat akun lewat
`php bin/mops user:create --email=... --password=...` lalu masuk di `/admin/login`.
Perintah lengkap: lihat `AGENTS.md`.

## Prototype

Dibuat dengan workflow: prompt di `prototype/prompts/` → paste ke DeepSeek → tempel hasil ke `prototype/html/`. Prototype = referensi visual saja; kalau konflik dengan spec, **spec menang**. Tema asli sudah ada di `themes/` — ubah di sana, bukan di prototype.

- **`vantablo/`** → `themes/vantablo/` — tema gelap, persona developer (base #0A0A0B, accent #7C6CFF).
- **`lumica/`** → `themes/lumica/` — tema terang editorial, persona desainer (paper #FAF8F4, clay #B4653F).
- **`admin-template/`** → `app/Views/admin/` — shell admin (sidebar/topbar), tabel, dan form.

## Status

- [x] Rencana & spec homepage sections
- [x] Prototype 2 tema default (vantablo, lumica)
- [x] Prototype admin template
- [x] MOPS 0.1: installer, auth, profile/experience/education/skills/certifications, projects, media, default theme, admin dasar
- [x] MOPS 0.2: blog (index + detail, pagination 10/halaman, editor + media)
- [x] MOPS 0.3: theme manager (dua tema bawaan, ganti tema tanpa kehilangan data)
- [x] MOPS 0.4: plugin manager + hook/event API (+ plugin contoh `mops-analytics-lite`)
- [x] 1.0 (sebagian): backup/restore, CSP + security headers. Auto-update belum ada (butuh release server).

## Aturan main

1. Jangan ubah stack tanpa diskusi.
2. Core kecil; selebihnya plugin (lihat `plan.md`).
3. Theme hanya render data — tanpa logika limit/visibility (lihat spec homepage).
4. Baca spec terkait di `docs/superpowers/specs/` sebelum implementasi fitur.
5. Jalankan `composer check` + `php dev/smoke.php` sebelum menganggap perubahan selesai.
