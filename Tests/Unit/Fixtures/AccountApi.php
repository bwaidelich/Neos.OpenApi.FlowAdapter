<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

use Neos\OpenApi\Attributes\AuthContext;
use Neos\OpenApi\Attributes\Operation;

final class AccountApi
{
    #[Operation(path: '/me', method: 'GET', security: 'bearerAuth')]
    public function me(#[AuthContext] Caller $caller): string
    {
        return $caller->name;
    }
}
