<?php

$methods = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FOODEX_PAYMENT_METHODS', 'cash_on_delivery')),
)));

return [
    'payment_methods' => $methods === [] ? ['cash_on_delivery'] : $methods,
    'default_payment_method' => (string) env('FOODEX_DEFAULT_PAYMENT_METHOD', 'cash_on_delivery'),
    'delivery_fee' => (float) env('FOODEX_DELIVERY_FEE', 0),
];
