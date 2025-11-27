# Test Email - Scorimmo

Interface web pour tester l'envoi d'emails vers le système de parsing de Scorimmo PreProd.

## Fonctionnement

1. **Démarrer le tunnel SSH** (obligatoire)

    - Double-cliquer sur `start_tunnel.bat`
    - OU exécuter manuellement : `ssh -L 13306:scorimmo-preprod8.mysql:3306 scorimmopp@scorimmo.gw.oxv.fr -i C:/Users/Luc/.ssh/id_rsa -N`
    - Laisser la fenêtre ouverte (ne pas fermer)

2. **Accéder à l'interface**

    - Ouvrir `http://localhost/toolV2/public/test_email.php` dans votre navigateur
    - Si le tunnel n'est pas actif, un message d'erreur clair s'affichera
    - Le chargement de la page prend ~1 seconde maximum

3. **Utiliser l'interface**
    - Sélectionner un type d'email (14 types disponibles)
    - Choisir la quantité d'emails à envoyer (1-20)
    - Définir la période de recherche en jours (1-90)
    - Cliquer sur "Envoyer les emails"

## Structure du projet

```
toolV2/
├── config/
│   └── email_types.json          # Configuration des 14 types d'emails
├── src/
│   ├── Database/
│   │   └── DatabaseConnection.php # Connexion PDO via tunnel SSH
│   ├── Email/
│   │   └── EmailTestSender.php    # Service d'envoi d'emails
│   └── Logger/
│       └── Logger.php             # Système de logging
├── public/
│   ├── JS/
│   │   └── test_email.js         # JavaScript (séparé du PHP)
│   ├── api/
│   │   └── send_emails.php       # API REST pour l'envoi
│   └── test_email.php            # Interface Bootstrap
├── logs/
│   ├── test_email.log            # Logs de l'interface
│   └── send_emails_api.log       # Logs de l'API
└── start_tunnel.bat              # Script pour démarrer le tunnel SSH
```

## Types d'emails disponibles

1. A Vendre A Louer
2. BienIci Contact
3. Contact 3F
4. Logic-Immo Contact
5. Orpi Meeting
6. Orpi Prospect
7. Pap Contact Vendeur Particu
8. ParuVendu Contact
9. SeLoger Contact
10. SeLoger Meeting
11. SuperImmo Pro
12. SuperNeuf
13. Traja
14. Vivastreet

## Logs et débogage

Tous les logs sont enregistrés dans le dossier `logs/` :

-   `test_email.log` : Chargement de la page, connexion DB, récupération des types
-   `send_emails_api.log` : Détails complets de chaque requête API et envoi d'email

Les logs incluent :

-   Niveau DEBUG : Tous les détails techniques
-   Requêtes SQL exécutées
-   Réponses HTTP des envois d'emails
-   Stack traces en cas d'erreur

## Points importants

-   ✓ Le tunnel SSH est **obligatoire** et doit rester actif
-   ✓ La page se charge en ~1 seconde même si le tunnel n'est pas actif
-   ✓ Utilise uniquement Bootstrap 5 (pas de CSS custom)
-   ✓ JavaScript séparé dans un fichier dédié
-   ✓ Output buffering pour éviter le HTML tronqué
-   ✓ Logging exhaustif pour le débogage

## Dépannage

### "Le tunnel SSH n'est pas actif"

→ Démarrer `start_tunnel.bat` et attendre quelques secondes

### "Erreur réseau : Failed to fetch"

→ Vérifier que l'API existe : `toolV2/public/api/send_emails.php`

### Page qui ne se charge pas

→ Vérifier les logs : `toolV2/logs/test_email.log`

### Emails non trouvés

→ Augmenter le nombre de jours de recherche
→ Vérifier les patterns dans `config/email_types.json`
