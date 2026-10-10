<?php

namespace Tests\Feature;

use App\Http\Middleware\CustomerConditionalGet;
use Illuminate\Http\Request;
use Tests\TestCase;

class CustomerLowDataConditionalGetTest extends TestCase
{
    public function test_customer_low_data_json_response_gets_etag_and_returns_304_when_unchanged(): void
    {
        $middleware = app(CustomerConditionalGet::class);

        $request = Request::create('/api/v1/products?store=7', 'GET', server: [
            'HTTP_X_FOODEX_LOW_DATA' => '1',
            'HTTP_X_FOODEX_CLIENT' => 'customer',
        ]);

        $first = $middleware->handle(
            $request,
            static fn () => response()->json([
                'data' => [
                    ['id' => 1, 'name' => 'Milk'],
                ],
            ]),
        );

        $etag = $first->headers->get('ETag');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertNotNull($etag);
        $this->assertSame('1', $first->headers->get('X-FOODEX-Conditional'));
        $this->assertSame((string) strlen((string) $first->getContent()), $first->headers->get('X-FOODEX-Payload-Bytes'));
        $this->assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));

        $secondRequest = Request::create('/api/v1/products?store=7', 'GET', server: [
            'HTTP_X_FOODEX_LOW_DATA' => '1',
            'HTTP_X_FOODEX_CLIENT' => 'customer',
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        $second = $middleware->handle(
            $secondRequest,
            static fn () => response()->json([
                'data' => [
                    ['id' => 1, 'name' => 'Milk'],
                ],
            ]),
        );

        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame('', (string) $second->getContent());
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertSame('1', $second->headers->get('X-FOODEX-Conditional'));
    }

    public function test_sensitive_customer_low_data_payload_is_private_and_varies_by_identity(): void
    {
        $middleware = app(CustomerConditionalGet::class);

        $request = Request::create('/api/v1/orders', 'GET', server: [
            'HTTP_X_FOODEX_LOW_DATA' => '1',
            'HTTP_X_FOODEX_CLIENT' => 'customer',
            'HTTP_AUTHORIZATION' => 'Bearer test-token',
        ]);

        $response = $middleware->handle(
            $request,
            static fn () => response()->json(['data' => []]),
        );

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $vary = (string) $response->headers->get('Vary');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('Authorization', $vary);
        $this->assertStringContainsString('X-Guest-Token', $vary);
        $this->assertStringContainsString('X-FOODEX-Data-Mode', $vary);
    }

    public function test_non_customer_or_non_low_data_requests_are_untouched(): void
    {
        $middleware = app(CustomerConditionalGet::class);
        $request = Request::create('/api/v1/products', 'GET');

        $response = $middleware->handle(
            $request,
            static fn () => response()->json(['data' => []]),
        );

        $this->assertNull($response->headers->get('ETag'));
        $this->assertNull($response->headers->get('X-FOODEX-Conditional'));
    }
}
