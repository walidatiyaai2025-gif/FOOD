<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WhatsAppCallingUiContractTest extends TestCase
{
    public function test_status_and_search_controls_remain_fixed_above_the_scrollable_golden_conversation_list(): void
    {
        $inbox = file_get_contents(resource_path('views/admin/whatsapp-inbox.blade.php'));

        $this->assertIsString($inbox);
        $workspace = strpos($inbox, 'data-wa-golden-workspace');
        $rail = strpos($inbox, 'data-wa-pane="conversation-list"');
        $search = strpos($inbox, 'class="wa-rail-tools"');
        $filters = strpos($inbox, 'class="wa-filter-tabs"');
        $scrollableList = strpos($inbox, 'class="wa-list"');

        $this->assertNotFalse($workspace);
        $this->assertNotFalse($rail);
        $this->assertNotFalse($search);
        $this->assertNotFalse($filters);
        $this->assertNotFalse($scrollableList);
        $this->assertLessThan($search, $rail);
        $this->assertLessThan($filters, $search);
        $this->assertLessThan($scrollableList, $filters);
        $this->assertStringContainsString(
            '.wa-conversation-rail{display:flex;flex-direction:column;min-height:0}',
            $inbox,
        );
        $this->assertStringContainsString(
            '.wa-list{overflow:auto;min-height:0;overscroll-behavior:contain}',
            $inbox,
        );
        $this->assertStringContainsString('id="wa-call-start"', $inbox);
        $this->assertStringContainsString('id="wa-call-permission"', $inbox);
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
