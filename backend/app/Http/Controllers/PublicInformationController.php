<?php

namespace App\Http\Controllers;

use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

final class PublicInformationController extends Controller
{
    public function privacy(Request $request): View
    {
        return $this->render($request, 'privacy');
    }

    public function terms(Request $request): View
    {
        return $this->render($request, 'terms');
    }

    public function support(Request $request): View
    {
        return $this->render($request, 'support');
    }

    public function accountDeletion(Request $request): View
    {
        return $this->render($request, 'account_deletion');
    }

    private function render(Request $request, string $page): View
    {
        $locale = $this->locale($request);
        app()->setLocale($locale);

        $copy = trans('public_information.pages.'.$page);
        abort_unless(is_array($copy), 404);

        return view('public.legal', [
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => (string) ($copy['title'] ?? ''),
            'paragraphs' => array_values(array_filter(
                (array) ($copy['paragraphs'] ?? []),
                static fn (mixed $paragraph): bool => is_string($paragraph) && trim($paragraph) !== '',
            )),
            'supportContact' => $page === 'support' ? $this->supportContact() : null,
        ]);
    }

    private function locale(Request $request): string
    {
        $requested = strtolower(trim((string) $request->query('lang', '')));
        if (in_array($requested, ['ar', 'en'], true)) {
            return $requested;
        }

        $preferred = strtolower((string) $request->getPreferredLanguage(['ar', 'en']));

        return $preferred === 'ar' ? 'ar' : 'en';
    }

    /** @return array{type:string,label:string,href:?string} */
    private function supportContact(): array
    {
        if (Schema::hasTable('whats_app_connection_profiles')) {
            $profile = WhatsAppConnectionProfile::query()
                ->where('is_primary', true)
                ->where('receive_only', false)
                ->where('status', ConnectionProfileStatus::ACTIVE->value)
                ->whereNotNull('normalized_phone_number')
                ->first();

            if ($profile !== null) {
                $number = trim((string) $profile->normalized_phone_number);
                $digits = preg_replace('/\D+/', '', $number) ?? '';
                if ($digits !== '') {
                    $label = trim((string) $profile->display_phone_number);

                    return [
                        'type' => 'whatsapp',
                        'label' => $label !== '' ? $label : $number,
                        'href' => 'https://wa.me/'.$digits,
                    ];
                }
            }
        }

        return [
            'type' => 'fallback',
            'label' => (string) trans('public_information.support.fallback_label'),
            'href' => null,
        ];
    }
}
