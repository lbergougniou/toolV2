<?php
namespace App\Logger;

/**
 * Classe de journalisation pour gérer les logs de l'application
 */
class Logger {
    /**
     * Chemin du fichier de log
     * 
     * @var string
     */
    private $logFile;

    /**
     * Niveau de log actuel
     * 
     * @var int
     */
    private $logLevel;

    /**
     * Constantes pour les niveaux de log
     */
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;
    const LEVEL_CRITICAL = 4;

    /**
     * Constructeur du logger
     *
     * @param string $logFile Chemin du fichier de log
     * @param int $logLevel Niveau de log (défaut: INFO)
     */
    public function __construct($logFile, $logLevel = self::LEVEL_INFO) {
        $this->logFile = $logFile;
        $this->logLevel = $logLevel;

        // Créer le répertoire si nécessaire
        $this->createLogDirectory();
    }

    /**
     * Crée le répertoire des logs s'il n'existe pas
     */
    private function createLogDirectory() {
        $directory = dirname($this->logFile);
        
        if (!file_exists($directory)) {
            // Crée le répertoire avec les permissions appropriées
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Écrit un message de log avec un niveau spécifique
     *
     * @param string $message Message à log
     * @param int $level Niveau de log
     */
    private function log($message, $level) {
        // Vérifie si le niveau de log est suffisant
        if ($level < $this->logLevel) {
            return;
        }

        // Prépare le message de log
        $timestamp = date('Y-m-d H:i:s');
        $levelName = $this->getLevelName($level);
        $formattedMessage = "[{$timestamp}] [{$levelName}] {$message}" . PHP_EOL;

        // Tente d'écrire le message de log
        try {
            // Utilisation de file_put_contents avec un verrou pour éviter les conflits
            file_put_contents($this->logFile, $formattedMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // En cas d'erreur, on pourrait gérer cela différemment selon les besoins
            error_log("Impossible d'écrire dans le fichier de log: " . $e->getMessage());
        }

        // Option supplémentaire : rotation des logs si le fichier devient trop volumineux
        $this->rotateLogIfNeeded();
    }

    /**
     * Récupère le nom du niveau de log
     *
     * @param int $level Niveau de log
     * @return string Nom du niveau
     */
    private function getLevelName($level) {
        $levels = [
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARNING => 'WARNING',
            self::LEVEL_ERROR => 'ERROR',
            self::LEVEL_CRITICAL => 'CRITICAL'
        ];

        return $levels[$level] ?? 'UNKNOWN';
    }

    /**
     * Fait tourner le fichier de log s'il devient trop volumineux
     * 
     * @param int $maxFileSize Taille maximale en octets (défaut: 10 Mo)
     */
    private function rotateLogIfNeeded($maxFileSize = 10 * 1024 * 1024) {
        if (file_exists($this->logFile) && filesize($this->logFile) > $maxFileSize) {
            $rotatedFile = $this->logFile . '.' . date('Y-m-d-H-i-s');
            rename($this->logFile, $rotatedFile);
        }
    }

    /**
     * Log un message de débogage
     *
     * @param string $message Message à log
     */
    public function debug($message) {
        $this->log($message, self::LEVEL_DEBUG);
    }

    /**
     * Log un message d'information
     *
     * @param string $message Message à log
     */
    public function info($message) {
        $this->log($message, self::LEVEL_INFO);
    }

    /**
     * Log un avertissement
     *
     * @param string $message Message à log
     */
    public function warning($message) {
        $this->log($message, self::LEVEL_WARNING);
    }

    /**
     * Log une erreur
     *
     * @param string $message Message à log
     */
    public function error($message) {
        $this->log($message, self::LEVEL_ERROR);
    }

    /**
     * Log un message critique
     *
     * @param string $message Message à log
     */
    public function critical($message) {
        $this->log($message, self::LEVEL_CRITICAL);
    }

    /**
     * Permet de changer dynamiquement le niveau de log
     *
     * @param int $level Nouveau niveau de log
     */
    public function setLogLevel($level) {
        $this->logLevel = $level;
    }

    /**
     * Nettoie les anciens fichiers de log
     * 
     * @param int $daysToKeep Nombre de jours à conserver (défaut: 30)
     */
    public function cleanupOldLogs($daysToKeep = 30) {
        $logDirectory = dirname($this->logFile);
        $logBasename = basename($this->logFile);

        $files = glob($logDirectory . '/' . $logBasename . '.*');
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }
}