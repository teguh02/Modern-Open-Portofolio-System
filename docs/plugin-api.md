# Plugin & Theme API — injeksi ala WordPress

MOPS memakai model ekstensi WordPress: **kode bawaan menyediakan titik injeksi bernama**, plugin
menempelkan kodenya ke titik itu. Plugin tidak pernah mengedit core atau template tema, dan tema tidak
pernah tahu plugin apa yang aktif.

| WordPress | MOPS | Keterangan |
|---|---|---|
| `wp_head` / `wp_footer` / `wp_body_open` | `theme_head` / `theme_footer` / `theme_body_open` | zona dokumen, dicetak tema di semua halaman publik |
| `do_action()` / `add_action()` | `do_action()` / `add_action()` | nama & semantik sama (prioritas, urutan) |
| `apply_filters()` / `add_filter()` | `apply_filters()` / `add_filter()` | transformasi nilai, bukan output |
| `add_menu_page()` | `admin_dashboard_widgets` (zona) | dashboard admin menerima kartu dari plugin |
| `plugin_dir_url()` | `plugin_asset($slug, $path)` | aset plugin disajikan core dari `plugins/<slug>/assets/` |
| `wp_head()` wajib di tema | `HookZones::REQUIRED_THEME_ZONES` | tema wajib mencetak zona inti, kalau tidak plugin "hilang" |
| `wp_register_sidebar_widget` (widget area) | `home_before_sections` / `home_after_sections` | area bertema halaman, khusus homepage |
| `register_activation_hook()` | `register_activation_hook(__FILE__, $cb)` | jalan sekali saat plugin diaktifkan |
| `register_deactivation_hook()` | `register_deactivation_hook(__FILE__, $cb)` | jalan sekali saat dinonaktifkan |
| `after_switch_theme` | `after_switch_theme` | nama hook sama; plus `theme_deactivated` |
| `add_menu_page()` | `add_menu_page($slug, $title, $icon, $renderer)` | halaman admin milik plugin di `/admin/{slug}` (GET+POST) |
| `functions.php` tema | `themes/<slug>/functions.php` | dimuat setelah `plugins_loaded`, sebelum `after_setup_theme` |
| Child theme (`Template:`) | `theme.json` `"parent": "<slug>"` | fallback template + asset ke parent, maks 2 level |
| Upload/hapus plugin & tema | form Upload + tombol Delete di `/admin/plugins`, `/admin/themes` | zip lokal, validasi di staging, `uninstall.php` dijalankan saat hapus |

Sumber kebenaran daftar zona: `app/Core/HookZones.php`. Halaman `/admin/plugins` menampilkan daftar ini
lengkap dengan posisi dan status "dipakai/kosong".

## 1. Zona injeksi

Cetak di template dengan Twig `render_hook`, isi dari plugin dengan `add_action`.

| Zona | Posisi | Argumen | Scope |
|---|---|---|---|
| `theme_head` | semua halaman publik, sebelum `</head>` | `context` | publik |
| `theme_body_open` | semua halaman publik, setelah `<body>` | `context` | publik |
| `main_before_content` | semua halaman publik, awal `<main>` | `context` | publik |
| `main_after_content` | semua halaman publik, akhir `<main>` | `context` | publik |
| `theme_footer` | semua halaman publik, setelah elemen `<footer>` | `context` | publik |
| `home_before_sections` | homepage, sebelum bagian-bagian profil | `context, sections` | publik |
| `home_after_sections` | homepage, area CTA tepat di atas footer | `context, sections` | publik |
| `admin_dashboard_widgets` | dashboard admin, di bawah statistik | `stats` | admin |

`context` selalu berisi minimal `path` (path request saat ini), `page`, `site`, dan `user`. Gunakan untuk
membatasi output per halaman, misal hanya homepage:

```php
add_action('theme_footer', function (array $context = []): void {
    if (($context['path'] ?? '/') !== '/') {
        return;
    }

    echo '<div class="text-xs text-muted">Hanya di homepage.</div>';
});
```

## 2. Kontrak tema

Tema hanya render, tapi wajib mencetak zona inti (seperti tema WordPress wajib memanggil `wp_head()`):

```twig
<body class="...">
{% set zone_context = { path: current_path, page: page|default({}), site: site, user: auth_user } %}
{{ render_hook('theme_body_open', zone_context) }}

<main>
  {{ render_hook('main_before_content', zone_context) }}
  {% block content %}{% endblock %}
  {{ render_hook('main_after_content', zone_context) }}
</main>

{{ render_hook('theme_footer', zone_context) }}
</body>
```

Zona wajib (dijaga saat discovery/aktivasi dan oleh test): `theme_head`, `theme_body_open`,
`main_before_content`, `main_after_content`, `theme_footer`, `home_before_sections`, dan
`home_after_sections`. Tema yang tidak memenuhi kontrak tetap terlihat di admin, tetapi tidak dapat
diaktifkan. Bila tema aktif hilang (foldernya tidak ada) atau induknya hilang, MOPS kembali ke tema
valid bawaan.

Tema boleh menambah zona sendiri (mis. `vantablo_hero_extra`) dengan `render_hook('<nama>')`; zona buatan
tema tidak muncul di registry core, jadi dokumentasikan di tema tersebut. Tema **tidak** boleh menaruh
logika limit/visibility — itu tetap milik core (`SectionRegistry`).

## 3. Struktur plugin

```
plugins/<slug>/
├── plugin.json      # manifest (name, version, author, description, requires, main)
├── plugin.php       # entry file, dimuat sekali per request saat plugin aktif
├── src/             # kelas plugin (namespace sendiri, mis. MopsPlugin\NamaPlugin)
├── views/           # template Twig, didaftarkan lewat filter view_paths
└── assets/          # css/js, disajikan lewat /plugin/<slug>/assets/<path>
```

Urutan boot yang bisa diandalkan:

1. `mops_before_plugins` — hook API sudah tersedia, plugin belum dimuat.
2. entry file setiap plugin aktif di-`require` (di sini: `add_action`, `add_filter`).
3. `plugins_loaded`
4. `functions.php` tema aktif dimuat, lalu `after_setup_theme`.
5. core mendaftarkan route-nya, lalu `routes_registered` (plugin menambah route).
6. core mendaftarkan **catch-all** `/ {path:.*}` paling akhir.
7. per request render: zona injeksi + `page_viewed`.

## 4. Hook yang disediakan core

Action: `mops_before_plugins`, `plugins_loaded`, `after_setup_theme`, `routes_registered`, `page_viewed`,
`theme_deactivated`, `theme_activated`, `plugin_activated`, `plugin_deactivated`, `backup_created`,
`backup_restored`,
event repository `<tabel>_created`, `<tabel>_updated`, `<tabel>_deleting`, `<tabel>_deleted`
(mis. `posts_created`, `experiences_updated`).

Filter: `section_registry` (tambah/atur bagian homepage), `portfolio_payload` (bentuk data portofolio),
`navigation_items`, `view_paths` (lokasi template plugin), `<tabel>_before_create`,
`<tabel>_before_update`, `<tabel>_row` (ubah data sebelum disimpan/keluar dari repository).

Contoh menambah template plugin:

```php
add_filter('view_paths', static function (array $paths): array {
    $paths[] = __DIR__ . '/views';

    return $paths;
});
```

## 5. Route dari plugin

```php
add_action('routes_registered', static function ($app): void {
    $app->get('/cv.pdf', function ($request, $response) {
        // bukan closure static: Slim mengikat handler ke container
        return $response->withHeader('Content-Type', 'application/pdf')
            ->withBody(new \Slim\Psr7\Stream(fopen('php://temp', 'r+')));
    });
});
```

Aturan penting:

- Handler **tidak boleh** `static fn` / `static function`: Slim mem-`bindTo()` closure ke container dan
  static closure menghasilkan `TypeError` → 500.
- Route statis apa pun aman. Catch-all 404 core didaftarkan *setelah* `routes_registered`; kalau
  urutannya dibalik, FastRoute melempar `BadRouteException` dan seluruh aplikasi 500.
- Route plugin tunduk pada middleware core yang sama: CSRF (untuk POST), security headers, gate installer.

## 6. Aset plugin

```twig
<link rel="stylesheet" href="{{ plugin_asset('mops-cv-ats', 'cv.css') }}">
<script defer src="{{ plugin_asset('mops-cv-ats', 'cv.js') }}"></script>
```

`/plugin/<slug>/assets/<path>` streaming file di `plugins/<slug>/assets/` (traversal ditolak, hanya folder
`assets/` slug tersebut). CSP ketat (`script-src 'self'`, `style-src 'self' 'unsafe-inline'`) — jadi
**script inline akan diblokir**; kirim file lewat `plugin_asset()`.

## 7. Data

Plugin memakai container, bukan tabel core:

```php
$portfolio = mops(\Mops\Core\Portfolio::class)->payload();   // profil, experiences, skills, projects, ...
$db = mops(\Mops\Core\Database::class);                      // untuk tabel milik plugin sendiri
$value = setting('site_name');                               // baca setting
```

Tabel milik plugin dibuat sendiri (lihat `plugins/mops-analytics-lite/plugin.php`, `Schema` + `Blueprint`,
idempoten, tanpa migrasi core). Jangan menulis SQL spesifik driver — aturan portabilitas yang sama berlaku.

## 8. Contoh lengkap: `mops-cv-ats`

Plugin contoh injeksi sekaligus generator PDF tanpa dependency:

- `plugin.json` + `plugin.php` — manifest dan bootstrap.
- `src/PdfWriter.php` — penulis PDF teks (base-14 Helvetica, tanpa kompresi/gambar/tabel) supaya hasilnya
  ramah ATS.
- `src/CvBuilder.php` — menyusun payload `portfolio` jadi dokumen: heading Inggris standar
  (`PROFESSIONAL SUMMARY`, `EXPERIENCE`, `EDUCATION`, `SKILLS`, `CERTIFICATIONS`, `AWARDS`, `SELECTED
  PROJECTS`), bullet `-`, rentang tanggal `Mar 2021 - Present`.
- Route `GET /cv.pdf` → `application/pdf`, `Content-Disposition: attachment`.
- Injeksi `home_after_sections` (tombol download) dan `theme_footer` (catatan kecil, hanya homepage).

Menonaktifkan tanpa menyentuh kode: `apply_filters('cv_ats_button_enabled', false)`,
`apply_filters('cv_ats_show_hint', false)`, `apply_filters('cv_ats_footer_note', false)`.

## 9. Memeriksa hasil kerja

```bash
php bin/mops plugin:list      # daftar plugin + status
composer check                # php -l + PHPUnit (kontrak tema & registry zona diuji di sini)
php dev/smoke.php             # end-to-end in-process
composer serve                # lalu buka /admin/plugins -> kartu "Injection Zones"
```

## 10. Guardrail

- Jangan mengedit `app/` atau `themes/` dari plugin. Semua lewat hook, zona, route, manifest.
- Plugin hanya menulis di folder plugin itu sendiri dan di `storage/`.
- Label publik berbahasa Indonesia, UI admin berbahasa Inggris (`docs/admin-ui.md`).
- Fitur non-core (analitik, SEO, contact form, integrasi GitHub/Spotify, ekspor PDF/CV) memang tempatnya
  di plugin — bukan di core.

## 11. Lifecycle hooks

Callback hidup-mati per plugin, dengan urutan yang sama seperti WordPress. Daftarkan dari entry file:

```php
register_activation_hook(__FILE__, function (string $slug, array $manifest): void {
    // Buat tabel/default milik plugin secara idempoten.
});

register_deactivation_hook(__FILE__, function (string $slug): void {
    // Hentikan scheduler/cache sementara; jangan hapus data pengguna.
});
```

Folder plugin berarti **terpasang**, bukan otomatis aktif. Hanya slug di setting `active_plugins` yang
dimuat, jadi admin bebas mengaktifkan sebagian plugin. Callback aktivasi hanya dijalankan sekali ketika
status berubah dari nonaktif ke aktif, bukan pada setiap request.

Slug di-derive dari nama folder (`basename(dirname($file))`), jadi tak perlu ditulis manual. Helper
membungkam dirinya sendiri bila folder itu tidak punya `plugin.json` (aman di-`require` di luar MOPS).
Di balik layar helper hanya memanggil `add_action('activate_{slug}' | 'deactivate_{slug}', $cb)`.

`PluginManager::activate($slug)` — urutan wajib:

1. validasi manifest + `checkRequirements()`;
2. tulis slug ke `active_plugins`;
3. **load entry file** — supaya callback `register_*_hook` ikut terdaftar di request yang sama;
4. `do_action('activate_{slug}', $slug, $manifest)`;
5. `do_action('plugin_activated', $slug, $manifest)`.

`PluginManager::deactivate($slug)` — urutan wajib:

1. pastikan entry file termuat pada request ini (no-op bila plugin memang sudah aktif saat boot);
2. `do_action('deactivate_{slug}', $slug)`;
3. hapus slug dari `active_plugins`;
4. `do_action('plugin_deactivated', $slug)`.

Menghapus data permanen bukan tugas deaktivasi; `uninstall.php` (kalau ada) dijalankan saat plugin
dihapus (§14).

Tema (`ThemeManager::activate()`): saat **benar-benar pindah** tema — `theme_deactivated($old)`,
lalu `after_switch_theme($slug, $old, $theme)` (nama hook persis WP), diakhiri
`theme_activated($slug, $theme)`. Aktivasi ulang tema yang sama hanya memicu `theme_activated`.
Tema mengikuti model WordPress: tepat satu tema aktif — tidak ada tombol "nonaktifkan tema";
mengaktifkan tema lain otomatis mengganti tema lama.

Penting: hook hidup-mati hanya dipicu dari `PluginManager::activate()` / `deactivate()` (POST
`/admin/plugins/{slug}/activate` dan `.../deactivate`) — **jangan pernah** memanggil
`do_action('activate_…')` saat boot.

## 12. Halaman settings plugin

Satu halaman admin per plugin lewat `add_menu_page($slug, $title, $icon, $renderer)`. Route `GET`
dan `POST` `/admin/{slug}` ada di dalam group `/admin`, jadi auth + CSRF sudah berlaku; `$renderer`
menerima `ServerRequestInterface` dan mengembalikan body HTML — core membungkusnya dengan shell
admin (`plugin-page.twig`).

```php
add_menu_page('cv-settings', 'CV Settings', 'document', function ($request): string {
    if ($request->getMethod() === 'POST') {
        mops(\Mops\Core\Settings::class)->set('cv_title', trim((string) ($_POST['cv_title'] ?? '')));

        return '<div class="card card-body">Saved.</div>';
    }

    return mops(\Mops\Core\View::class)->render('cv-ats/settings.twig', [
        'value' => setting('cv_title', ''),
    ]);
});
```

Aturan:

- **Slug cadangan core** ditolak `add_menu_page()` (RuntimeException saat aktivasi): `login`,
  `logout`, `profile`, `experiences`, `education`, `skills`, `certifications`, `awards`,
  `projects`, `posts`, `media`, `settings`, `themes`, `plugins`, `backup`. Pola slug
  `[a-z0-9][a-z0-9-]*`; slug yang kembar juga ditolak.
- Form memakai `{{ csrf_field() }}`, dan **POST kembali ke URL yang sama** (`/admin/{slug}`) —
  tidak perlu route khusus untuk menyimpan settings.
- `setting()` hanya membaca; menulis lewat `mops(\Mops\Core\Settings::class)->set()`.
- Daftarkan dari entry file plugin (hanya plugin **aktif** yang punya halaman). Nonaktifkan
  plugin → halaman dan menunya hilang otomatis; slug yang tak terdaftar → 404.
- Sidebar: halaman plugin muncul di grup `Plugins`; plugin bisa menata/menyisipkan item lewat
  filter `admin_menu`.

## 13. Tema: functions.php & child theme

Urutan boot (`App::loadPlugins()`), mengikuti WordPress:

1. `mops_before_plugins`
2. entry file setiap plugin aktif
3. `plugins_loaded`
4. `ThemeManager::loadFunctions()` — `functions.php` tema aktif (child dulu, lalu parent)
5. `do_action('after_setup_theme')`

`functions.php` hanya dimuat setelah sistem terpasang (`isInstalled()`) dan sekali per request; di
dalamnya hook API, `mops()`, `setting()`, `theme_asset()` tersedia. Error di-guard `try/catch` —
dicatat di `ThemeManager::errors()` dan tampil di `/admin/themes` (field `load_errors`), bukan
white-screen. Fatal di level bahasa (mis. mendeklarasi ulang fungsi) tetap menggugurkan request,
seperti WordPress.

Child theme = field `parent` di manifest tema:

```json
{ "name": "Teguh Custom", "parent": "vantablo", "version": "1.0.0" }
```

- **Template:** `templatePaths()` → `[child/templates, parent/templates]`; `templatePath()`
  (string) tetap ada dan mengembalikan jalur pertama. `missing_templates` dihitung atas gabungan
  child + parent, jadi child yang hanya meng-override `home.twig` tetap dianggap lengkap.
- **Asset:** rantai child dulu, baru parent — `assetUrl()` dan `assetPath()` mengecek file di
  child sebelum jatuh ke parent.
- **Validasi:** `assertActivatable()` menuntut parent ada & lengkap, parent tidak punya parent
  sendiri (maksimal 2 level), dan tidak ada lingkaran. Dipanggil otomatis oleh `activate()` dan
  oleh upload zip.
- Runtime: child yang kehilangan parent-nya jatuh ke tema default (`activeSlug()`), jadi situs
  tidak pernah mati.

## 14. Installer zip

Form "Upload Plugin" di `/admin/plugins` (field `plugin_zip`) dan "Upload Theme" di
`/admin/themes` (field `theme_zip`); tombol disembunyikan bila ekstensi `ZipArchive` tidak ada.
Semua POST melewati CSRF middleware.

Alur (`ZipInstaller::installUpload()` → `install()`):

1. upload divalidasi (ekstensi `.zip` + MIME nyata via `Uploader::detectMime()`), disalin ke
   `storage/tmp/upload-<rand>.zip`;
2. ekstrak ke `storage/tmp/install-<rand>/` — tiap entry ditolak bila path absolut, mengandung
   `..`, atau symlink (Zip Slip = `RuntimeException`);
3. deteksi struktur: **satu folder di root** yang berisi `plugin.json`/`theme.json` → slug = nama
   folder itu; selain itu (**zip datar**) manifest root **wajib punya field `"slug"`** yang valid;
   slug yang sudah ada di `plugins/`/`themes/` → tolak;
4. `rename()` atomik ke `plugins/<slug>` atau `themes/<slug>`; folder staging dibersihkan selalu
   (`finally`), jadi instalasi setengah jadi tidak pernah mendarat di `plugins/`/`themes/`;
5. **tema:** setelah pindah, controller memanggil `assertActivatable()` (template wajib + parent
   sah); gagal → folder hasil install dihapus kembali (rollback), error muncul sebagai flash.

Plugin tidak divalidasi lebih jauh saat install — file entry dicek saat aktivasi
(`PluginManager::activate()` melempar "manifest atau file utama tidak ditemukan").

**Hapus** (`POST /admin/plugins/{slug}/delete`, `POST /admin/themes/{slug}/delete`):

- Plugin: wajib sudah nonaktif (melempar "masih aktif", seperti WP); `uninstall.php` (kalau ada)
  dijalankan **sebelum** folder dihapus — errornya dicatat di `errors()`, penghapusan tetap lanjut;
  guard `Paths::isInside` mencegah hapus di luar `plugins/`.
- Tema: ditolak bila sedang aktif atau menjadi parent tema aktif; tidak ada `uninstall.php` untuk
  tema.
- Gagal install/hapus muncul sebagai flash message di halaman admin; `load_errors` di
  `/admin/plugins` dan `/admin/themes` khusus error load (`functions.php` / entry file).
