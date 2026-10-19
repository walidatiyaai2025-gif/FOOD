<?php

namespace App\Providers;

use App\Domain\SmartOrdering\Contracts\OrderNotificationBridge;
use App\Domain\SmartOrdering\Events\SmartOrderingOrderCreated;
use App\Domain\SmartOrdering\Events\SmartOrderingOrderStatusChanged;
use App\Domain\SmartOrdering\Infrastructure\LaravelEventOrderNotificationBridge;
use App\Domain\SmartOrdering\WhatsApp\SmartOrderingWhatsAppBridge;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SmartOrderingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderNotificationBridge::class, LaravelEventOrderNotificationBridge::class);
    }

    public function boot(): void
    {
        Event::listen(
            SmartOrderingOrderCreated::class,
            static function (SmartOrderingOrderCreated $event): void {
                app(SmartOrderingWhatsAppBridge::class)->handleCreated($event);
            },
        );
        Event::listen(
            SmartOrderingOrderStatusChanged::class,
            static function (SmartOrderingOrderStatusChanged $event): void {
                app(SmartOrderingWhatsAppBridge::class)->handleStatusChanged($event);
            },
        );

        RateLimiter::for('smart-ordering-public', function (Request $request): array {
            $cartIdentity = trim((string) $request->header('X-Smart-Cart-Token', ''));

            return [
                Limit::perMinute(120)->by('smart-ordering-ip:'.$request->ip()),
                Limit::perMinute(90)->by('smart-ordering-cart:'.hash('sha256', $cartIdentity ?: $request->ip())),
            ];
        });

        Route::middleware(['api', 'throttle:smart-ordering-public'])
            ->prefix('api/smart-ordering')
            ->name('smart-ordering.')
            ->group(base_path('routes/smart-ordering-api.php'));

        Route::middleware(['web', 'management.dashboard', 'restaurant.setup:operations'])
            ->prefix('admin/smart-ordering')
            ->name('admin.smart-ordering.')
            ->group(base_path('routes/smart-ordering-admin.php'));
    }
}
