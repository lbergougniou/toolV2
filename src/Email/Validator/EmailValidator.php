<?php
/**
 * EmailValidator.php - Classe principale de validation d'email
 *
 * Vérifications proposées :
 *  - résolution des serveurs mail du domaine (MX, repli A/AAAA, détection du "null MX")
 *  - dialogue SMTP direct avec le serveur MX du destinataire (port 25), sans envoi de message
 *  - détection des domaines catch-all (adresse aléatoire acceptée)
 *  - vérification avancée via l'API Mailjet
 *
 * Si le port 25 est bloqué en sortie (fréquent chez les hébergeurs / FAI), le test SMTP
 * est marqué "non testable" au lieu de conclure à une adresse invalide.
 */

namespace App\Email\Validator;

use App\Mailjet\MailjetClient;
use App\Config\SmtpCodes;

class EmailValidator {
    /**
     * Port SMTP des serveurs MX
     */
    const SMTP_PORT = 25;

    /**
     * Délai de connexion au serveur MX (secondes)
     */
    const CONNECT_TIMEOUT = 6;

    /**
     * Délai de lecture d'une réponse SMTP (secondes)
     */
    const READ_TIMEOUT = 15;

    /**
     * Nombre maximum de serveurs MX essayés
     */
    const MAX_MX_ATTEMPTS = 2;

    /**
     * Serveur de référence pour détecter un blocage du port 25 sortant
     */
    const PORT25_REFERENCE_HOST = 'gmail-smtp-in.l.google.com';

    /**
     * Durée de mémorisation du blocage du port 25 (secondes)
     */
    const PORT25_STATUS_TTL = 3600;

    /**
     * @var string Adresse email à valider
     */
    private $email;

    /**
     * @var string Domaine de l'adresse email
     */
    private $domain;

    /**
     * @var string Nom annoncé dans EHLO/HELO
     */
    private $heloDomain;

    /**
     * @var string Adresse utilisée dans MAIL FROM
     */
    private $fromEmail;

    /**
     * @var object|null Logger (optionnel)
     */
    private $logger;

    /**
     * @var MailjetClient Client Mailjet
     */
    private $mailjetClient;

    /**
     * @var callable|null Callback de progression : fn(string $step, string $status, ?string $detail)
     */
    private $onProgress;

    /**
     * Constructeur
     * @param string $email Adresse email à vérifier
     * @param object|null $logger Logger optionnel
     */
    public function __construct(string $email, $logger = null) {
        // Charger les variables d'environnement si nécessaire
        $this->loadEnvironment();

        $this->email = $email;
        $this->domain = strtolower(substr(strrchr($email, '@'), 1));
        $this->logger = $logger;
        $this->heloDomain = $_ENV['SMTP_VERIFY_HELO'] ?? 'pro.scorimmo.com';
        $this->fromEmail = $_ENV['SMTP_VERIFY_FROM'] ?? 'verification@pro.scorimmo.com';

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
            $dotenv->safeLoad();
        }
    }

    /**
     * Résout les serveurs mail du domaine
     *
     * Ordre : MX (triés par priorité), puis repli sur A/AAAA (RFC 5321 §5.1).
     * Un "null MX" (RFC 7505 : MX 0 ".") signifie que le domaine refuse tout email.
     *
     * @return array ['hosts' => [['host' => string, 'priority' => int|null]], 'source' => 'mx'|'a'|null, 'null_mx' => bool]
     */
    public function resolveMailHosts() {
        $result = ['hosts' => [], 'source' => null, 'null_mx' => false];

        $records = @dns_get_record($this->domain, DNS_MX);
        if (!empty($records)) {
            usort($records, fn($a, $b) => $a['pri'] <=> $b['pri']);

            foreach ($records as $record) {
                $target = rtrim($record['target'] ?? '', '.');
                if ($target === '') {
                    // MX 0 "." => le domaine déclare ne recevoir aucun email
                    if (count($records) === 1) {
                        $result['null_mx'] = true;
                        return $result;
                    }
                    continue;
                }
                $result['hosts'][] = ['host' => $target, 'priority' => (int) $record['pri']];
            }

            if (!empty($result['hosts'])) {
                $result['source'] = 'mx';
                return $result;
            }
        }

        // Pas de MX : le domaine lui-même sert de serveur mail s'il a une adresse IP
        if (checkdnsrr($this->domain, 'A') || checkdnsrr($this->domain, 'AAAA')) {
            $result['hosts'][] = ['host' => $this->domain, 'priority' => null];
            $result['source'] = 'a';
        }

        return $result;
    }

    /**
     * Lit une réponse SMTP complète (gère les réponses multi-lignes "250-...")
     * @param resource $socket Connexion socket
     * @return array ['code' => int, 'response' => string, 'timeout' => bool]
     */
    private function readResponse($socket) {
        $lines = [];
        $code = 0;

        while (($line = fgets($socket, 1024)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            // La dernière ligne d'une réponse a un espace en 4e position
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $meta = stream_get_meta_data($socket);

        return [
            'code' => $code,
            'response' => implode("\n", $lines),
            'timeout' => !empty($meta['timed_out']),
        ];
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
        $result = $this->readResponse($socket);

        if ($this->logger) {
            $this->logger->debug("SMTP Command", [
                'command' => $command,
                'response' => $result['response'],
                'code' => $result['code']
            ]);
        }

        $result['success'] = is_array($expectedCode)
            ? in_array($result['code'], $expectedCode, true)
            : $result['code'] === $expectedCode;

        return $result;
    }

    /**
     * Active TLS sur la connexion (opportuniste : les certificats des MX sont rarement
     * vérifiables par nom, et aucun secret ne transite pendant la vérification)
     * @param resource $socket Connexion socket
     * @return bool Succès de l'activation
     */
    private function startTLS($socket) {
        stream_context_set_option($socket, 'ssl', 'verify_peer', false);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        return @stream_socket_enable_crypto($socket, true, $cryptoMethod) === true;
    }

    /**
     * Teste l'adresse auprès des serveurs MX du destinataire, sans envoyer de message
     *
     * Dialogue : bannière 220, EHLO (repli HELO), STARTTLS si proposé, MAIL FROM,
     * RCPT TO (adresse testée), RCPT TO (adresse aléatoire, détection catch-all), RSET, QUIT.
     *
     * @param array $mailHosts Serveurs retournés par resolveMailHosts()['hosts']
     * @param callable|null $onProgress Callback de progression fn($step, $status, $detail)
     *                                  (étapes : smtp_connect, smtp_mailbox, smtp_catchall)
     * @return array Résultat détaillé de la vérification SMTP
     */
    public function testSmtpConnection(array $mailHosts, ?callable $onProgress = null) {
        $this->onProgress = $onProgress;
        $connectionErrors = [];
        $connected = false;

        // Blocage du port 25 détecté récemment : inutile d'attendre les délais de connexion
        $blockedSince = $this->port25BlockedSince();
        if ($blockedSince !== null) {
            $minutes = max(1, intdiv(time() - $blockedSince, 60));
            $retryIn = max(1, (int) ceil((self::PORT25_STATUS_TTL - (time() - $blockedSince)) / 60));
            return $this->untestableResult(
                "Port 25 bloqué en sortie (détecté il y a {$minutes} min)",
                " (blocage détecté il y a {$minutes} min, nouveau test du port dans {$retryIn} min)"
            );
        }

        foreach (array_slice($mailHosts, 0, self::MAX_MX_ATTEMPTS) as $mailHost) {
            $host = $mailHost['host'];
            $this->progress('smtp_connect', 'running', "Connexion à {$host}:" . self::SMTP_PORT . "...");

            $socket = @stream_socket_client(
                "tcp://{$host}:" . self::SMTP_PORT,
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT
            );

            if (!$socket) {
                $connectionErrors[] = "{$host}: " . ($errstr ?: "erreur {$errno}");
                continue;
            }

            $connected = true;
            stream_set_timeout($socket, self::READ_TIMEOUT);

            try {
                $result = $this->runSmtpDialogue($socket, $host);
            } finally {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
            }

            // Serveur indisponible (bannière absente, 421...) : on essaie le MX suivant
            if ($result['status'] === 'server_unavailable') {
                $connectionErrors[] = "{$host}: " . $result['response'];
                continue;
            }

            $this->savePort25Status(false);
            $result['details']['mx_host'] = $host;
            return $result;
        }

        // Aucune connexion TCP possible : port 25 bloqué, ou serveurs du destinataire en panne ?
        // On tranche en testant un serveur de référence, et on mémorise le résultat.
        if (!$connected) {
            $this->progress('smtp_connect', 'running', "Aucun serveur joignable, vérification du port 25 sortant...");
            $reference = @stream_socket_client("tcp://" . self::PORT25_REFERENCE_HOST . ":25", $errno, $errstr, self::CONNECT_TIMEOUT);
            if ($reference) {
                fclose($reference);
                $this->savePort25Status(false);
            } else {
                $this->savePort25Status(true);
                $this->log("Port 25 bloqué en sortie: " . implode(' | ', $connectionErrors));
                return $this->untestableResult("Port 25 bloqué en sortie : " . implode(' | ', $connectionErrors));
            }
        }

        $this->log("Test SMTP impossible: " . implode(' | ', $connectionErrors));

        return [
            'success' => null,
            'fatal' => false,
            'code' => 'UNTESTABLE',
            'extended_code' => '',
            'response' => implode("\n", $connectionErrors),
            'status' => 'untestable',
            'details' => [
                'probable_cause' => 'mx_unreachable',
                'confidence_level' => 'none',
                'warning' => "Les serveurs mail du destinataire ne répondent pas (le port 25 sortant fonctionne). "
                    . "Le test SMTP n'a pas pu être réalisé."
            ]
        ];
    }

    /**
     * Résultat "non testable" dû au blocage du port 25
     * @param string $response Détail technique
     * @param string $note Précision ajoutée au message d'avertissement
     * @return array
     */
    private function untestableResult($response, $note = '') {
        return [
            'success' => null,
            'fatal' => false,
            'code' => 'UNTESTABLE',
            'extended_code' => '',
            'response' => $response,
            'status' => 'untestable',
            'details' => [
                'probable_cause' => 'port_25_blocked',
                'confidence_level' => 'none',
                'warning' => "Le port 25 est bloqué en sortie sur ce réseau : le test SMTP direct est impossible{$note}."
            ]
        ];
    }

    /**
     * Fichier mémorisant l'état du port 25 sortant
     */
    private function port25StatusFile() {
        return sys_get_temp_dir() . '/toolv2_port25_status.json';
    }

    /**
     * Retourne le timestamp de détection du blocage du port 25, ou null s'il n'est pas bloqué
     * (ou si l'information a plus de PORT25_STATUS_TTL secondes)
     * @return int|null
     */
    private function port25BlockedSince() {
        $raw = @file_get_contents($this->port25StatusFile());
        $status = $raw ? json_decode($raw, true) : null;

        if (!is_array($status) || empty($status['blocked']) || time() - ($status['checked_at'] ?? 0) > self::PORT25_STATUS_TTL) {
            return null;
        }
        return (int) $status['checked_at'];
    }

    /**
     * Mémorise l'état du port 25 sortant
     * @param bool $blocked
     */
    private function savePort25Status($blocked) {
        @file_put_contents(
            $this->port25StatusFile(),
            json_encode(['blocked' => $blocked, 'checked_at' => time()]),
            LOCK_EX
        );
    }

    /**
     * Déroule le dialogue SMTP sur une connexion ouverte
     * @param resource $socket Connexion socket
     * @param string $host Serveur MX
     * @return array Résultat analysé
     */
    private function runSmtpDialogue($socket, $host) {
        $greeting = $this->readResponse($socket);
        if ($greeting['code'] !== 220) {
            return $this->unavailable($greeting, "Bannière SMTP invalide");
        }

        // EHLO, repli sur HELO pour les très vieux serveurs
        $ehlo = $this->smtpCommand($socket, "EHLO {$this->heloDomain}", 250);
        if (!$ehlo['success']) {
            $ehlo = $this->smtpCommand($socket, "HELO {$this->heloDomain}", 250);
            if (!$ehlo['success']) {
                return $this->unavailable($ehlo, "EHLO/HELO refusé");
            }
        }

        $tls = false;
        if (preg_match('/^250[ -]STARTTLS/mi', $ehlo['response'])) {
            $startTls = $this->smtpCommand($socket, "STARTTLS", 220);
            if ($startTls['success'] && $this->startTLS($socket)) {
                $tls = true;
                $this->smtpCommand($socket, "EHLO {$this->heloDomain}", 250);
            } else {
                $this->log("TLS n'a pas pu être activé sur {$host}, poursuite sans TLS");
            }
        }

        $this->progress('smtp_connect', 'success', "Connecté à {$host}" . ($tls ? " (TLS)" : " (sans TLS)"));
        $this->progress('smtp_mailbox', 'running', "Envoi de RCPT TO:<{$this->email}>...");

        $mailFrom = $this->smtpCommand($socket, "MAIL FROM:<{$this->fromEmail}>", [250, 251]);
        if (!$mailFrom['success']) {
            // Rejet de NOTRE expéditeur / IP : cela ne dit rien sur l'adresse testée
            return $this->buildResult($mailFrom, 'sender_rejected', null, [
                'probable_cause' => 'sender_rejected',
                'confidence_level' => 'none',
                'warning' => "Le serveur a refusé notre expéditeur ou notre IP : impossible de conclure sur l'adresse."
            ]);
        }

        $rcpt = $this->smtpCommand($socket, "RCPT TO:<{$this->email}>", [250, 251]);

        // Détection catch-all : une adresse forcément inexistante est-elle acceptée ?
        $catchAll = null;
        if ($rcpt['success']) {
            $this->progress('smtp_catchall', 'running', "Test d'une adresse aléatoire sur {$this->domain}...");
            $probe = 'verif-' . bin2hex(random_bytes(8)) . '@' . $this->domain;
            $probeResult = $this->smtpCommand($socket, "RCPT TO:<{$probe}>", [250, 251]);
            $catchAll = $probeResult['success'];
        }

        $this->smtpCommand($socket, "RSET", 250);

        return $this->analyzeSmtpResponse($rcpt, $catchAll);
    }

    /**
     * Notifie la progression du test SMTP
     */
    private function progress($step, $status, $detail = null) {
        if ($this->onProgress) {
            call_user_func($this->onProgress, $step, $status, $detail);
        }
    }

    /**
     * Résultat "serveur indisponible" (déclenche l'essai du MX suivant)
     */
    private function unavailable(array $response, $reason) {
        return [
            'status' => 'server_unavailable',
            'response' => $response['timeout'] ? "{$reason} (délai dépassé)" : ($response['response'] ?: $reason),
        ];
    }

    /**
     * Construit la structure de résultat commune
     * @param array $response Réponse SMTP
     * @param string $status Statut
     * @param bool|null $success Succès (null = indéterminé)
     * @param array $details Détails complémentaires
     * @return array
     */
    private function buildResult(array $response, $status, $success, array $details = []) {
        $extendedCode = '';
        if (preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $response['response'], $matches)) {
            $extendedCode = $matches[1];
        }

        return [
            'success' => $success,
            'fatal' => false,
            'code' => $response['code'],
            'extended_code' => $extendedCode,
            'response' => $response['response'],
            'status' => $status,
            'details' => array_merge([
                'is_temporary' => false,
                'probable_cause' => 'unknown',
                'confidence_level' => 'medium'
            ], $details)
        ];
    }

    /**
     * Analyse la réponse à RCPT TO
     *
     * @param array $rcptResult Résultat de la commande RCPT TO
     * @param bool|null $catchAll True si une adresse aléatoire est aussi acceptée
     * @return array Analyse détaillée des résultats
     */
    private function analyzeSmtpResponse(array $rcptResult, $catchAll) {
        $code = $rcptResult['code'];
        $lowerResponse = strtolower($rcptResult['response']);

        if ($rcptResult['timeout']) {
            return $this->buildResult($rcptResult, 'unknown_error', null, [
                'probable_cause' => 'timeout',
                'confidence_level' => 'none',
                'warning' => "Le serveur n'a pas répondu à temps"
            ]);
        }

        // Rejet lié à notre IP / réputation (blacklist, PBL...) : pas un verdict sur l'adresse
        $policyPatterns = [
            'blocked', 'blacklist', 'blocklist', 'spamhaus', 'policy', 'reputation',
            'not permitted', 'access denied', 'client host', 'dynamic', 'residential', 'banned'
        ];

        // Indices d'une boîte inexistante
        $unknownUserPatterns = [
            'user unknown', 'unknown user', 'no such user', 'does not exist', "doesn't exist",
            'mailbox unavailable', 'mailbox not found', 'invalid recipient', 'recipient rejected',
            'address rejected', 'unknown recipient', 'no mailbox', 'not found', 'inexistant'
        ];

        $matches = function(array $patterns) use ($lowerResponse) {
            foreach ($patterns as $pattern) {
                if (strpos($lowerResponse, $pattern) !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($code === 250 || $code === 251) {
            if ($catchAll) {
                return $this->buildResult($rcptResult, 'catch_all', true, [
                    'probable_cause' => 'catch_all_domain',
                    'confidence_level' => 'low',
                    'catch_all' => true,
                    'warning' => "Le domaine accepte toutes les adresses (catch-all) : l'existence de la boîte ne peut pas être confirmée."
                ]);
            }

            return $this->buildResult($rcptResult, 'valid', true, [
                'probable_cause' => 'address_exists',
                'confidence_level' => 'high',
                'catch_all' => false
            ]);
        }

        if ($code >= 400 && $code < 500) {
            $greylisted = strpos($lowerResponse, 'greylist') !== false || strpos($lowerResponse, 'try again') !== false;
            return $this->buildResult($rcptResult, 'temporary_error', null, [
                'is_temporary' => true,
                'probable_cause' => $greylisted ? 'greylisting' : 'mailbox_temporary_issue',
                'confidence_level' => 'none',
                'warning' => $greylisted
                    ? "Greylisting : le serveur demande de réessayer plus tard."
                    : "Erreur temporaire du serveur destinataire."
            ]);
        }

        if ($code >= 500) {
            $result = $this->buildResult($rcptResult, 'invalid', false);
            $extended = $result['extended_code'];

            // 5.1.x = problème d'adresse destinataire (5.1.1 : boîte inexistante)
            $addressProblem = strpos($extended, '5.1.') === 0 || $matches($unknownUserPatterns);
            // 5.7.x = politique / sécurité, souvent liée à notre IP
            $policyProblem = strpos($extended, '5.7.') === 0 || $matches($policyPatterns);

            if ($addressProblem && !$policyProblem) {
                $result['fatal'] = true;
                $result['details']['probable_cause'] = $extended === '5.1.1' ? 'mailbox_not_found' : 'address_invalid';
                $result['details']['confidence_level'] = 'high';
            } elseif (strpos($extended, '5.2.2') === 0 || $matches(['quota', 'mailbox full', 'over quota'])) {
                $result['status'] = 'mailbox_full';
                $result['details']['probable_cause'] = 'mailbox_full';
            } elseif ($policyProblem) {
                $result['status'] = 'rejected';
                $result['success'] = null;
                $result['details']['probable_cause'] = 'policy_rejection';
                $result['details']['confidence_level'] = 'none';
                $result['details']['warning'] = "Rejet lié à une politique du serveur (souvent la réputation de notre IP) : impossible de conclure sur l'adresse.";
            } else {
                // 550 sans précision : le plus souvent une adresse inexistante
                $result['fatal'] = in_array($code, [550, 551, 553], true);
                $result['details']['probable_cause'] = 'address_invalid';
            }

            return $result;
        }

        return $this->buildResult($rcptResult, 'unknown_error', null, [
            'confidence_level' => 'none'
        ]);
    }

    /**
     * Vérifie un email via l'API Mailjet en créant une liste
     * @return array Résultat de l'initialisation de vérification
     */
    public function verifyViaMailjetList() {
        if (!$this->mailjetClient->hasValidCredentials()) {
            return ['success' => false, 'code' => 'CONFIG_ERROR', 'response' => "Clés API Mailjet manquantes (MAILJET_API_KEY / MAILJET_SECRET_KEY)"];
        }

        $listId = null;

        try {
            // 1. Créer une liste
            $listResult = $this->mailjetClient->createList("verify_". md5($this->email. microtime()));
            $listId = $listResult['listId'];

            // 2. Ajouter le contact à la liste
            $this->mailjetClient->manageContact($listId, $this->email);

            // 3. Lancer la vérification
            $verifyResult = $this->mailjetClient->launchVerification($listId);

            // Retourne les identifiants pour le suivi
            return [
                'success' => true,
                'code' => 'JOB_STARTED',
                'response' => "Job de vérification lancé",
                'listId' => $listId,
                'jobId' => $verifyResult['jobId']
            ];

        } catch (\Exception $e) {
            // Ne pas laisser de liste orpheline dans le compte Mailjet
            if ($listId !== null) {
                $this->cleanupList($listId);
            }
            return ['success' => false, 'code' => 'ERROR', 'response' => $e->getMessage()];
        }
    }

    /**
     * Vérifie l'état d'un job de vérification Mailjet
     * La liste est supprimée dès que le job est terminé ou en erreur.
     *
     * @param string $listId ID de la liste
     * @param string $jobId ID du job
     * @return array État du job
     */
    public function checkVerificationJob($listId, $jobId) {
        try {
            $jobStatus = $this->mailjetClient->checkJobStatus($listId, $jobId);
        } catch (\Exception $e) {
            // Erreur ponctuelle de l'API : on laisse le polling réessayer
            return [
                'success' => false,
                'code' => 'JOB_STATUS_ERROR',
                'response' => $e->getMessage(),
                'status' => 'Checking'
            ];
        }

        // Si le job est en cours
        if (!$jobStatus['isCompleted'] && !$jobStatus['isError']) {
            return [
                'success' => true,
                'code' => 'JOB_PENDING',
                'response' => "Le job est toujours en cours",
                'status' => $jobStatus['status']
            ];
        }

        $this->cleanupList($listId);

        // Si le job est en erreur
        if ($jobStatus['isError']) {
            return [
                'success' => false,
                'code' => 'JOB_ERROR',
                'response' => "Le job a rencontré une erreur: ". ($jobStatus['error'] ?? 'Erreur inconnue'),
                'status' => 'Error'
            ];
        }

        // Si le job est terminé, analyser les résultats
        try {
            $analysisResult = $this->mailjetClient->analyzeEmailVerificationResult($jobStatus);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'code' => 'ANALYSIS_ERROR',
                'response' => $e->getMessage(),
                'status' => 'Error'
            ];
        }

        return [
            'success' => true,
            'code' => $analysisResult['isValid'] ? 'VALID' : 'INVALID',
            'response' => $analysisResult['message'],
            'status' => 'Completed',
            'details' => [
                'status' => $analysisResult['status'],
                'risk_level' => $analysisResult['riskLevel'],
                'job_error' => $analysisResult['jobError'],
                'result' => $analysisResult['rawResult'],
                'risk' => $analysisResult['rawRisk']
            ]
        ];
    }

    /**
     * Supprime une liste Mailjet sans propager d'erreur
     * @param string $listId ID de la liste
     */
    public function cleanupList($listId) {
        try {
            $this->mailjetClient->deleteList($listId);
        } catch (\Exception $e) {
            $this->log("Impossible de supprimer la liste Mailjet {$listId}: " . $e->getMessage());
        }
    }

    /**
     * Récupère le message associé à un code SMTP
     * @param mixed $code Code SMTP
     * @param string $extendedCode Code étendu (ex: 5.1.1)
     * @return string Message descriptif
     */
    public function getSmtpMessage($code, $extendedCode = '') {
        if ($code === 'UNTESTABLE') {
            return "Test SMTP non réalisable (serveurs mail injoignables sur le port 25)";
        }
        return SmtpCodes::CODES["{$code}-{$extendedCode}"]
            ?? SmtpCodes::CODES[$code]
            ?? "Code inconnu: $code";
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
