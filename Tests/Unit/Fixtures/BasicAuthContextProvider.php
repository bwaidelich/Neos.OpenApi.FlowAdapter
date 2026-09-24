<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

use Neos\OpenApi\FlowAdapter\AuthContextProviderWithSchemes;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Neos\OpenApi\Spec\SecuritySchemeObject;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Declares "basicAuth" only – not the "bearerAuth" {@see AccountApi} requires
 */
final class BasicAuthContextProvider implements AuthContextProviderWithSchemes
{
    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap
    {
        return SecuritySchemeOrReferenceObjectMap::create()->with('basicAuth', SecuritySchemeObject::basic());
    }

    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): object|null
    {
        return null;
    }
}
