<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOpenId;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class PowerSchoolOpenIdLoginController extends Controller
{
    use AuthenticatesUsingPowerSchoolWithOpenId {
        authenticate as private authenticateWithOpenId;
        authVerified as protected verifyOpenIdAssertion;
    }

    public function authenticate(Request $request)
    {
        // The legacy discovery library treats unknown URL schemes as hostnames.
        // Reject invalid input before it can initiate an outbound request.
        abort_unless($this->validOpenIdIdentifiers($request, ['openid_identifier']), 422, 'A valid HTTP or HTTPS PowerSchool OpenID identifier is required.');

        return $this->authenticateWithOpenId($request);
    }

    protected function authVerified(Request $request): bool
    {
        // The legacy verifier normalizes claimed_id before checking the mode.
        // Cancelled and incomplete callbacks must never reach that normalization.
        if ($request->input('openid_mode') !== 'id_res') {
            return false;
        }

        // Keep the library's unsolicited assertions and OpenID 1.1 support:
        // a missing identity falls back to claimed_id; 1.1 need not send claimed_id.
        $fields = $request->input('openid_identity') === null ? [] : ['openid_identity'];
        if ($fields === [] || $request->has('openid_ns') || $request->has('openid_claimed_id')) {
            $fields[] = 'openid_claimed_id';
        }
        if (! $this->validOpenIdIdentifiers($request, $fields)) {
            return false;
        }

        return $this->verifyOpenIdAssertion($request);
    }

    private function validOpenIdIdentifiers(Request $request, array $fields): bool
    {
        return Validator::make($request->only($fields), array_fill_keys(
            $fields, ['required', 'string', 'max:2048', 'url:http,https']
        ))->passes();
    }

    /**
     * The user has been authenticated.
     *
     * @return mixed
     */
    protected function authenticated(Request $request, User $user, Collection $data)
    {
        $adminSchools = $data->get('adminSchools', []);

        if (! empty($adminSchools)) {
            $schools = School::whereIn('school_number', $adminSchools)
                ->pluck('id');
            $user->schools()->syncWithoutDetaching($schools);
            $user->assign('staff');
        }

        if ($schoolId = $data->get('schoolID')) {
            $school = School::where('school_number', $schoolId)
                ->first();
            $user->school_id = $school->id;
        }

        if ($data->get('usertype') === 'guardian') {
            // Get the contact id if there isn't one set
            $user->setContactId()
                ->syncStudents()
                ->setSchool()
                ->assign('contact');
        }

        $user->save();
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
