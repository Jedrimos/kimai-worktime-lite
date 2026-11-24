<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\CustomFields;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Eigene Felder an Zeiteinträgen: im Formular, in den Listen und als Spalten in Exportvorlagen.
 */
final class CustomFieldsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CustomFields $customFields)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetMetaDefinitionEvent::class => ['onDefinition', 150],
            TimesheetMetaDisplayEvent::class => ['onDisplay', 150],
        ];
    }

    public function onDefinition(TimesheetMetaDefinitionEvent $event): void
    {
        $timesheet = $event->getEntity();
        foreach ($this->customFields->createMetaFields() as $meta) {
            $timesheet->setMetaField($meta);
        }
    }

    public function onDisplay(TimesheetMetaDisplayEvent $event): void
    {
        foreach ($this->customFields->createMetaFields() as $meta) {
            $event->addField($meta);
        }
    }
}
