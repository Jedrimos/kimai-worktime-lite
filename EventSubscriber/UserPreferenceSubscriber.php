<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Entity\UserPreference;
use App\Event\UserPreferenceEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Profil → Einstellungen: Arbeitgeber für "Meine Woche" pro Benutzer
 * (z.B. Domi = Arbeitgeber, Nebenbei = eigenes Gewerbe).
 */
final class UserPreferenceSubscriber implements EventSubscriberInterface
{
    public const EMPLOYER = 'wtl_employer';
    public const BUSINESS = 'wtl_business';

    public static function getSubscribedEvents(): array
    {
        return [
            UserPreferenceEvent::class => ['onPreferences', 150],
        ];
    }

    public function onPreferences(UserPreferenceEvent $event): void
    {
        $event->addPreference(
            (new UserPreference(self::EMPLOYER, ''))
                ->setOrder(950)
                ->setType(TextareaType::class)
                ->setEnabled(true)
                ->setOptions([
                    'label' => 'wtl.pref.employer',
                    'help' => 'wtl.pref.employer.help',
                    'required' => false,
                    'translation_domain' => 'messages',
                    'attr' => ['rows' => 3],
                ])
                ->addConstraint(new Length(max: 500))
                ->setSection('worktime_lite')
        );
        $event->addPreference(
            (new UserPreference(self::BUSINESS, false))
                ->setOrder(951)
                ->setType(CheckboxType::class)
                ->setEnabled(true)
                ->setOptions([
                    'label' => 'wtl.pref.business',
                    'help' => 'wtl.pref.business.help',
                    'required' => false,
                    'translation_domain' => 'messages',
                ])
                ->setSection('worktime_lite')
        );
    }
}
