# Digital Twin & Dam Monitoring System — Bendungan Budong Budong

Dashboard pemantauan bendungan untuk BWS Sulawesi V: peta interaktif dengan latar
bendungan yang mengikuti posisi matahari sebenarnya, panel data instrumentasi
bergaya *liquid glass*, dan viewer panorama 360° untuk setiap titik sensor.

Backend Laravel 13 + MySQL, frontend Blade + Tailwind CSS v4 + Alpine.js,
panorama dengan Photo Sphere Viewer, dan grafik dengan ECharts.

---

## Ringkasan fitur

| Halaman | Isi |
|---|---|
| **3D Digital Twin** (`/digital-twin`) | Panggung utama: panorama 360° tubuh bendungan (`Panoramic_Base Dam`) yang bisa diputar/di-zoom, 15 penanda stasiun berwarna status di dalam bola panorama, pencarian lokasi, dan panel ringkasan di kanan. Klik penanda → panorama stasiun itu. |
| **Viewer 360°** (`/digital-twin/{kode}`) | Panorama drone per stasiun, hotspot yang menampilkan bacaan sensor, tautan antar panorama, dan panel kanan berisi detail stasiun (grafik, ambang batas, riwayat peringatan & perawatan). Bar kiri tetap sama. |
| **Dashboard** (`/dashboard`) | KPI utama, tren muka air/debit, status seluruh stasiun, peringatan, dan perawatan mendatang. |
| **Data Instrumentasi** (`/sensor`) | Tabel 17 stasiun + filter tipe, pintasan ke 360° dan grafik. |
| **Analisa & Grafik** (`/analisa`) | Grafik per parameter dengan garis ambang batas, rentang 24 jam / 7 hari / 30 hari, dan statistik ringkas. |
| **Perawatan** (`/perawatan`) | Papan kanban preventif/korektif/kalibrasi; status bisa dipindah langsung. |
| **Peringatan** (`/peringatan`) | Riwayat pelampauan ambang; bisa ditinjau dan diselesaikan. |
| **Laporan** (`/laporan`) | Bangkitkan laporan PDF (dompdf) atau CSV data mentah, lalu unduh. |
| **Pengaturan** (`/pengaturan`) | Kunci fase latar peta, lihat identitas bendungan, ubah ambang batas tiap parameter, dan lihat cara menyambung telemetri. |

### Latar bendungan mengikuti jam asli

`App\Support\SolarClock` menghitung posisi matahari (algoritma NOAA) untuk
koordinat bendungan (−1,9536, 119,3411 — Asia/Makassar). Hasilnya dipakai untuk:

- memilih dua dari empat panorama (`malam`, `fajar`, `siang`, `senja`) lalu
  **crossfade** sesuai ketinggian matahari, bukan sekadar berganti gambar;
- *color grading* halus (kecerahan, kontras, saturasi, kehangatan cahaya).

Semua nilai tersedia di `GET /api/environment`. Fase bisa dikunci manual di
halaman Pengaturan (tersimpan di tabel `settings`).

### Skenario langit: mendung dan rintik hujan

Tutupan awan tidak punya sensor, tapi punya bekas: pada ketinggian matahari
tertentu langit cerah menghasilkan cahaya sebesar tertentu, jadi **iluminasi
yang jauh di bawah angka itu berarti awan**. Dipasangkan dengan penakar hujan,
keduanya memisahkan kejadian yang penting bagi operator:

| Iluminasi | Hujan | Dibaca sebagai |
|---|---|---|
| ± sesuai perkiraan | kering | Cerah |
| turun 25–55% | kering | Berawan |
| turun > 55% | kering | **Mendung** |
| turun | 0,1–12 mm/jam | **Rintik hujan** |
| apa pun | > 12 mm/jam | Hujan |
| matahari di bawah ufuk | kering | Malam (awan tidak ditebak) |

Panggung menggambarkannya: cahaya dan warna dikurangi lewat *grading*, sisa
kelabunya dilapis satu selubung, dan hujan digambar dua lapis garis dengan
kecepatan berbeda supaya terasa berjarak. Setiap keadaan membawa satu kalimat
alasan, mis. *"Iluminasi 12.000 lux dari perkiraan langit cerah 96.000 lux
(turun 87%), sementara hujan 3,5 mm/jam dan 22 mm dalam 24 jam."*

Untuk **uji skenario (what-if)**, panel jam di panggung punya baris **Skenario
langit**: `Otomatis` mengikuti sensor, sisanya (`Cerah`, `Berawan`, `Mendung`,
`Rintik`, `Hujan`) memaksa tampilan dan diberi label **simulasi** — angka
sensornya tidak diubah sama sekali.

### Kontrol waktu panggung

Gradasi ini dipakai panorama panggung dan latar seluruh halaman lain.

Latar halaman (dan halaman masuk) memakai panorama 360 bendungan yang sama
dengan panggung — tier `preview` 2048px, ±100–210 KB per fase — dan **bergeser
pelan** ke samping, satu putaran penuh 240 detik. Karena gambar 360 menyambung
di ujungnya, perulangan tidak terlihat. Gerakan berhenti di layar kecil
(< 640px) dan saat sistem meminta `prefers-reduced-motion`.

Chip jam di kanan atas panggung digital twin bisa dibuka menjadi panel kontrol
ringkas: **pilihan Otomatis/Kustom**, **slider waktu**, dan **pilihan kecepatan**.

- **Otomatis** mengikuti jam lokasi, **Kustom** memakai waktu simulasi. Menggeser
  slider, menekan putar, atau memilih kecepatan juga otomatis pindah ke Kustom.
- Tombol kecepatan diberi label pengalinya: **60×, 300×, 900×, 3600×** — yaitu
  1, 5, 15 menit, atau 1 jam untuk tiap detik nyata (keterangannya ada di bawah
  tombol).

Supaya pemutaran mulus tanpa satu permintaan per frame — dan tanpa menyalin
rumus matahari ke JavaScript — server mengirim kurva sehari lewat
`GET /api/environment/curve?step=10` (145 sampel), lalu browser meng-interpolasi
di antaranya.

Panggung menahan **keempat still sekaligus** (malam, fajar, siang, senja) dan
hanya menganimasikan opasitas masing-masing. Sebelumnya dua lapisan bertukar
`background-image` saat fase berganti, dan browser sempat men-decode ulang —
itu yang terlihat sebagai kedipan. Interpolasi juga dilakukan dalam ruang bobot,
bukan memilih sampel terdekat, jadi pergantian siang→senja→malam benar-benar
bertahap. Waktu simulasi maju tiap frame animasi (dengan interval 250 ms sebagai
cadangan saat tab tidak aktif), sehingga lajunya persis seperti angka tombol
kecepatan. Jam pada header ikut menampilkan waktu simulasi dengan penanda
"simulasi" supaya tidak tertukar dengan jam asli.

### Label penanda

Kolom **Cari lokasi** ada di header, tepat di samping jam (di ponsel jadi tombol
ikon), dan **hanya muncul di halaman Digital Twin** — kolom itu mengarahkan
kamera panggung, jadi tempatnya di halaman yang punya panggung. Tekan `/` untuk
melompat ke sana. Memilih hasil memutar kamera ke penanda itu **dan menampilkan
namanya** — sekalipun label sedang dimatikan — plus denyut singkat supaya jelas
yang mana. Namanya bertahan sampai panggung disentuh lagi atau stasiun lain
dibuka.

Pil **Label** pada bar bawah panggung menyalakan atau mematikan keterangan di
samping tiap penanda. Saat mati hanya pin berwarna yang tampil; menyorot sebuah
penanda atau membuka panoramanya tetap memunculkan labelnya. Pilihannya
tersimpan per browser (`localStorage`, kunci `twin.labels`).

### Empat waktu untuk panorama utama

Panorama dasar punya empat tekstur, dibangun dari render di
`Panoramic 360 fix\Transitions\Base Dam` oleh
`python tools/build_panorama_phases.py`:

| Fase | Sumber | Hasil |
|---|---|---|
| Fajar | `Panoramic_Base_Dam_Fajar.png` | `base-dam-dawn.webp` |
| Siang | `Panoramic_Base_Dam_Siang.png` | `base-dam-day.webp` |
| Senja | `Panoramic_Base_Dam_Senja.png` | `base-dam-dusk.webp` |
| Malam | `Panoramic_Base_Dam_Malam.png` | `base-dam-night.webp` |

Keempatnya 1774×887 seperti panorama lain, jadi masing-masing dinaikkan dengan
Real-ESRGAN x4 (jahitan 360° dibungkus dulu), diturunkan ke 4096×2048, lalu
ditulis tiga tingkat (HD, preview, thumb). Hasil upscale disimpan sebagai master
HD, jadi menjalankan ulang skripnya tidak memakai GPU lagi kecuali diberi
`--force`.

Karena keempatnya satu batch render — sudut, awan, dan tata letaknya sama —
panggung bisa berpadu-silang di antaranya tanpa gambar melompat:

- pergantian dipicu saat bobot fase matahari menyilang (`stage.base.phases` di
  `/api/environment`), ditunda 400 ms supaya menggeser slider tidak menukar
  tekstur bolak-balik;
- padu-silangnya **2,6 detik** pada jam nyata, dipercepat jadi 0,9 detik saat
  waktu sedang disimulasikan;
- gradasi matahari tetap jalan di atasnya tapi hanya **setengah kekuatan** —
  tiap tekstur sudah punya cahayanya sendiri, dan menit-menit di antara dua
  tekstur diisi oleh gradasi itu.

### Perawatan: permintaan, percakapan, riwayat

Menu **Perawatan** punya tiga bagian:

- **Tiket & Pesan** — daftar pekerjaan beserta percakapannya. Operator ruang
  kendali menulis di satu sisi, layanan teknis membalas di sisi lain; sisinya
  diambil dari peran akun (`roles.desk_side`), jadi tidak bisa mengaku-aku.
  Pesan yang belum dibuka memunculkan angka merah di menu Perawatan dan di
  tiketnya — dihitung dari sisi pembacanya sendiri.
- **Papan Tugas** — papan kanban jadwal perawatan (terjadwal, berjalan,
  tertunda, selesai) seperti sebelumnya.
- **Riwayat** — catatan pekerjaan yang sudah selesai per alat: tanggal selesai,
  alat, jenis pekerjaan, pelaksana, dan catatannya. Bisa disaring per stasiun.

Percakapannya dibaca seperti ruang obrolan: daftar tiket di kiri menampilkan
**kutipan pesan terakhir** beserta jamnya, dan utasnya di kanan memberi
**judul tanggal** saat harinya berganti, menyatukan pesan berurutan dari orang
yang sama (satu avatar dan nama per rentetan, jamnya di bawah baris terakhir),
serta menandai **batas "Belum dibaca"**. Kedua kolom punya tingginya sendiri dan
menggulir di dalamnya, jadi kolom tulis pesan tidak pernah lari ke bawah layar.

Tombol **Ajukan perawatan** membuka **dialog** — bukan panel yang mendorong meja
kerja ke bawah — dan membuat pekerjaan baru sekaligus membuka percakapannya
(`POST /api/maintenance/requests`). Endpoint lainnya:
`GET /api/maintenance/tickets`, `GET /api/maintenance/history`,
`POST /api/maintenance/{id}/messages`, dan `POST /api/maintenance/{id}/read`.

### Pengguna, peran, dan hak akses

Menu **Pengguna & Akses** (hanya untuk peran yang memegang `users.manage`)
berisi dua bagian:

- **Pengguna** — menambah akun, mengubah nama/email/unit, memindahkan peran,
  menyetel ulang kata sandi, dan menonaktifkan akun. Akun nonaktif tetap
  menyimpan riwayatnya tetapi ditolak saat masuk.
- **Peran & Hak Akses** — membuat peran, memilih sisi perawatannya (operator
  ruang kendali atau layanan teknis), dan mencentang hak aksesnya.

Tambah, ubah, dan hapus dikerjakan lewat **dialog**: satu dialog untuk akun,
satu untuk peran, dan satu konfirmasi hapus (bukan kotak bawaan browser).
Penyimpanannya tetap kiriman formulir biasa, jadi validasi dan CSRF tetap di
sisi server; kalau ada yang belum benar, dialognya terbuka kembali berisi apa
yang tadi diketik. Tombol Esc atau klik di luar menutup dialog.

Katalog hak akses ada di `config/access.php`, bukan di database: peran hanya
menyimpan kode yang dicentang, sehingga hak akses baru cukup ditambahkan satu
baris di sana lalu dicek dengan `can()` / `@can`.

| Kode | Artinya |
|---|---|
| `maintenance.view` | membuka menu Perawatan |
| `maintenance.request` | mengajukan permintaan perawatan |
| `maintenance.reply` | membalas pesan pada tiket |
| `maintenance.status` | memindahkan kartu di papan tugas |
| `stations.move` | menggeser penanda di peta dan panorama 360 |
| `thresholds.edit` | mengubah ambang batas instrumentasi |
| `alerts.handle` | meninjau dan menyelesaikan peringatan |
| `reports.create` | membangkitkan laporan |
| `users.manage` | mengelola pengguna |
| `roles.manage` | mengelola peran dan hak aksesnya |

Peran bawaan seeder: **Administrator** (seluruh hak akses, sisi layanan
teknis), **Operator Ruang Kendali**, **Layanan Teknis**, dan **Pengawas**.
Peran administrator selalu memegang seluruh katalog — itu peran yang membagikan
akses, jadi tidak bisa dipersempit sampai tak seorang pun dapat melebarkannya
kembali. Menu di rel kiri, tombol pada tiap halaman, dan endpoint API mengikuti
katalog yang sama, jadi tidak ada tombol yang menjawab 403.

### Gerak otomatis panggung

Panggung bergeser sendiri saat tidak disentuh, tetapi **menyapu kiri-kanan**,
bukan memutar penuh 360°. Sebuah putaran penuh pada akhirnya melewati garis
sambungan render — satu-satunya bagian gambar yang tidak layak dilihat. Lebar
sapuannya diatur `dam.stage.drift_arc` (bawaan 55° ke tiap sisi dari bingkai
panorama itu sendiri), dan titik tengahnya disetel ulang setiap kali panorama
berganti. Menggeser dengan tangan tetap bebas 360°.

Sapuannya berangkat **ke kanan dulu**, lalu kembali ke kiri.

Panggung terbuka menghadap **36° TL** (`dam.stage.default_bearing`) — sebuah
arah kompas, bukan yaw mentah, jadi bingkainya tidak bergeser kalau
`north_offset` dikoreksi. Bingkainya juga yang terlebar
(`dam.stage.default_zoom` = 0), jadi penunjuk di kiri bawah membaca **0%** saat
pertama muncul; tombol +/− mendekat dari sana.

### Analisa & Grafik: dua tampilan

Menu **Analisa & Grafik** punya dua tampilan dengan satu set kontrol
(stasiun + rentang) di atasnya:

- **Grafik** — tiap parameter jadi satu kartu chart, dua per baris, mengalir ke
  bawah. Pilihan stasiun **"Semua stasiun — parameter utama"** menampilkan
  parameter utama tiap stasiun beserta titik statusnya. Tiap kartu memuat nilai
  terakhir, selisih sepanjang rentang, dan garis ambang batasnya. Mengklik
  kartu membukanya di Analisa.
- **Analisa** — parameter yang **dicentang digabung dalam satu grafik**.
  Centang beberapa sekaligus (mis. ADR-01 + ADR-02, atau muka air hulu +
  hilir) dan semuanya ditumpuk dengan legenda. Satuan kedua mendapat **sumbu
  kanan** sendiri yang diberi nama satuannya; satuan ketiga tidak bisa ikut —
  tiga skala dalam satu grafik tidak terbaca, jadi tombolnya dinonaktifkan
  beserta alasannya. Statistik (terakhir/min/maks/rata-rata) mengikuti
  parameter pertama yang dipilih dan menyebutkan namanya.

Kartu digambar saat masuk ke layar, bukan semuanya sekaligus, dan pilihan
stasiun/rentang/tampilan diingat di `localStorage`.

Kalau chart tampak kosong dengan tulisan "Tidak ada bacaan pada rentang ini",
data demonya sudah tertinggal — jalankan `php artisan telemetry:simulate`.

### Kompas panggung

Kompas di kiri atas panggung kini berupa alat, bukan lambang yang ikut berputar:
rangkanya diam beserta penanda biru di puncaknya — itulah arah pandang kamera —
sementara piringan berisi jarum utara (merah), huruf **U**, dan tiga garis
penunjuk berputar di bawahnya. Angkanya dibaca di bawah rangka, lengkap dengan
mata angin Indonesia (`142° TG`). Mengklik kompas memutar kamera menghadap
utara. Piringannya memakai sudut berjalan, bukan 0–360, supaya saat melewati
utara ia meneruskan putaran alih-alih berbalik satu lingkaran penuh.

Arah utara di dalam panorama diatur lewat `dam.stage.north_offset` (derajat),
dan tiap stasiun bisa menimpanya dengan kolom `panorama_north_offset`. Render
panorama tidak membawa informasi orientasi — matahari di render fajar dan senja
hanya berselisih 18°, jadi tidak bisa dipakai menghitung utara — sehingga nilai
ini harus diisi dari data lapangan sekali saja.

### Aksesibilitas & sentuh

- Cincin fokus keyboard berlaku di seluruh aplikasi (`:focus-visible`), jadi
  operator yang bekerja tanpa tetikus selalu tahu posisinya. Tombol ikon punya
  `aria-label`, dan tombol dua-keadaan punya `aria-pressed`.
- **Esc** menutup panorama stasiun maupun panel geser.
- Di ponsel, daftar stasiun tampil sebagai kartu (bukan tabel yang harus digeser
  mendatar) dan seluruh sasaran sentuh minimal 40 px.
- 156 kolom isian ambang batas di Pengaturan punya nama yang terbaca pembaca
  layar, penanda "belum disimpan", serta pesan gagal bila penyimpanan tertolak.

### Melipat panel

Kolom kiri diringkas jadi deretan ikon lewat tombol chevron di bawah menu.
Panel kanan punya pegangan sendiri di tepi kirinya — **hanya di halaman 3D
Digital Twin** (layar ≥ 1280 px), karena hanya panggung itu yang mendapat
manfaat dari ruang tambahan; di halaman lain panel itu isi utamanya, jadi selalu
tampil: sekali
klik panelnya terlipat ke kanan dan panggung/isi halaman melebar memakai
ruangnya; pegangannya ikut pindah ke tepi layar untuk membukanya lagi. Di bawah
1280 px panel itu memang sudah berupa lapisan geser dengan tombolnya sendiri.
Kedua pilihan tersimpan per browser.

### Kolom kiri

Menu dan kartu **Sistem Monitor** menempati satu kolom: menu di atas, monitor
menempel di dasar kolom, keduanya selebar `--rail-w`. Tombol chevron di bawah
menu meringkasnya jadi deretan ikon 64 px — panggung ikut melebar karena
`--rail-w` yang sama dipakai untuk menghitung `--stage-left`. Di layar < 1440 px
menu memang hanya ikon, jadi Sistem Monitor tampil sebagai deretan titik status
(disorot untuk melihat namanya) alih-alih baris teks yang terpotong.

### Panggung 360°

Panggung digital twin adalah panorama bendungan itu sendiri — satu instansi
Photo Sphere Viewer yang memuat panorama dasar (`base-dam`) beserta penanda
seluruh stasiun. Klik penanda dan bola panorama berganti ke panorama stasiun
tersebut lengkap dengan hotspotnya; tombol **Kembali ke panorama utama**
mengembalikannya. Tidak ada viewer kedua, jadi hanya satu konteks WebGL yang
hidup.

Di dalam panorama stasiun, hotspot tidak hanya menandai alatnya. **ADR-02**
(robotic total station kiri) juga menandai **patok geser** — prisma reflektor
yang dibidik alat itu. Hanya ADR-02: kedua ADR membidik tubuh bendungan yang
sama, dan menggambar dua set patok di atas satu struktur menghasilkan gambar
yang tidak terbaca. Susunannya mengikuti bendungannya, bukan bidang gambar:
**tiga garis** melangkah menjauh dari puncak di tiap lereng (hulu dan hilir),
dan tiap garis berisi **lima patok** yang berbaris **searah tubuh bendungan**
— jadi 3 × 5 patok per lereng. **Tiap patok punya petaknya sendiri**, dengan
patoknya berdiri di tengah petak itu, dan petak yang lebih jauh digambar lebih
kecil serta lebih rapat sesuai perspektifnya.

Petaknya digambar sebagai kotak garis putus-putus yang **ikut menempel di
lereng** (poligon berkoordinat bola, jadi perspektifnya benar), sementara
patoknya digambar sebagai tanda sasaran survei — belah ketupat putih bergaris
gelap dengan titik di tengahnya. Patok itu tandanya, bukan bendanya: patok
asli tingginya sekitar satu meter pada jarak bidik 130 m — sepertiga derajat,
tidak akan kelihatan kalau digambar seukuran aslinya — jadi tandanya dibuat
tetap seukuran layar sementara petaknya ikut membesar saat di-zoom. Arahkan
kursor ke satu patok untuk melihat kodenya (`PG-HU2-3`).

Di bawah tiap patok tercetak **pergeseran liniernya dalam mm**, diwarnai
menurut statusnya — muncul begitu panggung di-zoom cukup dekat (dan selalu
muncul untuk patok yang sedang disentuh kursor), karena pada zoom paling lebar
patok terjauh dalam satu garis hanya berjarak sepuluh piksel dan angkanya
akan saling tumpuk. Klik satu patok dan panel kiri bawah menampilkan angka
besarnya beserta rincian komponen horizontal dan vertikalnya. Tombol
**arah pergeseran** (ikon deformasi di kluster kanan bawah, atau tombol di
panel itu) menggambar panah dari tiap patok ke arah geserannya, panjangnya
sebanding dengan besar pergeseran — skalanya, 10 px per mm, dicetak di bawah
panggung supaya panahnya bisa dibaca, bukan hanya dilihat.

Dua catatan soal angka itu. Yang diukur alat adalah tubuh bendungan
(`displacement_h` dan `displacement_v`), dan nilai per patok adalah
**sebaran** pergeseran itu di sepanjang satu elevasi — porsinya diturunkan
dari kode patoknya sendiri, jadi polanya tetap, tidak berubah setiap
penyegaran. Lalu "arah" yang ditampilkan adalah sudut **pada gambar**
(0° = ke kanan, 90° = ke bawah), bukan azimut hasil survei; panelnya menyebut
itu "Arah gambar".

Sudut penanda maupun hotspot bisa **digeser sendiri**: tombol penanda di
kluster kanan bawah menyalakan mode atur posisi, dan mode itu berlaku di kedua
tampilan — di panorama dasar yang digeser adalah penanda stasiun, di dalam
panorama stasiun yang digeser adalah hotspotnya (garis patok, alat, atau
tautan).

Untuk garis patok ada dua cara geser: seret **namanya** dan seluruh garis
pindah sebagai satu kesatuan (kelima petak ikut, karena semuanya diturunkan
dari titik tengah garis), atau seret **satu patoknya** dan hanya patok itu
yang bergeser. Yang tersimpan untuk patok tunggal adalah **selisihnya** dari
tempat yang dihitung garis, jadi kalau garisnya digeser lagi, koreksi tiap
patok ikut terbawa. Setiap lepasan langsung tersimpan — tapi hanya kalau
pointer benar-benar digeser, sebab satu klik tidak boleh memindahkan data
survei. Rendernya tidak disurvei, jadi
sudut awal hasil seeder memang perkiraan dari gambarnya; mengoreksinya dari
panggung adalah satu-satunya cara yang jujur. Hak akses `stations.move` yang
menentukan siapa boleh.

Panggungnya tidak pernah benar-benar diam: panorama **berputar pelan sendiri**
(0,11 rpm) sampai disentuh, lalu melanjutkan hanyutannya 4 detik setelah tangan
dilepas — tombol putar di kluster kanan bawah mematikannya. Tiap penanda juga
berdenyut halus.

Seluruh tahapannya bergerak satu arah — masuk. Fase menoleh hanya memutar arah
pandang, lalu dorongan majunya terjadi **di dalam** padu-silang (zoom 46 → 56),
jadi tidak ada tarikan mundur di ujung; keluar dari stasiun barulah melebar
kembali. Penandanya ikut memudar dulu sebelum gambar berganti, dan kamera
**tidak** diputar lagi saat berpadu-silang — arah pandang yang sudah dituju dipertahankan,
karena memutar ulang di tengah pudaran itulah yang dulu terlihat seperti
berkedip.

Perpindahannya tanpa layar tunggu, dibuat seperti berjalan ke lokasinya: begitu
penanda diklik kamera **menoleh ke penanda itu** (penandanya membesar), lalu
**mendekat** (zoom 45 → 60), panorama lama **berpadu-silang** ke panorama baru
sambil kamera ikut berputar ke arah barunya, dan sesampainya di sana framing
**mengendur kembali** ke 45. Teksturnya dihangatkan lewat `preloadPanorama()`
selagi kamera menoleh, jadi penukaran tidak menunggu unduhan.

Semuanya satu kurva gerak: menoleh, mendekat, dan berpadu-silang tidak dipisah
jadi animasi-animasi yang saling berhenti. Geseran tangan pun meluncur berhenti
(inersia 0,9) dan tombol zoom bergerak halus, bukan melompat. Panorama tiap stasiun sudah ikut di payload
penanda, jadi rangkaian ini tidak menunggu `GET /api/stations/{kode}` selesai —
data stasiun menyusul untuk panel kanan dan hotspot. Veil "Memuat panorama"
hanya tersisa untuk pemuatan bola pertama kali.

Tekstur pratinjau (2048 px, ±150–300 KB) dimuat lebih dulu sementara berkas HD
(4096 px, ±0,4–1,4 MB) diunduh **berbarengan** lewat `preloadPanorama()`, lalu ditukar
begitu gerakan kameranya selesai — jadi masa buramnya sependek animasi, bukan
sepanjang unduhan. Kunjungan berikutnya memakai cache, praktis langsung tajam.
Tekstur kecil dimuat lebih dulu lalu ditukar dengan versi HD tanpa loader, dan
seluruh kanvas diberi gradasi matahari (`stageFilter`) plus lapisan malam
(`nightWash`) — panoramanya diambil siang hari, jadi senja dan malam dilukis di
atasnya.

### Menempatkan penanda di panorama

Sudut tiap penanda disimpan pada `sphere_yaw` / `sphere_pitch`. Selama masih
kosong, sudutnya diperkirakan dari koordinat peta: arah dihitung sebagai bearing
dari posisi panorama dasar (`map_x` ke timur, `map_y` ke selatan) dan jaraknya
menentukan seberapa jauh di bawah cakrawala penanda menggantung — nilainya bisa
disetel di `dam.stage.sphere`.

Tombol pin di kluster kanan bawah mengaktifkan mode atur posisi: seret penanda
ke titik aslinya, lepas, dan sudutnya tersimpan lewat
`POST /api/stations/{kode}/sphere` (derajat). Selama mode ini aktif, klik
penanda tidak membuka panorama. Endpoint lama
`POST /api/stations/{kode}/position` tetap ada untuk koordinat peta rencana.

---

## Menjalankan

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### Database MySQL

Cara cepat (Windows) — skrip menanyakan kata sandi MySQL, membuat database,
memperbarui `.env`, lalu menjalankan migrasi + seeder:

```powershell
powershell -ExecutionPolicy Bypass -File tools\setup-mysql.ps1
```

Cara manual:

```bash
mysql -u root -p -e "CREATE DATABASE budong_budong_dt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Isi kredensial di `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=budong_budong_dt
DB_USERNAME=root
DB_PASSWORD=<kata-sandi-mysql>
```

Lalu:

```bash
php artisan migrate --seed
```

> Untuk mencoba tanpa MySQL: `DB_CONNECTION=sqlite` dan
> `touch database/database.sqlite` — seluruh migration dan seeder kompatibel.
> Perhatikan: pada koneksi `sqlite`, nilai `DB_DATABASE` dibaca sebagai **nama
> berkas**, bukan nama skema. Jadi `DB_DATABASE=budong_budong_dt` membuat berkas
> `budong_budong_dt` di akar proyek — bukan memakai database MySQL bernama sama.

### Jalankan aplikasi

```bash
npm run build
php artisan serve
```

Buka `http://localhost:8000`. Akun hasil seeder:

| Email | Kata sandi | Peran |
|---|---|---|
| admin@bwssulawesi5.go.id | password | Administrator |
| operator@bwssulawesi5.go.id | password | Operator Ruang Kendali |

Akun lain dibuat lewat menu **Pengguna & Akses** setelah masuk sebagai
administrator.

Untuk pengembangan frontend: `npm run dev` (Vite HMR) berdampingan dengan
`php artisan serve`.

---

## Aset gambar

Aset diproses dari folder `D:\BE Software\Panoramic 360 fix` oleh skrip Python
(butuh `pillow`, `numpy`, `scipy`, dan `torch` untuk super-resolution):

```bash
python tools/build_hd_masters.py        # sekali: master HD (butuh GPU/torch)
python tools/build_map_assets.py        # public/assets/map/map-{night,dawn,day,dusk}.webp
python tools/build_panorama_assets.py   # public/assets/panorama/*.webp + preview/ + thumb/
```

### Master HD (aset yang sudah HD)

`build_hd_masters.py` menjalankan super-resolution sekali lalu menyimpan
hasilnya sebagai aset siap pakai di luar aplikasi:

```
D:\BE Software\Panoramic 360 HD\
    panorama\<kode>.jpg   7096x3548 hasil upscale, JPEG q95
    map\day.jpg           4565x2597, UI bawaan render sudah dihapus + full-bleed
    map\night.jpg
    map\meta.json         ukuran kanvas + inset area foto
```

Berkas asli di `Panoramic 360 fix` **tidak diubah**. Begitu master ada, kedua
skrip build memakainya otomatis dan hanya melakukan resize + encode — rebuild
aset web jadi hitungan detik dan tidak butuh GPU lagi. Master berformat JPEG,
jadi bisa langsung dipakai QGIS/Photoshop atau proyek bendungan lain.

Opsi: `--only=panorama` / `--only=map` untuk sebagian, `--force` untuk menulis
ulang master yang sudah ada.

- `build_map_assets.py` memotong area bendungan dari dua render referensi
  (siang & malam), **menghapus UI yang tercetak** di gambar (chip penanda, pil
  pencarian, kompas, tombol zoom) memakai pencocokan tambalan sekitarnya,
  **melebarkan kanvas menjadi 16:9 full-bleed** dengan pantulan terrain,
  menaikkan resolusinya, lalu membangkitkan varian fajar dan senja dari
  pasangan siang/malam. Keluaran 4565x2597.
- `build_panorama_assets.py` menormalkan tiap panorama ke rasio 2:1
  (equirectangular) dan mengekspor tiga tingkat: HD 4096x2048 q82, `preview/`
  2048x1024 q72, dan
  `thumb/` 560x280.

### Kanvas full-bleed

Area render referensi yang bersih dari UI hanya bagian tengahnya (1032x845) —
di balik panel kanan yang opak tidak ada data gambar. Supaya panggung tetap
terlihat penuh sampai tepi layar, kanvas dilebarkan ke rasio 16:9 dengan
memantulkan (mirror) terrain ke arah luar; margin buatan itu persis seukuran
rail, header, dan panel ringkasan, jadi praktis selalu tertutup kaca.

`dam.map.content` di `config/dam.php` menyimpan posisi fraksional area foto asli
di dalam kanvas. Koordinat penanda (`map_x`/`map_y`) tetap persen terhadap area
foto itu, lalu dipetakan lewat inset tersebut oleh `map-stage.js` — jadi
penanda tetap menempel pada fitur bendungan, sementara gambarnya full-bleed.
Kalau proporsi chrome diubah, sesuaikan `CHROME` di
`tools/build_map_assets.py`, jalankan ulang skripnya, dan salin nilai
`width`/`height`/`content` yang dicetak di akhir proses ke `config/dam.php`.

### Super-resolution (HD)

Sumber panorama hanya 1774x887 — terlihat lembek begitu diperbesar viewer 360.
Karena itu tiap gambar dilewatkan **Real-ESRGAN x4** (`tools/upscale.py`,
arsitektur RRDBNet + bobot resmi) lalu diturunkan ke ukuran kirim, jadi tepian
railing, tangga, dan rumah sensor tetap tajam. Panorama dipadding melingkar
sebelum diproses supaya sambungan 360° tidak putus.

Bobot model (64 MB, sekali unduh):

```bash
curl -L -o tools/models/RealESRGAN_x4plus.pth   https://github.com/xinntao/Real-ESRGAN/releases/download/v0.1.0/RealESRGAN_x4plus.pth
```

Jalan di CUDA bila ada GPU (RTX 4060: ±20 detik per panorama), kalau tidak jatuh
ke CPU. Tanpa bobot atau dengan `--no-upscale`, skrip kembali memakai Lanczos.

Di browser, viewer 360 memuat `preview/` (±40 KB) lebih dulu supaya panorama
langsung tampil, lalu menukarnya ke tekstur HD tanpa loader — gambar hanya
menjadi tajam di tempat.

Hasilnya sudah ikut di repo, jadi skrip hanya perlu dijalankan kalau aset
sumbernya berubah.

---

## Tata letak layar

Seluruh chrome (header, rail kiri, panel kanan, kartu Sistem Monitor) digerakkan
satu set variabel CSS pada `.app-frame` di `resources/css/app.css`:

| Variabel | Nilai |
|---|---|
| `--gap` | `clamp(12px, 0.9vw, 22px)` |
| `--rail-w` | 64 px (ikon saja) di bawah 1440 px, `clamp(172px, 12vw, 214px)` di atasnya |
| `--panel-w` | `clamp(316px, 24vw, 470px)` |
| `--header-h` | `clamp(88px, 4.6vw + 30px, 132px)` |
| `--ui-zoom` | 1 → 1,1 (≥1800 px) → 1,26 (≥2200 px) → 1,5 (≥3000 px) |

Tidak ada lagi offset piksel tetap di view: semuanya turunan dari variabel itu
(`--stage-left`, `--stage-right`, dst). Di layar besar, elemen ber-class
`chrome-scale` ikut membesar lewat `--ui-zoom` supaya teks dan chip tidak
tenggelam di monitor 4K, sementara panggung tetap memakai geometrinya sendiri.

`map-stage.js` **mengukur** rail, header, dan panel lewat atribut
`data-chrome="…"` (plus `ResizeObserver`), jadi lebar panel berapa pun langsung
diikuti. Skala panggung diambil dari dua syarat: render harus menutup layar
penuh, dan area foto di dalamnya diusahakan pas di ruang bersih antara panel —
yang lebih besar dari keduanya yang dipakai.

Panel ringkasan memakai *container query*: kartu Parameter Utama otomatis
menjadi 2 kolom saat panel sempit dan 4 kolom saat lebar, mengikuti lebar panel,
bukan lebar layar.

### Perilaku per ukuran layar

| Lebar | Tata letak |
|---|---|
| ≥ 1440 px | Rail berlabel, panel ringkasan menetap di kanan, panggung full-bleed |
| 1280–1439 px | Rail ikon, panel tetap menetap |
| 1024–1279 px | Panel jadi panel geser (tombol di kanan atas), rail ikon |
| 640–1023 px | Chrome dirapatkan, rail 56 px, tabel bisa digeser mendatar |
| < 640 px | Rail pindah jadi bar bawah, panel geser selebar layar, penanda hanya pin (label disembunyikan), panggung **dipaskan** bukan full-bleed supaya seluruh bendungan terlihat |

Layar potret (mis. tablet 820×1180) juga memakai mode dipaskan; sisa ruangnya
diisi salinan render yang diburamkan, bukan bidang hitam.

Diuji pada 390×844, 820×1180, 1024×768, 1280×800, 1366×768, 1440×900, 1600×900,
1920×1080, 2560×1440, dan ultrawide 3440×1440 — seluruh 16 penanda tetap
terjangkau dan tidak ada geser mendatar di tingkat halaman.

### Dukungan browser

Target: Chrome/Edge, Firefox, dan Safari versi terkini (desktop maupun mobile).
Fitur yang belum merata dipakai dengan penurunan bertahap:

| Fitur | Kalau tidak didukung |
|---|---|
| `zoom` (penskalaan chrome di layar besar) | Firefox < 126: chrome tetap ukuran 1× — semua tetap berfungsi |
| *Container query* (kolom Parameter Utama) | Safari < 16 / Firefox < 110: tetap 2 kolom |
| `backdrop-filter` | Panel memakai latar solid supaya teks tetap terbaca |
| Filter SVG pada lapisan kaca | Safari: kaca tanpa efek kaustik |
| `dvh` | Jatuh ke `vh` |
| `prefers-reduced-motion` | Animasi kaca, denyut penanda, dan transisi panel dimatikan |

---

## Sumber data telemetri

Semua nilai yang tampil mengalir lewat satu kontrak,
`App\Services\Telemetry\TelemetryProvider`, sehingga sumber data bisa ditukar
tanpa mengubah frontend.

**1. Database (bawaan).** Membaca tabel `sensor_readings` — hasil seeder,
perintah simulator, atau kiriman perangkat.

```bash
php artisan telemetry:simulate            # lanjutkan data sampai waktu sekarang
php artisan telemetry:simulate --hours=48 # isi ulang 48 jam ke belakang
php artisan placements:export             # simpan posisi penanda & patok ke seeder
```

Perintah ini terjadwal tiap 5 menit di `routes/console.php`; hapus jadwalnya
begitu perangkat asli sudah mengirim data. Nilai sintetis dibuat oleh
`ReadingSimulator` sebagai fungsi murni dari (parameter, waktu) — termasuk
kejadian hujan yang ikut menaikkan inflow, tekanan air pori, rembesan, dan
kekeruhan.

**2. Tarik dari API logger.**

```dotenv
TELEMETRY_DRIVER=http
TELEMETRY_BASE_URL=https://logger.contoh.go.id/api
TELEMETRY_TOKEN=...
```

Template endpoint dan penyesuaian nama field ada di `config/telemetry.php`.
Kalau upstream gagal, dashboard otomatis jatuh ke data tersimpan.

**3. Perangkat mengirim sendiri (push).**

```bash
curl -X POST http://localhost:8000/api/ingest \
  -H "Content-Type: application/json" \
  -H "X-Ingest-Token: $TELEMETRY_INGEST_TOKEN" \
  -d '{"station":"awlr-hulu","recorded_at":"2026-09-03T09:20:00+08:00",
       "metrics":{"water_level":93.881,"inflow":12.36}}'
```

Endpoint memvalidasi token, menyimpan hanya parameter yang dikenal stasiun, dan
langsung mengevaluasi ambang batas (`AlertEvaluator`) sehingga peringatan
terbentuk otomatis.

Cuaca di header berasal dari stasiun AWR di lapangan
(`StationWeatherProvider`); bisa diganti API luar dengan `WEATHER_DRIVER=http`.

---

## Endpoint JSON untuk browser

Semua di bawah sesi login (`/api/...`):

| Metode | Endpoint | Isi |
|---|---|---|
| GET | `/api/environment` | jam lokasi, fase matahari, aset latar, cuaca |
| GET | `/api/dashboard` | parameter utama, skor kesehatan, riwayat, peringatan, status sistem |
| GET | `/api/stations` | penanda pada panggung digital twin (koordinat peta, sudut panorama, status) |
| GET | `/api/stations/{kode}` | detail stasiun + metrik + seri waktu + hotspot |
| GET | `/api/stations/{kode}/series/{parameter}?range=24h\|7d\|30d` | seri satu parameter |
| GET | `/api/environment/curve?step=10` | kurva pencahayaan sehari untuk kontrol waktu |
| POST | `/api/stations/{kode}/position` | simpan koordinat peta penanda hasil geser |
| POST | `/api/stations/{kode}/sphere` | simpan sudut penanda di dalam panorama dasar |
| POST | `/api/hotspots/{id}/position` | simpan sudut hotspot di dalam panorama stasiun |
| GET/POST | `/api/alerts`, `/api/alerts/{id}/acknowledge\|resolve` | peringatan |
| GET/POST | `/api/maintenance`, `/api/maintenance/{id}/status` | perawatan |
| GET/POST | `/api/reports`, `/api/reports/{id}/download` | laporan |
| GET/POST | `/api/settings` | preferensi tampilan & ambang batas |

Endpoint yang menulis dijaga hak akses: `stations.move` untuk ketiga endpoint
posisi penanda dan hotspot, `alerts.handle` untuk peringatan, `maintenance.*` untuk desk
perawatan, `reports.create` untuk laporan, dan `thresholds.edit` untuk
`POST /api/settings`. Peran tanpa hak itu menerima 403.

Interval polling browser diatur di `config/dam.php` (`refresh`).

---

## Struktur data

- `roles` — peran: slug, nama, sisi perawatan, dan daftar hak aksesnya.
  `users.role` menyimpan slug-nya.
- `dams` — identitas dan elevasi acuan bendungan.
- `sensor_stations` — 17 stasiun; `map_x`/`map_y` adalah posisi dalam persen
  terhadap render bendungan (bukan lat/lon), `panorama` menunjuk berkas `.webp`.
- `sensor_metrics` — definisi parameter per stasiun: satuan, desimal, rentang
  normal, ambang waspada/siaga/bahaya.
- `sensor_readings` — nilai time-series (`station`, `metric_key`, `value`,
  `recorded_at`).
- `panorama_hotspots` — titik sorot di panorama: `yaw`/`pitch` derajat, tipe
  `metric` (menampilkan bacaan), `info`, `plot` (petak patok geser), atau
  `link` (pindah panorama). Tipe `plot` memakai `meta` untuk sisi (hulu/hilir),
  kode baris, jumlah patok, jarak antar patok, ukuran satu petak, arah garis
  dan perspektifnya, serta arah lereng pada gambar — posisi tiap petak, patok,
  angka pergeseran dan panah arahnya diturunkan dari situ, bukan disimpan
  satu-satu. Satu-satunya yang disimpan per patok adalah selisih hasil geseran
  manual (`meta.places`). Sudutnya bisa digeser dari
  panggung; seeder hanya menulis sudut saat baris dibuat, jadi penempatan hasil
  geser tidak hilang saat `db:seed` dijalankan lagi.
- `alerts`, `maintenance_tasks`, `reports`, `settings`, `users`.

Ambang batas menentukan status (`normal`, `waspada`, `siaga`, `bahaya`) yang
dipakai untuk warna penanda pada panggung, donat "Kesehatan Struktur", dan
pembangkitan peringatan.

---

## Struktur kode

```
app/
  Http/Controllers/         PageController + Api\* (JSON)
  Models/                   Dam, SensorStation, SensorMetric, SensorReading, ...
  Services/
    MonitoringService.php   perakit seluruh payload dashboard
    AlertEvaluator.php      ambang batas → tabel alerts
    ReportBuilder.php       PDF/CSV
    Telemetry/              kontrak + driver database/http + simulator
    Weather/                stasiun AWR atau API luar
  Support/SolarClock.php    posisi matahari & pemilihan scene
resources/
  css/app.css               token desain + sistem liquid glass
  js/
    app.js                  store Alpine (site, viewer) + komponen
    components/             map-stage (panggung digital twin), panorama
    lib/                    api, charts (ECharts), format, icons
  views/
    layouts/app.blade.php   rangka: topbar, rail kiri, panel kanan
    pages/                  satu berkas per halaman
    partials/right/         panel ringkasan & panel stasiun
    partials/viewer/        chrome viewer 360°
tools/                      pipeline aset Python
tests/Feature/              API monitoring, ingest, autentikasi
```

### Catatan implementasi

- **Liquid glass** (`.glass` dan variannya) menumpuk empat efek:
  `backdrop-filter` (refraksi peta di belakang), kilau spekular yang mengikuti
  kursor (`x-sheen`), kaustik dari filter SVG `feTurbulence`, serta bayangan
  inset untuk dispersi tepi.
- **Penanda stasiun** dirender di luar lapisan yang di-transform, jadi ukurannya
  tetap saat zoom; posisinya dihitung dari persen `map_x`/`map_y`. Peta
  dipasang di area bersih antara rail dan panel ("safe rect") supaya semua
  penanda selalu terjangkau, sementara render aslinya tetap memenuhi layar
  dalam bentuk blur di belakang panel.
- **Photo Sphere Viewer disimpan di luar state Alpine** (objek closure), karena
  Proxy Alpine bentrok dengan properti matriks three.js (dipakai internal oleh
  viewer) yang bersifat read-only.
- Pustaka berat (Photo Sphere Viewer/three.js, ECharts) dimuat lewat dynamic
  import; bundel awal ±80 kB.

---

## Kecepatan

Halaman dirender di server, jadi yang terasa sebagai "loading" saat berpindah
menu adalah waktu server menyiapkan halaman berikutnya. Yang sudah dikerjakan di
sisi aplikasi:

- **Halaman berikutnya disiapkan saat kursor mendekat.** Layout memasang aturan
  `speculationrules`, sehingga Chromium sudah membangun halaman tujuan sebelum
  tautannya diklik. Panggung 360 sengaja dikecualikan. Peramban lain
  mengabaikannya tanpa efek samping.
- **Bilah kemajuan tipis** muncul begitu tautan diklik, jadi klik tidak pernah
  terasa hilang; polling data ikut dihentikan agar permintaan halaman tidak
  mengantre di belakangnya.
- **Berkas grafik dipangkas** dari 953 kB menjadi 227 kB (gzip 318 kB → 79 kB)
  dengan menamai komponen ECharts yang benar-benar dipakai.
- **Penanda tidak diminta ulang** tepat setelah halaman dimuat — datanya sudah
  ikut di dalam halaman — dan permintaan dashboard/stasiun kini berjalan
  bersamaan, bukan berurutan.

Sisanya ada di konfigurasi server, dan dampaknya paling besar:

| Langkah | Kenapa |
|---|---|
| Aktifkan **OPcache** (`zend_extension=opcache`, `opcache.enable=1` di `php.ini`) | Tanpa itu setiap permintaan mengompilasi ulang seluruh berkas PHP. Di mesin pengembangan ini ekstensinya belum terpasang sama sekali. |
| Jangan pakai `php artisan serve` untuk penggunaan sungguhan | Server bawaan melayani **satu permintaan pada satu waktu**: polling data menahan permintaan halaman. Pakai Nginx/Apache + PHP-FPM, FrankenPHP, atau Laravel Octane. |
| `php artisan optimize` saat rilis | Menyimpan cache config, route, dan view. (`php artisan optimize:clear` untuk mengembalikannya saat mengembangkan.) |
| `composer install --no-dev --optimize-autoloader` | Autoloader kelas yang sudah dipetakan. |

## Pengujian

```bash
php artisan test
```

Mencakup payload `/api/environment`, `/api/dashboard`, `/api/stations`,
alur ingest perangkat (termasuk peringatan terbentuk dan selesai otomatis), dan
autentikasi.
