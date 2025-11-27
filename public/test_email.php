<?php

/**
 * Interface de test d'emails - Scorimmo
 * Permet de récupérer des emails de la BDD de prod et les envoyer vers /email/incoming
 *
 * @author Luc Bergougniou
 * @copyright 2025 Scorimmo
 */

// Activation du buffer de sortie pour éviter l'envoi prématuré du HTML
ob_start();

// Chargement des dépendances
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database/DatabaseConnection.php';
require_once __DIR__ . '/../src/Email/EmailTestSender.php';
require_once __DIR__ . '/../src/Logger/Logger.php';

use Database\DatabaseConnection;
use Email\EmailTestSender;
use App\Logger\Logger;

// Initialisation du logger
$logger = new Logger(__DIR__ . '/../logs/test_email.log', Logger::LEVEL_DEBUG);
$logger->info('Accès à la page test_email.php');

// Récupération des types d'emails disponibles
$emailTypes = [];
$error = null;

try {
    $logger->debug('Tentative de connexion à la base de données');
    $db = DatabaseConnection::getInstance();
    $logger->info('Connexion à la base de données réussie');

    $emailSender = new EmailTestSender($db);
    $emailTypes = $emailSender->getAvailableEmailTypes();
    $logger->info('Types d\'emails chargés', ['count' => count($emailTypes)]);
} catch (Exception $e) {
    $error = $e->getMessage();
    $logger->error('Erreur lors du chargement de la page', [
        'error' => $error,
        'trace' => $e->getTraceAsString()
    ]);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Email - Scorimmo</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">

                <!-- Card principale -->
                <div class="card shadow-lg">
                    <div class="card-header bg-primary text-white text-center py-3">
                        <h2 class="mb-0"><i class="fas fa-envelope-open-text me-2"></i>Test Email Scorimmo</h2>
                        <p class="mb-0 mt-2 small">Envoi automatique d'emails de test vers /email/incoming</p>
                    </div>

                    <div class="card-body p-4">

                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Erreur:</strong> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Formulaire -->
                        <form id="emailTestForm">

                            <!-- Type d'email -->
                            <div class="mb-4">
                                <label for="emailType" class="form-label fw-bold">
                                    <i class="fas fa-tags me-2"></i>Type d'email
                                </label>
                                <select class="form-select form-select-lg" id="emailType" name="email_type" required>
                                    <option value="">Sélectionner un type...</option>
                                    <?php foreach ($emailTypes as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>">
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Sélectionnez le type d'email à tester
                                </div>
                            </div>

                            <!-- Quantité -->
                            <div class="mb-4">
                                <label for="quantity" class="form-label fw-bold">
                                    <i class="fas fa-hashtag me-2"></i>Quantité
                                </label>
                                <input type="number"
                                    class="form-control form-control-lg"
                                    id="quantity"
                                    name="quantity"
                                    min="1"
                                    max="20"
                                    value="1"
                                    required>
                                <div class="form-text">
                                    Nombre d'emails à envoyer (entre 1 et 20)
                                </div>
                            </div>

                            <!-- Nombre de jours -->
                            <div class="mb-4">
                                <label for="days" class="form-label fw-bold">
                                    <i class="fas fa-calendar-days me-2"></i>Période (jours)
                                </label>
                                <input type="number"
                                    class="form-control form-control-lg"
                                    id="days"
                                    name="days"
                                    min="1"
                                    max="90"
                                    value="20"
                                    required>
                                <div class="form-text">
                                    Nombre de jours à remonter dans l'historique
                                </div>
                            </div>

                            <!-- Bouton d'envoi -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <span class="btn-text">
                                        <i class="fas fa-paper-plane me-2"></i>Envoyer les emails
                                    </span>
                                    <span class="loading d-none">
                                        <span class="spinner-border spinner-border-sm me-2"></span>
                                        Envoi en cours...
                                    </span>
                                </button>
                            </div>

                        </form>

                    </div>
                </div>

                <!-- Zone de résultats -->
                <div id="resultsContainer" class="mt-3"></div>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script personnalisé -->
    <script src="JS/test_email.js"></script>
</body>

</html>
<?php
// Envoi du buffer complet
ob_end_flush();
?>