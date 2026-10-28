<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant->update(['allow_password_auth' => true]);
    }

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $this->withoutExceptionHandling();
        $user = $this->createUser();

        $this->post('/login', [
            'email' => strtoupper($user->email),
            'password' => 'password',
        ])
            ->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticated();

        $activity = Activity::first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('successfully', Activity::first()->description);
        $this->assertEquals('auth', $activity->log_name);
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = $this->createUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors();

        $this->assertGuest();

        $activity = Activity::first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('failed', Activity::first()->description);
        $this->assertEquals('auth', $activity->log_name);
    }

    public static function passwordlessRememberCookies(): array
    {
        return [
            'legacy empty password hash' => ['legacy', true],
            'current password hash' => ['current', true],
            'stale password hash' => ['stale', false],
            'mismatched remember token' => ['wrong-token', false],
        ];
    }

    #[DataProvider('passwordlessRememberCookies')]
    public function test_passwordless_sso_remember_cookies_are_handled(string $format, bool $authenticated): void
    {
        $user = $this->createUser(['password' => null]);
        $guard = Auth::guard('web');
        $hash = match ($format) {
            'legacy' => '',
            'current', 'wrong-token' => $guard->hashPasswordForCookie(''),
            'stale' => 'stale-password-hash',
        };
        $token = $format === 'wrong-token' ? 'unrelated-token' : $user->getRememberToken();
        $guard->setRequest(Request::create('/', 'GET', [], [
            $guard->getRecallerName() => $user->getAuthIdentifier().'|'.$token.'|'.$hash,
        ]));

        $this->assertSame($authenticated ? $user->uuid : null, $guard->user()?->uuid);
    }
}
