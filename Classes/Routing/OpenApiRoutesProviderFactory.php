<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter\Routing;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\RoutesProviderFactoryInterface;
use Neos\OpenApi\FlowAdapter\CompiledApis;

#[Flow\Scope('singleton')]
final readonly class OpenApiRoutesProviderFactory implements RoutesProviderFactoryInterface
{
    public function __construct(
        private CompiledApis $compiledApis,
    ) {}

    /**
     * @param array<mixed> $options unused – the provider covers every API configured in `Neos.OpenApi.FlowAdapter.apis`
     */
    public function createRoutesProvider(array $options): OpenApiRoutesProvider
    {
        return new OpenApiRoutesProvider($this->compiledApis);
    }
}
