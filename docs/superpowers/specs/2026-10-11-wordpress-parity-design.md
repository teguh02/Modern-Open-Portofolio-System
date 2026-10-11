# WordPress Parity: Lifecycle Hooks, Admin Menu, Installer, Child Theme — Design

Tanggal: 2026-10-11
Status: Approved (Approach A — WP-faithful incremental)
Scope: sistem tema & plugin MOPS — injeksi, aktivasi/nonaktivasi, instalasi zip, child theme

## Ringkasan

MOPS sudah punya pondasi ala WordPress: hook API (`add_action` / `add_filter` / `do_action` /
`apply_filters`), 8 zona injeksi (`HookZones`), theme switch, dan plugin activate/deactivate
selektif (daftar `active_plugins`). Yang belum ada adalah separuh kedua model WordPress:
callback hidup-mati per ekstensi, halaman admin milik plugin, instalasi/hapus dari UI, dan
theme inheritance.

Target: pengembang tema/plugin ketiga **tidak pernah menyentuh core MOPS**. Cukup membuat folder
di `themes/` atau `plugins/`, memanggil helper injeksi bawaan, dan semuanya langsung berfungsi —
dari aktivasi sampai uninstall — dengan perilaku yang sama seperti WordPress.

## Keputusan yang sudah dikunci

1. **Approach A (WP-faithful incremental)** — perpanjongan kode yang ada, bukan abstraction baru.
   Nama hook dan semantik mengikuti WordPress persis.
2. **`register_activation_hook(__FILE__, $cb)` / `register_deactivation_hook(__FILE__, $cb)`** —
   helper menerima path file plugin, slug di-derive dari nama folder (otomatis, tak ditulis manual).
3. **Instalasi = upload zip lokal dari admin.** Tanpa repository remote, tanpa install dari URL
   (itu urusan MOPS 1.0). Zip diekstrak aman, divalidasi di staging, baru dipindah atomik.
4. **Menu admin plugin = `add_menu_page()` (satu level).** Bukan `add_submenu_page`, bukan
   capability system — cukup satu halaman per plugin, route-nya sudah terproteksi auth + CSRF.
5. **Tema boleh punya `functions.php`** dan memakai seluruh hook API; urutan boot mengikuti
   WordPress (theme functions setelah `plugins_loaded`).
6. **Child theme = field `"parent"` di `theme.json`** dengan fallback template + asset ke parent.

## 1. Lifecycle hooks

### Helper (app/helpers.php)

```php
register_activation_hook(__FILE__, function (): void { ... });
register_deactivation_hook(__FILE__, function (): void { ... });
```

- Slug = `basename(dirname($file))`, sama dengan nama folder plugin. Helper memanggil
  `Hook::addAction('activate_{slug}' | 'deactivate_{slug}', $cb)`.
- File entry wajib berada di root folder plugin (`plugins/<slug>/plugin.php` — sesuai kontrak
  `main` di manifest). Bila `plugins/{slug}` tidak punya `plugin.json`, helper bungkam: tidak
  error, agar file bisa di-`require` di luar MOPS atau di lokasi yang salah.

### PluginManager

`activate($slug)` — urutan wajib:

1. validasi manifest + `checkRequirements()` (sudah ada);
2. tulis slug ke `active_plugins`;
3. **load entry file** (sudah ada) — supaya callback `register_*_hook` ikut terdaftar;
4. `do_action('activate_{slug}', $slug, $manifest)`;
5. `do_action('plugin_activated', $slug, $manifest)`.

`deactivate($slug)` — urutan wajib:

1. pastikan entry file termuat pada request ini (panggil `load()` bila belum — plugin memang
   sudah aktif saat boot, jadi ini no-op normal);
2. `do_action('deactivate_{slug}', $slug)`;
3. hapus slug dari `active_plugins`;
4. `do_action('plugin_deactivated', $slug)`.

Callback hidup-mati hanya dijalankan sekali per kejadian; listener lama tidak perlu dilepas
karena registrasi berikutnya terjadi dari awal request berikutnya.

### ThemeManager

`activate($slug)` menjadi:

```php
$old = $this->activeSlug();               // slug lama SEBELUM settings diubah
// ... validasi (sudah ada) ...
$this->settings->set('active_theme', $slug);

if ($old !== $slug) {
    do_action('theme_deactivated', $old);              // hanya saat benar-benar pindah
    do_action('after_switch_theme', $slug, $old, $theme); // nama persis WP
}
do_action('theme_activated', $slug, $theme);
```

Re-aktivasi tema yang sama: hanya `theme_activated`.

### Hook baru (didokumentasikan di docs/plugin-api.md)

| Hook | Jenis | Kapan |
|---|---|---|
| `activate_{slug}` | action | setelah plugin ditandai aktif, setelah entry file dimuat |
| `deactivate_{slug}` | action | sebelum slug dihapus dari `active_plugins` |
| `after_switch_theme` | action | saat benar-benar berpindah tema |
| `theme_deactivated` | action | slug tema lama, sebelum `after_switch_theme` |

## 2. Admin menu injection

### API

```php
add_menu_page(string $slug, string $title, string $icon, callable $renderer): string;
```

- `$renderer = function (ServerRequestInterface $request): string` → mengembalikan HTML body
  (bukan Response). Core membungkusnya dengan shell admin (sidebar, breadcrumb, title, flash).
- `$slug` pola `[a-z0-9][a-z0-9-]*`, menjadi path `/admin/{slug}` dan kunci menu.
- `$icon` = nama ikon di `app/Views/admin/partials/icon.twig` (default `'puzzle'`).
- Fungsi ini menyimpan entri ke registry `Mops\Core\AdminMenu` dan **mengembalikan slug**,
  seperti WP yang mengembalikan hookname.
- Registrasi dilakukan dari `plugin.php` (entry file), jadi hanya plugin **aktif** yang punya menu.

### Routing

Di dalam group `/admin` (sudah kena `AdminAuthMiddleware` + `CsrfMiddleware`):

```php
$group->get('/{menu:[a-z0-9][a-z0-9-]*}', Admin\MenuPageController::class . ':show');
$group->post('/{menu:[a-z0-9][a-z0-9-]*}', Admin\MenuPageController::class . ':submit');
```

- `GET` = render halaman. `POST` = halaman settings yang sama, jadi form plugin tidak butuh
  route khusus dan tetap terproteksi CSRF.
- FastRoute memberi prioritas static route di atas variable route — `/admin/profile`,
  `/admin/plugins`, dst. tetap menang; variable route hanya menangkap satu segmen, jadi
  `/admin/plugins/{slug}/activate` (dua segmen) tidak terganggu.
- Slug yang tidak terdaftar (plugin dinonaktifkan) → 404 lewat error handling yang ada.
- Slug yang menabrak route statis core (`settings`, `backup`, `themes`, …) → `add_menu_page()`
  melempar RuntimeException dengan pesan jelas; slug inti daftar hitam, tidak bisa dipakai
  plugin (lebih baik gagal saat aktivasi daripada menu diam-diam tidak muncul).

### Sidebar

`AdminController::nav()` diakhiri satu baris:

```php
return Hook::applyFilters('admin_menu', $groups);
```

Plugin menambah grup baru atau menyisipkan item ke grup `System`. Item menu hilang otomatis
saat plugin dinonaktifkan (registry berbasis request).

### Template halaman plugin

Plugin memakai filter `view_paths` yang sudah ada, lalu `$renderer` memanggil
`mops(View::class)->render('my-plugin/settings.twig', $ctx)`. Form POST kembali ke
`/admin/{slug}` (method POST) dan memakai `csrf_field()`.

## 3. Theme functions.php

- `App::loadPlugins()`: `mops_before_plugins` → load plugin → `plugins_loaded` →
  **`ThemeManager::loadFunctions()`** → **`do_action('after_setup_theme')`**.
  Urutan ini sama dengan WordPress (theme functions setelah plugins).
- `loadFunctions()` me-`require` `functions.php` tema aktif, lalu parent-nya (child dulu),
  sekali per request, di-guard `try/catch` — error sebuah tema dicatat, bukan white-screen.
- Dijalankan hanya saat `isInstalled()` (sama seperti plugin), karena tema butuh Settings.
- `functions.php` punya akses penuh ke hook API, container (`mops()`), helper `theme_asset()`
  dsb. — tapi tetap tidak boleh menyentuh repository/tabel core selain lewat hook.

## 4. Child theme

Manifest (`themes/<child>/theme.json`):

```json
{ "name": "Teguh Custom", "parent": "vantablo", "version": "1.0.0" }
```

- **Template:** `ThemeManager::templatePaths(): array<int,string>` mengembalikan
  `[child/templates, parent/templates]` (max 2 level). `View::environment()` memakai array ini
  sebagai entri pertama `view_paths`. `templatePath()` (string) tetap ada, mengembalikan jalur
  pertama, agar pemakaian lama tidak rusak.
- **Asset:** `assetUrl()` mengecek file di child dulu; tidak ada → parent. `assetPath()` juga
  mengembalikan path parent bila child tidak punya file.
- **Validasi aktivasi:** `missing_templates` dihitung terhadap gabungan child+parent.
- **Guard:**
  - `parent` tidak ada / parent tidak valid → aktivasi ditolak dengan pesan jelas.
  - Lingkaran (A→B→A) → ditolak. Lewati dari max 2 level.
  - Saat runtime, bila parent hilang saat child aktif → `activeSlug()` jatuh ke tema default
    seperti perilaku sekarang (situs tidak boleh mati).
- `screenshot`, `legacy` dst. tetap dibaca dari folder child; field yang tidak diisi child
  **tidak** diwarisi otomatis dari parent (manifest = metadata folder itu sendiri).

## 5. Installer zip (plugin & tema)

### ZipInstaller (app/Core/ZipInstaller.php)

Alur untuk keduanya:

1. **Terima upload** (multipart, field `zip`, extensi wajib `.zip`, ukuran maksimal ikut
   config upload yang ada) → simpan sementara di `storage/tmp/`.
2. **Ekstrak ke `storage/tmp/install-<random>/`** dengan penolakan per entry:
   path absolut (`/x`, `C:\x`), mengandung `..`, entry symlink (mode bit tipe). Normalisasi
   backslash ke slash. `Zip Slip` = throw RuntimeException.
3. **Deteksi struktur slug:**
   - tepat satu direktori di root zip yang berisi `plugin.json` / `theme.json` → slug = nama
     direktori itu;
   - selain itu semua file di root zip → slug dari field `"slug"` manifest; bila tidak ada →
     tolak (pesan: manifest wajib punya `slug` bila zip berbentuk datar).
4. **Validasi di staging** sebelum menulis ke tujuan:
   - plugin: `plugin.json` valid JSON + file entry (`main`, default `plugin.php`) ada;
   - tema: `theme.json` valid JSON + `REQUIRED_TEMPLATES` terpenuhi (untuk child: parent
     diperiksa setelah tujuan ada — lihat langkah 6).
5. **Pindah atomik**: slug sudah ada di `plugins/`/`themes/` → tolak; kalau tidak,
   `rename(staging, tujuan)`. Gagal → bersihkan `storage/tmp/` terkait.
6. **Child theme dari zip:** setelah folder ada, validasi `parent` (harus terdaftar, tidak
   lingkaran). Tidak valid → folder baru dihapus kembali dan error ditampilkan.

### Hapus

Route: `POST /admin/plugins/{slug}/delete`, `POST /admin/themes/{slug}/delete`.

- **Plugin:** wajib sudah nonaktif (kalau aktif → tolak dengan pesan "deactivate first",
  seperti WP). Kalau `uninstall.php` ada → di-load terisolasi (`require` di closure, dengan
  container + hook tersedia) **sebelum** folder dihapus. Core menghapus slug dari
  `active_plugins` (keamanan) lalu menghapus folder dengan guard `Paths::isInside`.
- **Tema:** tolak bila sedang aktif, atau bila menjadi `parent` tema aktif. Hapus folder
  dengan guard yang sama.
- Tidak ada `uninstall.php` untuk tema.

### UI

- `plugins.twig`: form "Upload Plugin" (file input `zip`) + tombol Delete per plugin
  (disabled bila masih aktif).
- `themes.twig`: form "Upload Theme" + tombol Delete per tema (disabled bila aktif/parent
  aktif).
- Label admin bahasa Inggris (`docs/admin-ui.md`). Semua POST melewati CSRF middleware.

### Keamanan

- Semua route di group `/admin` → auth admin + CSRF.
- Ekstraksi hanya ke `storage/tmp/` (di luar web root), tujuan hanya `plugins/` atau `themes/`
  (di luar web root), slug divalidasi pola `[a-z0-9][a-z0-9_-]*`.
- Aset hasil install tetap disajikan lewat route core `/plugin/{slug}/assets/...` dan
  `/theme/{slug}/assets/...` — tidak ada file baru di `public/`.
- `ZipArchive` wajib; kalau tidak tersedia tombol upload disembunyikan (konsisten dengan
  cek backup).

## 6. Testing & dokumentasi

**Unit**

- `tests/Unit/PluginLifecycleTest.php` — activation hook jalan sekali dan setelah entry
  dimuat; deactivation hook jalan sebelum slug hilang; helper bungkam di luar plugin.
- `tests/Unit/ZipInstallerTest.php` — tolak `../evil.php`, path absolut, symlink; terima zip
  folder-tunggal dan zip datar ber-`slug`; slug duplikat ditolak; staging bersih saat gagal.
- `tests/Unit/ChildThemeTest.php` — `templatePaths()` child→parent; asset fallback; tolak
  parent hilang dan lingkaran.
- `tests/Unit/AdminMenuTest.php` — `add_menu_page` terdaftar, filter `admin_menu` merge,
  slug cadangan core (`settings`, `backup`, `plugins`, …) ditolak.
- `tests/Unit/HookTest.php` tetap; `HookZonesTest` tetap (kontrak zona tidak berubah).

**Feature (HTTP)**

- Upload plugin zip → activate → menu muncul di sidebar → GET halaman 200 (guest → redirect
  login) → POST settings tersimpan → deactivate → delete bersih.
- Upload tema → jadi child → `after_switch_theme` terpicu → ganti tema → `theme_deactivated`
  terpicu → delete ditolak selama aktif.

**Dokumentasi**

- `docs/plugin-api.md`: tabel WordPress↔MOPS ditambah (activation hook, `add_menu_page`,
  functions.php, child theme, installer), bagian "contoh settings page plugin".
- `knowledge.md`: gotcha baru (urutan boot theme functions, `View::refresh()` setelah
  switch/(de)aktif sudah ada — tambahkan juga setelah upload/hapus).
- `docs/README.md`: status checklist ditambah.

**Verifikasi akhir:** `composer check` + `php dev/smoke.php`.

## Guardrail

- Tidak ada dependency baru. Semua fitur memakai `ZipArchive` (sudah dicek di installer),
  Slim/FastRoute, dan Twig yang sudah terpasang.
- Core tetap kecil: satu kelas baru (`AdminMenu`, `ZipInstaller`, `MenuPageController`) plus
  helper; tidak ada abstraction layer untuk "extensibility nanti".
- Repo rules tetap berlaku: tak ada SQL spesifik driver, tema tidak menyentuh data layer,
  plugin menulis hanya di folder sendiri + `storage/`.
