<?php
/// Cấu hình cho VNPAY
return [
    'tmn_code'    => env('VNPAY_TMN_CODE', env('VNP_TMN_CODE')),
    'hash_secret' => env('VNPAY_HASH_SECRET', env('VNP_HASH_SECRET')),
    'url'         => env('VNPAY_URL', env('VNP_URL')),
    'return_url'  => env('VNPAY_RETURN_URL', env('VNP_RETURN_URL')),
];
