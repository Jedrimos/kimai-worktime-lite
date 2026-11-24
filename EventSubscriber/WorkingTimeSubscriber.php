<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\WorkingTimeYearEvent;
use App\Event\WorkingTimeYearSummaryEvent;
use App\WorkingTime\Model\Day;
use App\WorkingTime\Model\DayAddon;
use App\WorkingTime\Model\YearSummary;
use KimaiPlugin\WorkTimeLiteBundle\Service\AbsenceCalculator;
use KimaiPlugin\WorkTimeLiteBundle\Service\AbsenceRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Feiertage und Abwesenheiten in der Arbeitszeit-Übersicht (Arbeitsvertrag → Arbeitszeiten).
 */
final class WorkingTimeSubscriber implements EventSubscriberInterface
{
    public const HOLIDAY_PREFIX = 'Feiertag: ';

    /** @var \WeakMap<Day, array<int, array{type: string, credit: int}>> */
    private \WeakMap $credits;

    public function __construct(
        private readonly Settings $settings,
        private readonly UserWorkSettings $userSettings,
        private readonly AbsenceRepository $absenceRepository,
        private readonly AbsenceCalculator $calculator,
    )
    {
        $this->credits = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkingTimeYearEvent::class => ['onYear', 100],
            WorkingTimeYearSummaryEvent::class => ['onYearSummary', 100],
        ];
    }

    public function onYear(WorkingTimeYearEvent $event): void
    {
        $year = $event->getYear();
        $user = $year->getUser();
        $yearDate = $year->getYear();

        $begin = new \DateTimeImmutable($yearDate->format('Y') . '-01-01');
        $end = new \DateTimeImmutable($yearDate->format('Y') . '-12-31');
        $absences = $this->absenceRepository->findByDay($user, $begin, $end);
        $holidayMode = $this->settings->getHolidayMode();
        $useValue = $this->settings->isAccountUsingAbsenceValue();
        $absenceValue = $this->userSettings->getAbsenceValue($user);
        $firstDay = $user->getWorkStartingDay();
        $lastDay = $user->getLastWorkingDay();
        $until = $event->getUntil();

        foreach ($year->getMonths() as $month) {
            /** @var Day $day */
            foreach ($month->getDays() as $day) {
                $workingTime = $day->getWorkingTime();
                if ($workingTime === null || $day->isLocked()) {
                    continue;
                }

                $date = $day->getDay();
                $key = $date->format('Y-m-d');
                $list = [];
                // Tage in der Zukunft beeinflussen das Konto noch nicht
                $isPast = $date <= $until;

                // Feiertage
                $holiday = $this->userSettings->getHolidayName($user, $date);
                $inContract = ($firstDay === null || $firstDay <= $date) && ($lastDay === null || $lastDay >= $date);
                if ($holiday !== null && $inContract && $this->userSettings->isWorkDay($user, $date)) {
                    if ($holidayMode === 'credit') {
                        if ($isPast && $workingTime->getExpectedTime() > 0) {
                            $day->addAddon(new DayAddon(self::HOLIDAY_PREFIX . $holiday, $absenceValue, $absenceValue));
                            $list[] = ['type' => 'holiday', 'credit' => $absenceValue];
                        } else {
                            $day->addAddon(new DayAddon(self::HOLIDAY_PREFIX . $holiday, 0, 0));
                        }
                    } else {
                        $workingTime->setExpectedTime(0);
                        $day->addAddon(new DayAddon(self::HOLIDAY_PREFIX . $holiday, 0, 0));
                    }
                }

                // Abwesenheiten (Urlaub, Krank, ...)
                foreach ($absences[$key] ?? [] as $absence) {
                    if (!$isPast) {
                        $day->addAddon(new DayAddon('wtl.type.' . $absence['type'], 0, 0));
                        continue;
                    }
                    $credit = $useValue
                        ? $this->calculator->getCredit($user, $date, $absence['type'], $absence['duration'])
                        : $absence['duration'];
                    $delta = $credit - $absence['duration'];
                    $day->addAddon(new DayAddon('wtl.type.' . $absence['type'], $delta, $credit));
                    $list[] = ['type' => $absence['type'], 'credit' => $credit];
                }

                if (\count($list) > 0) {
                    $this->credits[$day] = $list;
                }
            }
        }
    }

    public function onYearSummary(WorkingTimeYearSummaryEvent $event): void
    {
        $year = $event->getYear();
        $until = $event->getUntil();

        $rows = [];
        $work = new YearSummary($year->getYear(), 'wtl.summary.work');
        $hasCredits = false;

        foreach ($year->getMonths() as $month) {
            $credited = 0;
            foreach ($month->getDays() as $day) {
                foreach ($this->credits[$day] ?? [] as $item) {
                    $type = $item['type'];
                    if (!\array_key_exists($type, $rows)) {
                        $rows[$type] = new YearSummary($year->getYear(), 'wtl.summary.' . $type);
                    }
                    $summaryMonth = $rows[$type]->getMonth($month->getMonth());
                    $summaryMonth->setActualTime($summaryMonth->getActualTime() + $item['credit']);
                    $credited += $item['credit'];
                    $hasCredits = true;
                }
            }

            $workMonth = $work->getMonth($month->getMonth());
            $workMonth->setExpectedTime($month->getExpectedTime($until));
            $workMonth->setActualTime($month->getActualTime() - $credited);
        }

        if (!$hasCredits) {
            return;
        }

        $event->addSummary($work);
        foreach (['vacation', 'sick', 'other', 'overtime', 'holiday'] as $type) {
            if (\array_key_exists($type, $rows)) {
                $event->addSummary($rows[$type]);
            }
        }
    }
}
