<?php

namespace App\Http\Controllers;

use App\Domain\SmartOrdering\Themes\ConfigStorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SmartOrderingSitemapController extends Controller
{
    public function __invoke(
        Request $request,
        string $siteKey,
        StorefrontThemeRenderer $renderer,
    ): Response {
        $provider = app()->bound(StorefrontSnapshotProvider::class)
            ? app(StorefrontSnapshotProvider::class)
            : app(ConfigStorefrontSnapshotProvider::class);

        $snapshot = $provider->published($siteKey);
        abort_if($snapshot === null, 404);

        $fallback = $request->getSchemeAndHttpHost().'/smart-ordering/'.rawurlencode($siteKey);
        $prepared = $renderer->prepare($snapshot, null, null, 'en', $fallback);
        $canonical = $this->absoluteCanonical(
            (string) data_get($prepared, 'site.seo.canonical', ''),
            $request,
            $fallback,
        );

        $alternates = [
            'en' => $canonical.'?lang=en',
            'ar' => $canonical.'?lang=ar',
            'x-default' => $canonical,
        ];

        $xml = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">',
            '  <url>',
            '    <loc>'.$this->xml($canonical).'</loc>',
            '    <xhtml:link rel="alternate" hreflang="en" href="'.$this->xml($alternates['en']).'" />',
            '    <xhtml:link rel="alternate" hreflang="ar" href="'.$this->xml($alternates['ar']).'" />',
            '    <xhtml:link rel="alternate" hreflang="x-default" href="'.$this->xml($alternates['x-default']).'" />',
            '  </url>',
            '</urlset>',
            '',
        ]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300, stale-while-revalidate=600',
        ]);
    }

    private function absoluteCanonical(string $canonical, Request $request, string $fallback): string
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return $fallback;
        }

        if (str_starts_with($canonical, '/')) {
            return $request->getSchemeAndHttpHost().$canonical;
        }

        $parts = parse_url($canonical);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === '') {
            return $fallback;
        }

        return $canonical;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
