<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_information_pages_preserve_arabic_and_english_locale_navigation(): void
    {
        $routes = [
            '/support' => 'public.support',
            '/privacy' => 'public.privacy',
            '/terms' => 'public.terms',
            '/account-deletion' => 'public.account-deletion',
        ];

        foreach ($routes as $path => $routeName) {
            $this->get($path.'?lang=en')
                ->assertOk()
                ->assertSee('<html lang="en" dir="ltr">', false)
                ->assertSee(route($routeName, ['lang' => 'ar']), false)
                ->assertSee(route('public.support', ['lang' => 'en']), false);

            $this->get($path.'?lang=ar')
                ->assertOk()
                ->assertSee('<html lang="ar" dir="rtl">', false)
                ->assertSee(route($routeName, ['lang' => 'en']), false)
                ->assertSee(route('public.support', ['lang' => 'ar']), false);
        }
    }

    public function test_support_uses_active_primary_whatsapp_identity_without_exposing_provider_secrets(): void
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'FOODEX Public Support',
            'display_phone_number' => '+965 2222 0000',
            'normalized_phone_number' => '+96522220000',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'private-provider-phone-id',
            'credential_secret' => 'private-access-token',
            'status' => ConnectionProfileStatus::ACTIVE,
            'health_status' => ConnectionHealthStatus::READY,
            'is_primary' => true,
            'receive_only' => false,
            'primary_slot' => 1,
        ]);
        $profile->save();

        $this->get('/support?lang=en')
            ->assertOk()
            ->assertSee('WhatsApp support: +965 2222 0000')
            ->assertSee('href="https://wa.me/96522220000"', false)
            ->assertDontSee('private-provider-phone-id')
            ->assertDontSee('private-access-token');
    }

    public function test_support_has_explicit_safe_fallback_when_no_public_contact_is_active(): void
    {
        $this->get('/support?lang=en')
            ->assertOk()
            ->assertSee('No active public WhatsApp support contact is configured.')
            ->assertSee('Customer App')
            ->assertSee('Diagnostics')
            ->assertSee('organization administrator');

        $this->get('/support?lang=ar')
            ->assertOk()
            ->assertSee('لا توجد جهة دعم واتساب عامة ونشطة مضبوطة حاليًا.')
            ->assertSee('التشخيص')
            ->assertSee('مسؤول الجهة');
    }

    public function test_account_deletion_page_names_the_real_customer_self_service_and_managed_operational_path(): void
    {
        $this->get('/account-deletion?lang=en')
            ->assertOk()
            ->assertSee('Customer App Account screen')
            ->assertSee('Delete account')
            ->assertSee('Driver/Van operational accounts');

        $this->get('/account-deletion?lang=ar')
            ->assertOk()
            ->assertSee('شاشة الحساب داخل تطبيق العميل')
            ->assertSee('حذف الحساب')
            ->assertSee('حسابات السائق/الفان التشغيلية');
    }

    public function test_public_information_layout_keeps_keyboard_focus_and_compact_width_contract(): void
    {
        $view = file_get_contents(resource_path('views/public/legal.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('a:focus-visible', $view);
        $this->assertStringContainsString('min-height:44px', $view);
        $this->assertStringContainsString('@media(max-width:600px)', $view);
        $this->assertStringContainsString('dir="{{ $direction }}"', $view);
    }
}
