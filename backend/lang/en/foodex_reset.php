<?php

return [
    'title' => 'FOODEX Reset & Reseed',
    'description' => 'Protected clean-start operation for Customer VNext test and launch preparation.',
    'impact_title' => 'Dry-run impact',
    'impact_description' => 'Rows below are cleared and then rebuilt by the authoritative FOODEX launch seed. Security identity, RBAC, stores, provider credentials and platform configuration are preserved.',
    'total_rows' => 'Rows affected',
    'seed_ready' => 'Launch seed contract available',
    'seed_blocked' => 'Blocked until the authoritative launch seed is available',
    'preserved' => 'Preserved: management identities, roles and permissions, store definitions, provider credentials and platform configuration.',
    'execute_title' => 'Destructive reset',
    'execute_description' => 'This action cannot run until the authoritative launch seed is installed. Production also requires a backup reference and a second typed confirmation.',
    'confirmation_label' => 'Reset confirmation',
    'production_confirmation_label' => 'Production confirmation',
    'backup_reference' => 'Verified backup reference',
    'type_exactly' => 'Type exactly:',
    'execute' => 'Reset and reseed FOODEX',
    'completed' => 'FOODEX data was reset and reseeded from the authoritative launch dataset.',
    'errors' => [
        'confirmation' => 'The reset confirmation does not match.',
        'production_confirmation' => 'The production confirmation does not match.',
        'backup_required' => 'A verified backup reference is required in production.',
        'seed_not_ready' => 'The authoritative FOODEX launch seed is not available yet. Reset is blocked.',
    ],
];
