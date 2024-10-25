<?php
// Désactiver l'affichage des erreurs pour la production
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

// Fonction pour envoyer une réponse JSON
function sendJsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Gestionnaire d'erreurs et d'exceptions
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $error = "Erreur PHP [$errno] $errstr sur la ligne $errline dans le fichier $errfile";
    error_log($error);
    sendJsonResponse(['error' => $error], 500);
});

set_exception_handler(function ($exception) {
    $error = 'Exception non capturée : ' . $exception->getMessage();
    error_log($error);
    sendJsonResponse(['error' => $error], 500);
});

try {
    // Charger les variables d'environnement
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();

    // Initialiser le client ViaDialog
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    // Vérifier la méthode HTTP
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Méthode HTTP non autorisée');
    }

    // Récupérer et valider les paramètres
    $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
    $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : '';
    if (empty($prefix)) {
        throw new Exception('Le préfixe est requis');
    }
    $prefix =
        strpos($prefix, '+33') !== 0 ? '+33' . substr($prefix, 1) : $prefix;

    // Définir les critères de recherche
    $criteria = [
        'eq,telSvi.viacontact.id,null',
        'eq,sdaUsage,DIRECT',
        'cr,sdaNumber,' . $prefix,
    ];

    // Récupérer et filtrer les SDA disponibles
    $allSdas = $client->getSdaList($criteria, 2000);
    $availableSdas = array_filter(
        $allSdas,
        fn($sda) => strpos($sda->getNumber(), $prefix) === 0
    );
    $totalAvailable = count($availableSdas);
    $selectedCount = min($quantity, $totalAvailable);

    // Sélectionner aléatoirement les SDA
    $result = [];
    if ($selectedCount > 0) {
        $selectedIndexes = array_rand($availableSdas, $selectedCount);
        $selectedIndexes = is_array($selectedIndexes)
            ? $selectedIndexes
            : [$selectedIndexes];
        foreach ($selectedIndexes as $index) {
            $sda = $availableSdas[$index];
            $result[] = [
                'number' => $sda->getNumber(),
                'releasedDate' => $sda->getReleasedDate()->format('Y-m-d'),
            ];
        }
    }

    // Envoyer la réponse
    sendJsonResponse(
        empty($result)
            ? ['message' => 'Aucun SDA disponible pour les critères spécifiés.']
            : [
                'sdas' => $result,
                'totalAvailable' => $totalAvailable,
                'selectedCount' => $selectedCount,
            ]
    );
} catch (ApiException $e) {
    sendJsonResponse(
        [
            'error' =>
                'Erreur lors de la récupération des SDA : ' . $e->getMessage(),
        ],
        500
    );
} catch (Exception $e) {
    sendJsonResponse(
        [
            'error' =>
                "Une erreur inattendue s'est produite : " . $e->getMessage(),
        ],
        500
    );
}
