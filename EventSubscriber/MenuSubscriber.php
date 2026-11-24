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

        // eigener Hauptmenüpunkt "Baustelle" direkt unter Zeiterfassung
        $root = $event->getMenu();
        $site = new MenuItemModel('wtl_site_menu', 'Baustelle', null, [], 'fas fa-hard-hat');
        $site->addChild(new MenuItemModel('wtl_site', 'Baustellen', 'wtl_site', [], 'fas fa-hard-hat'));
        $site->addChild(new MenuItemModel('wtl_site_orders', 'Bestellliste', 'wtl_site_orders', [], 'fas fa-truck'));
        $site->setChildRoutes(['wtl_site', 'wtl_site_orders', 'wtl_site_project', 'wtl_site_sheet', 'wtl_site_sheet_new']);
        $root->addChild($site);
        $children = [];
        $inserted = false;
        foreach ($root->getChildren() as $child) {
            if ($child === $site) {
                continue;
            }
            $children[] = $child;
            if ($child->getIdentifier() === 'times') {
                $children[] = $site;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $children[] = $site;
        }
        $root->setChildren($children);

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
