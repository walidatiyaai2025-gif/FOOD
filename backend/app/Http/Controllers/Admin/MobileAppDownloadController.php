<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class MobileAppDownloadController extends Controller
{
    private const RELEASE_BASE_URL = 'https://github.com/walidatiyaai2025-gif/FOOD/releases/download';

    public function customer(): RedirectResponse
    {
        return $this->redirectToVersionedAsset('FOODEX-Customer.apk');
    }

    public function driver(): RedirectResponse
    {
        return $this->redirectToVersionedAsset('FOODEX-Driver.apk');
    }

    public function van(): RedirectResponse
    {
        return $this->redirectToVersionedAsset('FOODEX-Van.apk');
    }

    private function redirectToVersionedAsset(string $asset): RedirectResponse
    {
        Gate::authorize('platform.manage');

        $version = $this->currentVersion();

        return redirect()->away(self::RELEASE_BASE_URL.'/v'.$version.'/'.$asset);
    }

    private function currentVersion(): string
    {
        $version = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->value('version');

        if (! is_string($version) || $version === '') {
            $version = trim((string) @file_get_contents(base_path('../VERSION')));
        }

        if ($version === '' || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException('Current FOODEX version could not be determined for APK download.');
        }

        return $version;
    }
}
