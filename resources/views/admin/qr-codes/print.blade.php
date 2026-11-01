<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>{{ $qr->title }} - QR code</title>
    <link rel="stylesheet" href="{{ asset('css/phase17.css') }}?v={{ @filemtime(public_path('css/phase17.css')) }}">
    {{-- the QR is drawn by resources/js/qr.js (npm "qrcode"), which app.js loads when it finds a [data-qr] box --}}
    @vite(['resources/js/app.js'])
</head>
<body class="q17-print-body">

    <div class="q17-toolbar">
        <a class="btn btn-sm" href="{{ route('admin.qr-codes.index') }}"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button type="button" class="btn btn-sm" data-qr-download="qr-print" data-filename="{{ \Illuminate\Support\Str::slug($qr->title) ?: 'qr-code' }}"><i class="fa-solid fa-download"></i> Download SVG</button>
    </div>

    <main class="q17-sheet">
        <div class="q17-school">{{ config('app.name', 'LCCT') }}</div>
        <h1 class="q17-sheet-title" style="color: {{ $qr->color }}">{{ $qr->title }}</h1>
        <p class="q17-sheet-sub">Point your phone camera at the code</p>

        <div class="q17-box q17-big" id="qr-print" data-qr="{{ $qr->url }}" data-qr-color="{{ $qr->color }}" data-qr-label="QR code: {{ $qr->title }}"></div>

        <p class="q17-sheet-url">{{ $qr->url }}</p>
    </main>

</body>
</html>
