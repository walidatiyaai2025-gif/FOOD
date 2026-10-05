<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldOperationConfiguration extends Model
{
    protected $guarded = [];

    public function revisions(): HasMany
    {
        return $this->hasMany(FieldOperationConfigurationRevision::class, 'configuration_id');
    }

    protected function casts(): array
    {
        return [
            'validation_schema' => 'array',
            'default_value' => 'json',
            'dependencies' => 'array',
            'is_sensitive' => 'boolean',
        ];
    }
}
