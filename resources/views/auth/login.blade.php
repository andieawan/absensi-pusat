@extends('layouts.app', ['title' => $switching ? 'Ganti Akun' : 'Masuk', 'bare' => ! $switching, 'width' => $switching ? '520px' : null])
@section('content')
<div class="card login">
    <img class="logo-login" src="{{ asset('icons/icon-192.png') }}" alt="" width="56" height="56">
    <h1>{{ $switching ? 'Ganti Akun' : 'Absensi Siswa' }}</h1>
    @php($viaPusat = \App\Services\PusatKlien::aktif())
    <p class="mut">{{ $settings->school_name }}<br>{{ $switching ? ($viaPusat ? 'Pilih cara pindah ke akun lain.' : 'Masukkan username dan password akun tujuan.') : ($viaPusat ? 'Masuk dengan akun Pusat Sekolah (password Pusat), atau lewat tombol di bawah.' : 'Masuk dengan akun yang diberikan administrator.') }}</p>
    @if($switching && $viaPusat)
        <form method="post" action="{{ route('switch.pusat') }}">@csrf
            <button class="btn btn-pri" style="width:100%">Ganti akun lewat Pusat</button>
        </form>
        <p class="mut" style="font-size:13px;margin:8px 0 16px">Anda akan keluar dari Pusat dan Absensi, lalu masuk dengan akun tujuan di halaman Pusat.</p>
        <p class="mut" style="font-size:13px;margin:0 0 8px;border-top:1px solid var(--line);padding-top:14px">Atau langsung dengan <b>username &amp; password</b> (akun Pusat memakai password Pusat):</p>
    @endif
    @if($needsInstall ?? false)<div class="alert alert-info">Aplikasi belum dipasang. <a href="{{ route('install') }}">Buka halaman instalasi →</a></div>@endif
    <form method="post" action="{{ $switching ? route('switch') : route('login') }}" autocomplete="off">
        @csrf
        <div class="field"><label for="u">Username</label><input id="u" type="text" name="username" value="{{ old('username') }}" autocapitalize="none" autocomplete="username" required @unless($switching && $viaPusat) autofocus @endunless></div>
        <div class="field"><label for="pw">Password</label><div class="pw-wrap"><input id="pw" type="password" name="password" autocomplete="current-password" required><button type="button" class="pw-eye" data-toggle-pw="#pw" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></div></div>
        <button @class(['btn', 'btn-pri' => ! ($switching && $viaPusat)]) style="width:100%" data-busy="Memeriksa…">{{ $switching ? 'Pindah Akun' : 'Masuk' }}</button>
    </form>
    @if(! $switching && \App\Services\PusatKlien::aktif())<a class="btn" style="width:100%;margin-top:10px;text-align:center;display:block" href="{{ route('sso.login') }}">Masuk lewat Pusat</a>@endif
    @unless($switching)<button type="button" class="btn btn-sm hide" id="pwa-install" style="width:100%;margin-top:10px">📲 Pasang Aplikasi di Perangkat Ini</button>@endunless
    @if($switching)<p style="margin-top:14px"><a href="{{ route('dashboard') }}">&larr; Batal, kembali</a></p>@endif
</div>
@endsection
