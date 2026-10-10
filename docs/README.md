# MOPS Docs

Dokumentasi Modern Open Portfolio System — self-hosted portfolio CMS (developer, desainer, musisi).

## Stack (terkunci, lihat `plan.md`)

PHP 8.2+ · Slim 4 · Twig 3 · SQLite (PDO) · Tailwind CSS + Alpine.js · Composer.

## Peta folder

| Path | Isi |
|---|---|
| `plan.md` (root) | Design doc asli (mixed Indonesian/English) |
| `docs/superpowers/specs/` | Spesifikasi fitur yang sudah di-approve |
| `docs/admin-ui.md` | Konvensi & referensi UI admin |
| `prototype/prompts/` | Prompt paragraf untuk DeepSeek (`<nama-tema>/*.txt`) |
| `prototype/html/` | Hasil prototype statis: `vantablo/`, `lumica/`, `admin-template/` |

## Spesifikasi

- [Homepage Sections (LinkedIn-style)](superpowers/specs/2026-10-10-homepage-sections-design.md) — Section Registry di core, limit per bagian, toggle admin + auto-hide, fresh install = identitas + bio saja.

## Prototype

Dibuat dengan workflow: prompt di `prototype/prompts/` → paste ke DeepSeek → tempel hasil ke `prototype/html/`. Prototype = referensi visual saja; kalau konflik dengan spec, **spec menang**.

- **`vantablo/`** — tema gelap, persona developer. Jadi salah satu tema bawaan saat install.
- **`lumica/`** — tema terang minimalis, persona desainer. Tema bawaan kedua.
- **`admin-template/`** — `dashboard.html`, `table.html`, `form.html`. Referensi UI admin (lihat [admin-ui.md](admin-ui.md)).

## Status

- [x] Rencana & spec homepage sections
- [x] Prototype 2 tema default (vantablo, lumica)
- [x] Prototype admin template
- [ ] MOPS 0.1: installer, auth, profile/experience/education/skills/certifications, projects, media, default theme, admin dasar

## Aturan main

1. Jangan ubah stack tanpa diskusi.
2. Core kecil; selebihnya plugin (lihat `plan.md`).
3. Theme hanya render data — tanpa logika limit/visibility (lihat spec homepage).
4. Baca spec terkait di `docs/superpowers/specs/` sebelum implementasi fitur.
