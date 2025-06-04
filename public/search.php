<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/RealEstateAgent.php';
require_once __DIR__ . '/../src/WebSearchService.php';

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

$logger = new Logger(__DIR__ . '/../logs/search.log', Logger::LEVEL_INFO);

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference = trim($_POST['reference'] ?? '');
    $agency = trim($_POST['agency'] ?? '');
    
    if (empty($reference) || empty($agency)) {
        $error = "Veuillez remplir tous les champs.";
    } else {
        try {
            $agent = new RealEstateAgent($logger);
            $result = $agent->searchProperty($reference, $agency);
        } catch (Exception $e) {
            $error = "Erreur lors de la recherche : " . $e->getMessage();
        }
    }
}
?>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche d'Annonces Immobilières</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <h1 class="text-center mb-4">Recherche d'Annonces Immobilières</h1>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="reference" class="form-label">Référence du bien</label>
                                <input type="text" class="form-control" id="reference" name="reference" 
                                       value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="agency" class="form-label">Nom de l'agence</label>
                                <input type="text" class="form-control" id="agency" name="agency" 
                                       value="<?= htmlspecialchars($_POST['agency'] ?? '') ?>" required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Rechercher</button>
                        </form>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger mt-3" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($result): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5>Résultats de la recherche</h5>
                        </div>
                        <div class="card-body">
                            <?php if (isset($result['error'])): ?>
                                <div class="alert alert-warning">
                                    <?= htmlspecialchars($result['error']) ?>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Type de bien :</strong> <?= $result['type_bien'] ?? 'Non renseigné' ?></p>
                                        <p><strong>Prix :</strong> <?= $result['prix'] ? number_format($result['prix'], 0, ',', ' ') . ' €' : 'Non renseigné' ?></p>
                                        <p><strong>Surface :</strong> <?= $result['surface'] ? $result['surface'] . ' m²' : 'Non renseigné' ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Nombre de pièces :</strong> <?= $result['nombre_pieces'] ?? 'Non renseigné' ?></p>
                                        <p><strong>Lien de l'annonce :</strong> 
                                            <?php if ($result['lien_annonce']): ?>
                                                <a href="<?= htmlspecialchars($result['lien_annonce']) ?>" target="_blank" class="btn btn-sm btn-info">
                                                    Voir l'annonce
                                                </a>
                                            <?php else: ?>
                                                Non disponible
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <h6>Données JSON brutes :</h6>
                                    <pre class="bg-light p-3"><code><?= json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></code></pre>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>