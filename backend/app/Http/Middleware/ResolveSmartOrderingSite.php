<?php

namespace App\Http\Middleware;

use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingConfigurationService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SmartOrderingSiteResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSmartOrderingSite
{
    public function __construct(
        private readonly SmartOrderingSiteResolver $resolver,
        private readonly SmartOrderingRuntimePolicy $runtime,
        private readonly SmartOrderingConfigurationService $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->resolver->resolveHost($request->getHost());
        abort_unless($resolved !== null, 404);

        $branchReference = $request->query('branch');
        $branchReference = is_string($branchReference) && $branchReference !== '' ? $branchReference : null;
        $branch = $this->resolver->resolveBranch($resolved->site, $branchReference);

        if ($branchReference !== null) {
            abort_unless($branch !== null, 404);
        }

        $request->attributes->set('smart_ordering.site', $resolved->site);
        $request->attributes->set('smart_ordering.branch', $branch);
        $request->attributes->set('smart_ordering.resolved_from_alias', $resolved->resolvedFromAlias);
        $request->attributes->set('smart_ordering.resolved_from_custom_domain', $resolved->resolvedFromCustomDomain);

        $this->runtime->assertAvailable($resolved->site, Module::PUBLIC_MENU, $request, $branch);

        $revision = $this->configuration->published($resolved->site);
        abort_unless($revision !== null, 404);
        $request->attributes->set('smart_ordering.revision', $revision);

        return $next($request);
    }
}
