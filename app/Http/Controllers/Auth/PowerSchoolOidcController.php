<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOidc;
use GrantHolle\PowerSchool\Auth\UserFactory;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class PowerSchoolOidcController extends Controller
{
    use AuthenticatesUsingPowerSchoolWithOidc {
        authenticate as traitAuthenticate;
    }

    protected function getPowerSchoolUrl(): string
    {
        return Tenant::current()->ps_url;
    }

    protected function getClientId(): string
    {
        return Tenant::current()->ps_client_id;
    }

    public function getClientSecret(): string
    {
        return Tenant::current()->ps_secret;
    }

    public function authenticate(Request $request)
    {
        $tenant = $request->tenant();

        if ($tenant->allow_oidc_login) {
            return $this->traitAuthenticate($request);
        }

        return redirect($tenant->ps_url);
    }

    protected function isHttpsUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    protected function isTrustedPowerSchoolUrl(mixed $url): bool
    {
        if (! $this->isHttpsUrl($url)) {
            return false;
        }
        $parts = parse_url($url);
        $trusted = parse_url($this->getPowerSchoolUrl());

        return is_array($trusted)
            && strtolower($parts['host'] ?? '') === strtolower($trusted['host'] ?? '')
            && ($parts['port'] ?? 443) === ($trusted['port'] ?? 443);
    }

    /** Use the same bounded, trusted discovery flow for initiation and callback. */
    protected function getOidcConfiguration(?string $key = null)
    {
        // Older tenant settings may still use HTTP even though PowerSchool advertises HTTPS.
        $url = preg_replace('/^http:/i', 'https:', rtrim($this->getPowerSchoolUrl(), '/'))
            .'/oauth2/.well-known/openid-configuration';
        try {
            for ($redirects = 0; $redirects <= 5; $redirects++) {
                abort_unless($this->isTrustedPowerSchoolUrl($url), 502);
                $response = Http::acceptJson()->withoutRedirecting()->timeout(15)->get($url);
                if (! $response->redirect()) {
                    break;
                }
                $location = $response->header('Location');
                abort_unless($location !== '' && $redirects < 5, 502);
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            }
        } catch (ConnectionException|\InvalidArgumentException $exception) {
            abort(502, 'The sign-in provider is unavailable. Please try again.');
        }
        $configuration = $response->json();
        abort_unless($response->successful() && is_array($configuration)
            && $this->isTrustedPowerSchoolUrl($configuration['token_endpoint'] ?? null), 502);
        if ($key !== null) {
            return $configuration[$key] ?? null;
        }
        // Browser authorization may use a separate SSO host; client secrets go only to the trusted token endpoint.
        abort_unless($this->isHttpsUrl($configuration['authorization_endpoint'] ?? null)
            && is_array($configuration['scopes_supported'] ?? null)
            && collect($configuration['scopes_supported'])->every(fn ($scope) => is_string($scope)), 502);

        return $configuration;
    }

    /** Reject incomplete provider responses before decoding claims or creating a user. */
    public function login(Request $request): RedirectResponse|Response
    {
        $state = $request->input('state');
        $expectedState = $request->session()->token();
        abort_unless(is_string($state) && is_string($expectedState) && $expectedState !== ''
            && hash_equals($expectedState, $state), 403);

        $nonce = $request->session()->get('ps_oidc_nonce');
        $code = $request->input('code');
        abort_unless(is_string($nonce) && $nonce !== '' && is_string($code) && $code !== ''
            && ! $request->has('error'), 403);

        try {
            $endpoint = $this->getOidcConfiguration('token_endpoint');

            $response = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(15)
                ->post($endpoint, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->getRedirectUrl(),
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                ]);
        } catch (ConnectionException $exception) {
            abort(502, 'The sign-in provider is unavailable. Please try again.');
        }

        abort_unless($response->successful(), $response->clientError() ? 403 : 502);
        $payload = $response->json();
        abort_unless(is_array($payload) && ! isset($payload['error'])
            && is_string($payload['id_token'] ?? null), 403);
        $parts = explode('.', $payload['id_token']);
        abort_unless(count($parts) === 3 && $parts[1] !== '', 403);
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $decoded === false ? null : json_decode($decoded);
        abort_unless(is_object($claims) && is_string($claims->nonce ?? null)
            && hash_equals($nonce, $claims->nonce), 403);
        $request->session()->forget('ps_oidc_nonce');

        $data = collect($claims);
        $persona = $data->get('usertype', $data->get('persona'));
        abort_unless(is_string($persona) && $persona !== '', 403);
        $userType = UserFactory::getUserType($data);
        if (! config("powerschool-auth.{$userType}.allowed", false)) {
            return $this->sendNotAllowedResponse();
        }

        $user = UserFactory::getUser($data, $this->getDefaultAttributes($request, $data));
        auth()->guard(config("powerschool-auth.{$userType}.guard"))->login($user, $this->remember());

        return $this->sendLoginResponse($request, $user, $data);
    }

    protected function authenticated(Request $request, User $user, Collection $data)
    {
        if ($data->get('persona') === 'staff') {
            $user->setSchoolStaffSchools()
                ->assign('staff');
        }

        if ($data->get('persona') === 'parent') {
            $user->setContactId()
                ->syncStudents()
                ->assign('contact');
        }

        $user->setSchool()
            ->save();
    }

    /**
     * Gets the default attributes to be added for this user
     */
    protected function getDefaultAttributes(Request $request, Collection $data): array
    {
        return [
            'tenant_id' => $request->tenant()->id,
        ];
    }
}
