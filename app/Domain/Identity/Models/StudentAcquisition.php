<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a student came from — deep link, campaign or referral.
 *
 * @property int $academy_id
 * @property int $student_id
 *
 * @see docs/07-database-schema.md §4
 */
final class StudentAcquisition extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'referrer_student_id');
    }
}
