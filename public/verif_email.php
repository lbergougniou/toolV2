<?php
/**
 * verif_email.php - Script de vérification d'email avec Server-Sent Events
 * 
 * Ce script permet de vérifier la validité d'une adresse email en utilisant
 * plusieurs méthodes (format, DNS MX, SMTP direct sur le MX, Mailjet) et de communiquer
 * les résultats en temps réel au client via Server-Sent Events.
 */

// Configuration initiale du script
set_time_limit(360); // 6 minutes (attente Mailjet comprise)
ignore_user_abort(true); // Permet de nettoyer la liste Mailjet si l'onglet est fermé

// Chargement des dépendances
require_once __DIR__. '/../vendor/autoload.php';

use App\Email\Validator\EmailValidator;
use App\Config\SmtpCodes;
use App\Config\MailjetCodes;

/**
 * Durée de validité du cache (secondes)
 */
const CACHE_TTL = 600;

/**
 * Fichier de cache (hors du dossier public)
 */
function cacheFile()
{
    return sys_get_temp_dir() . '/toolv2_email_cache.json';
}

/**
 * Clé de cache : email normalisé + mode de vérification
 */
function cacheKey($email, $useMailjet)
{
    return md5(strtolower(trim($email)) . '|' . ($useMailjet ? 'mailjet' : 'basic'));
}

/**
 * Vérifie si un email a été vérifié récemment
 * 
 * @param string $email L'adresse email à vérifier
 * @param bool $useMailjet Mode de vérification
 * @return array|null Le résultat du cache, ou null si non trouvé
 */
function checkCache($email, $useMailjet)
{
    $raw = @file_get_contents(cacheFile());
    $cacheData = $raw ? json_decode($raw, true) : null;
    $key = cacheKey($email, $useMailjet);

    if (is_array($cacheData) && isset($cacheData[$key]) && time() - $cacheData[$key]['timestamp'] < CACHE_TTL) {
        return $cacheData[$key]['result'];
    }
    return null;
}

/**
 * Enregistre le résultat de la vérification dans le cache (et purge les entrées expirées)
 * 
 * @param string $email L'adresse email vérifiée
 * @param bool $useMailjet Mode de vérification
 * @param array $result Le résultat de la vérification
 */
function saveCache($email, $useMailjet, $result)
{
    $handle = @fopen(cacheFile(), 'c+');
    if (!$handle) {
        return;
    }

    flock($handle, LOCK_EX);
    $cacheData = json_decode(stream_get_contents($handle), true) ?: [];

    $now = time();
    $cacheData = array_filter($cacheData, fn($entry) => $now - ($entry['timestamp'] ?? 0) < CACHE_TTL);
    $cacheData[cacheKey($email, $useMailjet)] = [
        'timestamp' => $now,
        'result' => $result + ['from_cache' => true]
    ];

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($cacheData));
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * Envoie un événement SSE au client
 * 
 * @param string $event Nom de l'événement
 * @param array $data Données à envoyer (sera encodé en JSON)
 */
function sendEvent($event, $data)
{
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Envoie l'événement d'erreur final puis termine le script
 */
function sendErrorAndExit($message, $errorMessage = null)
{
    sendEvent('error', array_filter(['message' => $message, 'errorMessage' => $errorMessage]));
    exit;
}

/**
 * Configure les en-têtes HTTP pour SSE
 */
function setupSseHeaders()
{
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Désactive le buffering nginx
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
}

/**
 * Envoie l'état d'une étape de la checklist
 *
 * Étapes : format, mx, smtp_connect, smtp_mailbox, smtp_catchall, mailjet_launch, mailjet_job
 * Statuts : running | success | warning | error | skipped
 *
 * @param string $step Identifiant de l'étape
 * @param string $status Statut
 * @param string|null $detail Détail affiché sous l'étape
 */
function stepEvent($step, $status, $detail = null)
{
    sendEvent('step', array_filter([
        'step' => $step,
        'status' => $status,
        'detail' => $detail
    ], fn($v) => $v !== null));
}

/**
 * Gère la vérification d'email en mode SSE
 * 
 * @param string $email L'adresse email à vérifier
 * @param bool $useMailjet Utiliser la vérification Mailjet
 */
function handleEmailVerification($email, $useMailjet)
{
    if (strlen($email) > 254) {
        sendErrorAndExit('Email trop long');
    }

    // Vérifier le cache
    $cachedResult = checkCache($email, $useMailjet);
    if ($cachedResult !== null) {
        sendEvent('result', $cachedResult);
        return;
    }

    try {
        // 1. Vérification du format
        if (!verifyEmailFormat($email)) {
            return;
        }

        $validator = new EmailValidator($email);

        // 2. Vérification des MX records
        $mailHosts = verifyDomainMX($validator, $email);
        if ($mailHosts === null) {
            return;
        }

        // 3. Test SMTP direct sur le MX du destinataire
        $smtpResult = verifySmtpConnection($validator, $mailHosts);
        if ($smtpResult['fatal']) {
            return;
        }

        // 4. Vérification via Mailjet : demandée, ou en repli automatique si le SMTP n'est pas testable
        if ($useMailjet || $smtpResult['status'] === 'untestable') {
            verifyViaMailjet($validator, $email, $useMailjet, $smtpResult);
        } else {
            stepEvent('mailjet_launch', 'skipped', 'Non nécessaire : le test SMTP a abouti');
            stepEvent('mailjet_job', 'skipped');
            $finalResult = buildBasicResult($smtpResult);
            sendEvent('result', $finalResult);
            saveCache($email, false, $finalResult);
        }
    } catch (\Throwable $e) {
        error_log("[verif_email] " . $e->getMessage());
        sendEvent('error', [
            'message' => 'Erreur interne lors de la vérification',
            'errorMessage' => $e->getMessage()
        ]);
    }
}

/**
 * Construit le résultat final sans Mailjet à partir du résultat SMTP
 * level : success | warning | danger
 */
function buildBasicResult(array $smtpResult)
{
    $status = $smtpResult['status'];

    switch ($status) {
        case 'valid':
            return [
                'success' => true,
                'level' => 'success',
                'message' => 'Adresse email valide : le serveur destinataire a accepté l\'adresse',
                'details' => ['smtp_status' => $status]
            ];
        case 'catch_all':
            $message = 'Domaine catch-all : le serveur accepte toutes les adresses, l\'existence de la boîte n\'est pas garantie';
            break;
        case 'untestable':
            $message = 'Domaine valide, mais le test SMTP n\'a pas pu être réalisé (port 25 bloqué). Cochez Mailjet pour une vérification complète';
            break;
        case 'temporary_error':
            $message = 'Le serveur destinataire a répondu par une erreur temporaire, réessayez plus tard ou utilisez Mailjet';
            break;
        case 'mailbox_full':
            $message = 'La boîte mail existe mais semble pleine';
            break;
        default:
            $message = 'Résultat SMTP non concluant : ' . ($smtpResult['details']['warning'] ?? $smtpResult['response']);
    }

    return [
        'success' => null,
        'level' => 'warning',
        'message' => $message,
        'details' => ['smtp_status' => $status]
    ];
}

/**
 * Vérifie le format de l'email
 * 
 * @param string $email L'adresse email à vérifier
 * @return bool Si la vérification a réussi
 */
function verifyEmailFormat($email)
{
    stepEvent('format', 'running');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        stepEvent('format', 'error', "« {$email} » n'est pas une adresse email valide");
        sendEvent('result', [
            'success' => false,
            'level' => 'danger',
            'message' => "Format d'email invalide"
        ]);
        return false;
    }

    stepEvent('format', 'success');
    return true;
}

/**
 * Vérifie les serveurs mail du domaine (MX, repli A/AAAA, null MX)
 * 
 * @param EmailValidator $validator Instance du validateur
 * @param string $email L'adresse email à vérifier
 * @return array|null Serveurs mail, ou null si la vérification a échoué
 */
function verifyDomainMX($validator, $email)
{
    $domain = substr(strrchr($email, '@'), 1);

    stepEvent('mx', 'running', "Résolution DNS de {$domain}...");

    $resolution = $validator->resolveMailHosts();

    if ($resolution['null_mx'] || empty($resolution['hosts'])) {
        $reason = $resolution['null_mx']
            ? "Le domaine déclare explicitement ne recevoir aucun email (null MX, RFC 7505)"
            : "Aucun serveur mail configuré pour ce domaine (pas d'enregistrements MX, A ou AAAA)";

        stepEvent('mx', 'error', $reason);
        sendErrorAndExit('Domaine invalide: ' . $domain, $reason);
    }

    $hostsLabel = array_map(
        fn($h) => $h['host'] . ($h['priority'] !== null ? ' (priorité ' . $h['priority'] . ')' : ''),
        $resolution['hosts']
    );

    stepEvent('mx', 'success', ($resolution['source'] === 'a'
        ? 'Pas de MX, le domaine reçoit directement les emails : '
        : 'Serveurs : ') . implode(', ', $hostsLabel));

    return $resolution['hosts'];
}

/**
 * Vérifie l'email via une connexion SMTP directe au serveur MX
 * Étapes : smtp_connect, smtp_mailbox, smtp_catchall
 * 
 * @param EmailValidator $validator Instance du validateur d'email
 * @param array $mailHosts Serveurs mail du domaine
 * @return array Résultat de la vérification SMTP
 */
function verifySmtpConnection($validator, array $mailHosts)
{
    // Le validateur notifie la connexion et le début des tests au fil de l'eau
    $smtpResult = $validator->testSmtpConnection($mailHosts, 'stepEvent');
    $message = $validator->getSmtpMessage($smtpResult['code'], $smtpResult['extended_code']);
    $serverReply = is_int($smtpResult['code']) ? "Réponse : {$smtpResult['response']}" : null;
    $warning = $smtpResult['details']['warning'] ?? null;

    switch ($smtpResult['status']) {
        case 'untestable':
            stepEvent('smtp_connect', 'warning', $warning);
            stepEvent('smtp_mailbox', 'skipped', 'Non testable sans connexion SMTP');
            stepEvent('smtp_catchall', 'skipped', 'Non testable sans connexion SMTP');
            break;
        case 'valid':
            stepEvent('smtp_mailbox', 'success', "Adresse acceptée par le serveur ({$smtpResult['code']})");
            stepEvent('smtp_catchall', 'success', 'Domaine non catch-all : une adresse aléatoire est refusée');
            break;
        case 'catch_all':
            stepEvent('smtp_mailbox', 'success', "Adresse acceptée par le serveur ({$smtpResult['code']})");
            stepEvent('smtp_catchall', 'warning', $warning);
            break;
        case 'invalid':
            stepEvent('smtp_mailbox', 'error', "{$message}. {$serverReply}");
            stepEvent('smtp_catchall', 'skipped');
            break;
        default:
            // Expéditeur refusé, politique, erreur temporaire, boîte pleine, timeout...
            stepEvent('smtp_mailbox', 'warning', trim(($warning ?? $message) . ' ' . $serverReply));
            stepEvent('smtp_catchall', 'skipped');
    }

    sendEvent('smtp_result', [
        'success' => $smtpResult['success'],
        'status' => $smtpResult['status'],
        'message' => $message,
        'code' => $smtpResult['code'],
        'extended_code' => $smtpResult['extended_code'],
        'response' => $smtpResult['response'],
        'details' => $smtpResult['details']
    ]);

    // Boîte inexistante confirmée par le serveur : inutile d'aller plus loin
    if ($smtpResult['fatal']) {
        sendEvent('result', [
            'success' => false,
            'level' => 'danger',
            'message' => $message,
            'errorMessage' => 'Réponse du serveur ' . ($smtpResult['details']['mx_host'] ?? '') . ' : ' . $smtpResult['response']
        ]);
    }

    return $smtpResult;
}

/**
 * Vérifie l'email via l'API Mailjet
 * 
 * @param EmailValidator $validator Instance du validateur d'email
 * @param string $email Adresse vérifiée (pour le cache)
 * @param bool $requested True si Mailjet a été demandé, false en repli automatique
 * @param array $smtpResult Résultat SMTP (utilisé si le repli Mailjet échoue)
 */
function verifyViaMailjet($validator, $email, $requested, array $smtpResult)
{
    stepEvent('mailjet_launch', 'running', ($requested ? '' : 'Repli automatique (SMTP non testable). ')
        . 'Création de la liste temporaire et lancement du job...');

    $result = $validator->verifyViaMailjetList();

    if (!$result['success']) {
        stepEvent('mailjet_launch', 'error', $result['response']);
        stepEvent('mailjet_job', 'skipped');

        if ($requested) {
            sendErrorAndExit('Échec du lancement de la vérification Mailjet', $result['response']);
        }

        // Repli non demandé : on rend le résultat des vérifications de base, avec le motif Mailjet
        sendEvent('result', buildBasicResult($smtpResult) + [
            'errorMessage' => 'Repli Mailjet impossible : ' . $result['response']
        ]);
        return;
    }

    stepEvent('mailjet_launch', 'success', "Job {$result['jobId']} lancé" . ($requested ? '' : ' (repli automatique)'));

    monitorMailjetJob($validator, $email, $result['listId'], $result['jobId'], $requested);
}

/**
 * Surveille l'état du job Mailjet
 * 
 * @param EmailValidator $validator Instance du validateur d'email
 * @param string $email Adresse vérifiée (pour le cache)
 * @param string $listId ID de la liste Mailjet
 * @param string $jobId ID du job Mailjet
 * @param bool $cacheMode Mode de cache (vérification Mailjet demandée ou non)
 */
function monitorMailjetJob($validator, $email, $listId, $jobId, $cacheMode)
{
    // Configuration du polling : 20s + 50 x 5s ≈ 4 min 30 maximum
    $initialWait = 20;
    $pollingInterval = 5;
    $maxAttempts = 50;
    $lastError = null;

    stepEvent('mailjet_job', 'running', 'Mailjet analyse l\'adresse (environ 30 à 60 secondes)...');

    // Première attente pour laisser le temps à Mailjet de démarrer le job
    // (découpée pour détecter la fermeture de l'onglet)
    for ($i = 0; $i < $initialWait; $i += $pollingInterval) {
        sleep($pollingInterval);
        sendEvent('heartbeat', ['time' => time()]);
        if (connection_aborted()) {
            $validator->cleanupList($listId);
            exit;
        }
    }

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $jobResult = $validator->checkVerificationJob($listId, $jobId);
        $statusError = $jobResult['code'] === 'JOB_STATUS_ERROR' ? $jobResult['response'] : null;
        $lastError = $statusError ?? $lastError;

        sendEvent('job_status', [
            'attempt' => $attempt,
            'status' => $jobResult['status'] ?? 'Checking',
            'error' => $statusError
        ]);

        if ($jobResult['status'] === 'Completed') {
            processCompletedMailjetJob($jobResult, $email, $cacheMode);
            return;
        }

        if ($jobResult['status'] === 'Error') {
            stepEvent('mailjet_job', 'error', $jobResult['response']);
            sendErrorAndExit('Échec de la vérification Mailjet', $jobResult['response']);
        }

        sleep($pollingInterval);

        // Arrêt propre si l'utilisateur a fermé l'onglet
        if (connection_aborted()) {
            $validator->cleanupList($listId);
            exit;
        }
    }

    // Timeout - le job n'a pas été complété dans le temps imparti
    $validator->cleanupList($listId);

    $reason = "La vérification Mailjet prend trop de temps, veuillez réessayer plus tard"
        . ($lastError ? ". Dernière erreur Mailjet : {$lastError}" : '');

    stepEvent('mailjet_job', 'error', $reason);
    sendErrorAndExit('Délai d\'attente dépassé', $reason);
}

/**
 * Traite le résultat d'un job Mailjet terminé
 * 
 * @param array $jobResult Données du job terminé
 * @param string $email Adresse vérifiée (pour le cache)
 * @param bool $cacheMode Mode de cache (vérification Mailjet demandée ou non)
 */
function processCompletedMailjetJob($jobResult, $email, $cacheMode)
{
    $details = $jobResult['details'] ?? [];
    $status = $details['status'] ?? 'unknown';
    $riskLevel = $details['risk_level'] ?? 'unknown';

    // Délivrable à risque faible = vert ; délivrable à risque moyen/élevé ou catch-all/inconnu = orange
    if ($status === 'deliverable') {
        $level = in_array($riskLevel, ['medium', 'high'], true) ? 'warning' : 'success';
    } elseif (in_array($status, ['catch_all', 'unknown'], true)) {
        $level = 'warning';
    } else {
        $level = 'danger';
    }

    $stepStatus = ['success' => 'success', 'warning' => 'warning', 'danger' => 'error'][$level];
    $stepDetail = $jobResult['response'] . (!empty($details['job_error']) ? " — Motif Mailjet : {$details['job_error']}" : '');
    stepEvent('mailjet_job', $stepStatus, $stepDetail);

    $finalResult = [
        'success' => $level === 'danger' ? false : ($level === 'success' ? true : null),
        'level' => $level,
        'message' => $jobResult['response'],
        'details' => [
            'code' => $jobResult['code'] ?? 'UNKNOWN',
            'status' => $status,
            'risk_level' => $riskLevel,
            'job_error' => $details['job_error'] ?? null,
            'result' => $details['result'] ?? [],
            'risk' => $details['risk'] ?? []
        ]
    ];

    sendEvent('result', $finalResult);
    saveCache($email, $cacheMode, $finalResult);
}

// ======================================================
// LOGIQUE PRINCIPALE DU SCRIPT
// ======================================================

// Mode SSE (Server-Sent Events)
if (isset($_GET['stream'])) {
    setupSseHeaders();
    $email = trim((string) ($_GET['email'] ?? ''));
    $useMailjet = ($_GET['mailjet'] ?? '') === '1';

    if ($email === '') {
        sendErrorAndExit('Email manquant');
    }

    handleEmailVerification($email, $useMailjet);
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
                                        La vérification Mailjet fournit des résultats plus précis mais prend plus de temps. Décochée, elle reste utilisée automatiquement si le test SMTP est impossible (port 25 bloqué).
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