<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\WorkContractDetailControllerEvent;
use KimaiPlugin\WorkTimeLiteBundle\Controller\OverviewController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ContractBoxSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WorkContractDetailControllerEvent::class => ['onDetail', 100],
        ];
    }

    public function onDetail(WorkContractDetailControllerEvent $event): void
    {
        $event->addController(OverviewController::class . '::box');
    }
}
