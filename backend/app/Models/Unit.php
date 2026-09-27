<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    protected $table = 'units';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function localizedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) (($locale === 'ar' ? $this->name_ar : $this->name_en) ?: $this->name);
    }
}
