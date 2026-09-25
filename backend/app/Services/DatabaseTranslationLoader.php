<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\LoaderInterface;
use Throwable;

final class DatabaseTranslationLoader implements LoaderInterface
{
    public function __construct(private readonly LoaderInterface $base)
    {
    }

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->base->load($locale, $group, $namespace);

        if (! in_array($namespace, [null, '*'], true) || ! in_array($locale, ['ar', 'en'], true)) {
            return $lines;
        }

        try {
            if (! Schema::hasTable('translations')) {
                return $lines;
            }

            $rows = DB::table('translations')
                ->where('key', 'like', $group.'.%')
                ->pluck($locale, 'key');

            foreach ($rows as $key => $value) {
                $relative = substr((string) $key, strlen($group) + 1);
                Arr::set($lines, $relative, (string) $value);
            }
        } catch (Throwable) {
            return $lines;
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->base->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->base->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->base->namespaces();
    }
}
