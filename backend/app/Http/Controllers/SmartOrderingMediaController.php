<?php

namespace App\Http\Controllers;

use App\Services\SmartOrderingMediaService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SmartOrderingMediaController extends Controller
{
    public function __invoke(string $path, SmartOrderingMediaService $media): StreamedResponse
    {
        return $media->response($path);
    }
}
