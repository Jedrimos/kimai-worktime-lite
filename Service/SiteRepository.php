<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Baustellen-Zettel: Aufmaß, Material und Notizen pro Projekt.
 * Eigene, einfache Tabelle (wird beim ersten Aufruf automatisch angelegt).
 */
final class SiteRepository
{
    public const TABLE = 'kimai2_wtl_site_items';
    public const SHEETS = 'kimai2_wtl_site_sheets';
    public const TODOS = 'kimai2_wtl_site_todos';
    public const PHOTOS = 'kimai2_wtl_site_photos';
    public const KINDS = ['aufmass', 'material', 'notiz'];
    public const STATUS = ['offen', 'bestellt', 'geliefert'];

    private bool $ready = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    private function ensureTable(): void
    {
        if ($this->ready) {
            return;
        }
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            item_date DATE NOT NULL,
            kind VARCHAR(20) NOT NULL,
            quantity DECIMAL(12,3) DEFAULT NULL,
            unit VARCHAR(20) DEFAULT NULL,
            text VARCHAR(500) NOT NULL,
            room VARCHAR(100) DEFAULT NULL,
            supplier VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX wtl_site_user_project (user_id, project_id),
            INDEX wtl_site_status (user_id, kind, status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::SHEETS . ' (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            sheet_date DATE NOT NULL,
            title VARCHAR(150) DEFAULT NULL,
            supplier VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX wtl_sheet_user_project (user_id, project_id, kind),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::TODOS . ' (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            text VARCHAR(500) NOT NULL,
            done_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX wtl_todo_user (user_id, done_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::PHOTOS . ' (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            project_id INT NOT NULL,
            filename VARCHAR(100) NOT NULL,
            comment VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX wtl_photo_user_project (user_id, project_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $cols = $this->connection->fetchFirstColumn('SHOW COLUMNS FROM ' . self::TABLE . " LIKE 'sheet_id'");
        if (\count($cols) === 0) {
            $this->connection->executeStatement('ALTER TABLE ' . self::TABLE . ' ADD sheet_id INT DEFAULT NULL, ADD INDEX wtl_site_sheet (sheet_id)');
        }
        // alte Einträge ohne Zettel: pro Tag einen Zettel anlegen
        $orphans = $this->connection->fetchAllAssociative('SELECT user_id, project_id, kind, item_date, MAX(supplier) supplier FROM ' . self::TABLE . ' WHERE sheet_id IS NULL GROUP BY user_id, project_id, kind, item_date');
        foreach ($orphans as $o) {
            $sid = $this->insertSheet((int) $o['user_id'], (int) $o['project_id'], (string) $o['kind'], (string) $o['item_date'], null, $o['supplier'] !== null ? (string) $o['supplier'] : null);
            $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET sheet_id = ? WHERE sheet_id IS NULL AND user_id = ? AND project_id = ? AND kind = ? AND item_date = ?', [$sid, $o['user_id'], $o['project_id'], $o['kind'], $o['item_date']]);
        }
        $this->ready = true;
    }

    public function createSheet(int $userId, int $projectId, string $kind, string $date, ?string $title, ?string $supplier): int
    {
        $this->ensureTable();

        return $this->insertSheet($userId, $projectId, $kind, $date, $title, $supplier);
    }

    private function insertSheet(int $userId, int $projectId, string $kind, string $date, ?string $title, ?string $supplier): int
    {
        $this->connection->insert(self::SHEETS, [
            'user_id' => $userId,
            'project_id' => $projectId,
            'kind' => $kind,
            'sheet_date' => $date,
            'title' => $title,
            'supplier' => $supplier,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSheet(int $userId, int $id): ?array
    {
        $this->ensureTable();
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::SHEETS . ' WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $row === false ? null : $row;
    }

    public function updateSheet(int $userId, int $id, string $date, ?string $title, ?string $supplier): void
    {
        $this->ensureTable();
        $this->connection->update(self::SHEETS, ['sheet_date' => $date, 'title' => $title, 'supplier' => $supplier], ['id' => $id, 'user_id' => $userId]);
        $this->connection->update(self::TABLE, ['item_date' => $date, 'supplier' => $supplier], ['sheet_id' => $id, 'user_id' => $userId]);
    }

    public function deleteSheet(int $userId, int $id): void
    {
        $this->ensureTable();
        $this->connection->delete(self::TABLE, ['sheet_id' => $id, 'user_id' => $userId]);
        $this->connection->delete(self::SHEETS, ['id' => $id, 'user_id' => $userId]);
    }

    /**
     * Zettel eines Projekts (neueste zuerst) mit Anzahl Positionen und offenen Materialpositionen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sheetsForProject(int $userId, int $projectId, ?string $kind = null): array
    {
        $this->ensureTable();
        $sql = 'SELECT s.*, COUNT(i.id) items, SUM(CASE WHEN i.status = \'offen\' THEN 1 ELSE 0 END) open_items FROM ' . self::SHEETS . ' s LEFT JOIN ' . self::TABLE . ' i ON i.sheet_id = s.id WHERE s.user_id = ? AND s.project_id = ?';
        $params = [$userId, $projectId];
        if ($kind !== null) {
            $sql .= ' AND s.kind = ?';
            $params[] = $kind;
        }

        return $this->connection->fetchAllAssociative($sql . ' GROUP BY s.id ORDER BY s.sheet_date DESC, s.id DESC', $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function itemsForSheet(int $userId, int $sheetId): array
    {
        $this->ensureTable();

        return $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? AND sheet_id = ? ORDER BY id', [$userId, $sheetId]);
    }

    /**
     * @param array{project_id: int, item_date: string, kind: string, quantity: ?float, unit: ?string, text: string, room: ?string, supplier: ?string} $data
     */
    public function add(int $userId, array $data): int
    {
        $this->ensureTable();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert(self::TABLE, [
            'user_id' => $userId,
            'project_id' => $data['project_id'],
            'item_date' => $data['item_date'],
            'kind' => $data['kind'],
            'quantity' => $data['quantity'],
            'unit' => $data['unit'],
            'text' => $data['text'],
            'room' => $data['room'],
            'supplier' => $data['supplier'],
            'status' => $data['kind'] === 'material' ? 'offen' : null,
            'sheet_id' => $data['sheet_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $id): ?array
    {
        $this->ensureTable();
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $row === false ? null : $row;
    }

    public function delete(int $userId, int $id): void
    {
        $this->ensureTable();
        $this->connection->delete(self::TABLE, ['id' => $id, 'user_id' => $userId]);
    }

    public function setStatus(int $userId, int $id, string $status): void
    {
        $this->ensureTable();
        if (!\in_array($status, self::STATUS, true)) {
            return;
        }
        $this->connection->update(self::TABLE, ['status' => $status, 'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $id, 'user_id' => $userId, 'kind' => 'material']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forProject(int $userId, int $projectId, ?string $kind = null): array
    {
        $this->ensureTable();
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? AND project_id = ?';
        $params = [$userId, $projectId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY item_date DESC, id ASC', $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openMaterial(int $userId, bool $includeDelivered = false): array
    {
        $this->ensureTable();
        $sql = 'SELECT * FROM ' . self::TABLE . " WHERE user_id = ? AND kind = 'material'";
        if (!$includeDelivered) {
            $sql .= " AND status <> 'geliefert'";
        }

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY supplier, project_id, item_date, id', [$userId]);
    }

    /**
     * Projekte mit Einträgen (zuletzt bearbeitet zuerst).
     *
     * @return array<int, array{project_id: int, items: int, last: string}>
     */
    public function projectsWithItems(int $userId): array
    {
        $this->ensureTable();
        $rows = $this->connection->fetchAllAssociative('SELECT s.project_id, COUNT(DISTINCT s.id) items, GREATEST(MAX(s.created_at), COALESCE(MAX(i.updated_at), MAX(s.created_at))) last FROM ' . self::SHEETS . ' s LEFT JOIN ' . self::TABLE . ' i ON i.sheet_id = s.id WHERE s.user_id = ? GROUP BY s.project_id ORDER BY last DESC', [$userId]);

        return array_map(fn ($r) => ['project_id' => (int) $r['project_id'], 'items' => (int) $r['items'], 'last' => (string) $r['last']], $rows);
    }

    /**
     * Frühere Texte für Vorschläge beim Tippen.
     *
     * @return array<int, string>
     */
    public function suggestions(int $userId, string $kind): array
    {
        $this->ensureTable();
        $rows = $this->connection->fetchFirstColumn('SELECT text FROM ' . self::TABLE . ' WHERE user_id = ? AND kind = ? GROUP BY text ORDER BY COUNT(*) DESC LIMIT 200', [$userId, $kind]);

        return array_map('strval', $rows);
    }

    /**
     * @return array<int, string>
     */
    public function suppliers(int $userId): array
    {
        $this->ensureTable();

        return array_map('strval', $this->connection->fetchFirstColumn('SELECT supplier FROM ' . self::TABLE . " WHERE user_id = ? AND supplier IS NOT NULL AND supplier <> '' GROUP BY supplier ORDER BY COUNT(*) DESC", [$userId]));
    }

    // ---------- Restarbeiten ----------

    public function addTodo(int $userId, int $projectId, string $text): void
    {
        $this->ensureTable();
        $this->connection->insert(self::TODOS, ['user_id' => $userId, 'project_id' => $projectId, 'text' => $text, 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTodo(int $userId, int $id): ?array
    {
        $this->ensureTable();
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::TODOS . ' WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $row === false ? null : $row;
    }

    public function toggleTodo(int $userId, int $id): void
    {
        $this->ensureTable();
        $this->connection->executeStatement('UPDATE ' . self::TODOS . ' SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public function deleteTodo(int $userId, int $id): void
    {
        $this->ensureTable();
        $this->connection->delete(self::TODOS, ['id' => $id, 'user_id' => $userId]);
    }

    /**
     * Offene zuerst, erledigte der letzten 14 Tage danach.
     *
     * @return array<int, array<string, mixed>>
     */
    public function todos(int $userId, ?int $projectId = null, bool $withDone = true): array
    {
        $this->ensureTable();
        $sql = 'SELECT * FROM ' . self::TODOS . ' WHERE user_id = ?';
        $params = [$userId];
        if ($projectId !== null) {
            $sql .= ' AND project_id = ?';
            $params[] = $projectId;
        }
        $sql .= $withDone ? ' AND (done_at IS NULL OR done_at > DATE_SUB(NOW(), INTERVAL 14 DAY))' : ' AND done_at IS NULL';

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY done_at IS NOT NULL, done_at DESC, project_id, id', $params);
    }

    // ---------- Fotos ----------

    public function addPhoto(int $userId, int $projectId, string $filename, ?string $comment): int
    {
        $this->ensureTable();
        $this->connection->insert(self::PHOTOS, ['user_id' => $userId, 'project_id' => $projectId, 'filename' => $filename, 'comment' => $comment, 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPhoto(int $userId, int $id): ?array
    {
        $this->ensureTable();
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::PHOTOS . ' WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $row === false ? null : $row;
    }

    public function updatePhotoComment(int $userId, int $id, ?string $comment): void
    {
        $this->ensureTable();
        $this->connection->update(self::PHOTOS, ['comment' => $comment], ['id' => $id, 'user_id' => $userId]);
    }

    public function deletePhoto(int $userId, int $id): void
    {
        $this->ensureTable();
        $this->connection->delete(self::PHOTOS, ['id' => $id, 'user_id' => $userId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function photos(int $userId, int $projectId): array
    {
        $this->ensureTable();

        return $this->connection->fetchAllAssociative('SELECT * FROM ' . self::PHOTOS . ' WHERE user_id = ? AND project_id = ? ORDER BY created_at DESC, id DESC', [$userId, $projectId]);
    }
}
