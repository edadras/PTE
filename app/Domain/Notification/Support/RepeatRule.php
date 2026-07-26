<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The tiny recurrence grammar behind `scheduled_contents.repeat_rule`.
 *
 * Supported: `daily`, `weekdays`, `weekly`, `weekly:friday`, `monthly`,
 * `hourly`, `every:30m`, `every:6h`, `every:3d`. Anything else means "run once".
 *
 * The whole reason this class exists rather than a bare `->addDay()` at the call
 * site: **the next occurrence is computed in the academy's timezone.** "Every
 * day at 09:00" means 09:00 where the students are. Adding 24 hours to a UTC
 * instant gives the right answer only until the next daylight-saving change,
 * after which the daily nudge quietly arrives an hour early for six months and
 * nobody can explain why (docs/05 §6).
 */
final class RepeatRule
{
    private const WEEKDAYS = [
        'monday' => Carbon::MONDAY,
        'tuesday' => Carbon::TUESDAY,
        'wednesday' => Carbon::WEDNESDAY,
        'thursday' => Carbon::THURSDAY,
        'friday' => Carbon::FRIDAY,
        'saturday' => Carbon::SATURDAY,
        'sunday' => Carbon::SUNDAY,
    ];

    public static function isRecurring(?string $rule): bool
    {
        return self::normalize($rule) !== null;
    }

    /**
     * The next occurrence after `$current`, as a UTC instant.
     *
     * @param  string  $timezone  the academy's timezone, never the server's
     */
    public static function next(?string $rule, Carbon $current, string $timezone): ?Carbon
    {
        $rule = self::normalize($rule);

        if ($rule === null) {
            return null;
        }

        // Do the arithmetic on the local wall clock, then convert back.
        $local = $current->copy()->setTimezone($timezone);

        [$head, $argument] = self::split($rule);

        $next = match ($head) {
            'hourly' => $local->copy()->addHour(),
            'daily' => $local->copy()->addDay(),
            'weekdays' => self::nextWeekday($local),
            'weekly' => $argument === null
                ? $local->copy()->addWeek()
                : self::nextNamedWeekday($local, $argument),
            'monthly' => $local->copy()->addMonthNoOverflow(),
            'every' => self::everyInterval($local, $argument),
            default => null,
        };

        return $next?->copy()->utc();
    }

    /**
     * Advance past every occurrence already in the past — a scheduler that was
     * down for two days must not then fire two days of backlog at once.
     */
    public static function nextAfterNow(?string $rule, Carbon $current, string $timezone, ?Carbon $now = null): ?Carbon
    {
        $now ??= Carbon::now('UTC');
        $next = self::next($rule, $current, $timezone);

        $guard = 0;

        while ($next instanceof Carbon && $next->lessThanOrEqualTo($now) && $guard < 1000) {
            $next = self::next($rule, $next, $timezone);
            $guard++;
        }

        return $next;
    }

    private static function normalize(?string $rule): ?string
    {
        $rule = Str::lower(trim((string) $rule));

        if ($rule === '' || $rule === 'once' || $rule === 'none') {
            return null;
        }

        [$head] = self::split($rule);

        return in_array($head, ['hourly', 'daily', 'weekdays', 'weekly', 'monthly', 'every'], true)
            ? $rule
            : null;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function split(string $rule): array
    {
        $parts = explode(':', $rule, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private static function nextWeekday(Carbon $local): Carbon
    {
        $next = $local->copy()->addDay();

        // Iran's weekend is Thursday/Friday and the platform is Iran-first, but
        // a Saturday/Sunday academy exists too; skipping both keeps the rule
        // conservative — it never nudges on anyone's weekend.
        while (in_array($next->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
            $next->addDay();
        }

        return $next;
    }

    private static function nextNamedWeekday(Carbon $local, string $day): ?Carbon
    {
        $target = self::WEEKDAYS[Str::lower(trim($day))] ?? null;

        if ($target === null) {
            return null;
        }

        // next() moves to the following occurrence and resets the time, so the
        // wall-clock time has to be restored explicitly.
        return $local->copy()
            ->next($target)
            ->setTime($local->hour, $local->minute, $local->second);
    }

    private static function everyInterval(Carbon $local, ?string $argument): ?Carbon
    {
        if ($argument === null || preg_match('/^(\d+)([mhd])$/', trim($argument), $matches) !== 1) {
            return null;
        }

        $amount = max(1, (int) $matches[1]);

        return match ($matches[2]) {
            'm' => $local->copy()->addMinutes($amount),
            'h' => $local->copy()->addHours($amount),
            'd' => $local->copy()->addDays($amount),
            default => null,
        };
    }
}
