<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Timesheet;
use App\Repository\TimesheetRepository;
use App\Utils\PageSetup;
use KimaiPlugin\WorkTimeLiteBundle\Service\CustomFields;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Meine Woche": Nachweis der Arbeitszeiten und Einsatzorte für Kontrollen (Zoll/FKS) – fürs Handy gebaut.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class WeekController extends AbstractController
{
    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly Settings $settings,
        private readonly UserWorkSettings $userSettings,
        private readonly CustomFields $customFields,
    )
    {
    }

    #[Route(path: '/week', name: 'wtl_week', methods: ['GET'])]
    public function week(Request $request): Response
    {
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());

        $date = $request->query->get('date');
        try {
            $base = \is_string($date) && $date !== '' ? new \DateTimeImmutable($date, $tz) : new \DateTimeImmutable('today', $tz);
        } catch (\Exception) {
            $base = new \DateTimeImmutable('today', $tz);
        }
        $monday = $base->modify('monday this week')->setTime(0, 0);
        $sunday = $monday->modify('+6 days')->setTime(23, 59, 59);

        $qb = $this->timesheetRepository->createQueryBuilder('t');
        $qb
            ->select('t, p, c, a')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.customer', 'c')
            ->leftJoin('t.activity', 'a')
            ->where($qb->expr()->eq('t.user', ':user'))
            ->andWhere($qb->expr()->between('t.date', ':begin', ':end'))
            ->setParameter('user', $user->getId())
            ->setParameter('begin', $monday->format('Y-m-d'))
            ->setParameter('end', $sunday->format('Y-m-d'))
            ->orderBy('t.begin', 'ASC');

        /** @var array<Timesheet> $entries */
        $entries = $qb->getQuery()->getResult();

        // Abwesenheiten (Urlaub/Krank/...) als solche kennzeichnen
        $absenceTypes = $this->settings->getConfiguredAbsenceTypes();
        $fieldDefs = $this->customFields->getDefinitions();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify('+' . $i . ' days');
            $days[$d->format('Y-m-d')] = [
                'date' => $d,
                'entries' => [],
                'duration' => 0,
                'break' => 0,
                'first' => null,
                'last' => null,
                'holiday' => $this->userSettings->getHolidayName($user, $d),
            ];
        }

        $weekTotal = 0;
        foreach ($entries as $entry) {
            $begin = $entry->getBegin();
            if ($begin === null) {
                continue;
            }
            $local = \DateTimeImmutable::createFromMutable($begin)->setTimezone($tz);
            $key = $local->format('Y-m-d');
            if (!\array_key_exists($key, $days)) {
                continue;
            }

            $absence = null;
            foreach ($absenceTypes as $type => $ids) {
                if ($entry->getProject()?->getId() === $ids['project'] && $entry->getActivity()?->getId() === $ids['activity']) {
                    $absence = $type;
                }
            }

            $end = $entry->getEnd() !== null ? \DateTimeImmutable::createFromMutable($entry->getEnd())->setTimezone($tz) : null;
            $meta = [];
            foreach ($fieldDefs as $def) {
                $field = $entry->getMetaField($def['name']);
                $value = $field?->getValue();
                if ($value !== null && $value !== '' && !($def['type'] === 'yesno' && $value === 'Nein')) {
                    $meta[] = ['label' => $def['label'], 'value' => (string) $value];
                }
            }

            $days[$key]['entries'][] = [
                'begin' => $local,
                'end' => $end,
                'running' => $end === null,
                'duration' => (int) $entry->getDuration(),
                'break' => $entry->getBreak(),
                'customer' => $entry->getProject()?->getCustomer()?->getName(),
                'project' => $entry->getProject()?->getName(),
                'activity' => $entry->getActivity()?->getName(),
                'description' => $entry->getDescription(),
                'absence' => $absence,
                'meta' => $meta,
            ];

            if ($absence === null) {
                $days[$key]['duration'] += (int) $entry->getDuration();
                $days[$key]['break'] += $entry->getBreak();
                if ($days[$key]['first'] === null || $local < $days[$key]['first']) {
                    $days[$key]['first'] = $local;
                }
                if ($end !== null && ($days[$key]['last'] === null || $end > $days[$key]['last'])) {
                    $days[$key]['last'] = $end;
                }
                $weekTotal += (int) $entry->getDuration();
            }
        }

        $page = new PageSetup('wtl.week.title');
        $page->setHelp('timesheet.html');

        return $this->render('@WorkTimeLite/week.html.twig', [
            'page_setup' => $page,
            'user' => $user,
            'days' => $days,
            'monday' => $monday,
            'sunday' => $sunday,
            'weekTotal' => $weekTotal,
            'employer' => $this->settings->string('zoll_employer'),
            'prev' => $monday->modify('-7 days')->format('Y-m-d'),
            'next' => $monday->modify('+7 days')->format('Y-m-d'),
            'isCurrent' => $monday->format('Y-m-d') === (new \DateTimeImmutable('today', $tz))->modify('monday this week')->format('Y-m-d'),
            'now' => new \DateTimeImmutable('now', $tz),
        ]);
    }
}
