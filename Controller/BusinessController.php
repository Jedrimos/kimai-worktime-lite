<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\Query\ProjectQuery;
use KimaiPlugin\WorkTimeLiteBundle\EventSubscriber\UserPreferenceSubscriber;
use KimaiPlugin\WorkTimeLiteBundle\Service\BusinessRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gewerbe (Nebengewerbe): Kleinunternehmer-Ampel, Einnahmen, Ausgaben mit Beleg, Fahrten, EÜR-Übersicht.
 * Nur sichtbar, wenn im Profil der Gewerbe-Modus an ist. Alles strikt pro Benutzer.
 */
#[Route(path: '/business')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class BusinessController extends AbstractController
{
    public function __construct(
        private readonly BusinessRepository $repo,
        private readonly ProjectRepository $projectRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
    }

    private function guard(): User
    {
        $user = $this->getUser();
        if (!(bool) $user->getPreferenceValue(UserPreferenceSubscriber::BUSINESS, false, false)) {
            throw $this->createNotFoundException('Gewerbe-Modus ist im Profil nicht eingeschaltet');
        }

        return $user;
    }

    private function year(Request $request): int
    {
        return $request->query->getInt('year', (int) date('Y'));
    }

    private function num(mixed $v): float
    {
        return (float) str_replace(',', '.', trim((string) $v));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user, int $year): array
    {
        $uid = $user->getId();
        $invoices = $this->repo->paidInvoices($uid, $year);
        $entries = $this->repo->entries($uid, $year);
        $kmRate = $this->num($this->repo->get($uid, 'km_rate', '0.30'));
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['income' => 0.0, 'expense' => 0.0, 'trip' => 0.0];
        }
        $income = 0.0;
        foreach ($invoices as $inv) {
            // Umsatz = netto (Gesamt minus ausgewiesene Steuer; bei Kleinunternehmer ist tax = 0)
            $net = (float) $inv['total'] - (float) $inv['tax'];
            $income += $net;
            $months[(int) substr((string) $inv['payment_date'], 5, 2)]['income'] += $net;
        }
        $expense = 0.0;
        $trip = 0.0;
        $km = 0.0;
        foreach ($entries as $e) {
            $m = (int) substr((string) $e['entry_date'], 5, 2);
            if ($e['type'] === 'income') {
                $income += (float) $e['amount'];
                $months[$m]['income'] += (float) $e['amount'];
            } elseif ($e['type'] === 'expense') {
                $expense += (float) $e['amount'];
                $months[$m]['expense'] += (float) $e['amount'];
            } elseif ($e['type'] === 'trip') {
                $cost = (float) $e['km'] * $kmRate;
                $trip += $cost;
                $km += (float) $e['km'];
                $months[$m]['trip'] += $cost;
            }
        }

        // Kleinunternehmer-Ampel (§ 19 UStG)
        $founded = (int) $this->repo->get($uid, 'founded', (string) $year);
        $prev = $this->repo->get($uid, 'prev_revenue');
        if ($prev === null && $founded < $year) {
            $prevSum = $this->summaryIncomeOnly($user, $year - 1);
            $prev = (string) $prevSum;
        }
        $foundingYear = $founded === $year;
        $limit = $foundingYear ? 25000.0 : 100000.0;
        $pct = $limit > 0 ? min(100, $income / $limit * 100) : 0;
        $next25 = min(100, $income / 25000 * 100);
        $color = $pct >= 90 ? 'red' : ($pct >= 70 ? 'yellow' : 'green');
        $prevOver = !$foundingYear && $prev !== null && (float) $prev > 25000;

        return [
            'year' => $year,
            'invoices' => $invoices,
            'open' => $this->repo->openInvoices($uid),
            'income' => $income, 'expense' => $expense, 'trip' => $trip, 'km' => $km, 'kmRate' => $kmRate,
            'profit' => $income - $expense - $trip,
            'months' => $months,
            'ampel' => ['limit' => $limit, 'pct' => $pct, 'color' => $color, 'founding' => $foundingYear, 'next25' => $next25, 'prev' => $prev !== null ? (float) $prev : null, 'prevOver' => $prevOver, 'rest' => max(0, $limit - $income)],
            'founded' => $founded,
        ];
    }

    private function summaryIncomeOnly(User $user, int $year): float
    {
        $sum = 0.0;
        foreach ($this->repo->paidInvoices($user->getId(), $year) as $inv) {
            $sum += (float) $inv['total'] - (float) $inv['tax'];
        }
        foreach ($this->repo->entries($user->getId(), $year, 'income') as $e) {
            $sum += (float) $e['amount'];
        }

        return $sum;
    }

    #[Route(path: '', name: 'wtl_business', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->guard();

        return $this->render('@WorkTimeLite/business/index.html.twig', $this->summary($user, $this->year($request)));
    }

    #[Route(path: '/settings', name: 'wtl_business_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $user = $this->guard();
        if ($this->isCsrfTokenValid('wtl_business', (string) $request->request->get('_token'))) {
            $founded = (int) $request->request->get('founded');
            $this->repo->set($user->getId(), 'founded', $founded >= 2000 && $founded <= 2100 ? (string) $founded : null);
            $prev = trim((string) $request->request->get('prev_revenue'));
            $this->repo->set($user->getId(), 'prev_revenue', $prev !== '' ? (string) $this->num($prev) : null);
            $rate = $this->num($request->request->get('km_rate', '0.30'));
            $this->repo->set($user->getId(), 'km_rate', $rate > 0 ? (string) $rate : '0.30');
        }

        return $this->redirectToRoute('wtl_business');
    }

    // ---------- Einträge (Einnahme / Ausgabe / Fahrt) ----------

    #[Route(path: '/{type}', name: 'wtl_business_list', requirements: ['type' => 'income|expense|trip'], methods: ['GET', 'POST'])]
    public function list(Request $request, string $type): Response
    {
        $user = $this->guard();
        $year = $this->year($request);
        $tz = new \DateTimeZone($user->getTimezone());

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('wtl_business', (string) $request->request->get('_token'))) {
            try {
                $date = new \DateTimeImmutable((string) $request->request->get('date', 'today'), $tz);
            } catch (\Exception) {
                $date = new \DateTimeImmutable('today', $tz);
            }
            $title = mb_substr(trim((string) $request->request->get('title')), 0, 200);
            $data = [
                'type' => $type,
                'entry_date' => $date->format('Y-m-d'),
                'title' => $title !== '' ? $title : ($type === 'trip' ? 'Fahrt' : '–'),
                'note' => mb_substr(trim((string) $request->request->get('note')), 0, 500) ?: null,
                'amount' => $type === 'trip' ? 0 : round($this->num($request->request->get('amount')), 2),
                'km' => null,
                'category' => null,
                'project_id' => $request->request->getInt('project') ?: null,
                'receipt' => null,
            ];
            if ($type === 'expense') {
                $cat = (string) $request->request->get('category');
                $data['category'] = \array_key_exists($cat, BusinessRepository::CATEGORIES) ? $cat : 'sonstiges';
                $file = $request->files->get('receipt');
                if ($file !== null && $file->isValid() && $file->getSize() < 20 * 1024 * 1024) {
                    $info = @getimagesize($file->getPathname());
                    $ext = match ($info[2] ?? null) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null };
                    if ($ext === null && $file->getMimeType() === 'application/pdf') {
                        $ext = 'pdf';
                    }
                    if ($ext !== null) {
                        $dir = $this->projectDir . '/var/data/wtl_receipts/' . $user->getId();
                        if (!is_dir($dir)) {
                            mkdir($dir, 0775, true);
                        }
                        $name = $date->format('Y-m-d') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $file->move($dir, $name);
                        $data['receipt'] = $name;
                    }
                }
            }
            if ($type === 'trip') {
                $km = $this->num($request->request->get('km'));
                if ($request->request->getBoolean('roundtrip')) {
                    $km *= 2;
                }
                $data['km'] = round($km, 1);
            }
            if (($type === 'trip' && $data['km'] > 0) || ($type !== 'trip' && $data['amount'] != 0)) {
                $this->repo->add($user->getId(), $data);
            } else {
                $this->flashError($type === 'trip' ? 'Bitte Kilometer eintragen.' : 'Bitte einen Betrag eintragen.');
            }

            return $this->redirectToRoute('wtl_business_list', ['type' => $type, 'year' => $date->format('Y')]);
        }

        $query = new ProjectQuery();
        $query->setCurrentUser($user);
        $query->setPageSize(500);
        $query->setOrderBy('name');
        $projects = [];
        foreach ($this->projectRepository->getProjectsForQuery($query) as $p) {
            $projects[$p->getId()] = $p;
        }

        $entries = $this->repo->entries($user->getId(), $year, $type);
        $sum = 0.0;
        foreach ($entries as $e) {
            $sum += $type === 'trip' ? (float) $e['km'] : (float) $e['amount'];
        }

        return $this->render('@WorkTimeLite/business/list.html.twig', [
            'type' => $type,
            'year' => $year,
            'entries' => $entries,
            'sum' => $sum,
            'kmRate' => $this->num($this->repo->get($user->getId(), 'km_rate', '0.30')),
            'categories' => BusinessRepository::CATEGORIES,
            'projects' => $projects,
            'today' => (new \DateTimeImmutable('today', $tz))->format('Y-m-d'),
        ]);
    }

    #[Route(path: '/entry/{id}/delete', name: 'wtl_business_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->guard();
        $e = $this->repo->find($user->getId(), $id) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('wtl_business', (string) $request->request->get('_token'))) {
            if ($e['receipt']) {
                @unlink($this->projectDir . '/var/data/wtl_receipts/' . $user->getId() . '/' . basename((string) $e['receipt']));
            }
            $this->repo->delete($user->getId(), $id);
        }

        return $this->redirectToRoute('wtl_business_list', ['type' => $e['type'], 'year' => substr((string) $e['entry_date'], 0, 4)]);
    }

    #[Route(path: '/receipt/{id}', name: 'wtl_business_receipt', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function receipt(int $id): Response
    {
        $user = $this->guard();
        $e = $this->repo->find($user->getId(), $id);
        $path = $e && $e['receipt'] ? $this->projectDir . '/var/data/wtl_receipts/' . $user->getId() . '/' . basename((string) $e['receipt']) : null;
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }
        $r = new BinaryFileResponse($path);
        $r->setPrivate();

        return $r;
    }

    #[Route(path: '/csv', name: 'wtl_business_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        $user = $this->guard();
        $year = $this->year($request);
        $s = $this->summary($user, $year);
        $rows = [];
        foreach ($s['invoices'] as $inv) {
            $rows[] = [(string) $inv['payment_date'], 'Einnahme', 'Rechnung ' . $inv['invoice_number'] . ' – ' . $inv['customer'], (float) $inv['total'] - (float) $inv['tax'], 0, ''];
        }
        foreach ($this->repo->entries($user->getId(), $year) as $e) {
            if ($e['type'] === 'income') {
                $rows[] = [(string) $e['entry_date'], 'Einnahme', $e['title'], (float) $e['amount'], 0, $e['note'] ?? ''];
            } elseif ($e['type'] === 'expense') {
                $rows[] = [(string) $e['entry_date'], 'Ausgabe: ' . (BusinessRepository::CATEGORIES[$e['category']] ?? 'Sonstiges'), $e['title'], 0, (float) $e['amount'], ($e['note'] ?? '') . ($e['receipt'] ? ' [Beleg]' : '')];
            } else {
                $rows[] = [(string) $e['entry_date'], 'Fahrt (' . number_format((float) $e['km'], 1, ',', '') . ' km)', $e['title'], 0, round((float) $e['km'] * $s['kmRate'], 2), $e['note'] ?? ''];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Datum', 'Art', 'Beschreibung', 'Einnahme (€)', 'Ausgabe (€)', 'Notiz'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [date('d.m.Y', strtotime($r[0])), $r[1], $r[2], number_format($r[3], 2, ',', ''), number_format($r[4], 2, ',', ''), $r[5]], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, ['', 'Summe', '', number_format($s['income'], 2, ',', ''), number_format($s['expense'] + $s['trip'], 2, ',', ''), 'Gewinn: ' . number_format($s['profit'], 2, ',', '')], ';');
        rewind($out);
        $response = new Response((string) stream_get_contents($out));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="EUER ' . $year . '.csv"');

        return $response;
    }
}
