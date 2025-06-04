<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AIService;
use App\Logger\Logger;

// Chargement des variables d'environnement
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Initialisation du logger
$logger = new Logger(__DIR__ . '/../logs/norme_name.log', Logger::LEVEL_INFO);

$response = null;
$error = null;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $aiService = new AIService($logger);
        
        $data = [
            'nom' => $_POST['nom'] ?? '',
            'prenom' => $_POST['prenom'] ?? '',
            'email' => $_POST['email'] ?? ''
        ];
        
        $logger->debug("Données reçues du formulaire", $data);
        
        $response = $aiService->executePrompt('order_name', $data);
        
        $logger->debug("Traitement réussi", ['response' => $response]);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        $logger->error("Erreur dans norme_name.php: " . $error);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Normalisation des noms et prénoms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h2 class="h4 mb-0">
                            <i class="bi bi-person-check"></i> Normalisation des noms et prénoms
                        </h2>
                    </div>
                    <div class="card-body">
                        <!-- Formulaire -->
                        <form method="POST" id="aiForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nom" class="form-label">Nom</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="nom" 
                                           name="nom" 
                                           value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>"
                                           placeholder="Entrez le nom">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="prenom" class="form-label">Prénom</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="prenom" 
                                           name="prenom" 
                                           value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>"
                                           placeholder="Entrez le prénom">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                       placeholder="Entrez l'email">
                            </div>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <span class="spinner-border spinner-border-sm d-none" id="spinner"></span>
                                Normaliser les données
                            </button>
                        </form>

                        <!-- Résultats -->
                        <?php if ($response): ?>
                            <div class="mt-4">
                                <div class="alert alert-success">
                                    <h5><i class="bi bi-check-circle"></i> Données normalisées :</h5>
                                    <div class="bg-light p-3 rounded">
                                        <pre><?= json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Erreurs -->
                        <?php if ($error): ?>
                            <div class="mt-4">
                                <div class="alert alert-danger">
                                    <h5><i class="bi bi-exclamation-triangle"></i> Erreur :</h5>
                                    <p class="mb-0"><?= htmlspecialchars($error) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/norme_name.js"></script>
</body>
</html>