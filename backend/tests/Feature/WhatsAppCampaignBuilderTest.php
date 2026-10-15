<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\MarketingConsentService;
use App\Domain\WhatsApp\TemplateRegistryService;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class WhatsAppCampaignBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('local');
    }

    public function test_manual_contacts_are_deduplicated_and_consent_is_never_implicit(): void
    {
        $storeId = $this->store('B2C', 'WA-BUILDER-MANUAL');
        $this->enableCampaigns(['+96550000111']);
        $admin = $this->superAdmin();

        $first = $this->actingAs($admin)->postJson(route('admin.whatsapp-campaigns.audience.manual'), [
            'store_id' => $storeId,
            'manual_lines' => 'Manual Customer,965 5000 0111,manual@example.test',
        ])->assertOk()->json();

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, DB::table('platform_customers')->where('phone', '+96550000111')->count());
        $customerId = (int) $first['customer_ids'][0];
        $this->assertSame('unknown', app(MarketingConsentService::class)->state($customerId));

        $second = $this->actingAs($admin)->postJson(route('admin.whatsapp-campaigns.audience.manual'), [
            'store_id' => $storeId,
            'manual_lines' => 'Same Phone,+965-5000-0111,other@example.test',
            'consent_state' => 'granted',
            'consent_source' => 'signed_form',
            'consent_evidence' => 'Signed opt-in form #123',
        ])->assertOk()->json();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['matched']);
        $this->assertSame([$customerId], $second['customer_ids']);
        $this->assertSame('granted', app(MarketingConsentService::class)->state($customerId));
        $this->assertSame(1, DB::table('platform_customers')->where('phone', '+96550000111')->count());
    }

    public function test_csv_import_previews_maps_and_commits_contacts(): void
    {
        $storeId = $this->store('B2B', 'WA-BUILDER-CSV');
        $this->enableCampaigns(['+96550000222']);
        $admin = $this->superAdmin();

        $preview = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post(
                route('admin.whatsapp-campaigns.audience.import.preview'),
                [
                    'store_id' => $storeId,
                    'file' => UploadedFile::fake()->createWithContent(
                        'contacts.csv',
                        "Customer Name,Mobile,Email\nCSV Customer,+96550000222,csv@example.test\n",
                    ),
                ],
            )->assertOk()->json('preview');

        $this->assertSame(1, $preview['rows_total']);
        $this->assertSame('Customer Name', $preview['mapping']['name']);
        $this->assertSame('Mobile', $preview['mapping']['phone']);

        $result = $this->actingAs($admin)->postJson(
            route('admin.whatsapp-campaigns.audience.import.commit', ['batch' => $preview['batch_id']]),
            [
                'store_id' => $storeId,
                'mapping' => [
                    'name' => 'Customer Name',
                    'phone' => 'Mobile',
                    'email' => 'Email',
                ],
            ],
        )->assertOk()->json();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('platform_customers', [
            'id' => $result['customer_ids'][0],
            'phone' => '+96550000222',
            'origin_channel' => 'b2b',
            'origin_store_id' => $storeId,
        ]);
        $this->assertSame('unknown', app(MarketingConsentService::class)->state((int) $result['customer_ids'][0]));
    }

    public function test_xlsx_import_rejects_formula_rows_without_executing_them(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for XLSX import.');
        }

        $storeId = $this->store('B2C', 'WA-BUILDER-XLSX');
        $this->enableCampaigns(['+96550000333']);
        $admin = $this->superAdmin();
        $path = $this->xlsxFixture();

        $preview = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post(
                route('admin.whatsapp-campaigns.audience.import.preview'),
                [
                    'store_id' => $storeId,
                    'file' => new UploadedFile(
                        $path,
                        'contacts.xlsx',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        null,
                        true,
                    ),
                ],
            )->assertOk()->json('preview');

        $this->assertSame(2, $preview['rows_total']);
        $this->assertSame(1, $preview['formula_rows']);

        $result = $this->actingAs($admin)->postJson(
            route('admin.whatsapp-campaigns.audience.import.commit', ['batch' => $preview['batch_id']]),
            [
                'store_id' => $storeId,
                'mapping' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email'],
            ],
        )->assertOk()->json();

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['invalid']);
        $this->assertDatabaseHas('platform_customers', ['phone' => '+96550000333']);
        $this->assertDatabaseMissing('platform_customers', ['phone' => '+96559999999']);
    }

    public function test_campaign_builder_creates_snapshot_and_reports_export_from_authoritative_recipients(): void
    {
        $storeId = $this->store('B2C', 'WA-BUILDER-REPORT');
        $phone = '+96550000444';
        $this->enableCampaigns([$phone]);
        $admin = $this->superAdmin();
        $customerId = $this->customer($storeId, 'b2c', $phone);
        app(MarketingConsentService::class)->record($customerId, 'granted', 'verified', $admin->id, ['evidence' => 'fixture']);
        $templateId = $this->template();

        $this->actingAs($admin)->post(route('admin.whatsapp-campaigns.store'), [
            'name' => 'Builder Report Campaign',
            'template_id' => $templateId,
            'audience_source' => 'existing',
            'customer_ids' => [$customerId],
            'store_ids' => [$storeId],
            'test_mode' => 1,
            'test_allowlist' => $phone,
            'max_recipients' => 100,
            'batch_size' => 10,
            'per_minute_limit' => 10,
            'launch_action' => 'draft',
        ])->assertRedirect();

        $campaignId = (int) DB::table('whats_app_campaigns')
            ->where('name', 'Builder Report Campaign')
            ->value('id');
        $this->assertGreaterThan(0, $campaignId);
        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'campaign_id' => $campaignId,
            'platform_customer_id' => $customerId,
            'eligibility_status' => 'eligible',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-campaigns.report', ['campaign' => $campaignId]))
            ->assertOk()
            ->assertSee('Builder Report Campaign')
            ->assertSee($phone);

        $csv = $this->actingAs($admin)
            ->get(route('admin.whatsapp-campaigns.export', ['campaign' => $campaignId, 'format' => 'csv']))
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString($phone, $csv->getContent());

        if (class_exists(ZipArchive::class)) {
            $xlsx = $this->actingAs($admin)
                ->get(route('admin.whatsapp-campaigns.export', ['campaign' => $campaignId, 'format' => 'xlsx']))
                ->assertOk();
            $this->assertStringContainsString(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                (string) $xlsx->headers->get('content-type'),
            );
        }
    }

    public function test_live_campaign_snapshot_suppresses_missing_consent_and_can_schedule_approved_template(): void
    {
        $storeId = $this->store('B2B', 'WA-BUILDER-CONSENT');
        $grantedPhone = '+96550000551';
        $unknownPhone = '+96550000552';
        $this->enableCampaigns([$grantedPhone, $unknownPhone]);
        $admin = $this->superAdmin();
        $grantedId = $this->customer($storeId, 'b2b', $grantedPhone);
        $unknownId = $this->customer($storeId, 'b2b', $unknownPhone);
        app(MarketingConsentService::class)->record(
            $grantedId,
            'granted',
            'verified',
            $admin->id,
            ['evidence' => 'fixture'],
        );

        $this->actingAs($admin)->post(route('admin.whatsapp-campaigns.store'), [
            'name' => 'Consent Gated Campaign',
            'template_id' => $this->template(),
            'audience_source' => 'existing',
            'customer_ids' => [$grantedId, $unknownId],
            'store_ids' => [$storeId],
            'test_mode' => 1,
            'test_allowlist' => $grantedPhone."\n".$unknownPhone,
            'launch_action' => 'send_now',
        ])->assertRedirect();

        $campaignId = (int) DB::table('whats_app_campaigns')
            ->where('name', 'Consent Gated Campaign')
            ->value('id');

        $this->assertDatabaseHas('whats_app_campaigns', [
            'id' => $campaignId,
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'campaign_id' => $campaignId,
            'platform_customer_id' => $grantedId,
            'eligibility_status' => 'eligible',
        ]);
        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'campaign_id' => $campaignId,
            'platform_customer_id' => $unknownId,
            'eligibility_status' => 'suppressed',
            'suppression_reason' => 'no_consent',
        ]);
    }

    public function test_campaign_contact_mutations_enforce_channel_and_store_scope(): void
    {
        $b2bStore = $this->store('B2B', 'WA-BUILDER-SCOPE-B2B');
        $b2cStore = $this->store('B2C', 'WA-BUILDER-SCOPE-B2C');
        $this->enableCampaigns([]);

        $supervisor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $supervisor->roles()->attach(
            Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail(),
        );

        $this->actingAs($supervisor)->postJson(route('admin.whatsapp-campaigns.audience.manual'), [
            'store_id' => $b2bStore,
            'manual_lines' => 'Scoped B2B,+96550000661,scoped-b2b@example.test',
        ])->assertOk();

        $this->actingAs($supervisor)->postJson(route('admin.whatsapp-campaigns.audience.manual'), [
            'store_id' => $b2cStore,
            'manual_lines' => 'Forbidden B2C,+96550000662,scoped-b2c@example.test',
        ])->assertNotFound();

        $this->assertDatabaseHas('platform_customers', [
            'phone' => '+96550000661',
            'origin_store_id' => $b2bStore,
        ]);
        $this->assertDatabaseMissing('platform_customers', [
            'phone' => '+96550000662',
            'origin_store_id' => $b2cStore,
        ]);
    }

    public function test_meta_template_can_be_created_and_synced_for_campaign_use(): void
    {
        $this->enableCampaigns([]);
        $admin = $this->superAdmin();
        $this->metaProfile();

        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/waba-foodex/message_templates')) {
                return Http::response([
                    'id' => 'meta-template-1',
                    'status' => 'PENDING',
                    'category' => 'MARKETING',
                ], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/waba-foodex/message_templates')) {
                return Http::response([
                    'data' => [[
                        'id' => 'meta-template-1',
                        'name' => 'foodex_offer_oct',
                        'status' => 'APPROVED',
                        'category' => 'MARKETING',
                        'language' => 'ar',
                        'components' => [[
                            'type' => 'BODY',
                            'text' => 'عرض فودكس اليوم فقط',
                        ]],
                    ]],
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->actingAs($admin)->postJson(route('admin.whatsapp-campaigns.templates.meta.store'), [
            'name' => 'عرض أكتوبر',
            'provider_name' => 'Foodex Offer-Oct',
            'locale' => 'ar',
            'category' => 'marketing',
            'body_preview' => 'عرض فودكس اليوم فقط',
        ])->assertOk();

        $template = DB::table('whats_app_templates')
            ->where('provider_name', 'foodex_offer_oct')
            ->where('locale', 'ar')
            ->firstOrFail();

        $this->assertSame('pending', $template->provider_status);

        $this->actingAs($admin)
            ->postJson(route('admin.whatsapp-campaigns.templates.meta.sync'))
            ->assertOk();

        $this->assertDatabaseHas('whats_app_templates', [
            'id' => $template->id,
            'provider_name' => 'foodex_offer_oct',
            'locale' => 'ar',
            'provider_status' => 'approved',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/v26.0/waba-foodex/message_templates')
                && $request->hasHeader('Authorization', 'Bearer meta-template-token')
                && $request['name'] === 'foodex_offer_oct'
                && $request['language'] === 'ar'
                && $request['category'] === 'MARKETING'
                && $request['components'][0]['type'] === 'BODY';
        });
    }

    public function test_meta_template_sync_paginates_and_imports_remote_templates(): void
    {
        $this->enableCampaigns([]);
        $admin = $this->superAdmin();
        $this->metaProfile();

        app(TemplateRegistryService::class)->upsert([
            'name' => 'Existing Pending',
            'provider_name' => 'existing_pending',
            'locale' => 'en',
            'category' => 'marketing',
            'provider_status' => 'pending',
            'body_preview' => 'Existing body',
            'is_active' => true,
        ]);

        $getCount = 0;
        Http::fake(function ($request) use (&$getCount) {
            if ($request->method() !== 'GET' || ! str_contains($request->url(), '/waba-foodex/message_templates')) {
                return Http::response([], 404);
            }

            $getCount++;

            if ($getCount === 1) {
                return Http::response([
                    'data' => [[
                        'id' => 'meta-existing',
                        'name' => 'existing_pending',
                        'status' => 'APPROVED',
                        'category' => 'MARKETING',
                        'language' => 'en_US',
                        'components' => [[
                            'type' => 'BODY',
                            'text' => 'Existing body',
                        ]],
                    ]],
                    'paging' => [
                        'cursors' => ['after' => 'cursor-page-2'],
                        'next' => 'https://graph.facebook.com/v26.0/waba-foodex/message_templates?after=cursor-page-2',
                    ],
                ], 200);
            }

            return Http::response([
                'data' => [[
                    'id' => 'meta-remote-only',
                    'name' => 'remote_only_offer',
                    'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'language' => 'ar',
                    'components' => [[
                        'type' => 'BODY',
                        'text' => 'قالب موجود في ميتا',
                    ]],
                ]],
            ], 200);
        });

        $response = $this->actingAs($admin)
            ->postJson(route('admin.whatsapp-campaigns.templates.meta.sync'))
            ->assertOk()
            ->assertJson([
                'fetched' => 2,
                'updated' => 1,
                'imported' => 1,
                'approved' => 2,
                'pending' => 0,
                'rejected' => 0,
                'ok' => true,
            ]);

        $this->assertDatabaseHas('whats_app_templates', [
            'provider_name' => 'existing_pending',
            'locale' => 'en',
            'provider_status' => 'approved',
        ]);
        $this->assertDatabaseHas('whats_app_templates', [
            'provider_name' => 'remote_only_offer',
            'locale' => 'ar',
            'category' => 'utility',
            'provider_status' => 'approved',
        ]);

        $this->assertSame(2, $getCount);
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'GET') {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['after'] ?? null) === 'cursor-page-2';
        });

        $this->assertSame(2, $response->json('approved'));
    }

    public function test_meta_template_creation_fails_closed_without_valid_provider_or_permission(): void
    {
        $this->enableCampaigns([]);
        $admin = $this->superAdmin();

        Http::fake();

        $this->actingAs($admin)->postJson(route('admin.whatsapp-campaigns.templates.meta.store'), [
            'name' => 'Offer',
            'provider_name' => 'foodex_offer',
            'locale' => 'en',
            'category' => 'marketing',
            'body_preview' => 'FOODEX offer',
        ])->assertUnprocessable();

        Http::assertNothingSent();

        $this->metaProfile();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'provider detail must not leak'],
            ], 400),
        ]);

        $this->actingAs($admin)->postJson(route('admin.whatsapp-campaigns.templates.meta.store'), [
            'name' => 'Offer',
            'provider_name' => 'foodex_offer',
            'locale' => 'en',
            'category' => 'marketing',
            'body_preview' => 'FOODEX offer',
        ])->assertUnprocessable()
            ->assertJsonMissing(['provider detail must not leak']);

        $this->assertDatabaseMissing('whats_app_templates', [
            'provider_name' => 'foodex_offer',
        ]);

        $unauthorized = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->actingAs($unauthorized)->postJson(route('admin.whatsapp-campaigns.templates.meta.store'), [
            'name' => 'Unauthorized',
            'provider_name' => 'unauthorized_offer',
            'locale' => 'en',
            'category' => 'marketing',
            'body_preview' => 'Must not submit',
        ])->assertForbidden();
    }

    public function test_campaign_dashboard_exposes_builder_without_json_editing(): void
    {
        $this->enableCampaigns([]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-campaigns.index'))
            ->assertOk()
            ->assertSee('Create WhatsApp campaign')
            ->assertSee('Create WhatsApp template')
            ->assertSee('Sync Meta statuses')
            ->assertSee('id="wa-template-sync-form"', false)
            ->assertSee('id="wa-template-sync-submit"', false)
            ->assertSee('templateSyncSubmit.disabled = true;', false)
            ->assertSee('id="wa-template-provider-name"', false)
            ->assertSee('canonicalTemplateName', false)
            ->assertDontSee('pattern="[a-z0-9_]+"', false)
            ->assertSee('Existing customers')
            ->assertSee('Import CSV / Excel')
            ->assertSee('Add manually')
            ->assertSee('id="wa-manual-consent-state" autocomplete="off"', false)
            ->assertSee('id="wa-manual-consent-source" maxlength="64" placeholder="admin_verified" autocomplete="off" disabled', false)
            ->assertSee("const resetConsentFields = prefix => syncConsentFields(prefix,true);", false)
            ->assertSee("if(!state)return {consent_state:null};", false)
            ->assertDontSee('segment JSON');
    }

    public function test_campaign_builder_and_reports_keep_ar_en_and_responsive_contracts(): void
    {
        $builder = file_get_contents(resource_path('views/admin/whatsapp-campaigns/index.blade.php'));
        $report = file_get_contents(resource_path('views/admin/whatsapp-campaigns/report.blade.php'));
        $english = require lang_path('en/whatsapp.php');
        $arabic = require lang_path('ar/whatsapp.php');

        $this->assertIsString($builder);
        $this->assertIsString($report);
        $this->assertStringContainsString('@media(max-width:700px)', $builder);
        $this->assertStringContainsString('@media(max-width:700px)', $report);
        $this->assertStringContainsString('data-pagination="server"', $report);
        $this->assertSame('Create WhatsApp campaign', $english['campaigns']['create_campaign']);
        $this->assertSame('إنشاء حملة واتساب', $arabic['campaigns']['create_campaign']);
        $this->assertSame('Create WhatsApp template', $english['campaigns']['create_meta_template']);
        $this->assertSame('إنشاء قالب واتساب', $arabic['campaigns']['create_meta_template']);
        $this->assertSame('Reports', $english['campaigns']['reports']);
        $this->assertSame('التقارير', $arabic['campaigns']['reports']);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function enableCampaigns(array $testNumbers): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                'outbound' => true,
                'campaigns' => true,
                'inbound' => true,
                'agent_inbox' => true,
            ],
            'test_numbers' => $testNumbers,
        ])->save();
    }

    private function metaProfile(): WhatsAppConnectionProfile
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'FOODEX Meta',
            'display_phone_number' => '+96522220000',
            'normalized_phone_number' => '+96522220000',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'phone-foodex',
            'provider_account_id' => 'waba-foodex',
            'provider_app_id' => 'app-foodex',
            'provider_metadata' => ['graph_version' => 'v26.0'],
            'status' => 'ACTIVE',
            'health_status' => 'READY',
            'is_primary' => true,
            'receive_only' => false,
            'primary_slot' => 1,
            'configuration_revision' => 1,
        ]);
        $profile->credential_secret = 'meta-template-token';
        $profile->save();

        return $profile;
    }

    private function template(): int
    {
        return app(TemplateRegistryService::class)->upsert([
            'name' => 'Approved Offer',
            'provider_name' => 'approved_offer_'.strtolower(Str::random(6)),
            'locale' => 'en',
            'category' => 'marketing',
            'provider_status' => 'approved',
            'body_preview' => 'Offer',
            'is_active' => true,
        ]);
    }

    private function store(string $channel, string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $channel, string $phone): int
    {
        $user = User::factory()->create([
            'name' => 'Campaign Builder Customer',
            'email' => 'builder-'.Str::uuid().'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);
        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'test',
            'registered_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function xlsxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-import-');
        if (! is_string($path)) {
            $this->fail('Unable to create XLSX test file.');
        }
        @unlink($path);
        $path .= '.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c><c r="B1" t="inlineStr"><is><t>Phone</t></is></c><c r="C1" t="inlineStr"><is><t>Email</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>XLSX Customer</t></is></c><c r="B2" t="inlineStr"><is><t>+96550000333</t></is></c><c r="C2" t="inlineStr"><is><t>xlsx@example.test</t></is></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>Formula Customer</t></is></c><c r="B3"><f>1+1</f><v>96559999999</v></c><c r="C3" t="inlineStr"><is><t>formula@example.test</t></is></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }
}
