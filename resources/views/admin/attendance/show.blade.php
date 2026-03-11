@php
    $canEdit = auth()->user()?->hasPermission('asistencias', 'edit')
        || auth()->user()?->hasPermission('asistencias', 'admin')
        || auth()->user()?->hasPermission('settings', 'manage');
    $statusClass = match($record->attendance_status) {
        'anulada' => 'bg-rose-50 text-rose-700',
        'corregida' => 'bg-amber-50 text-amber-700',
        default => 'bg-emerald-50 text-emerald-700',
    };
@endphp

@extends('layouts.admin', [
    'title' => 'Detalle de asistencia',
    'header' => 'Detalle de registro de asistencia',
    'subheader' => 'Auditoria de cambios y datos crudos del registro.',
])

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-2">
        <a href="{{ route('admin.asistencias.index') }}" class="rounded-xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted">
            Volver al listado
        </a>

        @if($canEdit && $record->attendance_status !== 'anulada')
            <form id="annul-form-detail" method="POST" action="{{ route('admin.asistencias.annul', $record) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="reason" id="annul-reason-detail">
                <button type="button" onclick="submitAnnulmentDetail()" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-rose-700">
                    Anular registro
                </button>
            </form>
        @endif
    </div>

    <section class="grid gap-4 lg:grid-cols-3">
        <article class="card space-y-3 p-4 lg:col-span-2">
            <div class="flex items-center justify-between gap-3 border-b border-app pb-3">
                <h3 class="text-base font-semibold">Datos principales</h3>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">
                    {{ $statusLabels[$record->attendance_status] ?? ucfirst($record->attendance_status ?? 'N/A') }}
                </span>
            </div>

            <dl class="grid gap-3 md:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Registro ID</dt>
                    <dd class="text-sm font-semibold text-app">#{{ $record->id }} / log_id {{ $record->log_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Fecha/Hora</dt>
                    <dd class="text-sm text-app">{{ optional($record->log_date)->format('Y-m-d H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Empleado</dt>
                    <dd class="text-sm text-app">
                        {{ $record->employee?->full_name ?? $record->employee?->name ?? 'N/A' }}
                        <span class="text-soft">({{ $record->employee?->fortia_employee_id ?? $record->employee_id }})</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Unidad</dt>
                    <dd class="text-sm text-app">{{ $record->location?->name ?? 'Sin unidad' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Reloj</dt>
                    <dd class="text-sm text-app">{{ $record->clock?->clock_name ?? ('#' . ($record->device_id ?? 'N/A')) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Tipo</dt>
                    <dd class="text-sm text-app">{{ $typeLabels[(int)$record->log_type] ?? 'Desconocido' }} ({{ $record->log_type }})</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Fuente</dt>
                    <dd class="text-sm text-app">{{ $sourceLabels[$record->source] ?? strtoupper($record->source ?? 'N/A') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Motivo de ajuste/anulacion</dt>
                    <dd class="text-sm text-app">{{ $record->adjustment_reason ?: 'N/A' }}</dd>
                </div>
            </dl>
        </article>

        <article class="card space-y-3 p-4">
            <h3 class="text-base font-semibold">Estado de anulacion</h3>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Anulado en</dt>
                    <dd>{{ optional($record->annulled_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Usuario anulacion</dt>
                    <dd>{{ $record->annulledBy?->name ?? ($record->annulled_by_user_id ? 'Usuario #' . $record->annulled_by_user_id : 'N/A') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Fortia status</dt>
                    <dd>{{ $record->fortia_status ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-[0.2em] text-soft">Sent to Fortia at</dt>
                    <dd>{{ optional($record->sent_to_fortia_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</dd>
                </div>
            </dl>
        </article>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <article class="card p-4">
            <h3 class="mb-3 text-base font-semibold">Raw payload</h3>
            <pre class="overflow-x-auto rounded-xl bg-slate-950 p-3 text-xs text-slate-100">{{ json_encode($record->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </article>
        <article class="card p-4">
            <h3 class="mb-3 text-base font-semibold">Fortia response payload</h3>
            <pre class="overflow-x-auto rounded-xl bg-slate-950 p-3 text-xs text-slate-100">{{ json_encode($record->fortia_response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </article>
    </section>

    <section class="card p-4">
        <h3 class="mb-3 text-base font-semibold">Bitacora de cambios</h3>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-[0.2em] text-soft">
                <tr>
                    <th class="px-3 py-3 text-left">Fecha</th>
                    <th class="px-3 py-3 text-left">Accion</th>
                    <th class="px-3 py-3 text-left">Usuario</th>
                    <th class="px-3 py-3 text-left">Motivo</th>
                    <th class="px-3 py-3 text-left">Antes / Despues</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($record->audits->sortByDesc('created_at') as $audit)
                    <tr>
                        <td class="px-3 py-3 text-muted">{{ optional($audit->created_at)->format('Y-m-d H:i:s') }}</td>
                        <td class="px-3 py-3 text-app">{{ $audit->action }}</td>
                        <td class="px-3 py-3 text-app">{{ $audit->changed_by_name ?? $audit->changedBy?->name ?? 'N/A' }}</td>
                        <td class="px-3 py-3 text-app">{{ $audit->reason ?? 'N/A' }}</td>
                        <td class="px-3 py-3">
                            <details>
                                <summary class="cursor-pointer text-xs text-indigo-600">Ver diff</summary>
                                <div class="mt-2 grid gap-2 lg:grid-cols-2">
                                    <pre class="overflow-x-auto rounded-lg bg-slate-900 p-2 text-[11px] text-slate-100">{{ json_encode($audit->before_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    <pre class="overflow-x-auto rounded-lg bg-slate-900 p-2 text-[11px] text-slate-100">{{ json_encode($audit->after_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-6 text-center text-sm text-soft">Sin auditoria para este registro.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        function submitAnnulmentDetail() {
            const reason = window.prompt('Motivo de anulacion del registro:');
            if (!reason) {
                return;
            }

            const reasonInput = document.getElementById('annul-reason-detail');
            const form = document.getElementById('annul-form-detail');

            if (!reasonInput || !form) {
                return;
            }

            reasonInput.value = reason;
            form.submit();
        }
    </script>
@endpush
