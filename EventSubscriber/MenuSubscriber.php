<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Menüpunkte "Meine Woche" und "Abwesenheiten" unter Zeiterfassung.
 */
final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMainMenuEvent::class => ['onMenu', 50],
        ];
    }

    public function onMenu(ConfigureMainMenuEvent $event): void
    {
        if ($this->security->getUser() === null) {
            return;
        }

        $menu = $event->getTimesheetMenu();
        if ($menu === null) {
            return;
        }

        $menu->addChild(new MenuItemModel('wtl_week', 'wtl.week.title', 'wtl_week', [], 'fas fa-id-card'));
        if ($this->security->isGranted('wtl_absence_own')) {
            $menu->addChild(new MenuItemModel('wtl_absence', 'wtl.absence.title', 'wtl_absence', [], 'fas fa-umbrella-beach'));
        }
    }
}
