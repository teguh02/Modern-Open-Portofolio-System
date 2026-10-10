# MOPS Homepage Sections — Design

Tanggal: 2026-10-10
Status: Approved (Approach A — Section Registry)
Scope: tampilan homepage publik + halaman detail per bagian + visibility settings

## Ringkasan

Homepage publik = satu halaman gaya LinkedIn: semua bagian profil berurutan di satu halaman.
Tiap bagian hanya menampilkan N item teratas + tombol "Lihat selengkapnya" yang mengarah ke
halaman khusus berisi daftar lengkap. Saat install baru, hanya identitas + bio yang tampil;
bagian lain menyala saat admin menyalakannya dan datanya sudah ada.

## Keputusan yang sudah dikunci

1. **Visibility = auto-hide + toggle admin.**
   - Flag OFF → bagian tidak tampil walau datanya ada.
   - Flag ON tapi data kosong → bagian tidak tampil (auto-hide).
   - Keduanya berlaku bersama-sama (AND).
2. **Fresh install: hanya `profile` (identitas) dan `about` (bio) default ON.**
   Tidak ada kode seeding: default hidup di registry, key settings yang hilang = pakai default
   registry. Install baru otomatis = hanya identitas + bio.
3. **Limit item per bagian beda-beda, hard-coded di registry, admin TIDAK bisa mengubah.**
4. **Urutan bagian fixed** = urutan array registry. Tidak ada UI reorder.
5. **Arsitektur = Approach A (Section Registry di core)** — visibility, limit, dan URL
   "selengkapnya" dihitung core; theme hanya merender data yang sudah jadi.

## Section Registry

Registry = satu array ordered di core, satu-satunya sumber kebenaran untuk
urutan, label, limit, default visibility, dan route detail.

| # | id | Judul | Limit | Default | Selalu tampil | Detail route |
|---|----|-------|-------|---------|---------------|--------------|
| 1 | `profile` | Identitas (hero) | — | ON | ya (tidak bisa dimatikan) | — |
| 2 | `about` | Tentang / Bio | — (full inline) | ON | tidak | — |
| 3 | `experience` | Riwayat Kerja | 5 | OFF | tidak | `/experience` |
| 4 | `projects` | Proyek | 6 | OFF | tidak | `/projects` |
| 5 | `skills` | Keahlian | 12 | OFF | tidak | `/skills` |
| 6 | `education` | Pendidikan | 3 | OFF | tidak | `/education` |
| 7 | `certifications` | Sertifikasi | 5 | OFF | tidak | `/certifications` |
| 8 | `awards` | Penghargaan | 3 | OFF | tidak | `/awards` |
| 9 | `blog` | Blog Terbaru | 4 | OFF | tidak | `/blog` |

Catatan:
- Slug route bahasa Inggris, fixed di core (lokalisasi URL = pekerjaan lain, nanti).
- `about` tidak punya halaman detail — bio ditampilkan penuh di homepage.
- `profile` = header hero (nama, headline, foto, lokasi, social links), selalu tampil.
- Penambahan bagian ke depan = tambah entri registry + tabel/repository-nya.
  Services/Testimonials belum punya tabel core → belum masuk daftar; mekanisme sudah siap.

### Bentuk data (kontrak core → theme)

```php
$sections = [
    [
        'id'       => 'experience',
        'title'    => 'Riwayat Kerja',   // label siap pakai
        'items'    => [...],             // sudah di-slice sesuai limit, urut final
        'total'    => 12,                // jumlah total data
        'more_url' => '/experience',     // null kalau tidak ada halaman detail
    ],
    // ...
];
```

## Data flow

### Homepage (GET /)

1. `ProfilePageController` minta registry + settings + data per bagian.
2. Per entri registry: `flag = setting('section_enabled_{id}') ?? default`;
   `visible = alwaysVisible || (flag && total > 0)`.
3. Bagian yang visible → query dengan ORDER BY + LIMIT sesuai limit registry.
4. Susun `$sections[]` (kontrak di atas) → passing ke theme bersama schema `portfolio`.
5. Theme `home.twig` loop `sections`, render partial per `id`, tampilkan tombol
   "Lihat selengkapnya" hanya jika `total > items|length` dan `more_url` tidak null.

### Halaman detail (GET /experience, /projects, dst.)

1. Route per bagian detail → controller query daftar lengkap.
2. Theme render `pages/{id}.twig` milik theme, dengan data + link kembali ke homepage.
3. `/blog` = index blog yang sudah ada (post dapat banyak → satu-satunya tempat
   pakai pagination, 10/halaman). Lainnya: daftar penuh tanpa pagination —
   data portofolio wajar-nya muat satu halaman; tambahkan pagination saat terbukti panjang.

### Ordering query (final)

- experience: `start_date DESC` (terbaru di atas)
- projects: `created_at DESC`
- education: `end_date DESC`
- certifications: `issued_date DESC`
- awards: `year DESC`
- blog: `published_at DESC`
- skills: `sort_order ASC`

## Settings

- Tabel `settings` (key/value): key `section_enabled_{id}` = `1` | `0`.
- Key tidak ada → pakai default registry (inilah mekanisme "fresh install = identitas + bio").
- Admin UI: satu blok checkbox per bagian di halaman Settings (kecuali `profile`,
  tidak ditampilkan karena selalu aktif). Toggle only — limit dan urutan tidak bisa diubah.

## Kontrak theme

- **Homepage**: loop `sections` dari core → partial `sections/{id}.twig` milik theme.
  Judul, items, more_url datang dari core; theme tidak menghitung limit/visibility/route.
- **Detail**: theme sediakan `pages/{id}.twig` per bagian yang punya route.
- Core tidak pernah menghardcode HTML; theme tidak pernah membaca nama tabel/kolom.
- Theme boleh mengabaikan sebuah section (tidak di-render) — itu pilihan theme.

## Behaviour rules (ringkas)

- `visible = (alwaysVisible || flag ON) && (total > 0 || alwaysVisible)`.
- Tombol "selengkapnya" muncul hanya jika `total > limit` dan bagian punya detail route.
- `profile`: selalu tampil, tanpa toggle, tanpa tombol.
- `about`: toggle-able, default ON, tampil penuh tanpa tombol.

## Testing

- Unit: matriks visibility (flag ON/OFF × total 0/n), slicing + urutan per bagian,
  kondisi munculnya `more_url`.
- Controller: bentuk payload homepage (sections array sesuai kontrak); detail mengembalikan
  daftar lengkap.
- Integration: homepage hanya memuat section yang visible; fresh install settings = default.

## Di luar scope

- Pagination halaman detail selain `/blog`
- Admin mengubah limit atau urutan bagian
- Localisasi slug route
- Services/Testimonials (tabel belum ada di core)
- Child theme / theme inheritance (topik terpisah, roadmap 0.3)

## Dampak roadmap

Masuk **MOPS 0.1** (profile + default theme sudah ada di roadmap): registry, homepage,
halaman detail, dan toggle settings dikerjakan bersama Default Theme.

## Prototype terkait

Referensi visual untuk homepage + halaman detail per bagian:
`prototype/html/vantablo/` (tema gelap) dan `prototype/html/lumica/` (tema terang).
Jika markup prototype konflik dengan spec ini, spec menang.
