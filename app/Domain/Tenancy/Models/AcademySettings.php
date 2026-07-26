<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\AcademySettingsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $academy_id
 * @property string $locale
 * @property string $currency
 *
 * @see docs/07-database-schema.md §2
 */
final class AcademySettings extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<AcademySettingsFactory> */
    use HasFactory;

    protected $table = 'academy_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'practice_config' => 'array',
            'exam_config' => 'array',
            'notification_config' => 'array',
            'features' => 'array',
            'data_retention_days' => 'integer',
        ];
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): AcademySettingsFactory
    {
        return AcademySettingsFactory::new();
    }

    public function feature(string $key, mixed $default = null): mixed
    {
        return data_get($this->features ?? [], $key, $default);
    }

    public function practice(string $key, mixed $default = null): mixed
    {
        return data_get($this->practice_config ?? [], $key, $default);
    }

    public function exam(string $key, mixed $default = null): mixed
    {
        return data_get($this->exam_config ?? [], $key, $default);
    }
}
