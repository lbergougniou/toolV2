<?php
/**
 * verif_email.php - Script de vérification d'email avec Server-Sent Events
 **/

// Configuration initiale du script
set_time_limit(300); // 5 minutes
ignore_user_abort(true);

// Chargement des dépendances
require_once __DIR__. '/../vendor/autoload.php';
require_once __DIR__. '/../src/Email/Validator/EmailValidator.php';
require_once __DIR__. '/../src/Mailjet/MailjetClient.php';

use App\Email\Validator\EmailValidator;

/**
 * Vérifie si un email a été vérifié dans l'heure précédente
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
 * @param string $email L'adresse email vérifiée
 * @param array $result Le résultat de la vérification
 */
function saveCache($email, $result) {
    $cacheFile = __DIR__. '/email_cache.json';
    $emailHash = md5($email);
    $cacheData = file_exists($cacheFile)? json_decode(file_get_contents($cacheFile), true):[];
    $cacheData[$emailHash] = [
        'timestamp' => time(),
        'result' => $result
    ];
    file_put_contents($cacheFile, json_encode($cacheData));
}
/**
 * Envoie un événement SSE au client
 * @param string $event Nom de l'événement
 * @param array $data Données à envoyer (sera encodé en JSON)
 */
function sendEvent($event, $data)
{
    echo "event: $event\n";
    echo "data: ". json_encode($data). "\n\n";
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
 * @param string $email L'adresse email à vérifier
 */
function handleEmailVerification($email)
{
    // Vérifier le cache
    $cachedResult = checkCache($email);
    if ($cachedResult!== null) {
        sendEvent('result', $cachedResult);
        exit;
    }

    try {
        // Créer une instance du validateur
        $validator = new EmailValidator($email);

        // 1. Vérification du format
        if (!verifyEmailFormat($email))
            return;

        // 2. Vérification des MX records
        if (!verifyDomainMX($email))
            return;

        // 3. Test SMTP
        $smtpResult = verifySmtpConnection($validator);
        // Si erreur SMTP fatale, on arrête
        if (isset($smtpResult['fatal']) && $smtpResult['fatal'])
            return;

        // 4. Vérification via Mailjet
        verifyViaMailjet($validator);
    } catch (Exception $e) {
        sendEvent('error', [
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Vérifie le format de l'email
 * @param string $email L'adresse email à vérifier
 * @return bool Si la vérification a réussi
 */
function verifyEmailFormat($email)
{
    try {
        sendEvent('step', [
            'message' => 'Vérification du format...',
            'success' => null
        ]);
        $isValidFormat = filter_var($email, FILTER_VALIDATE_EMAIL);

        if (! $isValidFormat) {
            sendEvent('step', [
                'message' => 'Vérification du format...',
                'success' => false
            ]);
            sendEvent('result', [
                'success' => false,
                'message' => "Format d'email invalide"
            ]);
            return false;
        }

        sendEvent('step', [
            'message' => 'Vérification du format...',
            'success' => true
        ]);
        return true;
    } catch (Exception $e) {
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
 * Vérifie les enregistrements MX du domaine
 * @param string $email L'adresse email à vérifier
 * @return bool Si la vérification a réussi
 */
function verifyDomainMX($email)
{
    try {
        list(, $domain) = explode('@', $email);
        sendEvent('step', [
            'message' => 'Vérification des serveurs mail...',
            'success' => null
        ]);

        if (! getmxrr($domain, $mxhosts)) {
            sendEvent('step', [
                'message' => 'Vérification des serveurs mail...',
                'success' => false
            ]);
            sendEvent('error', [
                'message' => 'Domaine invalide: '. $domain,
                'errorMessage' => "Aucun serveur mail configuré pour ce domaine"
            ]);
            return false;
        }

        sendEvent('step', [
            'message' => 'Vérification des serveurs mail...',
            'success' => true
        ]);
        sendEvent('step', [
            'success' => true,
            'message' => 'Serveurs mail trouvés: '. implode(', ', $mxhosts)
        ]);

        return true;
    } catch (Exception $e) {
        sendEvent('step', [
            'message' => 'Vérification des serveurs mail...',
            'success' => false
        ]);
        sendEvent('error', [
            'message' => 'Vérification des serveurs mail...',
            'errorMessage' => "Erreur lors de la vérification des serveurs mail: ". $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Vérifie l'email via une connexion SMTP
 * @param EmailValidator $validator Instance du validateur d'email
 * @return array Résultat de la vérification
 */
function verifySmtpConnection($validator)
{
    try {
        sendEvent('step', [
            'message' => 'Test de l\'adresse email via SMTP...',
            'success' => null
        ]);
        $smtpResult = $validator->testSmtpConnection();

        if ($smtpResult['success']) {
            sendEvent('step', [
                'message' => 'Test de l\'adresse email via SMTP...',
                'success' => true
            ]);
            sendEvent('smtp_result', [
                'success' => true,
                'message' => $validator->getSmtpMessage($smtpResult['code']),
                'details' => [
                    'code' => $smtpResult['code'],
                    'response' => $smtpResult['response']
                ]
            ]);
        } else {
            sendEvent('step', [
                'message' => 'Test de l\'adresse email via SMTP...',
                'success' => false
            ]);
            $message = $validator->getSmtpMessage($smtpResult['code']);
            sendEvent('smtp_result', [
                'success' => false,
                'message' => $message,
                'details' => [
                    'code' => $smtpResult['code'],
                    'response' => $smtpResult['response']
                ]
            ]);

            // Si erreur permanente, on arrête la vérification
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
 * @param EmailValidator $validator Instance du validateur d'email
 * @return array Résultat de la vérification
 */
function verifyViaMailjet($validator)
{
    try {
        sendEvent('step', [
            'message' => 'Vérification avancée Mailjet...',
            'success' => null
        ]);
        $result = $validator->verifyViaMailjetList();

        if (! $result['success']) {
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

        monitorMailjetJob($validator, $listId, $jobId);
        return [
            'success' => true
        ];
    } catch (Exception $e) {
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

    // Première attente de 30 secondes
    sleep(20);

    while ($attempts < $maxAttempts &&! $jobCompleted) {
        // Vérifier l'état du job
        $jobResult = $validator->checkVerificationJob($listId, $jobId);
        $attempts ++;

        // Informer le client de la progression
        sendEvent('job_status', [
            'attempt' => $attempts,
            'status' => $jobResult['status']?? 'Checking',
            'progress' => isset($jobResult['progress'])? $jobResult['progress']: null
        ]);

        // Si le job est terminé ou en erreur
        if (isset($jobResult['status']) && ($jobResult['status'] === 'Completed' || $jobResult['status'] === 'Error')) {
            $jobCompleted = true;

            if ($jobResult['status'] === 'Completed') {
                processCompletedMailjetJob($jobResult);
            } else {
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

        // Attendre 5 secondes avant la prochaine vérification
        if (! $jobCompleted && $attempts < $maxAttempts) {
            sleep(5);
            // Maintenir la connexion active
            sendEvent('heartbeat', [
                'time' => time(),
                'attempt' => $attempts
            ]);
        }
    }

    // Timeout
    if (! $jobCompleted) {
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
 * @param array $jobResult Données du job terminé
 */
function processCompletedMailjetJob($jobResult)
{
    // Extraire les données de résultat
    $details = $jobResult['details']??'';
    $result = $details['result']??'';
    $risk = $details['risk']??'';

    // Informer de la réussite de l'étape
    sendEvent('step', [
        'message' => 'Vérification avancée Mailjet...',
        'success' => $jobResult['code'] === 'VALID',
        'details' => [
            'result' => $result,
            'risk' => $risk
        ]
    ]);

    // Envoyer le résultat final
    $finalResult = [
        'success' => $jobResult['code'] === 'VALID',
        'message' => $jobResult['response'],
        'details' => [
            'code' => $jobResult['code']?? 'UNKNOWN',
            'response' => $jobResult['response']?? '',
            'result' => $result,
            'risk' => $risk
        ]
    ];
    sendEvent('result', $finalResult);

    // Enregistrer le résultat final dans le cache
    saveCache($_GET['email'], $finalResult);
}

// Mode SSE (Server-Sent Events)
if (isset($_GET['stream'])) {
    setupSseHeaders();
    $email = $_GET['email']?? '';

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
    <script src="js/email_validator.js"></script>
</body>
</html>