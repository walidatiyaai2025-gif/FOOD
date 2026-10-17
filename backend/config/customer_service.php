<?php

return [
    'sla_hours' => [
        'urgent' => 2,
        'high' => 8,
        'normal' => 24,
        'low' => 48,
    ],
    'priorities' => ['low', 'normal', 'high', 'urgent'],
    'statuses' => ['open', 'pending', 'resolved', 'closed'],
    'categories' => ['complaint', 'delivery', 'billing', 'product', 'account', 'technical', 'other'],
    'queues' => ['general', 'orders', 'finance', 'delivery', 'whatsapp'],
];
