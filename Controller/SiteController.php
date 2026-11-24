<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\User;
use App\Pdf\HtmlToPdfConverter;
use App\Repository\ProjectRepository;
use App\Repository\Query\ProjectQuery;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\SiteRepository;
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

    public function __construct(
        private readonly SiteRepository $repository,
        private readonly ProjectRepository $projectRepository,
        private readonly HtmlToPdfConverter $pdfConverter,
        private readonly Settings $settings,
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

        return $this->render('@WorkTimeLite/site/index.html.twig', [
            'projects' => $projects,
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

    #[Route(path: '/{id}', name: 'wtl_site_project', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function project(Request $request, int $id): Response
    {
        $user = $this->getUser();
        $project = $this->loadProject($user, $id);
        $tz = new \DateTimeZone($user->getTimezone());
        $kind = (string) $request->query->get('kind', 'aufmass');
        if (!\array_key_exists($kind, self::KIND_LABELS)) {
            $kind = 'aufmass';
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('wtl_site', (string) $request->request->get('_token'))) {
                $this->flashError('Sitzung abgelaufen, bitte nochmal.');

                return $this->redirectToRoute('wtl_site_project', ['id' => $id, 'kind' => $kind]);
            }
            $kind = (string) $request->request->get('kind', $kind);
            if (!\array_key_exists($kind, self::KIND_LABELS)) {
                $kind = 'aufmass';
            }
            $text = trim((string) $request->request->get('text'));
            $qty = trim(str_replace(',', '.', (string) $request->request->get('quantity')));
            try {
                $date = new \DateTimeImmutable((string) $request->request->get('date', 'today'), $tz);
            } catch (\Exception) {
                $date = new \DateTimeImmutable('today', $tz);
            }
            if ($text === '') {
                $this->flashError('Bitte einen Text eingeben.');
            } else {
                $this->repository->add($user->getId(), [
                    'project_id' => $id,
                    'item_date' => $date->format('Y-m-d'),
                    'kind' => $kind,
                    'quantity' => is_numeric($qty) ? (float) $qty : null,
                    'unit' => $kind === 'notiz' ? null : (mb_substr(trim((string) $request->request->get('unit')), 0, 20) ?: null),
                    'text' => mb_substr($text, 0, 500),
                    'room' => mb_substr(trim((string) $request->request->get('room')), 0, 100) ?: null,
                    'supplier' => $kind === 'material' ? (mb_substr(trim((string) $request->request->get('supplier')), 0, 100) ?: null) : null,
                ]);
                // Datum, Raum und Großhändler für die nächste Zeile merken
                $last = (array) $request->getSession()->get('wtl_site_last', []);
                $last['unit_' . $kind] = (string) $request->request->get('unit');
                $last['date'] = $date->format('Y-m-d');
                $last['room'] = (string) $request->request->get('room');
                if ($kind === 'material') {
                    $last['supplier'] = (string) $request->request->get('supplier');
                }
                $request->getSession()->set('wtl_site_last', $last);
            }

            return $this->redirectToRoute('wtl_site_project', ['id' => $id, 'kind' => $kind]);
        }

        $items = $this->repository->forProject($user->getId(), $id);
        $byKind = ['aufmass' => [], 'material' => [], 'notiz' => []];
        foreach ($items as $item) {
            $byKind[$item['kind']][$item['item_date']][] = $item;
        }
        $last = $request->getSession()->get('wtl_site_last', []);

        return $this->render('@WorkTimeLite/site/project.html.twig', [
            'project' => $project,
            'kind' => $kind,
            'kinds' => self::KIND_LABELS,
            'byKind' => $byKind,
            'units' => self::UNITS,
            'suggestions' => $this->repository->suggestions($user->getId(), $kind),
            'suppliers' => $this->repository->suppliers($user->getId()),
            'last' => $last,
            'today' => (new \DateTimeImmutable('today', $tz))->format('Y-m-d'),
        ]);
    }

    #[Route(path: '/{id}/pdf', name: 'wtl_site_project_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectPdf(int $id): Response
    {
        $user = $this->getUser();
        $project = $this->loadProject($user, $id);
        $byKind = ['aufmass' => [], 'material' => [], 'notiz' => []];
        foreach ($this->repository->forProject($user->getId(), $id) as $item) {
            $byKind[$item['kind']][$item['item_date']][] = $item;
        }
        $html = $this->renderView('@WorkTimeLite/site/pdf.html.twig', [
            'mode' => 'project',
            'project' => $project,
            'byKind' => $byKind,
            'kinds' => self::KIND_LABELS,
            'user' => $user,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())),
        ]);

        return $this->pdfResponse($html, 'Baustelle ' . $project->getName() . ' ' . date('Y-m-d'));
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
