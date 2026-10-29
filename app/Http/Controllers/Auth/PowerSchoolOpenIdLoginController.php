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
    }

    public function authenticate(Request $request)
    {
        // The legacy discovery library treats unknown URL schemes as hostnames.
        // Reject invalid input before it can initiate an outbound request.
        abort_if(Validator::make($request->only('openid_identifier'), [
            'openid_identifier' => ['required', 'string', 'max:2048', 'url:http,https'],
        ])->fails(), 422, 'A valid HTTP or HTTPS PowerSchool OpenID identifier is required.');

        return $this->authenticateWithOpenId($request);
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
