<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalasi PPE Vision</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #14271f; background: #f4f7f5; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 18px; }
        main { width: min(680px, 100%); margin: 7vh auto; padding: 32px; background: #fff; border: 1px solid #dbe5df; border-radius: 20px; box-shadow: 0 18px 50px rgba(20, 39, 31, .08); }
        .eyebrow { color: #197553; font-size: 12px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        h1 { margin: 8px 0 10px; font-size: clamp(28px, 5vw, 42px); }
        p { color: #64726c; line-height: 1.65; }
        label { display: block; margin: 24px 0 8px; font-weight: 700; }
        input { width: 100%; padding: 14px 16px; border: 1px solid #bfd0c7; border-radius: 12px; font: inherit; }
        button, a { display: inline-block; margin-top: 16px; padding: 13px 18px; border: 0; border-radius: 12px; background: #197553; color: #fff; font: inherit; font-weight: 750; text-decoration: none; cursor: pointer; }
        .notice { margin-top: 20px; padding: 15px 17px; border-radius: 12px; background: #e4f5eb; color: #166240; }
        .error { margin-top: 18px; padding: 14px 16px; border-radius: 12px; background: #fff0ef; color: #a3312b; }
        code { background: #edf2ef; padding: 2px 6px; border-radius: 6px; }
        pre { overflow: auto; padding: 14px; border-radius: 10px; background: #14271f; color: #d8f6e8; white-space: pre-wrap; }
    </style>
</head>
<body>
<main>
    <div class="eyebrow">PPE Vision · InfinityFree</div>
    <h1>Instalasi database</h1>

    @if(!empty($completed))
        <div class="notice"><strong>Instalasi berhasil.</strong> Database dan data awal sudah tersedia.</div>
        @if(!empty($output))<pre>{{ $output }}</pre>@endif
        <p>Untuk keamanan, buka file <code>.env</code> di server, kosongkan <code>INFINITYFREE_INSTALL_TOKEN</code>, lalu simpan. Halaman instalasi ini akan otomatis menjadi 404.</p>
        <a href="{{ route('dashboard') }}">Buka dashboard</a>
    @else
        <p>Halaman ini menjalankan migration dan seeder Laravel tanpa SSH. Pastikan kredensial MySQL pada <code>.env</code> sudah benar.</p>
        @if($installed ?? false)<div class="notice">Tabel aplikasi sudah ditemukan. Instalasi dapat dijalankan lagi untuk memastikan migration terbaru dan data awal tersedia.</div>@endif

        @if($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('installation.store') }}">
            @csrf
            <label for="install_token">Token instalasi</label>
            <input id="install_token" name="install_token" type="password" required autocomplete="off">
            <button type="submit">Pasang database</button>
        </form>
    @endif
</main>
</body>
</html>
