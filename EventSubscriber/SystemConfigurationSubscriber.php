<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Entity\Activity;
use App\Entity\Project;
use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use KimaiPlugin\WorkTimeLiteBundle\Service\MonthDocument;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * System → Einstellungen → WorkTime Lite
 */
final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ActivityRepository $activityRepository,
        private readonly MonthDocument $document,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigurationEvent::class => ['onSystemConfiguration', 100],
        ];
    }

    private function cfg(string $key): Configuration
    {
        return (new Configuration('work_time_lite.' . $key))
            ->setLabel('wtl.cfg.' . $key)
            ->setTranslationDomain('messages')
            ->setRequired(false);
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $projects = [];
        /** @var Project $project */
        foreach ($this->projectRepository->findAll() as $project) {
            $customer = $project->getCustomer()?->getName() ?? '';
            $projects[$customer . ' › ' . $project->getName()] = (string) $project->getId();
        }
        ksort($projects, SORT_NATURAL | SORT_FLAG_CASE);

        $activities = [];
        /** @var Activity $activity */
        foreach ($this->activityRepository->findAll() as $activity) {
            $prefix = $activity->getProject() !== null ? $activity->getProject()->getName() . ' › ' : '';
            $activities[$prefix . $activity->getName()] = (string) $activity->getId();
        }
        ksort($activities, SORT_NATURAL | SORT_FLAG_CASE);

        $templates = array_flip($this->document->getAvailableTemplates());

        $config = [
            // Eingabe
            $this->cfg('input_time_normalize')->setType(CheckboxType::class),
            $this->cfg('input_duration_dot_minutes')->setType(CheckboxType::class),
        ];

        // Schnell anlegen + eigene Felder
        $config[] = $this->cfg('quick_create')->setType(CheckboxType::class);
        $config[] = $this->cfg('quick_create_global_activity')->setType(CheckboxType::class);
        $config[] = $this->cfg('auto_team')->setType(CheckboxType::class);
        $config[] = $this->cfg('day_buttons')->setType(CheckboxType::class);
        $config[] = $this->cfg('mobile_nav')->setType(CheckboxType::class);
        $config[] = $this->cfg('site_today')->setType(CheckboxType::class);
        $config[] = $this->cfg('site_todos')->setType(CheckboxType::class);
        $config[] = $this->cfg('site_photos')->setType(CheckboxType::class);
        $config[] = $this->cfg('custom_fields')->setType(TextareaType::class)->setOptions([
            'attr' => ['rows' => 6, 'placeholder' => "Regie | ja/nein\nBaustelle | text\nFahrzeug | auswahl: Crafter, Caddy\nTätigkeitsbericht | langtext"],
            'help' => 'wtl.cfg.custom_fields.help',
        ]);

        $config[] = $this->cfg('zoll_employer')->setType(TextareaType::class)->setOptions(['attr' => ['rows' => 3, 'placeholder' => "Firma GmbH\nStraße 1, 12345 Ort"]]);

        // Abwesenheiten
        foreach (['vacation', 'sick', 'overtime', 'other'] as $type) {
            $config[] = $this->cfg('absence_' . $type . '_project')->setType(ChoiceType::class)->setOptions(['choices' => $projects, 'placeholder' => '–', 'choice_translation_domain' => false]);
            $config[] = $this->cfg('absence_' . $type . '_activity')->setType(ChoiceType::class)->setOptions(['choices' => $activities, 'placeholder' => '–', 'choice_translation_domain' => false]);
        }
        $config[] = $this->cfg('absence_start_time')->setType(TextType::class);
        $config[] = $this->cfg('absence_duration_source')->setType(ChoiceType::class)->setOptions(['choices' => [
            'wtl.cfg.absence_duration_source.value' => 'value',
            'wtl.cfg.absence_duration_source.soll' => 'soll',
        ]]);
        $config[] = $this->cfg('absence_account_uses_value')->setType(CheckboxType::class);
        $config[] = $this->cfg('holiday_mode')->setType(ChoiceType::class)->setOptions(['choices' => [
            'wtl.cfg.holiday_mode.zero' => 'zero',
            'wtl.cfg.holiday_mode.credit' => 'credit',
        ]]);

        // Monatsabschluss
        $config[] = $this->cfg('close_template')->setType(ChoiceType::class)->setOptions(['choices' => $templates, 'placeholder' => 'PDF (Standard)', 'choice_translation_domain' => false]);
        $config[] = $this->cfg('close_filename')->setType(TextType::class);
        $config[] = $this->cfg('close_lock')->setType(CheckboxType::class);
        $config[] = $this->cfg('close_mail')->setType(CheckboxType::class);
        $config[] = $this->cfg('close_download')->setType(CheckboxType::class);
        $config[] = $this->cfg('smtp_host')->setType(TextType::class)->setOptions(['attr' => ['placeholder' => 'smtp.mail.yahoo.com']]);
        $config[] = $this->cfg('smtp_port')->setType(TextType::class)->setOptions(['attr' => ['placeholder' => '465']]);
        $config[] = $this->cfg('smtp_user')->setType(TextType::class);
        $config[] = $this->cfg('smtp_password')->setType(PasswordType::class)->setOptions(['always_empty' => false]);
        $config[] = $this->cfg('smtp_from')->setType(TextType::class);
        $config[] = $this->cfg('mail_to')->setType(TextType::class);
        $config[] = $this->cfg('mail_cc')->setType(TextType::class);
        $config[] = $this->cfg('mail_subject')->setType(TextType::class);
        $config[] = $this->cfg('mail_body')->setType(TextareaType::class)->setOptions(['attr' => ['rows' => 6]]);

        // Ablage
        $config[] = $this->cfg('storage_type')->setType(ChoiceType::class)->setOptions(['choices' => [
            'wtl.cfg.storage_type.none' => 'none',
            'wtl.cfg.storage_type.folder' => 'folder',
            'wtl.cfg.storage_type.webdav' => 'webdav',
        ]]);
        $config[] = $this->cfg('storage_folder')->setType(TextType::class);
        $config[] = $this->cfg('storage_server_path')->setType(TextType::class);
        $config[] = $this->cfg('storage_webdav_url')->setType(TextType::class);
        $config[] = $this->cfg('storage_webdav_user')->setType(TextType::class);
        $config[] = $this->cfg('storage_webdav_password')->setType(PasswordType::class)->setOptions(['always_empty' => false]);

        $event->addConfiguration(
            (new SystemConfiguration('work_time_lite'))
                ->setTranslation('wtl.settings')
                ->setTranslationDomain('messages')
                ->setConfiguration($config)
        );
    }
}
