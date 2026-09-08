<?php

/*
| Who may do what.
|
| The catalogue of abilities is part of the application, not of the database:
| a role only stores the codes it was granted. Adding an ability means adding
| one line here and checking it with `can()` / `@can`.
|
| Roles themselves are rows (`roles` table) so the administrator can rename
| them, change which side of the maintenance desk they sit on, and tick the
| abilities they carry without a deploy.
*/

return [

    /*
    | The two sides of the maintenance desk. A role sits on exactly one of
    | them; the pair is what decides who a message is addressed to and which
    | unread badge it counts against.
    */
    'sides' => [
        'operator' => 'Operator ruang kendali',
        'cs' => 'Layanan teknis',
    ],

    /*
    | Abilities, grouped the way they are presented on the access screen.
    */
    'groups' => [

        'Perawatan' => [
            'maintenance.view' => [
                'label' => 'Buka menu perawatan',
                'hint' => 'Melihat tiket, papan tugas, dan riwayat perawatan.',
            ],
            'maintenance.request' => [
                'label' => 'Ajukan permintaan perawatan',
                'hint' => 'Membuka pekerjaan baru dan memulai percakapan.',
            ],
            'maintenance.reply' => [
                'label' => 'Balas pesan tiket',
                'hint' => 'Menulis di percakapan tiket sebagai sisi perannya.',
            ],
            'maintenance.status' => [
                'label' => 'Ubah status pekerjaan',
                'hint' => 'Memindahkan kartu di papan tugas sampai selesai.',
            ],
        ],

        'Instrumentasi' => [
            'stations.move' => [
                'label' => 'Geser penanda stasiun',
                'hint' => 'Menyimpan posisi penanda di peta dan panorama 360.',
            ],
            'dashboard.arrange' => [
                'label' => 'Atur tata letak dashboard',
                'hint' => 'Mengurutkan, melebarkan dan menyembunyikan kartu untuk semua orang.',
            ],
            'gates.control' => [
                'label' => 'Atur bukaan pintu spillway',
                'hint' => 'Memberi perintah bukaan pada tiap daun pintu.',
            ],
            'thresholds.edit' => [
                'label' => 'Ubah ambang batas',
                'hint' => 'Menyetel batas waspada, siaga, dan bahaya.',
            ],
        ],

        'Peringatan' => [
            'alerts.handle' => [
                'label' => 'Tangani peringatan',
                'hint' => 'Mengakui dan menyelesaikan peringatan aktif.',
            ],
        ],

        'Laporan' => [
            'reports.create' => [
                'label' => 'Buat laporan',
                'hint' => 'Membangkitkan laporan harian, mingguan, dan bulanan.',
            ],
        ],

        'Administrasi' => [
            'users.manage' => [
                'label' => 'Kelola pengguna',
                'hint' => 'Menambah, menyunting, dan menonaktifkan akun.',
            ],
            'roles.manage' => [
                'label' => 'Kelola peran & hak akses',
                'hint' => 'Membuat peran dan menentukan hak aksesnya.',
            ],
        ],
    ],
];
