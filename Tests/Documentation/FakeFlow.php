<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Documentation;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\Dto\RouteContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\Route;
use Neos\Flow\ObjectManagement\Configuration\Configuration;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\OpenApi\FlowAdapter\CompiledApis;
use Neos\OpenApi\FlowAdapter\Controller\OpenApiController;
use Neos\OpenApi\FlowAdapter\Http\MethodNotAllowedMiddleware;
use Neos\OpenApi\FlowAdapter\Routing\OpenApiRoutesProviderFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Just enough of Flow to serve the APIs of the README's settings the way a Flow application would: the routes of
 * the routes provider are matched in order, a match is dispatched to the controller, and a request no route matched
 * passes the "method not allowed" middleware before ending in a 404
 *
 * The Api Classes and the authContextProvider come from a minimal object manager: an object registered with
 * {@see self::withSettings()} is used as it is, any other class is instantiated once, with its constructor arguments
 * autowired by type – just like Flow does
 */
final readonly class FakeFlow
{
    private ObjectManagerInterface $objectManager;
    private CompiledApis $compiledApis;

    /**
     * @param array<mixed> $apiSettings
     * @param array<string, object> $objects
     */
    private function __construct(array $apiSettings, array $objects)
    {
        $httpFactory = new HttpFactory();
        $this->objectManager = self::objectManager($objects);
        $this->compiledApis = new CompiledApis($apiSettings, $this->objectManager, $httpFactory, $httpFactory);
    }

    /**
     * @param array<mixed> $settings the complete settings, as Flow merges them
     * @param array<string, object> $objects instances by object name, for objects with dependencies to inject
     */
    public static function withSettings(array $settings, array $objects = []): self
    {
        $apiSettings = $settings['Neos']['OpenApi']['FlowAdapter']['apis'] ?? [];
        if (!is_array($apiSettings)) {
            throw new \InvalidArgumentException('"Neos.OpenApi.FlowAdapter.apis" must be an array', 1790000901);
        }
        return new self($apiSettings, $objects);
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, array $headers = [], string|null $body = null): ResponseInterface
    {
        return $this->handle(new ServerRequest($method, 'http://localhost' . $uri, $headers, $body, serverParams: ['SCRIPT_NAME' => '/index.php']));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        foreach ($this->routes() as $route) {
            if (!$route->matches(new RouteContext($request, RouteParameters::createEmpty()))) {
                continue;
            }
            $actionRequest = ActionRequest::fromHttpRequest($request->withAttribute(ServerRequestAttributes::ROUTING_RESULTS, $route->getMatchResults()));
            foreach ($route->getMatchResults() ?? [] as $name => $value) {
                // the controller is fixed, and resolving its package key would take Flow's package manager
                if (!str_starts_with((string)$name, '@')) {
                    $actionRequest->setArgument((string)$name, $value);
                }
            }
            return (new OpenApiController($this->compiledApis))->processRequest($actionRequest);
        }
        return (new MethodNotAllowedMiddleware($this->compiledApis))->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        });
    }

    /**
     * The instance of an object, as it is injected into the Api Classes
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function get(string $className): object
    {
        $object = $this->objectManager->get($className);
        if (!$object instanceof $className) {
            throw new \LogicException(sprintf('The object "%s" is not an instance of it', $className), 1790000904);
        }
        return $object;
    }

    /**
     * The names of the routes, in the order `./flow routing:list` lists them
     *
     * @return list<string>
     */
    public function routeNames(): array
    {
        return array_map(static fn(Route $route): string => (string)$route->getName(), $this->routes());
    }

    /**
     * @return list<Route>
     */
    private function routes(): array
    {
        return iterator_to_array((new OpenApiRoutesProviderFactory($this->compiledApis))->createRoutesProvider([])->getRoutes(), false);
    }

    /**
     * @param array<string, object> $objects
     */
    private static function objectManager(array $objects): ObjectManagerInterface
    {
        return new class ($objects) implements ObjectManagerInterface {
            /**
             * @param array<string, object> $objects
             */
            public function __construct(
                private array $objects,
            ) {}

            public function get($objectName, ...$constructorArguments): object
            {
                if (!$this->isRegistered($objectName)) {
                    throw new \InvalidArgumentException(sprintf('Unknown object "%s"', $objectName), 1790000902);
                }
                return $this->objects[$objectName] ??= $this->instantiate($objectName);
            }

            private function instantiate(string $className): object
            {
                if (!class_exists($className)) {
                    throw new \InvalidArgumentException(sprintf('Unknown object "%s"', $className), 1790000905);
                }
                $arguments = [];
                foreach ((new \ReflectionClass($className))->getConstructor()?->getParameters() ?? [] as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                        throw new \InvalidArgumentException(sprintf('The constructor argument "%s" of "%s" cannot be autowired', $parameter->getName(), $className), 1790000906);
                    }
                    $arguments[] = $this->get($type->getName());
                }
                return new $className(...$arguments);
            }

            public function has($objectName): bool
            {
                return $this->isRegistered($objectName);
            }

            public function isRegistered($objectName): bool
            {
                return isset($this->objects[$objectName]) || class_exists($objectName);
            }

            public function getContext(): never
            {
                throw new \BadMethodCallException(__METHOD__, 1790000903);
            }

            public function registerShutdownObject($object, $shutdownLifecycleMethodName): void {}

            public function getCaseSensitiveObjectName($caseInsensitiveObjectName): string|false
            {
                return $caseInsensitiveObjectName;
            }

            public function getObjectNameByClassName($className): string|false
            {
                return $className;
            }

            public function getClassNameByObjectName($objectName): string|false
            {
                return $objectName;
            }

            public function getPackageKeyByObjectName($objectName): string|false
            {
                return false;
            }

            public function getScope($objectName): int
            {
                return Configuration::SCOPE_PROTOTYPE;
            }

            public function setInstance($objectName, $instance): void
            {
                $this->objects[$objectName] = $instance;
            }

            public function forgetInstance($objectName): void
            {
                unset($this->objects[$objectName]);
            }

            public function getSessionInstances(): array
            {
                return [];
            }

            public function shutdown(): void {}
        };
    }
}
