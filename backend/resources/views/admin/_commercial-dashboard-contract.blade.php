@php
    $commercial = $moduleData['commercial'] ?? null;
    $isArCommercial = app()->getLocale() === 'ar';
@endphp

@if(is_array($commercial))
<section class="foodex-card" data-commercial-admin-surface="{{ $commercial['contract'] }}" style="margin-bottom:16px;padding:18px;display:grid;gap:14px">
    <div style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
        <div>
            <strong style="font-size:16px">
                {{ $isArCommercial
                    ? ($module === 'products' ? 'التحكم التجاري للمنتج' : 'عروض Flash')
                    : $commercial['title'] }}
            </strong>
            <p class="empty" style="margin:6px 0 0">
                {{ $isArCommercial
                    ? 'هذه الواجهة تستهلك العقد التجاري المركزي فقط. لا توجد حسابات أهلية أو حصص أو أسعار Flash داخل لوحة التحكم.'
                    : 'This surface consumes the canonical commercial backend contract only. Eligibility, quota and Flash pricing math is never duplicated in Dashboard.' }}
            </p>
        </div>
        <span class="badge" data-commercial-contract-status="{{ $commercial['mode'] }}">
            {{ $isArCommercial ? 'بانتظار عقد الباكند المركزي' : 'Canonical backend contract pending' }}
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        @foreach($commercial['sections'] as $section)
            <div class="foodex-card" style="padding:12px;box-shadow:none">
                <strong>{{ str_replace('_', ' ', ucwords($section, '_')) }}</strong>
                <div class="empty" style="margin-top:5px">
                    {{ $isArCommercial ? 'يتم الحفظ والتحقق من الخادم بعد توفر العقد.' : 'Server-owned save and validation once the canonical contract is available.' }}
                </div>
            </div>
        @endforeach
    </div>

    <div>
        <strong>{{ $isArCommercial ? 'عمليات الخادم المطلوبة' : 'Required server operations' }}</strong>
        <div class="module-links" style="margin-top:8px">
            @foreach($commercial['server_operations'] as $operation)
                <span class="badge">{{ $operation }}</span>
            @endforeach
        </div>
    </div>

    <details>
        <summary style="cursor:pointer;font-weight:700">{{ $isArCommercial ? 'أكواد أسباب الرفض القياسية' : 'Canonical rejection reason codes' }}</summary>
        <div class="module-links" style="margin-top:10px">
            @foreach($commercial['reason_codes'] as $reasonCode)
                <code class="badge">{{ $reasonCode }}</code>
            @endforeach
        </div>
    </details>

    <div class="flash" role="note" style="margin:0">
        {{ $isArCommercial
            ? 'إجراءات الإنشاء/التعديل/التفعيل معطلة عمدًا إلى أن ينشر #984/#985 العقود النهائية. هذا يمنع إنشاء محرك قواعد أو منطق عروض مكرر داخل الواجهة.'
            : 'Create/edit/activate mutations are intentionally gated until #984/#985 publish the final contracts. This prevents a second rule engine or duplicate Flash logic in Dashboard.' }}
    </div>
</section>
@endif
