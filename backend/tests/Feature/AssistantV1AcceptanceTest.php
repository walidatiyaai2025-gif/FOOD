<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Conversation\DeterministicBrain;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Http\Controllers\Admin\AssistantController;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

class AssistantV1AcceptanceTest extends TestCase
{
    public function test_current_page_authorized_entity_is_resolved_deterministically(): void
    {
        $tool = new class implements AssistantToolInterface
        {
            public function key(): string
            {
                return 'orders.lookup';
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                return new AssistantToolResult(data: ['order_id' => $request->entities['order_id'] ?? null]);
            }
        };

        $brain = new DeterministicBrain(new AssistantToolRegistry([$tool]));
        $result = $brain->respond(new AssistantBrainRequest(
            message: 'find order this',
            locale: 'en',
            context: [
                'authorized_entities' => [
                    'order_id' => 42,
                    'store_id' => 7,
                    'channel' => 'b2c',
                ],
            ],
        ));

        $this->assertSame('orders.lookup', $result->intent);
        $this->assertSame(42, $result->state['entities']['order_id'] ?? null);
        $this->assertSame(7, $result->state['entities']['store_id'] ?? null);
        $this->assertSame('b2c', $result->state['entities']['channel'] ?? null);
        $this->assertTrue((bool) ($result->state['reference_resolved'] ?? false));
    }

    public function test_assistant_domain_has_no_external_ai_or_process_runtime_dependency(): void
    {
        $source = '';

        foreach (File::allFiles(app_path('Domain/Assistant')) as $file) {
            $source .= "\n".$file->getContents();
        }

        foreach ([
            'OpenAI',
            'Anthropic',
            'Gemini',
            'GuzzleHttp\\',
            'curl_',
            'Http::get(',
            'Http::post(',
            'Http::send(',
            'Symfony\\Component\\Process',
            'shell_exec(',
            'proc_open(',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }

        $composer = strtolower(File::get(base_path('composer.json')));

        foreach (['"openai/', '"anthropic/', '"google/generative-ai', '"guzzlehttp/guzzle'] as $package) {
            $this->assertStringNotContainsString($package, $composer);
        }
    }

    public function test_authoritative_tool_services_are_read_only_and_query_bounded(): void
    {
        $business = File::get(app_path('Domain/Assistant/Business/AssistantBusinessReadService.php'));
        $operations = File::get(app_path('Domain/Assistant/Operations/AssistantOperationsReadService.php'));
        $toolSource = $business."\n".$operations;

        foreach ([
            '->insert(',
            '->update(',
            '->delete(',
            '->upsert(',
            '->increment(',
            '->decrement(',
            '::create(',
            '->save(',
        ] as $writeCall) {
            $this->assertStringNotContainsString($writeCall, $toolSource);
        }

        $this->assertStringContainsString('diffInDays($toDate) > 366', $business);
        $this->assertStringContainsString('diffInDays($toDate) > 366', $operations);
        $this->assertStringContainsString('->limit(25)', $business);
        $this->assertStringContainsString('private const MAX_ROWS = 50;', $operations);
        $this->assertStringContainsString('->limit(self::MAX_ROWS)', $operations);
    }

    public function test_safe_deep_link_allowlist_covers_supported_assistant_destinations_only(): void
    {
        $routes = (new ReflectionClass(AssistantController::class))->getConstant('ACTION_ROUTES');

        $this->assertIsArray($routes);

        foreach ([
            'admin.b2b.module',
            'admin.b2c.module',
            'admin.catalog.index',
            'admin.operations.orders.index',
            'admin.reports.index',
            'admin.retail-stores.index',
        ] as $route) {
            $this->assertContains($route, $routes);
        }

        foreach ([
            'admin.security.index',
            'admin.system-update.store',
            'admin.business.inventory.adjust',
            'admin.b2b.orders.status',
            'admin.b2c.orders.status',
        ] as $unsafeRoute) {
            $this->assertNotContains($unsafeRoute, $routes);
        }
    }
}
