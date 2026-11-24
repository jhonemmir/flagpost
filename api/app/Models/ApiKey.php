<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $environment
 * @property string $prefix
 * @property string $hash
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ApiKey extends Model
{
    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'environment', 'prefix', 'hash', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    public static function findActive(string $plainKey): ?self
    {
        return self::query()
            ->where('hash', hash('sha256', $plainKey))
            ->whereNull('revoked_at')
            ->first();
    }
}
