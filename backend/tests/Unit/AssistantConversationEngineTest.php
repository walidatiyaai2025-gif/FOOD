<?php

namespace Tests\Unit;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Conversation\DeterministicBrain;
use App\Domain\Assistant\Conversation\EntityExtractor;
use App\Domain\Assistant\Conversation\TextNormalizer;
use App\Domain\Assistant\Conversation\TimeWindowParser;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AssistantConversationEngineTest extends TestCase
{
    public function test_arabic_common_phrase_maps_to_sales_summary_and_today(): void
    {
        $result = $this->brain(['sales.summary'])->respond(new AssistantBrainRequest(
            message: 'عاوز مبيعات اليوم',
            locale: 'ar',
        ));

        $this->assertSame('sales.summary', $result->intent);
        $this->assertGreaterThanOrEqual(0.75, $result->confidence);
        $this->assertSame('today', $result->state['period']['key']);
    }

    public function test_english_common_phrase_maps_to_late_orders_and_yesterday(): void
    {
        $result = $this->brain(['orders.late'])->respond(new AssistantBrainRequest(
            message: 'Show delayed orders from yesterday',
            locale: 'en',
        ));

        $this->assertSame('orders.late', $result->intent);
        $this->assertSame('late', $result->state['entities']['status']);
        $this->assertSame('yesterday', $result->state['period']['key']);
    }

    public function test_mixed_arabic_english_business_terms_are_supported(): void
    {
        $result = $this->brain(['sales.summary'])->respond(new AssistantBrainRequest(
            message: 'مبيعات B2B اليوم',
            locale: 'ar',
        ));

        $this->assertSame('sales.summary', $result->intent);
        $this->assertSame('B2B', $result->state['entities']['channel']);
    }

    public function test_entity_extractor_handles_ids_names_statuses_and_channel(): void
    {
        $normalizer = new TextNormalizer;
        $extractor = new EntityExtractor($normalizer);
        $message = 'customer 42 customer named Ahmed Ali today, store 7, driver 9, product 11, B2C cancelled';

        $entities = $extractor->extract($message, $normalizer->normalize($message));

        $this->assertSame(42, $entities['customer_id']);
        $this->assertSame('Ahmed Ali', $entities['customer_name']);
        $this->assertSame(7, $entities['store_id']);
        $this->assertSame(9, $entities['driver_id']);
        $this->assertSame(11, $entities['product_id']);
        $this->assertSame('B2C', $entities['channel']);
        $this->assertSame('cancelled', $entities['status']);
    }

    public function test_relative_time_windows_are_deterministic(): void
    {
        $parser = new TimeWindowParser;
        $now = CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuwait');

        $today = $parser->parse('sales today', $now);
        $yesterday = $parser->parse('orders yesterday', $now);
        $thisWeek = $parser->parse('sales this week', $now);
        $lastWeek = $parser->parse('sales last week', $now);

        $this->assertSame('today', $today['key']);
        $this->assertSame('2026-10-01T00:00:00+03:00', $today['start']);
        $this->assertSame('yesterday', $yesterday['key']);
        $this->assertSame('2026-09-30T00:00:00+03:00', $yesterday['start']);
        $this->assertSame('this_week', $thisWeek['key']);
        $this->assertSame('2026-09-28T00:00:00+03:00', $thisWeek['start']);
        $this->assertSame('last_week', $lastWeek['key']);
        $this->assertSame('2026-09-21T00:00:00+03:00', $lastWeek['start']);
    }

    public function test_multi_turn_reference_resolves_authorized_entities_and_comparison(): void
    {
        $result = $this->brain(['sales.compare'])->respond(new AssistantBrainRequest(
            message: 'قارنهم بالأسبوع اللي فات',
            locale: 'ar',
            state: [
                'last_intent' => 'sales.summary',
                'last_authorized_entities' => ['store_id' => 12],
            ],
        ));

        $this->assertSame('sales.compare', $result->intent);
        $this->assertSame(['store_id' => 12], $result->state['entities']);
        $this->assertTrue($result->state['reference_resolved']);
        $this->assertSame('last_week', $result->state['period']['key']);
    }

    public function test_medium_confidence_asks_one_focused_clarification(): void
    {
        $result = $this->brain(['sales.compare', 'stores.compare'])->respond(new AssistantBrainRequest(
            message: 'compare',
            locale: 'en',
        ));

        $this->assertNotNull($result->intent);
        $this->assertGreaterThanOrEqual(0.45, $result->confidence);
        $this->assertLessThan(0.75, $result->confidence);
        $this->assertTrue($result->state['pending_clarification']);
        $this->assertStringEndsWith('?', $result->message);
    }

    public function test_low_confidence_returns_bounded_supported_topic_fallback(): void
    {
        $result = $this->brain(['sales.summary'])->respond(new AssistantBrainRequest(
            message: 'tell me a joke',
            locale: 'en',
        ));

        $this->assertNull($result->intent);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame(['Sales today', 'Late orders', 'Daily brief'], $result->suggestedPrompts);
    }

    public function test_brain_never_selects_an_unregistered_tool(): void
    {
        $result = $this->brain(['sales.summary'])->respond(new AssistantBrainRequest(
            message: 'late orders',
            locale: 'en',
        ));

        $this->assertNull($result->intent);
        $this->assertSame(0.0, $result->confidence);
        $this->assertStringContainsString('not currently registered', $result->message);
    }

    /** @param list<string> $keys */
    private function brain(array $keys): DeterministicBrain
    {
        $tools = array_map(static fn (string $key): AssistantToolInterface => new class($key) implements AssistantToolInterface
        {
            private string $toolKey;

            public function __construct(string $toolKey)
            {
                $this->toolKey = $toolKey;
            }

            public function key(): string
            {
                return $this->toolKey;
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                return new AssistantToolResult(data: ['actor_user_id' => $request->actorUserId]);
            }
        }, $keys);

        return new DeterministicBrain(new AssistantToolRegistry($tools));
    }
}
