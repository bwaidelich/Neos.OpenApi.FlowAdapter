<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Routing;

use Neos\Flow\Mvc\Routing\Route;
use Neos\Flow\Mvc\Routing\Routes;
use Neos\Flow\Mvc\Routing\RoutesProviderInterface;
use Neos\OpenApi\FlowAdapter\CompiledApis;
use Neos\OpenApi\Support\RelativePath;

/**
 * The routes of every API configured in `Neos.OpenApi.FlowAdapter.apis`
 *
 * One route per operation, all pointing to {@see \Neos\OpenApi\FlowAdapter\Controller\OpenApiController} with the
 * operationId to serve – plus, if the API has a `specPath`, one serving its OpenAPI specification, and if it has a
 * `docsPath`, one serving a Swagger UI for it
 */
final readonly class OpenApiRoutesProvider implements RoutesProviderInterface
{
    public function __construct(
        private CompiledApis $compiledApis,
    ) {}

    public function getRoutes(): Routes
    {
        $routes = [];
        foreach ($this->compiledApis->names() as $apiName) {
            $routes = [...$routes, ...$this->routesFor($apiName)];
        }
        return Routes::create(...$routes);
    }

    /**
     * @return list<Route>
     */
    private function routesFor(string $apiName): array
    {
        $compiledApi = $this->compiledApis->get($apiName);
        $uriPrefix = $this->compiledApis->uriPrefix($apiName);

        $routes = [];
        // ahead of the operations' routes, so that a template of theirs cannot swallow them
        $specPath = $this->compiledApis->specPath($apiName);
        if ($specPath !== null) {
            $routes[] = $this->auxiliaryRoute($apiName, 'OpenAPI specification', $uriPrefix, $specPath, '__spec');
        }
        $docsPath = $this->compiledApis->docsPath($apiName);
        if ($docsPath !== null) {
            $routes[] = $this->auxiliaryRoute($apiName, 'Swagger UI', $uriPrefix, $docsPath, '__docs');
        }
        // the document keeps concrete paths ahead of templates that would also match them, and so do the routes
        foreach ($compiledApi->document->paths ?? [] as $pathValue => $pathObject) {
            $path = RelativePath::fromString($pathValue);
            $uriPattern = $this->uriPattern($apiName, $uriPrefix, $path);
            foreach ($pathObject->allowedMethods() as $method) {
                $entry = $compiledApi->dispatchTable->find($path, $method);
                if ($entry === null) {
                    throw new \LogicException(sprintf('The document describes "%s %s" but the Dispatch Table has no entry for it', $method->value, $path->value), 1790000011);
                }
                $route = new Route();
                $route->setName(sprintf('%s :: %s', $apiName, $entry->operationId));
                $route->setUriPattern($uriPattern);
                $route->setHttpMethods([$method->value]);
                $route->setDefaults(self::defaults($apiName, ['__operation' => $entry->operationId]));
                $routes[] = $route;
            }
        }
        return $routes;
    }

    /**
     * A GET route next to the API's operations, serving something other than one of them
     */
    private function auxiliaryRoute(string $apiName, string $label, string $uriPrefix, string $path, string $internalArgument): Route
    {
        if ($this->compiledApis->get($apiName)->document->paths?->get(RelativePath::fromString('/' . $path)) !== null) {
            throw new \InvalidArgumentException(sprintf('The path "%s" for the %s of API "%s" is already the path of one of its operations', $path, $label, $apiName), 1790000013);
        }
        $route = new Route();
        $route->setName(sprintf('%s :: %s', $apiName, $label));
        $route->setUriPattern(trim($uriPrefix . '/' . $path, '/'));
        $route->setHttpMethods(['GET']);
        $route->setDefaults(self::defaults($apiName, [$internalArgument => 'true']));
        return $route;
    }

    /**
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    private static function defaults(string $apiName, array $defaults): array
    {
        return [
            '@package' => 'Neos.OpenApi.FlowAdapter',
            '@controller' => 'OpenApi',
            '@action' => 'handle',
            '__api' => $apiName,
            ...$defaults,
        ];
    }

    /**
     * The path template as a Flow URI pattern: its variables become dynamic route parts of the same name
     */
    private function uriPattern(string $apiName, string $uriPrefix, RelativePath $path): string
    {
        $unsupported = match (true) {
            str_contains($path->value, '}{') => 'two variables in a row',
            str_contains($path->value, '(') || str_contains($path->value, ')') => 'parentheses, which Flow reads as an optional section',
            array_filter($path->placeholders(), static fn (string $placeholder) => str_contains($placeholder, '.')) !== [] => 'a variable name with a dot, which Flow reads as a nested route value',
            $path->value !== '/' && str_ends_with($path->value, '/') => 'a trailing slash',
            default => null,
        };
        if ($unsupported !== null) {
            throw new \InvalidArgumentException(sprintf('The path "%s" of API "%s" cannot be expressed as a Flow route, since it contains %s', $path->value, $apiName, $unsupported), 1790000012);
        }
        return trim($uriPrefix . $path->value, '/');
    }
}
