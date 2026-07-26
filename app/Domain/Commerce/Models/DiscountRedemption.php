<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $academy_id
 * @property int $amount_discounted Minor units of $currency.
 */
final class DiscountRedemption extends Model
{
    use BelongsToAcademy;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_discounted' => 'integer',
            'redeemed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DiscountCode, $this> */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentSubscription, $this> */
    public function studentSubscription(): BelongsTo
    {
        return $this->belongsTo(StudentSubscription::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
