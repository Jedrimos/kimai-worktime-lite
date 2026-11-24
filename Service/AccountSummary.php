<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use App\WorkingTime\Model\Year;
use App\WorkingTime\WorkingTimeService;

/**
 * Kennzahlen für die Box "Arbeitszeitkonto & Urlaub" (Arbeitszeiten-Seite und Dashboard).
 */
final class AccountSummary
{
    public function __construct(
        private readonly WorkingTimeService $workingTimeService,
        private readonly UserWorkSettings $userSettings,
        private readonly AbsenceRepository $absenceRepository,
        private readonly AbsenceCalculator $calculator,
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $profile, int $shownYear, ?Year $preloaded = null): array
    {
        $tz = new \DateTimeZone($profile->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $currentYear = (int) $now->format('Y');

        $start = $profile->getWorkStartingDay();
        $firstYear = $start !== null ? (int) $start->format('Y') : min($shownYear, $currentYear);
        $firstYear = max($firstYear, $currentYear - 10);

        $balance = $this->userSettings->getBalanceStart($profile);
        $perYear = [];
        for ($y = $firstYear; $y <= $currentYear; $y++) {
            if ($preloaded !== null && $y === (int) $preloaded->getYear()->format('Y')) {
                $yearModel = $preloaded;
            } else {
                $yearModel = $this->workingTimeService->getYear($profile, new \DateTimeImmutable($y . '-01-01 00:00:00', $tz), $now);
            }
            $diff = $yearModel->getActualTime() - $yearModel->getExpectedTime($now);
            $perYear[$y] = $diff;
            $balance += $diff;
        }

        $list = $this->absenceRepository->findByDay($profile, new \DateTimeImmutable($shownYear . '-01-01', $tz), new \DateTimeImmutable($shownYear . '-12-31', $tz));
        $counts = ['vacation' => 0.0, 'sick' => 0.0, 'overtime' => 0.0, 'other' => 0.0];
        foreach ($list as $day => $entries) {
            $date = new \DateTimeImmutable($day, $tz);
            foreach ($entries as $entry) {
                $counts[$entry['type']] += $entry['type'] === Settings::TYPE_OVERTIME ? 1.0 : $this->calculator->getFraction($profile, $date, $entry['duration']);
            }
        }

        $holidays = [];
        foreach ($this->userSettings->getHolidays($profile, $shownYear) as $date => $name) {
            $holidays[] = ['date' => new \DateTimeImmutable($date, $tz), 'name' => $name];
        }

        return [
            'profile' => $profile,
            'balance' => $balance,
            'balanceStart' => $this->userSettings->getBalanceStart($profile),
            'perYear' => $perYear,
            'startDay' => $start,
            'counts' => $counts,
            'entitlement' => $this->userSettings->getHolidayEntitlement($profile, $shownYear),
            'entitlementFull' => $profile->getHolidaysPerYear(),
            'shownYear' => $shownYear,
            'absenceValue' => $this->userSettings->getAbsenceValue($profile),
            'weeklyHours' => $this->userSettings->getWeeklyHours($profile),
            'holidays' => $holidays,
            'hasContract' => $profile->hasWorkHourConfiguration(),
        ];
    }
}
