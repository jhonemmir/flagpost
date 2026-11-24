<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $environment
 * @property bool $enabled
 * @property int $percentage
 * @property list<string> $allow_list
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FlagEnvironmentConfig extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['environment', 'enabled', 'percentage', 'allow_list'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'percentage' => 'integer',
            'allow_list' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Flag, $this>
     */
    public function flag(): BelongsTo
    {
        return $this->belongsTo(Flag::class);
    }
}
