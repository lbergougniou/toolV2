<?php
namespace App\Logger;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Adaptateur pour utiliser Monolog avec votre Logger existant
 */
class MonologAdapter
{
    private MonologLogger $logger;

    public function __construct(string $logFile, int $logLevel = Logger::LEVEL_INFO)
    {
        $this->logger = new MonologLogger('ai_service');
        
        // Convertir le niveau de votre Logger vers Monolog
        $monologLevel = $this->convertLogLevel($logLevel);
        
        // Handler avec rotation automatique (plus robuste que votre implémentation)
        $handler = new RotatingFileHandler($logFile, 30, $monologLevel);
        
        // Format personnalisé pour correspondre à votre format existant
        $formatter = new LineFormatter(
            "[%datetime%] [%level_name%] %message% %context%\n",
            'Y-m-d H:i:s'
        );
        $handler->setFormatter($formatter);
        
        $this->logger->pushHandler($handler);
    }

    /**
     * Convertit les niveaux de votre Logger vers Monolog
     */
    private function convertLogLevel(int $level): int
    {
        return match($level) {
            Logger::LEVEL_DEBUG => MonologLogger::DEBUG,
            Logger::LEVEL_INFO => MonologLogger::INFO,
            Logger::LEVEL_WARNING => MonologLogger::WARNING,
            Logger::LEVEL_ERROR => MonologLogger::ERROR,
            Logger::LEVEL_CRITICAL => MonologLogger::CRITICAL,
            default => MonologLogger::INFO
        };
    }

    /**
     * Log un message de débogage
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    /**
     * Log un message d'information
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Log un avertissement
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Log une erreur
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * Log un message critique
     */
    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    /**
     * Permet de changer dynamiquement le niveau de log
     */
    public function setLogLevel(int $level): void
    {
        $monologLevel = $this->convertLogLevel($level);
        
        // Met à jour tous les handlers
        foreach ($this->logger->getHandlers() as $handler) {
            $handler->setLevel($monologLevel);
        }
    }
}