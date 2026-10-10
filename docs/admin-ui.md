# Admin UI — konvensi desain

Referensi visual: `prototype/html/admin-template/` (`dashboard.html`, `table.html`, `form.html`).

## Gaya

Flat material:

- Kartu putih di atas latar abu terang (`#f5f5f5`), shadow tipis 2–4px, tanpa gradient/glassmorphism
- Aksen solid datar: indigo primer; hijau (Published/success), amber (Draft/warning), merah (danger), biru, abu netral
- Sudut `rounded-lg`, font Inter (sans-serif), ikon SVG inline

## Shell (konsisten di semua halaman admin)

- **Sidebar kiri** fixed 260px: logo "MOPS Admin", nav berkelompok — OVERVIEW (Dashboard, Analytics), CONTENT (Profile, Projects, Blog, Media), SYSTEM (Plugins, Themes, Settings) — blok user di bawah
- **Topbar**: hamburger (drawer off-canvas via Alpine.js di bawah breakpoint `lg`), breadcrumb, search, ikon notifikasi + badge, avatar + dropdown
- **Footer** tipis dengan copyright
- Konten utama: max-width nyaman, responsif (kolom menumpuk di mobile)

## Halaman

- **Dashboard** (`01-dashboard.txt`): 4 stat card (Projects, Blog Posts, Media Files, Page Views + delta %), chart line "Views — Last 30 Days" dan doughnut "Traffic Sources" (Chart.js), tabel Recent Activity
- **Table** (`02-table.txt`): DataTables dengan 25 data dummy Projects — search, sort, pagination (10/halaman), bulk action + checkbox toolbar, kolom aksi Edit/Hapus, pill status
- **Form** (`03-form.txt`): dua kolom — semua jenis field (text/icon/disabled/error/success, textarea + counter, select, multi-select, date/time/number/URL/email, range slider, dropzone upload), checkbox/radio/switch Alpine, kartu Publish / Featured Image / Danger Zone, referensi lengkap semua varian button (solid, outline, text/icon, sm/md/lg, disabled, loading, FAB, extended FAB, segmented group), modal Alpine + alert banner

## Catatan implementasi (MOPS asli)

- Prototype memakai Tailwind CDN + jQuery/DataTables/Chart.js CDN. Saat implementasi: Tailwind build lokal + Alpine dibundel; **DataTables dan Chart.js hanya opsional** — boleh diganti tabel/chart sederhana ber-Alpine kalau cukup.
- Bahasa UI admin: **English** (berbeda dengan label bagian publik yang Indonesia).
- Admin = Tailwind + Alpine, tanpa SPA React/Vue (aturan stack terkunci).
- Shell admin dipakai ulang di semua halaman — jangan buat layout per halaman.
