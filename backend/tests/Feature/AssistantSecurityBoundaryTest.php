<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Security\AiPrincipalContextResolver;
use App\Domain\Assistant\Security\AssistantToolExecutor;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AssistantSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_principal_context_is_server_derived_and_cannot_be_forged_cross_store(): void
    {
        $storeA = $this->store('b2c', 'AI-SEC-RA');
        $storeB = $this->store('b2c', 'AI-SEC-RB');
        $actor = $this->storeUser($storeA, 'B2C_STORE_ADMIN');

        $principal = app(AiPrincipalContextResolver::class)->resolve(
            $actor,
            'en',
            [
                'path' => '/admin/b2c/dashboard',
                'channel' => 'b2c',
                'store_id' => $storeA,
                'authorized_entities' => ['store_id' => $storeA],
            ],
            'ai-sec-principal',
        );

        $this->assertSame($actor->getKey(), $principal->actorUserId);
        $this->assertSame('retail', $principal->businessScope);
        $this->assertSame('b2c', $principal->channel);
        $this->assertSame($storeA, $principal->activeStoreId);
        $this->assertSame([$storeA], $principal->permittedStoreIds);
        $this->assertContains('B2C_STORE_ADMIN', $principal->roleCodes);
        $this->assertContains('assistant.use', $principal->permissionCodes);
        $this->assertContains('reports.view', $principal->permissionCodes);

        $this->expectException(NotFoundHttpException::class);

        app(AiPrincipalContextResolver::class)->resolve(
            $actor,
            'en',
            [
                'path' => '/admin/b2c/dashboard',
                'channel' => 'b2c',
                'store_id' => $storeB,
            ],
            'ai-sec-forged',
        );
    }

    public function test_wholesale_sellout_requires_linked_active_consent_and_revocation_is_immediate(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('b2c', 'AI-SEC-R');
        $this->linkRetailWholesaleAccount($retailStore);

        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $retailOwner = $this->storeUser($retailStore, 'B2C_STORE_ADMIN');
        $policy = app(RetailerSellOutAccessPolicy::class);

        $this->assertDenied(
            fn () => $policy->assertCanRead($wholesaleActor, $retailStore, $wholesaleStore, locale: 'en'),
            'Retail sell-out data is not shared for this Assistant request.',
            [$retailStore, $wholesaleStore],
        );

        $grant = $policy->grant(
            $retailOwner,
            $retailStore,
            $wholesaleStore,
            RetailerSellOutAccessPolicy::BASIS_OPT_IN,
            retentionDays: 90,
            expiresAt: now()->addDays(30),
        );

        $this->assertSame($retailStore, $grant['retailer_store_id']);
        $this->assertSame($wholesaleStore, $grant['wholesale_store_id']);
        $this->assertSame(RetailerSellOutAccessPolicy::BASIS_OPT_IN, $grant['basis']);
        $this->assertSame(90, $grant['retention_days']);

        $authorized = $policy->assertCanRead(
            $wholesaleActor,
            $retailStore,
            $wholesaleStore,
            locale: 'en',
        );
        $this->assertSame($grant['grant_id'], $authorized['grant_id']);

        $principal = app(AiPrincipalContextResolver::class)->resolve(
            $wholesaleActor,
            'en',
            ['path' => '/admin/b2b/dashboard', 'channel' => 'b2b', 'store_id' => $wholesaleStore],
            'ai-sec-wholesale',
        );
        $this->assertSame($retailStore, $principal->retailerDataGrants[0]['retailer_store_id']);

        $policy->revoke($retailOwner, $retailStore, $wholesaleStore);

        $this->assertDenied(
            fn () => $policy->assertCanRead($wholesaleActor, $retailStore, $wholesaleStore, locale: 'en'),
            'Retail sell-out data is not shared for this Assistant request.',
            [$retailStore, $wholesaleStore],
        );

        $this->assertDatabaseHas('assistant_retailer_data_grants', [
            'retailer_store_id' => $retailStore,
            'wholesale_store_id' => $wholesaleStore,
            'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'assistant.sellout.consent.granted']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'assistant.sellout.read']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'assistant.sellout.consent.revoked']);
    }

    public function test_cross_retailer_aggregation_requires_consent_for_every_retailer(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailA = $this->store('b2c', 'AI-SEC-R1');
        $retailB = $this->store('b2c', 'AI-SEC-R2');
        $this->linkRetailWholesaleAccount($retailA);
        $this->linkRetailWholesaleAccount($retailB);

        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $ownerA = $this->storeUser($retailA, 'B2C_STORE_ADMIN');
        $policy = app(RetailerSellOutAccessPolicy::class);

        $policy->grant(
            $ownerA,
            $retailA,
            $wholesaleStore,
            RetailerSellOutAccessPolicy::BASIS_CONTRACT,
        );

        $this->assertDenied(
            fn () => $policy->assertCanReadMany(
                $wholesaleActor,
                [$retailA, $retailB],
                $wholesaleStore,
                locale: 'en',
            ),
            'Retail sell-out data is not shared for this Assistant request.',
            [$retailA, $retailB, $wholesaleStore],
        );
    }

    public function test_consent_denial_is_localized_and_does_not_disclose_hidden_identifiers(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('b2c', 'AI-SEC-R-L10N');
        $this->linkRetailWholesaleAccount($retailStore);
        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $policy = app(RetailerSellOutAccessPolicy::class);

        $this->assertDenied(
            fn () => $policy->assertCanRead($wholesaleActor, $retailStore, $wholesaleStore, locale: 'en'),
            'Retail sell-out data is not shared for this Assistant request.',
            [$retailStore, $wholesaleStore],
        );
        $this->assertDenied(
            fn () => $policy->assertCanRead($wholesaleActor, $retailStore, $wholesaleStore, locale: 'ar'),
            'بيانات البيع للمستهلك غير متاحة للمساعد لهذا الطلب.',
            [$retailStore, $wholesaleStore],
        );
    }

    public function test_central_tool_executor_reauthorizes_scope_before_tool_execution(): void
    {
        $storeA = $this->store('b2c', 'AI-SEC-TOOL-A');
        $storeB = $this->store('b2c', 'AI-SEC-TOOL-B');
        $actor = $this->storeUser($storeA, 'B2C_STORE_ADMIN');

        $tool = new class implements AssistantToolInterface
        {
            public bool $executed = false;

            public function key(): string
            {
                return 'security.test';
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                $this->executed = true;

                return new AssistantToolResult(data: ['store_id' => $request->storeId]);
            }
        };

        $executor = new AssistantToolExecutor(
            new AssistantToolRegistry([$tool]),
            app(AiPrincipalContextResolver::class),
            app(RetailerSellOutAccessPolicy::class),
            app(AuditLogger::class),
        );

        try {
            $executor->execute(
                'security.test',
                new AssistantToolRequest(
                    actorUserId: (int) $actor->getKey(),
                    locale: 'en',
                    channel: 'b2c',
                    storeId: $storeB,
                    context: ['path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $storeB],
                ),
                'ai-sec-denied-tool',
            );
            $this->fail('Cross-store tool execution should have been rejected.');
        } catch (NotFoundHttpException) {
            $this->assertFalse($tool->executed);
        }

        $result = $executor->execute(
            'security.test',
            new AssistantToolRequest(
                actorUserId: (int) $actor->getKey(),
                locale: 'en',
                channel: 'b2c',
                storeId: $storeA,
                context: ['path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $storeA],
            ),
            'ai-sec-allowed-tool',
        );

        $this->assertTrue($tool->executed);
        $this->assertSame($storeA, $result->data['store_id']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'assistant.tool.read']);
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', strtoupper($channel))
            ->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkRetailWholesaleAccount(int $retailStoreId): void
    {
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'Retail link '.$retailStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStoreId,
            'b2b_customer_id' => $customerId,
            'owner_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@ai-security.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.$storeId.'-'.uniqid().'@ai-security.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @param list<int> $hiddenIds */
    private function assertDenied(callable $callback, string $expectedMessage, array $hiddenIds): void
    {
        try {
            $callback();
            $this->fail('Expected Assistant sell-out access to be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame($expectedMessage, $exception->getMessage());

            foreach ($hiddenIds as $hiddenId) {
                $this->assertStringNotContainsString((string) $hiddenId, $exception->getMessage());
            }
        }
    }
}
