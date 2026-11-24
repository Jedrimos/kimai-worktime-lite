<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\WorkTimeLiteBundle\Service\PluginMailer;
use App\Repository\UserRepository;
use App\Utils\PageSetup;
use App\WorkingTime\WorkingTimeService;
use KimaiPlugin\WorkTimeLiteBundle\Service\Access;
use KimaiPlugin\WorkTimeLiteBundle\Service\DocumentStorage;
use KimaiPlugin\WorkTimeLiteBundle\Service\MonthDocument;
use KimaiPlugin\WorkTimeLiteBundle\Service\MonthLocker;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
#[Route(path: '/month')]
final class MonthController extends AbstractController
{
    public function __construct(
        private readonly Access $access,
        private readonly Settings $settings,
        private readonly MonthDocument $document,
        private readonly MonthLocker $locker,
        private readonly DocumentStorage $storage,
        private readonly UserRepository $userRepository,
        private readonly WorkingTimeService $workingTimeService,
    )
    {
    }

    private function loadProfile(int $userId): User
    {
        $profile = $this->userRepository->find($userId);
        if (!$profile instanceof User) {
            throw $this->createNotFoundException('Benutzer nicht gefunden');
        }

        return $profile;
    }

    private function monthDate(User $profile, int $year, int $month): \DateTimeImmutable
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw $this->createNotFoundException('Ungültiger Monat');
        }

        return new \DateTimeImmutable(\sprintf('%04d-%02d-01 00:00:00', $year, $month), new \DateTimeZone($profile->getTimezone()));
    }

    private function contractUrl(User $profile, \DateTimeInterface $month): string
    {
        return $this->generateUrl('user_contract', ['user' => $profile->getId(), 'date' => $month->format('Y') . '-01-01']);
    }

    #[Route(path: '/{user}/{year}/{month}/pdf', name: 'wtl_month_pdf', requirements: ['user' => '\d+', 'year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function pdf(int $user, int $year, int $month, Request $request): Response
    {
        $profile = $this->loadProfile($user);
        if (!$this->access->canClose($profile)) {
            throw $this->createAccessDeniedException();
        }

        $date = $this->monthDate($profile, $year, $month);
        $doc = $this->document->render($profile, $this->getUser(), $date);
        $filename = $this->document->getFilename($profile, $date, $doc['extension']);

        $disposition = $request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?? 'stundenzettel.pdf';

        return new Response($doc['content'], 200, [
            'Content-Type' => $doc['contentType'],
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $fallback),
        ]);
    }

    #[Route(path: '/mail-test', name: 'wtl_mail_test', methods: ['GET'])]
    #[IsGranted('system_configuration')]
    public function mailTest(PluginMailer $mailer): Response
    {
        $to = $this->settings->string('smtp_from') ?: $this->settings->string('smtp_user') ?: (string) $this->getUser()->getEmail();
        try {
            $mailer->send((new Email())->to($to)->subject('WorkTime Lite: Testmail')->text('Der Mailversand aus Kimai funktioniert.'));
            $this->addFlash('info', 'Testmail gesendet an ' . $to . ($mailer->hasOwnSmtp() ? ' (eigener SMTP)' : ' (Kimai-Mailserver)'));
        } catch (\Throwable $ex) {
            $this->addFlash('error', 'Testmail fehlgeschlagen: ' . $ex->getMessage());
        }

        return $this->redirectToRoute('system_configuration_section', ['section' => 'work_time_lite']);
    }

    #[Route(path: '/{user}/{year}/{month}/unlock', name: 'wtl_month_unlock', requirements: ['user' => '\d+', 'year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET'])]
    public function unlock(int $user, int $year, int $month, Request $request): Response
    {
        $profile = $this->loadProfile($user);
        if (!$this->access->canUnlock($profile)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('wtl_unlock', (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Ungültiges Sicherheits-Token');
        }

        $date = $this->monthDate($profile, $year, $month);
        $months = $this->locker->unlock($profile, $date, $this->getUser());

        if (\count($months) > 0) {
            $this->addFlash('info', 'Entsperrt: ' . implode(', ', array_map(fn (int $m) => MonthDocument::monthName($m), $months)) . ' ' . $year);
        } else {
            $this->addFlash('warning', 'Es war kein Monat gesperrt.');
        }

        return $this->redirect($this->contractUrl($profile, $date));
    }

    #[Route(path: '/{user}/{year}/{month}', name: 'wtl_month_close', requirements: ['user' => '\d+', 'year' => '\d{4}', 'month' => '\d{1,2}'], methods: ['GET', 'POST'])]
    public function close(int $user, int $year, int $month, Request $request, PluginMailer $mailer): Response
    {
        $profile = $this->loadProfile($user);
        if (!$this->access->canClose($profile)) {
            throw $this->createAccessDeniedException();
        }

        $date = $this->monthDate($profile, $year, $month);
        $tz = new \DateTimeZone($profile->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $monthModel = $this->workingTimeService->getMonth($profile, $date, $now);
        $isLocked = $monthModel->isLocked();
        $endOfMonth = $date->modify('last day of this month')->setTime(23, 59, 59);
        $canLock = !$isLocked && $endOfMonth < $now && !$request->query->getBoolean('nolock');

        $mailTo = $this->settings->string('mail_to');
        $data = [
            'to' => $mailTo,
            'cc' => $this->settings->string('mail_cc'),
            'subject' => $this->document->replace($this->settings->string('mail_subject'), $profile, $date),
            'body' => $this->document->replace($this->settings->string('mail_body'), $profile, $date),
            'lock' => $canLock && $this->settings->bool('close_lock', true),
            'mail' => $mailTo !== '' && $this->settings->bool('close_mail', true),
            'store' => $this->storage->isEnabled(),
            'download' => $this->settings->bool('close_download', true),
        ];

        $builder = $this->createFormBuilder($data, ['csrf_token_id' => 'wtl_month_close'])
            ->add('to', TextType::class, ['label' => 'wtl.mail.to', 'required' => false, 'help' => 'wtl.mail.to.help'])
            ->add('cc', TextType::class, ['label' => 'wtl.mail.cc', 'required' => false])
            ->add('subject', TextType::class, ['label' => 'wtl.mail.subject', 'required' => false])
            ->add('body', TextareaType::class, ['label' => 'wtl.mail.body', 'required' => false, 'attr' => ['rows' => 8]])
            ->add('mail', CheckboxType::class, ['label' => 'wtl.close.do_mail', 'required' => false])
            ->add('download', CheckboxType::class, ['label' => 'wtl.close.do_download', 'required' => false]);
        if ($canLock) {
            $builder->add('lock', CheckboxType::class, ['label' => 'wtl.close.do_lock', 'required' => false]);
        }
        if ($this->storage->isEnabled()) {
            $builder->add('store', CheckboxType::class, ['label' => 'wtl.close.do_store', 'required' => false]);
        }
        $form = $builder->getForm();
        $form->handleRequest($request);

        $downloadUrl = null;
        $done = false;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $values */
            $values = $form->getData();
            $errors = 0;
            $messages = [];

            // 1. sperren
            if ($canLock && !empty($values['lock'])) {
                try {
                    $locked = $this->locker->lock($profile, $date, $this->getUser());
                    if (\count($locked) > 0) {
                        $messages[] = ('Gesperrt: ' . implode(', ', array_map(fn (int $m) => MonthDocument::monthName($m), $locked)) . ' ' . $year);
                    }
                } catch (\Throwable $ex) {
                    $errors++;
                    $this->logException($ex);
                    $this->addFlash('error', 'Sperren fehlgeschlagen: ' . $ex->getMessage());
                }
            }

            $needsDocument = !empty($values['mail']) || !empty($values['store']);
            $doc = null;
            $filename = null;
            if ($needsDocument) {
                try {
                    $doc = $this->document->render($profile, $this->getUser(), $date);
                    $filename = $this->document->getFilename($profile, $date, $doc['extension']);
                } catch (\Throwable $ex) {
                    $errors++;
                    $this->logException($ex);
                    $this->addFlash('error', 'Stundenzettel konnte nicht erstellt werden: ' . $ex->getMessage());
                }
            }

            // 2. Mail
            if ($doc !== null && $filename !== null && !empty($values['mail'])) {
                try {
                    $recipients = $this->parseAddresses((string) ($values['to'] ?? ''));
                    if (\count($recipients) === 0) {
                        throw new \InvalidArgumentException('Kein Empfänger eingetragen');
                    }
                    $email = (new Email())
                        ->to(...$recipients)
                        ->subject((string) ($values['subject'] ?? ''))
                        ->text((string) ($values['body'] ?? ''))
                        ->attach($doc['content'], $filename, $doc['contentType']);
                    $cc = $this->parseAddresses((string) ($values['cc'] ?? ''));
                    if (\count($cc) > 0) {
                        $email->cc(...$cc);
                    }
                    if ($profile->getEmail() !== null) {
                        $email->replyTo(new Address($profile->getEmail(), $profile->getDisplayName()));
                    }
                    $mailer->send($email);
                    $messages[] = ('E-Mail gesendet an ' . implode(', ', array_map(fn (Address $a) => $a->getAddress(), $recipients)));
                } catch (\Throwable $ex) {
                    $errors++;
                    $this->logException($ex);
                    $this->addFlash('error', 'E-Mail konnte nicht gesendet werden: ' . $ex->getMessage());
                }
            }

            // 3. Ablage
            if ($doc !== null && $filename !== null && !empty($values['store'])) {
                try {
                    $where = $this->storage->store($profile, $date, $filename, $doc['content'], $doc['contentType']);
                    $messages[] = 'Gespeichert: ' . $where;
                } catch (\Throwable $ex) {
                    $errors++;
                    $this->logException($ex);
                    $this->addFlash('error', 'Ablage fehlgeschlagen: ' . $ex->getMessage());
                }
            }

            // 4. Download (wird auf der Ergebnisseite automatisch gestartet)
            if (!empty($values['download'])) {
                $downloadUrl = $this->generateUrl('wtl_month_pdf', ['user' => $profile->getId(), 'year' => $year, 'month' => $month, 'download' => 1]);
            }

            if (\count($messages) > 0) {
                $this->addFlash('info', implode(' · ', $messages));
            }

            if ($errors === 0 && $downloadUrl === null) {
                return $this->redirect($this->contractUrl($profile, $date));
            }
            $done = true;
        }

        $page = new PageSetup('wtl.close.title');
        $page->setHelp('contract.html');

        return $this->render('@WorkTimeLite/month_close.html.twig', [
            'page_setup' => $page,
            'form' => $form->createView(),
            'profile' => $profile,
            'month' => $monthModel,
            'monthDate' => $date,
            'monthName' => MonthDocument::monthName($month),
            'now' => $now,
            'isLocked' => $isLocked,
            'canLock' => $canLock,
            'done' => $done,
            'downloadUrl' => $downloadUrl,
            'contractUrl' => $this->contractUrl($profile, $date),
            'previewUrl' => $this->generateUrl('wtl_month_pdf', ['user' => $profile->getId(), 'year' => $year, 'month' => $month]),
            'storageTarget' => $this->storage->isEnabled() ? $this->storage->describe($profile, $date) : null,
            'filename' => $this->document->getFilename($profile, $date, 'pdf'),
            'templateTitle' => $this->document->getAvailableTemplates()[$this->document->getTemplateId()] ?? $this->document->getTemplateId(),
        ]);
    }

    /**
     * @return array<Address>
     */
    private function parseAddresses(string $list): array
    {
        $result = [];
        foreach (preg_split('/[,;\s]+/', $list) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (filter_var($entry, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Ungültige E-Mail-Adresse: ' . $entry);
            }
            $result[] = new Address($entry);
        }

        return $result;
    }
}
