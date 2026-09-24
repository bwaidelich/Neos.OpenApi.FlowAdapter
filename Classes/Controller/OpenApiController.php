<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Controller;

use GuzzleHttp\Psr7\Response;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Controller\ControllerInterface;
use Psr\Http\Message\ResponseInterface;
use Neos\OpenApi\FlowAdapter\CompiledApis;

/**
 * Serves the requests {@see \Neos\OpenApi\FlowAdapter\Routing\OpenApiRoutesProvider} routed here: the operation `__operation`
 * of the API named `__api` – or, with `__spec`, that API's OpenAPI specification, and with `__docs`, a Swagger UI for it
 */
final readonly class OpenApiController implements ControllerInterface
{
    public function __construct(
        private CompiledApis $compiledApis,
    ) {}

    public function processRequest(ActionRequest $request): ResponseInterface
    {
        $apiName = $request->getInternalArgument('__api');
        if (!is_string($apiName)) {
            throw new \InvalidArgumentException('Missing internal argument "__api"', 1790000031);
        }
        $compiledApi = $this->compiledApis->get($apiName);

        if ($request->getInternalArgument('__spec') !== null) {
            return new Response(
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($compiledApi->document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        }

        if ($request->getInternalArgument('__docs') !== null) {
            return new Response(
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
                body: self::swaggerUi($compiledApi->document->info->title, $this->specUri($request, $apiName)),
            );
        }

        $operationId = $request->getInternalArgument('__operation');
        if (!is_string($operationId)) {
            throw new \InvalidArgumentException('Missing internal argument "__operation"', 1790000033);
        }
        $entry = $compiledApi->dispatchTable->findByOperationId($operationId);
        if ($entry === null) {
            throw new \InvalidArgumentException(sprintf('The API "%s" has no operation "%s"', $apiName, $operationId), 1790000032);
        }
        // the route values of the path's variables, already percent-decoded by Flow
        $pathVariables = [];
        foreach ($entry->path->placeholders() as $placeholder) {
            if ($request->hasArgument($placeholder)) {
                $pathVariables[$placeholder] = (string)$request->getArgument($placeholder);
            }
        }
        return $this->compiledApis->requestHandler($apiName)->handleOperation($request->getHttpRequest(), $operationId, $pathVariables);
    }

    /**
     * The absolute URI path of the API's OpenAPI specification, taking into account the base URI Flow is served under
     */
    private function specUri(ActionRequest $request, string $apiName): string
    {
        $specPath = $this->compiledApis->specPath($apiName);
        if ($specPath === null) {
            throw new \LogicException(sprintf('The API "%s" has no specPath', $apiName), 1790000034);
        }
        $basePath = rtrim(RequestInformationHelper::generateBaseUri($request->getHttpRequest())->getPath(), '/');
        return $basePath . '/' . ltrim($this->compiledApis->uriPrefix($apiName) . '/' . $specPath, '/');
    }

    private static function swaggerUi(string $title, string $specUri): string
    {
        $assets = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0';
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5);
        $specUriJson = json_encode($specUri, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$escapedTitle}</title>
                <link rel="stylesheet" href="{$assets}/swagger-ui.css">
            </head>
            <body>
                <div id="swagger-ui"></div>
                <script src="{$assets}/swagger-ui-bundle.js" crossorigin></script>
                <script>
                    window.ui = SwaggerUIBundle({url: {$specUriJson}, dom_id: '#swagger-ui'});
                </script>
            </body>
            </html>
            HTML;
    }
}
