<?php

namespace Database;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use Exception;

/**
 * Gestionnaire de connexion à la base de données de production
 * Utilise un tunnel SSH existant sur le port 13306
 *
 * @author Luc Bergougniou
 * @copyright 2025 Scorimmo
 */
class DatabaseConnection
{
    private static $instance = null;
    private $pdo;

    // Configuration DB via tunnel SSH (chargée depuis .env)
    private string $dbHost;
    private int $dbPort;
    private string $dbName;
    private string $dbUser;
    private string $dbPassword;

    // Configuration SSH (chargée depuis .env)
    private string $sshHost;
    private string $sshUser;
    private string $sshKey;
    private string $sshRemoteHost;
    private int $sshRemotePort;

    /**
     * Constructeur privé (Singleton)
     */
    private function __construct()
    {
        $this->loadConfiguration();
        $this->connectToPDO();
    }

    /**
     * Charge la configuration depuis les variables d'environnement
     */
    private function loadConfiguration(): void
    {
        // Charger le fichier .env
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
        $dotenv->safeLoad();

        $this->dbHost = $_ENV['SCORIMMO_DB_HOST'] ?? '127.0.0.1';
        $this->dbPort = (int) ($_ENV['SCORIMMO_DB_PORT'] ?? 13306);
        $this->dbName = $_ENV['SCORIMMO_DB_NAME'] ?? 'scorimmo';
        $this->dbUser = $_ENV['SCORIMMO_DB_USER'] ?? 'scorimmo';
        $this->dbPassword = $_ENV['SCORIMMO_DB_PASSWORD'] ?? '';

        $this->sshHost = $_ENV['SSH_HOST'] ?? '';
        $this->sshUser = $_ENV['SSH_USER'] ?? '';
        $this->sshKey = $_ENV['SSH_KEY'] ?? '';
        $this->sshRemoteHost = $_ENV['SSH_REMOTE_HOST'] ?? '';
        $this->sshRemotePort = (int) ($_ENV['SSH_REMOTE_PORT'] ?? 3306);
    }

    /**
     * Récupère l'instance unique de DatabaseConnection
     *
     * @return DatabaseConnection
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Vérifie si le tunnel SSH est actif
     *
     * @return bool
     */
    private function isTunnelActive(): bool
    {
        $connection = @fsockopen('127.0.0.1', $this->dbPort, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Démarre le tunnel SSH en arrière-plan
     *
     * @return bool True si le tunnel a été démarré avec succès
     * @throws Exception Si le tunnel ne peut pas être démarré
     */
    private function startTunnel(): bool
    {
        $sshKey = str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $this->sshKey);

        $command = sprintf(
            'ssh -f -N -L %d:%s:%d %s@%s -i %s -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=10 2>&1',
            $this->dbPort,
            $this->sshRemoteHost,
            $this->sshRemotePort,
            $this->sshUser,
            $this->sshHost,
            escapeshellarg($sshKey)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception(
                "Impossible de démarrer le tunnel SSH.\n" .
                "Commande: $command\n" .
                "Erreur: " . implode("\n", $output)
            );
        }

        // Attendre que le tunnel soit établi
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            usleep(300000); // 300ms
            if ($this->isTunnelActive()) {
                return true;
            }
        }

        throw new Exception("Le tunnel SSH a été lancé mais n'est pas accessible sur le port " . $this->dbPort);
    }

    /**
     * Établit la connexion PDO à la base de données via le tunnel SSH
     *
     * @throws Exception Si le tunnel n'est pas actif ou si la connexion échoue
     */
    private function connectToPDO()
    {
        // Vérifier que le tunnel SSH est actif, sinon le démarrer
        if (!$this->isTunnelActive()) {
            $this->startTunnel();
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->dbHost,
                $this->dbPort,
                $this->dbName
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_TIMEOUT => 5
            ];

            $this->pdo = new PDO($dsn, $this->dbUser, $this->dbPassword, $options);
        } catch (PDOException $e) {
            throw new Exception(
                'Erreur de connexion à la base de données via le tunnel SSH.\n' .
                    'Vérifiez que le tunnel est bien actif sur le port ' . $this->dbPort . '.\n' .
                    'Erreur PDO: ' . $e->getMessage()
            );
        }
    }

    /**
     * Exécute une requête SQL avec des paramètres
     */
    public function query($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Récupère toutes les lignes d'une requête
     */
    public function fetchAll($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Récupère une seule ligne d'une requête
     */
    public function fetch($sql, $params = [])
    {
        return $this->query($sql, $params)->fetch();
    }

    /**
     * Exécute une requête et retourne le nombre de lignes affectées
     */
    public function execute($sql, $params = [])
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Récupère l'instance PDO
     */
    public function getPDO()
    {
        return $this->pdo;
    }

    /**
     * Empêche le clonage de l'instance
     */
    private function __clone() {}

    /**
     * Empêche la désérialisation de l'instance
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
