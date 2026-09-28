<?php

use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureManagementDashboardAccess;
use App\Http\Middleware\ResolveTenantContext;
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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [CorrelationId::class]);

        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'management.dashboard' => EnsureManagementDashboardAccess::class,
            'tenant.context' => ResolveTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            if ($request->is('admin/*')) {
                app(SystemInspectorRecorder::class)->recordException($exception, $request);

                if (! $request->expectsJson()
                    && ! $request->isMethod('GET')
                    && ! $request->isMethod('HEAD')
                    && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $status = $exception->getStatusCode();
                    $rawMessage = trim($exception->getMessage());
                    $userLocale = $request->user()?->locale;
                    $locale = in_array($userLocale, ['ar', 'en'], true) ? $userLocale : app()->getLocale();
                    $knownMessages = [
                        'No approved B2B price exists for this product.' => [
                            'ar' => 'لا يوجد سعر جملة معتمد لهذا المنتج ضمن شريحة تسعير العميل. أضف أو فعّل سعر المنتج في «التسعير والموافقات» ثم أعد إنشاء الطلب.',
                            'en' => 'No approved Wholesale price exists for this product in the customer price tier. Add or activate the product price under Pricing & Approvals, then retry the order.',
                        ],
                        'Approved B2B pricing account is required.' => [
                            'ar' => 'هذا العميل لا يملك حساب تسعير جملة نشطًا ومعتمدًا. حدّد شريحة التسعير للعميل أولاً ثم أعد المحاولة.',
                            'en' => 'This customer does not have an active approved Wholesale pricing account. Assign a price tier first, then retry.',
                        ],
                        'Order already has an active driver assignment.' => [
                            'ar' => 'هذا الطلب لديه سائق مُعيّن بالفعل. أنهِ أو ألغِ التعيين الحالي قبل إسناد سائق آخر.',
                            'en' => 'This order already has an active driver assignment. Complete or clear the current assignment before assigning another driver.',
                        ],
                        'Retail-linked Wholesale account status is controlled by the Retail store status.' => [
                            'ar' => 'حالة حساب الجملة المرتبط بمتجر تجزئة تُدار من حالة متجر التجزئة نفسه.',
                            'en' => 'The Wholesale account linked to a Retail store is controlled by the Retail store status.',
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

                    $message = $knownMessages[$rawMessage][$locale] ?? $rawMessage;
                    if ($message === '') {
                        $message = match ($status) {
                            401 => $locale === 'ar' ? 'انتهت جلسة الدخول. سجّل الدخول مرة أخرى.' : 'Your session has expired. Sign in again.',
                            403 => $locale === 'ar' ? 'ليس لديك صلاحية لتنفيذ هذه العملية.' : 'You do not have permission to perform this action.',
                            404 => $locale === 'ar' ? 'السجل المطلوب غير موجود أو خارج نطاق صلاحياتك.' : 'The requested record was not found or is outside your access scope.',
                            409 => $locale === 'ar' ? 'تعذر تنفيذ العملية بسبب تعارض في حالة البيانات الحالية.' : 'The action conflicts with the current data state.',
                            419 => $locale === 'ar' ? 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.' : 'The page session expired. Refresh the page and try again.',
                            422 => $locale === 'ar' ? 'تعذر تنفيذ العملية. راجع البيانات المطلوبة وحاول مرة أخرى.' : 'The action could not be completed. Review the required data and try again.',
                            default => $locale === 'ar' ? 'تعذر تنفيذ العملية (' . $status . ').' : 'The action could not be completed (' . $status . ').',
                        };
                    }

                    return back()
                        ->withInput()
                        ->withErrors(['operation' => $message]);
                }
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
