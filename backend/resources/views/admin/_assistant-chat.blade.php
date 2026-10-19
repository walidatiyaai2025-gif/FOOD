@php
    $assistantUser = $user ?? auth()->user();
    $assistantRuntime = app(\App\Services\AssistantRuntimeSettings::class);
    $assistantEnabled = $assistantRuntime->enabled() && $assistantRuntime->readOnly();
    $assistantAuthorized = false;

    if ($assistantEnabled && $assistantUser instanceof \App\Models\User) {
        $assistantAuthorized = $assistantUser->hasPermission('assistant.use');

        if (! $assistantAuthorized) {
            $assistantAuthorized = $assistantUser->storeRoleAssignments()
                ->whereHas('role', fn ($roleQuery) => $roleQuery
                    ->where('roles.is_active', true)
                    ->whereHas('permissions', fn ($permissionQuery) => $permissionQuery->where('code', 'assistant.use')))
                ->exists();
        }
    }

    $assistantArabic = app()->getLocale() === 'ar';
    $assistantCopy = $assistantArabic
        ? [
            'title' => 'مساعد FOODEX',
            'subtitle' => 'مساعد تشغيلي للقراءة فقط',
            'open' => 'فتح المساعد',
            'close' => 'تصغير المساعد',
            'new' => 'محادثة جديدة',
            'clear' => 'مسح المحادثة',
            'empty' => 'ابدأ بسؤال عن المبيعات أو الطلبات أو المتاجر أو العملاء أو السائقين أو المخزون.',
            'placeholder' => 'اكتب سؤالك هنا…',
            'send' => 'إرسال',
            'thinking' => 'جاري تجهيز الرد…',
            'error' => 'تعذر تحميل المساعد الآن. يمكنك متابعة العمل في FOODEX بشكل طبيعي.',
            'retry' => 'إعادة المحاولة',
            'you' => 'أنت',
            'assistant' => 'مساعد FOODEX',
            'open_action' => 'فتح',
        ]
        : [
            'title' => 'FOODEX Assistant',
            'subtitle' => 'Read-only operations copilot',
            'open' => 'Open Assistant',
            'close' => 'Minimize Assistant',
            'new' => 'New conversation',
            'clear' => 'Clear conversation',
            'empty' => 'Start with a question about sales, orders, stores, customers, drivers, or inventory.',
            'placeholder' => 'Ask FOODEX…',
            'send' => 'Send',
            'thinking' => 'Preparing the answer…',
            'error' => 'Assistant is unavailable right now. You can keep using FOODEX normally.',
            'retry' => 'Retry',
            'you' => 'You',
            'assistant' => 'FOODEX Assistant',
            'open_action' => 'Open',
        ];
@endphp

@if ($assistantEnabled && $assistantAuthorized)
    <link rel="stylesheet" href="{{ asset('assets/admin/assistant-chat.css') }}">

    <div
        class="foodex-assistant"
        data-foodex-assistant
        data-user-id="{{ $assistantUser->id }}"
        data-locale="{{ app()->getLocale() }}"
        data-base-url="{{ url('/admin/assistant') }}"
        data-bootstrap-url="{{ url('/admin/assistant/bootstrap') }}"
        data-csrf-token="{{ csrf_token() }}"
        data-label-empty="{{ $assistantCopy['empty'] }}"
        data-label-thinking="{{ $assistantCopy['thinking'] }}"
        data-label-error="{{ $assistantCopy['error'] }}"
        data-label-retry="{{ $assistantCopy['retry'] }}"
        data-label-you="{{ $assistantCopy['you'] }}"
        data-label-assistant="{{ $assistantCopy['assistant'] }}"
        data-label-open-action="{{ $assistantCopy['open_action'] }}"
        data-label-scope="{{ __('assistant_ui.scope_label') }}"
        data-label-scope-pending="{{ __('assistant_ui.scope_pending') }}"
        data-label-current-page="{{ __('assistant_ui.current_page') }}"
        data-label-context-open="{{ __('assistant_ui.context_open') }}"
        data-label-context-prompt="{{ __('assistant_ui.context_prompt') }}"
        data-label-purchase-plan-prompt="{{ __('assistant_ui.purchase_plan_prompt') }}"
        data-label-domain-wholesale="{{ __('assistant_ui.domain_wholesale') }}"
        data-label-domain-retail="{{ __('assistant_ui.domain_retail') }}"
        data-label-domain-analytics="{{ __('assistant_ui.domain_analytics') }}"
        data-label-domain-operations="{{ __('assistant_ui.domain_operations') }}"
        data-label-domain-catalog="{{ __('assistant_ui.domain_catalog') }}"
        data-label-domain-management="{{ __('assistant_ui.domain_management') }}"
        data-label-evidence="{{ __('assistant_ui.evidence') }}"
        data-label-evidence-items="{{ __('assistant_ui.evidence_items') }}"
        data-label-evidence-domain="{{ __('assistant_ui.evidence_domain') }}"
        data-label-evidence-metric="{{ __('assistant_ui.evidence_metric') }}"
        data-label-evidence-authorized="{{ __('assistant_ui.evidence_authorized') }}"
        data-label-fresh="{{ __('assistant_ui.fresh') }}"
        data-label-stale="{{ __('assistant_ui.stale') }}"
        data-label-partial="{{ __('assistant_ui.partial') }}"
        data-label-complete="{{ __('assistant_ui.complete') }}"
        data-label-degraded="{{ __('assistant_ui.degraded') }}"
        data-label-denied="{{ __('assistant_ui.denied') }}"
        data-label-needs-clarification="{{ __('assistant_ui.needs_clarification') }}"
        data-label-findings="{{ __('assistant_ui.findings') }}"
        data-label-recommendations="{{ __('assistant_ui.recommendations') }}"
        data-label-approval-required="{{ __('assistant_ui.approval_required') }}"
        data-label-approval-preview="{{ __('assistant_ui.approval_preview') }}"
        data-label-expires="{{ __('assistant_ui.expires') }}"
        data-label-scope-denied="{{ __('assistant_ui.scope_denied') }}"
        data-label-consent-required="{{ __('assistant_ui.consent_required') }}"
        data-label-invalid-request="{{ __('assistant_ui.invalid_request') }}"
        data-label-data-unavailable="{{ __('assistant_ui.data_unavailable') }}"
        data-label-network-error="{{ __('assistant_ui.network_error') }}"
        data-label-retry-action="{{ __('assistant_ui.retry') }}"
        data-label-critical="{{ __('assistant_ui.critical') }}"
        data-label-warning="{{ __('assistant_ui.warning') }}"
        data-label-watch="{{ __('assistant_ui.watch') }}"
        data-label-sell-in="{{ __('assistant_ui.sell_in') }}"
        data-label-sell-out="{{ __('assistant_ui.sell_out') }}"
        data-label-yes="{{ __('assistant_ui.yes') }}"
        data-label-no="{{ __('assistant_ui.no') }}"
    >
        <button
            class="foodex-assistant-launcher"
            type="button"
            aria-controls="foodex-assistant-drawer"
            aria-expanded="false"
            data-assistant-open
        >
            <span class="foodex-assistant-avatar" aria-hidden="true">
                <svg viewBox="0 0 48 48" role="img">
                    <rect x="10" y="13" width="28" height="24" rx="10"></rect>
                    <circle cx="19" cy="24" r="2.5"></circle>
                    <circle cx="29" cy="24" r="2.5"></circle>
                    <path d="M18 31h12M24 13V8M21 8h6"></path>
                </svg>
            </span>
            <span class="foodex-assistant-launcher-copy">
                <strong>{{ $assistantCopy['title'] }}</strong>
                <small>{{ $assistantCopy['subtitle'] }}</small>
            </span>
            <span class="foodex-assistant-launcher-status" aria-hidden="true"></span>
            <span class="sr-only">{{ $assistantCopy['open'] }}</span>
        </button>

        <section
            id="foodex-assistant-drawer"
            class="foodex-assistant-drawer"
            role="dialog"
            aria-labelledby="foodex-assistant-title"
            aria-modal="false"
            aria-hidden="true"
            hidden
            data-assistant-drawer
        >
            <header class="foodex-assistant-header">
                <div class="foodex-assistant-heading">
                    <span class="foodex-assistant-avatar foodex-assistant-avatar-lg" aria-hidden="true">
                        <svg viewBox="0 0 48 48">
                            <rect x="10" y="13" width="28" height="24" rx="10"></rect>
                            <circle cx="19" cy="24" r="2.5"></circle>
                            <circle cx="29" cy="24" r="2.5"></circle>
                            <path d="M18 31h12M24 13V8M21 8h6"></path>
                        </svg>
                    </span>
                    <span>
                        <strong id="foodex-assistant-title">{{ $assistantCopy['title'] }}</strong>
                        <small>{{ $assistantCopy['subtitle'] }}</small>
                    </span>
                </div>
                <div class="foodex-assistant-header-actions">
                    <button type="button" class="foodex-assistant-icon-button" data-assistant-new aria-label="{{ $assistantCopy['new'] }}">＋</button>
                    <button type="button" class="foodex-assistant-icon-button" data-assistant-clear aria-label="{{ $assistantCopy['clear'] }}">↺</button>
                    <button type="button" class="foodex-assistant-icon-button" data-assistant-close aria-label="{{ $assistantCopy['close'] }}">×</button>
                </div>
            </header>

            <div class="foodex-assistant-scope" data-assistant-scope aria-live="polite">
                <span class="foodex-assistant-scope-label">{{ __('assistant_ui.scope_label') }}</span>
                <strong data-assistant-scope-primary>{{ __('assistant_ui.current_page') }}</strong>
                <span data-assistant-scope-secondary>{{ __('assistant_ui.scope_pending') }}</span>
            </div>

            <div class="foodex-assistant-status" data-assistant-status role="status" aria-live="polite"></div>

            <div class="foodex-assistant-messages" data-assistant-messages aria-live="polite" aria-busy="false">
                <div class="foodex-assistant-empty" data-assistant-empty>
                    <span class="foodex-assistant-avatar foodex-assistant-avatar-lg" aria-hidden="true">
                        <svg viewBox="0 0 48 48">
                            <rect x="10" y="13" width="28" height="24" rx="10"></rect>
                            <circle cx="19" cy="24" r="2.5"></circle>
                            <circle cx="29" cy="24" r="2.5"></circle>
                            <path d="M18 31h12M24 13V8M21 8h6"></path>
                        </svg>
                    </span>
                    <p>{{ $assistantCopy['empty'] }}</p>
                </div>
            </div>

            <div class="foodex-assistant-prompts" data-assistant-prompts aria-label="{{ $assistantCopy['title'] }}">
                @foreach($assistantArabic
                    ? ['مبيعات اليوم', 'الطلبات المتأخرة', 'ملخص اليوم']
                    : ['Sales today', 'Late orders', 'Daily brief'] as $prompt)
                    <button type="button" class="foodex-assistant-prompt" data-assistant-prompt>{{ $prompt }}</button>
                @endforeach
            </div>

            <form class="foodex-assistant-composer" data-assistant-form>
                <label class="sr-only" for="foodex-assistant-input">{{ $assistantCopy['placeholder'] }}</label>
                <textarea
                    id="foodex-assistant-input"
                    rows="1"
                    maxlength="2000"
                    autocomplete="off"
                    placeholder="{{ $assistantCopy['placeholder'] }}"
                    data-assistant-input
                ></textarea>
                <button type="submit" class="foodex-assistant-send" data-assistant-send>{{ $assistantCopy['send'] }}</button>
            </form>
        </section>
    </div>

    <script src="{{ asset('assets/admin/assistant-chat.js') }}" defer></script>
@endif
