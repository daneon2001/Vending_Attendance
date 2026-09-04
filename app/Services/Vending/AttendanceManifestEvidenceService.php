<?php

namespace App\Services\Vending;

use App\Enums\Vending\AttendanceManifestEvidenceStatus;

class AttendanceManifestEvidenceService
{
    public function classify(int $reportedVersion, int $serverVersion): AttendanceManifestEvidenceStatus
    {
        if ($reportedVersion === $serverVersion) {
            return AttendanceManifestEvidenceStatus::CURRENT;
        }

        if ($reportedVersion < $serverVersion) {
            return AttendanceManifestEvidenceStatus::STALE;
        }

        return AttendanceManifestEvidenceStatus::UNKNOWN;
    }
}
