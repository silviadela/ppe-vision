<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="application-version" content="{{ config('deployment.build') }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body><div class="shell">
<aside class="sidebar">
    <a href="{{ route('dashboard') }}" class="brand"><span class="brand-mark">PV</span><span>PPE Vision</span></a>
    <div class="nav-label">Workspace</div>
    <nav class="nav">
        <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z"/></svg>Dashboard</a>
        <a class="{{ request()->routeIs('experiments.*') ? 'active' : '' }}" href="{{ route('experiments.index') }}"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" d="M9 3h6m-5 0v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M8 15h8"/></svg>Eksperimen</a>
        <a class="{{ request()->routeIs('detection.*') ? 'active' : '' }}" href="{{ route('detection.index') }}"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" d="M4 8V4h4m8 0h4v4m0 8v4h-4M8 20H4v-4M8 12h8"/></svg>Demo Deteksi</a>
        @php($latest = \App\Models\TrainingRun::whereNotNull('metrics')->latest()->first())
        <a class="{{ request()->routeIs('summary.*') ? 'active' : '' }}" href="{{ $latest ? route('summary.show', $latest) : route('experiments.index') }}"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" d="M9 18h6M10 22h4M8 14a7 7 0 1 1 8 0c-1 .7-1 1.4-1 2H9c0-.6 0-1.3-1-2Z"/></svg>AI Summary</a>
    </nav>
    <div class="sidebar-foot"><strong>3 kelas PPE</strong>Helm · Masker · People</div>
</aside>
<main class="main"><header class="topbar"><p class="eyebrow">Object detection workspace</p><span class="status-pill"><span class="status-dot"></span>System aktif</span></header>
<div class="content">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif
    @yield('content')
</div></main></div></body></html>
