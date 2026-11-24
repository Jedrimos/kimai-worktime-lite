<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use App\WorkingTime\WorkingTimeService;

/**
 * Monate sperren / entsperren.
 * Sperren schließt alle vorherigen offenen Monate des Jahres mit ein,
 * Entsperren alle späteren gesperrten Monate des Jahres.
 */
final class MonthLocker
{
    public function __construct(private readonly WorkingTimeService $workingTimeService)
    {
    }

    /**
     * @return array<int> Liste der gesperrten Monatsnummern
     */
    public function lock(User $profile, \DateTimeInterface $month, User $lockedBy): array
    {
        $tz = new \DateTimeZone($profile->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $yearDate = new \DateTimeImmutable($month->format('Y') . '-01-01 00:00:00', $tz);
        $lastMonth = (int) $month->format('n');

        $year = $this->workingTimeService->getYear($profile, $yearDate, $now);
        $locked = [];

        foreach ($year->getMonths() as $m) {
            $nr = (int) $m->getMonth()->format('n');
            if ($nr > $lastMonth) {
                break;
            }
            if ($m->isLocked()) {
                continue;
            }
            $endOfMonth = (new \DateTimeImmutable($m->getMonth()->format('Y-m-01'), $tz))->modify('last day of this month')->setTime(23, 59, 59);
            if ($endOfMonth > $now) {
                continue;
            }
            // Monate komplett vor dem ersten Arbeitstag trotzdem sperren, damit die Zählung "x/12" stimmt
            $this->workingTimeService->approveMonth($profile, $m, $now, $lockedBy);
            $locked[] = $nr;
        }

        return $locked;
    }

    /**
     * @return array<int> Liste der entsperrten Monatsnummern
     */
    public function unlock(User $profile, \DateTimeInterface $month, User $unlockedBy): array
    {
        $tz = new \DateTimeZone($profile->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $yearDate = new \DateTimeImmutable($month->format('Y') . '-01-01 00:00:00', $tz);
        $firstMonth = (int) $month->format('n');

        $year = $this->workingTimeService->getYear($profile, $yearDate, $now);
        $unlocked = [];

        foreach ($year->getMonths() as $m) {
            $nr = (int) $m->getMonth()->format('n');
            if ($nr < $firstMonth) {
                continue;
            }
            $hasLockedDay = false;
            foreach ($m->getDays() as $day) {
                if ($day->isLocked()) {
                    $hasLockedDay = true;
                    break;
                }
            }
            if (!$hasLockedDay) {
                continue;
            }
            $this->workingTimeService->unlockMonth($m, $unlockedBy);
            $unlocked[] = $nr;
        }

        return $unlocked;
    }
}
