<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Form;

use App\Form\Type\DurationType;
use App\Form\Type\TimePickerType;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use KimaiPlugin\WorkTimeLiteBundle\Service\TimeInputNormalizer;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Server-seitige Absicherung der bequemen Zeiteingabe (falls JavaScript nicht greift, z.B. API/Apps).
 */
final class TimeInputExtension extends AbstractTypeExtension
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        return [TimePickerType::class, DurationType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isDuration = $builder->getType()->getInnerType() instanceof DurationType;
        $settings = $this->settings;

        // 12-Stunden-Formate (AM/PM) nicht anfassen
        if (!$isDuration && isset($options['format']) && \is_string($options['format']) && preg_match('/[aA]/', $options['format']) === 1) {
            return;
        }

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($isDuration, $settings): void {
            $value = $event->getData();
            if (!\is_string($value) || $value === '') {
                return;
            }

            if ($isDuration) {
                if (!$settings->bool('input_duration_dot_minutes', false)) {
                    return;
                }
                $normalized = TimeInputNormalizer::normalizeDurationDotMinutes($value);
            } else {
                if (!$settings->bool('input_time_normalize', true)) {
                    return;
                }
                $normalized = TimeInputNormalizer::normalizeTime($value);
            }

            if ($normalized !== null) {
                $event->setData($normalized);
            }
        });
    }
}
