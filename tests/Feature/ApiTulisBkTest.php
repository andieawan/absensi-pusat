<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Attendance;
use App\Models\DelegationToken;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Dates;
use Tests\TestCase;

/** API untuk aplikasi BK: rekap per kelas, absensi manual, delegasi Ketua Kelas (scope kehadiran:tulis). */
class ApiTulisBkTest extends TestCase
{
    private function key(array $scopes): array
    {
        $plain = 'ak_'.bin2hex(random_bytes(20));
        ApiKey::create(['name' => 'Aplikasi BK', 'key_prefix' => substr($plain, 0, 11), 'key_hash' => ApiKey::hashOf($plain), 'scopes' => $scopes,
            'is_active' => true, 'rate_limit' => 120, 'created_at' => '2026-10-19 00:00:00']);

        return ['Authorization' => 'Bearer '.$plain];
    }

    public function test_scope_tulis_wajib_dan_input_manual_tersimpan(): void
    {
        SchoolClass::whereKey(1)->update(['pusat_uuid' => 'k-uuid-1']);
        Student::whereKey(1)->update(['pusat_uuid' => 's-uuid-1']);
        $hari = Dates::today();
        $body = ['siswa_uuid' => 's-uuid-1', 'tanggal' => $hari, 'status' => 'A', 'petugas' => 'Bu BK'];

        $this->postJson('/api/v1/kehadiran/manual', $body, $this->key(['kehadiran:baca']))->assertForbidden();
        $this->postJson('/api/v1/kehadiran/manual', $body, $this->key(['kehadiran:tulis']))->assertOk()->assertJsonPath('data.dibuat', 1);
        $this->assertSame('A', Attendance::where('student_id', 1)->where('tanggal', $hari)->value('status'));
        $this->assertSame('bk_manual', Attendance::where('student_id', 1)->value('recorded_via'));

        $h = $this->key(['kehadiran:tulis']);
        $this->postJson('/api/v1/kehadiran/manual', ['status' => 'H'] + $body, $h)->assertOk()->assertJsonPath('data.diperbarui', 1); // idempoten: ubah, bukan ganda
        $this->postJson('/api/v1/kehadiran/manual', ['tanggal' => date('Y-m-d', time() + 5 * 86400)] + $body, $h)->assertStatus(422);
        $this->postJson('/api/v1/kehadiran/manual', ['siswa_uuid' => 'tidak-ada'] + $body, $h)->assertNotFound();
        $this->postJson('/api/v1/kehadiran/manual', ['status' => 'X'] + $body, $h)->assertStatus(422);
        $this->assertSame(1, Attendance::count());
    }

    public function test_ringkasan_per_kelas_memakai_uuid_dan_hanya_harian(): void
    {
        SchoolClass::whereKey(1)->update(['pusat_uuid' => 'k-uuid-1']);
        Student::whereKey(1)->update(['pusat_uuid' => 's-uuid-1']);
        $hari = Dates::today();
        Attendance::create(['student_id' => 1, 'class_id' => 1, 'subject_id' => null, 'tanggal' => $hari, 'status' => 'A', 'recorded_via' => 'guru', 'created_at' => $hari.' 00:00:00', 'updated_at' => $hari.' 00:00:00', 'created_at_millis' => 0]);
        Attendance::create(['student_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'tanggal' => $hari, 'status' => 'A', 'recorded_via' => 'guru', 'created_at' => $hari.' 00:00:00', 'updated_at' => $hari.' 00:00:00', 'created_at_millis' => 0]);

        $r = $this->getJson('/api/v1/kehadiran/ringkasan?kelas_uuid=k-uuid-1', $this->key(['kehadiran:baca']))->assertOk();
        $this->assertCount(4, $r->json('data'));
        $s1 = collect($r->json('data'))->firstWhere('siswa_uuid', 's-uuid-1');
        $this->assertSame(1, $s1['alpa']); // sesi mapel tidak dihitung
        $this->getJson('/api/v1/kehadiran/ringkasan?kelas_uuid=salah', $this->key(['kehadiran:baca']))->assertNotFound();
    }

    public function test_delegasi_buat_daftar_dan_cabut(): void
    {
        SchoolClass::whereKey(1)->update(['pusat_uuid' => 'k-uuid-1']);
        $h = $this->key(['kehadiran:tulis', 'kehadiran:baca']);
        $r = $this->postJson('/api/v1/delegasi', ['kelas_uuid' => 'k-uuid-1', 'jam' => 6, 'petugas' => 'Bu BK'], $h)->assertCreated();
        $token = $r->json('data.token');
        $this->assertStringStartsWith('kk_', $token);
        $this->assertStringContainsString('/presensi/'.$token, $r->json('data.tautan'));
        $this->assertNull(DelegationToken::find($token)->created_by);

        $this->getJson('/api/v1/delegasi?kelas_uuid=k-uuid-1', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/delegasi/'.$token, [], $h)->assertOk();
        $this->getJson('/api/v1/delegasi?kelas_uuid=k-uuid-1', $h)->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/delegasi/kk_palsu', [], $h)->assertNotFound();

        // Ketua kelas tetap bisa memakai tautan dari API (tanpa pembuat pengguna)
        $t = $this->postJson('/api/v1/delegasi', ['kelas_id' => 1], $h)->json('data.token');
        auth()->logout();
        $this->post("/presensi/$t", ['date' => Dates::today(), 'status' => [1 => 'H', 2 => 'H', 3 => 'H', 4 => 'H']])->assertSessionHas('success');
        $this->assertNull(Attendance::where('student_id', 1)->value('recorded_by'));
    }

    public function test_menu_bk_dialihkan_bila_bk_url_diisi(): void
    {
        $admin = \App\Models\User::create(['username' => 'adm', 'nama' => 'Adm', 'password_hash' => 'h', 'roles' => ['admin'], 'is_active' => true]);
        config(['absensi.bk_url' => 'https://bk.example.test']);
        $this->actingAs($admin)->get('/bk/presensi')->assertRedirect('https://bk.example.test/presensi');
        $this->get('/bk/catatan/kasus')->assertRedirect('https://bk.example.test/catatan/kasus');
        $this->get('/bk')->assertRedirect('https://bk.example.test');
    }
}
