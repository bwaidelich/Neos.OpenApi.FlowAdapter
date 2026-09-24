<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

use Neos\OpenApi\Attributes\Operation;

final class PingApi
{
    #[Operation(path: '/ping', method: 'GET')]
    public function ping(): string
    {
        return 'pong';
    }
}
