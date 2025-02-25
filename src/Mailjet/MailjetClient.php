<?php
/**
 * MailjetClient.php - Classe de gestion des interactions avec l'API Mailjet
 * 
 * @package App\Mailjet
 */

namespace App\Mailjet;

use App\Mailjet\Exception\MailjetException;

class MailjetClient {
    /**
     * @var string Clé API Mailjet
     */
    private $apiKey;
    
    /**
     * @var string Clé secrète Mailjet
     */
    private $secretKey;
    
    /**
     * @var callable|null Fonction de logging optionnelle
     */
    private $logger;
    
    /**
     * @var array Cache des résultats de vérification
     */
    private $cache = [];
    
    /**
     * @var int Durée de validité du cache en secondes (1 heure par défaut)
     */
    private $cacheExpiration = 3600;
    
    /**
     * Constructeur
     *
     * @param string|null $apiKey Clé API (si null, utilise les variables d'environnement)
     * @param string|null $secretKey Clé secrète (si null, utilise les variables d'environnement)
     * @param callable|null $logger Fonction de logging optionnelle
     * @param int $cacheExpiration Durée de validité du cache en secondes
     */
    public function __construct($apiKey = null, $secretKey = null, $logger = null, $cacheExpiration = 3600) {
        // Charger les clés depuis les variables d'environnement si non fournies
        $this->apiKey = $apiKey ?? $_ENV['MAILJET_API_KEY'] ?? '';
        $this->secretKey = $secretKey ?? $_ENV['MAILJET_SECRET_KEY'] ?? '';
        $this->logger = $logger;
        $this->cacheExpiration = $cacheExpiration;
    }
    
    /**
     * Vérifie si les identifiants API sont valides
     *
     * @return bool True si les clés sont présentes
     */
    public function hasValidCredentials() {
        return !empty($this->apiKey) && !empty($this->secretKey);
    }
    
    /**
     * Initialise une session cURL pour les requêtes Mailjet
     *
     * @return resource Poignée cURL
     */
    public function initCurlHandle() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->apiKey}:{$this->secretKey}",
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false, // A adapter hors local
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30
        ]);
        return $ch;
    }
    
    /**
     * Crée une liste de contacts Mailjet
     *
     * @param string $listName Nom de la liste (optionnel, génère un nom unique si non fourni)
     * @return array Résultat avec l'ID de la liste en cas de succès
     * @throws MailjetException En cas d'erreur lors de la création de la liste
     */
    public function createList($listName = null) {
        if (!$this->hasValidCredentials()) {
            throw MailjetException::missingCredentials();
        }
        
        $listName = $listName ?? "list_" . md5(uniqid(mt_rand(), true));
        $this->log("Création de la liste: {$listName}");
        
        $ch = $this->initCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.mailjet.com/v3/REST/contactslist",
            CURLOPT_POSTFIELDS => json_encode(['Name' => $listName])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->log("Réponse Mailjet (création liste): HTTP {$httpCode} - " . substr($response, 0, 100) . "...");
        
        if (curl_errno($ch) || $httpCode >= 400) {
            $exception = MailjetException::fromCurlResponse(
                $ch, 
                "Erreur lors de la création de la liste", 
                'LIST_CREATION_ERROR', 
                ['listName' => $listName]
            );
            curl_close($ch);
            throw $exception;
        }
        
        $data = json_decode($response, true);
        curl_close($ch);
        
        if (!isset($data['Data'][0]['ID'])) {
            throw MailjetException::invalidResponseFormat($response, 'Data[0][ID]');
        }
        
        return [
            'success' => true,
            'listId' => $data['Data'][0]['ID'],
            'name' => $data['Data'][0]['Name']
        ];
    }
    
    /**
     * Ajoute ou met à jour un contact dans une liste
     *
     * @param string $listId ID de la liste
     * @param string $email Adresse email du contact
     * @param string $action Action à effectuer (addnoforce, addforce, remove)
     * @return array Résultat de l'opération
     * @throws MailjetException En cas d'erreur lors de la gestion du contact
     */
    public function manageContact($listId, $email, $action = 'addforce') {
        if (!$this->hasValidCredentials()) {
            throw MailjetException::missingCredentials();
        }
        
        $this->log("Gestion du contact {$email} dans la liste {$listId} (action: {$action})");
        
        $ch = $this->initCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.mailjet.com/v3/REST/contactslist/{$listId}/managecontact",
            CURLOPT_POSTFIELDS => json_encode(['Email' => $email, 'action' => $action]),
            CURLOPT_CUSTOMREQUEST => "POST"
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->log("Réponse Mailjet (gestion contact): HTTP {$httpCode}");
        
        if (curl_errno($ch) || $httpCode >= 400) {
            $exception = MailjetException::fromCurlResponse(
                $ch, 
                "Erreur lors de la gestion du contact", 
                'CONTACT_MANAGEMENT_ERROR', 
                ['listId' => $listId, 'email' => $email, 'action' => $action]
            );
            curl_close($ch);
            throw $exception;
        }
        
        curl_close($ch);
        return ['success' => true];
    }
    
    /**
     * Lance un job de vérification d'email sur une liste
     *
     * @param string $listId ID de la liste
     * @param string $method Méthode de vérification (fulllist, newcontacts, ...)
     * @return array Résultat avec l'ID du job en cas de succès
     * @throws MailjetException En cas d'erreur lors du lancement de la vérification
     */
    public function launchVerification($listId, $method = 'fulllist')
    {
        if (!$this->hasValidCredentials()) {
            throw MailjetException::missingCredentials();
        }
        
        $this->log("Lancement de la vérification de la liste {$listId} (méthode: {$method})");
        
        return $this->retryApiCall(function() use ($listId, $method) {
            $ch = $this->initCurlHandle();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.mailjet.com/v3/REST/contactslist/{$listId}/verify",
                CURLOPT_POSTFIELDS => json_encode(['Method' => $method])
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->log("Réponse Mailjet (lancement verification): HTTP {$httpCode}");
            
            if (curl_errno($ch) || $httpCode !== 201) {
                $exception = MailjetException::fromCurlResponse(
                    $ch, 
                    "Erreur lors du lancement de la vérification", 
                    'VERIFICATION_LAUNCH_ERROR', 
                    ['listId' => $listId, 'method' => $method]
                );
                curl_close($ch);
                throw $exception;
            }
            
            $data = json_decode($response, true);
            curl_close($ch);
            
            if (!isset($data['Data'][0]['JobID'])) {
                throw MailjetException::invalidResponseFormat($response, 'Data[0][JobID]');
            }
            
            return [
                'success' => true,
                'jobId' => $data['Data'][0]['JobID']
            ];
        });
    }
    
    /**
     * Vérifie l'état d'un job de vérification
     *
     * @param string $listId ID de la liste
     * @param string $jobId ID du job
     * @return array État du job et résultats si disponibles
     * @throws MailjetException En cas d'erreur lors de la vérification du statut
     */
    public function checkJobStatus($listId, $jobId) {
        if (!$this->hasValidCredentials()) {
            throw MailjetException::missingCredentials();
        }
        
        $this->log("Vérification du statut du job {$jobId} pour la liste {$listId}");
        
        $ch = $this->initCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.mailjet.com/v3/REST/contactslist/{$listId}/verify/{$jobId}",
            CURLOPT_CUSTOMREQUEST => "GET"
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->log("Réponse Mailjet (statut job): HTTP {$httpCode}");
        
        if (curl_errno($ch) || $httpCode >= 400) {
            $exception = MailjetException::fromCurlResponse(
                $ch, 
                "Erreur lors de la vérification du statut du job", 
                'JOB_STATUS_ERROR', 
                ['listId' => $listId, 'jobId' => $jobId]
            );
            curl_close($ch);
            throw $exception;
        }
        
        $data = json_decode($response, true);
        curl_close($ch);
        
        if (!isset($data['Data'][0]['Status'])) {
            throw MailjetException::invalidResponseFormat($response, 'Data[0][Status]');
        }
        
        $jobData = $data['Data'][0];
        $status = $jobData['Status'];
        
        return [
            'success' => true,
            'status' => $status,
            'data' => $jobData,
            'isCompleted' => $status === 'Completed',
            'isError' => $status === 'Error',
            'summary' => $jobData['Summary'] ?? null,
            'error' => $jobData['Error'] ?? null
        ];
    }
    
    /**
     * Supprime une liste Mailjet
     *
     * @param string $listId ID de la liste
     * @return array Résultat de l'opération
     * @throws MailjetException En cas d'erreur lors de la suppression de la liste
     */
    public function deleteList($listId) {
        if (!$this->hasValidCredentials()) {
            throw MailjetException::missingCredentials();
        }
        
        $this->log("Suppression de la liste {$listId}");
        
        $ch = $this->initCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.mailjet.com/v3/REST/contactslist/{$listId}",
            CURLOPT_CUSTOMREQUEST => "DELETE"
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->log("Réponse Mailjet (suppression liste): HTTP {$httpCode}");
        
        if (curl_errno($ch) || ($httpCode < 200 || $httpCode >= 300)) {
            $exception = MailjetException::fromCurlResponse(
                $ch, 
                "Erreur lors de la suppression de la liste", 
                'LIST_DELETION_ERROR', 
                ['listId' => $listId]
            );
            curl_close($ch);
            throw $exception;
        }
        
        curl_close($ch);
        return ['success' => true];
    }
    
    /**
     * Vérifie directement un email
     *
     * Cette méthode gère tout le processus de vérification en créant une liste,
     * ajoutant le contact, lançant la vérification et en gérant le polling.
     * Elle utilise également un cache pour éviter de revérifier les emails récents.
     *
     * @param string $email Email à vérifier
     * @param bool $useCache Utiliser le cache si disponible
     * @param int $maxAttempts Nombre maximal de tentatives de polling
     * @param int $initialWait Temps d'attente initial en secondes
     * @param int $pollingInterval Intervalle entre les vérifications en secondes
     * @return array Résultat de la vérification
     * @throws MailjetException En cas d'erreur durant le processus
     */
    public function verifyEmail($email, $useCache = true, $maxAttempts = 60, $initialWait = 30, $pollingInterval = 5) {
        // Vérifier le cache si activé
        if ($useCache) {
            $cachedResult = $this->getCachedResult($email);
            if ($cachedResult !== null) {
                $this->log("Résultat récupéré du cache pour {$email}");
                return $cachedResult;
            }
        }
        
        try {
            // 1. Créer une liste temporaire
            $listName = "verify_" . md5($email . time());
            $listResult = $this->createList($listName);
            $listId = $listResult['listId'];
            
            // 2. Ajouter le contact à la liste
            $this->manageContact($listId, $email);
            
            // 3. Lancer la vérification
            $verifyResult = $this->launchVerification($listId);
            $jobId = $verifyResult['jobId'];
            
            // 4. Attendre le résultat
            $result = $this->pollJobResult($listId, $jobId, $maxAttempts, $initialWait, $pollingInterval);
            
            // 5. Stocker dans le cache
            if ($useCache) {
                $this->setCacheResult($email, $result);
            }
            
            return $result;
        } finally {
            // Nettoyer la liste même en cas d'erreur
            if (isset($listId)) {
                try {
                    $this->deleteList($listId);
                } catch (MailjetException $e) {
                    // Simplement logger l'erreur sans la propager
                    $this->log("Erreur lors du nettoyage de la liste: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Effectue le polling d'un job Mailjet jusqu'à son achèvement
     *
     * @param string $listId ID de la liste
     * @param string $jobId ID du job
     * @param int $maxAttempts Nombre maximal de tentatives
     * @param int $initialWait Temps d'attente initial en secondes
     * @param int $pollingInterval Intervalle entre les vérifications en secondes
     * @return array Résultat final de la vérification
     * @throws MailjetException En cas d'erreur durant le polling ou timeout
     */
    private function pollJobResult($listId, $jobId, $maxAttempts, $initialWait, $pollingInterval) {
        // Première attente plus longue pour laisser le temps à Mailjet de démarrer
        sleep($initialWait);
        
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $jobResult = $this->checkJobStatus($listId, $jobId);
            $attempts++;
            
            // Si le job est terminé ou en erreur
            if ($jobResult['isCompleted'] || $jobResult['isError']) {
                if ($jobResult['isCompleted']) {
                    return $this->analyzeEmailVerificationResult($jobResult);
                } else {
                    throw new MailjetException(
                        "Le job de vérification a échoué: " . ($jobResult['error'] ?? 'Erreur inconnue'),
                        'JOB_FAILED',
                        0,
                        ['listId' => $listId, 'jobId' => $jobId, 'data' => $jobResult['data'] ?? []]
                    );
                }
            }
            
            // Attendre avant la prochaine vérification
            if ($attempts < $maxAttempts) {
                sleep($pollingInterval);
            }
        }
        
        throw new MailjetException(
            "Délai d'attente dépassé pour le job de vérification",
            'POLLING_TIMEOUT',
            0,
            ['listId' => $listId, 'jobId' => $jobId, 'maxAttempts' => $maxAttempts]
        );
    }
    
    /**
     * Récupère un résultat depuis le cache
     *
     * @param string $email Adresse email
     * @return array|null Résultat mis en cache ou null si non disponible/expiré
     */
    private function getCachedResult($email) {
        $key = md5($email);
        if (isset($this->cache[$key]) && time() - $this->cache[$key]['time'] < $this->cacheExpiration) {
            return $this->cache[$key]['result'];
        }
        return null;
    }
    
    /**
     * Stocke un résultat dans le cache
     *
     * @param string $email Adresse email
     * @param array $result Résultat à mettre en cache
     */
    private function setCacheResult($email, $result) {
        $key = md5($email);
        $this->cache[$key] = [
            'time' => time(),
            'result' => $result
        ];
    }
    
    /**
     * Analyse les résultats de vérification d'un email
     *
     * @param array $jobResult Résultat du job de vérification
     * @return array Résultats formatés avec statuts et risques
     * @throws MailjetException Si les données de résultat sont manquantes ou invalides
     */
    public function analyzeEmailVerificationResult($jobResult) {
        if (!isset($jobResult['summary'])) {
            throw new MailjetException(
                "Aucun résultat disponible dans la réponse Mailjet",
                'MISSING_VERIFICATION_RESULTS',
                0,
                ['jobResult' => $jobResult]
            );
        }
        
        $summary = $jobResult['summary'];
        $result = $summary['result'] ?? [];
        $risk = $summary['risk'] ?? [];
        
        // Déterminer le statut de l'email
        $status = $this->determineEmailStatus($result);
        
        // Déterminer le niveau de risque
        $riskLevel = $this->determineRiskLevel($risk);
        
        return [
            'success' => true,
            'status' => $status,
            'riskLevel' => $riskLevel,
            'isValid' => $status === 'deliverable',
            'rawResult' => $result,
            'rawRisk' => $risk,
            'message' => $this->formatStatusMessage($status, $riskLevel)
        ];
    }
    
    /**
     * Détermine le statut d'un email d'après les résultats Mailjet
     *
     * @param array $result Données de résultat Mailjet
     * @return string Statut de l'email (deliverable, catch_all, etc.)
     */
    private function determineEmailStatus($result) {
        if (isset($result['deliverable']) && $result['deliverable'] > 0) {
            return 'deliverable';
        } elseif (isset($result['catch_all']) && $result['catch_all'] > 0) {
            return 'catch_all';
        } elseif (isset($result['undeliverable']) && $result['undeliverable'] > 0) {
            return 'undeliverable';
        } elseif (isset($result['do_not_send']) && $result['do_not_send'] > 0) {
            return 'do_not_send';
        } elseif (isset($result['unknown']) && $result['unknown'] > 0) {
            return 'unknown';
        }
        
        return 'unknown';
    }
    
    /**
     * Détermine le niveau de risque d'après les résultats Mailjet
     *
     * @param array $risk Données de risque Mailjet
     * @return string Niveau de risque (low, medium, high, unknown)
     */
    private function determineRiskLevel($risk) {
        if (isset($risk['low']) && $risk['low'] > 0) {
            return 'low';
        } elseif (isset($risk['medium']) && $risk['medium'] > 0) {
            return 'medium';
        } elseif (isset($risk['high']) && $risk['high'] > 0) {
            return 'high';
        }
        
        return 'unknown';
    }
    
    /**
     * Formate un message de statut lisible
     *
     * @param string $status Statut de l'email
     * @param string $riskLevel Niveau de risque
     * @return string Message formaté
     */
    private function formatStatusMessage($status, $riskLevel) {
        $statusMessages = [
            'deliverable' => 'Délivrable',
            'catch_all' => 'Catch-all (accepte tous les emails)',
            'undeliverable' => 'Non délivrable',
            'do_not_send' => 'Ne pas envoyer',
            'unknown' => 'Statut inconnu'
        ];
        
        $riskMessages = [
            'low' => 'risque faible',
            'medium' => 'risque moyen',
            'high' => 'risque élevé',
            'unknown' => ''
        ];
        
        $message = $statusMessages[$status] ?? 'Statut inconnu';
        
        if ($status === 'deliverable' && $riskLevel !== 'unknown') {
            $message .= ' avec ' . $riskMessages[$riskLevel];
        }
        
        return $message;
    }

    /**
     * Exécute un appel API avec retries automatiques en cas d'erreur temporaire
     * @param callable $apiCall Fonction d'appel API à exécuter
     * @param int $maxRetries Nombre maximum de tentatives
     * @param array $retryableHttpCodes Codes HTTP à considérer comme réessayables
     * @return mixed Résultat de l'appel API
     */
    private function retryApiCall(callable $apiCall, $maxRetries = 3, $retryableHttpCodes = [429, 500, 502, 503, 504])
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            try {
                return $apiCall();
            } catch (\Exception $e) {
                $lastException = $e;
                $httpCode = $this->getHttpCodeFromException($e);
                
                // Si l'erreur n'est pas réessayable, arrêter immédiatement
                if (!in_array($httpCode, $retryableHttpCodes)) {
                    break;
                }
                
                // Backoff exponentiel
                $sleepTime = pow(2, $attempt) * 1000000; // en microsecondes
                $this->log("Erreur temporaire détectée (code: $httpCode). Nouvel essai dans " . ($sleepTime/1000000) . " secondes");
                usleep($sleepTime);
                $attempt++;
            }
        }
        
        // Toutes les tentatives ont échoué
        throw $lastException;
    }

    /**
     * Extrait le code HTTP d'une exception
     * @param \Exception $e Exception
     * @return int Code HTTP ou 0 si non trouvé
     */
    private function getHttpCodeFromException(\Exception $e)
    {
        if (method_exists($e, 'getCode')) {
            return $e->getCode();
        }
        
        // Extraire le code HTTP du message d'erreur si possible
        if (preg_match('/HTTP (\d+)/', $e->getMessage(), $matches)) {
            return (int)$matches[1];
        }
        
        return 0;
    }
        
    /**
     * Enregistre un message de log
     *
     * @param string $message Message à logger
     */
    private function log($message) {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        } else {
            error_log("[MailjetClient] " . $message);
        }
    }
}