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

        $query = AuditLog::query()->with('user:id,name,email')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

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
            ],
        ]);
    }

    protected function resolveRange(Request $request): array
    {
        $range = $request->string('range', 'today');
        $today = now();

        if ($range === 'custom' && $request->filled(['from', 'to'])) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to = Carbon::parse($request->input('to'))->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->clone()->startOfDay(), $from->clone()->endOfDay()];
            }

            return [$from, $to];
        }

        if ($range === '7d') {
            return [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()];
        }

        if ($range === '30d') {
            return [$today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()];
        }

        return [$today->copy()->startOfDay(), $today->copy()->endOfDay()];
    }
}
