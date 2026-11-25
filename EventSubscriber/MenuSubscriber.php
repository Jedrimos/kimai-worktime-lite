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
    public function __construct(
        private readonly Security $security,
        private readonly \KimaiPlugin\WorkTimeLiteBundle\Service\Settings $settings,
    ) {
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
        if ($this->settings->bool('site_today', true)) {
            $site->addChild(new MenuItemModel('wtl_today', 'Heute', 'wtl_today', [], 'fas fa-sun'));
        }
        $site->addChild(new MenuItemModel('wtl_site', 'Baustellen', 'wtl_site', [], 'fas fa-hard-hat'));
        if ($this->settings->bool('site_todos', true)) {
            $site->addChild(new MenuItemModel('wtl_site_todos', 'Noch zu machen', 'wtl_site_todos', [], 'fas fa-tasks'));
        }
        $site->addChild(new MenuItemModel('wtl_site_orders', 'Bestellliste', 'wtl_site_orders', [], 'fas fa-truck'));
        if ($this->settings->bool('site_stock', true)) {
            $site->addChild(new MenuItemModel('wtl_stock', 'Bus-Lager', 'wtl_stock', [], 'fas fa-boxes'));
        }
        if ($this->settings->bool('site_tools', true)) {
            $site->addChild(new MenuItemModel('wtl_tools', 'Werkzeug', 'wtl_tools', [], 'fas fa-tools'));
        }
        if ($this->settings->bool('site_report', true)) {
            $site->addChild(new MenuItemModel('wtl_report', 'Baustellenberichte', 'wtl_report', [], 'fas fa-file-signature'));
        }
        if ($this->settings->string('companions') !== '') {
            $site->addChild(new MenuItemModel('wtl_people', 'Mit wem', 'wtl_people', [], 'fas fa-user-friends'));
        }
        if ($this->settings->bool('site_tax', true)) {
            $site->addChild(new MenuItemModel('wtl_tax', 'Steuer-Hilfe', 'wtl_tax', [], 'fas fa-receipt'));
        }
        $site->setChildRoutes(['wtl_report', 'wtl_report_day', 'wtl_report_setup', 'wtl_people', 'wtl_person', 'wtl_today', 'wtl_today_feierabend', 'wtl_tools', 'wtl_stock', 'wtl_tax', 'wtl_site_circuits', 'wtl_site_todos', 'wtl_site_photos', 'wtl_site', 'wtl_site_orders', 'wtl_site_project', 'wtl_site_sheet', 'wtl_site_sheet_new']);
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
