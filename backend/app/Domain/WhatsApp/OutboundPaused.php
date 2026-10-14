<?php

namespace App\Domain\WhatsApp;

/** #1402 must retain unsent work as paused, never fall back to direct transport. */
final class OutboundPaused extends \RuntimeException {}
