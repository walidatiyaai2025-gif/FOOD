<?php

use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureFreshDriverLocation;
use App\Http\Middleware\EnsureManagementDashboardAccess;
use App\Http\Middleware\ResolveCustomerPreviewSession;
use App\Http\Middleware\ResolveDriverPreviewSession;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\RunSchedulerHeartbeat;
use App\Services\SystemInspectorRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
        then: static function (): void {
            require base_path('routes/notifications.php');
            require base_path('routes/coupons.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RunSchedulerHeartbeat::class);
        $middleware->api(prepend: [CorrelationId::class]);

        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'driver.location.fresh' => EnsureFreshDriverLocation::class,
            'management.dashboard' => EnsureManagementDashboardAccess::class,
            'tenant.context' => ResolveTenantContext::class,
            'preview.customer' => ResolveCustomerPreviewSession::class,
            'preview.driver' => ResolveDriverPreviewSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')
                && ! $exception instanceof \\Illuminate\\Validation\\ValidationException
                && ! $exception instanceof \\Illuminate\\Auth\\AuthenticationException) {
                $status = $exception instanceof \\Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface
                    ? $exception->getStatusCode()
                    : 500;

                if ($status >= 500) {
                    app(SystemInspectorRecorder::class)->recordException($exception, $request);
                }
            }

            if ($request->is('admin/*')) {
                app(SystemInspectorRecorder::class)->recordException($exception, $request);

                if (! $request->expectsJson()
                    && ! $request->isMethod('GET')
                    && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $rawMessage = trim($exception->getMessage());
                    $userLocale = $request->user()?->locale;
                    $locale = in_array($userLocale, ['ar', 'en'], true) ? $userLocale : app()->getLocale();
                    $knownMessages = [
                        'No approved B2B price exists for this product.' => [
                            'ar' => 'لا يوجد سعر جملة معتمد لهذا المنتج ضمن شريحة تسعير العميل. أضف سعرًا معتمدًا للمنتج ثم أعد المحاولة.',
                            'en' => 'No approved Wholesale price exists for this product in the customer price tier. Add an approved price and try again.',
                        ],
                        'Approved B2B pricing account is required.' => [
                            'ar' => 'يجب أن يكون للعميل حساب تسعير جملة نشط وشريحة سعر معتمدة قبل إنشاء الطلب.',
                            'en' => 'The customer needs an active Wholesale pricing account and an approved price tier before an order can be created.',
                        ],
                        'Order already has an active driver assignment.' => [
                            'ar' => 'هذا الطلب لديه سائق مسند بالفعل. أنهِ أو ألغِ الإسناد الحالي قبل تعيين سائق آخر.',
                            'en' => 'This order already has an active driver assignment. Complete or clear the current assignment before assigning another driver.',
                        ],
                        'Retail-linked Wholesale account status is controlled by the Retail store status.' => [
                            'ar' => 'حالة حساب الجملة المرتبط بمتجر التجزئة تُدار من حالة متجر التجزئة نفسه.',
                            'en' => 'The linked Wholesale account status is controlled by the Retail store status.',
                        ],
                        'Driver and order must belong to the same store.' => [
                            'ar' => 'يجب أن يكون السائق والطلب تابعين لنفس المتجر.',
                            'en' => 'The driver and order must belong to the same store.',
                        ],
                        'Driver and order channels must match.' => [
                            'ar' => 'نوع السائق لا يطابق قناة الطلب.',
                            'en' => 'The driver type does not match the order channel.',
                        ],
                    ];

                    $isActionableConflict = array_key_exists($rawMessage, $knownMessages);

                    if ($isActionableConflict) {
                        $message = $knownMessages[$rawMessage][$locale] ?? $rawMessage;
                        if ($message === '') {
                            $message = $locale === 'ar'
                                ? 'تعذر تنفيذ العملية المطلوبة. راجع البيانات وحاول مرة أخرى.'
                                : 'The requested action could not be completed. Review the data and try again.';
                        }

                        return back()
                            ->withInput()
                            ->withErrors(['operation' => $message]);
                    }
                }
            }

            if ($request->is('api/*')) {
                app(SystemInspectorRecorder::class)->recordException($exception, $request);
            }

            if (! $request->is('api/*') || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $exception instanceof \Illuminate\Validation\ValidationException || $exception instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            $correlationId = $request->attributes->get('correlation_id');
            \Illuminate\Support\Facades\Log::error('Unhandled API exception', [
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'An unexpected server error occurred.',
                'correlation_id' => $correlationId,
            ], 500);
        });
    })->create();
