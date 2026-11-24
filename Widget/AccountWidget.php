<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Widget;

use App\Widget\Type\AbstractWidget;
use App\Widget\WidgetInterface;
use KimaiPlugin\WorkTimeLiteBundle\Service\AccountSummary;
use KimaiPlugin\WorkTimeLiteBundle\Service\Access;

/**
 * Dashboard-Widget "Arbeitszeitkonto & Urlaub" für den angemeldeten Benutzer.
 */
final class AccountWidget extends AbstractWidget
{
    public function __construct(
        private readonly AccountSummary $summary,
        private readonly Access $access,
    )
    {
    }

    public function getId(): string
    {
        return 'WorkTimeLiteAccount';
    }

    public function getTitle(): string
    {
        return 'wtl.overview.title';
    }

    public function getTranslationDomain(): string
    {
        return 'messages';
    }

    public function getWidth(): int
    {
        return WidgetInterface::WIDTH_FULL;
    }

    public function getHeight(): int
    {
        return WidgetInterface::HEIGHT_SMALL;
    }

    public function getOptions(array $options = []): array
    {
        return array_merge(['id' => 'WorkTimeLiteAccount'], parent::getOptions($options));
    }

    public function getData(array $options = []): mixed
    {
        $user = $this->getUser();
        $year = (int) (new \DateTimeImmutable('now', $this->getTimezone()))->format('Y');

        return $this->summary->build($user, $year) + [
            'canManageAbsences' => $this->access->canManageAbsences($user),
            'canEditContract' => false,
        ];
    }

    public function getTemplateName(): string
    {
        return '@WorkTimeLite/widget_account.html.twig';
    }
}
