<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Timesheet;
use App\Repository\TimesheetRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\CustomFields;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Daten für die Knöpfe "Standardtag" und "Wie letzter Tag" im Zeiteintrag.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DayInfoController extends AbstractController
{
    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly UserWorkSettings $userSettings,
        private readonly Settings $settings,
        private readonly CustomFields $customFields,
    )
    {
    }

    #[Route(path: '/day-info', name: 'wtl_day_info', methods: ['GET'])]
    public function info(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        try {
            $date = new \DateTimeImmutable((string) $request->query->get('date', 'today'), $tz);
        } catch (\Exception) {
            $date = new \DateTimeImmutable('today', $tz);
        }
        $date = $date->setTime(0, 0);

        // Standardtag aus dem Arbeitsvertrag
        $standard = null;
        $soll = $this->userSettings->getExpectedForDay($user, $date);
        if ($soll > 0) {
            [$h, $m] = $this->settings->getAbsenceStartTime();
            $break = $soll > 9 * 3600 ? 45 * 60 : ($soll > 6 * 3600 ? 30 * 60 : 0);
            $begin = $date->setTime($h, $m);
            $end = $begin->modify('+' . ($soll + $break) . ' seconds');
            $standard = [
                'begin' => $begin->format('H:i'),
                'end' => $end->format('H:i'),
                'break' => \sprintf('%d:%02d', intdiv($break, 3600), intdiv($break % 3600, 60)),
                'label' => $begin->format('H:i') . '–' . $end->format('H:i') . ($break > 0 ? ', ' . intdiv($break, 60) . ' min Pause' : ''),
            ];
        }

        // Beginn für neuen Eintrag: Ende des letzten Eintrags an diesem Tag, sonst Arbeitsbeginn
        [$sh, $sm] = $this->settings->getAbsenceStartTime();
        $nextBegin = \sprintf('%02d:%02d', $sh, $sm);
        $lastEnd = $this->timesheetRepository->createQueryBuilder('t')
            ->select('MAX(t.end)')
            ->where('t.user = :user')->andWhere('t.date = :date')->andWhere('t.end IS NOT NULL')
            ->setParameter('user', $user->getId())->setParameter('date', $date->format('Y-m-d'))
            ->getQuery()->getSingleScalarResult();
        $hasEntries = false;
        if ($lastEnd !== null) {
            $hasEntries = true;
            $nextBegin = (new \DateTimeImmutable((string) $lastEnd, new \DateTimeZone('UTC')))->setTimezone($tz)->format('H:i');
        }

        // letzter Arbeits-Eintrag vor diesem Tag (ohne Abwesenheiten)
        $last = null;
        $qb = $this->timesheetRepository->createQueryBuilder('t');
        $qb->where($qb->expr()->eq('t.user', ':user'))
            ->andWhere($qb->expr()->lt('t.date', ':date'))
            ->andWhere($qb->expr()->isNotNull('t.end'))
            ->setParameter('user', $user->getId())
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('t.begin', 'DESC')
            ->setMaxResults(25);
        /** @var array<Timesheet> $candidates */
        $candidates = $qb->getQuery()->getResult();
        $absenceTypes = $this->settings->getConfiguredAbsenceTypes();

        foreach ($candidates as $t) {
            $isAbsence = false;
            foreach ($absenceTypes as $ids) {
                if ($t->getProject()?->getId() === $ids['project'] && $t->getActivity()?->getId() === $ids['activity']) {
                    $isAbsence = true;
                }
            }
            if ($isAbsence || $t->getProject() === null || $t->getActivity() === null) {
                continue;
            }

            $b = \DateTimeImmutable::createFromMutable($t->getBegin())->setTimezone($tz);
            $e = \DateTimeImmutable::createFromMutable($t->getEnd())->setTimezone($tz);
            $meta = [];
            foreach ($this->customFields->getDefinitions() as $def) {
                $value = $t->getMetaField($def['name'])?->getValue();
                if ($value !== null && $value !== '') {
                    $meta[$def['name']] = (string) $value;
                }
            }
            $last = [
                'date' => $b->format('d.m.Y'),
                'customer' => (string) $t->getProject()->getCustomer()?->getId(),
                'customerName' => $t->getProject()->getCustomer()?->getName(),
                'project' => (string) $t->getProject()->getId(),
                'projectName' => $t->getProject()->getName(),
                'activity' => (string) $t->getActivity()->getId(),
                'begin' => $b->format('H:i'),
                'end' => $e->format('H:i'),
                'break' => \sprintf('%d:%02d', intdiv($t->getBreak(), 3600), intdiv($t->getBreak() % 3600, 60)),
                'description' => (string) $t->getDescription(),
                'meta' => $meta,
            ];
            break;
        }

        return new JsonResponse(['standard' => $standard, 'last' => $last, 'nextBegin' => $nextBegin, 'continues' => $hasEntries]);
    }
}
