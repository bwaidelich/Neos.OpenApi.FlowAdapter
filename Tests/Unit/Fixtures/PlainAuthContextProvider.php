<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Spec\SecurityRequirementObject;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The core's interface alone, which declares no schemes
 */
final class PlainAuthContextProvider implements AuthContextProvider
{
    public function authContextFor(ServerRequestInterface $request, SecurityRequirementObject $requirement): object|null
    {
        return new Caller('ada');
    }
}
