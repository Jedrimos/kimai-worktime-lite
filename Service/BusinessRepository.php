<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Gewerbe: Einnahmen, Ausgaben, Fahrten + Einstellungen pro Benutzer.
 */
final class BusinessRepository
{
    public const ENTRIES = 'kimai2_wtl_biz_entries';
    public const KV = 'kimai2_wtl_user_kv';
    public const CATEGORIES = ['material' => 'Material', 'werkzeug' => 'Werkzeug / Geräte', 'fahrzeug' => 'Fahrzeug / Tanken', 'buero' => 'Büro / Telefon / Software', 'versicherung' => 'Versicherung / Beiträge', 'werbung' => 'Werbung', 'sonstiges' => 'Sonstiges'];

    private bool $ready = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    private function ensure(): void
    {
        if ($this->ready) {
            return;
        }
        $opts = ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB';
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::ENTRIES . ' (
            id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(10) NOT NULL, entry_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0, km DECIMAL(10,1) DEFAULT NULL, category VARCHAR(30) DEFAULT NULL,
            title VARCHAR(200) NOT NULL, note VARCHAR(500) DEFAULT NULL, project_id INT DEFAULT NULL, receipt VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL, INDEX wtl_biz_user_date (user_id, entry_date), PRIMARY KEY(id))' . $opts);
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . self::KV . ' (user_id INT NOT NULL, name VARCHAR(60) NOT NULL, value VARCHAR(500) DEFAULT NULL, PRIMARY KEY(user_id, name))' . $opts);
        $this->ready = true;
    }

    public function get(int $userId, string $name, ?string $default = null): ?string
    {
        $this->ensure();
        $v = $this->connection->fetchOne('SELECT value FROM ' . self::KV . ' WHERE user_id = ? AND name = ?', [$userId, $name]);

        return $v === false || $v === null || $v === '' ? $default : (string) $v;
    }

    public function set(int $userId, string $name, ?string $value): void
    {
        $this->ensure();
        $this->connection->executeStatement('INSERT INTO ' . self::KV . ' (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$userId, $name, $value]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function add(int $userId, array $data): int
    {
        $this->ensure();
        $this->connection->insert(self::ENTRIES, $data + ['user_id' => $userId, 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $id): ?array
    {
        $this->ensure();
        $r = $this->connection->fetchAssociative('SELECT * FROM ' . self::ENTRIES . ' WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $r === false ? null : $r;
    }

    public function delete(int $userId, int $id): void
    {
        $this->ensure();
        $this->connection->delete(self::ENTRIES, ['id' => $id, 'user_id' => $userId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function entries(int $userId, int $year, ?string $type = null): array
    {
        $this->ensure();
        $sql = 'SELECT * FROM ' . self::ENTRIES . ' WHERE user_id = ? AND entry_date BETWEEN ? AND ?';
        $params = [$userId, $year . '-01-01', $year . '-12-31'];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY entry_date DESC, id DESC', $params);
    }

    /**
     * Bezahlte Kimai-Rechnungen (Zahlungsdatum im Jahr) des Benutzers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paidInvoices(int $userId, int $year): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT i.id, i.invoice_number, i.total, i.tax, i.payment_date, c.name customer FROM kimai2_invoices i LEFT JOIN kimai2_customers c ON c.id = i.customer_id
             WHERE i.user_id = ? AND i.status = 'paid' AND i.payment_date BETWEEN ? AND ? ORDER BY i.payment_date DESC",
            [$userId, $year . '-01-01', $year . '-12-31']
        );
    }

    /**
     * Offene Rechnungen (noch nicht bezahlt).
     *
     * @return array<int, array<string, mixed>>
     */
    public function openInvoices(int $userId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT i.id, i.invoice_number, i.total, i.created_at, i.due_days, c.name customer FROM kimai2_invoices i LEFT JOIN kimai2_customers c ON c.id = i.customer_id
             WHERE i.user_id = ? AND i.status IN ('new', 'pending') ORDER BY i.created_at",
            [$userId]
        );
    }
}
