<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

/**
 * Macht aus bequemen Eingaben gültige Uhrzeiten bzw. Dauern.
 *
 * Uhrzeit:  7 → 07:00 | 7.00 / 7,00 → 07:00 | 7.3 → 07:30 | 730 / 0730 → 07:30 | 16 15 → 16:15
 * Dauer (nur wenn eingeschaltet): 7.30 / 7,30 → 7:30 | 7.3 → 7:30 | 0.45 → 0:45
 *
 * Die gleiche Logik gibt es als JavaScript in Resources/views/input-normalizer.html.twig.
 */
final class TimeInputNormalizer
{
    public static function normalizeTime(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = trim($input);
        if ($value === '') {
            return null;
        }

        $hour = null;
        $minute = null;

        if (preg_match('/^(\d{1,2})\s*[:.,;\- h]\s*(\d{1,2})$/u', $value, $m) === 1) {
            $hour = (int) $m[1];
            $minute = \strlen($m[2]) === 1 ? (int) $m[2] * 10 : (int) $m[2];
        } elseif (preg_match('/^(\d{1,2})(\d{2})$/', $value, $m) === 1) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        } elseif (preg_match('/^(\d{1,2})\s*h?$/', $value, $m) === 1) {
            $hour = (int) $m[1];
            $minute = 0;
        }

        if ($hour === null || $minute === null || $hour > 23 || $minute > 59) {
            return null;
        }

        return \sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Interpretiert "7.30" als 7 Stunden 30 Minuten (statt 7,3 Stunden).
     * Gibt null zurück, wenn die Eingabe nicht in dieses Muster passt (dann bleibt sie unverändert).
     */
    public static function normalizeDurationDotMinutes(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = trim($input);
        if (preg_match('/^(-?)(\d{1,3})[.,](\d{1,2})$/', $value, $m) !== 1) {
            return null;
        }

        $minute = \strlen($m[3]) === 1 ? (int) $m[3] * 10 : (int) $m[3];
        if ($minute > 59) {
            return null;
        }

        return \sprintf('%s%d:%02d', $m[1], (int) $m[2], $minute);
    }
}
