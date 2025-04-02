/**
 * Script pour la détection de pays des numéros de téléphone
 * Version optimisée:
 * - Affichage du drapeau SVG pour les numéros valides
 * - Affichage d'une icône d'invalidité pour les numéros non reconnus
 * - Affichage du code pays en fallback si le drapeau n'est pas disponible
 * - Validation uniquement lors de la perte de focus (blur) et au chargement
 * - Utilisation de fichiers SVG locaux pour les drapeaux
 */
document.addEventListener("DOMContentLoaded", function () {
    // ===== ÉLÉMENTS DU DOM =====
    const phoneInput = document.getElementById("phoneInput"); // Champ de saisie du numéro
    const flag = document.getElementById("countryFlag"); // Image du drapeau
    const invalidIcon = document.getElementById("invalidIcon"); // Icône d'invalidité
    const countryCodeElement = document.getElementById("countryCode"); // Code pays (fallback)

    // Variables pour stocker les dernières données
    let lastCountryCode = null;
    let lastPhoneData = null;

    // Chemin vers les fichiers SVG des drapeaux
    const flagBasePath = "img/flags/";

    // ===== FONCTIONS PRINCIPALES =====

    /**
     * Initialise le détecteur de numéro
     */
    function initialize() {
        // Réinitialiser l'affichage
        resetDisplay();

        // Vérifier si un numéro est déjà présent dans le champ au chargement
        if (phoneInput.value.trim()) {
            validatePhone();
        }

        // Ajouter les écouteurs d'événements
        setupEventListeners();
    }

    /**
     * Configure les écouteurs d'événements pour le champ de saisie
     * Optimisé pour déclencher la validation uniquement à la perte de focus
     */
    function setupEventListeners() {
        // Validation uniquement à la perte de focus (blur)
        phoneInput.addEventListener("blur", validatePhone);

        // Réinitialiser l'affichage quand l'utilisateur commence à modifier le numéro
        phoneInput.addEventListener("input", function () {
            if (phoneInput.value.trim()) {
                resetDisplay();
            }
        });
    }

    /**
     * Valide le numéro de téléphone et détecte son pays
     */
    function validatePhone() {
        const phoneNumber = phoneInput.value.trim();

        // Si le champ est vide, réinitialiser l'affichage
        if (!phoneNumber) {
            resetDisplay();
            return;
        }

        // Envoyer la requête AJAX au serveur
        fetch(window.location.href, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
            },
            body: `phone_number=${encodeURIComponent(phoneNumber)}`,
        })
            .then((response) => response.json())
            .then((data) => {
                lastPhoneData = data;
                if (data.success) {
                    if (data.isValid) {
                        // Numéro valide : afficher le drapeau
                        displayCountryInfo(data.countryCode, data);
                    } else {
                        // Numéro invalide : afficher l'icône d'invalidité
                        showInvalidNumber("Numéro non valide ou non reconnu");
                    }
                } else {
                    // Erreur côté serveur
                    showInvalidNumber(data.message || "Erreur inconnue");
                }
            })
            .catch((error) => {
                // Erreur de communication
                showInvalidNumber("Erreur de communication");
                console.error("Erreur:", error);
            });
    }

    /**
     * Affiche les informations du pays détecté
     * @param {string} countryCode - Code ISO du pays (2 lettres)
     * @param {Object} phoneData - Données du numéro de téléphone
     */
    function displayCountryInfo(countryCode, phoneData) {
        if (!countryCode) {
            resetDisplay();
            return;
        }

        // Masquer tous les éléments visuels
        hideAllVisualElements();

        // Afficher le code pays en attendant le chargement du drapeau
        countryCodeElement.textContent = countryCode.toUpperCase();
        countryCodeElement.classList.remove("d-none");

        // Préparer la base du texte de l'infobulle
        let tooltipText = "";
        
        if (phoneData.country) {
            tooltipText = phoneData.country;
        } else {
            tooltipText = `Pays: ${countryCode.toUpperCase()}`;
        }
        
        // Ajouter l'indicatif téléphonique au tooltip
        if (phoneData.phoneCode) {
            tooltipText += `<br><i>Indicatif : ${phoneData.phoneCode}</i>`;
        }
    
        // Configurer l'image du drapeau
        flag.src = `${flagBasePath}${countryCode.toLowerCase()}.svg`;

        // Gérer l'événement de chargement/erreur de l'image
        flag.onload = function () {
            // L'image s'est chargée correctement : afficher le drapeau et masquer le code
            countryCodeElement.classList.add("d-none");
            flag.classList.remove("d-none");
        };

        flag.onerror = function () {
            // Erreur de chargement : garder le code pays affiché
            flag.classList.add("d-none");
            countryCodeElement.classList.remove("d-none");
            console.error(`Erreur de chargement du drapeau: ${flag.src}`);
        };

        // Créer/mettre à jour l'infobulle
        updateTooltip(countryCodeElement, tooltipText);
        updateTooltip(flag, tooltipText);
    }

    // ===== FONCTIONS UTILITAIRES =====

    /**
     * Affiche l'indicateur de numéro invalide
     * @param {string} errorMessage - Message d'erreur à afficher dans l'infobulle
     */
    function showInvalidNumber(errorMessage) {
        // Réinitialiser d'abord l'affichage
        resetDisplay();

        // Masquer tous les éléments visuels et afficher l'icône d'invalidité
        hideAllVisualElements();
        invalidIcon.classList.remove("d-none");

        // Ajouter une infobulle à l'icône avec le message d'erreur
        updateTooltip(invalidIcon, errorMessage);
    }

    /**
     * Masque tous les éléments visuels d'indication
     */
    function hideAllVisualElements() {
        flag.classList.add("d-none");
        invalidIcon.classList.add("d-none");
        countryCodeElement.classList.add("d-none");
    }

    /**
     * Réinitialise complètement l'affichage
     */
    function resetDisplay() {
        // Masquer tous les éléments visuels
        hideAllVisualElements();

        // Réinitialiser l'image du drapeau
        flag.src = "";

        // Réinitialiser le texte du code pays
        countryCodeElement.textContent = "";

        // Supprimer les infobulles existantes
        removeTooltip(flag);
        removeTooltip(invalidIcon);
        removeTooltip(countryCodeElement);

        // Réinitialiser les données stockées
        lastCountryCode = null;
        lastPhoneData = null;
    }

    /**
     * Met à jour ou crée une infobulle Bootstrap pour un élément
     * @param {HTMLElement} element - Élément auquel ajouter l'infobulle
     * @param {string} tooltipText - Texte de l'infobulle
     */
    function updateTooltip(element, tooltipText) {
        // Supprimer l'infobulle existante si présente
        removeTooltip(element);

        // Créer une nouvelle infobulle
        new bootstrap.Tooltip(element, {
            title: tooltipText,
            placement: "top",
            html: true,
        });
    }

    /**
     * Supprime une infobulle Bootstrap d'un élément
     * @param {HTMLElement} element - Élément dont supprimer l'infobulle
     */
    function removeTooltip(element) {
        const tooltipInstance = bootstrap.Tooltip.getInstance(element);
        if (tooltipInstance) {
            tooltipInstance.dispose();
        }
    }

    // ===== INITIALISATION =====
    initialize();
});