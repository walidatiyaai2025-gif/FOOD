<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source
 * @property string $severity
 * @property int|null $status_code
 * @property int|null $user_id
 * @property int|null $store_id
 * @property string|null $method
 * @property string|null $route_name
 * @property string|null $url
 * @property string $message
 * @property string|null $exception_class
 * @property string|null $category
 * @property string|null $correlation_id
 * @property array<string,mixed>|null $context
 * @property Carbon $occurred_at
 * @property-read User|null $user
 * @property-read Store|null $store
 */
class SystemInspectorEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return Attribute<string|null, never> */
    protected function category(): Attribute
    {
        return Attribute::get(fn (): ?string => is_string($this->context['category'] ?? null)
            ? $this->context['category']
            : null);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
