<?php

namespace App\Domain\WhatsApp;

enum Capability: string
{
    case INBOUND = 'inbound';
    case AGENT_INBOX = 'agent_inbox';
    case TRANSACTIONAL = 'transactional';
    case CAMPAIGNS = 'campaigns';
    case AUTOMATION = 'automation';
    case OUTBOUND = 'outbound';

    public function isOutbound(): bool
    {
        return in_array($this, [self::OUTBOUND, self::TRANSACTIONAL, self::CAMPAIGNS, self::AUTOMATION], true);
    }
}
