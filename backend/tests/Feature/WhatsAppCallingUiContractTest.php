<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WhatsAppCallingUiContractTest extends TestCase
{
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
