<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once 'lib/db.php';
    require_once 'classes/Transaction.php';
    require_once 'classes/RecurringExpense.php';
    require_once 'classes/Wallet.php';
    require_once 'lib/functions.php';

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $wallet = new Wallet($pdo);

    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? null;
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $action = $_GET['action'] ?? null;

    if ($resource !== 'transactions') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Невідомий ресурс або шлях']);
        exit;
    }

    switch ($method) {
        case 'GET':
            if ($id === null) {
                $q = $_GET['q'] ?? null;
                $transactions = $wallet->getTransactions($q);
                $data = [];

                foreach ($transactions as $tx) {
                    $data[] = [
                        'id' => $tx->getId(),
                        'info' => htmlspecialchars($tx->getInfo(), ENT_QUOTES, 'UTF-8'),
                        'date' => $tx->getDate(),
                        'month' => monthName($tx->getDate()),
                        'amount' => $tx->getAmount(),
                        'is_large' => $tx->getAmount() > 1000
                    ];
                }

                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'csrf_token' => $_SESSION['csrf_token'],
                    'transactions' => $data,
                    'total' => formatMoney($wallet->totalBalance()),
                    'utilities' => formatMoney($wallet->totalByCategory('Комунальні послуги'))
                ]);
            } else {
                $transactions = $wallet->getTransactions();
                $foundTx = null;
                foreach ($transactions as $tx) {
                    if ($tx->getId() === $id) {
                        $foundTx = $tx;
                        break;
                    }
                }

                if (!$foundTx) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Транзакцію з таким ID не знайдено']);
                    exit;
                }

                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'id' => $foundTx->getId(),
                        'amount' => $foundTx->getAmount(),
                        'category' => htmlspecialchars($foundTx->getCategory(), ENT_QUOTES, 'UTF-8'),
                        'date' => $foundTx->getDate(),
                        'info' => htmlspecialchars($foundTx->getInfo(), ENT_QUOTES, 'UTF-8')
                    ]
                ]);
            }
            break;

        case 'POST':
            $clientToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientToken)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Недійсний або відсутній CSRF-токен']);
                exit;
            }

            if ($action === 'stats') {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'total_balance' => $wallet->totalBalance(),
                    'utilities_total' => $wallet->totalByCategory('Комунальні послуги')
                ]);
                break;
            }

            $amount = trim($_POST['amount'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $date = trim($_POST['date'] ?? '');
            $type = trim($_POST['type'] ?? 'standard');
            $frequency = trim($_POST['frequency'] ?? 'Щомісячно');

            if ($amount === '' || !is_numeric($amount) || floatval($amount) <= 0 || empty($category) || empty($date)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'error' => 'Некоректні або неповні дані.'
                ]);
                exit;
            }

            if ($type === 'recurring') {
                $newTx = new RecurringExpense(floatval($amount), $category, $date, $frequency);
            } else {
                $newTx = new Transaction(floatval($amount), $category, $date);
            }

            $newId = $wallet->addTransaction($newTx, $type, $type === 'recurring' ? $frequency : null);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'id' => $newId,
                'message' => 'Транзакцію успішно створено',
                'total' => formatMoney($wallet->totalBalance())
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error' => 'Метод не підтримується для цього ресурсу'
            ]);
            break;
    }

} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Сталася внутрішня помилка сервера.']);
}