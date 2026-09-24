<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
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

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): object|null
    {
        return $request->getHeaderLine('Authorization') === 'Bearer secret' ? new Caller('ada') : null;
    }
}
