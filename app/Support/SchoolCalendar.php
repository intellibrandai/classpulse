<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class SchoolCalendar
{
    public function __construct(private readonly string $timezone = 'America/Toronto') {}

    public function today(): string
    {
        return CarbonImmutable::now($this->timezone)->format('Y-m-d');
    }

    public function isWeekday(string $date): bool
    {
        return $this->parse($date)->dayOfWeekIso <= 5;
    }

    public function isFuture(string $date): bool
    {
        return $date > $this->today();
    }

    public function previousWeekday(string $date): string
    {
        $d = $this->parse($date)->subDay();
        while ($d->dayOfWeekIso > 5) {
            $d = $d->subDay();
        }

        return $d->format('Y-m-d');
    }

    public function nextWeekday(string $date): string
    {
        $d = $this->parse($date)->addDay();
        while ($d->dayOfWeekIso > 5) {
            $d = $d->addDay();
        }

        return $d->format('Y-m-d');
    }

    /** Monday of the ISO week containing the date. */
    public function weekStart(string $date): string
    {
        $d = $this->parse($date);

        return $d->subDays($d->dayOfWeekIso - 1)->format('Y-m-d');
    }

    /**
     * @return array<int, string> the five dates Monday to Friday
     */
    public function weekDays(string $monday): array
    {
        $start = $this->parse($monday);

        return array_map(fn (int $i) => $start->addDays($i)->format('Y-m-d'), range(0, 4));
    }

    /** Today, or the previous Friday when today is a weekend. */
    public function defaultDate(): string
    {
        $today = $this->today();

        return $this->isWeekday($today) ? $today : $this->previousWeekday($today);
    }

    private function parse(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }
}
