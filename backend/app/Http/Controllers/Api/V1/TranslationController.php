<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TranslationCatalog;
use Illuminate\Http\JsonResponse;

final class TranslationController extends Controller
{
    public function __invoke(string $locale, TranslationCatalog $catalog): JsonResponse
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);

        return response()->json([
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'translations' => $catalog->bundle($locale),
        ]);
    }
}
