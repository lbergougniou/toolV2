<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

$BASE_URL = rtrim($_SERVER['REQUEST_URI'], '/');
// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Vérification de la présence des variables d'environnement nécessaires
$requiredEnvVars = [
    'VIAD_API_USERNAME',
    'VIAD_API_PASSWORD',
    'VIAD_API_COMPANY',
    'VIAD_API_GRANT_TYPE',
    'VIAD_API_SLUG',
];
foreach ($requiredEnvVars as $var) {
    if (!isset($_ENV[$var])) {
        die(
            "La variable d'environnement $var n'est pas définie. Veuillez vérifier votre fichier .env"
        );
    }
}

try {
    $client = new ViaDialogClient(
        $_ENV['VIAD_API_USERNAME'],
        $_ENV['VIAD_API_PASSWORD'],
        $_ENV['VIAD_API_COMPANY'],
        $_ENV['VIAD_API_GRANT_TYPE'],
        $_ENV['VIAD_API_SLUG']
    );

    error_log('Tentative de récupération des services actifs ViaContact...');

    // Récupérer les services actifs ViaContact
    $activeViaContactServices = $client->getServiceList(
        ['eq,product,VIACONTACT', 'eq,enable,true'],
        500
    );
} catch (ApiException $e) {
    error_log('Erreur API : ' . $e->getMessage());
    die('Erreur lors de la récupération des services : ' . $e->getMessage());
} catch (Exception $e) {
    error_log('Erreur inattendue : ' . $e->getMessage());
    die('Erreur inattendue : ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des SDA</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="/toolV2/public/css/styles.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
</head>
<body>
    <h1>Cablage des SDA</h1>
    <form id="sdaForm">
        <div>
            <label for="service">Service :</label>
            <select id="service" name="service" style="width: 300px;">
                <option value="">Sélectionnez un service</option>
                <?php foreach ($activeViaContactServices as $service): ?>
                    <option value="<?= htmlspecialchars($service->getId()) ?>">
                        <?= htmlspecialchars(
                            $service->getLabel()
                        ) ?> (<?= htmlspecialchars($service->getId()) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="quantity">Quantité :</label>
            <input type="number" id="quantity" name="quantity" min="1" value="1" required>
        </div>
        <div>
            <label for="prefix">Indicatif :</label>
            <select id="prefix" name="prefix">
                <option value="01">01</option>
                <option value="02">02</option>
                <option value="03">03</option>
                <option value="04">04</option>
                <option value="05">05</option>
                <option value="09" selected>09</option>
            </select>
        </div>
        <button type="button" id="searchButton">Rechercher les SDA disponibles</button>
    </form>

    <div id="result">
        
    </div>
    <button type="button" id="cableButton" style="display: none;">Câbler les SDA</button>

    <div id="confirmationModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2 id="modalTitle">Confirmation de câblage</h2>
        <div id="notification" class="notification">Message copié dans le presse-papiers !</div>
        <div id="confirmationMessage"></div>
        <div id="initialButtons" class="button-group">
            <button id="confirmCable">Confirmer</button>
            <button id="cancelCable">Annuler</button>
        </div>
        <div id="postCableButtons" class="button-group" style="display: none;">
            <button id="copyMessage">Copier le message</button>
            <button id="closeModal">Fermer</button>
        </div>
    </div>
</div>


<script src="/toolV2/public/js/script.js"></script>
</body>
</html>