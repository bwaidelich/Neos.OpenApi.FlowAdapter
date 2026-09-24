# OpenApi Flow Adapter

Serves [neos/openapi](https://github.com/neos/openapi) APIs through [Flow](https://flow.neos.io/): describe the API
as attribute-annotated PHP methods, name it in the Settings, and every operation becomes a route.

## Installation

```bash
composer require neos/openapi-flowadapter
```

## Usage

An Api Class is a plain class with `#[Operation]` methods, see [neos/openapi](https://github.com/neos/openapi):

```php
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

Api Class instances are fetched from Flow's object manager, so they can have dependencies injected.

## Authentication

An operation states the authentication it requires, see [neos/openapi](https://github.com/neos/openapi):

```php
use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;

final readonly class AccountApi
{
    #[Operation(path: '/me', method: 'GET', security: 'bearerAuth')]
    public function me(#[AuthContext] Caller $caller): Caller
    {
        return $caller;
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
          authContextProvider: 'Some\Package\BlogAuthContextProvider'
```

It implements `AuthContextProviderWithSchemes`: it declares the security schemes the operations name, and turns a
request into the caller – any object of your own, or `null` for a `401`:

```php
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

It is fetched from Flow's object manager, so it can have dependencies injected. An API with operations requiring
authentication, but without an `authContextProvider` – or with one not declaring a scheme they name – fails on its
first request, rather than on the first call of such an operation.

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

- **Routing:** every operation gets a Flow route of its own (`./flow routing:list` shows them as `<api> :: <operationId>`),
  pointing to one controller along with the operationId to serve. Path variables become dynamic route parts.
- **Wrong methods:** a request with a method none of a path's operations answers matches no route. A middleware right
  after Flow's routing tells that apart from an unknown path and answers it with a `405`.
- **Servers:** unless `servers` are configured, the specification lists a single server at `/<uriPrefix>`, since its
  paths don't include the prefix.
- **Compilation:** each API is compiled at most once per request.

## Limitations

- Path templates Flow can't express as a route are rejected when the routes are built: two variables in a row,
  parentheses, variable names with a dot, and trailing slashes.
- The Swagger UI is loaded from the jsdelivr CDN.
