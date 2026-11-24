<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use App\Repository\TimesheetRepository;

/**
 * Findet Abwesenheiten = Zeiteinträge auf den in den Einstellungen gewählten Projekt/Tätigkeit-Kombinationen.
 */
final class AbsenceRepository
{
    public function __construct(
        private readonly TimesheetRepository $timesheetRepository,
        private readonly Settings $settings,
    )
    {
    }

    /**
     * @return array<string, array<int, array{type: string, duration: int, id: int}>> Y-m-d => Liste
     */
    public function findByDay(User $user, \DateTimeInterface $begin, \DateTimeInterface $end): array
    {
        $types = $this->settings->getConfiguredAbsenceTypes();
        if (\count($types) === 0 || $user->getId() === null) {
            return [];
        }

        $qb = $this->timesheetRepository->createQueryBuilder('t');
        $qb
            ->select('t.id AS id, t.duration AS duration, DATE(t.date) AS day, IDENTITY(t.project) AS project, IDENTITY(t.activity) AS activity')
            ->where($qb->expr()->isNotNull('t.end'))
            ->andWhere($qb->expr()->eq('t.user', ':user'))
            ->andWhere($qb->expr()->between('t.date', ':begin', ':end'))
            ->setParameter('user', $user->getId())
            ->setParameter('begin', $begin->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('t.date', 'ASC')
        ;

        $or = $qb->expr()->orX();
        $i = 0;
        foreach ($types as $type => $ids) {
            $or->add($qb->expr()->andX(
                $qb->expr()->eq('t.project', ':p' . $i),
                $qb->expr()->eq('t.activity', ':a' . $i)
            ));
            $qb->setParameter('p' . $i, $ids['project']);
            $qb->setParameter('a' . $i, $ids['activity']);
            $i++;
        }
        $qb->andWhere($or);

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $type = $this->resolveType($types, (int) $row['project'], (int) $row['activity']);
            if ($type === null) {
                continue;
            }
            $day = $row['day'] instanceof \DateTimeInterface ? $row['day']->format('Y-m-d') : substr((string) $row['day'], 0, 10);
            $result[$day][] = ['type' => $type, 'duration' => (int) $row['duration'], 'id' => (int) $row['id']];
        }

        return $result;
    }

    /**
     * @param array<string, array{project: int, activity: int}> $types
     */
    private function resolveType(array $types, int $project, int $activity): ?string
    {
        foreach ($types as $type => $ids) {
            if ($ids['project'] === $project && $ids['activity'] === $activity) {
                return $type;
            }
        }

        return null;
    }
}
