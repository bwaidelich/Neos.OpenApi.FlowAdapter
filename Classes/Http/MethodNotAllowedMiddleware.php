<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Http;

use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\OpenApi\Support\HttpMethod;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Neos\OpenApi\FlowAdapter\CompiledApis;

/**
 * Answers a request that no route matched, but whose path is one of an API's, with a 405 listing the methods that
 * path does answer
 *
 * {@see \Neos\OpenApi\FlowAdapter\Routing\OpenApiRoutesProvider} only adds a route per operation, so a known path requested with
 * the wrong method matches none – this tells that case apart from an unknown path. Runs after the routing
 * middleware and does nothing if it found a route, so a request that is routed pays nothing for it
 */
final readonly class MethodNotAllowedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CompiledApis $compiledApis,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        if ($request->getAttribute(ServerRequestAttributes::ROUTING_RESULTS) !== null) {
            return $next->handle($request);
        }
        $relativePath = trim(RequestInformationHelper::getRelativeRequestPath($request), '/');
        $method = HttpMethod::tryFrom(strtoupper($request->getMethod()));
        foreach ($this->compiledApis->names() as $apiName) {
            $pathInApi = self::pathInApi($relativePath, $this->compiledApis->uriPrefix($apiName));
            if ($pathInApi === null) {
                continue;
            }
            $paths = $this->compiledApis->get($apiName)->document->paths;
            $template = $paths?->matchTemplate($pathInApi);
            if ($template === null) {
                continue;
            }
            // the path does answer this method, so it was not routed for some other reason – leave that to the rest
            // of the chain rather than claiming the method is not allowed
            if ($method !== null && $paths?->get($template)?->operation($method) !== null) {
                continue;
            }
            return $this->compiledApis->requestHandler($apiName)->handlePath($request, $template);
        }
        return $next->handle($request);
    }

    /**
     * The request path as a path of the API served under $uriPrefix – or null if it lies outside of it
     */
    private static function pathInApi(string $relativePath, string $uriPrefix): string|null
    {
        if ($uriPrefix === '') {
            return '/' . $relativePath;
        }
        if ($relativePath !== $uriPrefix && !str_starts_with($relativePath, $uriPrefix . '/')) {
            return null;
        }
        return '/' . ltrim(substr($relativePath, strlen($uriPrefix)), '/');
    }
}
