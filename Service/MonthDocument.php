<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use App\Export\ServiceExport;
use App\Repository\Query\ExportQuery;
use App\Repository\Query\TimesheetQuery;

/**
 * Erzeugt den Monats-Stundenzettel mit der gewählten Exportvorlage
 * und baut Dateinamen / Mailtexte aus den Platzhaltern.
 */
final class MonthDocument
{
    private const MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(
        private readonly ServiceExport $serviceExport,
        private readonly Settings $settings,
    )
    {
    }

    /**
     * @return array<string, string> id => Titel
     */
    public function getAvailableTemplates(): array
    {
        $list = [];
        foreach ($this->serviceExport->getRenderer() as $renderer) {
            $list[$renderer->getId()] = $renderer->getTitle();
        }

        return $list;
    }

    public function getTemplateId(): string
    {
        $id = $this->settings->string('close_template');
        if ($id === '' || $this->serviceExport->getRendererById($id) === null) {
            return 'pdf';
        }

        return $id;
    }

    /**
     * @return array{content: string, contentType: string, extension: string}
     */
    public function render(User $profile, User $currentUser, \DateTimeInterface $month): array
    {
        $renderer = $this->serviceExport->getRendererById($this->getTemplateId());
        if ($renderer === null) {
            throw new \RuntimeException('Exportvorlage nicht gefunden');
        }

        $tz = new \DateTimeZone($profile->getTimezone());
        $begin = new \DateTime($month->format('Y-m-01') . ' 00:00:00', $tz);
        $end = (clone $begin)->modify('last day of this month')->setTime(23, 59, 59);

        $query = new ExportQuery();
        $query->setCurrentUser($currentUser);
        $query->addUser($profile);
        $query->setBegin($begin);
        $query->setEnd($end);
        $query->setState(TimesheetQuery::STATE_STOPPED);
        $query->setExported(TimesheetQuery::STATE_ALL);
        $query->setRenderer($renderer->getId());

        $items = $this->serviceExport->getExportItems($query);
        $response = $renderer->render($items, $query);

        $content = $response->getContent();
        if ($content === false || $content === '') {
            // z.B. BinaryFileResponse
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        }

        $contentType = (string) ($response->headers->get('Content-Type') ?? 'application/octet-stream');
        $extension = 'pdf';
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m) === 1) {
            $ext = strtolower(pathinfo(rawurldecode($m[1]), PATHINFO_EXTENSION));
            if ($ext !== '') {
                $extension = $ext;
            }
        } elseif (str_contains($contentType, 'spreadsheetml')) {
            $extension = 'xlsx';
        } elseif (str_contains($contentType, 'csv')) {
            $extension = 'csv';
        } elseif (str_contains($contentType, 'html')) {
            $extension = 'html';
        }

        return ['content' => $content, 'contentType' => $contentType, 'extension' => $extension];
    }

    /**
     * @return array<string, string>
     */
    public function getPlaceholders(User $profile, \DateTimeInterface $month): array
    {
        $monthNr = (int) $month->format('n');

        return [
            '{monat}' => self::MONTHS[$monthNr],
            '{monat_nr}' => $month->format('m'),
            '{jahr}' => $month->format('Y'),
            '{name}' => $profile->getDisplayName(),
            '{benutzer}' => $profile->getUserIdentifier(),
        ];
    }

    public function replace(string $text, User $profile, \DateTimeInterface $month): string
    {
        return strtr($text, $this->getPlaceholders($profile, $month));
    }

    public function getFilename(User $profile, \DateTimeInterface $month, string $extension): string
    {
        $name = $this->replace($this->settings->string('close_filename', 'Stundenzettel {monat} {jahr}'), $profile, $month);
        // keine Pfade oder verbotenen Zeichen im Dateinamen
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $name) ?? '');
        if ($name === '') {
            $name = 'Stundenzettel ' . $month->format('Y-m');
        }

        return $name . '.' . $extension;
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? (string) $month;
    }
}
