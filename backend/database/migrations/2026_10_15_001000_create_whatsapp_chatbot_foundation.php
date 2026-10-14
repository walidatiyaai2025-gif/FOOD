<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_chatbot_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->boolean('emergency_stop')->default(false);
            $table->string('mode', 16)->default('hybrid');
            $table->string('default_locale', 8)->default('ar');
            $table->time('working_from')->nullable();
            $table->time('working_to')->nullable();
            $table->boolean('after_hours_handoff')->default(true);
            $table->text('welcome_ar')->nullable();
            $table->text('welcome_en')->nullable();
            $table->text('fallback_ar')->nullable();
            $table->text('fallback_en')->nullable();
            $table->boolean('ai_enabled')->default(true);
            $table->decimal('confidence_threshold', 4, 3)->default(0.650);
            $table->unsignedTinyInteger('max_autonomous_steps')->default(4);
            $table->boolean('require_order_confirmation')->default(true);
            $table->boolean('human_handoff_enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });

        Schema::create('whats_app_chatbot_features', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name_ar', 191);
            $table->string('name_en', 191);
            $table->boolean('enabled')->default(false)->index();
            $table->boolean('visible')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(100)->index();
            $table->boolean('ai_allowed')->default(false);
            $table->string('channel_scope', 32)->default('whatsapp');
            $table->json('segment_codes')->nullable();
            $table->json('configuration')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whats_app_chatbot_flows', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name_ar', 191);
            $table->string('name_en', 191);
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->json('definition');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whats_app_chatbot_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name_ar', 191);
            $table->string('name_en', 191);
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('priority')->default(100)->index();
            $table->json('rules');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whats_app_chatbot_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('whats_app_conversations')->cascadeOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->string('state', 32)->default('ACTIVE')->index();
            $table->string('current_intent', 64)->nullable()->index();
            $table->decimal('last_confidence', 4, 3)->nullable();
            $table->json('context')->nullable();
            $table->boolean('human_takeover')->default(false)->index();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_bot_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whats_app_chatbot_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->string('event_type', 64)->index();
            $table->string('feature_code', 64)->nullable()->index();
            $table->string('intent', 64)->nullable()->index();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_type', 'occurred_at'], 'wa_chatbot_event_type_time_idx');
            $table->index(['platform_customer_id', 'occurred_at'], 'wa_chatbot_customer_time_idx');
        });

        $now = now();
        DB::table('whats_app_chatbot_settings')->insert([
            'enabled' => false,
            'emergency_stop' => false,
            'mode' => 'hybrid',
            'default_locale' => 'ar',
            'after_hours_handoff' => true,
            'welcome_ar' => 'أهلاً بك في مساعد FOODEX الذكي. اختر الخدمة المطلوبة أو اكتب طلبك.',
            'welcome_en' => 'Welcome to the FOODEX smart assistant. Choose a service or type your request.',
            'fallback_ar' => 'لم أفهم الطلب بدقة. اختر خدمة من القائمة أو اطلب التحدث مع موظف.',
            'fallback_en' => 'I could not understand that request reliably. Choose a service or ask for an agent.',
            'ai_enabled' => true,
            'confidence_threshold' => 0.650,
            'max_autonomous_steps' => 4,
            'require_order_confirmation' => true,
            'human_handoff_enabled' => true,
            'revision' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $features = [
            ['new_order', 'طلب جديد', 'New Order', true, true, 10, false],
            ['repeat_order', 'إعادة آخر طلب', 'Repeat Last Order', true, true, 20, true],
            ['product_search', 'البحث عن منتج', 'Product Search', true, true, 30, true],
            ['prices_offers', 'الأسعار والعروض', 'Prices & Offers', true, true, 40, false],
            ['order_tracking', 'متابعة الطلب', 'Order Tracking', true, true, 50, false],
            ['finance', 'الفواتير والحساب', 'Invoices & Account', false, true, 60, false],
            ['complaints', 'الدعم والشكاوى', 'Support & Complaints', true, true, 70, true],
            ['human_handoff', 'التحدث مع موظف', 'Talk to an Agent', true, true, 80, false],
            ['smart_suggestions', 'الاقتراحات الذكية', 'Smart Suggestions', false, false, 90, true],
            ['cart_optimizer', 'تحسين سلة المشتريات', 'Cart Optimizer', false, false, 100, true],
            ['product_alternatives', 'بدائل المنتجات', 'Product Alternatives', false, false, 110, true],
            ['cost_calculator', 'حاسبة تكلفة الطبق', 'Plate Cost Calculator', false, false, 120, true],
            ['budget_basket', 'سلة حسب الميزانية', 'Budget Basket', false, false, 130, true],
            ['chef_assistant', 'مساعد الشيف', 'Chef Assistant', false, false, 140, true],
            ['rewards', 'المكافآت والتحديات', 'Rewards & Challenges', false, false, 150, true],
            ['multi_branch', 'اختيار الفرع', 'Multi-branch Selection', false, false, 160, false],
            ['voice_input', 'الطلبات الصوتية', 'Voice Input', false, false, 170, true],
            ['image_input', 'استخراج الطلب من صورة', 'Image Order Capture', false, false, 180, true],
        ];

        foreach ($features as [$code, $nameAr, $nameEn, $enabled, $visible, $sort, $aiAllowed]) {
            DB::table('whats_app_chatbot_features')->insert([
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'enabled' => $enabled,
                'visible' => $visible,
                'sort_order' => $sort,
                'ai_allowed' => $aiAllowed,
                'channel_scope' => 'whatsapp',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            'chatbot.view' => 'View FOODEX WhatsApp chatbot management',
            'chatbot.manage' => 'Manage FOODEX WhatsApp chatbot',
            'chatbot.runtime.manage' => 'Manage chatbot runtime and emergency stop',
            'chatbot.features.view' => 'View chatbot feature configuration',
            'chatbot.features.manage' => 'Manage chatbot feature configuration',
            'chatbot.flows.view' => 'View chatbot conversation flows',
            'chatbot.flows.manage' => 'Manage chatbot conversation flows',
            'chatbot.flows.publish' => 'Publish and roll back chatbot conversation flows',
            'chatbot.ai.view' => 'View chatbot AI behavior settings',
            'chatbot.ai.manage' => 'Manage chatbot AI behavior settings',
            'chatbot.segments.view' => 'View chatbot customer segments',
            'chatbot.segments.manage' => 'Manage chatbot customer segments',
            'chatbot.analytics.view' => 'View chatbot analytics',
            'chatbot.sessions.view' => 'View chatbot sessions',
            'chatbot.sessions.takeover' => 'Take over chatbot sessions',
            'chatbot.audit.view' => 'View chatbot audit events',
        ] as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $supervisorRole = DB::table('roles')->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->value('id');
        if ($supervisorRole !== null) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', [
                    'chatbot.view', 'chatbot.manage', 'chatbot.runtime.manage',
                    'chatbot.features.view', 'chatbot.features.manage',
                    'chatbot.flows.view', 'chatbot.flows.manage', 'chatbot.flows.publish',
                    'chatbot.ai.view', 'chatbot.ai.manage',
                    'chatbot.segments.view', 'chatbot.segments.manage',
                    'chatbot.analytics.view', 'chatbot.sessions.view',
                    'chatbot.sessions.takeover', 'chatbot.audit.view',
                ])
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $supervisorRole,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_chatbot_events');
        Schema::dropIfExists('whats_app_chatbot_sessions');
        Schema::dropIfExists('whats_app_chatbot_segments');
        Schema::dropIfExists('whats_app_chatbot_flows');
        Schema::dropIfExists('whats_app_chatbot_features');
        Schema::dropIfExists('whats_app_chatbot_settings');
    }
};
