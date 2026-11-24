<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\FlagpostConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Flag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'description'];

    /**
     * @return HasMany<FlagEnvironmentConfig, $this>
     */
    public function configs(): HasMany
    {
        return $this->hasMany(FlagEnvironmentConfig::class);
    }

    /**
     * @return array{
     *     key: string,
     *     description: string|null,
     *     createdAt: string,
     *     updatedAt: string,
     *     environments: array<string, array{enabled: bool, percentage: int, allowList: list<string>}>
     * }
     */
    public function toApiArray(): array
    {
        $environments = [];

        foreach (FlagpostConfig::environments() as $environment) {
            $config = $this->configs->firstWhere('environment', $environment);

            $environments[$environment] = $config === null
                ? ['enabled' => false, 'percentage' => 100, 'allowList' => []]
                : [
                    'enabled' => $config->enabled,
                    'percentage' => $config->percentage,
                    'allowList' => $config->allow_list,
                ];
        }

        return [
            'key' => $this->key,
            'description' => $this->description,
            'createdAt' => $this->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z') ?? '',
            'updatedAt' => $this->updated_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z') ?? '',
            'environments' => $environments,
        ];
    }
}
