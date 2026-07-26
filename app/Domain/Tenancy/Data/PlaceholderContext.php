<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Models\Academy;
use Illuminate\Support\Carbon;
use Stringable;

/**
 * The bag of values a template may interpolate.
 *
 * @see docs/03-white-label.md §5
 */
final readonly class PlaceholderContext
{
    /**
     * @param  array<string, scalar|null>  $values
     */
    public function __construct(private array $values = []) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function make(array $values = []): self
    {
        return new self(self::normalize($values));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->has($key) ? (string) $this->values[$key] : $default;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): self
    {
        return new self([...$this->values, ...self::normalize($values)]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->values, ...$other->all()]);
    }

    public function forAcademy(Academy $academy): self
    {
        $brand = $academy->brand;

        return $this->with([
            'academy_name' => $brand?->display_name ?? $academy->name,
            'academy_short_name' => $brand?->shortName() ?? $academy->name,
            'support_phone' => $brand?->support_phone,
            'support_email' => $brand?->support_email,
            'website' => $brand?->website_url,
            'instagram' => $brand?->instagram_url,
        ]);
    }

    /** Time placeholders resolved in the tenant's timezone. */
    public function withMoment(?Carbon $moment = null, ?string $timezone = null): self
    {
        $moment = ($moment ?? Carbon::now())->copy();

        if ($timezone !== null) {
            $moment = $moment->setTimezone($timezone);
        }

        return $this->with([
            'today' => $moment->translatedFormat('Y/m/d'),
            'time' => $moment->format('H:i'),
            'weekday' => $moment->translatedFormat('l'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, scalar|null>
     */
    private static function normalize(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[$key] = match (true) {
                $value === null, is_scalar($value) => $value,
                $value instanceof Carbon => $value->toDateTimeString(),
                $value instanceof Stringable => (string) $value,
                default => null,
            };
        }

        return $normalized;
    }
}
