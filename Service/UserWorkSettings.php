<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use App\WorkingTime\WorkingTimeService;
use Yasumi\Holiday;
use Yasumi\Yasumi;

/**
 * Pro-Benutzer-Werte aus dem Arbeitsvertrag (Profil → Arbeitsvertrag).
 */
final class UserWorkSettings
{
    // Benutzer-Einstellungen (werden in kimai2_user_preferences gespeichert)
    public const PREF_ABSENCE_MODE = 'wtlAbsenceMode';       // auto | fixed
    public const PREF_ABSENCE_VALUE = 'wtlAbsenceValue';     // Sekunden (nur bei fixed)
    public const PREF_HOLIDAY_REGION = 'wtlHolidayRegion';   // z.B. Germany/Bavaria
    public const PREF_HOLIDAY_ASSUMPTION = 'wtlHolidayAssumption'; // Mariä Himmelfahrt mitzählen
    public const PREF_BALANCE_START = 'wtlBalanceStart';     // Sekunden, Startsaldo (+/-)
    public const PREF_HOLIDAY_PRO_RATA = 'wtlHolidayProRata'; // Urlaub im Ein-/Austrittsjahr anteilig

    public const REGIONS = [
        'Bundesweit' => 'Germany',
        'Baden-Württemberg' => 'Germany/BadenWurttemberg',
        'Bayern' => 'Germany/Bavaria',
        'Berlin' => 'Germany/Berlin',
        'Brandenburg' => 'Germany/Brandenburg',
        'Bremen' => 'Germany/Bremen',
        'Hamburg' => 'Germany/Hamburg',
        'Hessen' => 'Germany/Hesse',
        'Mecklenburg-Vorpommern' => 'Germany/MecklenburgWesternPomerania',
        'Niedersachsen' => 'Germany/LowerSaxony',
        'Nordrhein-Westfalen' => 'Germany/NorthRhineWestphalia',
        'Rheinland-Pfalz' => 'Germany/RhinelandPalatinate',
        'Saarland' => 'Germany/Saarland',
        'Sachsen' => 'Germany/Saxony',
        'Sachsen-Anhalt' => 'Germany/SaxonyAnhalt',
        'Schleswig-Holstein' => 'Germany/SchleswigHolstein',
        'Thüringen' => 'Germany/Thuringia',
        'Österreich' => 'Austria',
        'Schweiz' => 'Switzerland',
    ];

    /** @var array<string, array<string, string>> */
    private array $holidayCache = [];

    public function __construct(private readonly WorkingTimeService $workingTimeService)
    {
    }

    /**
     * Sollzeit des Tages laut Vertrag in Sekunden.
     */
    public function getExpectedForDay(User $user, \DateTimeInterface $day): int
    {
        return $this->workingTimeService->getContractMode($user)->getCalculator($user)->getWorkHoursForDay($day);
    }

    public function isWorkDay(User $user, \DateTimeInterface $day): bool
    {
        return $this->getExpectedForDay($user, $day) > 0;
    }

    public function getAbsenceMode(User $user): string
    {
        return $user->getPreferenceValue(self::PREF_ABSENCE_MODE, 'auto', false) === 'fixed' ? 'fixed' : 'auto';
    }

    /**
     * Wert eines ganzen Abwesenheitstages in Sekunden.
     * auto:  Wochenstunden / Anzahl Arbeitstage (39h / 5 = 7:48)
     * fixed: eingetragener Wert
     */
    public function getAbsenceValue(User $user): int
    {
        if ($this->getAbsenceMode($user) === 'fixed') {
            $value = $user->getPreferenceValue(self::PREF_ABSENCE_VALUE, 0, false);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $total = 0;
        $days = 0;
        // eine beliebige, vollständige Woche (Montag bis Sonntag)
        $date = new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone($user->getTimezone()));
        for ($i = 0; $i < 7; $i++) {
            $seconds = $this->getExpectedForDay($user, $date->modify('+' . $i . ' days'));
            if ($seconds > 0) {
                $total += $seconds;
                $days++;
            }
        }

        if ($days === 0) {
            return 0;
        }

        // auf volle Minuten runden
        return (int) (round($total / $days / 60) * 60);
    }

    public function getWeeklyHours(User $user): int
    {
        $total = 0;
        $date = new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone($user->getTimezone()));
        for ($i = 0; $i < 7; $i++) {
            $total += $this->getExpectedForDay($user, $date->modify('+' . $i . ' days'));
        }

        return $total;
    }

    public function isHolidayProRata(User $user): bool
    {
        return (bool) $user->getPreferenceValue(self::PREF_HOLIDAY_PRO_RATA, true, false);
    }

    /**
     * Volle Beschäftigungsmonate im Jahr (zwischen erstem und letztem Arbeitstag).
     */
    public function getFullMonthsInYear(User $user, int $year): int
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $yearStart = new \DateTimeImmutable($year . '-01-01', $tz);
        $yearEnd = new \DateTimeImmutable($year . '-12-31', $tz);
        $first = $user->getWorkStartingDay();
        $last = $user->getLastWorkingDay();
        $from = ($first !== null && $first->format('Y-m-d') > $yearStart->format('Y-m-d')) ? new \DateTimeImmutable($first->format('Y-m-d'), $tz) : $yearStart;
        $to = ($last !== null && $last->format('Y-m-d') < $yearEnd->format('Y-m-d')) ? new \DateTimeImmutable($last->format('Y-m-d'), $tz) : $yearEnd;
        if ($to < $from) {
            return 0;
        }

        $months = 0;
        for ($m = 1; $m <= 12; $m++) {
            $mStart = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $m), $tz);
            $mEnd = $mStart->modify('last day of this month');
            if ($from <= $mStart && $to >= $mEnd) {
                $months++;
            }
        }

        return $months;
    }

    /**
     * Urlaubsanspruch im Jahr. Bei "anteilig": 1/12 pro vollem Beschäftigungsmonat,
     * Bruchteile ab einem halben Tag werden aufgerundet (wie § 5 BUrlG).
     */
    public function getHolidayEntitlement(User $user, int $year): float
    {
        $perYear = $user->getHolidaysPerYear();
        if (!$this->isHolidayProRata($user) || $perYear <= 0) {
            return $perYear;
        }

        $months = $this->getFullMonthsInYear($user, $year);
        if ($months >= 12) {
            return $perYear;
        }

        $value = $perYear * $months / 12;
        $full = floor($value);
        $fraction = $value - $full;

        return $fraction >= 0.5 ? $full + 1 : round($value, 1);
    }

    public function getBalanceStart(User $user): int
    {
        $value = $user->getPreferenceValue(self::PREF_BALANCE_START, 0, false);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function getHolidayRegion(User $user): ?string
    {
        $region = $user->getPreferenceValue(self::PREF_HOLIDAY_REGION, null, false);
        if (!\is_string($region) || !\in_array($region, self::REGIONS, true)) {
            return null;
        }

        return $region;
    }

    /**
     * Gesetzliche Feiertage des Jahres für den Benutzer.
     *
     * @return array<string, string> Y-m-d => Name
     */
    public function getHolidays(User $user, int $year): array
    {
        $region = $this->getHolidayRegion($user);
        if ($region === null) {
            return [];
        }

        $withAssumption = (bool) $user->getPreferenceValue(self::PREF_HOLIDAY_ASSUMPTION, false, false);
        $key = $region . '_' . $year . '_' . ($withAssumption ? '1' : '0');

        if (!\array_key_exists($key, $this->holidayCache)) {
            $list = [];
            try {
                $provider = Yasumi::create($region, $year, 'de_DE');
                foreach ($provider as $holiday) {
                    $isAssumption = $holiday->getKey() === 'assumptionOfMary';
                    if ($holiday->getType() !== Holiday::TYPE_OFFICIAL && !($isAssumption && $withAssumption)) {
                        continue;
                    }
                    $list[$holiday->format('Y-m-d')] = $holiday->getName();
                }
            } catch (\Throwable) {
                $list = [];
            }
            $this->holidayCache[$key] = $list;
        }

        return $this->holidayCache[$key];
    }

    public function getHolidayName(User $user, \DateTimeInterface $day): ?string
    {
        $list = $this->getHolidays($user, (int) $day->format('Y'));

        return $list[$day->format('Y-m-d')] ?? null;
    }
}
