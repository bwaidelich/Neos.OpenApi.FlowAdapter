# OpenApi Flow Adapter

Serves [neos/openapi](https://github.com/neos/openapi) APIs through [Flow](https://flow.neos.io/): describe the API
as attribute-annotated PHP methods, name it in the Settings, and every operation becomes a route.

## Installation

```bash
composer require neos/openapi-flowadapter
```

No `Routes.yaml` is needed: the package registers its routes and middleware on its own.

## Usage

An Api Class is a plain class with `#[Operation]` methods, see [neos/openapi](https://github.com/neos/openapi):

```php
namespace Some\Package;

use Neos\OpenApi\Attributes\Operation;

final readonly class BlogApi
{
    #[Operation(path: '/posts/{slug}', method: 'GET')]
    public function getPost(string $slug): string
    {
        return $slug;
    }
}
```

Register it as part of a named API:

```yaml
Neos:
  OpenApi:
    FlowAdapter:
      apis:
        blog:
          uriPrefix: 'api/blog'
          info:
            title: 'Blog'
            version: '1.0.0'
          classes:
            'Some\Package\BlogApi': true
          # optional
          specPath: 'openapi.json'
          docsPath: 'docs'
```

That's all:

| Request | Answer |
| --- | --- |
| `GET /api/blog/posts/hello` | the result of `BlogApi::getPost('hello')` |
| `DELETE /api/blog/posts/hello` | `405`, with an `Allow: GET` header |
| `GET /api/blog/openapi.json` | the OpenAPI specification (if `specPath` is set) |
| `GET /api/blog/docs` | a Swagger UI for it (if `docsPath` is set) |

<!-- Verifies the table above, see Tests/Documentation/ReadmeCodeBlockTest.php
```php
// ...
use Neos\OpenApi\FlowAdapter\Tests\Documentation\FakeFlow;

$flow = FakeFlow::withSettings($settings);

$response = $flow->request('GET', '/api/blog/posts/hello');
assert($response->getStatusCode() === 200);
assert((string)$response->getBody() === '"hello"');

$response = $flow->request('DELETE', '/api/blog/posts/hello');
assert($response->getStatusCode() === 405);
assert($response->getHeaderLine('Allow') === 'GET');

$response = $flow->request('GET', '/api/blog/openapi.json');
assert($response->getHeaderLine('Content-Type') === 'application/json');
$spec = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
assert($spec['info'] === ['title' => 'Blog', 'version' => '1.0.0']);
assert(array_keys($spec['paths']) === ['/posts/{slug}']);

$response = $flow->request('GET', '/api/blog/docs');
assert(str_starts_with($response->getHeaderLine('Content-Type'), 'text/html'));
assert(str_contains((string)$response->getBody(), 'url: "/api/blog/openapi.json"'));

assert($flow->request('GET', '/api/blog/unknown')->getStatusCode() === 404);
```
-->

Everything past routing – which argument is taken from where, how a result becomes a response, the `400` for
invalid input – is up to neos/openapi, and works just the same as without Flow.

### Dependencies, request bodies and several classes

Api Class instances are fetched from Flow's object manager, so they can have dependencies injected. An API can be
made up of any number of Api Classes:

```php
// ...
use Neos\OpenApi\Attributes\RequestBody;

final readonly class CommentApi
{
    public function __construct(
        private CommentRepository $comments,
    ) {}

    #[Operation(path: '/posts/{slug}/comments', method: 'POST')]
    public function addComment(string $slug, #[RequestBody] string $text): void
    {
        $this->comments->add($slug, $text);
    }
}
```

```yaml
Neos:
  OpenApi:
    FlowAdapter:
      apis:
        blog:
          classes:
            'Some\Package\BlogApi': true
            'Some\Package\CommentApi': true
```

Only classes set to `true` are part of the API, so another package – or the Settings of a specific context – can
remove one again by setting it to `false`.

<!--
```php
// ...
final class CommentRepository
{
    /** @var list<array{string, string}> */
    public array $added = [];

    public function add(string $slug, string $text): void
    {
        $this->added[] = [$slug, $text];
    }
}

$flow = FakeFlow::withSettings($settings);

$response = $flow->request('POST', '/api/blog/posts/hello/comments', ['Content-Type' => 'application/json'], '"Nice post!"');
assert($response->getStatusCode() === 204);
assert($flow->get(CommentRepository::class)->added === [['hello', 'Nice post!']]);

// the API's routes are unaffected by the other class
assert((string)$flow->request('GET', '/api/blog/posts/hello')->getBody() === '"hello"');
// a body that is not the declared type is rejected by neos/openapi
assert($flow->request('POST', '/api/blog/posts/hello/comments', ['Content-Type' => 'application/json'], '42')->getStatusCode() === 400);

$withoutComments = $settings;
$withoutComments['Neos']['OpenApi']['FlowAdapter']['apis']['blog']['classes'][CommentApi::class] = false;
assert(FakeFlow::withSettings($withoutComments)->request('POST', '/api/blog/posts/hello/comments', [], '"Nice post!"')->getStatusCode() === 404);
```
-->

## Settings

Every entry of `Neos.OpenApi.FlowAdapter.apis` is an API of its own, with its own routes, OpenAPI specification and
authentication. The name of an entry is used for its route names, and as the title if none is configured.

| Setting | Default | |
| --- | --- | --- |
| `uriPrefix` | `''` | The URI path the API is served under, relative to Flow's base URI. Leading and trailing slashes don't matter. |
| `info.title` | the API's name | The `title` of the specification, and of the Swagger UI. |
| `info.version` | `'1.0.0'` | The `version` of the specification, i.e. of the API – not of the OpenAPI standard. |
| `classes` | none | The Api Classes, by class name. Only classes set to `true` are included. |
| `specPath` | none | Serves the OpenAPI specification as JSON under `<uriPrefix>/<specPath>`. Not served if unset. |
| `docsPath` | none | Serves a Swagger UI under `<uriPrefix>/<docsPath>`. Not served if unset, and requires a `specPath`. |
| `servers` | a single server at `/<uriPrefix>` | The `servers` of the specification, see below. |
| `authContextProvider` | none | The object name of the API's `AuthContextProviderWithSchemes`, see [Authentication](#authentication). |

Neither `specPath` nor `docsPath` may be the path of one of the API's operations.

The paths of the specification don't include the `uriPrefix`, so its `servers` have to. That's what the default
server does, and that's what configured servers have to do as well. They are keyed by a name of your choice, so that
the Settings of a specific context can replace or remove (`~`) a single one:

```yaml
Neos:
  OpenApi:
    FlowAdapter:
      apis:
        blog:
          info:
            title: 'Blog API'
            version: '2.1.0'
          servers:
            production:
              url: 'https://www.example.com/api/blog'
              description: 'Production'
            local:
              url: '/api/blog'
```

<!--
```php
// ...
$spec = json_decode((string)FakeFlow::withSettings($settings)->request('GET', '/api/blog/openapi.json')->getBody(), true, flags: JSON_THROW_ON_ERROR);
assert($spec['info'] === ['title' => 'Blog API', 'version' => '2.1.0']);
assert($spec['servers'] === [
    ['url' => 'https://www.example.com/api/blog', 'description' => 'Production'],
    ['url' => '/api/blog'],
]);
```
-->

A server needs a `url`, an entry without one is ignored. Server variables aren't supported.

## Authentication

An operation states the authentication it requires, see [neos/openapi](https://github.com/neos/openapi):

```php
// ...
use Neos\OpenApi\Attributes\AuthContext;

final readonly class Caller
{
    public function __construct(
        public string $name,
    ) {}
}

final readonly class AccountApi
{
    #[Operation(path: '/me', method: 'GET', security: 'bearerAuth')]
    public function me(#[AuthContext] Caller $caller): string
    {
        return $caller->name;
    }
}
```

What `bearerAuth` is, and who the caller is, is up to the API's `authContextProvider`:

```yaml
Neos:
  OpenApi:
    FlowAdapter:
      apis:
        blog:
          # ...
          classes:
            'Some\Package\AccountApi': true
          authContextProvider: 'Some\Package\BlogAuthContextProvider'
```

It implements `AuthContextProviderWithSchemes`: it declares the security schemes the operations name, and turns a
request into the caller – any object of your own, or `null` for a `401`:

```php
// ...
use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Psr\Http\Message\ServerRequestInterface;

final readonly class BlogAuthContextProvider implements AuthContextProviderWithSchemes
{
    public function __construct(
        private CallerRepository $callers,
    ) {}

    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        return SecuritySchemeOrReferenceObjectMap::create()->with('bearerAuth', SecuritySchemeObject::bearer());
    }

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): object|null
    {
        $token = preg_replace('/^Bearer /', '', $request->getHeaderLine('Authorization'));
        return $token !== '' ? $this->callers->findByToken($token) : null;
    }
}
```

<!--
```php
// ...
final class CallerRepository
{
    public function findByToken(string $token): Caller|null
    {
        return $token === 'secret' ? new Caller('ada') : null;
    }
}

$flow = FakeFlow::withSettings($settings);

$response = $flow->request('GET', '/api/blog/me', ['Authorization' => 'Bearer secret']);
assert($response->getStatusCode() === 200);
assert((string)$response->getBody() === '"ada"');

$response = $flow->request('GET', '/api/blog/me', ['Authorization' => 'Bearer wrong']);
assert($response->getStatusCode() === 401);
assert($response->getHeaderLine('WWW-Authenticate') === 'Bearer');
assert($flow->request('GET', '/api/blog/me')->getStatusCode() === 401);

// operations that don't require authentication don't ask the authContextProvider
assert($flow->request('GET', '/api/blog/posts/hello')->getStatusCode() === 200);

$spec = json_decode((string)$flow->request('GET', '/api/blog/openapi.json')->getBody(), true, flags: JSON_THROW_ON_ERROR);
assert(array_keys($spec['components']['securitySchemes']) === ['bearerAuth']);
assert($spec['paths']['/me']['get']['security'] === [['bearerAuth' => []]]);
```
-->

It is fetched from Flow's object manager, so it can have dependencies injected. An API with operations requiring
authentication, but without an `authContextProvider` – or with one not declaring a scheme they name – fails on its
first request, rather than on the first call of such an operation.

<!--
```php
// ...
$withoutAuthContextProvider = $settings;
unset($withoutAuthContextProvider['Neos']['OpenApi']['FlowAdapter']['apis']['blog']['authContextProvider']);
try {
    FakeFlow::withSettings($withoutAuthContextProvider)->request('GET', '/api/blog/posts/hello');
    assert(false, 'An API with secured operations but no authContextProvider must not be served');
} catch (\InvalidArgumentException $exception) {
    assert($exception->getCode() === 1790000005);
}
```
-->

An operation can also accept a request without credentials, and get `null` for the caller then – see
`allowAnonymous` in [neos/openapi](https://github.com/neos/openapi).

### Using Flow's security framework

The adapter doesn't authenticate through Flow on its own, but an `authContextProvider` can. For HTTP Basic, with the
accounts Flow already knows:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Some.Package:BlogApi':
            provider: PersistedUsernamePasswordProvider
            token: UsernamePasswordHttpBasic
            requestPatterns:
              'Some.Package:BlogApi':
                pattern: Uri
                patternOptions:
                  uriPattern: '/api/blog/.*'
```

```php
// ...
use Neos\Flow\Security\Authentication\AuthenticationManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;

final readonly class FlowAuthContextProvider implements AuthContextProviderWithSchemes
{
    public function __construct(
        private AuthenticationManagerInterface $authenticationManager,
        private SecurityContext $securityContext,
    ) {}

    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        return SecuritySchemeOrReferenceObjectMap::create()->with('basicAuth', SecuritySchemeObject::basic());
    }

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): object|null
    {
        // authenticates the credentials Flow collected from the request – without throwing if there are none
        $this->authenticationManager->isAuthenticated();
        foreach ($this->securityContext->getAuthenticationTokens() as $token) {
            if ($token->getAuthenticationProviderName() === 'Some.Package:BlogApi' && $token->isAuthenticated()) {
                return new Caller((string)$token->getAccount()?->getAccountIdentifier());
            }
        }
        return null;
    }
}
```

<!-- Flow's security framework can't run here, so this stands in for what it hands over
```php
// ...
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\TokenInterface;

$token = function (string $providerName, bool $authenticated, string $accountIdentifier): TokenInterface {
    $account = $this->createStub(Account::class);
    $account->method('getAccountIdentifier')->willReturn($accountIdentifier);
    $token = $this->createStub(TokenInterface::class);
    $token->method('getAuthenticationProviderName')->willReturn($providerName);
    $token->method('isAuthenticated')->willReturn($authenticated);
    $token->method('getAccount')->willReturn($authenticated ? $account : null);
    return $token;
};
$authContextProvider = function (TokenInterface ...$tokens): FlowAuthContextProvider {
    $securityContext = $this->createStub(SecurityContext::class);
    $securityContext->method('getAuthenticationTokens')->willReturn($tokens);
    return new FlowAuthContextProvider($this->createStub(AuthenticationManagerInterface::class), $securityContext);
};
$request = new ServerRequest('GET', '/api/blog/me');
$requirement = SecurityRequirementObject::scheme('basicAuth');

assert($authContextProvider($token('Some.Package:BlogApi', true, 'ada'))->authContextFor($request, $requirement) == new Caller('ada'));
assert($authContextProvider($token('Some.Package:BlogApi', false, 'ada'))->authContextFor($request, $requirement) === null);
// e.g. a Neos backend session
assert($authContextProvider($token('Neos.Neos:Backend', true, 'admin'))->authContextFor($request, $requirement) === null);
assert($authContextProvider()->securitySchemes()->names() === ['basicAuth']);
```
-->

Mind that:

- **Nothing authenticates for you:** Flow only collects the credentials of a request, and authenticates them once
  something asks – a policy, or `isAuthenticated()` as above.
- **Only the API's own token counts:** `SecurityContext::getAccount()` would also return an account authenticated
  through a session, e.g. of a browser logged in to the Neos backend. Hence the check for the provider name, and the
  `Uri` request pattern keeping the token to the API.
- **There's no bearer authentication out of the box:** Flow ships a `BearerToken`, but no provider for it.
- **No CSRF protection applies:** Flow's CSRF check only kicks in for actions a policy protects, and the controller
  serving the API has none.

## How it works

- **Routing:** every operation gets a Flow route of its own, pointing to one controller along with the operationId
  to serve. Path variables become dynamic route parts. The routes are added ahead of all others, so that catch-all
  routes like the one of the Neos frontend can't swallow them.
- **Route names:** `./flow routing:list` shows the routes as `<api> :: <operationId>` – the operationId being the
  method name unless the `#[Operation]` sets one – next to the ones for the specification and the Swagger UI:
  ```text
  blog :: OpenAPI specification
  blog :: Swagger UI
  blog :: getPost
  blog :: addComment
  blog :: me
  ```
- **Wrong methods:** a request with a method none of a path's operations answers matches no route. A middleware right
  after Flow's routing tells that apart from an unknown path and answers it with a `405`.
- **Servers:** unless `servers` are configured, the specification lists a single server at `/<uriPrefix>`, since its
  paths don't include the prefix.
- **Swagger UI:** loads the specification from `<uriPrefix>/<specPath>`, taking into account the base URI Flow is
  served under.
- **Compilation:** each API is compiled at most once per request.

<!--
```php
// ...
assert(FakeFlow::withSettings($settings)->routeNames() === [
    'blog :: OpenAPI specification',
    'blog :: Swagger UI',
    'blog :: getPost',
    'blog :: addComment',
    'blog :: me',
]);
```
-->

## Troubleshooting

A configuration the adapter can't serve fails loudly, with one of these exception codes:

| Code | Cause |
| --- | --- |
| `1790000001` | An API is used that isn't configured in `Neos.OpenApi.FlowAdapter.apis`. |
| `1790000002` | An API has a `docsPath`, but no `specPath` for the Swagger UI to load. |
| `1790000003` | The `authContextProvider` isn't a known object name. |
| `1790000004` | The `authContextProvider` doesn't implement `AuthContextProviderWithSchemes`. |
| `1790000005` | An operation requires authentication, but there is no `authContextProvider`, or it doesn't declare the scheme. |
| `1790000012` | A path template can't be expressed as a Flow route, see [Limitations](#limitations). |
| `1790000013` | The `specPath` or `docsPath` is the path of one of the API's operations. |

Mistakes in the Api Classes themselves – a duplicate operationId, say – are reported by neos/openapi.

## Limitations

- Path templates Flow can't express as a route are rejected when the routes are built: two variables in a row,
  parentheses, variable names with a dot, and trailing slashes.
- The Swagger UI is loaded from the jsdelivr CDN.
- An API without a `uriPrefix` is served at the root. Since its routes come first, a template like `/{slug}` would
  shadow the routes of the rest of the application.
