<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\OpenApi\FlowAdapter\CompiledApis;
use Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures\AccountApi;
use Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures\BasicAuthContextProvider;
use Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures\BearerAuthContextProvider;
use Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures\PingApi;
use Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures\PlainAuthContextProvider;
use PHPUnit\Framework\TestCase;

final class CompiledApisTest extends TestCase
{
    /**
     * @param array<string, mixed> $apiSettings
     */
    private static function compiledApis(array $apiSettings): CompiledApis
    {
        $objects = [
            AccountApi::class => new AccountApi(),
            PingApi::class => new PingApi(),
            BearerAuthContextProvider::class => new BearerAuthContextProvider(),
            BasicAuthContextProvider::class => new BasicAuthContextProvider(),
            PlainAuthContextProvider::class => new PlainAuthContextProvider(),
        ];
        $objectManager = self::createStub(ObjectManagerInterface::class);
        $objectManager->method('isRegistered')->willReturnCallback(static fn(string $objectName): bool => isset($objects[$objectName]));
        $objectManager->method('has')->willReturnCallback(static fn(string $objectName): bool => isset($objects[$objectName]));
        $objectManager->method('get')->willReturnCallback(static fn(string $objectName): object => $objects[$objectName]);
        $httpFactory = new HttpFactory();
        return new CompiledApis($apiSettings, $objectManager, $httpFactory, $httpFactory);
    }

    /**
     * @param class-string $apiClassName
     * @return array<string, mixed>
     */
    private static function apiSettings(string $apiClassName, string|null $authContextProvider = null): array
    {
        $settings = [
            'uriPrefix' => 'api',
            'info' => ['title' => 'Test', 'version' => '1.0.0'],
            'classes' => [$apiClassName => true],
        ];
        if ($authContextProvider !== null) {
            $settings['authContextProvider'] = $authContextProvider;
        }
        return ['test' => $settings];
    }

    public function testTheSchemesOfTheAuthContextProviderAreDeclaredInTheDocument(): void
    {
        $compiledApi = self::compiledApis(self::apiSettings(AccountApi::class, BearerAuthContextProvider::class))->get('test');

        $securitySchemes = $compiledApi->document->components?->securitySchemes;
        self::assertNotNull($securitySchemes);
        self::assertSame(['bearerAuth'], $securitySchemes->names());
    }

    public function testTheAuthContextProviderHandsTheCallerToTheOperation(): void
    {
        $requestHandler = self::compiledApis(self::apiSettings(AccountApi::class, BearerAuthContextProvider::class))->requestHandler('test');

        $response = $requestHandler->handleOperation(new ServerRequest('GET', '/api/me', ['Authorization' => 'Bearer secret']), 'me', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"ada"', (string)$response->getBody());
    }

    public function testARequestTheAuthContextProviderFindsNoCallerForIsUnauthorized(): void
    {
        $requestHandler = self::compiledApis(self::apiSettings(AccountApi::class, BearerAuthContextProvider::class))->requestHandler('test');

        $response = $requestHandler->handleOperation(new ServerRequest('GET', '/api/me'), 'me', []);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAnOperationRequiringAuthenticationWithoutAnAuthContextProviderFailsLoudly(): void
    {
        $compiledApis = self::compiledApis(self::apiSettings(AccountApi::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1790000005);
        $this->expectExceptionMessage('The API "test" has operations requiring authentication, but no "authContextProvider" is configured');
        $compiledApis->get('test');
    }

    public function testAnOperationNamingASchemeTheAuthContextProviderDoesNotDeclareFailsLoudly(): void
    {
        $compiledApis = self::compiledApis(self::apiSettings(AccountApi::class, BasicAuthContextProvider::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1790000005);
        $this->expectExceptionMessage(sprintf('its authContextProvider "%s" does not declare', BasicAuthContextProvider::class));
        $compiledApis->get('test');
    }

    public function testAnUnknownAuthContextProviderIsRejected(): void
    {
        $compiledApis = self::compiledApis(self::apiSettings(AccountApi::class, 'Some\Unknown\Provider'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1790000003);
        $compiledApis->get('test');
    }

    public function testAnAuthContextProviderDeclaringNoSchemesIsRejected(): void
    {
        $compiledApis = self::compiledApis(self::apiSettings(AccountApi::class, PlainAuthContextProvider::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1790000004);
        $compiledApis->get('test');
    }

    public function testAnAuthContextProviderIsAllowedForAnApiWithoutSecuredOperations(): void
    {
        $compiledApi = self::compiledApis(self::apiSettings(PingApi::class, BearerAuthContextProvider::class))->get('test');

        self::assertSame(['bearerAuth'], $compiledApi->document->components?->securitySchemes?->names());
    }

    public function testAnApiWithoutSecurityNeedsNoAuthContextProvider(): void
    {
        $compiledApis = self::compiledApis(self::apiSettings(PingApi::class));

        self::assertNull($compiledApis->get('test')->document->components);
        $response = $compiledApis->requestHandler('test')->handleOperation(new ServerRequest('GET', '/api/ping'), 'ping', []);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"pong"', (string)$response->getBody());
    }
}
