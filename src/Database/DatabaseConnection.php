<?php

namespace Database;

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

    // Configuration DB via tunnel SSH
    const DB_HOST = '127.0.0.1';
    const DB_PORT = 13306;
    const DB_NAME = 'scorimmopp';
    const DB_USER = 'scorimmopp';
    const DB_PASSWORD = 'W8XNbPgTfXrHDhrK';

    /**
     * Constructeur privé (Singleton)
     */
    private function __construct()
    {
        $this->connectToPDO();
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
    private function isTunnelActive()
    {
        $connection = @fsockopen('127.0.0.1', self::DB_PORT, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Établit la connexion PDO à la base de données via le tunnel SSH
     *
     * @throws Exception Si le tunnel n'est pas actif ou si la connexion échoue
     */
    private function connectToPDO()
    {
        // Vérifier que le tunnel SSH est actif
        if (!$this->isTunnelActive()) {
            throw new Exception(
                "Le tunnel SSH n'est pas actif sur le port " . self::DB_PORT . ".\n\n" .
                    "Pour créer le tunnel, exécutez cette commande dans un terminal :\n" .
                    "ssh -L 13306:scorimmo-preprod8.mysql:3306 scorimmopp@scorimmo.gw.oxv.fr -i C:/Users/Luc/.ssh/id_rsa -N\n\n" .
                    "Ou utilisez le fichier batch : toolV2/start_tunnel.bat"
            );
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                self::DB_HOST,
                self::DB_PORT,
                self::DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_TIMEOUT => 5
            ];

            $this->pdo = new PDO($dsn, self::DB_USER, self::DB_PASSWORD, $options);
        } catch (PDOException $e) {
            throw new Exception(
                'Erreur de connexion à la base de données via le tunnel SSH.\n' .
                    'Vérifiez que le tunnel est bien actif sur le port ' . self::DB_PORT . '.\n' .
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
