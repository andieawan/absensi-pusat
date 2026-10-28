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
        $mapelPusat = \App\Models\Subject::dariPusat();
        if ($apa === 'Mapel' && ! $mapelPusat) {
            return $next($request); // daftar mapel baru dikunci setelah diambil dari Pusat
        }
        $kunci = $mapelPusat ? ['kelas', 'guru', 'siswa', 'mapel'] : ['kelas', 'guru', 'siswa'];
        if ($request->route('type') !== null && ! in_array($request->route('type'), $kunci, true)) {
            return $next($request); // impor pasangan/absensi/nilai tetap di Absensi
        }
        if ($apa === 'Mapel') {
            $apa = 'Mata_pelajaran';
        }

        return back()->with('error', str_replace('_', ' ', $apa).' dikelola di Pusat Sekolah. Ubah di Pusat; perubahan tersalin otomatis ke Absensi.');
    }
}
