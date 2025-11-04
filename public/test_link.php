<?php
function getRedirectUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    
    curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);
    
    return $error ? ['error' => $error] : ['url' => $finalUrl];
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url'])) {
    sleep(1); // Petit délai pour éviter le spam
    $result = getRedirectUrl(trim($_POST['url']));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Décodeur de Liens</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center" style="min-height: 100vh; align-items: center;">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <h3 class="text-center mb-4">🔗 Décodeur de Liens</h3>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <input 
                                    type="text" 
                                    name="url" 
                                    class="form-control form-control-lg" 
                                    placeholder="Collez votre lien ici..."
                                    value="<?= isset($_POST['url']) ? htmlspecialchars($_POST['url']) : '' ?>"
                                    required
                                    autofocus
                                >
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                Décoder
                            </button>
                        </form>

                        <?php if ($result): ?>
                            <hr class="my-4">
                            
                            <?php if (isset($result['error'])): ?>
                                <div class="alert alert-danger">
                                    <strong>Erreur :</strong> <?= htmlspecialchars($result['error']) ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <strong>Destination :</strong>
                                </div>
                                <div class="p-3 bg-light rounded">
                                    <a href="<?= htmlspecialchars($result['url']) ?>" 
                                       target="_blank" 
                                       class="text-break fw-bold">
                                        <?= htmlspecialchars($result['url']) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <p class="text-center text-muted mt-3">
                    <small>💡 Astuce : Espacez vos requêtes pour éviter les blocages</small>
                </p>
            </div>
        </div>
    </div>
</body>
</html>