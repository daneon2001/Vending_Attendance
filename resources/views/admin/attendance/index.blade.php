@php
    $canEdit = auth()->user()?->hasPermission('asistencias', 'edit')
        || auth()->user()?->hasPermission('asistencias', 'admin')
        || auth()->user()?->hasPermission('settings', 'manage');
    $canExport = auth()->user()?->hasPermission('asistencias', 'export')
        || auth()->user()?->hasPermission('asistencias', 'admin')
        || auth()->user()?->hasPermission('settings', 'manage');
@endphp

@extends('layouts.admin', [
    'title' => 'Central de Asistencias',
    'header' => 'Central de Asistencias',
    'subheader' => 'Registros crudos centralizados, filtros operativos y ajustes auditados.',
])

@section('content')
    <section class="card p-4">
        <form method="GET" action="{{ route('admin.asistencias.index') }}" class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Desde
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Hasta
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Empleado (nombre/codigo)
                <input type="text" name="employee" value="{{ $filters['employee'] ?? '' }}" placeholder="Nombre o codigo" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Empleado exacto
                <select name="employee_id" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    <option value="">Todos</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(($filters['employee_id'] ?? null) == $employee->id)>
                            {{ $employee->full_name ?? $employee->name ?? 'Empleado #' . $employee->id }} ({{ $employee->fortia_employee_id ?? $employee->id }})
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Unidad / sucursal
                <select name="location_id" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    <option value="">Todas</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}" @selected(($filters['location_id'] ?? null) == $location->id)>
                            {{ $location->name }}{{ $location->code ? ' (' . $location->code . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Reloj / dispositivo
                <select name="device_id" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    <option value="">Todos</option>
                    @foreach($clocks as $clock)
                        <option value="{{ $clock->id }}" @selected(($filters['device_id'] ?? null) == $clock->id)>
                            {{ $clock->clock_name }}{{ $clock->serial_number ? ' (' . $clock->serial_number . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Tipo
                <select name="type" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    <option value="">Todos</option>
                    <option value="in" @selected(($filters['type'] ?? '') === 'in')>IN (Entrada)</option>
                    <option value="out" @selected(($filters['type'] ?? '') === 'out')>OUT (Salida)</option>
                    <option value="1" @selected(($filters['type'] ?? '') === '1')>1</option>
                    <option value="2" @selected(($filters['type'] ?? '') === '2')>2</option>
                    <option value="3" @selected(($filters['type'] ?? '') === '3')>3</option>
                    <option value="4" @selected(($filters['type'] ?? '') === '4')>4</option>
                    <option value="unknown" @selected(($filters['type'] ?? '') === 'unknown')>Desconocido</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Fuente
                <select name="source" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    <option value="">Todas</option>
                    @foreach($sourceLabels as $sourceKey => $sourceLabel)
                        <option value="{{ $sourceKey }}" @selected(($filters['source'] ?? '') === $sourceKey)>
                            {{ $sourceLabel }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Estatus
                <select name="status" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    <option value="">Todos</option>
                    @foreach($statusLabels as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>
                            {{ $statusLabel }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                Registros por pagina
                <select name="per_page" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    @foreach([25, 50, 100, 200] as $size)
                        <option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 25) === $size)>
                            {{ $size }}
                        </option>
                    @endforeach
                </select>
            </label>

            <div class="flex flex-wrap items-end gap-2 lg:col-span-2">
                <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.25em] text-white">
                    Aplicar filtros
                </button>
                <a href="{{ route('admin.asistencias.index') }}" class="rounded-xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.25em] text-muted">
                    Limpiar
                </a>
                @if($canEdit)
                    <button type="button" onclick="openAdjustmentModal()" class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.25em] text-emerald-700">
                        Ajuste manual
                    </button>
                @endif
            </div>
        </form>
    </section>

    <section class="card p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-muted">
                Mostrando {{ $records->firstItem() ?? 0 }} - {{ $records->lastItem() ?? 0 }} de {{ $records->total() }} registros.
            </div>
            @if($canExport)
                <div class="flex gap-2">
                    <a href="{{ route('admin.asistencias.export', array_merge(request()->query(), ['format' => 'csv'])) }}"
                       class="rounded-xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted">
                        Exportar CSV
                    </a>
                    <a href="{{ route('admin.asistencias.export', array_merge(request()->query(), ['format' => 'excel'])) }}"
                       class="rounded-xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted">
                        Exportar Excel
                    </a>
                </div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-[0.2em] text-soft">
                <tr>
                    <th class="px-3 py-3 text-left">Fecha/hora</th>
                    <th class="px-3 py-3 text-left">Empleado</th>
                    <th class="px-3 py-3 text-left">Unidad</th>
                    <th class="px-3 py-3 text-left">Reloj</th>
                    <th class="px-3 py-3 text-left">Tipo</th>
                    <th class="px-3 py-3 text-left">Fuente</th>
                    <th class="px-3 py-3 text-left">Estatus</th>
                    <th class="px-3 py-3 text-left">Acciones</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($records as $record)
                    @php
                        $statusClass = match($record->attendance_status) {
                            'anulada' => 'bg-rose-50 text-rose-700',
                            'corregida' => 'bg-amber-50 text-amber-700',
                            default => 'bg-emerald-50 text-emerald-700',
                        };
                        $typeLabel = $typeLabels[(int)$record->log_type] ?? 'Desconocido';
                    @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="px-3 py-3 text-app">
                            {{ optional($record->log_date)->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="px-3 py-3">
                            <p class="font-semibold text-app">{{ $record->employee?->full_name ?? $record->employee?->name ?? 'N/A' }}</p>
                            <p class="text-xs text-soft">ID {{ $record->employee?->fortia_employee_id ?? $record->employee_id }}</p>
                        </td>
                        <td class="px-3 py-3 text-muted">
                            {{ $record->location?->name ?? 'Sin unidad' }}
                        </td>
                        <td class="px-3 py-3 text-muted">
                            {{ $record->clock?->clock_name ?? ('#' . ($record->device_id ?? 'N/A')) }}
                        </td>
                        <td class="px-3 py-3 text-muted">
                            {{ $typeLabel }} ({{ $record->log_type }})
                        </td>
                        <td class="px-3 py-3 text-muted">
                            {{ $sourceLabels[$record->source] ?? strtoupper($record->source ?? 'N/A') }}
                        </td>
                        <td class="px-3 py-3">
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">
                                {{ $statusLabels[$record->attendance_status] ?? ucfirst($record->attendance_status ?? 'N/A') }}
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('admin.asistencias.show', $record) }}"
                                   class="rounded-xl border border-app px-3 py-1 text-xs font-semibold text-muted">
                                    Ver detalle
                                </a>
                                @if($canEdit && $record->attendance_status !== 'anulada')
                                    <form id="annul-form-{{ $record->id }}" method="POST" action="{{ route('admin.asistencias.annul', $record) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="reason" id="annul-reason-{{ $record->id }}">
                                        <button type="button"
                                                onclick="submitAnnulment({{ $record->id }})"
                                                class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
                                            Anular
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-8 text-center text-sm text-soft">
                            No hay registros para los filtros seleccionados.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $records->links() }}
        </div>
    </section>

    @if($canEdit)
        <div id="adjustment-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-app">Nuevo ajuste manual</h3>
                    <button type="button" onclick="closeAdjustmentModal()" class="rounded-xl border border-app px-3 py-1 text-xs font-semibold text-muted">Cerrar</button>
                </div>

                <form method="POST" action="{{ route('admin.asistencias.adjustments.store') }}" class="grid gap-3 md:grid-cols-2">
                    @csrf

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft md:col-span-2">
                        Empleado
                        <select name="employee_id" required class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                            <option value="">Selecciona empleado</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                    {{ $employee->full_name ?? $employee->name ?? 'Empleado #' . $employee->id }} ({{ $employee->fortia_employee_id ?? $employee->id }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                        Fecha y hora
                        <input type="datetime-local" name="log_date" value="{{ old('log_date', now()->format('Y-m-d\TH:i')) }}" required class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                        Tipo
                        <select name="log_type" required class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                            <option value="in" @selected(old('log_type') === 'in')>IN (Entrada)</option>
                            <option value="out" @selected(old('log_type') === 'out')>OUT (Salida)</option>
                            <option value="unknown" @selected(old('log_type') === 'unknown')>Desconocido</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                        Unidad (opcional)
                        <select name="location_id" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                            <option value="">Sin unidad especifica</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}" @selected(old('location_id') == $location->id)>
                                    {{ $location->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft">
                        Reloj (opcional)
                        <select name="device_id" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                            <option value="">Sin reloj especifico</option>
                            @foreach($clocks as $clock)
                                <option value="{{ $clock->id }}" @selected(old('device_id') == $clock->id)>
                                    {{ $clock->clock_name }}{{ $clock->serial_number ? ' (' . $clock->serial_number . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft md:col-span-2">
                        Motivo del ajuste
                        <input type="text" name="reason" value="{{ old('reason') }}" required maxlength="500" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-[0.25em] text-soft md:col-span-2">
                        Notas (opcional)
                        <textarea name="notes" rows="3" maxlength="1000" class="rounded-xl border border-app bg-white px-3 py-2 text-sm text-app">{{ old('notes') }}</textarea>
                    </label>

                    <div class="md:col-span-2 flex justify-end gap-2 pt-2">
                        <button type="button" onclick="closeAdjustmentModal()" class="rounded-xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted">
                            Cancelar
                        </button>
                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-white">
                            Guardar ajuste
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        function submitAnnulment(recordId) {
            const reason = window.prompt('Motivo de anulacion del registro:');
            if (!reason) {
                return;
            }

            const reasonInput = document.getElementById('annul-reason-' + recordId);
            const form = document.getElementById('annul-form-' + recordId);

            if (!reasonInput || !form) {
                return;
            }

            reasonInput.value = reason;
            form.submit();
        }

        function openAdjustmentModal() {
            const modal = document.getElementById('adjustment-modal');
            if (!modal) {
                return;
            }
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeAdjustmentModal() {
            const modal = document.getElementById('adjustment-modal');
            if (!modal) {
                return;
            }
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        @if(isset($errors) && ($errors->has('employee_id') || $errors->has('log_date') || $errors->has('log_type') || $errors->has('reason')))
        openAdjustmentModal();
        @endif
    </script>
@endpush
