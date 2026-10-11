<?php

// Penyambungan ke aplikasi Pusat Sekolah (login tunggal + data induk). Mati bila PUSAT_ENABLED=false.
return [
    'enabled' => (bool) env('PUSAT_ENABLED', false),
    'url' => rtrim((string) env('PUSAT_URL', ''), '/'),
    'client_id' => env('PUSAT_CLIENT_ID', ''),
    'client_secret' => env('PUSAT_CLIENT_SECRET', ''),
    'webhook_secret' => env('PUSAT_WEBHOOK_SECRET', ''),
    'timeout' => (int) env('PUSAT_TIMEOUT', 15),

    // Sinkron data induk: false = hanya lewat tombol Admin → Integrasi Pusat; true = otomatis tiap jam.
    'sinkron_otomatis' => (bool) env('PUSAT_SINKRON_OTOMATIS', false),

    // Peran di Pusat -> peran lokal Absensi. Peran lain di Pusat (mis. wali_kelas) diabaikan:
    // penugasan wali kelas & pasangan mapel tetap diatur di Absensi.
    'peran_peta' => [
        'guru' => 'guru',
        'bk' => 'bk',
        'tu' => 'tu',
        'kepsek' => 'kepsek',
        'admin' => 'admin',
    ],
];
