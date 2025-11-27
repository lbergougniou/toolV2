<?php

namespace Email;

use Database\DatabaseConnection;
use Exception;

/**
 * Service pour récupérer des emails de test depuis la BDD de production
 * et les envoyer vers la route /email/incoming
 *
 * @author Luc Bergougniou
 * @copyright 2025 Scorimmo
 */
class EmailTestSender
{
    private $db;
    private $emailTypesConfig;

    const API_URL = 'https://ppd:nk2saQjVrjwZa4HpYqNS@pp.scorimmo.com/email/incoming';
    const DEFAULT_TO_EMAIL = 'test@mail.scorimmo.com';
    const DEFAULT_FROM_EMAIL = 'error@scorimmo.com';

    /**
     * Constructeur
     *
     * @param DatabaseConnection $db Instance de DatabaseConnection
     */
    public function __construct(DatabaseConnection $db)
    {
        $this->db = $db;
        $this->loadEmailTypesConfig();
    }

    /**
     * Charge la configuration des types d'emails depuis le fichier JSON
     *
     * @throws Exception Si le fichier n'existe pas ou est invalide
     */
    private function loadEmailTypesConfig()
    {
        $configPath = __DIR__ . '/../../config/email_types.json';

        if (!file_exists($configPath)) {
            throw new Exception("Le fichier de configuration email_types.json n'existe pas : $configPath");
        }

        $jsonContent = file_get_contents($configPath);
        $this->emailTypesConfig = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur lors du parsing du fichier JSON : " . json_last_error_msg());
        }
    }

    /**
     * Récupère la liste des types d'emails disponibles
     *
     * @return array Liste des types avec leur label
     */
    public function getAvailableEmailTypes()
    {
        $types = [];
        foreach ($this->emailTypesConfig as $key => $config) {
            $types[$key] = $config['label'];
        }
        return $types;
    }

    /**
     * Construit la clause WHERE SQL basée sur la configuration du type d'email
     *
     * @param string $emailType Type d'email (clé du JSON)
     * @return string Clause WHERE SQL
     * @throws Exception Si le type d'email est inconnu
     */
    private function buildWhereClause($emailType)
    {
        if (!isset($this->emailTypesConfig[$emailType])) {
            throw new Exception("Type d'email inconnu : $emailType");
        }

        $searchMethods = $this->emailTypesConfig[$emailType]['search_methods'];
        $conditions = [];

        foreach ($searchMethods as $method) {
            $field = $method['field'];
            $pattern = $method['pattern'];
            $operator = $method['operator'];

            if ($operator === 'LIKE') {
                // Échappement pour éviter les injections SQL
                $escapedPattern = addslashes($pattern);
                $conditions[] = "$field LIKE '%$escapedPattern%'";
            }
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Récupère les emails depuis la base de données
     *
     * @param string $emailType Type d'email à rechercher
     * @param int $limit Nombre maximum d'emails à récupérer (1-20)
     * @param int $days Nombre de jours à remonter dans l'historique (par défaut 20)
     * @return array Liste des emails trouvés
     */
    public function fetchEmailsFromDatabase($emailType, $limit = 20, $days = 20)
    {
        // Validation des paramètres
        $limit = max(1, min(20, intval($limit)));
        $days = max(1, intval($days));

        // Construction de la clause WHERE
        $whereClause = $this->buildWhereClause($emailType);

        // Construction de la requête SQL
        $sql = "
            SELECT
                id,
                to_email,
                sender_email,
                sender_subject,
                plain,
                html
            FROM si_received_email
            WHERE created_at > CURRENT_DATE() - INTERVAL ? DAY
                AND substatus = 'lead_created'
                AND $whereClause
            ORDER BY created_at DESC
            LIMIT ?
        ";

        // Exécution de la requête
        $results = $this->db->fetchAll($sql, [$days, $limit]);

        return $results;
    }

    /**
     * Échappe le contenu pour l'inclure dans le JSON
     *
     * @param string|null $content Contenu à échapper
     * @return string Contenu échappé
     */
    private function escapeContent($content)
    {
        if (empty($content)) {
            return '';
        }

        // Le contenu sera encodé en JSON, pas besoin d'addslashes
        return $content;
    }

    /**
     * Envoie un email vers la route /email/incoming
     *
     * @param array $email Données de l'email
     * @return array Résultat de l'envoi (success, http_code, response)
     */
    public function sendEmail($email)
    {
        // Préparation des données
        $toEmail = $email['to_email'] ?: self::DEFAULT_TO_EMAIL;
        $fromEmail = $email['sender_email'] ?: self::DEFAULT_FROM_EMAIL;
        $subject = 'TECH : ' . ($email['sender_subject'] ?: 'Test email') . ' ' . time();
        $plainText = $this->escapeContent($email['plain']);
        $htmlContent = $this->escapeContent($email['html']);

        // Construction du payload JSON
        $payload = [
            'headers' => [
                'subject' => $subject,
                'from' => $fromEmail,
                'to' => $toEmail
            ],
            'envelope' => [
                'from' => $fromEmail,
                'recipients' => [$toEmail]
            ],
            'plain' => $plainText,
            'html' => $htmlContent,
            'reply_plain' => 'Message reply if found.'
        ];

        // Envoi de la requête HTTP POST
        $ch = curl_init(self::API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Retour du résultat
        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $error,
            'email_id' => $email['id']
        ];
    }

    /**
     * Récupère et envoie plusieurs emails
     *
     * @param string $emailType Type d'email
     * @param int $quantity Nombre d'emails à envoyer (1-20)
     * @param int $days Nombre de jours à remonter
     * @return array Résultats de l'envoi pour chaque email
     */
    public function fetchAndSendEmails($emailType, $quantity = 1, $days = 20)
    {
        // Récupération des emails depuis la BDD
        $emails = $this->fetchEmailsFromDatabase($emailType, $quantity, $days);

        if (empty($emails)) {
            return [
                'success' => false,
                'message' => 'Aucun email trouvé pour ce type et cette période',
                'emails_found' => 0,
                'results' => []
            ];
        }

        // Envoi de chaque email
        $results = [];
        foreach ($emails as $email) {
            $result = $this->sendEmail($email);
            $results[] = $result;

            // Petite pause entre chaque envoi pour éviter de surcharger le serveur
            usleep(500000); // 0.5 seconde
        }

        // Calcul du nombre de succès
        $successCount = count(array_filter($results, function($r) {
            return $r['success'];
        }));

        return [
            'success' => true,
            'message' => "$successCount email(s) envoyé(s) avec succès sur " . count($emails),
            'emails_found' => count($emails),
            'success_count' => $successCount,
            'results' => $results
        ];
    }
}
