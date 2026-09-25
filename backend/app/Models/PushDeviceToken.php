<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PushDeviceToken extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_encrypted','token_hash'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plainToken(): string { return (string) $this->token_encrypted; }
    protected function casts(): array { return ['token_encrypted'=>'encrypted','revoked_at'=>'datetime']; }
}
