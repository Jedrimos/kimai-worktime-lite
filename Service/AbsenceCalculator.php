<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;

/**
 * Bewertet Abwesenheitstage für das Arbeitszeitkonto.
 */
final class AbsenceCalculator
{
    public function __construct(private readonly UserWorkSettings $userSettings)
    {
    }

    /**
     * Anteil des Tages (1 = ganzer Tag, 0.5 = halber Tag), abgeleitet aus der Dauer des Zeiteintrags.
     */
    public function getFraction(User $user, \DateTimeInterface $day, int $duration): float
    {
        $value = $this->userSettings->getAbsenceValue($user);
        $soll = $this->userSettings->getExpectedForDay($user, $day);

        $candidates = array_filter([$value, $soll], fn (int $v) => $v > 0);
        if (\count($candidates) === 0) {
            return 1.0;
        }
        $base = min($candidates);

        return $duration >= (int) round($base * 0.75) ? 1.0 : 0.5;
    }

    /**
     * Gutschrift fürs Konto in Sekunden.
     * Urlaub/Krank/Sonstiges: Abwesenheitswert (anteilig), Überstundenabbau: 0.
     */
    public function getCredit(User $user, \DateTimeInterface $day, string $type, int $duration): int
    {
        if ($type === Settings::TYPE_OVERTIME) {
            return 0;
        }

        $fraction = $this->getFraction($user, $day, $duration);

        return (int) round($this->userSettings->getAbsenceValue($user) * $fraction);
    }

    /**
     * Dauer für einen neu anzulegenden Zeiteintrag.
     */
    public function getEntryDuration(User $user, \DateTimeInterface $day, string $type, bool $halfDay, string $source): int
    {
        if ($type === Settings::TYPE_OVERTIME) {
            return 0;
        }

        $base = $source === 'soll' ? $this->userSettings->getExpectedForDay($user, $day) : $this->userSettings->getAbsenceValue($user);
        if ($base <= 0) {
            $base = $this->userSettings->getAbsenceValue($user);
        }

        $seconds = $halfDay ? (int) round($base / 2) : $base;

        // auf volle Minuten
        return (int) (round($seconds / 60) * 60);
    }
}
