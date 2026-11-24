<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\TimesheetRepository;

/**
 * Prüft einen Monat vor dem Abschluss auf typische Fehler.
 * Ergebnis ist nur ein Hinweis – es wird nichts verändert.
 */
final class MonthCheck
{
    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly UserWorkSettings $userSettings,
        private readonly Settings $settings,
    )
    {
    }

    /**
     * @return array<int, array{level: string, date: ?\DateTimeImmutable, text: string, id: ?int, createDate: ?string}>
     */
    public function check(User $user, \DateTimeInterface $month): array
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $first = new \DateTimeImmutable($month->format('Y-m-01') . ' 00:00:00', $tz);
        $last = $first->modify('last day of this month');
        $today = new \DateTimeImmutable('today', $tz);
        $until = $last < $today ? $last : $today->modify('-1 day');

        $qb = $this->timesheetRepository->createQueryBuilder('t');
        $qb->where($qb->expr()->eq('t.user', ':user'))
            ->andWhere($qb->expr()->between('t.date', ':begin', ':end'))
            ->setParameter('user', $user->getId())
            ->setParameter('begin', $first->modify('-1 day')->format('Y-m-d'))
            ->setParameter('end', $last->format('Y-m-d'))
            ->orderBy('t.begin', 'ASC');
        /** @var array<Timesheet> $entries */
        $entries = $qb->getQuery()->getResult();

        $absenceTypes = $this->settings->getConfiguredAbsenceTypes();
        $isAbsence = function (Timesheet $t) use ($absenceTypes): bool {
            foreach ($absenceTypes as $ids) {
                if ($t->getProject()?->getId() === $ids['project'] && $t->getActivity()?->getId() === $ids['activity']) {
                    return true;
                }
            }

            return false;
        };

        // nach Tag gruppieren
        $byDay = [];
        foreach ($entries as $t) {
            if ($t->getBegin() === null) {
                continue;
            }
            $begin = \DateTimeImmutable::createFromMutable($t->getBegin())->setTimezone($tz);
            $end = $t->getEnd() !== null ? \DateTimeImmutable::createFromMutable($t->getEnd())->setTimezone($tz) : null;
            $byDay[$begin->format('Y-m-d')][] = ['t' => $t, 'begin' => $begin, 'end' => $end, 'absence' => $isAbsence($t)];
        }

        $issues = [];
        $add = function (string $level, ?\DateTimeImmutable $date, string $text, ?int $id = null, ?string $createDate = null) use (&$issues): void {
            $issues[] = ['level' => $level, 'date' => $date, 'text' => $text, 'id' => $id, 'createDate' => $createDate];
        };

        $firstDay = $user->getWorkStartingDay();
        $lastDay = $user->getLastWorkingDay();
        $prevEnd = null;
        // letzter Eintrag vom Vortag des Monats für die Ruhezeit
        $prevKey = $first->modify('-1 day')->format('Y-m-d');
        foreach ($byDay[$prevKey] ?? [] as $e) {
            if (!$e['absence'] && $e['end'] !== null && ($prevEnd === null || $e['end'] > $prevEnd)) {
                $prevEnd = $e['end'];
            }
        }

        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $list = $byDay[$key] ?? [];
            $work = array_values(array_filter($list, fn ($e) => !$e['absence']));

            // laufende Einträge
            foreach ($list as $e) {
                if ($e['end'] === null) {
                    $add('danger', $day, 'Zeiterfassung läuft noch (nicht gestoppt).', $e['t']->getId());
                }
            }

            // fehlende Arbeitstage
            if ($day <= $until && \count($list) === 0) {
                $inContract = ($firstDay === null || $firstDay <= $day) && ($lastDay === null || $lastDay >= $day);
                if ($inContract && $this->userSettings->isWorkDay($user, $day) && $this->userSettings->getHolidayName($user, $day) === null) {
                    $add('warning', $day, 'Kein Eintrag an einem Arbeitstag (Soll ' . $this->fmt($this->userSettings->getExpectedForDay($user, $day)) . ').', null, $key);
                }
            }

            if (\count($work) === 0) {
                continue;
            }

            // Überlappungen
            for ($i = 1; $i < \count($work); $i++) {
                $a = $work[$i - 1];
                $b = $work[$i];
                if ($a['end'] !== null && $b['begin'] < $a['end']) {
                    $add('danger', $day, 'Überschneidung: ' . $a['begin']->format('H:i') . '–' . $a['end']->format('H:i') . ' und ' . $b['begin']->format('H:i') . '–' . ($b['end']?->format('H:i') ?? '…') . '.', $b['t']->getId());
                }
            }

            // Summen, Pausen (Pausenfeld + Lücken zwischen Einträgen ab 15 min)
            $duration = 0;
            $break = 0;
            $dayStart = $work[0]['begin'];
            $dayEnd = null;
            foreach ($work as $i => $e) {
                $duration += (int) $e['t']->getDuration();
                $break += $e['t']->getBreak();
                if ($i > 0 && $work[$i - 1]['end'] !== null) {
                    $gap = $e['begin']->getTimestamp() - $work[$i - 1]['end']->getTimestamp();
                    if ($gap >= 900) {
                        $break += $gap;
                    }
                }
                if ($e['end'] !== null && ($dayEnd === null || $e['end'] > $dayEnd)) {
                    $dayEnd = $e['end'];
                }
            }

            if ($duration > 10 * 3600) {
                $add('warning', $day, 'Mehr als 10 Stunden Arbeitszeit (' . $this->fmt($duration) . ').', $work[0]['t']->getId());
            }
            if ($duration > 9 * 3600 && $break < 45 * 60) {
                $add('info', $day, 'Über 9 h gearbeitet, aber weniger als 45 min Pause (' . $this->fmt($break) . ').', $work[0]['t']->getId());
            } elseif ($duration > 6 * 3600 && $break < 30 * 60) {
                $add('info', $day, 'Über 6 h gearbeitet, aber weniger als 30 min Pause (' . $this->fmt($break) . ').', $work[0]['t']->getId());
            }

            // Ruhezeit 11 h
            if ($prevEnd !== null) {
                $rest = $dayStart->getTimestamp() - $prevEnd->getTimestamp();
                if ($rest >= 0 && $rest < 11 * 3600) {
                    $add('info', $day, 'Nur ' . $this->fmt($rest) . ' Ruhezeit seit dem Vortag (' . $prevEnd->format('d.m. H:i') . ').', $work[0]['t']->getId());
                }
            }
            if ($dayEnd !== null) {
                $prevEnd = $dayEnd;
            }
        }

        return $issues;
    }

    private function fmt(int $seconds): string
    {
        $neg = $seconds < 0;
        $seconds = abs($seconds);

        return ($neg ? '-' : '') . \sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
