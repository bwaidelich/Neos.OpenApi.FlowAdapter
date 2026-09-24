<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter;

use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\OpenApi\ApiDefinition;
use Neos\OpenApi\Compilation\ApiCompiler;
use Neos\OpenApi\Compilation\CompiledApi;
use Neos\OpenApi\Exception\InvalidApiDefinitionException;
use Neos\OpenApi\Http\RequestHandler;
use Neos\OpenApi\Spec\InfoObject;
use Neos\OpenApi\Spec\ServerObject;
use Neos\OpenApi\Spec\ServerObjects;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The APIs configured in `Neos.OpenApi.FlowAdapter.apis`, compiled on first use and kept for the rest of the request
 *
 * Shared by the routes provider, the controller and the middleware, so all of them work off the very same compilation
 * (it is a singleton, see Objects.yaml)
 */
final class CompiledApis
{
    /**
     * @var array<string, CompiledApi>
     */
    private array $compiledApis = [];

    /**
     * @param array<mixed> $apiSettings the `Neos.OpenApi.FlowAdapter.apis` settings, by API name
     */
    public function __construct(
        private readonly array $apiSettings,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->apiSettings));
    }

    public function get(string $apiName): CompiledApi
    {
        return $this->compiledApis[$apiName] ??= $this->compile($apiName);
    }

    /**
     * The URI path the API is served under, relative to the base URI and without leading or trailing slashes
     */
    public function uriPrefix(string $apiName): string
    {
        return trim((string)($this->settings($apiName)['uriPrefix'] ?? ''), '/');
    }

    /**
     * The URI path the API's OpenAPI specification is served under, relative to its URI prefix – or null if it isn't
     */
    public function specPath(string $apiName): string|null
    {
        $specPath = trim((string)($this->settings($apiName)['specPath'] ?? ''), '/');
        return $specPath !== '' ? $specPath : null;
    }

    /**
     * The URI path a Swagger UI for the API is served under, relative to its URI prefix – or null if it isn't
     *
     * Requires a {@see specPath()}, since that is what the Swagger UI loads
     */
    public function docsPath(string $apiName): string|null
    {
        $docsPath = trim((string)($this->settings($apiName)['docsPath'] ?? ''), '/');
        if ($docsPath === '') {
            return null;
        }
        if ($this->specPath($apiName) === null) {
            throw new \InvalidArgumentException(sprintf('The API "%s" has a docsPath but no specPath, which the Swagger UI needs to load the OpenAPI specification from', $apiName), 1790000002);
        }
        return $docsPath;
    }

    public function requestHandler(string $apiName): RequestHandler
    {
        return new RequestHandler($this->get($apiName), $this->objectManager, $this->responseFactory, $this->streamFactory, $this->authContextProvider($apiName));
    }

    private function compile(string $apiName): CompiledApi
    {
        try {
            return (new ApiCompiler())->compile($this->definition($apiName));
        } catch (InvalidApiDefinitionException $exception) {
            // an operation names a security scheme the definition does not declare – and the schemes come from the
            // authContextProvider, so that is where to point rather than at ApiDefinition::create()
            if ($exception->getCode() !== 1783500335) {
                throw $exception;
            }
            $authContextProviderName = $this->settings($apiName)['authContextProvider'] ?? null;
            throw new \InvalidArgumentException(
                $authContextProviderName === null
                    ? sprintf('The API "%s" has operations requiring authentication, but no "authContextProvider" is configured to authenticate them', $apiName)
                    : sprintf('An operation of the API "%s" names a security scheme its authContextProvider "%s" does not declare', $apiName, $authContextProviderName),
                1790000005,
                $exception,
            );
        }
    }

    private function definition(string $apiName): ApiDefinition
    {
        $settings = $this->settings($apiName);
        $definition = ApiDefinition::create(
            info: new InfoObject(
                $settings['info']['title'] ?? $apiName,
                version: (string)($settings['info']['version'] ?? '1.0.0'),
            ),
            servers: $this->servers($apiName),
            securitySchemes: $this->authContextProvider($apiName)?->securitySchemes(),
        );
        foreach ($settings['classes'] ?? [] as $className => $enabled) {
            if ($enabled !== true) {
                continue;
            }
            $definition = $definition->withOperationsFrom($className);
        }
        return $definition;
    }

    /**
     * The servers configured for the API – or, if there are none, the one it is routed to: the document's paths
     * don't include the URI prefix, so without it a client would miss it
     */
    private function servers(string $apiName): ServerObjects
    {
        $servers = [];
        foreach ($this->settings($apiName)['servers'] ?? [] as $server) {
            if (!is_array($server) || !isset($server['url'])) {
                continue;
            }
            $servers[] = new ServerObject((string)$server['url'], isset($server['description']) ? (string)$server['description'] : null);
        }
        if ($servers === []) {
            $servers[] = new ServerObject('/' . $this->uriPrefix($apiName));
        }
        return new ServerObjects(...$servers);
    }

    /**
     * The object configured to authenticate the API's operations – or null if there is none, in which case none of
     * them may require authentication
     */
    private function authContextProvider(string $apiName): AuthContextProviderWithSchemes|null
    {
        $objectName = $this->settings($apiName)['authContextProvider'] ?? null;
        if ($objectName === null) {
            return null;
        }
        if (!is_string($objectName) || !$this->objectManager->isRegistered($objectName)) {
            throw new \InvalidArgumentException(sprintf('The authContextProvider of the API "%s" is not a known object name', $apiName), 1790000003);
        }
        $authContextProvider = $this->objectManager->get($objectName);
        if (!$authContextProvider instanceof AuthContextProviderWithSchemes) {
            throw new \InvalidArgumentException(sprintf('The authContextProvider "%s" of the API "%s" does not implement %s', $objectName, $apiName, AuthContextProviderWithSchemes::class), 1790000004);
        }
        return $authContextProvider;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(string $apiName): array
    {
        $settings = $this->apiSettings[$apiName] ?? null;
        if (!is_array($settings)) {
            throw new \InvalidArgumentException(sprintf('No API "%s" is configured in "Neos.OpenApi.FlowAdapter.apis"', $apiName), 1790000001);
        }
        return $settings;
    }
}
