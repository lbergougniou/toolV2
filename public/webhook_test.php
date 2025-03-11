<?php
// Définir un token de sécurité (à changer régulièrement)
$validToken = "1234";

// Vérifier si un token est présent dans l'URL
$hasValidToken = isset($_GET['token']) && $_GET['token'] === $validToken;

// Vérifier si c'est une requête au proxy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_webhook'])) {
    header("Content-Type: application/json");

    // Récupérer les données du formulaire
    $url = $_POST['webhook_url'];
    $eventType = $_POST['event_type'];
    
    // Construire le payload selon le type d'événement
    $payload = buildPayload($eventType, $_POST);
    
    // Initialiser cURL
    $ch = curl_init($url);

    // Configurer la requête
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    // Configurer les headers
    $headers = ["Content-Type: application/json"];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Exécuter la requête
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    // Préparer la réponse
    $result = [];
    if ($error) {
        $result = [
            "status" => 0,
            "statusText" => "Error",
            "response" => "Erreur cURL: " . $error
        ];
    } else {
        // Déterminer le texte du statut HTTP
        $statusTexts = [
            200 => "OK",
            201 => "Created",
            400 => "Bad Request",
            401 => "Unauthorized",
            403 => "Forbidden",
            404 => "Not Found",
            500 => "Internal Server Error"
        ];
        
        $statusText = $statusTexts[$httpCode] ?? "Unknown";
        
        $result = [
            "status" => $httpCode,
            "statusText" => $statusText,
            "response" => $response
        ];
    }
    
    // Stocker le résultat dans la session pour l'afficher après la redirection
    session_start();
    $_SESSION['webhook_result'] = $result;
    
    // Rediriger vers la page avec les résultats
    header("Location: " . $_SERVER['PHP_SELF'] . "?token=" . $_GET['token'] . "&event=" . $eventType . "&sent=true");
    exit;
}

// Fonction pour construire le payload selon le type d'événement
function buildPayload($eventType, $data) {
    $payload = [];
    
    // Champs communs à tous les événements
    if ($eventType === 'new_lead') {
        $payload = [
            "id" => (int)$data['lead_id'],
            "event" => "new_lead",
            "seller" => [
                "id" => (int)$data['seller_id'] ?? 1,
                "first_name" => $data['seller_first_name'] ?? "Commercial",
                "last_name" => $data['seller_last_name'] ?? "Scorimmo",
                "email" => $data['seller_email'] ?? "vendeur@scorimmo.com"
            ],
            "customer" => [
                "title" => $data['customer_title'] ?? "M.",
                "first_name" => $data['customer_first_name'] ?? "",
                "last_name" => $data['customer_last_name'] ?? "",
                "email" => $data['customer_email'] ?? "",
                "phone" => $data['customer_phone'] ?? ""
            ],
            "origin" => $data['origin'] ?? "Leboncoin",
            "interest" => $data['interest'] ?? "TRANSACTION",
            "created_at" => $data['created_at'] ?? date("Y-m-d H:i:s"),
            "status" => $data['status'] ?? "Nouveau",
            "purpose" => $data['purpose'] ?? "Achat",
            "seller_present_on_creation" => isset($data['seller_present']) && $data['seller_present'] === "on",
            "contact_type" => $data['contact_type'] ?? "call",
            "transfered" => isset($data['transfered']) && $data['transfered'] === "on"
        ];
        
        // Ajouter les champs conditionnels s'ils sont présents
        if (!empty($data['residence_type'])) {
            $payload['residence_type'] = $data['residence_type'];
        }
        
        if (!empty($data['funding_type'])) {
            $payload['funding_type'] = $data['funding_type'];
        }
        
        if (isset($data['have_residence_to_sell']) && $data['have_residence_to_sell'] === "on") {
            $payload['have_residence_to_sell'] = true;
        }
        
        if (isset($data['has_lot']) && $data['has_lot'] === "on") {
            $payload['has_lot'] = true;
        }
        
        // Propriétés
        if (!empty($data['property_type']) && !empty($data['property_price'])) {
            $payload['properties'] = [
                [
                    "id" => (int)($data['property_id'] ?? 1),
                    "type" => $data['property_type'],
                    "price" => $data['property_price'],
                    "area" => !empty($data['property_area']) ? (int)$data['property_area'] : null,
                    "reference" => $data['property_reference'] ?? "",
                    "address" => $data['property_address'] ?? "",
                    "link" => $data['property_link'] ?? ""
                ]
            ];
        }
    } 
    else if ($eventType === 'update_lead') {
        $payload = [
            "id" => (int)$data['lead_id'],
            "updated_at" => $data['updated_at'] ?? date("Y-m-d H:i:s"),
            "event" => "update_lead"
        ];
        
        // Ajouter les champs modifiés
        if (!empty($data['update_customer_first_name'])) {
            $payload['customer'] = [
                "first_name" => $data['update_customer_first_name']
            ];
        }
    } 
    else if ($eventType === 'new_comment') {
        $payload = [
            "event" => "new_comment",
            "lead_id" => (int)$data['lead_id'],
            "comment" => $data['comment_content'] ?? "Commentaire test",
            "created_at" => $data['created_at'] ?? date("Y-m-d H:i:s")
        ];
        
        if (!empty($data['external_lead_id'])) {
            $payload['external_lead_id'] = $data['external_lead_id'];
        }
    } 
    else if ($eventType === 'new_reminder') {
        $payload = [
            "event" => "new_reminder",
            "lead_id" => (int)$data['lead_id'],
            "created_at" => $data['created_at'] ?? date("Y-m-d H:i:s"),
            "start_time" => $data['start_time'] ?? date("Y-m-d H:i:s", strtotime("+1 day")),
            "detail" => $data['detail'] ?? "recontact"
        ];
        
        if (!empty($data['comment'])) {
            $payload['comment'] = $data['comment'];
        }
        
        if (!empty($data['external_lead_id'])) {
            $payload['external_lead_id'] = $data['external_lead_id'];
        }
    } 
    else if ($eventType === 'new_rdv') {
        $payload = [
            "event" => "new_rdv",
            "lead_id" => (int)$data['lead_id'],
            "created_at" => $data['created_at'] ?? date("Y-m-d H:i:s"),
            "start_time" => $data['start_time'] ?? date("Y-m-d H:i:s", strtotime("+1 day"))
        ];
        
        if (!empty($data['location'])) {
            $payload['location'] = $data['location'];
        }
        
        if (!empty($data['detail'])) {
            $payload['detail'] = $data['detail'];
        } else {
            $payload['detail'] = null;
        }
        
        if (!empty($data['comment'])) {
            $payload['comment'] = $data['comment'];
        } else {
            $payload['comment'] = null;
        }
        
        if (!empty($data['external_lead_id'])) {
            $payload['external_lead_id'] = $data['external_lead_id'];
        }
    } 
    else if ($eventType === 'closure_lead') {
        $payload = [
            "event" => "closure_lead",
            "lead_id" => (int)$data['lead_id'],
            "status" => $data['status'] ?? "SALE"
        ];
        
        if ($data['status'] === "ECHEC" && !empty($data['lost_reason'])) {
            $payload['lost_reason'] = $data['lost_reason'];
        }
        
        if (!empty($data['external_lead_id'])) {
            $payload['external_lead_id'] = $data['external_lead_id'];
        }
    }
    
    return $payload;
}

// Récupérer les résultats de la requête précédente
session_start();
$webhookResult = null;
if (isset($_GET['sent']) && $_GET['sent'] === 'true' && isset($_SESSION['webhook_result'])) {
    $webhookResult = $_SESSION['webhook_result'];
    unset($_SESSION['webhook_result']);
}

// Définir l'onglet actif
$activeTab = isset($_GET['event']) ? $_GET['event'] : 'new_lead';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Scorimmo | Documentation Webhook</title>
    
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        
        h1, h2, h3 {
            color: #0056b3;
        }
        
        pre {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        
        code {
            background-color: #f5f5f5;
            padding: 2px 5px;
            border-radius: 3px;
        }
        
        ul {
            padding-left: 20px;
        }
        
        .webhook-doc {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        <?php if ($hasValidToken): ?>
        .webhook-test-section {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin-top: 40px;
            max-width: 1000px;
            margin: 40px auto 0;
        }
        
        .webhook-test-section h2 {
            color: #0056b3;
            border-bottom: 2px solid #0056b3;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .webhook-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .form-group select,
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="url"],
        .form-group input[type="datetime-local"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .form-row .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            margin-left: 8px;
        }
        
        .section-title {
            font-weight: bold;
            margin: 20px 0 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        
        .btn-send {
            background-color: #0056b3;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            align-self: flex-start;
        }
        
        .btn-send:hover {
            background-color: #003d82;
        }
        
        .response-container {
            margin-top: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            background-color: #f0f0f0;
        }
        
        .response-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-error {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .response-body {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .webhook-tabs {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            flex-wrap: wrap;
        }
        
        .webhook-tab {
            padding: 8px 15px;
            cursor: pointer;
            margin-right: 5px;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            background-color: #f8f8f8;
            text-decoration: none;
            color: #333;
        }
        
        .webhook-tab.active {
            background-color: #fff;
            border-bottom: 2px solid #fff;
            margin-bottom: -1px;
            font-weight: bold;
            color: #0056b3;
        }
        
        fieldset {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        legend {
            font-weight: bold;
            padding: 0 10px;
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <div class="webhook-doc">
        <h1>Webhook</h1>
        <!-- Version simplifiée de la documentation pour test -->
        <h2>1. <span>Information</span></h2>
        <p>
            Une URL de webhook est l'adresse HTTPS que notre serveur appellera pour chaque événement au fur et à mesure qu'il se produit.<br>
            Il doit retourner un <code>200 OK</code> code HTTPS si tout se passe bien.
        </p>
        
        <h2>2. <span>Type d'événement</span></h2>
        <h3>2.1. Événement new_lead</h3>
        <p>Envoyé lors de la création d'un nouveau lead hors action API</p>
        
        <!-- Contenu minimal pour le test -->
    </div>
    
    <?php if ($hasValidToken): ?>
    <!-- Section de test des webhooks (uniquement visible avec token valide) -->
    <div class="webhook-test-section">
        <h2>Test des Webhooks</h2>
        <p>Cette section vous permet de tester l'envoi de webhooks vers votre endpoint.</p>
        
        <div class="webhook-tabs">
            <a href="?token=<?php echo $validToken; ?>&event=new_lead" class="webhook-tab <?php echo $activeTab === 'new_lead' ? 'active' : ''; ?>">new_lead</a>
            <a href="?token=<?php echo $validToken; ?>&event=update_lead" class="webhook-tab <?php echo $activeTab === 'update_lead' ? 'active' : ''; ?>">update_lead</a>
            <a href="?token=<?php echo $validToken; ?>&event=new_comment" class="webhook-tab <?php echo $activeTab === 'new_comment' ? 'active' : ''; ?>">new_comment</a>
            <a href="?token=<?php echo $validToken; ?>&event=new_reminder" class="webhook-tab <?php echo $activeTab === 'new_reminder' ? 'active' : ''; ?>">new_reminder</a>
            <a href="?token=<?php echo $validToken; ?>&event=new_rdv" class="webhook-tab <?php echo $activeTab === 'new_rdv' ? 'active' : ''; ?>">new_rdv</a>
            <a href="?token=<?php echo $validToken; ?>&event=closure_lead" class="webhook-tab <?php echo $activeTab === 'closure_lead' ? 'active' : ''; ?>">closure_lead</a>
        </div>
        
        <?php if ($webhookResult): ?>
        <div class="response-container">
            <div class="response-header">
                <h3>Réponse du serveur</h3>
                <span class="status-badge <?php echo $webhookResult['status'] >= 200 && $webhookResult['status'] < 300 ? 'status-success' : 'status-error'; ?>">
                    <?php echo $webhookResult['status'] . ' ' . $webhookResult['statusText']; ?>
                </span>
            </div>
            <div class="response-body"><?php 
                try {
                    echo json_encode(json_decode($webhookResult['response']), JSON_PRETTY_PRINT);
                } catch (Exception $e) {
                    echo htmlspecialchars($webhookResult['response']);
                }
            ?></div>
        </div>
        <?php endif; ?>
        
        <form method="post" action="?token=<?php echo $validToken; ?>" class="webhook-form">
            <input type="hidden" name="event_type" value="<?php echo $activeTab; ?>">
            <input type="hidden" name="send_webhook" value="1">
            
            <div class="form-group">
                <label for="webhook-url">URL du webhook</label>
                <input type="url" id="webhook-url" name="webhook_url" placeholder="https://exemple.com/webhook" required>
            </div>
            
            <?php if ($activeTab === 'new_lead'): ?>
            <!-- Formulaire pour new_lead -->
            <fieldset>
                <legend>Informations du lead</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="lead-id">ID du Lead</label>
                        <input type="number" id="lead-id" name="lead_id" value="2" required>
                    </div>
                    <div class="form-group">
                        <label for="created-at">Date de création</label>
                        <input type="text" id="created-at" name="created_at" value="<?php echo date('Y-m-d H:i:s'); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="residence-type">Type de résidence</label>
                        <select id="residence-type" name="residence_type">
                            <option value="">-- Aucun --</option>
                            <option value="INVESTMENT">Investissement</option>
                            <option value="PRINCIPAL">Résidence Principale</option>
                            <option value="SECONDARY">Résidence Secondaire</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="funding-type">Financement</label>
                        <select id="funding-type" name="funding_type">
                            <option value="">-- Aucun --</option>
                            <option value="CASH">Comptant</option>
                            <option value="GOT">Obtenu</option>
                            <option value="IN_PROGRESS">En cours</option>
                            <option value="NOT_STUDIED">Non étudié</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="origin">Origine</label>
                        <input type="text" id="origin" name="origin" value="Leboncoin">
                    </div>
                    
                    <div class="form-group">
                        <label for="interest">Univers</label>
                        <input type="text" id="interest" name="interest" value="TRANSACTION">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="status">Statut</label>
                        <input type="text" id="status" name="status" value="Nouveau">
                    </div>
                    
                    <div class="form-group">
                        <label for="purpose">Type de demande</label>
                        <select id="purpose" name="purpose">
                            <option value="Achat">Achat</option>
                            <option value="Vente">Vente</option>
                            <option value="Estimation">Estimation</option>
                            <option value="Location">Location</option>
                            <option value="Recherche">Recherche</option>
                            <option value="Locataire">Locataire</option>
                            <option value="Bailleur">Bailleur</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact-type">Canal de communication</label>
                        <select id="contact-type" name="contact_type">
                            <option value="call">Appel</option>
                            <option value="email">Email</option>
                            <option value="physical">Physique</option>
                        </select>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="have-residence" name="have_residence_to_sell">
                    <label for="have-residence">A un bien à vendre</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="has-lot" name="has_lot">
                    <label for="has-lot">Possession terrain</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="seller-present" name="seller_present" checked>
                    <label for="seller-present">Commercial présent à la création</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="transfered" name="transfered">
                    <label for="transfered">Transfert télécoms</label>
                </div>
            </fieldset>
            
            <fieldset>
                <legend>Commercial</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="seller-id">ID</label>
                        <input type="number" id="seller-id" name="seller_id" value="2">
                    </div>
                    
                    <div class="form-group">
                        <label for="seller-first-name">Prénom</label>
                        <input type="text" id="seller-first-name" name="seller_first_name" value="Commercial">
                    </div>
                    
                    <div class="form-group">
                        <label for="seller-last-name">Nom</label>
                        <input type="text" id="seller-last-name" name="seller_last_name" value="Scorimmo">
                    </div>
                    
                    <div class="form-group">
                        <label for="seller-email">Email</label>
                        <input type="text" id="seller-email" name="seller_email" value="vendeur@scorimmo.com">
                    </div>
                </div>
            </fieldset>
            
            <fieldset>
                <legend>Client</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="customer-title">Titre</label>
                        <select id="customer-title" name="customer_title">
                            <option value="M.">M.</option>
                            <option value="Mme">Mme</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer-first-name">Prénom</label>
                        <input type="text" id="customer-first-name" name="customer_first_name" value="Prenom_2">
                    </div>
                    
                    <div class="form-group">
                        <label for="customer-last-name">Nom</label>
                        <input type="text" id="customer-last-name" name="customer_last_name" value="Nom_2">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="customer-email">Email</label>
                        <input type="text" id="customer-email" name="customer_email" value="Email_2">
                    </div>
                    
                    <div class="form-group">
                        <label for="customer-phone">Téléphone</label>
                        <input type="text" id="customer-phone" name="customer_phone" value="Phone_2">
                    </div>
                </div>
            </fieldset>
            
            <fieldset>
                <legend>Propriété</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="property-id">ID</label>
                        <input type="number" id="property-id" name="property_id" value="1">
                    </div>
                    
                    <div class="form-group">
                        <label for="property-type">Type</label>
                        <input type="text" id="property-type" name="property_type" value="Maison">
                    </div>
                    
                    <div class="form-group">
                        <label for="property-price">Prix</label>
                        <input type="text" id="property-price" name="property_price" value="368 259">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="property-area">Surface</label>
                        <input type="number" id="property-area" name="property_area" value="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="property-reference">Référence</label>
                        <input type="text" id="property-reference" name="property_reference" value="REF_6046">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="property-address">Adresse</label>
                        <input type="text" id="property-address" name="property_address" value="44000 NANTES">
                    </div>
                    
                    <div class="form-group">
                        <label for="property-link">Lien</label>
                        <input type="text" id="property-link" name="property_link" value="https://www.liendevotreannonce.com/1234">
                    </div>
                </div>
            </fieldset>
            
            <?php elseif ($activeTab === 'update_lead'): ?>
            <!-- Formulaire pour update_lead -->
            <fieldset>
                <legend>Mise à jour du lead</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="lead-id">ID du Lead</label>
                        <input type="number" id="lead-id" name="lead_id" value="2" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="updated-at">Date de modification</label>
                        <input type="text" id="updated-at" name="updated_at" value="<?php echo date('Y-m-d H:i:s'); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="update-customer-first-name">Nouveau prénom du client</label>
                    <input type="text" id="update-customer-first-name" name="update_customer_first_name" value="Prenom_3">
                </div>
            </fieldset>
            
            <?php elseif ($activeTab === 'new_comment'): ?>
            <!-- Formulaire pour new_comment -->
            <fieldset>
                <legend>Nouveau commentaire</legend>
                <div class="form-group">
                        <label for="lead-id">ID du Lead</label>
                        <input type="number" id="lead-id" name="lead_id" value="2" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="created-at">Date de création</label>
                        <input type="text" id="created-at" name="created_at" value="<?php echo date('Y-m-d H:i:s'); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="comment-content">Contenu du commentaire</label>
                    <input type="text" id="comment-content" name="comment_content" value="3eme appel du client">
                </div>
                
                <div class="form-group">
                    <label for="external-lead-id">ID de lead externe (optionnel)</label>
                    <input type="text" id="external-lead-id" name="external_lead_id">
                </div>
            </fieldset>
            
            <?php elseif ($activeTab === 'new_reminder'): ?>
            <!-- Formulaire pour new_reminder -->
            <fieldset>
                <legend>Nouveau rappel</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="lead-id">ID du Lead</label>
                        <input type="number" id="lead-id" name="lead_id" value="2" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="created-at">Date de création</label>
                        <input type="text" id="created-at" name="created_at" value="<?php echo date('Y-m-d H:i:s'); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start-time">Date du rappel</label>
                        <input type="text" id="start-time" name="start_time" value="<?php echo date('Y-m-d H:i:s', strtotime('+1 day')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="detail">Type de rappel</label>
                        <select id="detail" name="detail">
                            <option value="offer">Offre</option>
                            <option value="recontact" selected>Recontact</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="comment">Commentaire (optionnel)</label>
                    <input type="text" id="comment" name="comment" value="Retour de la banque ce jour">
                </div>
                
                <div class="form-group">
                    <label for="external-lead-id">ID de lead externe (optionnel)</label>
                    <input type="text" id="external-lead-id" name="external_lead_id">
                </div>
            </fieldset>
            
            <?php elseif ($activeTab === 'new_rdv'): ?>
            <!-- Formulaire pour new_rdv -->
            <fieldset>
                <legend>Nouveau rendez-vous</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="lead-id">ID du Lead</label>
                        <input type="number" id="lead-id" name="lead_id" value="2" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="created-at">Date de création</label>
                        <input type="text" id="created-at" name="created_at" value="<?php echo date('Y-m-d H:i:s'); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start-time">Date du rendez-vous</label>
                        <input type="text" id="start-time" name="start_time" value="<?php echo date('Y-m-d H:i:s', strtotime('+1 day')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="detail">Type de rendez-vous</label>
                        <select id="detail" name="detail">
                            <option value="" selected>-- Aucun --</option>
                            <option value="Estimation">Estimation</option>
                            <option value="Découverte">Découverte</option>
                            <option value="Visite">Visite</option>
                            <option value="Suivi">Suivi</option>
                            <option value="Proposition">Proposition</option>
                            <option value="Signature">Signature</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="location">Lieu</label>
                    <input type="text" id="location" name="location" value="Agence Scorimmo 64 Rue du Abysses, Les Sables">
                </div>
                
                <div class="form-group">
                    <label for="comment">Commentaire (optionnel)</label>
                    <input type="text" id="comment" name="comment">
                </div>
                
                <div class="form-group">
                    <label for="external-lead-id">ID de lead externe (optionnel)</label>
                    <input type="text" id="external-lead-id" name="external_lead_id">
                </div>
            </fieldset>
            
            <?php elseif ($activeTab === 'closure_lead'): ?>
            <!-- Formulaire pour closure_lead -->
            <fieldset>
                <legend>Clôture de lead</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="lead-id">ID du Lead</label>
                        <input type="number" id="lead-id" name="lead_id" value="2" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Statut</label>
                        <select id="status" name="status" onchange="toggleLostReason()">
                            <option value="SALE" selected>Vente (SALE)</option>
                            <option value="MANDAT">Mandat (MANDAT)</option>
                            <option value="ECHEC">Échec (ECHEC)</option>
                            <option value="CLOSE_OPERATOR">Clôturé par l'opérateur (CLOSE_OPERATOR)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group" id="lost-reason-group" style="display: none;">
                    <label for="lost-reason">Raison d'échec</label>
                    <input type="text" id="lost-reason" name="lost_reason">
                </div>
                
                <div class="form-group">
                    <label for="external-lead-id">ID de lead externe (optionnel)</label>
                    <input type="text" id="external-lead-id" name="external_lead_id">
                </div>
            </fieldset>
            
            <script>
            function toggleLostReason() {
                const status = document.getElementById('status').value;
                const lostReasonGroup = document.getElementById('lost-reason-group');
                if (status === 'ECHEC') {
                    lostReasonGroup.style.display = 'block';
                } else {
                    lostReasonGroup.style.display = 'none';
                }
            }
            </script>
            <?php endif; ?>
            
            <button type="submit" class="btn-send">Envoyer le webhook</button>
        </form>
    </div>
    <?php endif; ?>
</body>
</html>