<?php

namespace App\Domain\Assistant\Conversation;

use App\Domain\Assistant\Data\AssistantBrainResult;

final class ConversationBasics
{
    public function __construct(private readonly TextNormalizer $normalizer = new TextNormalizer) {}

    /**
     * Covers natural conversational basics without invoking business tools.
     *
     * @param  array<string, mixed>  $state
     */
    public function respond(string $message, string $normalized, string $locale, array $state = []): ?AssistantBrainResult
    {
        if ($normalized === '' || count(preg_split('/\\s+/u', $normalized) ?: []) > 14) {
            return null;
        }

        $kind = $this->kind($normalized);
        if ($kind === null) {
            return null;
        }

        $arabic = str_starts_with(mb_strtolower($locale, 'UTF-8'), 'ar');

        return new AssistantBrainResult(
            message: $this->message($kind, $arabic),
            intent: null,
            confidence: 1.0,
            suggestedPrompts: $arabic
                ? ['مبيعات اليوم', 'الطلبات المتأخرة', 'ملخص اليوم', 'حالة السائقين']
                : ['Sales today', 'Late orders', 'Daily brief', 'Driver status'],
            state: [
                'preserve_context' => true,
                'pending_clarification' => false,
                'locale' => $arabic ? 'ar' : 'en',
            ],
        );
    }

    private function kind(string $message): ?string
    {
        $groups = [
            'morning' => [
                'صباح الخير', 'صباح النور', 'صباح الفل', 'صباح الورد', 'صباحك خير',
                'good morning', 'morning',
            ],
            'evening' => [
                'مساء الخير', 'مسا الخير', 'مساء النور', 'مسا النور', 'مساء الورد',
                'good evening', 'evening',
            ],
            'peace' => [
                'السلام عليكم', 'سلام عليكم', 'السلام عليكم ورحمه الله', 'السلام عليكم ورحمه الله وبركاته',
                'assalamu alaikum', 'salam alaikum',
            ],
            'goodnight' => [
                'تصبح علي خير', 'تصبحوا علي خير', 'ليله سعيده', 'good night', 'goodnight',
            ],
            'goodbye' => [
                'مع السلامه', 'في امان الله', 'باي', 'اشوفك بعدين', 'الي اللقاء',
                'bye', 'goodbye', 'see you', 'see you later',
            ],
            'thanks' => [
                'شكرا', 'شكرا جدا', 'متشكر', 'تسلم', 'تسلمي', 'مشكور', 'يعطيك العافيه', 'الله يعطيك العافيه',
                'thanks', 'thank you', 'many thanks', 'appreciate it',
            ],
            'how_are_you' => [
                'ازيك', 'ازيك يا مساعد', 'عامل ايه', 'عامل ايه يا مساعد', 'اخبارك', 'اخبارك ايه',
                'كيف حالك', 'شلونك', 'شخبارك', 'how are you', 'how are you doing', 'how is it going',
            ],
            'identity' => [
                'مين انت', 'انت مين', 'من انت', 'عرفني بنفسك', 'ايه انت',
                'who are you', 'what are you', 'introduce yourself',
            ],
            'help' => [
                'ساعدني', 'محتاج مساعده', 'عاوز مساعده', 'ممكن تساعدني', 'تقدر تعمل ايه', 'بتعرف تعمل ايه',
                'ايه اللي تقدر تعمله', 'اعمل ايه هنا', 'help', 'help me', 'can you help me', 'what can you do',
            ],
            'hello' => [
                'اهلا', 'اهلا وسهلا', 'هلا', 'مرحبا', 'هاي', 'الو', 'يا هلا',
                'hello', 'hi', 'hey', 'welcome',
            ],
            'ack' => [
                'تمام', 'تمام كده', 'اوكي', 'اوك', 'ماشي', 'كويس', 'حلو', 'جميل',
                'ok', 'okay', 'got it', 'sounds good', 'great',
            ],
        ];

        foreach ($groups as $kind => $phrases) {
            foreach ($phrases as $phrase) {
                $needle = $this->normalizer->normalize($phrase);
                if ($message === $needle || str_starts_with($message, $needle.' ') || str_ends_with($message, ' '.$needle)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    private function message(string $kind, bool $arabic): string
    {
        if ($arabic) {
            return match ($kind) {
                'morning' => 'صباح النور والسرور 👋 أنا معاك. تحب نراجع مبيعات اليوم، الطلبات المتأخرة، ولا حاجة تانية؟',
                'evening' => 'مساء النور 👋 أنا معاك. قولّي تحب نراجع إيه في FOODEX؟',
                'peace' => 'وعليكم السلام ورحمة الله وبركاته 👋 أهلاً بيك. أنا جاهز أساعدك.',
                'goodnight' => 'وأنت من أهله 🌙 تصبح على خير. لما ترجع أنا موجود.',
                'goodbye' => 'مع السلامة 👋 وقت ما تحتاجني هتلاقيني هنا.',
                'thanks' => 'العفو، تحت أمرك 🙏 لو عاوز نكمّل في نفس الموضوع أنا معاك.',
                'how_are_you' => 'تمام الحمد لله، وجاهز أساعدك 😊 قولّي عاوز تعرف إيه من FOODEX؟',
                'identity' => 'أنا مساعد FOODEX الذكي. أساعدك في قراءة بيانات التشغيل وفهم المبيعات والطلبات والمتاجر والعملاء والمنتجات والسائقين والمخزون، مع الالتزام بصلاحيات حسابك.',
                'help' => 'أكيد. اسألني بشكل طبيعي عن المبيعات، الطلبات، العملاء، المتاجر، المنتجات، السائقين أو المخزون. مثال: «مبيعات اليوم»، «الطلبات المتأخرة»، أو «حالة السائقين».',
                'hello' => 'أهلاً وسهلاً 👋 أنا معاك. قولّي محتاج إيه؟',
                'ack' => 'تمام 👍 أنا معاك. كمّل أو اسألني عن أي حاجة تشغيلية في FOODEX.',
                default => 'أنا معاك. قولّي محتاج إيه؟',
            };
        }

        return match ($kind) {
            'morning' => 'Good morning 👋 I’m here with you. Want to check today’s sales, late orders, or something else?',
            'evening' => 'Good evening 👋 I’m here. What would you like to review in FOODEX?',
            'peace' => 'Wa alaikum assalam 👋 Welcome. I’m ready to help.',
            'goodnight' => 'Good night 🌙 Rest well. I’ll be here when you’re back.',
            'goodbye' => 'Goodbye 👋 I’ll be here whenever you need me.',
            'thanks' => 'You’re welcome 🙏 If you want to continue with the same topic, I’m with you.',
            'how_are_you' => 'I’m doing well and ready to help 😊 What would you like to know from FOODEX?',
            'identity' => 'I’m the FOODEX Assistant. I help you read operational data and understand sales, orders, stores, customers, products, drivers, and inventory within your account permissions.',
            'help' => 'Sure. Ask naturally about sales, orders, customers, stores, products, drivers, or inventory. For example: “Sales today”, “Late orders”, or “Driver status”.',
            'hello' => 'Hello 👋 I’m here. What do you need?',
            'ack' => 'Got it 👍 I’m with you. Continue, or ask me anything operational in FOODEX.',
            default => 'I’m here. What do you need?',
        };
    }
}
