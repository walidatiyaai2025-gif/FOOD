<?php
return [
    'purposes'=>['otp','auth','order_notification','dispatch','marketing','system'],
    'rate_limits'=>['otp_per_minute'=>5,'default_per_minute'=>30],
    'templates'=>[
        'otp'=>[
            'ar'=>'رمز التحقق الخاص بك في FOODEX هو :code. لا تشارك هذا الرمز مع أي شخص.',
            'en'=>'Your FOODEX verification code is :code. Do not share this code with anyone.',
        ],
        'order_status'=>[
            'ar'=>'تم تحديث حالة طلبك :order إلى :status.',
            'en'=>'Your order :order status is now :status.',
        ],
        'dispatch'=>[
            'ar'=>'تم تحديث مهمة التوصيل للطلب :order.',
            'en'=>'Delivery assignment for order :order has been updated.',
        ],
        'system'=>['ar'=>':message','en'=>':message'],
    ],
];
