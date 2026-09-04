<?php

namespace App\Http\Controllers\Vending;

use App\Http\Controllers\Controller;
use App\Integrations\Sybi\SybiVendingApiException;
use App\Services\Vending\SybiVendingSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SybiVendingSyncController extends Controller
{
    public function __invoke(Request $request, SybiVendingSyncService $service): RedirectResponse
    {
        try {
            $summary = $service->sync(false, $request->user()?->getKey());
        } catch (SybiVendingApiException $exception) {
            return back()->with('sybi_sync_result', [
                'ok' => false,
                'error_code' => $exception->errorCode->value,
                'http_status' => $exception->httpStatus,
            ]);
        }

        return back()->with('sybi_sync_result', [
            'ok' => true,
            'status' => $summary['status'],
            'received' => $summary['received_total'],
            'source_candidates' => $summary['source_candidates'],
            'source_created' => $summary['source_created'],
            'source_updated' => $summary['source_updated'],
            'source_unchanged' => $summary['source_unchanged'],
            'source_invalid' => $summary['source_invalid'],
            'operational_ready' => $summary['operational_ready'],
            'operational_created' => $summary['operational_created'],
            'operational_updated' => $summary['operational_updated'],
            'operational_unchanged' => $summary['operational_unchanged'],
            'operational_incomplete' => $summary['operational_incomplete'],
            'operational_conflicts' => $summary['operational_conflicts'],
            'operational_invalid' => $summary['operational_invalid'],
            'operational_missing' => $summary['operational_missing'],
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'unchanged' => $summary['unchanged'],
            'rejected' => $summary['rejected'],
            'conflicts' => $summary['conflicts'],
            'missing' => $summary['missing'],
            'warnings' => $summary['warnings'],
        ]);
    }
}
