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
     * @param string $email Adresse email à vérifier
     * @param object|null $logger Logger optionnel
     */
    public function __construct(string $email, $logger = null) {
        // Charger les variables d'environnement si nécessaire
        $this->loadEnvironment();
        
        $this->email = $email;
        $this->logger = $logger;
        $this->smtpConfig = [
            'host' => $_ENV['SMTP_HOST'] ?? 'in-v3.mailjet.com',
            'port' => $_ENV['SMTP_PORT'] ?? 587,
            'user' => $_ENV['SMTP_AUTH_USER'] ?? '',
            'pass' => $_ENV['SMTP_AUTH_PASSWORD'] ?? '',
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
     * Envoie une commande SMTP et lit la réponse
     * @param resource $socket Connexion socket
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
            'success' => is_array($expectedCode) ? in_array($code, $expectedCode) : $code === $expectedCode,
        ];
    }

    /**
     * Active TLS pour une connexion socket de manière plus robuste
     * @param resource $socket Connexion socket
     * @return bool Succès de l'activation
     */
    private function startTLS($socket) {
        // Activer TLS avec gestion d'erreur améliorée
        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        
        // Support de versions TLS plus anciennes/récentes si disponibles
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        
        // Sauvegarde des gestionnaires d'erreurs pour éviter les warnings
        $oldErrorReporting = error_reporting();
        error_reporting(E_ERROR); // Supprimer les warnings
        
        $success = false;
        try {
            $success = stream_socket_enable_crypto($socket, true, $crypto_method);
        } catch (\Exception $e) {
            $this->log("Erreur TLS: " . $e->getMessage());
            $success = false;
        }
        
        // Restaurer le reporting d'erreurs
        error_reporting($oldErrorReporting);
        
        return $success;
    }

    /**
     * Teste la connexion SMTP avec un serveur pour vérifier la validité de l'email
     * Inclut la détection de faux positifs via test DATA
     * 
     * @param string $envelopeDomain Domaine d'enveloppe pour les commandes EHLO
     * @param string $fromDomain Domaine d'expéditeur pour MAIL FROM
     * @return array Résultat détaillé de la vérification SMTP
     */
    public function testSmtpConnection($envelopeDomain = 'bnc3.socirmmo.com', $fromDomain = 'pro.scorimmo.com') {
        try {
            // Établir la connexion au serveur SMTP
            $socket = @fsockopen($this->smtpConfig['host'], $this->smtpConfig['port'], $errno, $errstr, 10);
            if (!$socket) {
                throw new \Exception("Connexion impossible: $errstr ($errno)");
            }

            // Étape 1: Envoyer EHLO et vérifier le support TLS
            $ehloResult = $this->smtpCommand($socket, "EHLO {$envelopeDomain}", 250);
            $supportsTLS = preg_match('/STARTTLS/i', $ehloResult['response']);
            
            // Étape 2: Activer TLS si supporté
            if ($supportsTLS) {
                $startTlsResult = $this->smtpCommand($socket, "STARTTLS", [220, 250, 502]);
                
                if ($startTlsResult['success'] && $startTlsResult['code'] !== 502) {
                    $tlsSuccess = $this->startTLS($socket);
                    
                    if ($tlsSuccess) {
                        $this->smtpCommand($socket, "EHLO {$envelopeDomain}", 250);
                    } else {
                        $this->log("TLS n'a pas pu être activé, continuons sans TLS");
                    }
                }
            }
            
            // Étape 3: Authentification si nécessaire
            if ($this->smtpConfig['user'] && $this->smtpConfig['pass']) {
                $authResult = $this->smtpCommand($socket, "AUTH LOGIN", [334, 504, 535]);
                
                if ($authResult['success'] && $authResult['code'] == 334) {
                    $this->smtpCommand($socket, base64_encode($this->smtpConfig['user']), 334);
                    $this->smtpCommand($socket, base64_encode($this->smtpConfig['pass']), 235);
                } else {
                    $this->log("Authentification non supportée ou refusée par le serveur SMTP");
                }
            }
            
            // Étape 4: Simuler un envoi avec adresse expéditeur
            $fromEmail = "verification@{$fromDomain}";
            $mailFromResult = $this->smtpCommand($socket, "MAIL FROM:<{$fromEmail}>", [250, 251, 550, 553]);
            
            if (!$mailFromResult['success']) {
                $this->log("Le serveur a rejeté notre adresse d'expéditeur: " . $mailFromResult['response']);
                $this->smtpCommand($socket, "QUIT", [221, 250]);
                fclose($socket);
                
                return [
                    'success' => false,
                    'code' => $mailFromResult['code'],
                    'response' => $mailFromResult['response'],
                    'status' => 'sender_rejected',
                    'details' => [
                        'error_type' => 'sender',
                        'error_message' => 'Domaine expéditeur rejeté par le serveur'
                    ]
                ];
            }
            
            // Étape 5: Vérification du destinataire - partie cruciale pour la validité de l'email
            $rcptResult = $this->smtpCommand($socket, "RCPT TO:<{$this->email}>", 
                [250, 251, 450, 451, 452, 550, 551, 552, 553, 554]);
            
            // Étape 6 (optionnelle): Test DATA pour détecter les faux positifs
            $dataTestSuccess = false;
            $dataResponse = '';
            $dataCode = 0;
            
            if ($rcptResult['success'] && ($rcptResult['code'] == 250 || $rcptResult['code'] == 251)) {
                $dataResult = $this->smtpCommand($socket, "DATA", [354, 450, 451, 452, 503, 550, 551, 552, 553, 554]);
                $dataTestSuccess = $dataResult['success'] && $dataResult['code'] == 354;
                $dataResponse = $dataResult['response'];
                $dataCode = $dataResult['code'];
                
                // Si DATA est accepté, annuler la transaction pour ne pas envoyer réellement
                if ($dataTestSuccess) {
                    $this->smtpCommand($socket, "RSET", [250, 251, 500, 501, 502, 503]);
                }
            }
            
            // Étape 7: Terminer proprement la connexion
            $this->smtpCommand($socket, "QUIT", [221, 250]);
            fclose($socket);
            
            // Analyse des résultats incluant le test DATA
            $analysisResult = $this->analyzeSmtpResponseImproved($rcptResult, $dataTestSuccess, $dataResponse, $dataCode);
            
            return $analysisResult;
        } catch (\Exception $e) {
            // Nettoyage en cas d'erreur
            if (isset($socket) && is_resource($socket)) {
                @$this->smtpCommand($socket, "QUIT", [221, 250, 500, 501, 502, 503]);
                @fclose($socket);
            }
            
            $this->log("Exception dans testSmtpConnection: " . $e->getMessage());
            
            return [
                'success' => false,
                'code' => 'ERROR',
                'response' => $e->getMessage(),
                'status' => 'connection_error',
                'details' => [
                    'error_type' => 'connection',
                    'error_message' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Analyse les réponses SMTP pour détecter les faux positifs et autres problèmes
     * 
     * @param array $rcptResult Résultat de la commande RCPT TO
     * @param bool $dataTestSuccess Succès du test DATA
     * @param string $dataResponse Réponse du test DATA
     * @param int $dataCode Code du test DATA
     * @return array Analyse détaillée des résultats
     */
    private function analyzeSmtpResponseImproved($rcptResult, $dataTestSuccess, $dataResponse = '', $dataCode = 0) {
        $code = $rcptResult['code'];
        $response = $rcptResult['response'];
        $lowerResponse = strtolower($response);
        
        // Extraire les codes étendus si présents (comme 5.1.1)
        $extendedCode = '';
        if (preg_match('/\d\.\d\.\d/', $response, $matches)) {
            $extendedCode = $matches[0];
        }
        
        // Structure de base du résultat
        $result = [
            'success' => $rcptResult['success'],
            'code' => $code,
            'extended_code' => $extendedCode,
            'response' => $response,
            'status' => 'unknown',
            'details' => [
                'response_text' => $response,
                'is_temporary' => false,
                'needs_retry' => false,
                'probable_cause' => 'unknown',
                'data_test_success' => $dataTestSuccess,
                'data_response' => $dataResponse,
                'data_code' => $dataCode,
                'confidence_level' => 'medium'
            ]
        ];
        
        // Patterns pour l'analyse des réponses textuelles
        $creationPatterns = [
            'in process of being created', 'mailbox unavailable', 
            'temporairement indisponible', 'try again later',
            'temporarily unavailable', 'deferred', 'try later',
            'not available', 'greylisted', 'please try again'
        ];
        
        $hardBouncePatterns = [
            'blocked', 'administratively disabled', 'policy rejection',
            'rejected', 'denied', 'refused', 'blacklisted',
            'spam', 'blackholed', 'listed', 'filtered'
        ];
        
        $suspiciousPatterns = [
            'verify', 'verification', 'confirm', 'confirmation',
            'temporary', 'pending', 'review', 'monitored',
            'under observation', 'filtered', 'delayed'
        ];
        
        // Analyse basée sur le code de réponse SMTP
        if ($code == 250 || $code == 251) {
            // Codes de succès, mais vérifier les faux positifs
            $result['status'] = 'valid';
            $result['details']['probable_cause'] = 'address_exists';
            
            // Vérifier si la réponse contient des termes suspects malgré le code 250
            foreach ($suspiciousPatterns as $pattern) {
                if (strpos($lowerResponse, $pattern) !== false) {
                    $result['details']['suspicious_response'] = true;
                    $result['details']['confidence_level'] = 'low';
                    $result['details']['warning'] = "Réponse suspecte malgré le code 250: Contient '$pattern'";
                    break;
                }
            }
            
            // Si RCPT TO est accepté mais DATA est rejeté, possible faux positif
            if (!$dataTestSuccess && !empty($dataResponse)) {
                $result['details']['suspicious_response'] = true;
                $result['details']['confidence_level'] = 'very_low';
                $result['details']['warning'] = "Le serveur a accepté l'adresse mais a rejeté à l'étape DATA";
                
                // Vérifier si le rejet DATA contient des indices de hard bounce
                foreach ($hardBouncePatterns as $pattern) {
                    if (strpos(strtolower($dataResponse), $pattern) !== false) {
                        $result['success'] = false;
                        $result['status'] = 'potential_hard_bounce';
                        $result['details']['probable_cause'] = 'sender_rejected_at_data';
                        $result['details']['warning'] = "Rejet probable à l'étape DATA: Contient '$pattern'";
                        break;
                    }
                }
            } else if ($dataTestSuccess) {
                // Si le test DATA réussit, augmenter la confiance
                $result['details']['confidence_level'] = 'high';
            }
        } 
        else if ($code == 450 || $code == 451 || $code == 452) {
            // Erreurs temporaires
            $result['status'] = 'temporary_error';
            $result['success'] = false;
            $result['details']['is_temporary'] = true;
            $result['details']['needs_retry'] = true;
            
            // Vérifier si c'est une boîte en cours de création
            foreach ($creationPatterns as $pattern) {
                if (strpos($lowerResponse, $pattern) !== false) {
                    $result['details']['probable_cause'] = 'mailbox_being_created';
                    break;
                }
            }
            
            if ($result['details']['probable_cause'] == 'unknown') {
                $result['details']['probable_cause'] = 'mailbox_temporary_issue';
            }
        } 
        else if ($code == 550 || $code == 551 || $code == 553) {
            // Erreurs permanentes - email invalide
            $result['status'] = 'invalid';
            $result['success'] = false;
            
            // 5.1.1 indique généralement une boîte mail inexistante
            if (strpos($extendedCode, '5.1.1') !== false) {
                $result['details']['probable_cause'] = 'mailbox_not_found';
            } else {
                // Vérifier si c'est un rejet dû à une politique
                foreach ($hardBouncePatterns as $pattern) {
                    if (strpos($lowerResponse, $pattern) !== false) {
                        $result['details']['probable_cause'] = 'sender_rejected';
                        break;
                    }
                }
                
                // Vérifier si c'est une boîte pleine
                if ($result['details']['probable_cause'] == 'unknown') {
                    if (strpos($lowerResponse, 'quota') !== false || strpos($lowerResponse, 'full') !== false) {
                        $result['details']['probable_cause'] = 'mailbox_full';
                    } else {
                        $result['details']['probable_cause'] = 'address_invalid';
                    }
                }
            }
        } 
        else if ($code == 501) {
            // Erreur de format d'adresse
            $result['status'] = 'invalid';
            $result['success'] = false;
            $result['details']['probable_cause'] = 'invalid_email_format';
        } 
        else if ($code == 554) {
            // Rejet de politique
            $result['status'] = 'rejected';
            $result['success'] = false;
            $result['details']['probable_cause'] = 'policy_rejection';
        } 
        else {
            // Autres codes d'erreur non spécifiques
            $result['status'] = 'unknown_error';
            $result['success'] = false;
            $result['details']['probable_cause'] = 'unknown';
        }
        
        return $result;
    }

    /**
     * Vérifie un email via l'API Mailjet en créant une liste
     * @return array Résultat de l'initialisation de vérification
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
                    'response' => "Erreur lors de la création de la liste: ". ($listResult['message'] ?? 'Erreur inconnue')
                ];
            }
            
            $listId = $listResult['listId'];
            
            // 2. Ajouter le contact à la liste
            $contactResult = $this->mailjetClient->manageContact($listId, $this->email);
            
            if (!$contactResult['success']) {
                return [
                    'success' => false, 
                    'code' => 'CONTACT_ERROR', 
                    'response' => "Erreur lors de l'ajout du contact: ". ($contactResult['message'] ?? 'Erreur inconnue')
                ];
            }
            
            // 3. Lancer la vérification
            $verifyResult = $this->mailjetClient->launchVerification($listId);
            
            if (!$verifyResult['success']) {
                return [
                    'success' => false, 
                    'code' => 'VERIFY_ERROR', 
                    'response' => "Erreur lors du lancement de la vérification: ". ($verifyResult['message'] ?? 'Erreur inconnue')
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
     * @param string $listId ID de la liste
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
                    'response' => $jobStatus['message'] ?? "Erreur lors de la vérification du statut"
                ];
            }
            
            // Si le job est en cours
            if (!$jobStatus['isCompleted'] && !$jobStatus['isError']) {
                return [
                    'success' => true,
                    'code' => 'JOB_PENDING',
                    'response' => "Le job est toujours en cours",
                    'status' => $jobStatus['status'],
                    'progress' => $jobStatus['data'] ?? ''
                ];
            }
            
            // Si le job est en erreur
            if ($jobStatus['isError']) {
                // Nettoyer la liste
                $this->mailjetClient->deleteList($listId);
                
                return [
                    'success' => false,
                    'code' => 'JOB_ERROR',
                    'response' => "Le job a rencontré une erreur: ". ($jobStatus['error'] ?? 'Erreur inconnue'),
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
                    'response' => $analysisResult['message'] ?? "Impossible d'analyser les résultats",
                    'status' => 'Completed'
                ];
            }
            
            return [
                'success' => true,
                'code' => $analysisResult['isValid'] ? 'VALID' : 'INVALID',
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
     * @param mixed $code Code SMTP
     * @return string Message descriptif
     */
    public function getSmtpMessage($code) {
        return SmtpCodes::CODES[$code] ?? "Code inconnu: $code";
    }
    
    /**
     * Détermine si un code SMTP indique une erreur fatale
     * @param mixed $code Code SMTP
     * @return bool True si l'erreur est fatale
     */
    public function isSmtpErrorFatal($code) {
        // Codes d'erreur considérés comme fatals (non récupérables)
        $fatalCodes = [
            501, // Erreur de syntaxe dans les paramètres
            550, // Boîte mail inexistante
            551, // Utilisateur non local
            553, // Nom de boîte invalide
            554  // Transaction échouée
        ];
        
        return in_array($code, $fatalCodes);
    }
    
    /**
     * Log un message (si logger disponible)
     * @param string $message Message à logger
     */
    private function log($message) {
        if (!$this->logger) {
            error_log("[EmailValidator] ". $message);
        } else {
            $this->logger->debug($message);
        }
    }
}