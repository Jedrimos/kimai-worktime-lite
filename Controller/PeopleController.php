<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Pdf\HtmlToPdfConverter;
use App\Repository\TimesheetRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\CustomFields;
use KimaiPlugin\WorkTimeLiteBundle\Service\MonteurRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mit wem": Einsätze pro Kollege/Azubi – z. B. fürs Berichtsheft.
 */
#[Route(path: '/people')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PeopleController extends AbstractController
{
    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly CustomFields $customFields,
        private readonly HtmlToPdfConverter $pdfConverter,
        private readonly MonteurRepository $monteur,
    )
    {
    }

    /**
     * @return array<int, \App\Entity\Timesheet>
     */
    private function entries(User $user, ?string $name, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->timesheetRepository->createQueryBuilder('t')
            ->join('t.meta', 'm')
            ->where('t.user = :u')->andWhere('m.name = :n')->andWhere("m.value IS NOT NULL AND m.value <> ''")
            ->andWhere('t.date >= :from')->andWhere('t.date <= :to')
            ->setParameter('u', $user->getId())->setParameter('n', CustomFields::COMPANIONS)
            ->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('t.begin', 'ASC');
        if ($name !== null) {
            $qb->andWhere('m.value LIKE :like')->setParameter('like', '%' . addcslashes($name, '%_') . '%');
        }
        $result = $qb->getQuery()->getResult();
        if ($name === null) {
            return $result;
        }

        return array_values(array_filter($result, fn ($t) => \in_array($name, CustomFields::splitCompanions($t->getMetaField(CustomFields::COMPANIONS)?->getValue()), true)));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function range(Request $request, User $user): array
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $period = (string) $request->query->get('period', 'month');
        $ref = new \DateTimeImmutable((string) $request->query->get('date', 'today'), $tz);
        switch ($period) {
            case 'week':
                $from = $ref->modify('monday this week')->setTime(0, 0);
                $to = $from->modify('+6 days');
                break;
            case 'year':
                $from = $ref->setDate((int) $ref->format('Y'), 1, 1)->setTime(0, 0);
                $to = $from->modify('last day of december');
                break;
            default:
                $period = 'month';
                $from = $ref->modify('first day of this month')->setTime(0, 0);
                $to = $ref->modify('last day of this month');
        }

        return [$from, $to, $period];
    }

    #[Route(path: '/add', name: 'wtl_people_add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $name = mb_substr(trim(str_replace(',', ' ', (string) $request->request->get('name'))), 0, 60);
        if ($name === '' || !$this->isCsrfTokenValid('wtl_people', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], 400);
        }
        $this->monteur->addCompanion($user->getId(), $name);

        return new JsonResponse(['ok' => true, 'name' => $name]);
    }

    #[Route(path: '/remove', name: 'wtl_people_remove', methods: ['POST'])]
    public function remove(Request $request): Response
    {
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('wtl_people', (string) $request->request->get('_token'))) {
            $this->monteur->deleteCompanion($user->getId(), trim((string) $request->request->get('name')));
        }

        return $this->redirectToRoute('wtl_people');
    }

    #[Route(path: '', name: 'wtl_people', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        [$from, $to, $period] = $this->range($request, $user);
        $counts = [];
        foreach ($this->customFields->getCompanions() as $n) {
            $counts[$n] = ['days' => [], 'seconds' => 0];
        }
        foreach ($this->entries($user, null, $from, $to) as $t) {
            foreach (CustomFields::splitCompanions($t->getMetaField(CustomFields::COMPANIONS)?->getValue()) as $n) {
                $counts[$n] ??= ['days' => [], 'seconds' => 0];
                $counts[$n]['days'][$t->getBegin()->format('Y-m-d')] = true;
                $counts[$n]['seconds'] += $t->getDuration();
            }
        }

        return $this->render('@WorkTimeLite/people/index.html.twig', [
            'own' => $this->monteur->companions($user->getId()),
            'counts' => $counts,
            'from' => $from, 'to' => $to, 'period' => $period,
        ]);
    }

    #[Route(path: '/person', name: 'wtl_person', methods: ['GET'])]
    public function person(Request $request): Response
    {
        $user = $this->getUser();
        $name = trim((string) $request->query->get('name'));
        [$from, $to, $period] = $this->range($request, $user);

        return $this->render('@WorkTimeLite/people/person.html.twig', $this->personData($user, $name, $from, $to) + [
            'period' => $period,
        ]);
    }

    #[Route(path: '/person/pdf', name: 'wtl_person_pdf', methods: ['GET'])]
    public function personPdf(Request $request): Response
    {
        $user = $this->getUser();
        $name = trim((string) $request->query->get('name'));
        [$from, $to] = $this->range($request, $user);
        $html = $this->renderView('@WorkTimeLite/people/pdf.html.twig', $this->personData($user, $name, $from, $to) + ['user' => $user]);
        $response = new Response($this->pdfConverter->convertToPdf($html, ['format' => 'A4']));
        $file = 'Einsaetze ' . preg_replace('/[^\pL\pN \-_]/u', '', $name) . ' ' . $from->format('Y-m') . '.pdf';
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file, 'Einsaetze.pdf'));

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function personData(User $user, string $name, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $days = [];
        $total = 0;
        foreach ($this->entries($user, $name, $from, $to) as $t) {
            $b = \DateTimeImmutable::createFromMutable($t->getBegin())->setTimezone($tz);
            $d = $b->format('Y-m-d');
            $days[$d] ??= ['date' => $b, 'entries' => [], 'seconds' => 0];
            $days[$d]['entries'][] = $t;
            $days[$d]['seconds'] += $t->getDuration();
            $total += $t->getDuration();
        }
        // nach Woche gruppieren (Berichtsheft ist meist wöchentlich)
        $weeks = [];
        foreach ($days as $d => $day) {
            $weeks[$day['date']->format('o-W')][$d] = $day;
        }

        return ['name' => $name, 'weeks' => $weeks, 'total' => $total, 'dayCount' => \count($days), 'from' => $from, 'to' => $to];
    }
}
