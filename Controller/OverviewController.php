<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\WorkingTime\Model\Year;
use App\WorkingTime\WorkingTimeService;
use KimaiPlugin\WorkTimeLiteBundle\Service\AbsenceCalculator;
use KimaiPlugin\WorkTimeLiteBundle\Service\AbsenceRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\Access;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Box "Arbeitszeitkonto & Urlaub" unter Arbeitsvertrag → Arbeitszeiten.
 * Wird über WorkContractDetailControllerEvent eingebunden (keine eigene Route).
 */
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly WorkingTimeService $workingTimeService,
        private readonly UserWorkSettings $userSettings,
        private readonly AbsenceRepository $absenceRepository,
        private readonly AbsenceCalculator $calculator,
        private readonly Access $access,
    )
    {
    }

    public function box(Year $year): Response
    {
        $profile = $year->getUser();
        $tz = new \DateTimeZone($profile->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $currentYear = (int) $now->format('Y');
        $shownYear = (int) $year->getYear()->format('Y');

        // Konto über alle Jahre seit Arbeitsbeginn
        $start = $profile->getWorkStartingDay();
        $firstYear = $start !== null ? (int) $start->format('Y') : min($shownYear, $currentYear);
        $firstYear = max($firstYear, $currentYear - 10);

        $balance = $this->userSettings->getBalanceStart($profile);
        $perYear = [];
        for ($y = $firstYear; $y <= $currentYear; $y++) {
            if ($y === $shownYear) {
                $yearModel = $year;
            } else {
                $yearModel = $this->workingTimeService->getYear($profile, new \DateTimeImmutable($y . '-01-01 00:00:00', $tz), $now);
            }
            $diff = $yearModel->getActualTime() - $yearModel->getExpectedTime($now);
            $perYear[$y] = $diff;
            $balance += $diff;
        }

        // Urlaub / Krank im angezeigten Jahr
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

        return $this->render('@WorkTimeLite/overview_box.html.twig', [
            'profile' => $profile,
            'balance' => $balance,
            'balanceStart' => $this->userSettings->getBalanceStart($profile),
            'perYear' => $perYear,
            'startDay' => $start,
            'counts' => $counts,
            'entitlement' => $profile->getHolidaysPerYear(),
            'shownYear' => $shownYear,
            'absenceValue' => $this->userSettings->getAbsenceValue($profile),
            'weeklyHours' => $this->userSettings->getWeeklyHours($profile),
            'holidays' => $holidays,
            'canManageAbsences' => $this->access->canManageAbsences($profile),
            'canEditContract' => $this->isGranted('contract', $profile),
        ]);
    }
}
