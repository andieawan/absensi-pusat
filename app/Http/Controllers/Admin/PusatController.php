<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\PusatKlien;
use App\Services\PusatSync;
use Illuminate\Http\Request;

/** Admin → Integrasi Pusat: uji koneksi, periksa perubahan (simulasi), terapkan sinkron data induk. */
class PusatController extends Controller
{
    public function index()
    {
        $siap = PusatKlien::siap();

        return view('admin.pusat', [
            'aktif' => PusatKlien::aktif(),
            'siap' => $siap,
            'url' => config('pusat.url'),
            'callback' => route('sso.callback'),
            'webhook' => url('/api/pusat/webhook'),
            'terakhir' => $siap ? PusatKlien::baca('sinkron_terakhir') : null,
            'ringkasan' => $siap ? json_decode((string) PusatKlien::baca('sinkron_ringkasan'), true) : null,
            'menunggu' => $siap ? (int) PusatKlien::baca('perubahan_menunggu', 0) : 0,
            'otomatis' => (bool) config('pusat.sinkron_otomatis'),
        ]);
    }

    public function uji()
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            return back()->with('error', 'Integrasi Pusat belum aktif atau database belum diperbarui.');
        }
        try {
            $res = PusatKlien::http()->get('/api/v1/ping');
        } catch (\Throwable $e) {
            return back()->with('error', 'Pusat tidak dapat dihubungi: '.mb_substr($e->getMessage(), 0, 200));
        }
        if ($res->status() === 401) {
            return back()->with('error', 'Pusat menolak kredensial (client_id/secret salah atau aplikasi nonaktif).');
        }

        return $res->ok()
            ? back()->with('success', 'Terhubung ke Pusat. Kredensial benar.')
            : back()->with('error', 'Pusat membalas HTTP '.$res->status().'.');
    }

    public function periksa()
    {
        return $this->jalankan(false, false);
    }

    public function terapkan(Request $request)
    {
        return $this->jalankan(true, $request->boolean('paksa'));
    }

    private function jalankan(bool $terapkan, bool $paksa)
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            return back()->with('error', 'Integrasi Pusat belum aktif atau database belum diperbarui.');
        }
        try {
            $hasil = app(PusatSync::class)->jalankan($terapkan, $paksa);
        } catch (\Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                report($e);
            }

            return back()->with('error', 'Sinkron gagal, tidak ada data yang berubah: '.mb_substr($e->getMessage(), 0, 300));
        }
        if ($terapkan) {
            Audit::log('Terapkan Sinkron Pusat', 'Integrasi Pusat', $this->me()->nama, 'Dijalankan manual dari Admin');
        }

        return back()->with('success', $terapkan ? 'Sinkron selesai dan diterapkan.' : 'Simulasi selesai — belum ada data yang diubah.')->with('pusat_hasil', $hasil);
    }
}
