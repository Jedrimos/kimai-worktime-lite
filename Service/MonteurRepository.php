<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Daten der Monteur-Funktionen: Baustellen-Status/Zugang, Vorlagen, Mitnehmen-Liste,
 * Stromkreisliste, Werkzeug, Bus-Lager. Alles pro Benutzer, Tabellen entstehen automatisch.
 */
final class MonteurRepository
{
    public const META = 'kimai2_wtl_site_meta';
    public const TEMPLATES = 'kimai2_wtl_templates';
    public const PACK = 'kimai2_wtl_packlist';
    public const CIRCUITS = 'kimai2_wtl_circuits';
    public const TOOLS = 'kimai2_wtl_tools';
    public const STOCK = 'kimai2_wtl_stock';
    public const COMPANIONS = 'kimai2_wtl_companions';

    public const STATUS = ['geplant' => 'Geplant', 'laeuft' => 'Läuft', 'fertig' => 'Fertig', 'abgerechnet' => 'Abgerechnet'];

    private bool $ready = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function ensure(): void
    {
        if ($this->ready) {
            return;
        }
        $opts = ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB';
        $c = $this->connection;
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::META . ' (user_id INT NOT NULL, project_id INT NOT NULL, status VARCHAR(20) DEFAULT NULL, access_info TEXT DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(user_id, project_id))' . $opts);
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::TEMPLATES . ' (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, kind VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL, lines_json TEXT NOT NULL, created_at DATETIME NOT NULL, INDEX wtl_tpl_user (user_id, kind), PRIMARY KEY(id))' . $opts);
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::PACK . ' (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, text VARCHAR(300) NOT NULL, done_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX wtl_pack_user (user_id), PRIMARY KEY(id))' . $opts);
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::CIRCUITS . ' (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, project_id INT NOT NULL, board VARCHAR(100) DEFAULT NULL, pos VARCHAR(30) DEFAULT NULL, device VARCHAR(100) DEFAULT NULL, description VARCHAR(300) DEFAULT NULL, cable VARCHAR(100) DEFAULT NULL, sort INT NOT NULL DEFAULT 0, INDEX wtl_circ_user_project (user_id, project_id), PRIMARY KEY(id))' . $opts);
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::TOOLS . ' (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(150) NOT NULL, location VARCHAR(150) DEFAULT NULL, project_id INT DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX wtl_tools_user (user_id), PRIMARY KEY(id))' . $opts);
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::STOCK . ' (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, text VARCHAR(200) NOT NULL, unit VARCHAR(20) DEFAULT NULL, low TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, INDEX wtl_stock_user (user_id), PRIMARY KEY(id))' . $opts);
        $c->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::COMPANIONS . ' (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(60) NOT NULL, UNIQUE INDEX wtl_comp_user_name (user_id, name), PRIMARY KEY(id))' . $opts);
        $this->ready = true;
    }

    // ---------- Mit wem ----------

    /**
     * @return array<int, string>
     */
    public function companions(int $userId): array
    {
        $this->ensure();

        return array_map('strval', $this->connection->fetchFirstColumn('SELECT name FROM ' . self::COMPANIONS . ' WHERE user_id = ? ORDER BY name', [$userId]));
    }

    public function addCompanion(int $userId, string $name): void
    {
        $this->ensure();
        $this->connection->executeStatement('INSERT IGNORE INTO ' . self::COMPANIONS . ' (user_id, name) VALUES (?, ?)', [$userId, $name]);
    }

    public function deleteCompanion(int $userId, string $name): void
    {
        $this->ensure();
        $this->connection->delete(self::COMPANIONS, ['user_id' => $userId, 'name' => $name]);
    }

    // ---------- Baustellen-Status / Zugang ----------

    /**
     * @return array{status: ?string, access_info: ?string}
     */
    public function meta(int $userId, int $projectId): array
    {
        $this->ensure();
        $row = $this->connection->fetchAssociative('SELECT status, access_info FROM ' . self::META . ' WHERE user_id = ? AND project_id = ?', [$userId, $projectId]);

        return $row === false ? ['status' => null, 'access_info' => null] : $row;
    }

    /**
     * @return array<int, string> project_id => status
     */
    public function allStatus(int $userId): array
    {
        $this->ensure();
        $out = [];
        foreach ($this->connection->fetchAllAssociative('SELECT project_id, status FROM ' . self::META . ' WHERE user_id = ? AND status IS NOT NULL', [$userId]) as $r) {
            $out[(int) $r['project_id']] = (string) $r['status'];
        }

        return $out;
    }

    public function setMeta(int $userId, int $projectId, string $field, ?string $value): void
    {
        $this->ensure();
        if (!\in_array($field, ['status', 'access_info'], true)) {
            return;
        }
        $this->connection->executeStatement(
            'INSERT INTO ' . self::META . ' (user_id, project_id, ' . $field . ', updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ' . $field . ' = VALUES(' . $field . '), updated_at = VALUES(updated_at)',
            [$userId, $projectId, $value, $this->now()]
        );
    }

    // ---------- Vorlagen ----------

    /**
     * @param array<int, array{quantity: ?float, unit: ?string, text: string}> $lines
     */
    public function addTemplate(int $userId, string $kind, string $name, array $lines): void
    {
        $this->ensure();
        $this->connection->insert(self::TEMPLATES, ['user_id' => $userId, 'kind' => $kind, 'name' => $name, 'lines_json' => json_encode(array_values($lines), JSON_UNESCAPED_UNICODE), 'created_at' => $this->now()]);
    }

    /**
     * @return array<int, array{id: int, name: string, kind: string, lines: array<int, array<string, mixed>>}>
     */
    public function templates(int $userId, ?string $kind = null): array
    {
        $this->ensure();
        $sql = 'SELECT * FROM ' . self::TEMPLATES . ' WHERE user_id = ?';
        $params = [$userId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $out = [];
        foreach ($this->connection->fetchAllAssociative($sql . ' ORDER BY name', $params) as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'lines' => json_decode((string) $r['lines_json'], true) ?: []];
        }

        return $out;
    }

    public function deleteTemplate(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->delete(self::TEMPLATES, ['id' => $id, 'user_id' => $userId]);
    }

    // ---------- Mitnehmen-Liste ----------

    public function addPack(int $userId, string $text): void
    {
        $this->ensure();
        $this->connection->insert(self::PACK, ['user_id' => $userId, 'text' => $text, 'created_at' => $this->now()]);
    }

    /**
     * Offene + heute abgehakte.
     *
     * @return array<int, array<string, mixed>>
     */
    public function packlist(int $userId): array
    {
        $this->ensure();

        return $this->connection->fetchAllAssociative('SELECT * FROM ' . self::PACK . ' WHERE user_id = ? AND (done_at IS NULL OR done_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)) ORDER BY done_at IS NOT NULL, id', [$userId]);
    }

    public function togglePack(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->executeStatement('UPDATE ' . self::PACK . ' SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public function deletePack(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->delete(self::PACK, ['id' => $id, 'user_id' => $userId]);
    }

    // ---------- Stromkreisliste ----------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function circuits(int $userId, int $projectId): array
    {
        $this->ensure();

        return $this->connection->fetchAllAssociative('SELECT * FROM ' . self::CIRCUITS . ' WHERE user_id = ? AND project_id = ? ORDER BY board, sort, id', [$userId, $projectId]);
    }

    /**
     * @param array{board: ?string, pos: ?string, device: ?string, description: ?string, cable: ?string} $data
     */
    public function addCircuit(int $userId, int $projectId, array $data): void
    {
        $this->ensure();
        $sort = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . self::CIRCUITS . ' WHERE user_id = ? AND project_id = ?', [$userId, $projectId]);
        $this->connection->insert(self::CIRCUITS, $data + ['user_id' => $userId, 'project_id' => $projectId, 'sort' => $sort]);
    }

    /**
     * @param array<string, ?string> $data
     */
    public function updateCircuit(int $userId, int $id, array $data): void
    {
        $this->ensure();
        $allowed = array_intersect_key($data, array_flip(['board', 'pos', 'device', 'description', 'cable']));
        if ($allowed !== []) {
            $this->connection->update(self::CIRCUITS, $allowed, ['id' => $id, 'user_id' => $userId]);
        }
    }

    public function deleteCircuit(int $userId, int $id): ?int
    {
        $this->ensure();
        $pid = $this->connection->fetchOne('SELECT project_id FROM ' . self::CIRCUITS . ' WHERE id = ? AND user_id = ?', [$id, $userId]);
        $this->connection->delete(self::CIRCUITS, ['id' => $id, 'user_id' => $userId]);

        return $pid === false ? null : (int) $pid;
    }

    public function circuitProject(int $userId, int $id): ?int
    {
        $this->ensure();
        $pid = $this->connection->fetchOne('SELECT project_id FROM ' . self::CIRCUITS . ' WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $pid === false ? null : (int) $pid;
    }

    // ---------- Werkzeug ----------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tools(int $userId): array
    {
        $this->ensure();

        return $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TOOLS . ' WHERE user_id = ? ORDER BY name', [$userId]);
    }

    public function addTool(int $userId, string $name, ?string $location): void
    {
        $this->ensure();
        $this->connection->insert(self::TOOLS, ['user_id' => $userId, 'name' => $name, 'location' => $location, 'updated_at' => $this->now()]);
    }

    public function moveTool(int $userId, int $id, ?string $location, ?int $projectId): void
    {
        $this->ensure();
        $this->connection->update(self::TOOLS, ['location' => $location, 'project_id' => $projectId, 'updated_at' => $this->now()], ['id' => $id, 'user_id' => $userId]);
    }

    public function deleteTool(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->delete(self::TOOLS, ['id' => $id, 'user_id' => $userId]);
    }

    // ---------- Bus-Lager ----------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stock(int $userId, bool $onlyLow = false): array
    {
        $this->ensure();

        return $this->connection->fetchAllAssociative('SELECT * FROM ' . self::STOCK . ' WHERE user_id = ?' . ($onlyLow ? ' AND low = 1' : '') . ' ORDER BY low DESC, text', [$userId]);
    }

    public function addStock(int $userId, string $text, ?string $unit): void
    {
        $this->ensure();
        $this->connection->insert(self::STOCK, ['user_id' => $userId, 'text' => $text, 'unit' => $unit, 'low' => 0, 'updated_at' => $this->now()]);
    }

    public function toggleStock(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->executeStatement('UPDATE ' . self::STOCK . ' SET low = 1 - low, updated_at = NOW() WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public function deleteStock(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->delete(self::STOCK, ['id' => $id, 'user_id' => $userId]);
    }
}
