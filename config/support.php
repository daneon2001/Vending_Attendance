<?php

return [
    // Local phase-13 categories, not a certified production taxonomy.
    'catalog_policy' => 'DEMO',
    // Explicit Phase-13 reservation by source vending identifier, not SYBI row id.
    'reserved_sybi_identifiers' => ['7'],
    'categories' => [
        'CONNECTIVITY' => 'Conectividad', 'SCREEN' => 'Pantalla', 'POWER' => 'Energía',
        'GPS' => 'Ubicación', 'CAMERA' => 'Cámara', 'APPLICATION' => 'Aplicación',
        'INVENTORY' => 'Inventario', 'DAMAGE' => 'Daño físico', 'OTHER' => 'Otro',
    ],
    'evidence' => [
        'disk' => 'support_private', 'max_size_bytes' => 5 * 1024 * 1024, 'max_count' => 5,
        'max_pixels' => 12000000, 'max_dimension' => 6000, 'thumbnail_max_dimension' => 320,
        'reservation_ttl_minutes' => 60, 'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
    // Same 4,000-character bound as activity descriptions; bounded offline/detail payloads.
    'activity_notes' => ['max_length' => 4000, 'max_count' => 100],
    'automation' => ['enabled' => false, 'batch_size' => 100, 'max_batch_size' => 250, 'max_duration_seconds' => 15],
    'sla' => ['batch_size' => 100, 'max_batch_size' => 250, 'max_duration_seconds' => 15],
    'notifications' => ['max_event_batch' => 250, 'max_recipient_batch' => 500, 'max_duration_seconds' => 15],
    'pagination' => ['default' => 50, 'max' => 100],
    'rate_limits' => ['read_per_minute' => 120, 'write_per_minute' => 30, 'upload_per_minute' => 10],
    'integration_scopes' => [
        'support.tickets.read', 'support.tickets.create', 'support.comments.create',
        'support.tickets.assign', 'support.tickets.transition', 'support.tickets.resolve',
        'support.tickets.manage', 'support.evidence.read', 'support.evidence.download',
    ],
];
