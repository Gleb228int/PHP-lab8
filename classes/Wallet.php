<?php
require_once __DIR__ . '/Transaction.php';
require_once __DIR__ . '/RecurringExpense.php';

class Wallet {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getTransactions(?string $searchQuery = null): array {
        if ($searchQuery) {
            $stmt = $this->pdo->prepare("SELECT * FROM transactions WHERE category LIKE :q OR date LIKE :q ORDER BY date DESC");
            $stmt->execute([':q' => '%' . $searchQuery . '%']);
            $rows = $stmt->fetchAll();
        } else {
            // Використовуємо файловий кеш для загального списку (TTL = 30 секунд)
            $cacheFile = sys_get_temp_dir() . '/cache_transactions_list.json';
            if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 30) {
                $rows = json_decode(file_get_contents($cacheFile), true);
            } else {
                $stmt = $this->pdo->query("SELECT * FROM transactions ORDER BY date DESC");
                $rows = $stmt->fetchAll();
                file_put_contents($cacheFile, json_encode($rows));
            }
        }

        $transactions = [];
        foreach ($rows as $row) {
            if (isset($row['type']) && $row['type'] === 'recurring') {
                $transactions[] = new RecurringExpense(
                    (float)$row['amount'],
                    $row['category'],
                    $row['date'],
                    $row['frequency'] ?? 'Щомісячно',
                    (int)$row['id']
                );
            } else {
                $transactions[] = new Transaction(
                    (float)$row['amount'],
                    $row['category'],
                    $row['date'],
                    (int)$row['id']
                );
            }
        }
        return $transactions;
    }

    public function addTransaction(Transaction $tx, string $type = 'standard', ?string $frequency = null): int {
        $stmt = $this->pdo->prepare("INSERT INTO transactions (amount, category, date, type, frequency) VALUES (:amount, :category, :date, :type, :frequency)");
        $stmt->execute([
            ':amount' => $tx->getAmount(),
            ':category' => $tx->getCategory(),
            ':date' => $tx->getDate(),
            ':type' => $type,
            ':frequency' => $frequency
        ]);
        
        $this->clearCache();

        return (int)$this->pdo->lastInsertId();
    }

    public function totalBalance(): float {
        $cacheFile = sys_get_temp_dir() . '/cache_total_balance.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 30) {
            return (float)json_decode(file_get_contents($cacheFile), true);
        }

        $stmt = $this->pdo->query("SELECT SUM(amount) as total FROM transactions");
        $result = $stmt->fetch();
        $total = (float)($result['total'] ?? 0);

        file_put_contents($cacheFile, json_encode($total));
        return $total;
    }

    public function totalByCategory(string $category): float {
        $stmt = $this->pdo->prepare("SELECT SUM(amount) as total FROM transactions WHERE category = :category");
        $stmt->execute([':category' => $category]);
        $result = $stmt->fetch();
        return (float)($result['total'] ?? 0);
    }

    private function clearCache(): void {
        @unlink(sys_get_temp_dir() . '/cache_transactions_list.json');
        @unlink(sys_get_temp_dir() . '/cache_total_balance.json');
    }
}