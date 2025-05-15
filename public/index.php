<?php 
require_once __DIR__ . '/../vendor/autoload.php';

use App\Scraping\LeboncoinScraper;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('leboncoin_scraper');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/scraper.log', Logger::DEBUG));

$scraper = new LeboncoinScraper($logger);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference = isset($_POST['reference']) && $_POST['reference'] !== '' ? $_POST['reference'] : null;
    $prix = isset($_POST['prix']) && $_POST['prix'] !== '' ? (float)$_POST['prix'] : null;
    $localisation = isset($_POST['localisation']) && $_POST['localisation'] !== '' ? $_POST['localisation'] : null;
    
    try {
        $results = $scraper->searchByReference($reference, $prix, $localisation);
        
        // Vérifier si une erreur a été retournée par le scraper
        if (isset($results['error']) && $results['error'] === true) {
            echo json_encode(['success' => false, 'message' => $results['message']]);
        } else {
            echo json_encode(['success' => true, 'results' => $results]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recherche d'annonces</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h1 class="h4 mb-0">Recherche d'annonces</h1>
                    </div>
                    <div class="card-body">
                        <form id="scrapingForm" method="POST">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label for="site" class="form-label">Site</label>
                                    <select class="form-select" id="site" name="site">
                                        <option value="leboncoin">Le Bon Coin</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label for="reference" class="form-label">Référence</label>
                                    <input type="text" class="form-control" id="reference" name="reference">
                                </div>
                                <div class="col-md-3">
                                    <label for="localisation" class="form-label">Localisation</label>
                                    <input type="text" class="form-control" id="localisation" name="localisation">
                                </div>
                                <div class="col-md-4">
                                    <label for="prix" class="form-label">Prix</label>
                                    <input type="text" class="form-control" id="prix" name="prix">
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Rechercher
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
    <script src="js/scraping.js"></script>
</body>
</html>