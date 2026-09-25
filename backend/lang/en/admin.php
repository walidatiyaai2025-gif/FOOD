<?php

return [
    'title' => 'FOODEX Management Dashboard',
    'management_system' => 'Unified management system',
    'navigation' => 'Navigation',
    'overview' => 'Overview',
    'shell_ready' => 'Management shell is ready',
    'shell_description' => 'This is the shared routing and layout layer. Product, order and reporting screens are implemented in their own issues without creating separate admin applications.',
    'authorization_boundary' => 'UI visibility is not authorization; every real operation must continue to enforce permissions and store scope on the server.',
    'channels' => [
        'b2b' => 'B2B Wholesale',
        'b2b_description' => 'Wholesale operations inside the single management dashboard.',
        'b2c' => 'B2C Retail',
        'b2c_description' => 'Authorized retail-store operations inside the single management dashboard.',
    ],
    'b2c_workspace' => ['title'=>'B2C Retail Management','assigned_scope'=>'Authorized store scope','authoritative'=>'Data and operations remain server-authoritative and permission scoped','empty_hint'=>'Available records appear here; empty states remain explicit when no records exist.','modules'=>['dashboard'=>'Dashboard','products'=>'Products','inventory'=>'Inventory','orders'=>'Orders','customers'=>'Customers','promotions'=>'Promotions','drivers'=>'Drivers & Delivery','storefront'=>'Storefront Preview','content'=>'Content & Banners','reports'=>'Reports','settings'=>'Settings']],
];
