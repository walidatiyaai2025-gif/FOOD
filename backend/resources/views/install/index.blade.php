<!doctype html>
<html lang="ar" dir="rtl">
<head>
    @include('admin._brand')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>FOODEX Installer</title>
    <style>
        :root { color: #18212f; background: #f5f7fa; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: var(--foodex-font-ui); }
        .layout { min-height: 100vh; display: grid; grid-template-columns: minmax(260px, 320px) 1fr; }
        aside { background: #111827; color: #fff; padding: 28px 22px; }
        aside h1 { margin: 0 0 8px; font-size: 24px; }
        aside p { color: #cbd5e1; margin: 0 0 24px; line-height: 1.7; }
        ol { list-style: none; padding: 0; margin: 0; display: grid; gap: 6px; }
        .step-link, .step-disabled { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 8px; color: inherit; text-decoration: none; font-size: 14px; }
        .step-link:hover, .step-link.active { background: #1f2937; }
        .step-disabled { color: #64748b; }
        .step-number { display: inline-grid; place-items: center; width: 26px; height: 26px; border: 1px solid #475569; border-radius: 999px; flex: 0 0 auto; }
        .done .step-number { background: #e2e8f0; color: #111827; border-color: #e2e8f0; }
        main { padding: 48px clamp(22px, 5vw, 72px); }
        .card { max-width: 840px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px rgba(15,23,42,.05); }
        .eyebrow { color: #64748b; font-size: 14px; margin: 0 0 8px; }
        h2 { margin: 0 0 8px; font-size: 28px; }
        .description { margin: 0 0 28px; color: #64748b; line-height: 1.8; }
        .notice, .error, .success { border-radius: 10px; padding: 14px 16px; margin: 0 0 20px; line-height: 1.7; }
        .notice { background: #f8fafc; border: 1px solid #e2e8f0; }
        .error { background: #fff7ed; border: 1px solid #fdba74; }
        .success { background: #f0fdf4; border: 1px solid #86efac; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 18px; }
        label { display: grid; gap: 7px; font-size: 14px; font-weight: 700; }
        label.full { grid-column: 1 / -1; }
        input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 11px 12px; font: inherit; background: #fff; }
        small { color: #64748b; font-weight: 400; }
        .actions { display: flex; justify-content: flex-start; gap: 10px; margin-top: 28px; }
        button { border: 0; border-radius: 8px; background: #111827; color: white; padding: 12px 22px; font: inherit; font-weight: 700; cursor: pointer; }
        code { direction: ltr; unicode-bidi: embed; }
        ul { line-height: 1.8; }
        @media (max-width: 820px) { .layout { grid-template-columns: 1fr; } aside { padding: 20px; } main { padding: 22px; } .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="layout">
    <aside>
        <h1>FOODEX</h1>
        <p>FOODEX Setup Wizard<br>معالج الإعداد الأولي / First-run Setup Wizard</p>
        <ol>
            @foreach ($steps as $number => $item)
                @php($done = $number <= $completedStep)
                @php($available = $number <= $allowedStep)
                <li class="{{ $done ? 'done' : '' }}">
                    @if ($available)
                        <a class="step-link {{ $number === $currentStep ? 'active' : '' }}" href="{{ route('install.index', ['step' => $number]) }}">
                            <span class="step-number">{{ $number }}</span>
                            <span>{{ $item['title'] }}</span>
                        </a>
                    @else
                        <span class="step-disabled">
                            <span class="step-number">{{ $number }}</span>
                            <span>{{ $item['title'] }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </aside>

    <main>
        <section class="card">
            <p class="eyebrow">الخطوة {{ $currentStep }} من {{ count($steps) }}</p>
            <h2>{{ $step['title'] }}</h2>
            <p class="description">{{ $step['description'] }}</p>

            @if ($errorMessages !== [])
                <div class="error" role="alert">
                    <strong>تعذر إكمال الخطوة.</strong>
                    <ul>
                        @foreach ($errorMessages as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($currentStep === 2)
                @if ($requirementFailures === [])
                    <div class="success">متطلبات الخادم الأساسية متاحة.</div>
                @else
                    <div class="error"><ul>@foreach ($requirementFailures as $failure)<li>{{ $failure }}</li>@endforeach</ul></div>
                @endif
            @endif

            @if ($currentStep === 3)
                @if ($permissionFailures === [])
                    <div class="success">مجلدات التشغيل المطلوبة قابلة للكتابة.</div>
                @else
                    <div class="error"><ul>@foreach ($permissionFailures as $failure)<li>{{ $failure }}</li>@endforeach</ul></div>
                @endif
            @endif

            <form method="post" action="{{ route('install.step', ['step' => $currentStep]) }}" autocomplete="off">
                <input type="hidden" name="installer_token" value="{{ $installerToken }}">

                @if ($currentStep === 1)
                    <div class="notice">سيتم تنفيذ الإعداد بالتتابع، ولن يتم عرض كلمات المرور المخزنة أو الأسرار مرة أخرى.</div>
                @elseif ($currentStep === 4)
                    <div class="fields" dir="ltr">
                        <label>Database host<input name="db_host" value="{{ $input['db_host'] ?? ($values['DB_HOST'] ?: '127.0.0.1') }}" required></label>
                        <label>Port<input name="db_port" type="number" min="1" max="65535" value="{{ $input['db_port'] ?? ($values['DB_PORT'] ?: '5432') }}" required></label>
                        <label>Database<input name="db_database" value="{{ $input['db_database'] ?? ($values['DB_DATABASE'] ?: 'foodex') }}" required></label>
                        <label>Username<input name="db_username" value="{{ $input['db_username'] ?? ($values['DB_USERNAME'] ?: 'foodex') }}" required></label>
                        <label class="full">Password<input name="db_password" type="password" value=""><small>Stored passwords are never rendered back.</small></label>
                    </div>
                @elseif ($currentStep === 5)
                    <div class="notice">سيتم اختبار اتصال قاعدة البيانات باستخدام الإعدادات المحفوظة.</div>
                @elseif ($currentStep === 6)
                    <div class="fields">
                        <label>اسم المنصة<input name="app_name" value="{{ $input['app_name'] ?? ($values['APP_NAME'] ?: 'FOODEX') }}" required></label>
                        <label dir="ltr">Application URL<input name="app_url" type="url" value="{{ $input['app_url'] ?? ($values['APP_URL'] ?: 'http://localhost') }}" required></label>
                        <label>اللغة الأساسية
                            <select name="app_locale">
                                <option value="ar" @selected(($input['app_locale'] ?? ($values['APP_LOCALE'] ?: 'ar')) === 'ar')>العربية</option>
                                <option value="en" @selected(($input['app_locale'] ?? ($values['APP_LOCALE'] ?: 'ar')) === 'en')>English</option>
                            </select>
                        </label>
                    </div>
                @elseif ($currentStep === 7)
                    <div class="fields">
                        <label>الاسم<input name="admin_name" value="{{ $input['admin_name'] ?? '' }}" required></label>
                        <label dir="ltr">Email<input name="admin_email" type="email" value="{{ $input['admin_email'] ?? '' }}" required></label>
                        <label>اللغة
                            <select name="admin_locale">
                                <option value="ar">العربية</option>
                                <option value="en">English</option>
                            </select>
                        </label>
                        <label dir="ltr">Password<input name="admin_password" type="password" minlength="12" required></label>
                        <label class="full" dir="ltr">Confirm password<input name="admin_password_confirmation" type="password" minlength="12" required></label>
                    </div>
                @elseif ($currentStep === 8)
                    <div class="fields">
                        <label>Default storage disk
                            <select name="filesystem_disk">
                                <option value="local" @selected(($input['filesystem_disk'] ?? ($values['FILESYSTEM_DISK'] ?: 'local')) === 'local')>local (private)</option>
                                <option value="public" @selected(($input['filesystem_disk'] ?? ($values['FILESYSTEM_DISK'] ?: 'local')) === 'public')>public</option>
                            </select>
                        </label>
                    </div>
                @elseif ($currentStep === 9)
                    <div class="fields" dir="ltr">
                        <label>Cache
                            <select name="cache_store">
                                <option value="redis" @selected(($input['cache_store'] ?? ($values['CACHE_STORE'] ?: 'redis')) === 'redis')>redis</option>
                                <option value="file" @selected(($input['cache_store'] ?? $values['CACHE_STORE']) === 'file')>file</option>
                            </select>
                        </label>
                        <label>Queue
                            <select name="queue_connection">
                                <option value="redis" @selected(($input['queue_connection'] ?? ($values['QUEUE_CONNECTION'] ?: 'redis')) === 'redis')>redis</option>
                                <option value="sync" @selected(($input['queue_connection'] ?? $values['QUEUE_CONNECTION']) === 'sync')>sync</option>
                            </select>
                        </label>
                        <label>Redis host<input name="redis_host" value="{{ $input['redis_host'] ?? ($values['REDIS_HOST'] ?: '127.0.0.1') }}"></label>
                        <label>Redis port<input name="redis_port" type="number" min="1" max="65535" value="{{ $input['redis_port'] ?? ($values['REDIS_PORT'] ?: '6379') }}"></label>
                        <label class="full">Redis password<input name="redis_password" type="password" value=""></label>
                    </div>
                @elseif ($currentStep === 10)
                    <div class="fields" dir="ltr">
                        <label>Mailer
                            <select name="mail_mailer">
                                <option value="log" @selected(($input['mail_mailer'] ?? ($values['MAIL_MAILER'] ?: 'log')) === 'log')>log</option>
                                <option value="array" @selected(($input['mail_mailer'] ?? $values['MAIL_MAILER']) === 'array')>array</option>
                                <option value="smtp" @selected(($input['mail_mailer'] ?? $values['MAIL_MAILER']) === 'smtp')>smtp</option>
                            </select>
                        </label>
                        <label>SMTP scheme
                            <select name="mail_scheme">
                                <option value="">default</option>
                                <option value="smtp" @selected(($input['mail_scheme'] ?? $values['MAIL_SCHEME']) === 'smtp')>smtp</option>
                                <option value="smtps" @selected(($input['mail_scheme'] ?? $values['MAIL_SCHEME']) === 'smtps')>smtps</option>
                            </select>
                        </label>
                        <label>SMTP host<input name="mail_host" value="{{ $input['mail_host'] ?? ($values['MAIL_HOST'] ?: '127.0.0.1') }}"></label>
                        <label>SMTP port<input name="mail_port" type="number" min="1" max="65535" value="{{ $input['mail_port'] ?? ($values['MAIL_PORT'] ?: '587') }}"></label>
                        <label>Username<input name="mail_username" value="{{ $input['mail_username'] ?? $values['MAIL_USERNAME'] }}"></label>
                        <label>Password<input name="mail_password" type="password" value=""></label>
                        <label>From address<input name="mail_from_address" type="email" value="{{ $input['mail_from_address'] ?? ($values['MAIL_FROM_ADDRESS'] ?: 'noreply@example.com') }}" required></label>
                        <label>From name<input name="mail_from_name" value="{{ $input['mail_from_name'] ?? ($values['MAIL_FROM_NAME'] ?: 'FOODEX') }}" required></label>
                    </div>
                @elseif ($currentStep === 11)
                    <div class="notice">سيتم تشغيل migrations المعتمدة فقط باستخدام <code>--force</code>.</div>
                @elseif ($currentStep === 12)
                    <div class="notice">سيتم تشغيل seed المعتمد للأدوار والصلاحيات والبيانات المرجعية الأساسية.</div>
                @elseif ($currentStep === 13)
                    <div class="notice">سيتم إنشاء <code>APP_KEY</code> جديد وآمن للتثبيت.</div>
                @elseif ($currentStep === 14)
                    <div class="notice">سيتم التحقق من قاعدة البيانات والتخزين والـcache والـqueue قبل الإنهاء.</div>
                @elseif ($currentStep === 15)
                    <div class="notice">سيتم إنشاء حساب Super Admin النهائي، تسجيل نسخة النظام، كتابة install lock، ثم إغلاق المعالج.</div>
                @endif

                <div class="actions">
                    <button type="submit">
                        {{ $currentStep === 15 ? 'Finish / إنهاء التثبيت' : 'Continue / متابعة' }}
                    </button>
                </div>
            </form>
        </section>
    </main>
</div>
</body>
</html>
