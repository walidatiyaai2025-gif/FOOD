<?php

namespace App\Http\Middleware;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\RuntimePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apply only to WhatsApp operational routes; authentication/RBAC remain required. */
final class EnsureWhatsAppCapability
{
    public function __construct(private readonly RuntimePolicy $policy) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $allowed = match (Capability::tryFrom($capability)) {
            Capability::INBOUND => $this->policy->allowsInbound(),
            Capability::AGENT_INBOX => $this->policy->allowsAgentInbox(),
            Capability::CAMPAIGNS => $this->policy->allowsCampaignManagement(),
            default => false,
        };
        abort_unless($allowed, 404);

        return $next($request);
    }
}
