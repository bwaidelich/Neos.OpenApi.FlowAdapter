<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Tests\Unit\Fixtures;

final readonly class Caller
{
    public function __construct(
        public string $name,
    ) {}
}
