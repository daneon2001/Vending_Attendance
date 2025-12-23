<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveRange($request);

        $query = AuditLog::query()
            ->with('user:id,name,email')
            ->when($from && $to, fn ($q) => $q->whereBetween('created_at', [$from, $to]));

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        if ($search = $request->string('q')->trim()) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%");
            });
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return response()->json([
            'data' => AuditLogResource::collection($logs)->resolve(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user:id,name,email');

        $timezone = config('app.timezone', 'UTC');

        return response()->json([
            'data' => [
                'id' => $auditLog->id,
                'event' => $auditLog->event,
                'description' => $auditLog->description,
                'user' => [
                    'id' => $auditLog->user_id,
                    'name' => $auditLog->user_name ?? optional($auditLog->user)->name,
                    'email' => $auditLog->user_email ?? optional($auditLog->user)->email,
                ],
                'auditable_type' => $auditLog->auditable_type,
                'auditable_id' => $auditLog->auditable_id,
                'metadata' => $auditLog->metadata,
                'ip_address' => $auditLog->ip_address,
                'user_agent' => $auditLog->user_agent,
                'created_at' => optional($auditLog->created_at)->toISOString(),
                'created_at_local' => optional($auditLog->created_at)
                    ? $auditLog->created_at->copy()->setTimezone($timezone)->format('d/m/Y H:i:s')
                    : null,
            ],
        ]);
    }

    protected function resolveRange(Request $request): array
    {
        $range = $request->string('range', 'today');
        $timezone = config('app.timezone', 'UTC');
        $now = now($timezone);

        if ($range === 'custom' && $request->filled(['from', 'to'])) {
            $from = $this->parseLocalDate($request->input('from'), $timezone);
            $to = $this->parseLocalDate($request->input('to'), $timezone);

            if ($from && $to) {
                if ($from->gt($to)) {
                    [$from, $to] = [$to, $from];
                }

                return [
                    $from->copy()->startOfDay()->setTimezone('UTC'),
                    $to->copy()->endOfDay()->setTimezone('UTC'),
                ];
            }
        }

        if ($range === '7d') {
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($range === '30d') {
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
        } else {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        }

        return [
            $start->setTimezone('UTC'),
            $end->setTimezone('UTC'),
        ];
    }

    protected function parseLocalDate(?string $value, string $timezone): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $formats = ['d/m/Y', 'Y-m-d'];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value, $timezone);
            } catch (\Throwable $th) {
                continue;
            }
        }

        return null;
    }
}
