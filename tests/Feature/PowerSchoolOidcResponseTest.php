<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PowerSchoolOidcController;
use App\Models\User;
use GrantHolle\PowerSchool\Auth\UserFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesApplication;

class PowerSchoolOidcResponseTest extends TestCase
{
    use CreatesApplication;

    private function makeCallback(array $input = []): Request
    {
        $session = app('session.store');
        $session->start();
        $session->put('_token', 'synthetic-state');
        $session->put('ps_oidc_nonce', 'synthetic-nonce');
        $request = Request::create('/auth/powerschool/oidc', 'GET', array_replace([
            'state' => 'synthetic-state', 'code' => 'synthetic-code',
        ], $input));
        $request->setLaravelSession($session);

        return $request;
    }

    private function provider(mixed $body, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://school.example.test/oauth2/.well-known/openid-configuration' => Http::response([
                'token_endpoint' => 'https://school.example.test/token',
            ]),
            'https://school.example.test/token' => Http::response($body, $status),
        ]);
    }

    private function denied(Request $request, int $status): void
    {
        try {
            (new OidcResponseFixture)->login($request);
            $this->fail('Invalid callback was accepted.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
        $this->assertGuest();
    }

    public function test_invalid_state_and_missing_code_never_contact_provider(): void
    {
        foreach ([['state' => []], ['state' => 'wrong'], ['state' => ''], ['code' => []], ['code' => ''], ['error' => 'access_denied']] as $input) {
            Http::fake();
            $this->denied($this->makeCallback($input), 403);
            Http::assertNothingSent();
        }
    }

    public function test_null_html_error_and_malformed_tokens_are_rejected(): void
    {
        foreach (['', '<html>Login</html>', 'null', ['error' => 'invalid_grant'], [], ['id_token' => []], ['id_token' => 'bad'], ['id_token' => 'a.!!!!.c'], ['id_token' => 'a.'.base64_encode('null').'.c'], ['id_token' => 'a.'.base64_encode('{"nonce":"wrong"}').'.c']] as $body) {
            $this->provider($body);
            $request = $this->makeCallback();
            $this->denied($request, 403);
            $this->assertSame('synthetic-nonce', $request->session()->get('ps_oidc_nonce'));
            Http::assertSentCount(2);
        }
    }

    public function test_http_failure_status_is_not_treated_as_successful_json(): void
    {
        foreach ([302 => 502, 400 => 403, 500 => 502] as $status => $expected) {
            $this->provider(['id_token' => 'a.'.base64_encode('{"nonce":"synthetic-nonce","persona":"staff"}').'.c'], $status);
            $this->denied($this->makeCallback(), $expected);
        }
    }

    public function test_unavailable_discovery_stops_before_token_exchange(): void
    {
        foreach (['<html>Login</html>', ['token_endpoint' => null], ['token_endpoint' => 'http://school.example.test/token']] as $body) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($body)]);
            $this->denied($this->makeCallback(), 502);
            Http::assertSentCount(1);
        }
    }

    public function test_discovery_failure_can_be_retried_with_the_original_nonce(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection()]);
        $request = $this->makeCallback();
        $this->denied($request, 502);
        $this->assertSame('synthetic-nonce', $request->session()->get('ps_oidc_nonce'));

        $this->provider(['id_token' => 'a.'.base64_encode('{"nonce":"synthetic-nonce","persona":"staff"}').'.c']);
        config(['powerschool-auth.staff.allowed' => false]);
        $this->assertSame(403, (new OidcResponseFixture)->login($request)->getStatusCode());
        $this->assertFalse($request->session()->has('ps_oidc_nonce'));
    }

    public function test_https_scheme_is_case_insensitive(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://school.example.test/oauth2/.well-known/openid-configuration' => Http::response(['token_endpoint' => 'HTTPS://school.example.test/token']),
            '*' => Http::response('null'),
        ]);
        $this->denied($this->makeCallback(), 403);
        Http::assertSentCount(2);
    }

    public function test_discovery_follows_an_https_canonical_redirect_without_network_access(): void
    {
        Http::swap(new Factory);
        $handler = new MockHandler([
            new Response(302, ['Location' => 'https://school.example.test/discovery']),
            new Response(200, ['Content-Type' => 'application/json'], '{"token_endpoint":"https://school.example.test/token"}'),
            new Response(200, ['Content-Type' => 'application/json'], 'null'),
        ]);
        Http::globalOptions(['handler' => HandlerStack::create($handler)]);
        $this->denied($this->makeCallback(), 403);
        $this->assertSame(0, $handler->count());
    }

    public function test_untrusted_and_malformed_redirects_stop_before_credentials_are_sent(): void
    {
        foreach (['https://other.example.test/discovery', 'https://school.example.test:99999/discovery', 'http://school.example.test/discovery'] as $location) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response('', 302, ['Location' => $location])]);
            $this->denied($this->makeCallback(), 502);
            Http::assertSentCount(1);
        }
    }

    public function test_foreign_token_endpoint_never_receives_credentials(): void
    {
        foreach (['https://other.example.test/token', 'https://school.example.test:444/token', 'https://name:password@school.example.test/token'] as $endpoint) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['token_endpoint' => $endpoint])]);
            $this->denied($this->makeCallback(), 502);
            Http::assertSentCount(1);
        }
    }

    public function test_initiation_uses_the_same_discovery_validation(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('', 302, ['Location' => '/canonical-discovery'])
            ->push(['token_endpoint' => 'https://school.example.test/token', 'authorization_endpoint' => 'https://school.example.test/authorize', 'scopes_supported' => ['openid']]);
        $this->assertSame(['openid'], (new OidcResponseFixture)->discovery()['scopes_supported']);
        Http::assertSentCount(2);
    }

    public function test_legacy_http_configuration_uses_https_discovery(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://school.example.test/*' => Http::response([
            'token_endpoint' => 'https://school.example.test/token',
            'authorization_endpoint' => 'https://school.example.test/authorize',
            'scopes_supported' => ['openid'],
        ])]);
        $controller = new class extends OidcResponseFixture
        {
            protected function getPowerSchoolUrl(): string
            {
                return 'http://school.example.test';
            }
        };
        $this->assertSame(['openid'], $controller->discovery()['scopes_supported']);
        Http::assertSentCount(1);
    }

    public function test_valid_response_preserves_user_factory_guard_and_login_response(): void
    {
        $this->provider(['id_token' => 'a.'.base64_encode('{"nonce":"synthetic-nonce","persona":"staff","sub":"synthetic-user"}').'.c']);
        config(['powerschool-auth.staff.allowed' => true, 'powerschool-auth.staff.guard' => 'web',
            'powerschool-auth.staff.model' => User::class]);
        $user = \Mockery::mock(User::class);
        $user->shouldReceive('forceFill')->once()->andReturnSelf();
        $user->shouldReceive('save')->once()->andReturn(true);
        UserFactory::findUserUsing(fn () => $user);
        $guard = \Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('login')->once()->with($user, true);
        Auth::shouldReceive('guard')->once()->with('web')->andReturn($guard);
        $request = $this->makeCallback();
        $request->session()->put('ps_oidc_remember', true);
        try {
            $response = (new OidcResponseFixture)->login($request);
            $this->assertSame('/verified-login', $response->getTargetUrl());
            Http::assertSentCount(2);
            Http::assertSent(fn ($request) => $request->url() === 'https://school.example.test/token'
                && $request['code'] === 'synthetic-code' && $request->hasHeader('Accept', 'application/json'));
        } finally {
            $property = new \ReflectionProperty(UserFactory::class, 'findUserResolver');
            $property->setValue(null, null);
        }
    }

    public function test_valid_claims_preserve_persona_denial_and_nonce_is_single_use(): void
    {
        $this->provider(['id_token' => 'a.'.base64_encode('{"nonce":"synthetic-nonce","persona":"staff"}').'.c']);
        config(['powerschool-auth.staff.allowed' => false]);
        $request = $this->makeCallback();
        $this->assertSame(403, (new OidcResponseFixture)->login($request)->getStatusCode());
        $this->denied($request, 403);
        Http::assertSentCount(2);
    }
}

class OidcResponseFixture extends PowerSchoolOidcController
{
    public function discovery(): array
    {
        return $this->getOidcConfiguration();
    }

    protected function getDefaultAttributes(Request $request, Collection $data): array
    {
        return [];
    }

    protected function sendLoginResponse(Request $request, Authenticatable $user, Collection $data): RedirectResponse
    {
        return new RedirectResponse('/verified-login');
    }

    protected function getPowerSchoolUrl(): string
    {
        return 'https://school.example.test';
    }

    protected function getClientId(): string
    {
        return 'synthetic-client';
    }

    public function getClientSecret(): string
    {
        return 'synthetic-secret';
    }
}
