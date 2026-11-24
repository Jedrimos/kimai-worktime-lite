<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Entity\User;
use App\Event\PageActionsEvent;
use App\WorkingTime\Model\Month;
use KimaiPlugin\WorkTimeLiteBundle\Service\Access;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Menüpunkte im ⋯-Menü (und Rechtsklick) der Monatszeilen sowie oben in der Arbeitszeiten-Ansicht.
 */
final class ContractActionsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Access $access,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'actions.contract_month' => ['onMonth', 100],
            'actions.contract_links' => ['onLinks', 100],
        ];
    }

    public function onMonth(PageActionsEvent $event): void
    {
        $payload = $event->getPayload();
        $month = $payload['month'] ?? null;
        if (!$month instanceof Month) {
            return;
        }

        $profile = $month->getUser();
        if ($profile->getId() === null || !$this->access->canClose($profile)) {
            return;
        }

        $params = [
            'user' => $profile->getId(),
            'year' => (int) $month->getMonth()->format('Y'),
            'month' => (int) $month->getMonth()->format('n'),
        ];

        $tz = new \DateTimeZone($profile->getTimezone());
        $endOfMonth = (new \DateTimeImmutable($month->getMonth()->format('Y-m-01'), $tz))->modify('last day of this month')->setTime(23, 59, 59);
        $isPast = $endOfMonth < new \DateTimeImmutable('now', $tz);

        if (!$month->isLocked()) {
            if ($isPast) {
                $event->addAction('wtl_close', [
                    'title' => 'wtl.action.close',
                    'url' => $this->urlGenerator->generate('wtl_month_close', $params),
                ]);
            }
            $event->addAction('wtl_send', [
                'title' => 'wtl.action.send',
                'url' => $this->urlGenerator->generate('wtl_month_close', $params + ['nolock' => 1]),
            ]);
        } else {
            $event->addAction('wtl_send', [
                'title' => 'wtl.action.send_again',
                'url' => $this->urlGenerator->generate('wtl_month_close', $params + ['nolock' => 1]),
            ]);
        }

        $event->addAction('wtl_pdf', [
            'title' => 'wtl.action.pdf',
            'url' => $this->urlGenerator->generate('wtl_month_pdf', $params),
            'target' => '_blank',
        ]);

        if ($month->isLocked() && $this->access->canUnlock($profile)) {
            $event->addDivider();
            $event->addAction('wtl_unlock', [
                'title' => 'wtl.action.unlock',
                'url' => $this->urlGenerator->generate('wtl_month_unlock', $params + [
                    'token' => $this->csrfTokenManager->getToken('wtl_unlock')->getValue(),
                ]),
                'class' => 'confirmation-link text-danger',
                'attr' => [
                    'data-question' => 'Monat wirklich entsperren? Spätere gesperrte Monate dieses Jahres werden ebenfalls entsperrt.',
                ],
            ]);
        }
    }

    public function onLinks(PageActionsEvent $event): void
    {
        $payload = $event->getPayload();
        $profile = $payload['user'] ?? null;
        if (!$profile instanceof User || $profile->getId() === null) {
            return;
        }

        if ($this->access->canManageAbsences($profile)) {
            $event->addAction('wtl_absence', [
                'title' => 'wtl.action.absence',
                'url' => $this->urlGenerator->generate('wtl_absence', ['user' => $profile->getId()]),
                'icon' => 'create',
                'combined' => true,
            ]);
        }
    }
}
