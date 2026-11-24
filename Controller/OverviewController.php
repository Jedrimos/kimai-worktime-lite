<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Controller;

use App\Controller\AbstractController;
use App\WorkingTime\Model\Year;
use KimaiPlugin\WorkTimeLiteBundle\Service\AccountSummary;
use KimaiPlugin\WorkTimeLiteBundle\Service\Access;
use Symfony\Component\HttpFoundation\Response;

/**
 * Box "Arbeitszeitkonto & Urlaub" unter Arbeitsvertrag → Arbeitszeiten.
 * Wird über WorkContractDetailControllerEvent eingebunden (keine eigene Route).
 */
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly AccountSummary $summary,
        private readonly Access $access,
    )
    {
    }

    public function box(Year $year): Response
    {
        $profile = $year->getUser();
        $data = $this->summary->build($profile, (int) $year->getYear()->format('Y'), $year);

        return $this->render('@WorkTimeLite/overview_box.html.twig', $data + [
            'canManageAbsences' => $this->access->canManageAbsences($profile),
            'canEditContract' => $this->isGranted('contract', $profile),
        ]);
    }
}
