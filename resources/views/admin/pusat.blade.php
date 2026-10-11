@extends('layouts.app', ['title' => 'Integrasi Pusat'])
@section('content')
@include('admin._tabs')
@php $h = session('pusat_hasil'); @endphp
@if(! $siap)
    <div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Fitur ini butuh pembaruan database. Buka <a href="{{ route('admin.settings') }}">Pengaturan → Perbarui Database Sekarang</a>.</span></div>
@elseif(! $aktif)
    <div class="alert alert-info"><span class="alert-txt">Integrasi Pusat belum diaktifkan. Isi <code>PUSAT_ENABLED=true</code>, <code>PUSAT_URL</code>, <code>PUSAT_CLIENT_ID</code>, <code>PUSAT_CLIENT_SECRET</code> di file <code>.env</code>, lalu muat ulang halaman ini.</span></div>
@else
<div class="card">
    <b>Terhubung ke:</b> <code>{{ $url }}</code>
    <p class="hint" style="margin:6px 0">Daftarkan di Pusat — Redirect URI: <code>{{ $callback }}</code> · Webhook: <code>{{ $webhook }}</code></p>
    <form method="post" action="{{ route('admin.pusat.uji') }}" style="display:inline">@csrf<button class="btn btn-sm">Uji Koneksi</button></form>
</div>

<div class="card">
    <b>Sinkron data induk (kelas &amp; siswa) dari Pusat</b>
    <p class="hint" style="margin:6px 0">
        Terakhir diterapkan: {{ $terakhir ? \Carbon\CarbonImmutable::parse($terakhir.' UTC')->setTimezone(\App\Support\Dates::tz())->format('Y-m-d H:i') : 'belum pernah' }}.
        @if($menunggu > 0)<b>Ada {{ $menunggu }} pemberitahuan perubahan di Pusat yang belum diterapkan.</b>@endif
        Mode: {{ $otomatis ? 'otomatis tiap jam' : 'manual (tombol di bawah)' }}.
    </p>
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
        <form method="post" action="{{ route('admin.pusat.periksa') }}">@csrf<button class="btn" data-busy="Memeriksa…">1. Periksa Perubahan (simulasi)</button></form>
        <form method="post" action="{{ route('admin.pusat.terapkan') }}" data-confirm="Terapkan perubahan dari Pusat ke data Absensi sekarang?">@csrf
            <label class="chk" style="margin-right:8px"><input type="checkbox" name="paksa" value="1"> tetap terapkan bila banyak siswa dinonaktifkan</label>
            <button class="btn btn-pri" data-busy="Menerapkan…">2. Terapkan</button>
        </form>
    </div>
    <p class="hint" style="margin:8px 0 0">Tidak ada data yang dihapus: siswa yang keluar/hilang di Pusat hanya diberi status “keluar”, riwayat absensi tetap ada. Mapel, pasangan mapel, wali kelas, dan nilai tidak disentuh.</p>
</div>

@if($h)
<div class="card">
    <b>{{ $h['diterapkan'] ? 'Hasil sinkron (sudah diterapkan)' : 'Hasil simulasi (belum diterapkan)' }}</b>
    @foreach($h['peringatan'] as $w)<div class="alert alert-warn" style="margin-top:8px"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">{{ $w }}</span></div>@endforeach
    <div class="scroll"><table class="tbl"><thead><tr><th></th><th>Baru</th><th>Diubah</th><th>Sama</th><th>Catatan</th></tr></thead><tbody>
        <tr><td>Jurusan</td><td>{{ $h['jurusan']['baru'] }}</td><td>-</td><td>-</td><td></td></tr>
        <tr><td>Kelas</td><td>{{ $h['kelas']['baru'] }}</td><td>{{ $h['kelas']['ubah'] }}</td><td>{{ $h['kelas']['sama'] }}</td>
            <td>@if($h['kelas']['ambigu'])Nama kembar di Absensi (dilewati): {{ implode(', ', $h['kelas']['ambigu']) }}. @endif @if($h['kelas']['hilang'])Tidak ada lagi di Pusat (dibiarkan): {{ implode(', ', $h['kelas']['hilang']) }}.@endif</td></tr>
        <tr><td>Siswa</td><td>{{ $h['siswa']['baru'] }}</td><td>{{ $h['siswa']['ubah'] }}</td><td>{{ $h['siswa']['sama'] }}</td>
            <td>Dinonaktifkan: {{ $h['siswa']['nonaktif'] }}. Aktif di Absensi tapi belum ada di Pusat: {{ $h['siswa']['hanya_lokal'] }}.
                @if($h['siswa']['ambigu'])NIS kembar di Absensi (dilewati): {{ implode(', ', array_slice($h['siswa']['ambigu'], 0, 10)) }}.@endif</td></tr>
    </tbody></table></div>
    @if($h['contoh'])
        <p class="hint" style="margin:10px 0 4px">Contoh perubahan (maks. 25):</p>
        <ul style="margin:0;padding-left:18px">@foreach($h['contoh'] as $c)<li>{{ $c }}</li>@endforeach</ul>
    @endif
</div>
@endif
@endif
@endsection
