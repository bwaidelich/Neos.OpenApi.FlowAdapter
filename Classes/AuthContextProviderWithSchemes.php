<?php

declare(strict_types=1);

namespace Neos\OpenApi\FlowAdapter;

use Neos\OpenApi\Http\AuthContextProvider;
use Neos\OpenApi\Spec\SecuritySchemeOrReferenceObjectMap;

/**
 * The `authContextProvider` of an API configured in `Neos.OpenApi.FlowAdapter.apis`: it hands over the caller of the
 * operations that require authentication, and declares the security schemes they name
 *
 * The operations name a scheme (`#[Operation(security: 'bearerAuth')]`), this says what that scheme is – which is why
 * both live in one class: the code that understands "bearerAuth" is the one to declare it
 */
interface AuthContextProviderWithSchemes extends AuthContextProvider
{
    /**
     * The document's `components.securitySchemes` – every scheme an operation of the API names has to be among them
     */
    public function securitySchemes(): SecuritySchemeOrReferenceObjectMap;
}
