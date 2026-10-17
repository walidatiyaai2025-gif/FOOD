<?php

return [
    'mfa' => [
        'issuer' => env('SECURITY_MFA_ISSUER', 'FOODEX'),
        'enforce_privileged' => (bool) env('SECURITY_MFA_ENFORCE_PRIVILEGED', false),
        'period' => 30,
        'digits' => 6,
        'window' => 1,
        'recovery_codes' => 8,
    ],
    'sso' => [
        'enabled' => (bool) env('SECURITY_SSO_ENABLED', false),
        'provider' => env('SECURITY_SSO_PROVIDER', 'enterprise'),
        'authorization_endpoint' => env('SECURITY_SSO_AUTHORIZATION_ENDPOINT'),
        'token_endpoint' => env('SECURITY_SSO_TOKEN_ENDPOINT'),
        'userinfo_endpoint' => env('SECURITY_SSO_USERINFO_ENDPOINT'),
        'client_id' => env('SECURITY_SSO_CLIENT_ID'),
        'client_secret' => env('SECURITY_SSO_CLIENT_SECRET'),
        'scopes' => env('SECURITY_SSO_SCOPES', 'openid profile email'),
        'allow_email_linking' => (bool) env('SECURITY_SSO_ALLOW_EMAIL_LINKING', false),
    ],
    'sessions' => [
        'stale_minutes' => (int) env('SECURITY_SESSION_STALE_MINUTES', 120),
    ],
];
