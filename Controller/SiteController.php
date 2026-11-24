<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\User;
use App\Pdf\HtmlToPdfConverter;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Repository\Query\ProjectQuery;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\SiteRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Baustellen-Zettel: Aufmaß, Material (Bestellliste) und Notizen pro Projekt.
 */
#[Route(path: '/site')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SiteController extends AbstractController
{
    public const UNITS = ['Stk', 'm', 'm²', 'm³', 'Rolle', 'Pkg', 'Satz', 'h', 'kg', 'l'];
    public const KIND_LABELS = ['aufmass' => 'Aufmaß', 'material' => 'Material', 'notiz' => 'Notiz'];
    public const SHEET_TITLES = ['aufmass' => 'Aufmaß', 'material' => 'Materialbestellung', 'notiz' => 'Notizen'];

    public function __construct(
        private readonly SiteRepository $repository,
        private readonly ProjectRepository $projectRepository,
        private readonly HtmlToPdfConverter $pdfConverter,
        private readonly Settings $settings,
        private readonly TimesheetRepository $timesheetRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
    }

    /**
     * @return array<int, Project>
     */
    private function visibleProjects(User $user): array
    {
        $query = new ProjectQuery();
        $query->setCurrentUser($user);
        $query->setPageSize(1000);
        $query->setOrderBy('name');
        $absence = array_map(fn ($ids) => $ids['project'], $this->settings->getConfiguredAbsenceTypes());
        $result = [];
        foreach ($this->projectRepository->getProjectsForQuery($query) as $project) {
            if (\in_array($project->getId(), $absence, true)) {
                continue;
            }
            $result[$project->getId()] = $project;
        }

        return $result;
    }

    private function loadProject(User $user, int $id): Project
    {
        $projects = $this->visibleProjects($user);
        if (!isset($projects[$id])) {
            throw $this->createNotFoundException('Projekt nicht gefunden');
        }

        return $projects[$id];
    }

    #[Route(path: '', name: 'wtl_site', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $projects = $this->visibleProjects($user);
        $recent = [];
        foreach ($this->repository->projectsWithItems($user->getId()) as $row) {
            if (isset($projects[$row['project_id']])) {
                $recent[] = $row + ['project' => $projects[$row['project_id']]];
            }
        }

        $customers = [];
        foreach ($projects as $project) {
            $c = $project->getCustomer();
            $key = $c?->getId() ?? 0;
            $customers[$key] ??= ['name' => $c?->getName() ?? '–', 'projects' => []];
            $customers[$key]['projects'][] = ['id' => $project->getId(), 'name' => $project->getName()];
        }
        uasort($customers, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        // Baustellen, auf denen ich zuletzt war (aus meinen Zeiten)
        $since = (new \DateTimeImmutable('-90 days'))->format('Y-m-d');
        $rows = $this->timesheetRepository->createQueryBuilder('t')
            ->select('IDENTITY(t.project) pid, MAX(t.date) last, SUM(t.duration) total')
            ->where('t.user = :user')->andWhere('t.date >= :since')
            ->setParameter('user', $user->getId())->setParameter('since', $since)
            ->groupBy('t.project')->orderBy('last', 'DESC')->setMaxResults(15)
            ->getQuery()->getArrayResult();
        $visited = [];
        foreach ($rows as $r) {
            if (isset($projects[(int) $r['pid']])) {
                $visited[] = ['project' => $projects[(int) $r['pid']], 'last' => $r['last'], 'total' => (int) $r['total']];
            }
        }

        return $this->render('@WorkTimeLite/site/index.html.twig', [
            'visited' => $visited,
            'customers' => $customers,
            'recent' => $recent,
            'openCount' => \count($this->repository->openMaterial($user->getId())),
        ]);
    }

    #[Route(path: '/orders', name: 'wtl_site_orders', methods: ['GET'])]
    public function orders(Request $request): Response
    {
        $user = $this->getUser();
        $all = $request->query->getBoolean('all');

        return $this->render('@WorkTimeLite/site/orders.html.twig', $this->ordersData($user, $all) + ['all' => $all]);
    }

    #[Route(path: '/orders/pdf', name: 'wtl_site_orders_pdf', methods: ['GET'])]
    public function ordersPdf(Request $request): Response
    {
        $user = $this->getUser();
        $html = $this->renderView('@WorkTimeLite/site/pdf.html.twig', $this->ordersData($user, $request->query->getBoolean('all')) + [
            'mode' => 'orders',
            'user' => $user,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())),
        ]);

        return $this->pdfResponse($html, 'Bestellliste ' . date('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    private function ordersData(User $user, bool $all): array
    {
        $projects = $this->visibleProjects($user);
        $groups = [];
        foreach ($this->repository->openMaterial($user->getId(), $all) as $item) {
            $supplier = trim((string) $item['supplier']) !== '' ? (string) $item['supplier'] : 'Ohne Großhändler';
            $item['project'] = $projects[(int) $item['project_id']] ?? null;
            $groups[$supplier][] = $item;
        }

        return ['groups' => $groups];
    }

    #[Route(path: '/{id}', name: 'wtl_site_project', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function project(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $project = $this->loadProject($user, $id);
        $kind = $request->query->has('kind') ? $this->kind($request->query->get('kind')) : 'overview';
        $counts = ['aufmass' => 0, 'material' => 0, 'notiz' => 0];
        $openMaterial = 0;
        $allSheets = $this->repository->sheetsForProject($user->getId(), $id);
        foreach ($allSheets as $sheet) {
            ++$counts[$sheet['kind']];
            $openMaterial += (int) $sheet['open_items'];
        }

        return $this->render('@WorkTimeLite/site/project.html.twig', [
            'overview' => $kind === 'overview' ? $this->overview($user, $project) + ['openMaterial' => $openMaterial, 'sheets' => $allSheets] : null,
            'project' => $project,
            'kind' => $kind,
            'kinds' => self::KIND_LABELS,
            'titles' => self::SHEET_TITLES,
            'counts' => $counts,
            'sheets' => $this->repository->sheetsForProject($user->getId(), $id, $kind),
        ]);
    }

    #[Route(path: '/{id}/new', name: 'wtl_site_sheet_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function newSheet(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $project = $this->loadProject($user, $id);
        $kind = $this->kind($request->query->get('kind'));
        $tz = new \DateTimeZone($user->getTimezone());

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            $date = $this->postDate($request, $tz);
            $title = mb_substr(trim((string) $request->request->get('title')), 0, 150);
            $supplier = $kind === 'material' ? mb_substr(trim((string) $request->request->get('supplier')), 0, 100) : '';
            $sheetId = $this->repository->createSheet($user->getId(), $id, $kind, $date->format('Y-m-d'), $title !== '' ? $title : null, $supplier !== '' ? $supplier : null);
            $this->addRows($request, $user, $sheetId, $id, $kind, $date->format('Y-m-d'), $supplier);

            return $this->redirectToRoute('wtl_site_sheet', ['sid' => $sheetId]);
        }

        return $this->render('@WorkTimeLite/site/sheet.html.twig', [
            'project' => $project,
            'kind' => $kind,
            'sheet' => null,
            'items' => [],
            'titles' => self::SHEET_TITLES,
            'today' => (new \DateTimeImmutable('today', $tz))->format('Y-m-d'),
        ] + $this->formHelpers($request, $user, $kind));
    }

    #[Route(path: '/sheet/{sid}', name: 'wtl_site_sheet', requirements: ['sid' => '\d+'], methods: ['GET', 'POST'])]
    public function sheet(Request $request, int $sid): Response
    {
        $user = $this->getUser();
        $sheet = $this->repository->findSheet($user->getId(), $sid) ?? throw $this->createNotFoundException();
        $project = $this->loadProject($user, (int) $sheet['project_id']);
        $kind = (string) $sheet['kind'];

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            if ($request->request->has('edit_head')) {
                $date = $this->postDate($request, new \DateTimeZone($user->getTimezone()));
                $title = mb_substr(trim((string) $request->request->get('title')), 0, 150);
                $supplier = $kind === 'material' ? mb_substr(trim((string) $request->request->get('supplier')), 0, 100) : '';
                $this->repository->updateSheet($user->getId(), $sid, $date->format('Y-m-d'), $title !== '' ? $title : null, $supplier !== '' ? $supplier : null);
            } else {
                $this->addRows($request, $user, $sid, (int) $sheet['project_id'], $kind, (string) $sheet['sheet_date'], (string) $sheet['supplier']);
            }

            return $this->redirectToRoute('wtl_site_sheet', ['sid' => $sid]);
        }

        return $this->render('@WorkTimeLite/site/sheet.html.twig', [
            'project' => $project,
            'kind' => $kind,
            'sheet' => $sheet,
            'items' => $this->repository->itemsForSheet($user->getId(), $sid),
            'titles' => self::SHEET_TITLES,
            'today' => (string) $sheet['sheet_date'],
        ] + $this->formHelpers($request, $user, $kind));
    }

    #[Route(path: '/sheet/{sid}/delete', name: 'wtl_site_sheet_delete', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function deleteSheet(Request $request, int $sid): Response
    {
        $user = $this->getUser();
        $sheet = $this->repository->findSheet($user->getId(), $sid) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            $this->repository->deleteSheet($user->getId(), $sid);
        }

        return $this->redirectToRoute('wtl_site_project', ['id' => $sheet['project_id'], 'kind' => $sheet['kind']]);
    }

    #[Route(path: '/sheet/{sid}/pdf', name: 'wtl_site_sheet_pdf', requirements: ['sid' => '\d+'], methods: ['GET'])]
    public function sheetPdf(int $sid): Response
    {
        $user = $this->getUser();
        $sheet = $this->repository->findSheet($user->getId(), $sid) ?? throw $this->createNotFoundException();
        $project = $this->loadProject($user, (int) $sheet['project_id']);
        $sheet['items_list'] = $this->repository->itemsForSheet($user->getId(), $sid);
        $title = self::SHEET_TITLES[$sheet['kind']];

        return $this->pdfResponse($this->renderView('@WorkTimeLite/site/pdf.html.twig', [
            'mode' => 'project',
            'title' => $title,
            'project' => $project,
            'sheets' => [$sheet],
            'titles' => self::SHEET_TITLES,
            'user' => $user,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())),
        ]), $title . ' ' . $project->getName() . ' ' . $sheet['sheet_date']);
    }

    #[Route(path: '/{id}/pdf', name: 'wtl_site_project_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectPdf(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $project = $this->loadProject($user, $id);
        $only = (string) $request->query->get('kind', '');
        $only = \array_key_exists($only, self::KIND_LABELS) ? $only : null;
        $title = $only !== null ? self::SHEET_TITLES[$only] : 'Baustellenbericht';
        $photos = [];
        if ($only === null && $this->settings->bool('site_photos', true)) {
            foreach (array_reverse($this->repository->photos($user->getId(), $id)) as $photo) {
                $path = $this->photoDir($user->getId()) . '/' . basename((string) $photo['filename']);
                if (is_file($path)) {
                    $photos[] = $photo + ['path' => $path];
                }
            }
        }
        $sheets = [];
        foreach (['aufmass', 'material', 'notiz'] as $k) {
            if ($only !== null && $only !== $k) {
                continue;
            }
            foreach (array_reverse($this->repository->sheetsForProject($user->getId(), $id, $k)) as $sheet) {
                $sheet['items_list'] = $this->repository->itemsForSheet($user->getId(), (int) $sheet['id']);
                $sheets[] = $sheet;
            }
        }

        return $this->pdfResponse($this->renderView('@WorkTimeLite/site/pdf.html.twig', [
            'mode' => 'project',
            'title' => $title,
            'project' => $project,
            'sheets' => $sheets,
            'photos' => $photos,
            'titles' => self::SHEET_TITLES,
            'user' => $user,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())),
        ]), $title . ' ' . $project->getName() . ' ' . date('Y-m-d'));
    }

    /**
     * Was für den Monteur zählt: Adresse, Ansprechpartner, Infos, eigene Stunden.
     *
     * @return array<string, mixed>
     */
    private function overview(User $user, Project $project): array
    {
        $customer = $project->getCustomer();
        $place = array_filter([$customer?->getAddressLine1(), trim(($customer?->getPostCode() ?? '') . ' ' . ($customer?->getCity() ?? ''))]);
        $address = $place !== [] ? implode(', ', $place) : trim((string) $customer?->getAddress());
        // Projekte heißen oft wie die Adresse ("Musterstr. 5") – dann die nehmen
        $mapsQuery = preg_match('/\d/', (string) $project->getName()) ? preg_replace('/\s*\(.*\)\s*$/', '', (string) $project->getName()) . ($customer?->getCity() ? ', ' . $customer->getCity() : '') : $address;

        $qb = $this->timesheetRepository->createQueryBuilder('t');
        $stats = $qb->select('SUM(t.duration) total, COUNT(DISTINCT t.date) days, MIN(t.date) first, MAX(t.date) last')
            ->where('t.user = :user')->andWhere('t.project = :project')
            ->setParameter('user', $user->getId())->setParameter('project', $project->getId())
            ->getQuery()->getSingleResult();

        $recent = $this->timesheetRepository->createQueryBuilder('t')
            ->where('t.user = :user')->andWhere('t.project = :project')
            ->setParameter('user', $user->getId())->setParameter('project', $project->getId())
            ->orderBy('t.begin', 'DESC')->setMaxResults(8)
            ->getQuery()->getResult();

        return [
            'customer' => $customer,
            'address' => $address,
            'mapsQuery' => $mapsQuery !== '' ? $mapsQuery : null,
            'phone' => $customer?->getMobile() ?: $customer?->getPhone(),
            'phone2' => $customer?->getMobile() && $customer?->getPhone() ? $customer->getPhone() : null,
            'stats' => $stats,
            'recent' => $recent,
            'todos' => $this->settings->bool('site_todos', true) ? $this->repository->todos($user->getId(), $project->getId()) : null,
            'photos' => $this->settings->bool('site_photos', true) ? \array_slice($this->repository->photos($user->getId(), $project->getId()), 0, 6) : null,
            'photoCount' => $this->settings->bool('site_photos', true) ? \count($this->repository->photos($user->getId(), $project->getId())) : 0,
        ];
    }

    private function kind(mixed $kind): string
    {
        $kind = (string) $kind;

        return \array_key_exists($kind, self::KIND_LABELS) ? $kind : 'aufmass';
    }

    private function postDate(Request $request, \DateTimeZone $tz): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable((string) $request->request->get('date', 'today'), $tz);
        } catch (\Exception) {
            return new \DateTimeImmutable('today', $tz);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formHelpers(Request $request, User $user, string $kind): array
    {
        return [
            'units' => self::UNITS,
            'suggestions' => $this->repository->suggestions($user->getId(), $kind),
            'suppliers' => $this->repository->suppliers($user->getId()),
            'defUnit' => (string) (((array) $request->getSession()->get('wtl_site_last', []))['unit_' . $kind] ?? ($kind === 'aufmass' ? 'm' : 'Stk')),
        ];
    }

    private function addRows(Request $request, User $user, int $sheetId, int $projectId, string $kind, string $date, string $supplier): int
    {
        $added = 0;
        $lastUnit = null;
        foreach ($request->request->all('rows') as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $qty = trim(str_replace(',', '.', (string) ($row['quantity'] ?? '')));
            $unit = $kind === 'notiz' ? '' : mb_substr(trim((string) ($row['unit'] ?? '')), 0, 20);
            $room = mb_substr(trim((string) ($row['room'] ?? '')), 0, 100);
            $lastUnit = $unit !== '' ? $unit : $lastUnit;
            $this->repository->add($user->getId(), [
                'sheet_id' => $sheetId,
                'project_id' => $projectId,
                'item_date' => $date,
                'kind' => $kind,
                'quantity' => is_numeric($qty) ? (float) $qty : null,
                'unit' => $unit !== '' ? $unit : null,
                'text' => mb_substr($text, 0, 500),
                'room' => $room !== '' ? $room : null,
                'supplier' => $supplier !== '' ? $supplier : null,
            ]);
            ++$added;
        }
        if ($lastUnit !== null) {
            $last = (array) $request->getSession()->get('wtl_site_last', []);
            $last['unit_' . $kind] = $lastUnit;
            $request->getSession()->set('wtl_site_last', $last);
        }

        return $added;
    }

    // ---------- Restarbeiten ----------

    #[Route(path: '/todos', name: 'wtl_site_todos', methods: ['GET'])]
    public function todos(): Response
    {
        $this->denyUnless('site_todos');
        $user = $this->getUser();
        $projects = $this->visibleProjects($user);
        $groups = [];
        foreach ($this->repository->todos($user->getId(), null, false) as $todo) {
            if (isset($projects[(int) $todo['project_id']])) {
                $groups[(int) $todo['project_id']]['project'] = $projects[(int) $todo['project_id']];
                $groups[(int) $todo['project_id']]['todos'][] = $todo;
            }
        }

        return $this->render('@WorkTimeLite/site/todos.html.twig', ['groups' => $groups]);
    }

    #[Route(path: '/{id}/todo', name: 'wtl_site_todo_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function todoAdd(Request $request, int $id): Response
    {
        $this->denyUnless('site_todos');
        $user = $this->getUser();
        $this->loadProject($user, $id);
        if ($this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            foreach (preg_split('/\R/', (string) $request->request->get('text')) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $this->repository->addTodo($user->getId(), $id, mb_substr($line, 0, 500));
                }
            }
        }

        return $this->redirect($this->safeBack($request, $this->generateUrl('wtl_site_project', ['id' => $id])));
    }

    #[Route(path: '/todo/{tid}/{action}', name: 'wtl_site_todo_action', requirements: ['tid' => '\d+', 'action' => 'toggle|delete'], methods: ['POST'])]
    public function todoAction(Request $request, int $tid, string $action): Response
    {
        $user = $this->getUser();
        $todo = $this->repository->findTodo($user->getId(), $tid) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            $action === 'delete' ? $this->repository->deleteTodo($user->getId(), $tid) : $this->repository->toggleTodo($user->getId(), $tid);
        }

        return $this->redirect($this->safeBack($request, $this->generateUrl('wtl_site_project', ['id' => $todo['project_id']])));
    }

    // ---------- Fotos ----------

    private function photoDir(int $userId): string
    {
        return $this->projectDir . '/var/data/wtl_photos/' . $userId;
    }

    #[Route(path: '/{id}/photos', name: 'wtl_site_photos', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function photos(Request $request, int $id): Response
    {
        $this->denyUnless('site_photos');
        $user = $this->getUser();
        $project = $this->loadProject($user, $id);

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            $comment = mb_substr(trim((string) $request->request->get('comment')), 0, 500);
            $dir = $this->photoDir($user->getId());
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $count = 0;
            foreach ($request->files->all('photos') as $file) {
                if ($file === null || !$file->isValid() || $file->getSize() > 20 * 1024 * 1024) {
                    continue;
                }
                $info = @getimagesize($file->getPathname());
                $ext = match ($info[2] ?? null) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null };
                if ($ext === null) {
                    continue;
                }
                $name = bin2hex(random_bytes(12)) . '.' . $ext;
                $file->move($dir, $name);
                $this->repository->addPhoto($user->getId(), $id, $name, $comment !== '' ? $comment : null);
                ++$count;
            }
            if ($count === 0) {
                $this->flashError('Kein Bild erkannt (JPG, PNG oder WebP).');
            }

            return $this->redirectToRoute('wtl_site_photos', ['id' => $id]);
        }

        return $this->render('@WorkTimeLite/site/photos.html.twig', [
            'project' => $project,
            'photos' => $this->repository->photos($user->getId(), $id),
        ]);
    }

    #[Route(path: '/photo/{pid}', name: 'wtl_site_photo', requirements: ['pid' => '\d+'], methods: ['GET'])]
    public function photo(int $pid): Response
    {
        $user = $this->getUser();
        $photo = $this->repository->findPhoto($user->getId(), $pid) ?? throw $this->createNotFoundException();
        $path = $this->photoDir($user->getId()) . '/' . basename((string) $photo['filename']);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    #[Route(path: '/photo/{pid}/{action}', name: 'wtl_site_photo_action', requirements: ['pid' => '\d+', 'action' => 'delete|comment'], methods: ['POST'])]
    public function photoAction(Request $request, int $pid, string $action): Response
    {
        $user = $this->getUser();
        $photo = $this->repository->findPhoto($user->getId(), $pid) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            if ($action === 'delete') {
                @unlink($this->photoDir($user->getId()) . '/' . basename((string) $photo['filename']));
                $this->repository->deletePhoto($user->getId(), $pid);
            } else {
                $c = mb_substr(trim((string) $request->request->get('comment')), 0, 500);
                $this->repository->updatePhotoComment($user->getId(), $pid, $c !== '' ? $c : null);
            }
        }

        return $this->redirectToRoute('wtl_site_photos', ['id' => $photo['project_id']]);
    }

    private function denyUnless(string $feature): void
    {
        if (!$this->settings->bool($feature, true)) {
            throw $this->createNotFoundException('Funktion ist ausgeschaltet');
        }
    }

    private function safeBack(Request $request, string $fallback): string
    {
        $back = (string) $request->request->get('back');

        return str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $fallback;
    }

    #[Route(path: '/item/{id}/delete', name: 'wtl_site_item_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $item = $this->repository->find($user->getId(), $id);
        if ($item !== null && $this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            $this->repository->delete($user->getId(), $id);
        }

        return $this->back($request, $item);
    }

    #[Route(path: '/item/{id}/status', name: 'wtl_site_item_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $item = $this->repository->find($user->getId(), $id);
        if ($item !== null && $this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
            $this->repository->setStatus($user->getId(), $id, (string) $request->request->get('status'));
        }

        return $this->back($request, $item);
    }

    /**
     * @param array<string, mixed>|null $item
     */
    private function back(Request $request, ?array $item): Response
    {
        if ($request->request->get('back') === 'orders') {
            return $this->redirectToRoute('wtl_site_orders', $request->request->getBoolean('all') ? ['all' => 1] : []);
        }
        if ($item === null) {
            return $this->redirectToRoute('wtl_site');
        }

        if (!empty($item['sheet_id'])) {
            return $this->redirectToRoute('wtl_site_sheet', ['sid' => $item['sheet_id']]);
        }

        return $this->redirectToRoute('wtl_site_project', ['id' => $item['project_id'], 'kind' => $item['kind']]);
    }

    private function pdfResponse(string $html, string $name): Response
    {
        $pdf = $this->pdfConverter->convertToPdf($html, ['format' => 'A4']);
        $name = preg_replace('/[^\pL\pN \-_.]/u', '', $name) . '.pdf';
        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, 'Baustelle.pdf'));

        return $response;
    }
}
