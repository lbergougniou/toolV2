<?php

declare(strict_types=1);

/**
 * API de l'outil de rejeu d'emails (Email_Inbound_Replay.php).
 * POST JSON {action: search|preview|send, ...}
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Email\InboundEmailReplayer;
use App\Logger\Logger;
use Database\DatabaseConnection;
use Dotenv\Dotenv;

$logger = new Logger(__DIR__ . '/../../logs/inbound_replay.log', Logger::LEVEL_INFO);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

set_time_limit(0);

try {
    Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();
    $data = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);

    $replayer = new InboundEmailReplayer(
        DatabaseConnection::getInstance(),
        $_ENV['INBOUND_TARGET_URL'] ?? '',
        $_ENV['INBOUND_TARGET_SECRET'] ?? '',
    );
    $options = (array) ($data['options'] ?? []);

    $result = match ($data['action'] ?? '') {
        'search' => ['emails' => $replayer->search((array) ($data['filters'] ?? []))],
        'preview' => ['payload' => $replayer->preview((int) ($data['id'] ?? 0), $options)],
        'send' => ['results' => $replayer->replay(InboundEmailReplayer::parseIds(implode(',', (array) ($data['ids'] ?? []))), $options)],
        default => throw new InvalidArgumentException('Action inconnue'),
    };

    if (($data['action'] ?? '') === 'send') {
        $logger->info('Rejeu d\'emails', [
            'target' => $replayer->getTargetBaseUrl(),
            'results' => array_map(static fn(array $r): array => [
                'source_id' => $r['source_id'],
                'http_code' => $r['http_code'],
                'response' => $r['response'],
            ], $result['results']),
        ]);
    }

    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    $logger->error('Erreur rejeu d\'emails', ['error' => $e->getMessage()]);
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
