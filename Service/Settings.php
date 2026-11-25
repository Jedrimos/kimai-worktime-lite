<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Configuration\SystemConfiguration;

/**
 * Zugriff auf die globalen Einstellungen (System → Einstellungen → Handwerker Toolbox).
 */
final class Settings
{
    public const PREFIX = 'work_time_lite.';

    public const TYPE_VACATION = 'vacation';
    public const TYPE_SICK = 'sick';
    public const TYPE_OVERTIME = 'overtime';
    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_VACATION, self::TYPE_SICK, self::TYPE_OVERTIME, self::TYPE_OTHER];

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public function get(string $key): string|int|bool|float|null
    {
        return $this->configuration->find(self::PREFIX . $key);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return ($value === null || $value === false) ? $default : trim((string) $value);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->get($key);
        if ($value === null || $value === '' || !is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    public function getAbsenceProjectId(string $type): ?int
    {
        return $this->intOrNull('absence_' . $type . '_project');
    }

    public function getAbsenceActivityId(string $type): ?int
    {
        return $this->intOrNull('absence_' . $type . '_activity');
    }

    /**
     * @return array<string, array{project: int, activity: int}>
     */
    public function getConfiguredAbsenceTypes(): array
    {
        $result = [];
        foreach (self::TYPES as $type) {
            $project = $this->getAbsenceProjectId($type);
            $activity = $this->getAbsenceActivityId($type);
            if ($project !== null && $activity !== null) {
                $result[$type] = ['project' => $project, 'activity' => $activity];
            }
        }

        return $result;
    }

    /**
     * Startzeit für automatisch angelegte Abwesenheiten als [Stunde, Minute].
     *
     * @return array{0: int, 1: int}
     */
    public function getAbsenceStartTime(): array
    {
        $normalized = TimeInputNormalizer::normalizeTime($this->string('absence_start_time', '07:00'));
        if ($normalized === null) {
            return [7, 0];
        }
        [$h, $m] = explode(':', $normalized);

        return [(int) $h, (int) $m];
    }

    /**
     * "value" = Zeiteintrag bekommt den Abwesenheitswert (z.B. 7:48)
     * "soll"  = Zeiteintrag bekommt das Tagessoll laut Vertrag (z.B. 8:30 / 5:00)
     */
    public function getAbsenceDurationSource(): string
    {
        return $this->string('absence_duration_source', 'value') === 'soll' ? 'soll' : 'value';
    }

    public function isAccountUsingAbsenceValue(): bool
    {
        return $this->bool('absence_account_uses_value', true);
    }

    /**
     * "zero"   = Feiertag: Soll wird 0
     * "credit" = Feiertag: Soll bleibt, es wird der Abwesenheitswert gutgeschrieben
     */
    public function getHolidayMode(): string
    {
        return $this->string('holiday_mode', 'zero') === 'credit' ? 'credit' : 'zero';
    }
}
