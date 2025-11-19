<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Injecteur de Payloads ViaDialog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h1 class="h3 mb-0">
                            <i class="bi bi-arrow-repeat"></i> Injecteur de Payloads ViaDialog
                        </h1>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i> 
                            <strong>Mode local :</strong> Les requêtes passent par le serveur PHP pour éviter les problèmes CORS.
                        </div>
                        
                        <form id="injectionForm">
                            <div class="mb-3">
                                <label for="targetUrl" class="form-label">
                                    <i class="bi bi-link-45deg"></i> URL de destination
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="targetUrl" 
                                       value="https://pro.scorimmo.com/viaflow/close-call" 
                                       required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="authHeader" class="form-label">
                                    <i class="bi bi-shield-lock"></i> En-tête d'authentification
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="authHeader" 
                                       value="votre-token-ici" 
                                       placeholder="Votre token d'authentification">
                            </div>
                            
                            <div class="mb-3">
                                <label for="payloads" class="form-label">
                                    <i class="bi bi-code-square"></i> JSON des payloads (array)
                                </label>
                                <textarea class="form-control font-monospace" 
                                          id="payloads" 
                                          rows="10" 
                                          placeholder='Collez ici votre JSON array avec les payloads...' 
                                          required></textarea>
                                <div class="form-text">Format attendu : tableau d'objets avec {id, webhookId, payload, ...}</div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="delayEnabled" 
                                               checked>
                                        <label class="form-check-label" for="delayEnabled">
                                            Ajouter un délai entre chaque requête
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <input type="number" 
                                               class="form-control" 
                                               id="delay" 
                                               value="500" 
                                               min="0" 
                                               max="5000" 
                                               step="100">
                                        <span class="input-group-text">ms</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="bi bi-rocket-takeoff"></i> Lancer l'injection
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Progression -->
                <div id="progressContainer" class="card shadow-sm mt-4" style="display: none;">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Progression</h5>
                    </div>
                    <div class="card-body">
                        <div class="progress mb-3" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 id="progressBar" 
                                 role="progressbar" 
                                 style="width: 0%">
                                0%
                            </div>
                        </div>
                        
                        <div class="row text-center">
                            <div class="col-6 col-md-3 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-primary mb-0" id="statTotal">0</h3>
                                    <small class="text-muted">Total</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-success mb-0" id="statSuccess">0</h3>
                                    <small class="text-muted">Succès</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-danger mb-0" id="statError">0</h3>
                                    <small class="text-muted">Erreurs</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-info mb-0" id="statProcessed">0</h3>
                                    <small class="text-muted">Traités</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Résultats -->
                <div id="results" class="card shadow-sm mt-4" style="display: none;">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Résultats</h5>
                    </div>
                    <div class="card-body">
                        <div id="resultsContent"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/inject-payloads.js"></script>
</body>
</html>