<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PowerSchoolOpenIdLoginController;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Pear\OpenId\Discover\Discover;
use Pear\OpenId\Discover\DiscoverInterface;
use Pear\OpenId\Exceptions\OpenIdAssociationException;
use Pear\OpenId\OpenId;
use Pear\OpenId\ServiceEndpoint;
use Pear\OpenId\ServiceEndpoints;
use Pear\OpenId\Store\StoreInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesApplication;

class PowerSchoolOpenIdIdentifierTest extends TestCase
{
    use CreatesApplication;

    private array $discoveryOrder;

    private StoreInterface $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discoveryOrder = Discover::$discoveryOrder;
        $this->store = OpenId::getStore();
        $store = \Mockery::mock(StoreInterface::class);
        $store->shouldReceive('getDiscover')->andReturn(false);
        $store->shouldReceive('setDiscover');
        OpenId::setStore($store);
        Discover::$discoveryOrder = [FixtureOpenIdDiscovery::class];
        FixtureOpenIdDiscovery::$identifiers = [];
    }

    protected function tearDown(): void
    {
        Discover::$discoveryOrder = $this->discoveryOrder;
        OpenId::setStore($this->store);
        parent::tearDown();
    }

    public function test_malformed_identifiers_are_rejected_before_discovery(): void
    {
        foreach ([null, '', [], ['https://school.example.org'], 'Bogus://example.invalid/a', 'school.example.org', 'ftp://example.invalid', 'https://', 'https://school.example.org/'.str_repeat('a', 2048)] as $identifier) {
            try {
                app(PowerSchoolOpenIdLoginController::class)->authenticate(
                    Request::create('/auth/powerschool/openid', 'GET', ['openid_identifier' => $identifier])
                );
                $this->fail('Malformed identifier was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }

        $this->assertSame([], FixtureOpenIdDiscovery::$identifiers);
    }

    public function test_valid_identifiers_keep_the_existing_openid_exchange(): void
    {
        foreach (['https://school.example.org/openid/identifier', 'http://school.example.org/openid/identifier'] as $identifier) {
            $response = app(PowerSchoolOpenIdLoginController::class)->authenticate(
                Request::create('https://invoices.example.org/auth/powerschool/openid', 'GET', ['openid_identifier' => $identifier])
            );

            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringStartsWith('https://school.example.org/openid/authorize?', $response->getTargetUrl());
            parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
            $this->assertSame('checkid_setup', $query['openid_mode']);
            $this->assertStringContainsString('/auth/powerschool/openid/verify', $query['openid_return_to']);
            $this->assertStringContainsString('studentids', $query['openid_ax_required']);
        }

        $this->assertCount(2, FixtureOpenIdDiscovery::$identifiers);
    }

    public function test_incomplete_and_cancelled_callbacks_are_denied_before_discovery(): void
    {
        $valid = [
            'openid_mode' => 'id_res',
            'openid_ns' => OpenId::NS_2_0,
            'openid_identity' => 'https://school.example.org/openid/identity',
            'openid_claimed_id' => 'https://school.example.org/openid/identity',
        ];
        $callbacks = [[], ['openid_mode' => 'cancel']];
        foreach ([null, '', [], 'cancel', 'setup_needed', 'error', 'unknown'] as $mode) {
            $callbacks[] = array_replace($valid, ['openid_mode' => $mode]);
        }
        foreach (['openid_identity', 'openid_claimed_id'] as $field) {
            if ($field === 'openid_claimed_id') {
                $missing = $valid;
                unset($missing[$field]);
                $callbacks[] = $missing;
                $callbacks[] = array_replace($valid, [$field => null]);
            }
            foreach (['', [], ['https://school.example.org'], 'ftp://school.example.org', 'https://', 'https://school.example.org/'.str_repeat('a', 2048)] as $value) {
                $callbacks[] = array_replace($valid, [$field => $value]);
            }
        }

        foreach ($callbacks as $callback) {
            try {
                app(PowerSchoolOpenIdLoginController::class)->login(
                    Request::create('/auth/powerschool/openid/verify', 'GET', $callback)
                );
                $this->fail('Invalid callback was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame([], FixtureOpenIdDiscovery::$identifiers);
    }

    public function test_well_formed_callback_preserves_the_assertion_verifiers_result(): void
    {
        $identity = 'https://school.example.org/openid/identity';
        foreach ([
            ['openid.ns' => OpenId::NS_2_0, 'openid.identity' => $identity, 'openid.claimed_id' => $identity],
            ['openid.identity' => $identity], // OpenID 1.1
            ['openid.ns' => OpenId::NS_2_0, 'openid.claimed_id' => $identity], // Unsolicited
        ] as $fields) {
            $query = http_build_query(['openid.mode' => 'id_res'] + $fields);
            $request = Request::create('/auth/powerschool/openid/verify?'.$query);
            foreach ([true, false] as $verified) {
                $controller = \Mockery::mock(PowerSchoolOpenIdLoginController::class)->makePartial()
                    ->shouldAllowMockingProtectedMethods();
                $controller->shouldReceive('verifyOpenIdAssertion')->once()->with($request)->andReturn($verified);
                $method = new \ReflectionMethod(PowerSchoolOpenIdLoginController::class, 'authVerified');
                $this->assertSame($verified, $method->invoke($controller, $request));
            }
        }
    }

    public function test_well_formed_identifiers_do_not_bypass_real_assertion_validation(): void
    {
        $query = http_build_query([
            'openid.mode' => 'id_res',
            'openid.identity' => 'https://school.example.org/openid/identity',
            'openid.claimed_id' => 'https://school.example.org/openid/identity',
            'openid.return_to' => 'invalid',
        ]);
        $request = Request::create('https://invoices.example.org/auth/powerschool/openid/verify?'.$query);

        $this->expectException(OpenIdAssociationException::class);
        $this->expectExceptionMessage('openid.return_to parameter is invalid or missing');
        app(PowerSchoolOpenIdLoginController::class)->login($request);
    }
}

// Exercise the real relying-party flow without performing any external HTTP requests.
class FixtureOpenIdDiscovery implements DiscoverInterface
{
    public static array $identifiers = [];

    public function __construct(private string $identifier)
    {
        self::$identifiers[] = $identifier;
    }

    public function setRequestOptions(array $options) {}

    public function discover()
    {
        $endpoint = new ServiceEndpoint;
        $endpoint->setURIs(['https://school.example.org/openid/authorize']);
        $endpoint->setVersion(OpenId::SERVICE_2_0_SERVER);

        return new ServiceEndpoints($this->identifier, $endpoint);
    }
}
