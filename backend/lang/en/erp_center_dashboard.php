<?php

return [
    'summary' => [
        'label' => 'ERP module state summary',
        'available' => 'Available',
        'degraded' => 'Degraded',
        'disabled' => 'Disabled',
        'restricted' => 'Not entitled',
        'setup_required' => 'Setup required',
    ],
    'statuses' => [
        'available' => 'Available',
        'degraded' => 'Degraded',
        'disabled' => 'Disabled',
        'not_entitled' => 'Not entitled',
        'setup_required' => 'Setup required',
    ],
    'status_help' => [
        'available' => 'Open the canonical module overview.',
        'degraded' => 'The module is reachable, but one or more health checks need attention.',
        'disabled' => 'Enable the module before operational use.',
        'not_entitled' => 'Your current role or scope is not entitled to this module.',
        'setup_required' => 'Install or complete module setup before operational use.',
    ],
    'snapshot' => [
        'title' => '30-second ERP snapshot',
        'description' => 'Authorized work, exceptions, reporting failures and module health from the existing FOODEX authorities.',
        'refreshed' => 'Snapshot refreshed :time',
    ],
    'kpis' => [
        'pending_approvals' => [
            'label' => 'Pending approvals',
            'help' => 'Workflow decisions currently visible to you.',
        ],
        'overdue_work' => [
            'label' => 'Overdue work',
            'help' => 'Authorized My Work items past their due date.',
        ],
        'open_exceptions' => [
            'label' => 'Open exceptions',
            'help' => 'Authorized work items currently requiring attention.',
        ],
        'failed_report_jobs' => [
            'label' => 'Failed report/export jobs',
            'help' => 'Failed BI exports or scheduled reports within your reporting scope.',
        ],
        'module_health' => [
            'label' => 'Healthy modules',
            'help' => 'Healthy entitled ERP modules compared with visible module-health authorities.',
        ],
        'unavailable' => '—',
        'open' => 'Open details',
    ],
    'charts' => [
        'work_title' => 'Work and exceptions by module',
        'work_description' => 'Only modules with an authoritative My Work or reporting projection expose totals.',
        'health_title' => 'Module health and state',
        'health_description' => 'Lifecycle, entitlement and runtime health are combined without inventing values for unavailable modules.',
        'work' => 'Work',
        'exceptions' => 'Exceptions',
        'no_authoritative_total' => 'No authoritative total',
        'open_module' => 'Open module',
    ],
    'work' => [
        'title' => 'My Work preview',
        'description' => 'Highest-priority due, overdue and attention items from the existing Action Center projection.',
        'empty' => 'No authorized work is currently waiting for you.',
        'open' => 'Open record',
        'due' => 'Due :date',
        'states' => [
            'pending' => 'Pending',
            'overdue' => 'Overdue',
            'attention' => 'Needs attention',
        ],
    ],
    'activity' => [
        'title' => 'Recent cross-module activity',
        'description' => 'A bounded, scope-aware view of authoritative audit events for modules you can access.',
        'empty' => 'No recent authorized ERP activity is available.',
        'recorded' => 'A recent audited activity was recorded in this module.',
        'by' => 'By :name',
        'open' => 'Open module',
    ],
];

