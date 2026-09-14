<?php

namespace App\Models;

/** Reuses confirmed-evidence immutability and private metadata; never creates a ticket. */
class SupportActivityEvidence extends SupportEvidence
{
    protected $table = 'support_activity_evidence';

    public function activity()
    {
        return $this->belongsTo(VendingSupportActivity::class, 'support_activity_id');
    }
}
