<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ trim(($title ?? '') . ' | ' . config('app.name', 'Asistencias')) }}</title>
    @php
        $manifestPath = public_path('build/manifest.json');
        $hotPath = public_path('hot');
        $canLoadAssets = false;
        $manifest = null;

        if (file_exists($hotPath)) {
            $canLoadAssets = true;
        } elseif (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $canLoadAssets = is_array($manifest)
                && array_key_exists('resources/css/app.css', $manifest)
                && array_key_exists('resources/js/select-enhancer-entry.js', $manifest);
        }
    @endphp
    @if(!app()->runningUnitTests() && $canLoadAssets)
        @vite(['resources/css/app.css', 'resources/js/select-enhancer-entry.js'])
    @endif
</head>
<body class="bg-app text-app">
<div class="min-h-screen lg:flex">
    <aside class="border-app border-b bg-white/90 p-4 lg:w-72 lg:border-b-0 lg:border-r">
        <div class="mb-6">
            <p class="text-xs uppercase tracking-[0.25em] text-soft">Admin</p>
            <h1 class="text-lg font-semibold">Central de Asistencias</h1>
        </div>

        <nav class="space-y-2 text-sm">
            <a href="{{ route('dashboard') }}"
               class="block rounded-xl border border-app px-3 py-2 hover:bg-slate-50">
                Panel general
            </a>
            <a href="{{ route('employees.index') }}"
               class="block rounded-xl border border-app px-3 py-2 hover:bg-slate-50">
                Catalogo de empleados
            </a>
            @if(auth()->user()?->hasPermission('units', 'view') || auth()->user()?->hasPermission('settings', 'manage'))
                <a href="{{ route('units.index') }}"
                   class="block rounded-xl border border-app px-3 py-2 hover:bg-slate-50">
                    Catalogo de unidades
                </a>
            @endif
            <a href="{{ route('clocks.index') }}"
               class="block rounded-xl border border-app px-3 py-2 hover:bg-slate-50">
                Relojes biometricos
            </a>
            <a href="{{ route('admin.asistencias.index') }}"
               class="block rounded-xl border px-3 py-2 {{ request()->routeIs('admin.asistencias.*') ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-app hover:bg-slate-50' }}">
                Asistencias (Centralizado)
            </a>
        </nav>
    </aside>

    <div class="flex-1">
        <header class="border-app border-b bg-white/90 px-4 py-3 sm:px-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ $header ?? 'Administracion' }}</h2>
                    @isset($subheader)
                        <p class="text-xs text-soft">{{ $subheader }}</p>
                    @endisset
                </div>

                <div class="flex items-center gap-3 text-sm">
                    <div class="text-right">
                        <p class="font-semibold">{{ auth()->user()?->name }}</p>
                        <p class="text-xs text-soft">{{ auth()->user()?->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="rounded-xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted hover:text-app">
                            Cerrar sesion
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="space-y-4 p-4 sm:p-6">
            @if(session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if(session('warning'))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ session('warning') }}
                </div>
            @endif

            @if(isset($errors) && $errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc pl-4">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
