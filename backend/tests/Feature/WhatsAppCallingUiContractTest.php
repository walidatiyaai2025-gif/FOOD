<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WhatsAppCallingUiContractTest extends TestCase
{
    public function test_search_filters_live_in_conversation_rail_while_agent_status_remains_below_workspace(): void
    {
        $inbox = file_get_contents(resource_path('views/admin/whatsapp-inbox.blade.php'));

        $this->assertIsString($inbox);
        $workspace = strpos($inbox, 'data-wa-golden-workspace');
        $filters = strpos($inbox, '<div class="wa-conversation-filters">');
        $controls = strpos($inbox, '<div class="wa-controls-row">');

        $this->assertNotFalse($workspace);
        $this->assertNotFalse($filters);
        $this->assertNotFalse($controls);
        $this->assertLessThan($filters, $workspace);
        $this->assertLessThan($controls, $filters);
        $this->assertStringContainsString(
            '.wa-controls-row{display:grid;grid-template-columns:minmax(0,1fr);gap:',
            $inbox,
        );
        $this->assertStringContainsString('name="q"', $inbox);
        $this->assertStringContainsString('name="status"', $inbox);
        $this->assertStringContainsString('name="assignment"', $inbox);
        $this->assertStringContainsString('name="store_id"', $inbox);
        $this->assertStringContainsString('name="channel"', $inbox);
        $this->assertStringNotContainsString('foodex-card wa-toolbar', $inbox);
    }

    public function test_calling_ui_uses_chromium_unsigned_ssrc_compatibility_wrapper(): void
    {
        $inbox = file_get_contents(resource_path('views/admin/whatsapp-inbox.blade.php'));

        $this->assertIsString($inbox);
        $this->assertStringContainsString('const chromiumUnsignedSsrcFallback = value => {', $inbox);
        $this->assertStringContainsString('ssrc > 0x7fffffff && ssrc <= 0xffffffff', $inbox);
        $this->assertStringContainsString('const setRemoteDescriptionCompat = async (pc, description) => {', $inbox);
        $this->assertStringContainsString(
            "await setRemoteDescriptionCompat(pc, {type: 'offer', sdp: incomingCall.remote_sdp});",
            $inbox,
        );
        $this->assertStringContainsString(
            "await setRemoteDescriptionCompat(peer, {type: 'answer', sdp: active.remote_sdp});",
            $inbox,
        );
    }
}
