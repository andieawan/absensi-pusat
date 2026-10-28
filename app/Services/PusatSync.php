<?php

namespace App\Services;

use App\Models\Jurusan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menarik data induk (jurusan, kelas, siswa) dari Pusat ke Absensi.
 *
 * Simulasi dan penerapan memakai logika yang SAMA: semua perubahan dijalankan di dalam transaksi
 * lalu dibatalkan bila hanya simulasi. Tidak ada yang dihapus: siswa yang hilang/keluar di Pusat
 * diberi status "keluar" (riwayat absensi tetap utuh). Kelas yang hilang hanya dilaporkan.
 */
class PusatSync
{
    private const KELUAR = ['keluar', 'berhenti', 'nonaktif'];

    private array $hasil;

    private ?bool $adaWali = null;

    /** @return array hasil ringkas untuk ditampilkan/dicetak */
    public function jalankan(bool $terapkan, bool $paksa = false): array
    {
        Sambungan::tarikAman(); // sambungan antar-aplikasi ikut diperbarui setiap sinkron
        ProfilPusat::tarikAman(); // nama sekolah, kepala sekolah, guru BK
        $jurusan = PusatKlien::semua('jurusan');
        $tahun = PusatKlien::semua('tahun-ajaran');
        $kelas = PusatKlien::semua('kelas');
        $siswa = PusatKlien::semua('siswa');
        try {
            $mapel = PusatKlien::semua('mapel');
        } catch (RuntimeException $e) {
            $mapel = null; // izin "mapel" belum dicentang di Pusat → daftar mapel tetap dikelola di Absensi
        }
        try {
            $pengampu = $mapel === null ? null : PusatKlien::semua('pengampu');
        } catch (RuntimeException $e) {
            $pengampu = null; // izin "pengampu" belum dicentang → Pasangan Mapel tetap diatur di Absensi
        }

        $aktif = collect($tahun)->firstWhere('aktif', true);
        if (! $aktif) {
            throw new RuntimeException('Pusat belum punya tahun ajaran aktif. Atur dulu di Pusat.');
        }
        $tertaut = Student::whereNotNull('pusat_uuid')->count();
        if ($tertaut > 0 && count($siswa) === 0) {
            throw new RuntimeException('Pusat mengembalikan 0 siswa padahal Absensi sudah punya '.$tertaut.' siswa tertaut. Dibatalkan demi keamanan.');
        }

        $this->hasil = [
            'jurusan' => ['baru' => 0],
            'kelas' => ['baru' => 0, 'ubah' => 0, 'sama' => 0, 'ambigu' => [], 'hilang' => []],
            'siswa' => ['baru' => 0, 'ubah' => 0, 'sama' => 0, 'ambigu' => [], 'nonaktif' => 0, 'kelas_tak_dikenal' => 0, 'hanya_lokal' => 0],
            'mapel' => ['baru' => 0, 'ubah' => 0, 'tertaut' => 0, 'nonaktif' => 0, 'hanya_lokal' => []],
            'pengampu' => null,
            'contoh' => [],
            'peringatan' => [],
            'diterapkan' => false,
        ];

        DB::beginTransaction();
        try {
            $this->jurusan($jurusan);
            $petaKelas = $this->kelas($kelas, $aktif['nama']);
            $this->siswa($siswa, $petaKelas, $tertaut, $paksa, $terapkan);
            if ($mapel === null) {
                $this->hasil['peringatan'][] = 'Mata pelajaran belum diambil dari Pusat: centang izin "Mata pelajaran" untuk Absensi di Pusat → Aplikasi.';
            } elseif ($mapel !== []) {
                $this->mapel($mapel);
            }
            if ($terapkan) {
                PusatAkun::terapkanWali();
            }
            if ($pengampu !== null && $pengampu !== [] && PusatPengampu::siap()) {
                $this->pengampu($pengampu);
            } elseif ($mapel !== null && $pengampu === null) {
                $this->hasil['peringatan'][] = 'Pasangan mapel belum diambil dari Pusat: centang izin "Pengampu mapel" untuk Absensi di Pusat → Aplikasi.';
            }
            if ($terapkan) {
                $set = \App\Models\SchoolSetting::current();
                if ($set->exists && $set->tahun_ajaran !== $aktif['nama']) {
                    \App\Models\SchoolSetting::put(['tahun_ajaran' => $aktif['nama']]); // tahun ajaran mengikuti Pusat
                    $this->catat('Tahun ajaran aktif: '.$aktif['nama']);
                }
                if (self::terapkanSemester($aktif)) {
                    $this->catat('Semester: '.$aktif['semester']);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($terapkan) {
            DB::commit();
            $this->hasil['diterapkan'] = true;
            PusatKlien::simpan('sinkron_terakhir', now('UTC')->format('Y-m-d H:i:s'));
            PusatKlien::simpan('tahun_aktif', $aktif['nama']);
            PusatKlien::simpan('sinkron_ringkasan', array_diff_key($this->hasil, ['contoh' => 1]));
            PusatKlien::simpan('perubahan_menunggu', 0);
            if (! empty($mapel)) {
                PusatKlien::simpan('mapel_pusat', 1);
            }
            if (! empty($pengampu) && PusatPengampu::siap()) {
                PusatKlien::simpan('pengampu_pusat', 1);
                PusatPengampu::simpanData($pengampu);
            }
            Audit::log('Sinkron Pusat', 'Integrasi Pusat', null, sprintf(
                'kelas +%d ~%d, siswa +%d ~%d, dinonaktifkan %d',
                $this->hasil['kelas']['baru'], $this->hasil['kelas']['ubah'], $this->hasil['siswa']['baru'], $this->hasil['siswa']['ubah'], $this->hasil['siswa']['nonaktif'],
            ));
        } else {
            DB::rollBack();
        }

        return $this->hasil;
    }

    /** Semester berjalan mengikuti tahun ajaran aktif di Pusat. @return bool berubah */
    public static function terapkanSemester(array $ta): bool
    {
        $sem = (string) ($ta['semester'] ?? '');
        if (empty($ta['aktif']) || ! in_array($sem, ['Ganjil', 'Genap'], true)) {
            return false;
        }
        PusatKlien::simpan('semester', $sem);
        $set = \App\Models\SchoolSetting::current();
        if (! $set->exists || $set->semester === $sem) {
            return false;
        }
        \App\Models\SchoolSetting::put(['semester' => $sem]);
        Audit::log('Semester dari Pusat', 'Integrasi Pusat', null, 'Semester berjalan: '.$sem);

        return true;
    }

    private function catat(string $teks): void
    {
        if (count($this->hasil['contoh']) < 25) {
            $this->hasil['contoh'][] = $teks;
        }
    }

    private function jurusan(array $baris): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('jurusan')) {
            return;
        }
        $ada = Jurusan::all()->keyBy(fn ($j) => mb_strtoupper($j->kode));
        foreach ($baris as $j) {
            if (! empty($j['dihapus']) || $ada->has(mb_strtoupper((string) $j['kode']))) {
                continue;
            }
            Jurusan::create(['kode' => $j['kode'], 'nama' => $j['nama']]);
            $this->hasil['jurusan']['baru']++;
            $this->catat("Jurusan baru: {$j['kode']}");
        }
    }

    /**
     * Mapel: cocokkan lewat UUID, lalu nama (huruf besar/kecil diabaikan) agar ID lokal & riwayat presensi/nilai tetap.
     * Mapel tertaut yang hilang/nonaktif di Pusat hanya dinonaktifkan, tidak pernah dihapus.
     */
    private function mapel(array $baris): void
    {
        if (! \App\Models\Subject::adaKolomAktif()) {
            return;
        }
        $lokal = \App\Models\Subject::all();
        $perUuid = $lokal->whereNotNull('pusat_uuid')->keyBy('pusat_uuid');
        $perNama = $lokal->whereNull('pusat_uuid')->groupBy(fn ($s) => mb_strtolower(trim($s->name)));
        $terlihat = [];
        foreach ($baris as $m) {
            if (! empty($m['dihapus']) || empty($m['uuid'])) {
                continue;
            }
            $terlihat[] = $m['uuid'];
            $data = ['name' => $m['nama'], 'kode' => $m['kode'] ?? null, 'kelompok' => $m['kelompok'] ?? null, 'aktif' => (bool) ($m['aktif'] ?? true)];
            $s = $perUuid->get($m['uuid']);
            if (! $s && ($calon = $perNama->get(mb_strtolower(trim($m['nama'])))) && $calon->count() === 1) {
                $s = $calon->first();
                $perNama->forget(mb_strtolower(trim($m['nama'])));
                $s->pusat_uuid = $m['uuid'];
                $this->hasil['mapel']['tertaut']++;
            }
            if (! $s) {
                \App\Models\Subject::create($data + ['pusat_uuid' => $m['uuid']]);
                $this->hasil['mapel']['baru']++;
                $this->catat("Mapel baru: {$m['nama']}");
                continue;
            }
            $s->fill($data);
            if ($s->isDirty()) {
                $this->hasil['mapel']['ubah'] += $s->isDirty('pusat_uuid') ? 0 : 1;
                $s->save();
            }
        }
        foreach ($perUuid as $uuid => $s) {
            if (! in_array($uuid, $terlihat, true) && $s->aktif) {
                $s->update(['aktif' => false]);
                $this->hasil['mapel']['nonaktif']++;
                $this->catat("Mapel dinonaktifkan: {$s->name}");
            }
        }
        $bk = (int) config('absensi.bk_subject_id');
        $sisa = $perNama->flatten()->filter(fn ($s) => (int) $s->id !== $bk && $s->aktif)->pluck('name')->all();
        $this->hasil['mapel']['hanya_lokal'] = $sisa;
        if ($sisa) {
            $this->hasil['peringatan'][] = 'Mapel di Absensi yang belum ada di Pusat (tetap dipakai, tambahkan di Pusat agar tertaut): '.implode(', ', array_slice($sisa, 0, 15)).'.';
        }
    }

    private function pengampu(array $baris): void
    {
        $h = PusatPengampu::terapkan($baris);
        $this->hasil['pengampu'] = $h;
        if ($h['baru'] || $h['dihapus']) {
            $this->catat("Pasangan mapel dari Pusat: +{$h['baru']} −{$h['dihapus']}");
        }
        foreach (array_filter([
            $h['tertunda'] ? $h['tertunda'].' pengampu menunggu gurunya masuk Absensi lewat Pusat (dipasang otomatis saat login pertama).' : null,
            $h['tanpa_mapel'] ? $h['tanpa_mapel'].' pengampu memakai mapel yang belum ada di Absensi.' : null,
            $h['hanya_lokal'] ? $h['hanya_lokal'].' pasangan mapel hanya ada di Absensi (tidak diubah; tambahkan di Pusat agar tertaut, lalu hapus yang lokal).' : null,
        ]) as $w) {
            $this->hasil['peringatan'][] = $w;
        }
    }

    /** @return array<string,int> uuid kelas Pusat => id kelas lokal */
    private function kelas(array $baris, string $tahunAktif): array
    {
        $lokal = SchoolClass::all();
        $perUuid = $lokal->whereNotNull('pusat_uuid')->keyBy('pusat_uuid');
        $belum = $lokal->whereNull('pusat_uuid')->groupBy(fn ($c) => mb_strtolower(trim($c->name)));
        $peta = [];

        foreach ($baris as $k) {
            $c = $perUuid->get($k['uuid']);
            // Hanya kelas tahun ajaran aktif (atau yang sudah tertaut) yang dibawa ke Absensi.
            if (! $c && ($k['tahun_ajaran'] ?? null) !== $tahunAktif) {
                continue;
            }
            $tautBaru = false;
            if (! $c) {
                $kandidat = $belum->get(mb_strtolower(trim($k['nama'])), collect());
                if ($kandidat->count() > 1) {
                    $this->hasil['kelas']['ambigu'][] = $k['nama'];

                    continue;
                }
                if ($kandidat->count() === 1) {
                    $c = $kandidat->first();
                    $tautBaru = true;
                }
            }

            $data = ['name' => $k['nama'], 'jurusan' => $k['jurusan_kode'] ?? null, 'tahun_ajaran' => $k['tahun_ajaran'] ?? null];
            if ($this->adaWali ??= \Illuminate\Support\Facades\Schema::hasColumn('classes', 'wali_pusat_uuid')) {
                $data['wali_pusat_uuid'] = $k['wali_uuid'] ?? null;
            }
            if (! $c) {
                $c = SchoolClass::create($data + ['pusat_uuid' => $k['uuid']]);
                $this->hasil['kelas']['baru']++;
                $this->catat("Kelas baru: {$k['nama']}");
            } elseif ($tautBaru || $this->beda($c, $data)) {
                $c->update($data + ['pusat_uuid' => $k['uuid']]);
                $this->hasil['kelas']['ubah']++;
                $this->catat(($tautBaru ? 'Kelas ditautkan: ' : 'Kelas diperbarui: ').$k['nama']);
            } else {
                $this->hasil['kelas']['sama']++;
            }
            $peta[$k['uuid']] = $c->id;
        }

        $uuidPusat = array_column($baris, 'uuid');
        foreach ($perUuid as $uuid => $c) {
            if (! in_array($uuid, $uuidPusat, true)) {
                $this->hasil['kelas']['hilang'][] = $c->name;
            }
        }

        return $peta;
    }

    private function siswa(array $baris, array $petaKelas, int $tertautAwal, bool $paksa, bool $terapkan): void
    {
        $lokal = Student::all();
        $perUuid = $lokal->whereNotNull('pusat_uuid')->keyBy('pusat_uuid');
        $belum = $lokal->whereNull('pusat_uuid')->groupBy(fn ($s) => trim((string) $s->nis));
        $adaDiPusat = [];
        $nonaktif = 0;

        foreach ($baris as $p) {
            $adaDiPusat[$p['uuid']] = true;
            $s = $perUuid->get($p['uuid']);
            $tautBaru = false;
            if (! $s) {
                $kandidat = $belum->get(trim((string) $p['nis']), collect());
                if ($kandidat->count() > 1) {
                    $this->hasil['siswa']['ambigu'][] = $p['nis'];

                    continue;
                }
                if ($kandidat->count() === 1) {
                    $s = $kandidat->first();
                    $tautBaru = true;
                }
            }

            $data = ['nis' => $p['nis'], 'nama' => $p['nama'], 'jk' => $p['jenis_kelamin'] ?: ($s?->jk ?? 'L'), 'status' => $p['status']];
            if (($p['kelas_uuid'] ?? null) !== null) {
                if (isset($petaKelas[$p['kelas_uuid']])) {
                    $data['class_id'] = $petaKelas[$p['kelas_uuid']];
                } else {
                    $this->hasil['siswa']['kelas_tak_dikenal']++;
                }
            }
            // Kontak orang tua: Pusat hanya menimpa bila ada isinya (jangan menghapus data Absensi yang belum diisi di Pusat).
            if (! empty($p['nama_ortu'])) {
                $data['nama_ortu'] = $p['nama_ortu'];
            }
            if (($tel = WhatsApp::normalize($p['no_hp_ortu'] ?? null)) !== null) {
                $data['telp_ortu'] = $tel;
            }

            if (! \App\Support\DbUpdate::parentContactReady()) {
                unset($data['nama_ortu'], $data['telp_ortu']); // kolom kontak orang tua belum dimigrasi
            }

            if (! $s) {
                if (! isset($data['class_id'])) {
                    $this->hasil['siswa']['kelas_tak_dikenal']++;

                    continue; // siswa tanpa kelas yang dikenal tidak dibuat (class_id wajib)
                }
                Student::create($data + ['pusat_uuid' => $p['uuid']]);
                $this->hasil['siswa']['baru']++;
                $this->catat("Siswa baru: {$p['nis']} {$p['nama']}");

                continue;
            }

            if ($this->statusSama($s->status, $data['status'])) {
                unset($data['status']); // "berhenti"/"nonaktif" lokal setara "keluar" di Pusat
            }
            $berubahStatus = isset($data['status']) && $data['status'] !== 'aktif' && $s->status === 'aktif';
            if ($tautBaru || $this->beda($s, $data)) {
                $s->update($data + ['pusat_uuid' => $p['uuid']]);
                $this->hasil['siswa']['ubah']++;
                $berubahStatus && $nonaktif++;
                $this->catat(($tautBaru ? 'Siswa ditautkan: ' : 'Siswa diperbarui: ')."{$p['nis']} {$p['nama']}".($berubahStatus ? " (status → {$data['status']})" : ''));
            } else {
                $this->hasil['siswa']['sama']++;
            }
        }

        // Siswa tertaut yang tidak ada lagi di Pusat (dihapus): diberi status "keluar", tidak dihapus.
        foreach ($perUuid as $uuid => $s) {
            if (! isset($adaDiPusat[$uuid]) && $s->status === 'aktif') {
                $s->update(['status' => 'keluar']);
                $nonaktif++;
                $this->hasil['siswa']['ubah']++;
                $this->catat("Siswa hilang di Pusat → keluar: {$s->nis} {$s->nama}");
            }
        }
        $this->hasil['siswa']['nonaktif'] = $nonaktif;
        $this->hasil['siswa']['hanya_lokal'] = Student::whereNull('pusat_uuid')->where('status', 'aktif')->count();

        $batas = max(10, (int) ceil($tertautAwal * 0.2));
        if ($nonaktif > $batas) {
            $pesan = "Perubahan menonaktifkan {$nonaktif} siswa sekaligus (batas aman {$batas}). Periksa data di Pusat.";
            if ($terapkan && ! $paksa) {
                throw new RuntimeException($pesan.' Klik "Tetap terapkan" bila memang benar.');
            }
            $this->hasil['peringatan'][] = $pesan;
        }
        if ($this->hasil['siswa']['kelas_tak_dikenal'] > 0) {
            $this->hasil['peringatan'][] = $this->hasil['siswa']['kelas_tak_dikenal'].' siswa di Pusat berada di kelas yang tidak dikenal Absensi (bukan tahun ajaran aktif) sehingga kelasnya tidak diubah.';
        }
    }

    private function statusSama(?string $lokal, string $pusat): bool
    {
        return $lokal === $pusat || (in_array($lokal, self::KELUAR, true) && in_array($pusat, self::KELUAR, true));
    }

    /** Apakah ada kolom yang nilainya berbeda dari yang tersimpan (null dianggap sama dengan ''). */
    private function beda($model, array $data): bool
    {
        foreach ($data as $kolom => $nilai) {
            if ((string) ($model->{$kolom} ?? '') !== (string) ($nilai ?? '')) {
                return true;
            }
        }

        return false;
    }
}
