<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Pdf\HtmlToPdfConverter;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\CustomFields;
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
 * Baustellenbericht (Tagesbericht) pro Tag und Baustelle – aus den eigenen Zeiten.
 */
#[Route(path: '/report')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ReportController extends AbstractController
{
    private const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly SiteRepository $siteRepository,
        private readonly CustomFields $customFields,
        private readonly Settings $settings,
        private readonly HtmlToPdfConverter $pdfConverter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
    }

    private function deny(): void
    {
        if (!$this->settings->bool('site_report', true)) {
            throw $this->createNotFoundException('Funktion ist ausgeschaltet');
        }
    }

    private function dir(User $user): string
    {
        return $this->projectDir . '/var/data/wtl_report/' . $user->getId();
    }

    private function logoPath(User $user): ?string
    {
        foreach (['png', 'jpg', 'webp'] as $ext) {
            if (is_file($p = $this->dir($user) . '/logo.' . $ext)) {
                return $p;
            }
        }

        return null;
    }

    private function signaturePath(User $user): ?string
    {
        $p = $this->dir($user) . '/signature.png';

        return is_file($p) ? $p : null;
    }

    private function employer(User $user): string
    {
        return trim((string) $user->getPreferenceValue('wtl_employer', '', false)) ?: $this->settings->string('zoll_employer');
    }

    /**
     * Einträge eines Tages, gruppiert nach Projekt (ohne Abwesenheiten).
     *
     * @return array<int, array{project: Project, entries: array<int, Timesheet>}>
     */
    private function dayGroups(User $user, \DateTimeImmutable $date): array
    {
        $absence = array_map(fn ($ids) => $ids['project'], $this->settings->getConfiguredAbsenceTypes());
        $rows = $this->timesheetRepository->createQueryBuilder('t')
            ->where('t.user = :u')->andWhere('t.date = :d')->andWhere('t.end IS NOT NULL')
            ->setParameter('u', $user->getId())->setParameter('d', $date->format('Y-m-d'))
            ->orderBy('t.begin', 'ASC')->getQuery()->getResult();
        $groups = [];
        foreach ($rows as $t) {
            $p = $t->getProject();
            if ($p === null || \in_array($p->getId(), $absence, true)) {
                continue;
            }
            $groups[$p->getId()] ??= ['project' => $p, 'entries' => []];
            $groups[$p->getId()]['entries'][] = $t;
        }

        return $groups;
    }

    /**
     * Vorschlag für den Bericht aus den Einträgen.
     *
     * @param array<int, Timesheet> $entries
     * @return array<string, mixed>
     */
    private function build(User $user, Project $project, \DateTimeImmutable $date, array $entries): array
    {
        $defs = $this->customFields->getDefinitions();
        $seconds = 0;
        $regie = false;
        $lines = [];
        foreach ($entries as $t) {
            $seconds += $t->getDuration();
            foreach ($defs as $def) {
                $val = trim((string) $t->getMetaField($def['name'])?->getValue());
                if ($val === '') {
                    continue;
                }
                if ($def['type'] === 'yesno' && str_contains(mb_strtolower($def['label']), 'regie') && $val === 'Ja') {
                    $regie = true;
                }
                if ($def['type'] === 'textarea') {
                    $lines[] = $val;
                }
            }
            if (trim((string) $t->getDescription()) !== '') {
                array_unshift($lines, trim((string) $t->getDescription()));
            }
        }
        $aufmass = false;
        foreach ($this->siteRepository->sheetsForProject($user->getId(), $project->getId(), 'aufmass') as $sheet) {
            if ($sheet['sheet_date'] === $date->format('Y-m-d') && (int) $sheet['items'] > 0) {
                $aufmass = true;
            }
        }
        if ($lines === []) {
            foreach ($entries as $t) {
                if ($t->getActivity() !== null) {
                    $lines[] = $t->getActivity()->getName();
                }
            }
        }

        return [
            'project' => $project,
            'site' => $project->getName() . ($project->getCustomer() && $project->getCustomer()->getName() !== $project->getName() ? ' (' . $project->getCustomer()->getName() . ')' : ''),
            'date' => $date,
            'weekday' => self::WEEKDAYS[(int) $date->format('w')],
            'hours' => round($seconds / 3600, 2),
            'aufmass' => $aufmass,
            'regie' => $regie,
            'text' => implode("\n", array_values(array_unique($lines))),
        ];
    }

    #[Route(path: '', name: 'wtl_report', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->deny();
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $month = new \DateTimeImmutable((string) $request->query->get('month', 'first day of this month'), $tz);
        $month = $month->modify('first day of this month')->setTime(0, 0);
        $days = [];
        for ($d = $month; $d < $month->modify('+1 month'); $d = $d->modify('+1 day')) {
            foreach ($this->dayGroups($user, $d) as $pid => $g) {
                $days[] = ['date' => $d, 'project' => $g['project'], 'hours' => array_sum(array_map(fn ($t) => $t->getDuration(), $g['entries'])) / 3600];
            }
        }

        return $this->render('@WorkTimeLite/report/index.html.twig', [
            'month' => $month,
            'days' => array_reverse($days),
            'hasSignature' => $this->signaturePath($user) !== null,
        ]);
    }

    #[Route(path: '/day', name: 'wtl_report_day', methods: ['GET', 'POST'])]
    public function day(Request $request): Response
    {
        $this->deny();
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        try {
            $date = (new \DateTimeImmutable((string) $request->query->get('date', 'today'), $tz))->setTime(0, 0);
        } catch (\Exception) {
            $date = new \DateTimeImmutable('today', $tz);
        }
        $groups = $this->dayGroups($user, $date);
        $pid = $request->query->getInt('project', (int) array_key_first($groups));
        if (!isset($groups[$pid])) {
            $this->flashError('An dem Tag gibt es keine Einträge auf dieser Baustelle.');

            return $this->redirectToRoute('wtl_report', ['month' => $date->format('Y-m-01')]);
        }
        $data = $this->build($user, $groups[$pid]['project'], $date, $groups[$pid]['entries']);

        if ($request->isMethod('POST')) {
            $data['site'] = trim((string) $request->request->get('site', $data['site']));
            $data['hours'] = (float) str_replace(',', '.', (string) $request->request->get('hours', (string) $data['hours']));
            $data['aufmass'] = $request->request->get('aufmass') === 'ja';
            $data['regie'] = $request->request->get('regie') === 'ja';
            $data['text'] = trim((string) $request->request->get('text'));

            return $this->pdf($user, [$data], 'Baustellenbericht ' . $date->format('Y-m-d') . ' ' . $groups[$pid]['project']->getName());
        }

        return $this->render('@WorkTimeLite/report/day.html.twig', [
            'data' => $data,
            'others' => array_filter($groups, fn ($g) => $g['project']->getId() !== $pid),
            'hasSignature' => $this->signaturePath($user) !== null,
        ]);
    }

    #[Route(path: '/month/pdf', name: 'wtl_report_month_pdf', methods: ['GET'])]
    public function monthPdf(Request $request): Response
    {
        $this->deny();
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $month = (new \DateTimeImmutable((string) $request->query->get('month', 'first day of this month'), $tz))->modify('first day of this month')->setTime(0, 0);
        $pages = [];
        for ($d = $month; $d < $month->modify('+1 month'); $d = $d->modify('+1 day')) {
            foreach ($this->dayGroups($user, $d) as $g) {
                $pages[] = $this->build($user, $g['project'], $d, $g['entries']);
            }
        }

        return $this->pdf($user, $pages, 'Baustellenberichte ' . $month->format('Y-m'));
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     */
    private function pdf(User $user, array $pages, string $name): Response
    {
        $html = $this->renderView('@WorkTimeLite/report/pdf.html.twig', [
            'pages' => $pages,
            'user' => $user,
            'employer' => $this->employer($user),
            'logo' => $this->logoPath($user),
            'signature' => $this->signaturePath($user),
        ]);
        $response = new Response($this->pdfConverter->convertToPdf($html, ['format' => 'A4', 'margin_top' => 10, 'margin_bottom' => 10, 'margin_left' => 15, 'margin_right' => 15, 'shrink_tables_to_fit' => 0]));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, preg_replace('/[^\pL\pN \-_.]/u', '', $name) . '.pdf', 'Baustellenbericht.pdf'));

        return $response;
    }

    // ---------- Unterschrift & Logo ----------

    #[Route(path: '/setup', name: 'wtl_report_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $this->deny();
        $user = $this->getUser();
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_report', (string) $request->request->get('_token'))) {
            $dir = $this->dir($user);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $action = (string) $request->request->get('action');
            if ($action === 'signature') {
                $data = (string) $request->request->get('signature');
                if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m) && \strlen($m[1]) < 2_000_000) {
                    file_put_contents($dir . '/signature.png', base64_decode($m[1]));
                }
            } elseif ($action === 'signature_delete') {
                @unlink($dir . '/signature.png');
            } elseif ($action === 'logo') {
                $file = $request->files->get('logo');
                if ($file !== null && $file->isValid() && $file->getSize() < 3 * 1024 * 1024) {
                    $info = @getimagesize($file->getPathname());
                    $ext = match ($info[2] ?? null) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null };
                    if ($ext !== null) {
                        foreach (['png', 'jpg', 'webp'] as $e) {
                            @unlink($dir . '/logo.' . $e);
                        }
                        $file->move($dir, 'logo.' . $ext);
                    }
                }
            } elseif ($action === 'logo_delete') {
                foreach (['png', 'jpg', 'webp'] as $e) {
                    @unlink($dir . '/logo.' . $e);
                }
            }

            return $this->redirectToRoute('wtl_report_setup');
        }

        return $this->render('@WorkTimeLite/report/setup.html.twig', [
            'hasSignature' => $this->signaturePath($user) !== null,
            'hasLogo' => $this->logoPath($user) !== null,
            'employer' => $this->employer($user),
            'v' => time(),
        ]);
    }

    #[Route(path: '/asset/{type}', name: 'wtl_report_asset', requirements: ['type' => 'logo|signature'], methods: ['GET'])]
    public function asset(string $type): Response
    {
        $user = $this->getUser();
        $path = $type === 'logo' ? $this->logoPath($user) : $this->signaturePath($user);
        if ($path === null) {
            throw $this->createNotFoundException();
        }
        $r = new BinaryFileResponse($path);
        $r->setPrivate();

        return $r;
    }
}
