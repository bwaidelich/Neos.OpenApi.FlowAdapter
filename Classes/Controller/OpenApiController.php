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
 * of the API named `__api` – or, with `__spec`, that API's OpenAPI specification, with `__docs`, a Swagger UI for it, and
 * with `__oauth2Redirect`, the page that UI's OAuth2 authorization returns to
 */
final readonly class OpenApiController implements ControllerInterface
{
    /**
     * Where the page that the Swagger UI's OAuth2 authorization returns to is served, relative to the Swagger UI
     */
    public const string OAUTH2_REDIRECT_PATH = 'oauth2-redirect.html';

    private const string SWAGGER_UI_ASSETS = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0';

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
                body: self::swaggerUi(
                    $compiledApi->document->info->title,
                    $this->specUri($request, $apiName),
                    $this->oauth2RedirectUri($request, $apiName),
                    $this->compiledApis->swaggerUiInitOAuth($apiName),
                ),
            );
        }

        if ($request->getInternalArgument('__oauth2Redirect') !== null) {
            return new Response(
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
                body: self::oauth2Redirect(),
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
        return $this->uriPath($request, $apiName, $specPath);
    }

    /**
     * The absolute URI path of the page the Swagger UI's OAuth2 authorization returns to
     */
    private function oauth2RedirectUri(ActionRequest $request, string $apiName): string
    {
        $docsPath = $this->compiledApis->docsPath($apiName);
        if ($docsPath === null) {
            throw new \LogicException(sprintf('The API "%s" has no docsPath', $apiName), 1790000035);
        }
        return $this->uriPath($request, $apiName, $docsPath . '/' . self::OAUTH2_REDIRECT_PATH);
    }

    /**
     * The absolute URI path of the given path of the API, taking into account the base URI Flow is served under
     */
    private function uriPath(ActionRequest $request, string $apiName, string $path): string
    {
        $basePath = rtrim(RequestInformationHelper::generateBaseUri($request->getHttpRequest())->getPath(), '/');
        return $basePath . '/' . ltrim($this->compiledApis->uriPrefix($apiName) . '/' . $path, '/');
    }

    /**
     * @param array<string, mixed> $initOAuth
     */
    private static function swaggerUi(string $title, string $specUri, string $oauth2RedirectUri, array $initOAuth): string
    {
        $assets = self::SWAGGER_UI_ASSETS;
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5);
        $specUriJson = self::json($specUri);
        // made absolute in the browser, since the authorization server redirects the browser to it
        $oauth2RedirectUriJson = self::json($oauth2RedirectUri);
        $initOAuthCall = $initOAuth === [] ? '' : sprintf("\n        window.ui.initOAuth(%s);", self::json($initOAuth));
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
                    window.ui = SwaggerUIBundle({
                        url: {$specUriJson},
                        dom_id: '#swagger-ui',
                        oauth2RedirectUrl: new URL({$oauth2RedirectUriJson}, window.location.href).href,
                    });{$initOAuthCall}
                </script>
            </body>
            </html>
            HTML;
    }

    /**
     * The page the authorization server redirects to: it hands the result back to the Swagger UI that opened it
     *
     * It has to be served from the origin of the Swagger UI, since it talks to it through `window.opener`
     */
    private static function oauth2Redirect(): string
    {
        $assets = self::SWAGGER_UI_ASSETS;
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Swagger UI: OAuth2 redirect</title>
            </head>
            <body>
                <script src="{$assets}/oauth2-redirect.js" crossorigin></script>
            </body>
            </html>
            HTML;
    }

    private static function json(mixed $value): string
    {
        // safe to embed in a <script> element
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
