<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Timesheet;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Timesheet\TimesheetService;
use App\Validator\ValidationFailedException;
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

        return $this->render('@WorkTimeLite/today.html.twig', [
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
}
