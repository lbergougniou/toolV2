<?php
/**
 * EmailValidator.php - Classe principale de validation d'email
 */

namespace App\Email\Validator;

use App\Mailjet\MailjetClient;
use App\Config\SmtpCodes;

class EmailValidator {
    /**
     * @var string Adresse email à valider
     */
    private $email;
    
    /**
     * @var array Configuration SMTP
     */
    private $smtpConfig;
    
    /**
     * @var object|null Logger (optionnel)
     */
    private $logger;
    
    /**
     * @var MailjetClient Client Mailjet
     */
    private $mailjetClient;

    /**
     * Constructeur
     * * @param string $email Adresse email à vérifier
     * @param object|null $logger Logger optionnel
     */
    public function __construct(string $email, $logger = null) {
        // Charger les variables d'environnement si nécessaire
        $this->loadEnvironment();
        
        $this->email = $email;
        $this->logger = $logger;
        $this->smtpConfig = [
            'host' => $_ENV['SMTP_HOST']?? 'in-v3.mailjet.com',
            'port' => $_ENV['SMTP_PORT']?? 587,
            'user' => $_ENV['SMTP_AUTH_USER']?? '',
            'pass' => $_ENV['SMTP_AUTH_PASSWORD']?? '',
        ];
        
        // Initialiser le client Mailjet avec une fonction de logging
        $this->mailjetClient = new MailjetClient(
            null, 
            null, 
            function($message) { $this->log($message); }
        );
    }
    
    /**
     * Charge les variables d'environnement
     */
    private function loadEnvironment() {
        if (!isset($_ENV['MAILJET_API_KEY']) && file_exists(__DIR__. '/../../../.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__. '/../../../');
            $dotenv->load();
        }
    }

    /**
     * Active TLS pour une connexion socket
     * * @param resource $socket Connexion socket
     * @return bool Succès de l'activation
     */
    private function startTLS($socket) {
        // Activer TLS avec les bonnes options
        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT; 
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT; 
        }
        return stream_socket_enable_crypto($socket, true, $crypto_method);
    }

    /**
     * Envoie une commande SMTP et lit la réponse
     * * @param resource $socket Connexion socket
     * @param string $command Commande SMTP
     * @param int|array $expectedCode Code(s) de réponse attendu(s)
     * @return array Résultat de la commande
     */
    private function smtpCommand($socket, $command, $expectedCode) {
        fwrite($socket, $command. "\r\n");
        $response = fgets($socket);
        $code = intval(substr($response, 0, 3));
        
        if ($this->logger) {
            $this->logger->debug("SMTP Command", [
                'command' => $command,
                'response' => trim($response),
                'code' => $code
            ]);
        }

        return [
            'code' => $code,
            'response' => trim($response),
            'success' => is_array($expectedCode)? in_array($code, $expectedCode): $code === $expectedCode,
        ];
    }

    /**
     * Teste la connexion SMTP pour vérifier l'existence de l'email
     * * @return array Résultat du test
     */
    public function testSmtpConnection() {
        try {
            $socket = @fsockopen($this->smtpConfig['host'], $this->smtpConfig['port'], $errno, $errstr, 10);
            if (!$socket) {
                throw new \Exception("Connexion impossible: $errstr ($errno)");
            }
     
            // Authentification SMTP
            $this->smtpCommand($socket, "EHLO example.com", 250);
            $this->smtpCommand($socket, "AUTH LOGIN", 334);
            $this->smtpCommand($socket, base64_encode($this->smtpConfig['user']), 334);
            $this->smtpCommand($socket, base64_encode($this->smtpConfig['pass']), 235);
            
            // Test avec VRFY et RCPT TO pour plus de fiabilité
            $vrfyResult = $this->smtpCommand($socket, "VRFY {$this->email}",[250, 251, 550, 551, 553]);
            $rcptResult = $this->smtpCommand($socket, "RCPT TO:<{$this->email}>",[250, 251, 550, 551, 553]);
            
            $this->smtpCommand($socket, "QUIT", 221);
            fclose($socket);
     
            // Si un des tests indique une erreur, on retourne l'erreur
            if (in_array($vrfyResult['code'],[550, 551, 553]) || in_array($rcptResult['code'],[550, 551, 553])) {
                return [
                    'success' => false,
                    'code' => $rcptResult['code'],
                    'response' => "L'adresse email n'existe pas sur ce domaine"
                ];
            }
            
            return $rcptResult;
     
        } catch (\Exception $e) {
            if (isset($socket)) {
                fclose($socket);
            }
            return [
                'success' => false,
                'code' => 'ERROR',
                'response' => $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie un email via l'API Mailjet en créant une liste
     * * @return array Résultat de l'initialisation de vérification
     */
    public function verifyViaMailjetList() {
        if (!$this->mailjetClient->hasValidCredentials()) {
            return ['success' => false, 'code' => 'CONFIG_ERROR', 'response' => "Clés API manquantes"];
        }

        try {
            // 1. Créer une liste
            $listName = "verify_". md5($this->email. time());
            $listResult = $this->mailjetClient->createList($listName);
            
            if (!$listResult['success']) {
                return [
                    'success' => false, 
                    'code' => 'LIST_ERROR', 
                    'response' => "Erreur lors de la création de la liste: ". ($listResult['message']?? 'Erreur inconnue')
                ];
            }
            
            $listId = $listResult['listId'];
            
            // 2. Ajouter le contact à la liste
            $contactResult = $this->mailjetClient->manageContact($listId, $this->email);
            
            if (!$contactResult['success']) {
                return [
                    'success' => false, 
                    'code' => 'CONTACT_ERROR', 
                    'response' => "Erreur lors de l'ajout du contact: ". ($contactResult['message']?? 'Erreur inconnue')
                ];
            }
            
            // 3. Lancer la vérification
            $verifyResult = $this->mailjetClient->launchVerification($listId);
            
            if (!$verifyResult['success']) {
                return [
                    'success' => false, 
                    'code' => 'VERIFY_ERROR', 
                    'response' => "Erreur lors du lancement de la vérification: ". ($verifyResult['message']?? 'Erreur inconnue')
                ];
            }
            
            $jobId = $verifyResult['jobId'];

            // Retourne les identifiants pour le suivi
            return [
                'success' => true,
                'code' => 'JOB_STARTED',
                'response' => "Job de vérification lancé",
                'listId' => $listId,
                'jobId' => $jobId
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'code' => 'ERROR', 'response' => $e->getMessage()];
        }
    }

    /**
     * Vérifie l'état d'un job de vérification Mailjet
     * * @param string $listId ID de la liste
     * @param string $jobId ID du job
     * @return array État du job
     */
    public function checkVerificationJob($listId, $jobId) {
        try {
            $jobStatus = $this->mailjetClient->checkJobStatus($listId, $jobId);
            
            if (!$jobStatus['success']) {
                return [
                    'success' => false,
                    'code' => 'JOB_STATUS_ERROR',
                    'response' => $jobStatus['message']?? "Erreur lors de la vérification du statut"
                ];
            }
            
            // Si le job est en cours
            if (!$jobStatus['isCompleted'] &&!$jobStatus['isError']) {
                return [
                    'success' => true,
                    'code' => 'JOB_PENDING',
                    'response' => "Le job est toujours en cours",
                    'status' => $jobStatus['status'],
                    'progress' => $jobStatus['data']??''
                ];
            }
            
            // Si le job est en erreur
            if ($jobStatus['isError']) {
                // Nettoyer la liste
                $this->mailjetClient->deleteList($listId);
                
                return [
                    'success' => false,
                    'code' => 'JOB_ERROR',
                    'response' => "Le job a rencontré une erreur: ". ($jobStatus['error']?? 'Erreur inconnue'),
                    'status' => 'Error'
                ];
            }
            
            // Si le job est terminé, analyser les résultats
            $analysisResult = $this->mailjetClient->analyzeEmailVerificationResult($jobStatus);
            
            // Nettoyer la liste
            $this->mailjetClient->deleteList($listId);
            
            if (!$analysisResult['success']) {
                return [
                    'success' => false,
                    'code' => 'ANALYSIS_ERROR',
                    'response' => $analysisResult['message']?? "Impossible d'analyser les résultats",
                    'status' => 'Completed'
                ];
            }
            
            return [
                'success' => true,
                'code' => $analysisResult['isValid']? 'VALID': 'INVALID',
                'response' => $analysisResult['message'],
                'status' => 'Completed',
                'details' => [
                    'result' => $analysisResult['rawResult'],
                    'risk' => $analysisResult['rawRisk']
                ]
            ];
        } catch (\Exception $e) {
            // En cas d'exception, essayer de nettoyer la liste si elle existe
            if (isset($listId)) {
                $this->mailjetClient->deleteList($listId);
            }
            
            return [
                'success' => false,
                'code' => 'ERROR',
                'response' => $e->getMessage(),
                'status' => 'Error'
            ];
        }
    }
    
    /**
     * Récupère le message associé à un code SMTP
     * * @param mixed $code Code SMTP
     * @return string Message descriptif
     */
    public function getSmtpMessage($code) {
        return SmtpCodes::CODES[$code]?? "Code inconnu: $code";
    }
    
    /**
     * Détermine si un code SMTP indique une erreur fatale
     * * @param mixed $code Code SMTP
     * @return bool True si l'erreur est fatale
     */
    public function isSmtpErrorFatal($code) {
        return in_array($code,[]);
    }
    
    /**
     * Log un message (si logger disponible)
     * * @param string $message Message à logger
     */
    private function log($message) {
        if (!$this->logger) {
            error_log("[EmailValidator] ". $message);
        } else {
            $this->logger->debug($message);
        }
    }
}