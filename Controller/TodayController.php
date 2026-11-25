<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Timesheet;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Timesheet\TimesheetService;
use App\Validator\ValidationFailedException;
use App\Configuration\SystemConfiguration;
use KimaiPlugin\WorkTimeLiteBundle\Service\AccountSummary;
use KimaiPlugin\WorkTimeLiteBundle\Service\MonteurRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\SiteRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Startseite "Heute": Stempeln, Baustelle von heute, Restarbeiten, offenes Material.
 */
#[Route(path: '/today')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class TodayController extends AbstractController
{
    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly TimesheetService $timesheetService,
        private readonly ProjectRepository $projectRepository,
        private readonly ActivityRepository $activityRepository,
        private readonly SiteRepository $siteRepository,
        private readonly UserWorkSettings $userSettings,
        private readonly Settings $settings,
        private readonly MonteurRepository $monteur,
        private readonly AccountSummary $accountSummary,
        private readonly SystemConfiguration $systemConfiguration,
    )
    {
    }

    #[Route(path: '', name: 'wtl_today', methods: ['GET'])]
    public function today(): Response
    {
        if (!$this->settings->bool('site_today', true)) {
            return $this->redirectToRoute('wtl_site');
        }
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0);

        $running = $this->timesheetRepository->getActiveEntries($user);
        $todays = $this->timesheetRepository->createQueryBuilder('t')
            ->where('t.user = :user')->andWhere('t.begin >= :from')->andWhere('t.begin < :to')
            ->setParameter('user', $user->getId())
            ->setParameter('from', \DateTime::createFromImmutable($today))
            ->setParameter('to', \DateTime::createFromImmutable($today->modify('+1 day')))
            ->orderBy('t.begin', 'ASC')->getQuery()->getResult();

        $worked = 0;
        foreach ($todays as $t) {
            $worked += $t->isRunning() ? ($now->getTimestamp() - $t->getBegin()->getTimestamp() - $t->getBreak()) : $t->getDuration();
        }

        // Baustelle von heute: läuft gerade > heute zuletzt > zuletzt überhaupt
        $absence = array_map(fn ($ids) => $ids['project'], $this->settings->getConfiguredAbsenceTypes());
        $current = null;
        foreach (array_merge($running, array_reverse($todays)) as $t) {
            if ($t->getProject() !== null && !\in_array($t->getProject()->getId(), $absence, true)) {
                $current = $t;
                break;
            }
        }
        $lastTs = null;
        if ($current === null) {
            foreach ($this->timesheetRepository->createQueryBuilder('t')->where('t.user = :user')->setParameter('user', $user->getId())->orderBy('t.begin', 'DESC')->setMaxResults(20)->getQuery()->getResult() as $t) {
                if ($t->getProject() !== null && !\in_array($t->getProject()->getId(), $absence, true)) {
                    $lastTs = $t;
                    break;
                }
            }
        }
        $siteTs = $current ?? $lastTs;
        $project = $siteTs?->getProject();

        $todos = [];
        $allOpenTodos = 0;
        if ($this->settings->bool('site_todos', true)) {
            $all = $this->siteRepository->todos($user->getId(), null, false);
            $allOpenTodos = \count($all);
            if ($project !== null) {
                $todos = $this->siteRepository->todos($user->getId(), $project->getId());
            }
        }
        $openMaterial = \count($this->siteRepository->openMaterial($user->getId()));

        $customer = $project?->getCustomer();
        $mapsQuery = null;
        if ($project !== null) {
            $mapsQuery = preg_match('/\d/', (string) $project->getName())
                ? preg_replace('/\s*\(.*\)\s*$/', '', (string) $project->getName()) . ($customer?->getCity() ? ', ' . $customer->getCity() : '')
                : trim(implode(', ', array_filter([$customer?->getAddressLine1(), trim(($customer?->getPostCode() ?? '') . ' ' . ($customer?->getCity() ?? ''))])));
        }

        // Überstunden-Ampel
        $ampel = null;
        if ($this->settings->bool('site_ampel', true) && $user->hasWorkHourConfiguration()) {
            try {
                $balance = (int) $this->accountSummary->build($user, (int) $now->format('Y'))['balance'];
                $h = $balance / 3600;
                $yellow = (float) str_replace(',', '.', $this->settings->string('ampel_yellow', '20'));
                $red = (float) str_replace(',', '.', $this->settings->string('ampel_red', '40'));
                $ampel = ['balance' => $balance, 'color' => $h >= $red ? 'red' : ($h >= $yellow ? 'yellow' : 'green')];
            } catch (\Throwable) {
                $ampel = null;
            }
        }

        // Kacheln
        $tools = $this->settings->bool('site_tools', true) ? $this->monteur->tools($user->getId()) : null;
        $toolsOut = $tools !== null ? \count(array_filter($tools, fn ($t) => $t['project_id'] !== null)) : 0;
        $stockLow = $this->settings->bool('site_stock', true) ? \count($this->monteur->stock($user->getId(), true)) : null;
        $running_sites = [];
        if ($this->settings->bool('site_status', true)) {
            foreach ($this->monteur->allStatus($user->getId()) as $pid => $st) {
                if ($st === 'laeuft' && ($p = $this->projectRepository->find($pid)) !== null) {
                    $running_sites[] = $p;
                }
            }
        }
        $longRunning = false;
        foreach ($running as $r) {
            if ($now->getTimestamp() - $r->getBegin()->getTimestamp() > 10 * 3600) {
                $longRunning = true;
            }
        }

        return $this->render('@WorkTimeLite/today.html.twig', [
            'ampel' => $ampel,
            'longRunning' => $longRunning,
            'pack' => $this->settings->bool('site_packlist', true) ? $this->monteur->packlist($user->getId()) : null,
            'access' => $project !== null && $this->settings->bool('site_access', true) ? $this->monteur->meta($user->getId(), $project->getId())['access_info'] : null,
            'toolsOut' => $toolsOut,
            'useTools' => $tools !== null,
            'stockLow' => $stockLow,
            'runningSites' => $running_sites,
            'useFeierabend' => $this->settings->bool('site_feierabend', true),
            'stamp' => $this->settings->bool('today_stamp', false),
            'now' => $now,
            'running' => $running,
            'todays' => $todays,
            'worked' => $worked,
            'soll' => $this->userSettings->getExpectedForDay($user, $today),
            'project' => $project,
            'isCurrent' => $current !== null,
            'siteTs' => $siteTs,
            'mapsQuery' => $mapsQuery !== '' ? $mapsQuery : null,
            'phone' => $customer?->getMobile() ?: $customer?->getPhone(),
            'todos' => $todos,
            'allOpenTodos' => $allOpenTodos,
            'openMaterial' => $openMaterial,
            'showTodos' => $this->settings->bool('site_todos', true),
            'showPhotos' => $this->settings->bool('site_photos', true),
        ]);
    }

    #[Route(path: '/start/{id}', name: 'wtl_today_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $user = $this->getUser();
        /** @var Timesheet|null $copy */
        $copy = $this->timesheetRepository->find($id);
        if ($copy === null || $copy->getUser()?->getId() !== $user->getId() || !$this->isCsrfTokenValid('wtl_today', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('wtl_today');
        }
        $timesheet = $this->timesheetService->createNewTimesheet($user, $request);
        $timesheet->setBegin(new \DateTime('now', new \DateTimeZone($user->getTimezone())));
        $timesheet->setEnd(null);
        $timesheet->setProject($copy->getProject());
        $timesheet->setActivity($copy->getActivity());
        try {
            $this->timesheetService->saveTimesheet($timesheet);
        } catch (ValidationFailedException $e) {
            $msgs = [];
            foreach ($e->getViolations() as $v) {
                $msgs[] = $v->getMessage();
            }
            $this->flashError('Konnte nicht starten: ' . implode(' ', $msgs));
        } catch (\Exception $e) {
            $this->flashError('Konnte nicht starten: ' . $e->getMessage());
        }

        return $this->redirectToRoute('wtl_today');
    }

    #[Route(path: '/stop/{id}', name: 'wtl_today_stop', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $ts = $this->timesheetRepository->find($id);
        if ($ts !== null && $ts->getUser()?->getId() === $user->getId() && $ts->isRunning() && $this->isCsrfTokenValid('wtl_today', (string) $request->request->get('_token'))) {
            try {
                $this->timesheetService->stopTimesheet($ts);
            } catch (\Exception $e) {
                $this->flashError('Konnte nicht stoppen: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('wtl_today');
    }

    #[Route(path: '/pack', name: 'wtl_today_pack', methods: ['POST'])]
    public function pack(Request $request): Response
    {
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('wtl_today', (string) $request->request->get('_token'))) {
            $id = (int) $request->request->get('id');
            $action = (string) $request->request->get('action');
            if ($action === 'add') {
                foreach (preg_split('/\R/', (string) $request->request->get('text')) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $this->monteur->addPack($user->getId(), mb_substr(trim($line), 0, 300));
                    }
                }
            } elseif ($action === 'toggle') {
                $this->monteur->togglePack($user->getId(), $id);
            } elseif ($action === 'delete') {
                $this->monteur->deletePack($user->getId(), $id);
            }
        }

        return $this->redirectToRoute('wtl_today');
    }

    #[Route(path: '/feierabend', name: 'wtl_today_feierabend', methods: ['GET', 'POST'])]
    public function feierabend(Request $request): Response
    {
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $running = $this->timesheetRepository->getActiveEntries($user);
        $ts = $running[0] ?? null;
        $project = $ts?->getProject();

        $stamp = $this->settings->bool('today_stamp', false);
        if (!$stamp || $ts === null) {
            // Tagesabschluss ohne Stoppen: Baustelle = zuletzt heute eingetragen
            $ts = null;
            $todayEntries = $this->timesheetRepository->createQueryBuilder('t')->where('t.user = :u')->andWhere('t.date = :d')
                ->setParameter('u', $user->getId())->setParameter('d', (new \DateTimeImmutable('now', $tz))->format('Y-m-d'))
                ->orderBy('t.begin', 'DESC')->getQuery()->getResult();
            $absence = array_map(fn ($ids) => $ids['project'], $this->settings->getConfiguredAbsenceTypes());
            $project = null;
            foreach ($todayEntries as $t) {
                if ($t->getProject() !== null && !\in_array($t->getProject()->getId(), $absence, true)) {
                    $project = $t->getProject();
                    break;
                }
            }
        }

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_today', (string) $request->request->get('_token'))) {
            if ($ts !== null) {
                try {
                    $time = (string) $request->request->get('end');
                    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
                        $end = (new \DateTime('now', $tz))->setTime((int) $m[1], (int) $m[2]);
                        if ($end <= $ts->getBegin()) {
                            $end = new \DateTime('now', $tz);
                        }
                        $ts->setEnd($end);
                        $brk = (string) $request->request->get('break');
                        if ($brk !== '' && preg_match('/^(\d+)$/', $brk)) {
                            $ts->setBreak((int) $brk * 60);
                        }
                        $this->timesheetService->updateTimesheet($ts);
                    } else {
                        $this->timesheetService->stopTimesheet($ts);
                    }
                } catch (\Exception $e) {
                    $this->flashError('Konnte nicht stoppen: ' . $e->getMessage());

                    return $this->redirectToRoute('wtl_today_feierabend');
                }
            }
            foreach ($request->request->all('done') as $tid) {
                $todo = $this->siteRepository->findTodo($user->getId(), (int) $tid);
                if ($todo !== null && $todo['done_at'] === null) {
                    $this->siteRepository->toggleTodo($user->getId(), (int) $tid);
                }
            }
            if ($project !== null) {
                foreach (preg_split('/\R/', (string) $request->request->get('todos')) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $this->siteRepository->addTodo($user->getId(), $project->getId(), mb_substr(trim($line), 0, 500));
                    }
                }
            }
            foreach (preg_split('/\R/', (string) $request->request->get('pack')) ?: [] as $line) {
                if (trim($line) !== '') {
                    $this->monteur->addPack($user->getId(), mb_substr(trim($line), 0, 300));
                }
            }
            if ($project !== null && $request->request->getBoolean('material')) {
                return $this->redirectToRoute('wtl_site_sheet_new', ['id' => $project->getId(), 'kind' => 'material']);
            }
            $this->addFlash('info', 'Schönen Feierabend! 🍺');

            return $this->redirectToRoute('wtl_today');
        }

        $now = new \DateTimeImmutable('now', $tz);
        $min = (int) $now->format('i');
        $rounded = $now->setTime((int) $now->format('H'), $min - $min % 5);

        return $this->render('@WorkTimeLite/feierabend.html.twig', [
            'ts' => $ts,
            'stamp' => $ts !== null,
            'project' => $project,
            'end' => $rounded->format('H:i'),
            'breakActive' => $this->systemConfiguration->isBreakTimeEnabled(),
            'todos' => $project !== null && $this->settings->bool('site_todos', true) ? $this->siteRepository->todos($user->getId(), $project->getId(), false) : [],
            'usePack' => $this->settings->bool('site_packlist', true),
        ]);
    }
}
