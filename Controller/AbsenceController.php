<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\User;
use App\Form\Type\DatePickerType;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\Timesheet\TimesheetService;
use App\Utils\PageSetup;
use App\Validator\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\WorkingTime\WorkingTimeService;
use KimaiPlugin\WorkTimeLiteBundle\Service\AbsenceCalculator;
use KimaiPlugin\WorkTimeLiteBundle\Service\AbsenceRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\Access;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotBlank;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
#[Route(path: '/absence')]
final class AbsenceController extends AbstractController
{
    public function __construct(
        private readonly Access $access,
        private readonly Settings $settings,
        private readonly UserWorkSettings $userSettings,
        private readonly AbsenceCalculator $calculator,
        private readonly AbsenceRepository $absenceRepository,
        private readonly UserRepository $userRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly ActivityRepository $activityRepository,
        private readonly TimesheetRepository $timesheetRepository,
        private readonly TimesheetService $timesheetService,
        private readonly WorkingTimeService $workingTimeService,
        private readonly TranslatorInterface $translator,
    )
    {
    }

    private function loadProfile(Request $request): User
    {
        $id = $request->query->getInt('user');
        $profile = $id > 0 ? $this->userRepository->find($id) : $this->getUser();
        if (!$profile instanceof User) {
            throw $this->createNotFoundException('Benutzer nicht gefunden');
        }
        if (!$this->access->canManageAbsences($profile)) {
            throw $this->createAccessDeniedException();
        }

        return $profile;
    }

    #[Route(path: '', name: 'wtl_absence', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $profile = $this->loadProfile($request);
        $types = $this->settings->getConfiguredAbsenceTypes();
        $tz = new \DateTimeZone($profile->getTimezone());
        $today = new \DateTime('today', $tz);

        $choices = [];
        foreach ($types as $type => $ids) {
            $choices['wtl.type.' . $type] = $type;
        }

        $form = $this->createFormBuilder([
            'type' => \array_key_exists(Settings::TYPE_VACATION, $types) ? Settings::TYPE_VACATION : array_key_first($types),
            'begin' => clone $today,
            'end' => clone $today,
            'halfDay' => false,
            'description' => '',
        ], ['csrf_token_id' => 'wtl_absence'])
            ->add('type', ChoiceType::class, [
                'label' => 'wtl.absence.type',
                'choices' => $choices,
                'expanded' => true,
                'constraints' => [new NotBlank()],
            ])
            ->add('begin', DatePickerType::class, ['label' => 'wtl.absence.begin', 'constraints' => [new NotBlank()]])
            ->add('end', DatePickerType::class, ['label' => 'wtl.absence.end', 'required' => false, 'help' => 'wtl.absence.end.help'])
            ->add('halfDay', CheckboxType::class, ['label' => 'wtl.absence.half_day', 'required' => false])
            ->add('description', TextType::class, ['label' => 'description', 'required' => false])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && \count($types) > 0) {
            /** @var array{type: string, begin: \DateTime, end: ?\DateTime, halfDay: bool, description: ?string} $data */
            $data = $form->getData();
            $result = $this->createAbsences($profile, $data['type'], $data['begin'], $data['end'] ?? $data['begin'], (bool) $data['halfDay'], (string) $data['description']);

            if ($result['created'] > 0) {
                $this->addFlash('info', \sprintf('%d Abwesenheitstag(e) eingetragen.', $result['created']));
            }
            if (\count($result['skipped']) > 0) {
                $this->addFlash('warning', 'Übersprungen: ' . implode(' · ', $result['skipped']));
            }
            if ($result['created'] === 0 && \count($result['skipped']) === 0) {
                $this->addFlash('warning', 'Im gewählten Zeitraum liegt kein Arbeitstag.');
            }

            return $this->redirectToRoute('wtl_absence', ['user' => $profile->getId()]);
        }

        // Übersicht des Jahres
        $year = $request->query->getInt('year', (int) $today->format('Y'));
        $begin = new \DateTimeImmutable($year . '-01-01', $tz);
        $end = new \DateTimeImmutable($year . '-12-31', $tz);
        $list = $this->absenceRepository->findByDay($profile, $begin, $end);
        krsort($list);

        $counts = ['vacation' => 0.0, 'sick' => 0.0, 'overtime' => 0.0, 'other' => 0.0];
        $rows = [];
        foreach ($list as $day => $entries) {
            $date = new \DateTimeImmutable($day, $tz);
            foreach ($entries as $entry) {
                $fraction = $this->calculator->getFraction($profile, $date, $entry['duration']);
                if ($entry['type'] === Settings::TYPE_OVERTIME) {
                    $fraction = 1.0;
                }
                $counts[$entry['type']] += $fraction;
                $rows[] = [
                    'date' => $date,
                    'type' => $entry['type'],
                    'fraction' => $fraction,
                    'duration' => $entry['duration'],
                    'id' => $entry['id'],
                    'locked' => $this->workingTimeService->isApproved($profile, $date),
                ];
            }
        }

        $entitlement = $this->userSettings->getHolidayEntitlement($profile, $year);

        $page = new PageSetup('wtl.absence.title');
        $page->setHelp('contract.html');

        return $this->render('@WorkTimeLite/absence.html.twig', [
            'page_setup' => $page,
            'form' => $form->createView(),
            'profile' => $profile,
            'configured' => \count($types) > 0,
            'rows' => $rows,
            'counts' => $counts,
            'entitlement' => $entitlement,
            'entitlementFull' => $profile->getHolidaysPerYear(),
            'remaining' => $entitlement - $counts['vacation'],
            'year' => $year,
            'absenceValue' => $this->userSettings->getAbsenceValue($profile),
            'contractUrl' => $this->generateUrl('user_contract', ['user' => $profile->getId(), 'date' => $year . '-01-01']),
            'isSelf' => $profile->getId() === $this->getUser()->getId(),
        ]);
    }

    #[Route(path: '/{id}/delete', name: 'wtl_absence_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $profile = $this->loadProfile($request);
        if (!$this->isCsrfTokenValid('wtl_absence_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Sicherheits-Token');
        }

        $timesheet = $this->timesheetRepository->find($id);
        if ($timesheet === null || $timesheet->getUser()?->getId() !== $profile->getId()) {
            throw $this->createNotFoundException();
        }

        // nur echte Abwesenheits-Einträge löschen
        $isAbsence = false;
        foreach ($this->settings->getConfiguredAbsenceTypes() as $ids) {
            if ($timesheet->getProject()?->getId() === $ids['project'] && $timesheet->getActivity()?->getId() === $ids['activity']) {
                $isAbsence = true;
            }
        }
        if (!$isAbsence) {
            throw $this->createAccessDeniedException('Kein Abwesenheits-Eintrag');
        }
        if (!$this->isGranted('delete', $timesheet)) {
            throw $this->createAccessDeniedException();
        }

        $this->timesheetService->deleteTimesheet($timesheet);
        $this->addFlash('info', 'Abwesenheit gelöscht.');

        return $this->redirectToRoute('wtl_absence', ['user' => $profile->getId(), 'year' => $request->query->getInt('year')]);
    }

    /**
     * @return array{created: int, skipped: array<string>}
     */
    private function createAbsences(User $profile, string $type, \DateTimeInterface $from, \DateTimeInterface $until, bool $halfDay, string $description): array
    {
        $ids = $this->settings->getConfiguredAbsenceTypes()[$type] ?? null;
        if ($ids === null) {
            return ['created' => 0, 'skipped' => ['Abwesenheitsart ist nicht eingerichtet.']];
        }

        $project = $this->projectRepository->find($ids['project']);
        $activity = $this->activityRepository->find($ids['activity']);
        if (!$project instanceof Project || !$activity instanceof Activity) {
            return ['created' => 0, 'skipped' => ['Projekt oder Tätigkeit der Abwesenheit wurde nicht gefunden.']];
        }

        $tz = new \DateTimeZone($profile->getTimezone());
        $day = new \DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $tz);
        $last = new \DateTimeImmutable($until->format('Y-m-d') . ' 00:00:00', $tz);
        if ($last < $day) {
            [$day, $last] = [$last, $day];
        }
        if ($day->diff($last)->days > 366) {
            return ['created' => 0, 'skipped' => ['Zeitraum ist zu lang (max. 1 Jahr).']];
        }

        $existing = $this->absenceRepository->findByDay($profile, $day, $last);
        [$startHour, $startMinute] = $this->settings->getAbsenceStartTime();
        $source = $this->settings->getAbsenceDurationSource();
        $firstDay = $profile->getWorkStartingDay();
        $lastDay = $profile->getLastWorkingDay();

        $created = 0;
        $skipped = [];

        while ($day <= $last) {
            $key = $day->format('Y-m-d');
            $label = $day->format('d.m.Y');
            $next = $day->modify('+1 day');

            if (!$this->userSettings->isWorkDay($profile, $day)) {
                $day = $next;
                continue;
            }
            if (($firstDay !== null && $day < $firstDay) || ($lastDay !== null && $day > $lastDay)) {
                $skipped[] = $label . ': außerhalb des Arbeitsvertrags';
                $day = $next;
                continue;
            }
            $holiday = $this->userSettings->getHolidayName($profile, $day);
            if ($holiday !== null) {
                $skipped[] = $label . ': Feiertag (' . $holiday . ')';
                $day = $next;
                continue;
            }
            if (\array_key_exists($key, $existing)) {
                $skipped[] = $label . ': ist schon als Abwesenheit eingetragen';
                $day = $next;
                continue;
            }
            if ($this->workingTimeService->isApproved($profile, $day)) {
                $skipped[] = $label . ': Monat ist gesperrt';
                $day = $next;
                continue;
            }

            $duration = $this->calculator->getEntryDuration($profile, $day, $type, $halfDay, $source);
            $begin = \DateTime::createFromImmutable($day->setTime($startHour, $startMinute));
            $end = (clone $begin)->modify('+' . $duration . ' seconds');

            $timesheet = $this->timesheetService->createNewTimesheet($profile);
            $timesheet->setBegin($begin);
            $timesheet->setEnd($end);
            $timesheet->setDuration($duration);
            $timesheet->setProject($project);
            $timesheet->setActivity($activity);
            $desc = trim($description);
            if ($halfDay) {
                $desc = trim('½ Tag ' . $desc);
            }
            if ($desc !== '') {
                $timesheet->setDescription($desc);
            }

            try {
                $this->timesheetService->saveTimesheet($timesheet);
                $created++;
            } catch (ValidationFailedException $ex) {
                $msgs = [];
                foreach ($ex->getViolations() as $violation) {
                    $msgs[] = $this->translator->trans((string) $violation->getMessageTemplate(), $violation->getParameters(), 'validators');
                }
                $skipped[] = $label . ': ' . implode(', ', array_unique($msgs));
            } catch (\Throwable $ex) {
                $this->logException($ex);
                $skipped[] = $label . ': ' . $ex->getMessage();
            }

            $day = $next;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
