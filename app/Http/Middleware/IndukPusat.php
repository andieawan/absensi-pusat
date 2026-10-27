<?php

namespace App\Http\Middleware;

use App\Services\PusatKlien;
use Closure;
use Illuminate\Http\Request;

/**
 * Data induk (jurusan, kelas, akun baru, kenaikan kelas, impor kelas/guru/siswa) dikelola di Pusat bila integrasi aktif:
 * perubahan lokal ditolak agar tidak bentrok/tertimpa sinkron berikutnya. Parameter = nama data untuk pesan.
 */
class IndukPusat
{
    public function handle(Request $request, Closure $next, string $apa = 'Data ini')
    {
        if (! PusatKlien::kelolaSiswa()) {
            return $next($request);
        }
        if ($request->route('type') !== null && ! in_array($request->route('type'), ['kelas', 'guru', 'siswa'], true)) {
            return $next($request); // impor mapel/pasangan/absensi/nilai tetap di Absensi
        }

        return back()->with('error', str_replace('_', ' ', $apa).' dikelola di Pusat Sekolah. Ubah di Pusat; perubahan tersalin otomatis ke Absensi.');
    }
}
