<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\Query\ProjectQuery;
use App\Repository\TimesheetRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\MonteurRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Werkzeug-Tracker, Bus-Lager und Steuer-Hilfe.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ToolboxController extends AbstractController
{
    public const PLACES = ['Bus', 'Lager', 'Werkstatt', 'Zuhause', 'Verliehen'];

    public function __construct(
        private readonly MonteurRepository $monteur,
        private readonly Settings $settings,
        private readonly ProjectRepository $projectRepository,
        private readonly TimesheetRepository $timesheetRepository,
    )
    {
    }

    private function deny(string $feature): void
    {
        if (!$this->settings->bool($feature, true)) {
            throw $this->createNotFoundException('Funktion ist ausgeschaltet');
        }
    }

    /**
     * @return array<int, \App\Entity\Project>
     */
    private function projects(User $user): array
    {
        $query = new ProjectQuery();
        $query->setCurrentUser($user);
        $query->setPageSize(1000);
        $query->setOrderBy('name');
        $absence = array_map(fn ($ids) => $ids['project'], $this->settings->getConfiguredAbsenceTypes());
        $out = [];
        foreach ($this->projectRepository->getProjectsForQuery($query) as $p) {
            if (!\in_array($p->getId(), $absence, true)) {
                $out[$p->getId()] = $p;
            }
        }

        return $out;
    }

    // ---------- Werkzeug ----------

    #[Route(path: '/tools', name: 'wtl_tools', methods: ['GET', 'POST'])]
    public function tools(Request $request): Response
    {
        $this->deny('site_tools');
        $user = $this->getUser();
        $projects = $this->projects($user);

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_tools', (string) $request->request->get('_token'))) {
            $action = (string) $request->request->get('action');
            $id = (int) $request->request->get('id');
            if ($action === 'add') {
                $name = mb_substr(trim((string) $request->request->get('name')), 0, 150);
                if ($name !== '') {
                    $this->monteur->addTool($user->getId(), $name, 'Bus');
                }
            } elseif ($action === 'move') {
                $where = (string) $request->request->get('where');
                if (str_starts_with($where, 'p:') && isset($projects[(int) substr($where, 2)])) {
                    $pid = (int) substr($where, 2);
                    $this->monteur->moveTool($user->getId(), $id, $projects[$pid]->getName(), $pid);
                } else {
                    $where = mb_substr(trim($where), 0, 150);
                    $this->monteur->moveTool($user->getId(), $id, $where !== '' ? $where : null, null);
                }
            } elseif ($action === 'delete') {
                $this->monteur->deleteTool($user->getId(), $id);
            }

            return $this->redirectToRoute('wtl_tools');
        }

        // Baustelle von heute als Schnellauswahl
        $last = $this->timesheetRepository->createQueryBuilder('t')->where('t.user = :u')->setParameter('u', $user->getId())
            ->orderBy('t.begin', 'DESC')->setMaxResults(10)->getQuery()->getResult();
        $recent = [];
        foreach ($last as $t) {
            if ($t->getProject() !== null && isset($projects[$t->getProject()->getId()])) {
                $recent[$t->getProject()->getId()] = $t->getProject();
            }
        }

        return $this->render('@WorkTimeLite/tools.html.twig', [
            'tools' => $this->monteur->tools($user->getId()),
            'places' => self::PLACES,
            'recent' => \array_slice($recent, 0, 3, true),
            'projects' => $projects,
        ]);
    }

    // ---------- Bus-Lager ----------

    #[Route(path: '/stock', name: 'wtl_stock', methods: ['GET', 'POST'])]
    public function stock(Request $request): Response
    {
        $this->deny('site_stock');
        $user = $this->getUser();
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_stock', (string) $request->request->get('_token'))) {
            $action = (string) $request->request->get('action');
            $id = (int) $request->request->get('id');
            if ($action === 'add') {
                foreach (preg_split('/\R/', (string) $request->request->get('text')) ?: [] as $line) {
                    $line = mb_substr(trim($line), 0, 200);
                    if ($line !== '') {
                        $this->monteur->addStock($user->getId(), $line, null);
                    }
                }
            } elseif ($action === 'toggle') {
                $this->monteur->toggleStock($user->getId(), $id);
            } elseif ($action === 'delete') {
                $this->monteur->deleteStock($user->getId(), $id);
            }
            $back = (string) $request->request->get('back');

            return str_starts_with($back, '/') && !str_starts_with($back, '//') ? $this->redirect($back) : $this->redirectToRoute('wtl_stock');
        }

        return $this->render('@WorkTimeLite/stock.html.twig', [
            'items' => $this->monteur->stock($user->getId()),
        ]);
    }

    // ---------- Steuer-Hilfe ----------

    /**
     * @return array<string, mixed>
     */
    private function taxData(User $user, int $year): array
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $absence = array_map(fn ($ids) => $ids['project'], $this->settings->getConfiguredAbsenceTypes());
        $travel = (int) $this->settings->string('tax_travel_minutes', '0') * 60;
        $mealRate = (float) str_replace(',', '.', $this->settings->string('tax_meal_rate', '14'));
        $kmRate = (float) str_replace(',', '.', $this->settings->string('tax_km_rate', '0.38'));
        $km = (float) str_replace(',', '.', $this->settings->string('tax_km', '0'));

        $rows = $this->timesheetRepository->createQueryBuilder('t')
            ->where('t.user = :u')->andWhere('t.end IS NOT NULL')->andWhere('t.date >= :from')->andWhere('t.date <= :to')
            ->setParameter('u', $user->getId())->setParameter('from', $year . '-01-01')->setParameter('to', $year . '-12-31')
            ->orderBy('t.begin', 'ASC')->getQuery()->getResult();

        $days = [];
        foreach ($rows as $t) {
            if ($t->getProject() === null || \in_array($t->getProject()->getId(), $absence, true)) {
                continue;
            }
            $b = \DateTimeImmutable::createFromMutable($t->getBegin())->setTimezone($tz);
            $e = \DateTimeImmutable::createFromMutable($t->getEnd())->setTimezone($tz);
            $d = $b->format('Y-m-d');
            $days[$d] ??= ['date' => $b->setTime(0, 0), 'first' => $b, 'last' => $e, 'work' => 0, 'projects' => []];
            $days[$d]['first'] = min($days[$d]['first'], $b);
            $days[$d]['last'] = max($days[$d]['last'], $e);
            $days[$d]['work'] += $t->getDuration();
            $days[$d]['projects'][$t->getProject()->getName()] = true;
        }

        $months = [];
        $total = ['days' => 0, 'meal_days' => 0, 'meal' => 0.0, 'km' => 0.0];
        foreach ($days as $d => &$day) {
            $span = $day['last']->getTimestamp() - $day['first']->getTimestamp() + $travel;
            $day['span'] = $span;
            $day['meal'] = $span > 8 * 3600;
            $m = (int) substr($d, 5, 2);
            $months[$m] ??= ['days' => 0, 'meal_days' => 0];
            ++$months[$m]['days'];
            ++$total['days'];
            if ($day['meal']) {
                ++$months[$m]['meal_days'];
                ++$total['meal_days'];
            }
        }
        unset($day);
        $total['meal'] = $total['meal_days'] * $mealRate;
        $total['km'] = $total['days'] * $km * $kmRate;
        ksort($months);

        return ['days' => $days, 'months' => $months, 'total' => $total, 'mealRate' => $mealRate, 'kmRate' => $kmRate, 'km' => $km, 'travel' => $travel];
    }

    #[Route(path: '/tax', name: 'wtl_tax', methods: ['GET'])]
    public function tax(Request $request): Response
    {
        $this->deny('site_tax');
        $user = $this->getUser();
        $year = $request->query->getInt('year', (int) date('Y'));

        return $this->render('@WorkTimeLite/tax.html.twig', $this->taxData($user, $year) + ['year' => $year]);
    }

    #[Route(path: '/tax/csv', name: 'wtl_tax_csv', methods: ['GET'])]
    public function taxCsv(Request $request): Response
    {
        $this->deny('site_tax');
        $user = $this->getUser();
        $year = $request->query->getInt('year', (int) date('Y'));
        $data = $this->taxData($user, $year);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Datum', 'Von', 'Bis', 'Abwesenheit (h)', 'Arbeitszeit (h)', 'Verpflegung', 'Baustellen'], ';');
        foreach ($data['days'] as $day) {
            fputcsv($out, [
                $day['date']->format('d.m.Y'), $day['first']->format('H:i'), $day['last']->format('H:i'),
                number_format($day['span'] / 3600, 2, ',', ''), number_format($day['work'] / 3600, 2, ',', ''),
                $day['meal'] ? number_format($data['mealRate'], 2, ',', '') : '0,00', implode(', ', array_keys($day['projects'])),
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        $response = new Response((string) $csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="Steuer-Hilfe ' . $year . '.csv"');

        return $response;
    }
}
