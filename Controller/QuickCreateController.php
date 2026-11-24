<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Activity\ActivityService;
use App\Controller\AbstractController;
use App\Customer\CustomerService;
use App\Entity\Customer;
use App\Entity\Project;
use App\Project\ProjectService;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Schnell anlegen" von Kunde / Projekt / Tätigkeit direkt aus dem Zeiteintrag-Formular.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class QuickCreateController extends AbstractController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CustomerService $customerService,
        private readonly ProjectService $projectService,
        private readonly ActivityService $activityService,
        private readonly CustomerRepository $customerRepository,
        private readonly ProjectRepository $projectRepository,
    )
    {
    }

    #[Route(path: '/quick-create', name: 'wtl_quick_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->settings->bool('quick_create', true)) {
            return $this->fail('Schnell-Anlegen ist ausgeschaltet.', 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->fail('Ungültige Anfrage');
        }
        if (!$this->isCsrfTokenValid('wtl_quick_create', (string) ($data['token'] ?? ''))) {
            return $this->fail('Sicherheits-Token abgelaufen – Seite neu laden.', 403);
        }

        $type = (string) ($data['type'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            return $this->fail('Bitte einen Namen (max. 150 Zeichen) eingeben.');
        }

        try {
            return match ($type) {
                'customer' => $this->createCustomer($name),
                'project' => $this->createProject($name, (int) ($data['customer'] ?? 0)),
                'activity' => $this->createActivity($name, (int) ($data['project'] ?? 0)),
                default => $this->fail('Unbekannter Typ'),
            };
        } catch (\Throwable $ex) {
            $this->logException($ex);

            return $this->fail('Anlegen fehlgeschlagen: ' . $ex->getMessage());
        }
    }

    private function fail(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private function createCustomer(string $name): JsonResponse
    {
        if (!$this->isGranted('create_customer')) {
            return $this->fail('Du darfst keine Kunden anlegen.', 403);
        }

        $existing = $this->customerService->findCustomerByName($name);
        if ($existing !== null) {
            return new JsonResponse(['id' => $existing->getId(), 'text' => $existing->getName(), 'existing' => true]);
        }

        $customer = $this->customerService->createNewCustomer($name);
        $this->customerService->saveCustomer($customer);

        return new JsonResponse(['id' => $customer->getId(), 'text' => $customer->getName()]);
    }

    private function createProject(string $name, int $customerId): JsonResponse
    {
        if (!$this->isGranted('create_project')) {
            return $this->fail('Du darfst keine Projekte anlegen.', 403);
        }

        $customer = $customerId > 0 ? $this->customerRepository->find($customerId) : null;
        if (!$customer instanceof Customer) {
            return $this->fail('Bitte zuerst einen Kunden auswählen.');
        }

        $existing = $this->projectService->findProjectByName($name, $customer);
        if ($existing !== null) {
            return new JsonResponse(['id' => $existing->getId(), 'text' => $existing->getName(), 'existing' => true]);
        }

        $project = $this->projectService->createNewProject($customer);
        $project->setName($name);
        $this->projectService->saveProject($project);

        return new JsonResponse(['id' => $project->getId(), 'text' => $project->getName()]);
    }

    private function createActivity(string $name, int $projectId): JsonResponse
    {
        if (!$this->isGranted('create_activity')) {
            return $this->fail('Du darfst keine Tätigkeiten anlegen.', 403);
        }

        // global (für alle Projekte nutzbar) oder nur fürs gewählte Projekt
        $project = null;
        if (!$this->settings->bool('quick_create_global_activity', true)) {
            $project = $projectId > 0 ? $this->projectRepository->find($projectId) : null;
            if (!$project instanceof Project) {
                return $this->fail('Bitte zuerst ein Projekt auswählen.');
            }
        }

        $existing = $this->activityService->findActivityByName($name, $project);
        if ($existing !== null) {
            return new JsonResponse(['id' => $existing->getId(), 'text' => $existing->getName(), 'existing' => true]);
        }

        $activity = $this->activityService->createNewActivity($project);
        $activity->setName($name);
        $this->activityService->saveActivity($activity);

        return new JsonResponse(['id' => $activity->getId(), 'text' => $activity->getName()]);
    }
}
