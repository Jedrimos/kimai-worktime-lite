<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Entity\User;
use App\Event\ActivityCreatePreEvent;
use App\Event\CustomerCreatePreEvent;
use App\Event\ProjectCreatePreEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Neue Kunden, Projekte und Tätigkeiten automatisch den Teams des Erstellers zuweisen.
 * So sieht z.B. der Benutzer "Domi" (Team Abicht) nur Abicht-Kunden und "Nebenbei" (Team Jedrimos) nur seine eigenen –
 * auch wenn beide selbst neue anlegen.
 */
final class AutoTeamSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly Settings $settings,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerCreatePreEvent::class => ['onCustomer', 50],
            ProjectCreatePreEvent::class => ['onProject', 50],
            ActivityCreatePreEvent::class => ['onActivity', 50],
        ];
    }

    /**
     * @return array<\App\Entity\Team>
     */
    private function teams(): array
    {
        if (!$this->settings->bool('auto_team', true)) {
            return [];
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $user->getTeams();
    }

    public function onCustomer(CustomerCreatePreEvent $event): void
    {
        $customer = $event->getCustomer();
        if ($customer->getTeams()->count() > 0) {
            return;
        }
        foreach ($this->teams() as $team) {
            $customer->addTeam($team);
        }
    }

    public function onProject(ProjectCreatePreEvent $event): void
    {
        $project = $event->getProject();
        if ($project->getTeams()->count() > 0) {
            return;
        }
        foreach ($this->teams() as $team) {
            $project->addTeam($team);
        }
    }

    public function onActivity(ActivityCreatePreEvent $event): void
    {
        $activity = $event->getActivity();
        if ($activity->getTeams()->count() > 0) {
            return;
        }
        foreach ($this->teams() as $team) {
            $activity->addTeam($team);
        }
    }
}
