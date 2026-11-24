<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\TimesheetMeta;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Eigene Felder für Zeiteinträge, definiert in den Einstellungen – eine Zeile pro Feld:
 *
 *   Regie | ja/nein
 *   Baustelle | text
 *   Fahrzeug | auswahl: Crafter, Caddy, Privat
 *   Tätigkeitsbericht | langtext
 *   Auftragsnummer | text | pflicht
 */
final class CustomFields
{
    public const PREFIX = 'wtl_';

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array<int, array{name: string, label: string, type: string, choices: array<string>, required: bool}>
     */
    public function getDefinitions(): array
    {
        $raw = $this->settings->string('custom_fields');
        if ($raw === '') {
            return [];
        }

        $result = [];
        $seen = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0];
            if ($label === '') {
                continue;
            }
            $typeRaw = mb_strtolower($parts[1] ?? 'text');
            $required = false;
            foreach (\array_slice($parts, 2) as $flag) {
                if (\in_array(mb_strtolower($flag), ['pflicht', 'required', '!'], true)) {
                    $required = true;
                }
            }

            $choices = [];
            if (preg_match('/^(ja\/nein|ja-nein|janein|bool|yes\/no)$/u', $typeRaw) === 1) {
                $type = 'yesno';
                $choices = ['Ja', 'Nein'];
            } elseif (preg_match('/^(auswahl|liste|choice)\s*:\s*(.+)$/u', $parts[1] ?? '', $m) === 1 || preg_match('/^(Auswahl|Liste|Choice)\s*:\s*(.+)$/u', $parts[1] ?? '', $m) === 1) {
                $type = 'choice';
                $choices = array_values(array_filter(array_map('trim', explode(',', $m[2])), fn ($c) => $c !== ''));
            } elseif (\in_array($typeRaw, ['langtext', 'textarea', 'bericht', 'mehrzeilig'], true)) {
                $type = 'textarea';
            } else {
                $type = 'text';
            }

            $name = self::PREFIX . $this->slug($label);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $result[] = ['name' => $name, 'label' => $label, 'type' => $type, 'choices' => $choices, 'required' => $required];
        }

        return $result;
    }

    private function slug(string $label): string
    {
        $slug = strtr(mb_strtolower($label), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return substr($slug !== '' ? $slug : 'feld', 0, 40);
    }

    /**
     * @return array<TimesheetMeta>
     */
    public function createMetaFields(): array
    {
        $fields = [];
        $order = 100;
        foreach ($this->getDefinitions() as $def) {
            $meta = new TimesheetMeta();
            $meta->setName($def['name']);
            $meta->setLabel($def['label']);
            $meta->setIsVisible(true);
            $meta->setIsRequired($def['required']);
            $meta->setOrder($order++);

            $options = ['label' => $def['label'], 'required' => $def['required'], 'translation_domain' => false];

            switch ($def['type']) {
                case 'yesno':
                    $meta->setType(ChoiceType::class);
                    $options += [
                        'choices' => ['Ja' => 'Ja', 'Nein' => 'Nein'],
                        'expanded' => true,
                        'placeholder' => false,
                        'choice_translation_domain' => false,
                    ];
                    $meta->setValue('Nein');
                    break;
                case 'choice':
                    $meta->setType(ChoiceType::class);
                    $options += [
                        'choices' => array_combine($def['choices'], $def['choices']),
                        'placeholder' => $def['required'] ? null : '–',
                        'choice_translation_domain' => false,
                    ];
                    break;
                case 'textarea':
                    $meta->setType(TextareaType::class);
                    $options += ['attr' => ['rows' => 4]];
                    $meta->addConstraint(new Length(max: 65000));
                    break;
                default:
                    $meta->setType(TextType::class);
                    $meta->addConstraint(new Length(max: 250));
            }

            if ($def['required']) {
                $meta->addConstraint(new NotBlank());
            }

            $meta->setOptions($options);
            $fields[] = $meta;
        }

        return $fields;
    }
}
