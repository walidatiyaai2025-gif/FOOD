<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

final class EnterpriseOidcService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AdminSecurityPolicy $policy,
    ) {}

    public function authorizationUrl(Request $request): string
    {
        $this->assertReady();
        $state = Str::random(64);
        $request->session()->put('security_sso_state', hash('sha256', $state));

        return (string) config('security.sso.authorization_endpoint').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('security.sso.client_id'),
            'redirect_uri' => route('admin.sso.callback'),
            'scope' => (string) config('security.sso.scopes', 'openid profile email'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{sub:string,email:?string,email_verified:bool} */
    public function identity(Request $request): array
    {
        $this->assertReady();
        $state = (string) $request->query('state', '');
        $expected = (string) $request->session()->pull('security_sso_state', '');
        abort_unless($state !== '' && $expected !== '' && hash_equals($expected, hash('sha256', $state)), 403);

        $code = trim((string) $request->query('code', ''));
        abort_if($code === '', 422);

        $tokenResponse = $this->http->asForm()
            ->acceptJson()
            ->timeout(10)
            ->post((string) config('security.sso.token_endpoint'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('admin.sso.callback'),
                'client_id' => (string) config('security.sso.client_id'),
                'client_secret' => (string) config('security.sso.client_secret'),
            ]);

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('Enterprise SSO token exchange failed.');
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Enterprise SSO did not return an access token.');
        }

        $userInfo = $this->http->withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->get((string) config('security.sso.userinfo_endpoint'));

        if (! $userInfo->successful()) {
            throw new RuntimeException('Enterprise SSO user info lookup failed.');
        }

        $subject = $userInfo->json('sub');
        if (! is_string($subject) || trim($subject) === '') {
            throw new RuntimeException('Enterprise SSO identity is missing a subject.');
        }

        $email = $userInfo->json('email');

        return [
            'sub' => trim($subject),
            'email' => is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null,
            'email_verified' => filter_var($userInfo->json('email_verified', false), FILTER_VALIDATE_BOOL),
        ];
    }

    private function assertReady(): void
    {
        $readiness = $this->policy->ssoReadiness();
        abort_unless($readiness['enabled'] && $readiness['configured'], 404);
    }
}
