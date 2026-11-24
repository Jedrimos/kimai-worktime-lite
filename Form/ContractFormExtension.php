<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Form;

use App\Form\Type\DatePickerType;
use App\Form\Type\DurationType;
use App\Form\UserContractType;
use KimaiPlugin\WorkTimeLiteBundle\Service\UserWorkSettings;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * Erweitert Profil → Arbeitsvertrag um Arbeitsbeginn, Urlaub, Feiertage, Abwesenheitswert und Startsaldo.
 */
final class ContractFormExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [UserContractType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workStartingDay', DatePickerType::class, [
                'label' => 'wtl.work_start_day',
                'translation_domain' => 'messages',
                'required' => false,
                'help' => 'wtl.work_start_day.help',
                'input' => 'datetime_immutable',
            ])
            ->add('lastWorkingDay', DatePickerType::class, [
                'label' => 'wtl.work_last_day',
                'translation_domain' => 'messages',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('holidaysPerYear', NumberType::class, [
                'label' => 'wtl.holidays_per_year',
                'translation_domain' => 'messages',
                'required' => false,
                'scale' => 1,
                'constraints' => [new GreaterThanOrEqual(0)],
            ])
            ->add(UserWorkSettings::PREF_ABSENCE_MODE, ChoiceType::class, [
                'label' => 'wtl.absence_mode',
                'translation_domain' => 'messages',
                'required' => true,
                'choices' => [
                    'wtl.absence_mode.auto' => 'auto',
                    'wtl.absence_mode.fixed' => 'fixed',
                ],
                'help' => 'wtl.absence_mode.help',
            ])
            ->add(UserWorkSettings::PREF_ABSENCE_VALUE, DurationType::class, [
                'label' => 'wtl.absence_value',
                'translation_domain' => 'messages',
                'required' => false,
                'help' => 'wtl.absence_value.help',
            ])
            ->add(UserWorkSettings::PREF_HOLIDAY_REGION, ChoiceType::class, [
                'label' => 'wtl.holiday_region',
                'translation_domain' => 'messages',
                'required' => false,
                'placeholder' => 'wtl.holiday_region.none',
                'choices' => UserWorkSettings::REGIONS,
                'choice_translation_domain' => false,
            ])
            ->add(UserWorkSettings::PREF_HOLIDAY_ASSUMPTION, CheckboxType::class, [
                'label' => 'wtl.holiday_assumption',
                'translation_domain' => 'messages',
                'required' => false,
                'help' => 'wtl.holiday_assumption.help',
            ])
            ->add(UserWorkSettings::PREF_BALANCE_START, DurationType::class, [
                'label' => 'wtl.balance_start',
                'translation_domain' => 'messages',
                'required' => false,
                'allow_negative' => true,
                'max_hours' => 10000,
                'help' => 'wtl.balance_start.help',
            ])
        ;

        // Werte aus den Benutzer-Einstellungen kommen als String zurück
        $toInt = new CallbackTransformer(
            fn ($value) => ($value === null || $value === '') ? null : (int) $value,
            fn ($value) => $value === null ? null : (int) $value
        );
        $builder->get(UserWorkSettings::PREF_ABSENCE_VALUE)->addModelTransformer($toInt, true);
        $builder->get(UserWorkSettings::PREF_BALANCE_START)->addModelTransformer($toInt, true);

        $builder->get(UserWorkSettings::PREF_HOLIDAY_ASSUMPTION)->addModelTransformer(new CallbackTransformer(
            fn ($value) => (bool) $value,
            fn ($value) => $value ? 1 : 0
        ));

        $builder->get(UserWorkSettings::PREF_ABSENCE_MODE)->addModelTransformer(new CallbackTransformer(
            fn ($value) => $value === 'fixed' ? 'fixed' : 'auto',
            fn ($value) => $value === 'fixed' ? 'fixed' : 'auto'
        ));

        $builder->get('holidaysPerYear')->addModelTransformer(new CallbackTransformer(
            fn ($value) => is_numeric($value) ? (float) $value : null,
            fn ($value) => $value === null ? 0.0 : (float) $value
        ));
    }
}
