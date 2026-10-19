<?php

namespace App\Services;

use App\Models\User;
use App\Support\Passwords;
use Illuminate\Support\Str;

/** Mencocokkan profil dari Pusat (userinfo) dengan akun lokal Absensi. */
class PusatAkun
{
    /**
     * @param  array  $info  objek "user" dari Pusat (sub, username, nama, peran[] ...)
     * @return array{0:?User,1:?string}  [akun, pesan galat]
     */
    public static function masuk(array $info): array
    {
        $uuid = (string) ($info['sub'] ?? '');
        $username = strtolower(trim((string) ($info['username'] ?? '')));
        if ($uuid === '' || $username === '') {
            return [null, 'Data akun dari Pusat tidak lengkap.'];
        }
        $peran = self::petakanPeran((array) ($info['peran'] ?? []));
        if (! $peran) {
            return [null, 'Akun Anda belum diberi peran yang dikenal Absensi. Hubungi administrator.'];
        }

        $user = User::where('pusat_uuid', $uuid)->first();
        if (! $user) {
            $kandidat = User::whereRaw('LOWER(username) = ?', [$username])->first();
            if ($kandidat && $kandidat->pusat_uuid !== null) {
                return [null, 'Username sudah tertaut ke akun Pusat lain. Hubungi administrator.'];
            }
            $user = $kandidat; // penautan pertama lewat username yang sama
        }

        if ($user && ! $user->is_active) {
            // Dinonaktifkan otomatis oleh Pusat lalu aksesnya dipulihkan di Pusat (login ini membuktikannya): aktifkan lagi.
            if ($user->pusat_uuid !== null && PusatKlien::baca('dc:'.$user->pusat_uuid) !== null) {
                $user->update(['is_active' => true]);
                \Illuminate\Support\Facades\DB::table('pusat_state')->where('kunci', 'dc:'.$user->pusat_uuid)->delete();
                Audit::log('Akses Dipulihkan Pusat', 'Akun Guru', $user->nama, 'Akun diaktifkan kembali karena akses dipulihkan di Pusat');
            } else {
                return [null, 'Akun Anda dinonaktifkan di Absensi. Hubungi administrator.'];
            }
        }

        if (! $user) {
            $user = User::create([
                'username' => $username, 'nama' => (string) ($info['nama'] ?? $username), 'roles' => $peran, 'subjects' => [], 'classes' => [],
                'password_hash' => Passwords::make(Str::random(48)), // tak diketahui siapa pun: hanya login lewat Pusat
                'is_active' => true, 'pusat_uuid' => $uuid,
            ]);
            Audit::log('Akun dari Pusat', 'Akun Guru', $user->nama, "Akun dibuat otomatis saat login pertama lewat Pusat, peran: ".implode(', ', $peran));

            return [$user, null];
        }

        $roles = $peran;
        if (in_array('superadmin', $user->roles ?? [], true)) {
            $roles[] = 'superadmin'; // superadmin hanya diatur di Absensi
            $roles = array_values(array_unique($roles));
        }
        $dulu = collect($user->roles ?? [])->sort()->values()->all();
        $baru = collect($roles)->sort()->values()->all();
        $perubahan = ['pusat_uuid' => $uuid];
        if ($dulu !== $baru) {
            $perubahan['roles'] = $roles;
        }
        if (! empty($info['nama']) && $user->nama !== $info['nama']) {
            $perubahan['nama'] = (string) $info['nama'];
        }
        $user->update($perubahan);
        if (isset($perubahan['roles'])) {
            Audit::log('Peran dari Pusat', 'Akun Guru', $user->nama, 'Peran diselaraskan dengan Pusat: '.implode(', ', $dulu).' → '.implode(', ', $baru));
        }

        return [$user, null];
    }

    /** @return string[] peran lokal (unik); peran Pusat yang tidak dikenal diabaikan */
    public static function petakanPeran(array $peranPusat): array
    {
        $peta = (array) config('pusat.peran_peta');
        $hasil = [];
        foreach ($peranPusat as $p) {
            if (isset($peta[$p])) {
                $hasil[] = $peta[$p];
            }
        }

        return array_values(array_unique($hasil));
    }

    /** Webhook akun: hanya arah AMAN yang diterapkan otomatis (mencabut akses). Perubahan lain menunggu login/sinkron berikutnya. */
    public static function cabutBilaPerlu(array $data): bool
    {
        $uuid = (string) ($data['uuid'] ?? '');
        if ($uuid === '') {
            return false;
        }
        $user = User::where('pusat_uuid', $uuid)->first();
        if (! $user || ! $user->is_active) {
            return false;
        }
        $dicabut = (array_key_exists('aktif', $data) && $data['aktif'] === false)
            || (array_key_exists('peran', $data) && self::petakanPeran((array) $data['peran']) === []);
        if (! $dicabut) {
            return false;
        }
        $user->update(['is_active' => false]); // sesi aktif keluar pada request berikutnya (EnsureActiveUser)
        PusatKlien::simpan('dc:'.$uuid, 1); // penanda: boleh dipulihkan otomatis bila Pusat memberi akses lagi
        Audit::log('Akses Dicabut Pusat', 'Akun Guru', $user->nama, 'Akun dinonaktifkan karena akses dicabut di Pusat');

        return true;
    }
}
