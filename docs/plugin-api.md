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
| `register_activation_hook()` / `register_deactivation_hook()` | nama & semantik sama | callback lifecycle hanya untuk plugin tersebut |
| `functions.php` tema | `functions.php` opsional | dimuat hanya dari tema yang aktif |
| `wp_head()` wajib di tema | `HookZones::REQUIRED_THEME_ZONES` | tema wajib mencetak zona inti, kalau tidak plugin "hilang" |
| `wp_register_sidebar_widget` (widget area) | `home_before_sections` / `home_after_sections` | area bertema halaman, khusus homepage |

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
diaktifkan. Bila tema aktif hilang atau rusak, MOPS kembali ke tema valid bawaan.

Tema boleh memiliki `functions.php`. File ini hanya dimuat ketika tema tersebut aktif dan dapat memakai
`add_action()`/`add_filter()` untuk perilaku khusus tema. Jangan mengambil alih query domain dari sini;
data tetap disiapkan core, tema tetap bertugas mempresentasikan data.

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
`theme_deactivating`, `theme_deactivated`, `theme_activated`, `plugin_activated`, `plugin_deactivated`,
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

## 8. Aktivasi dan deaktivasi

Folder plugin berarti **terpasang**, bukan otomatis aktif. Hanya slug di setting `active_plugins` yang
dimuat. Admin bebas mengaktifkan sebagian plugin; misalnya dua dari lima plugin. Callback aktivasi hanya
dijalankan sekali ketika status berubah dari nonaktif ke aktif, bukan pada setiap request:

```php
register_activation_hook(__FILE__, static function (): void {
    // Buat tabel/default milik plugin secara idempoten.
});

register_deactivation_hook(__FILE__, static function (): void {
    // Hentikan scheduler/cache sementara; jangan hapus data pengguna.
});
```

Jika bootstrap atau callback aktivasi melempar exception, aktivasi dibatalkan dan plugin tidak disimpan
sebagai aktif. Deaktivasi juga menjalankan callback sebelum status dihapus. Menghapus data permanen bukan
tugas deaktivasi; sediakan mekanisme uninstall terpisah bila kelak diperlukan.

Tema mengikuti model WordPress: tepat satu tema aktif. Tidak ada tombol "nonaktifkan tema"; mengaktifkan
tema lain otomatis mengganti tema lama. Event pergantian berurutan:
`theme_deactivating` → simpan tema baru → `theme_deactivated` → `theme_activated`.

## 9. Contoh lengkap: `mops-cv-ats`

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

## 10. Memeriksa hasil kerja

```bash
php bin/mops plugin:list      # daftar plugin + status
composer check                # php -l + PHPUnit (kontrak tema & registry zona diuji di sini)
php dev/smoke.php             # end-to-end in-process
composer serve                # lalu buka /admin/plugins -> kartu "Injection Zones"
```

## 11. Guardrail

- Jangan mengedit `app/` atau `themes/` dari plugin. Semua lewat hook, zona, route, manifest.
- Plugin hanya menulis di folder plugin itu sendiri dan di `storage/`.
- Label publik berbahasa Indonesia, UI admin berbahasa Inggris (`docs/admin-ui.md`).
- Fitur non-core (analitik, SEO, contact form, integrasi GitHub/Spotify, ekspor PDF/CV) memang tempatnya
  di plugin — bukan di core.
