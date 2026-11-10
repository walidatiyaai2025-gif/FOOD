<?php

return [
    'description' => 'Edit the role’s real page permissions. Each checkbox changes a canonical RBAC Permission Code; it cannot bypass route, scope, tenant or module guards.',
    'scope_global' => 'Global / Wholesale',
    'scope_store' => 'Store / Retail',
    'scope_note' => 'Shared page permissions appear once. Unrelated role grants are preserved; server-side scope, readiness and entitlement guards remain authoritative.',
    'permission_code' => 'Page permission:',
    'shared_code' => 'Permission shared with another page',
    'missing_code' => 'Permission not registered',
    'no_explicit_code' => 'No independent permission on this item; its real route authorization still applies.',
    'hidden_by_preference' => 'Hidden by an existing sidebar preference',
    'read_only' => 'This is a protected built-in role or beyond your grant authority. Edit an approved custom role clone in Security instead.',
    'save' => 'Save role permissions',
    'confirm' => 'Changing this role affects every assigned user. Continue?',
];
