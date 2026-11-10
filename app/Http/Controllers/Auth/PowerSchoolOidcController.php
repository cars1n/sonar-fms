<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOidc;
use GrantHolle\PowerSchool\Auth\UserFactory;
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
            $discovery = Http::acceptJson()->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]])->timeout(15)
                ->get(rtrim($this->getPowerSchoolUrl(), '/').'/oauth2/.well-known/openid-configuration');
            $endpoint = $discovery->json('token_endpoint');
            abort_unless($discovery->successful() && is_string($endpoint)
                && filter_var($endpoint, FILTER_VALIDATE_URL)
                && strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) === 'https', 502);

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
