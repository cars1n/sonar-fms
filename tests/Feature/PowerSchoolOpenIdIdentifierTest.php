<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PowerSchoolOpenIdLoginController;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Pear\OpenId\Discover\Discover;
use Pear\OpenId\Discover\DiscoverInterface;
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
