<?php
// Charger l'autoloader de Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Utiliser notre classe PhoneUtil
use App\PhoneUtil;

// Traitement des requêtes AJAX
if (isset($_POST['phone_number'])) {
    $phoneUtil = new PhoneUtil();
    $result = $phoneUtil->detectPhoneCountry($_POST['phone_number']);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détecteur de Pays - Numéro de Téléphone</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        /* Style du conteneur du drapeau/icône */
        .flag-container {
            position: relative;
            width: 20px;
            margin-right: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Style du drapeau */
        .country-flag {
            width: 100%;
            height: 100%;
            border-radius: 2px;
            object-fit: cover;
        }
        
        /* Style de l'icône d'invalidité */
        .invalid-icon {
            color: #dc3545;
            font-size: 14px;
            opacity: 0.5;
        }
        
        /* Style du code pays de fallback */
        .country-code {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        /* Styles pour l'input group */
        .input-group-text {
            background-color: #fff;
            padding: 1px;
        }
        
        .form-control {
            border-left: 0;
            padding: 0px;
        }
        
        /* Style de la carte */
        .card {
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-0">
                    <div class="card-header bg-white border-bottom-0 py-3">
                        <h5 class="mb-0">Détecteur de Pays</h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="mb-3">
                            <label for="phoneInput" class="form-label">Numéro de téléphone</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <div class="flag-container">
                                        <!-- Drapeau du pays (affiché si numéro valide) -->
                                        <img id="countryFlag" class="country-flag d-none" src="" alt="Drapeau du pays">
                                        
                                        <!-- Icône "interdit" (affichée si numéro invalide) -->
                                        <i id="invalidIcon" class="bi bi-x-circle-fill invalid-icon d-none"></i>
                                        
                                        <!-- Code pays (fallback si image non disponible) -->
                                        <span id="countryCode" class="country-code d-none"></span>
                                    </div>
                                </span>
                                <input 
                                    type="tel" 
                                    class="form-control" 
                                    id="phoneInput" 
                                    placeholder="Entrez un numéro"
                                    autocomplete="off"
                                >
                            </div>
                            <small class="form-text text-muted mt-2">
                                Exemples: +33612345678, 06 12 34 56 78, 0612345678
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS avec Popper pour les tooltips -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Notre script de détection de pays -->
    <script src="js/phone-detector.js"></script>
</body>
</html>