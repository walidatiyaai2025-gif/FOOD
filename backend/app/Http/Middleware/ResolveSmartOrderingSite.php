<?php

namespace App\Http\Middleware;

use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSmartOrderingSite
{
    public function __construct(
        private readonly SmartOrderingRuntimePolicy $runtime,
        private readonly SmartOrderingRevisionService $revisions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->runtime->resolveByHost($request->getHost());
        abort_unless($site !== null, 404);

        $branch = null;
        $branchReference = $request->query('branch');
        if (is_string($branchReference) && $branchReference !== '') {
            abort_unless(Str::isUuid($branchReference), 404);
            $branch = $this->runtime->branch($site, $branchReference);
        }

        $this->runtime->assertRequest($site, Module::PUBLIC_MENU, $request, $branch);

        $revision = $this->revisions->published($site);
        abort_unless($revision !== null, 404);

        $request->attributes->set('smart_ordering.site', $site);
        $request->attributes->set('smart_ordering.branch', $branch);
        $request->attributes->set('smart_ordering.revision', $revision);

        return $next($request);
    }
}
