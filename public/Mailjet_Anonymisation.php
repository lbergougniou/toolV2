<?php

$apiKey = '7c6b0549f225cf9c16f30aa40c545aa7';
$apiSecret = '1237f9ae7d2fe1bae5ced82573a21a62';

// === PARAMÈTRES ===
$monthsInactive = 6;
$maxToDelete = 50000;
$parallelRequests = 5; // Nombre de suppressions en parallèle (réduit pour éviter le rate limiting)
$displayEvery = 100; // Afficher un résumé tous les X contacts
$delayBetweenBatches = 0.5; // Délai en secondes entre chaque batch (pour éviter HTTP 429)
$maxRetries = 3; // Nombre de tentatives en cas d'erreur 429
// ==================

// ===== FONCTIONS =====

/**
 * Configure et exécute une requête curl vers l'API Mailjet
 */
function mailjetRequest($url, $apiKey, $apiSecret, $method = 'GET')
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$apiKey:$apiSecret");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch) ? curl_error($ch) : null;

    return [
        'success' => !$error,
        'httpCode' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

/**
 * Récupère les contacts inactifs depuis l'API Mailjet, triés par LastActivityAt
 */
function fetchContacts($apiKey, $apiSecret, $limit, $offset, $dateLimit)
{
    // Récupérer tous les contacts (le filtre LastActivityAt est obsolète dans l'API)
    // Le filtrage par date se fera manuellement côté PHP
    $url = "https://api.mailjet.com/v3/REST/contact?Limit=$limit&Offset=$offset";
    $result = mailjetRequest($url, $apiKey, $apiSecret);

    if (!$result['success']) {
        echo "✗ ERREUR: {$result['error']}\n";
        return null;
    }

    if ($result['httpCode'] !== 200) {
        echo "✗ ERREUR HTTP {$result['httpCode']}\n";
        return null;
    }

    return json_decode($result['response'], true);
}

/**
 * Supprime un contact via l'API Mailjet (GDPR)
 */
function deleteContact($contactId, $apiKey, $apiSecret)
{
    $url = "https://api.mailjet.com/v4/contacts/$contactId";
    return mailjetRequest($url, $apiKey, $apiSecret, 'DELETE');
}

/**
 * Configure les options communes curl pour optimiser les performances
 */
function configureCurlHandle($ch, $url, $apiKey, $apiSecret)
{
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "$apiKey:$apiSecret",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'DELETE'
    ]);
}

/**
 * Supprime plusieurs contacts en parallèle avec curl_multi et gestion du rate limiting
 */
function deleteContactsParallel($contacts, $apiKey, $apiSecret, $maxRetries = 3)
{
    if (empty($contacts)) {
        return [];
    }

    $attempt = 0;
    $toRetry = $contacts;
    $allResults = [];

    while ($attempt < $maxRetries && !empty($toRetry)) {
        $mh = curl_multi_init();
        $handles = [];

        // Préparer les handles curl
        foreach ($toRetry as $contact) {
            $url = "https://api.mailjet.com/v4/contacts/{$contact['ID']}";
            $ch = curl_init();
            configureCurlHandle($ch, $url, $apiKey, $apiSecret);

            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = [
                'handle' => $ch,
                'contact' => $contact
            ];
        }

        // Exécuter toutes les requêtes en parallèle
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);

        // Récupérer les résultats et identifier les 429 à retry
        $toRetry = [];
        foreach ($handles as $handleData) {
            $ch = $handleData['handle'];
            $contact = $handleData['contact'];

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_errno($ch) ? curl_error($ch) : null;

            if ($httpCode === 429 && $attempt < $maxRetries - 1) {
                // Rate limited - à réessayer
                $toRetry[] = $contact;
            } else {
                $results[] = [
                    'contact' => $contact,
                    'success' => !$error && ($httpCode === 204 || $httpCode === 200),
                    'httpCode' => $httpCode,
                    'error' => $error
                ];
            }

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        $allResults = array_merge($allResults, $results);

        // Si on a des 429, attendre avant de retry
        if (!empty($toRetry) && $attempt < $maxRetries - 1) {
            $waitTime = pow(2, $attempt); // Exponential backoff: 1s, 2s, 4s
            echo "  ⏳ Rate limit atteint, attente de {$waitTime}s avant retry...\n";
            sleep($waitTime);
        }

        $attempt++;
    }

    return $allResults;
}

// ===== SCRIPT PRINCIPAL =====

$dateLimit = strtotime("-$monthsInactive months");
$limit = 1000; // Maximum autorisé par l'API pour réduire le nombre d'appels
$offset = 0;
$deleted = 0;
$total = 0;

echo "=== SUPPRESSION CONTACTS MAILJET ===\n";
echo "Période d'inactivité: $monthsInactive mois\n";
echo "Date limite: " . date('Y-m-d H:i:s', $dateLimit) . "\n";
echo "Limite: $maxToDelete contacts\n\n";

// Traitement par batch
$processed = 0;
$errors = 0;
$startTime = time();
$rateLimitHits = 0; // Compteur de rate limits pour ajustement dynamique

do {
    echo "Batch offset $offset...\n";
    $data = fetchContacts($apiKey, $apiSecret, $limit, $offset, $dateLimit);

    if (!$data) {
        exit(1);
    }

    if ($offset == 0) {
        echo "  → Total: {$data['Total']}\n";
    }

    $contacts = $data['Data'] ?? [];
    $total += count($contacts);
    echo "  → " . count($contacts) . " contacts récupérés\n";

    // Filtrer manuellement les contacts inactifs (le paramètre API est obsolète)
    $inactiveContacts = array_filter($contacts, function($contact) use ($dateLimit) {
        $lastActivity = strtotime($contact['LastActivityAt']);
        return $lastActivity < $dateLimit;
    });

    echo "  → " . count($inactiveContacts) . " contacts inactifs (>12 mois)\n";

    $remainingSlots = $maxToDelete - $deleted;
    $toDelete = array_slice($inactiveContacts, 0, $remainingSlots);
    $foundEnough = count($toDelete) >= $remainingSlots;
    $processed += count($toDelete);

    // Suppression en parallèle par batches
    if (!empty($toDelete)) {
        $batches = array_chunk($toDelete, $parallelRequests);

        foreach ($batches as $batchIndex => $batch) {
            $results = deleteContactsParallel($batch, $apiKey, $apiSecret, $maxRetries);

            foreach ($results as $result) {
                $contact = $result['contact'];
                $lastActivityDate = date('Y-m-d', strtotime($contact['LastActivityAt']));

                if ($result['success']) {
                    $deleted++;
                    // Affichage réduit
                    if ($deleted % $displayEvery == 0 || $deleted == 1) {
                        echo "  ✓ [$deleted] {$contact['Email']} (inactif depuis: $lastActivityDate)\n";
                    }
                } elseif ($result['httpCode'] === 401) {
                    echo "\n✗ ERREUR 401: Permissions insuffisantes!\n";
                    echo "  → Vérifie tes clés sur: https://app.mailjet.com/account/apikeys\n";
                    exit(1);
                } else {
                    $errors++;
                    if ($result['httpCode'] === 429) {
                        $rateLimitHits++;
                        echo "  ⚠️  Rate limit persistant pour: {$contact['Email']}\n";
                    } else {
                        echo "  ✗ ERREUR: {$contact['Email']} (HTTP {$result['httpCode']})\n";
                    }
                }
            }

            // Délai adaptatif si rate limiting détecté
            if ($rateLimitHits > 0 && $batchIndex < count($batches) - 1) {
                $adaptiveDelay = $delayBetweenBatches + ($rateLimitHits * 0.5);
                echo "  ⏱️  Délai adaptatif: {$adaptiveDelay}s (rate limits détectés: $rateLimitHits)\n";
                usleep($adaptiveDelay * 1000000);
                $rateLimitHits = 0; // Reset pour le prochain batch
            } elseif ($batchIndex < count($batches) - 1 && $delayBetweenBatches > 0) {
                usleep($delayBetweenBatches * 1000000);
            }

            if ($deleted >= $maxToDelete) {
                echo "  ⏩ Limite de $maxToDelete suppressions atteinte\n";
                break 2;
            }
        }
    }

    // Si on a supprimé des contacts, rester sur le même offset (les contacts suivants remontent)
    // Sinon, incrémenter l'offset pour passer au batch suivant
    if (count($toDelete) > 0) {
        // Des contacts ont été supprimés, on reste à l'offset 0
        $offset = 0;
    } else {
        // Aucun contact inactif dans ce batch, passer au suivant
        $offset += $limit;
    }

    // Sortir si on a atteint la limite de suppressions
    if ($deleted >= $maxToDelete) {
        break;
    }
} while (count($contacts) == $limit);

$duration = time() - $startTime;
$rate = $duration > 0 ? round($deleted / $duration, 2) : 0;

echo "\n=== TERMINÉ ===\n";
echo "Traités: $processed\n";
echo "Supprimés: $deleted\n";
echo "Erreurs: $errors\n";
echo "Durée: {$duration}s\n";
echo "Vitesse: {$rate} suppressions/sec\n";
