<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
use Neos\OpenApi\Http\Authenticated;
use Neos\OpenApi\Http\AuthenticationResult;
use Neos\OpenApi\Http\Unauthenticated;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Psr\Http\Message\ServerRequestInterface;

final class BearerAuthContextProvider implements AuthContextProviderWithSchemes
{
    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        return SecuritySchemeOrReferenceObjectMap::create()->with('bearerAuth', SecuritySchemeObject::bearer());
    }

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): AuthenticationResult
    {
        return $request->getHeaderLine('Authorization') === 'Bearer secret' ? Authenticated::as(new Caller('ada')) : Unauthenticated::create();
    }
}
