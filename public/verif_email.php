<?php
/**
 * verif_email.php - Script de vérification d'email avec Server-Sent Events
 * 
 * Ce script permet de vérifier la validité d'une adresse email en utilisant
 * plusieurs méthodes (format, DNS MX, SMTP, Mailjet) et de communiquer
 * les résultats en temps réel au client via Server-Sent Events.
 */

// Configuration initiale du script
set_time_limit(300); // 5 minutes
ignore_user_abort(true);

// Chargement des dépendances
require_once __DIR__. '/../vendor/autoload.php';
require_once __DIR__. '/../src/Email/Validator/EmailValidator.php';
require_once __DIR__. '/../src/Mailjet/MailjetClient.php';
require_once __DIR__. '/../src/Config/SmtpCodes.php';
require_once __DIR__. '/../src/Config/MailjetCodes.php';

use App\Email\Validator\EmailValidator;
use App\Config\SmtpCodes;
use App\Config\MailjetCodes;

/**
 * Vérifie si un email a été vérifié dans l'heure précédente
 * 
 * @param string $email L'adresse email à vérifier
 * @return array|null Le résultat du cache, ou null si non trouvé
 */
function checkCache($email)
{
    $cacheFile = __DIR__. '/email_cache.json';
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        $emailHash = md5($email);
        if (isset($cacheData[$emailHash]) && time() - $cacheData[$emailHash]['timestamp'] < 600) {
            return $cacheData[$emailHash]['result'];
        }
    }
    return null;
}

/**
 * Enregistre le résultat de la vérification dans le cache
 * 
 * @param string $email L'adresse email vérifiée
 * @param array $result Le résultat de la vérification
 */
function saveCache($email, $result) {
    $cacheFile = __DIR__. '/email_cache.json';
    $emailHash = md5($email);
    $cacheData = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
    $cacheData[$emailHash] = [
        'timestamp' => time(),
        'result' => $result
    ];
    file_put_contents($cacheFile, json_encode($cacheData));
}

/**
 * Envoie un événement SSE au client
 * 
 * @param string $event Nom de l'événement
 * @param array $data Données à envoyer (sera encodé en JSON)
 */
function sendEvent($event, $data) {
    echo "event: " . htmlspecialchars($event) . "\n";
    echo "data: " . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "\n\n";
    ob_flush();
    flush();
}

/**
 * Configure les en-têtes HTTP pour SSE
 */
function setupSseHeaders()
{
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
}

/**
 * Gère la vérification d'email en mode SSE
 * 
 * @param string $email L'adresse email à vérifier
 */
function handleEmailVerification($email)
{
    // Validation stricte de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendEvent('error', [
            'message' => 'Format d\'email invalide'
        ]);
        exit;
    }
    
    // Limiter la longueur de l'email
    if (strlen($email) > 254) {
        sendEvent('error', [
            'message' => 'Email trop long'
        ]);
        exit;
    }
    
    // Protection contre les injections CRLF
    if (preg_match('/[\r\n]/', $email)) {
        sendEvent('error', [
            'message' => 'Caractères non autorisés dans l\'email'
        ]);
        exit;
    }

    // Vérifier le cache
    $cachedResult = checkCache($email);
    if ($cachedResult !== null) {
        sendEvent('result', $cachedResult);
        exit;
    }

    try {
        // Créer une instance du validateur
        $validator = new EmailValidator($email);

        // 1. Vérification du format
        if (!verifyEmailFormat($email)) {
            return;
        }

        // 2. Vérification des MX records
        if (!verifyDomainMX($email)) {
            return;
        }

        // 3. Test SMTP
        $smtpResult = verifySmtpConnection($validator);
        // Si erreur SMTP fatale, on arrête
        if (isset($smtpResult['fatal']) && $smtpResult['fatal']) {
            return;
        }

        // 4. Vérification via Mailjet (optionnelle)
        $useMailjet = isset($_GET['mailjet']) && $_GET['mailjet'] === '1';
        if ($useMailjet) {
            verifyViaMailjet($validator);
        } else {
            // Si on n'utilise pas Mailjet, envoyer le résultat final basé sur les étapes précédentes
            $finalResult = [
                'success' => true,
                'message' => 'Adresse email valide (vérifications de base complétées)',
                'details' => [
                    'basic_validation' => true,
                    'mailjet_skipped' => true
                ]
            ];
            
            sendEvent('result', $finalResult);
            saveCache($_GET['email'], $finalResult);
        }
    } catch (Exception $e) {
        sendEvent('error', [
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Vérifie le format de l'email
 * 
 * @param string $email L'adresse email à vérifier
 * @return bool Si la vérification a réussi
 */
function verifyEmailFormat($email)
{
    try {
        // Notifier le début de la vérification
        sendEvent('step', [
            'message' => 'Vérification du format...',
            'success' => null
        ]);
        
        $isValidFormat = filter_var($email, FILTER_VALIDATE_EMAIL);

        if (!$isValidFormat) {
            // Notifier l'échec de la vérification
            sendEvent('step', [
                'message' => 'Vérification du format...',
                'success' => false
            ]);
            
            // Envoyer le résultat final
            sendEvent('result', [
                'success' => false,
                'message' => "Format d'email invalide"
            ]);
            
            return false;
        }

        // Notifier le succès de la vérification
        sendEvent('step', [
            'message' => 'Vérification du format...',
            'success' => true
        ]);
        
        return true;
    } catch (Exception $e) {
        // Gérer les erreurs
        sendEvent('step', [
            'message' => 'Vérification du format...',
            'success' => false
        ]);
        
        sendEvent('error', [
            'message' => "Erreur lors de la vérification du format: ". $e->getMessage()
        ]);
        
        return false;
    }
}

/**
 * Vérifie les enregistrements MX du domaine de façon robuste
 * 
 * @param string $email L'adresse email à vérifier
 * @return bool Si la vérification a réussi
 */
function verifyDomainMX($email)
{
    try {
        // Extraire le domaine
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            sendEvent('step', [
                'message' => 'Vérification des serveurs mail...',
                'success' => false
            ]);
            
            sendEvent('error', [
                'message' => 'Format d\'email invalide',
                'errorMessage' => "Impossible d'extraire le domaine de l'email"
            ]);
            
            return false;
        }
        
        $domain = trim($parts[1]);
        
        // Vérifier que le domaine n'est pas vide
        if (empty($domain)) {
            sendEvent('step', [
                'message' => 'Vérification des serveurs mail...',
                'success' => false
            ]);
            
            sendEvent('error', [
                'message' => 'Domaine vide',
                'errorMessage' => "Le domaine ne peut pas être vide"
            ]);
            
            return false;
        }
        
        // Notifier le début de la vérification
        sendEvent('step', [
            'message' => 'Vérification des serveurs mail...',
            'success' => null
        ]);

        // Variables pour stocker les résultats
        $mxhosts = [];
        $mxweights = [];
        $hasMX = false;
        $hasA = false;
        
        // 1. Vérifier les enregistrements MX
        $hasMX = getmxrr($domain, $mxhosts, $mxweights);
        
        // 2. Si pas de MX, vérifier l'enregistrement A (fallback RFC)
        if (!$hasMX) {
            // Selon la RFC 5321, si pas de MX, on doit essayer l'enregistrement A
            $aRecord = gethostbyname($domain);
            if ($aRecord !== $domain && filter_var($aRecord, FILTER_VALIDATE_IP)) {
                $hasA = true;
                $mxhosts[] = $domain; // Le domaine lui-même comme serveur mail
                
                sendEvent('step', [
                    'success' => true,
                    'message' => 'Aucun enregistrement MX trouvé, mais enregistrement A disponible: ' . $aRecord
                ]);
            }
        }
        
        // 3. Vérifier aussi les enregistrements AAAA (IPv6) si nécessaire
        if (!$hasMX && !$hasA) {
            // Utiliser dns_get_record pour une vérification plus complète
            $records = @dns_get_record($domain, DNS_A + DNS_AAAA + DNS_MX);
            
            if ($records !== false) {
                foreach ($records as $record) {
                    if ($record['type'] === 'MX') {
                        $hasMX = true;
                        $mxhosts[] = $record['target'];
                        break;
                    } elseif ($record['type'] === 'A' || $record['type'] === 'AAAA') {
                        $hasA = true;
                        $mxhosts[] = $domain;
                        break;
                    }
                }
            }
        }
        
        // Si aucun enregistrement trouvé
        if (!$hasMX && !$hasA) {
            sendEvent('step', [
                'message' => 'Vérification des serveurs mail...',
                'success' => false
            ]);
            
            sendEvent('error', [
                'message' => 'Domaine invalide: ' . $domain,
                'errorMessage' => "Aucun serveur mail configuré pour ce domaine (pas d'enregistrements MX, A ou AAAA)"
            ]);
            
            return false;
        }

        // Notifier le succès de la vérification
        sendEvent('step', [
            'message' => 'Vérification des serveurs mail...',
            'success' => true
        ]);
        
        // Trier les serveurs MX par priorité si on a les poids
        if ($hasMX && !empty($mxweights)) {
            array_multisort($mxweights, SORT_ASC, $mxhosts);
            
            // Créer un message détaillé avec les priorités
            $mxDetails = [];
            for ($i = 0; $i < count($mxhosts); $i++) {
                $priority = isset($mxweights[$i]) ? $mxweights[$i] : 'N/A';
                $mxDetails[] = $mxhosts[$i] . ' (priorité: ' . $priority . ')';
            }
            
            sendEvent('step', [
                'success' => true,
                'message' => 'Serveurs mail trouvés: ' . implode(', ', $mxDetails)
            ]);
        } else {
            // Message simple pour les enregistrements A
            sendEvent('step', [
                'success' => true,
                'message' => 'Serveurs mail trouvés: ' . implode(', ', $mxhosts)
            ]);
        }

        // Validation supplémentaire: vérifier que les serveurs MX sont accessibles
        $validMxCount = 0;
        foreach ($mxhosts as $mxhost) {
            // Test rapide de résolution DNS du serveur MX
            $mxIp = gethostbyname($mxhost);
            if ($mxIp !== $mxhost && filter_var($mxIp, FILTER_VALIDATE_IP)) {
                $validMxCount++;
            }
        }
        
        if ($validMxCount === 0) {
            sendEvent('step', [
                'success' => false,
                'message' => 'Attention: Les serveurs mail trouvés ne semblent pas accessibles'
            ]);
        } else {
            sendEvent('step', [
                'success' => true,
                'message' => $validMxCount . ' serveur(s) mail accessible(s) sur ' . count($mxhosts)
            ]);
        }

        return true;
        
    } catch (Exception $e) {
        // Gérer les erreurs
        sendEvent('step', [
            'message' => 'Vérification des serveurs mail...',
            'success' => false
        ]);
        
        sendEvent('error', [
            'message' => 'Erreur lors de la vérification des serveurs mail',
            'errorMessage' => $e->getMessage()
        ]);
        
        return false;
    }
}

/**
 * Vérifie l'email via une connexion SMTP
 * 
 * @param EmailValidator $validator Instance du validateur d'email
 * @return array Résultat de la vérification
 */
function verifySmtpConnection($validator)
{
    try {
        // Notifier le début de la vérification
        sendEvent('step', [
            'message' => 'Test de l\'adresse email via SMTP...',
            'success' => null
        ]);
        
        // Effectuer la vérification SMTP
        $smtpResult = $validator->testSmtpConnection();

        if ($smtpResult['success']) {
            // Notifier le succès de la vérification
            sendEvent('step', [
                'message' => 'Test de l\'adresse email via SMTP...',
                'success' => true
            ]);
            
            // Transmettre toutes les informations améliorées
            sendEvent('smtp_result', [
                'success' => true,
                'message' => $validator->getSmtpMessage($smtpResult['code']),
                'code' => $smtpResult['code'],
                'extended_code' => $smtpResult['extended_code'] ?? null,
                'response' => $smtpResult['response'],
                'details' => $smtpResult['details'] ?? []
            ]);
        } else {
            // Notifier l'échec de la vérification
            sendEvent('step', [
                'message' => 'Test de l\'adresse email via SMTP...',
                'success' => false
            ]);
            
            $message = $validator->getSmtpMessage($smtpResult['code']);
            
            // Transmettre toutes les informations améliorées
            sendEvent('smtp_result', [
                'success' => false,
                'message' => $message,
                'code' => $smtpResult['code'],
                'extended_code' => $smtpResult['extended_code'] ?? null,
                'response' => $smtpResult['response'],
                'details' => $smtpResult['details'] ?? []
            ]);

            // Si l'erreur SMTP est fatale, on arrête le processus
            if ($validator->isSmtpErrorFatal($smtpResult['code'])) {
                sendEvent('result', [
                    'success' => false,
                    'message' => $message,
                    'details' => [
                        'code' => $smtpResult['code'],
                        'response' => $smtpResult['response']
                    ]
                ]);
                
                return [
                    'success' => false,
                    'fatal' => true
                ];
            }
        }

        return [
            'success' => $smtpResult['success'],
            'fatal' => false
        ];
    } catch (Exception $e) {
        // Gérer les erreurs
        sendEvent('step', [
            'message' => 'Test de l\'adresse email via SMTP...',
            'success' => false
        ]);
        
        sendEvent('error', [
            'message' => 'Test de l\'adresse email via SMTP...',
            'errorMessage' => "Erreur lors du test SMTP: ". $e->getMessage()
        ]);
        
        return [
            'success' => false,
            'fatal' => false
        ];
    }
}

/**
 * Vérifie l'email via l'API Mailjet
 * 
 * @param EmailValidator $validator Instance du validateur d'email
 * @return array Résultat de la vérification
 */
function verifyViaMailjet($validator)
{
    try {
        // Notifier le début de la vérification
        sendEvent('step', [
            'message' => 'Vérification avancée Mailjet...',
            'success' => null
        ]);
        
        // Lancer la vérification via Mailjet
        $result = $validator->verifyViaMailjetList();

        if (!$result['success']) {
            // Notifier l'échec du lancement de la vérification
            sendEvent('step', [
                'message' => 'Vérification avancée Mailjet...',
                'success' => false
            ]);
            
            sendEvent('error', [
                'message' => 'Échec du lancement de la vérification',
                'errorMessage' => $result['response'],
                'details' => [
                    'code' => $result['code'],
                    'response' => $result['response']
                ]
            ]);
            
            return [
                'success' => false
            ];
        }

        // Récupération des IDs pour le suivi
        $listId = $result['listId'];
        $jobId = $result['jobId'];

        // Surveiller l'état du job de vérification
        monitorMailjetJob($validator, $listId, $jobId);
        
        return [
            'success' => true
        ];
    } catch (Exception $e) {
        // Gérer les erreurs
        sendEvent('step', [
            'message' => 'Vérification avancée Mailjet...',
            'success' => false
        ]);
        
        sendEvent('error', [
            'message' => 'Vérification avancée Mailjet...',
            'errorMessage' => $e->getMessage()
        ]);
        
        return [
            'success' => false
        ];
    }
}

/**
 * Surveille l'état du job Mailjet
 * 
 * @param EmailValidator $validator Instance du validateur d'email
 * @param string $listId ID de la liste Mailjet
 * @param string $jobId ID du job Mailjet
 */
function monitorMailjetJob($validator, $listId, $jobId)
{
    // Configuration du polling
    $maxAttempts = 120; // ~2 minutes maximum
    $attempts = 0;
    $jobCompleted = false;

    // Première attente pour laisser le temps à Mailjet de démarrer le job
    sleep(30);

    // Boucle de vérification périodique du job
    while ($attempts < $maxAttempts && !$jobCompleted) {
        // Vérifier l'état du job
        $jobResult = $validator->checkVerificationJob($listId, $jobId);
        $attempts++;

        // Informer le client de la progression
        sendEvent('job_status', [
            'attempt' => $attempts,
            'status' => $jobResult['status'] ?? 'Checking',
            'progress' => isset($jobResult['progress']) ? $jobResult['progress'] : null
        ]);

        // Si le job est terminé ou en erreur
        if (isset($jobResult['status']) && ($jobResult['status'] === 'Completed' || $jobResult['status'] === 'Error')) {
            $jobCompleted = true;

            if ($jobResult['status'] === 'Completed') {
                // Traiter le résultat du job terminé
                processCompletedMailjetJob($jobResult);
            } else {
                // Notifier l'erreur du job
                sendEvent('step', [
                    'message' => 'Vérification avancée Mailjet...',
                    'success' => false
                ]);
                
                sendEvent('error', [
                    'message' => 'Échec de la vérification',
                    'errorMessage' => $jobResult['response']
                ]);
            }
            return;
        }

        // Attendre avant la prochaine vérification si le job n'est pas terminé
        if (!$jobCompleted && $attempts < $maxAttempts) {
            sleep(5);
            
            // Maintenir la connexion active
            sendEvent('heartbeat', [
                'time' => time(),
                'attempt' => $attempts
            ]);
        }
    }

    // Timeout - le job n'a pas été complété dans le temps imparti
    if (!$jobCompleted) {
        sendEvent('step', [
            'message' => 'Vérification avancée Mailjet...',
            'success' => false
        ]);
        
        sendEvent('error', [
            'message' => 'Délai d\'attente dépassé',
            'errorMessage' => "La vérification prend trop de temps, veuillez réessayer plus tard"
        ]);
    }
}

/**
 * Traite le résultat d'un job Mailjet terminé
 * 
 * @param array $jobResult Données du job terminé
 */
function processCompletedMailjetJob($jobResult)
{
    // Extraire les données de résultat
    $details = $jobResult['details'] ?? '';
    $result = $details['result'] ?? '';
    $risk = $details['risk'] ?? '';

    // Informer de la réussite de l'étape
    sendEvent('step', [
        'message' => 'Vérification avancée Mailjet...',
        'success' => $jobResult['code'] === 'VALID',
        'details' => [
            'result' => $result,
            'risk' => $risk
        ]
    ]);

    // Préparer et envoyer le résultat final
    $finalResult = [
        'success' => $jobResult['code'] === 'VALID',
        'message' => $jobResult['response'],
        'details' => [
            'code' => $jobResult['code'] ?? 'UNKNOWN',
            'response' => $jobResult['response'] ?? '',
            'result' => $result,
            'risk' => $risk
        ]
    ];
    
    sendEvent('result', $finalResult);

    // Enregistrer le résultat final dans le cache
    saveCache($_GET['email'], $finalResult);
}

// ======================================================
// LOGIQUE PRINCIPALE DU SCRIPT
// ======================================================

// Mode SSE (Server-Sent Events)
if (isset($_GET['stream'])) {
    setupSseHeaders();
    $email = $_GET['email'] ?? '';

    if (empty($email)) {
        sendEvent('error', [
            'message' => 'Email manquant'
        ]);
        exit;
    }

    handleEmailVerification($email);
    exit;
}

// Rendu HTML normal si pas en mode stream
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vérification d'Email</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h1 class="h4 mb-0">Vérificateur d'Email</h1>
                    </div>
                    <div class="card-body">
                        <form id="emailForm" method="POST">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label for="email" class="form-label">Adresse Email</label>
                                    <input type="text" class="form-control" id="email" name="email" required>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="" id="use-mailjet" checked>
                                    <label class="form-check-label" for="use-mailjet">
                                        Utiliser la vérification avancée Mailjet
                                    </label>
                                    <div class="form-text">
                                        La vérification Mailjet fournit des résultats plus précis mais prend plus de temps.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Vérifier
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <div id="results" class="mt-4"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Injection des constantes depuis le serveur -->
    <script>
    // Constantes pour les descriptions de statut email
    const EMAIL_STATUS_DESCRIPTIONS = <?= json_encode([
        'Délivrable' => MailjetCodes::RESULT_CODES['deliverable'],
        'Catch-all' => MailjetCodes::RESULT_CODES['catch_all'],
        'Non délivrable' => MailjetCodes::RESULT_CODES['undeliverable'],
        'Ne pas envoyer' => MailjetCodes::RESULT_CODES['do_not_send'],
        'Inconnu' => MailjetCodes::RESULT_CODES['unknown']
    ]) ?>;

    // Constantes pour les descriptions de niveau de risque
    const RISK_LEVEL_DESCRIPTIONS = <?= json_encode([
        'Faible' => MailjetCodes::RISK_LEVELS['low'],
        'Moyen' => MailjetCodes::RISK_LEVELS['medium'],
        'Élevé' => MailjetCodes::RISK_LEVELS['high'],
        'Inconnu' => MailjetCodes::RISK_LEVELS['unknown']
    ]) ?>;

    // Constantes pour les explications combinées
    const COMBINED_EXPLANATIONS = <?= json_encode(MailjetCodes::COMBINED_EXPLANATIONS) ?>;

    // Constantes pour les codes SMTP
    const SMTP_CODE_EXPLANATIONS = <?= json_encode(SmtpCodes::CODES) ?>;
    </script>
    
    <script src="js/email_validator.js"></script>
</body>
</html>