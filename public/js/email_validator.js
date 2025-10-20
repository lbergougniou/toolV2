/**
 * email_validator.js - Script pour la vérification d'emails avec interface utilisateur améliorée
 *
 * Ce script gère la vérification d'emails via Server-Sent Events (SSE) en offrant
 * une interface utilisateur réactive et informative.
 *
 * Note: Ce script s'attend à ce que des constantes soient définies au préalable:
 * - EMAIL_STATUS_DESCRIPTIONS: Descriptions des statuts d'email
 * - RISK_LEVEL_DESCRIPTIONS: Descriptions des niveaux de risque
 * - COMBINED_EXPLANATIONS: Explications combinées (statut + risque)
 * - SMTP_CODE_EXPLANATIONS: Descriptions des codes SMTP
 */
$(document).ready(function () {
    // Vérification que les constantes sont bien définies
    if (
        !window.EMAIL_STATUS_DESCRIPTIONS ||
        !window.RISK_LEVEL_DESCRIPTIONS ||
        !window.COMBINED_EXPLANATIONS ||
        !window.SMTP_CODE_EXPLANATIONS
    ) {
        console.error(
            "Les constantes nécessaires ne sont pas définies. Certaines fonctionnalités peuvent ne pas fonctionner correctement."
        );
    }

    // Cache des sélecteurs DOM fréquemment utilisés
    const $resultsDiv = $("#results");
    const $emailForm = $("#emailForm");
    const $emailInput = $("#email");
    const $submitButton = $emailForm.find('button[type="submit"]');

    // Configuration globale
    const MAX_RECONNECT_ATTEMPTS = 0; // Désactivé par défaut, augmenter si nécessaire
    const THROTTLE_DELAY = 250; // ms - Limite le taux de mise à jour de l'UI

    /**
     * Fonction de throttling pour limiter les mises à jour UI fréquentes
     * Permet d'éviter de surcharger le DOM avec trop de mises à jour
     */
    function throttle(func, limit) {
        let lastFunc;
        let lastRan;
        return function () {
            const context = this;
            const args = arguments;
            if (!lastRan) {
                func.apply(context, args);
                lastRan = Date.now();
            } else {
                clearTimeout(lastFunc);
                lastFunc = setTimeout(function () {
                    if (Date.now() - lastRan >= limit) {
                        func.apply(context, args);
                        lastRan = Date.now();
                    }
                }, limit - (Date.now() - lastRan));
            }
        };
    }

    /**
     * Initialise les tooltips Bootstrap sur les éléments
     * À appeler après avoir ajouté des éléments avec des tooltips
     */
    function initializeTooltips() {
        // Initialiser tous les tooltips nouvellement ajoutés
        $('[data-bs-toggle="tooltip"]').tooltip();
    }

    /**
     * Gestionnaire principal du formulaire
     */
    $emailForm.on("submit", function (e) {
        e.preventDefault();

        const email = $emailInput.val().trim();
        if (!email) return;

        // Réinitialisation de l'interface
        $resultsDiv.html('<ul class="list-group"></ul>');
        $submitButton.prop("disabled", true);

        // Vérifier si la case Mailjet est cochée
        const useMailjet = $("#use-mailjet").is(":checked");

        // Configuration du processus de vérification
        const totalSteps = useMailjet ? 4 : 3; // Format, MX, SMTP, (Mailjet optionnel)
        const completedSteps = {
            format: false,
            mx: false,
            smtp: false,
            mailjet: false,
        };

        // Initialiser la progression
        updateGlobalProgress(0, totalSteps);

        // Établir la connexion SSE
        let evtSource = createEventSource(email, useMailjet);
        let reconnectAttempts = 0;

        // Configuration des gestionnaires d'événements
        setupEventListeners(
            evtSource,
            completedSteps,
            totalSteps,
            reconnectAttempts
        );
    });

    /**
     * Crée une connexion EventSource vers le backend
     */
    function createEventSource(email, useMailjet = false) {
        const mailjetParam = useMailjet ? '&mailjet=1' : '&mailjet=0';
        return new EventSource(
            `verif_email.php?stream=1&email=${encodeURIComponent(email)}${mailjetParam}`
        );
    }

    /**
     * Configure tous les écouteurs d'événements SSE
     */
    function setupEventListeners(
        evtSource,
        completedSteps,
        totalSteps,
        reconnectAttempts
    ) {
        const $listGroup = $resultsDiv.find(".list-group");
        const stepItems = new Map(); // Cache des éléments d'étape

        // Utiliser des versions throttled pour les mises à jour fréquentes
        const throttledJobStatus = throttle(showJobStatus, THROTTLE_DELAY);

        // Gestion des étapes de vérification
        evtSource.addEventListener("step", (e) => {
            const data = JSON.parse(e.data);
            processStepEvent(
                data,
                completedSteps,
                totalSteps,
                stepItems,
                $listGroup
            );
        });

        // Suivi du job Mailjet (throttled)
        evtSource.addEventListener("job_status", (e) => {
            const data = JSON.parse(e.data);
            throttledJobStatus(data, stepItems);
        });

        // Maintien de la connexion
        evtSource.addEventListener("heartbeat", () => {
            // Ne rien faire, juste maintenir la connexion
        });

        // Résultat final
        evtSource.addEventListener("result", (e) => {
            const data = JSON.parse(e.data);
            showFinalResult(data.success, data.message, data.details);
            cleanupEventSource(evtSource);
        });

        // Résultat SMTP spécifique
        evtSource.addEventListener("smtp_result", (e) => {
            const data = JSON.parse(e.data);
            if (!data.success) {
                showSmtpResult(data);
            }
        });

        // Gestion des erreurs et reconnexion
        evtSource.addEventListener("error", (e) => {
            handleEventSourceError(e, evtSource, reconnectAttempts);
        });
    }

    /**
     * Gère les erreurs de la connexion EventSource
     * Tente de reconnecter ou affiche une erreur finale
     */
    function handleEventSourceError(e, evtSource, reconnectAttempts) {
        if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
            // Tentative de reconnexion
            setTimeout(() => {
                reconnectAttempts++;
                const email = $emailInput.val().trim();

                // Message de tentative de reconnexion
                if (!$resultsDiv.find(".reconnect-notice").length) {
                    $resultsDiv.append(`
                        <div class="reconnect-notice alert alert-warning">
                            <i class="bi bi-arrow-repeat"></i> 
                            Tentative de reconnexion (${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})...
                        </div>
                    `);
                } else {
                    $resultsDiv.find(".reconnect-notice").html(`
                        <i class="bi bi-arrow-repeat"></i> 
                        Tentative de reconnexion (${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})...
                    `);
                }

                // Recréer la connexion
                evtSource.close();
                const newSource = createEventSource(email);
                setupEventListeners(
                    newSource,
                    completedSteps,
                    totalSteps,
                    reconnectAttempts
                );
                evtSource = newSource;
            }, 1000 * reconnectAttempts); // Délai exponentiel
        } else {
            // Abandon après MAX_RECONNECT_ATTEMPTS tentatives
            if (e.data) {
                try {
                    const data = JSON.parse(e.data);
                    showFinalResult(
                        false,
                        data.message || "Erreur de vérification",
                        null,
                        data.errorMessage
                    );
                } catch (error) {
                    showFinalResult(
                        false,
                        "Erreur de communication",
                        null,
                        "Impossible de traiter la réponse du serveur"
                    );
                }
            } else {
                showFinalResult(
                    false,
                    "Erreur de connexion",
                    null,
                    "Connexion perdue avec le serveur après plusieurs tentatives"
                );
            }
            cleanupEventSource(evtSource);
        }
    }

    /**
     * Traite les événements d'étape et met à jour la progression
     */
    function processStepEvent(
        data,
        completedSteps,
        totalSteps,
        stepItems,
        $listGroup
    ) {
        // Déterminer quelle étape est concernée et si elle est terminée
        if (
            data.message.includes("Vérification du format") &&
            data.success === true &&
            !completedSteps.format
        ) {
            completedSteps.format = true;
            updateProgressCounter(completedSteps, totalSteps);
        } else if (
            data.message.includes("Vérification des serveurs mail") &&
            data.success === true &&
            !completedSteps.mx
        ) {
            completedSteps.mx = true;
            updateProgressCounter(completedSteps, totalSteps);
        } else if (
            data.message.includes("Test de l'adresse email via SMTP") &&
            data.success !== null &&
            !completedSteps.smtp
        ) {
            completedSteps.smtp = true;
            updateProgressCounter(completedSteps, totalSteps);
        } else if (
            data.message.includes("Vérification avancée Mailjet") &&
            data.success !== null &&
            !completedSteps.mailjet
        ) {
            completedSteps.mailjet = true;
            updateProgressCounter(completedSteps, totalSteps);
        }

        // Afficher ou mettre à jour l'étape
        const key = data.message;
        let $stepItem;

        if (stepItems.has(key)) {
            $stepItem = stepItems.get(key);
        } else {
            $stepItem = $(`
                <li class="list-group-item">
                    <i class="bi bi-hourglass text-primary"></i> 
                    ${data.message}
                    <div class="error-details"></div>
                    <div class="job-status-details d-none"></div>
                    <div class="verification-details d-none"></div>
                </li>
            `);
            $listGroup.append($stepItem);
            stepItems.set(key, $stepItem);
        }

        // Mettre à jour l'icône de statut
        const $icon = $stepItem.find("i").first();
        $icon
            .removeClass()
            .addClass(
                data.success === null
                    ? "bi bi-hourglass text-primary"
                    : data.success
                    ? "bi bi-check-circle-fill text-success"
                    : "bi bi-x-circle-fill text-danger"
            );

        // Afficher le message d'erreur si présent
        if (data.errorMessage) {
            $stepItem.find(".error-details").html(`
                <small class="text-muted mt-2 d-block">${data.errorMessage}</small>
            `);
        }
    }

    /**
     * Met à jour le compteur de progression global
     */
    function updateProgressCounter(completedSteps, totalSteps) {
        const currentStep =
            Object.values(completedSteps).filter(Boolean).length;
        updateGlobalProgress(currentStep, totalSteps);
    }

    /**
     * Nettoie les ressources EventSource et réactive le bouton
     */
    function cleanupEventSource(evtSource) {
        evtSource.close();
        $submitButton.prop("disabled", false);
    }

    /**
     * Met à jour la barre de progression globale de façon optimisée
     * Utilise requestAnimationFrame pour éviter les problèmes de performance
     */
    function updateGlobalProgress(currentStep, totalSteps) {
        requestAnimationFrame(() => {
            let $progressContainer = $(".global-progress-container");

            if (!$progressContainer.length) {
                $progressContainer = $(`
                    <div class="global-progress-container mt-3 mb-3">
                        <small class="text-muted">Étape ${currentStep}/${totalSteps}</small>
                        <div class="progress mt-1" style="height: 8px;">
                            <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" 
                                role="progressbar" 
                                style="width: ${
                                    (currentStep / totalSteps) * 100
                                }%" 
                                aria-valuenow="${currentStep}" aria-valuemin="0" aria-valuemax="${totalSteps}">
                            </div>
                        </div>
                    </div>
                `);

                $resultsDiv.find(".list-group").before($progressContainer);
            } else {
                $progressContainer
                    .find("small")
                    .text(`Étape ${currentStep}/${totalSteps}`);
                $progressContainer
                    .find(".progress-bar")
                    .css("width", `${(currentStep / totalSteps) * 100}%`)
                    .attr("aria-valuenow", currentStep);
            }
        });
    }

    /**
     * Affiche les informations de progression du job de vérification
     */
    function showJobStatus(statusData, stepItems) {
        // Trouver l'élément du job Mailjet
        let $jobItem;
        for (const [key, item] of stepItems.entries()) {
            if (key.includes("Vérification avancée Mailjet")) {
                $jobItem = item;
                break;
            }
        }

        if (!$jobItem) return;

        const $jobStatusDiv = $jobItem.find(".job-status-details");
        $jobStatusDiv.removeClass("d-none");

        // Calculer le pourcentage de progression si disponible
        let progressHtml = "";
        if (
            statusData.progress !== null &&
            typeof statusData.progress === "number"
        ) {
            progressHtml = `
                <div class="progress mt-1" style="height: 4px;">
                    <div class="progress-bar bg-info" role="progressbar" 
                        style="width: ${statusData.progress}%" 
                        aria-valuenow="${statusData.progress}" aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
            `;
        }

        const statusText = `Tentative ${statusData.attempt} - Statut: ${statusData.status}`;
        $jobStatusDiv.html(`
            <div class="mt-2">
                <small class="text-muted">${statusText}</small>
                ${progressHtml}
            </div>
        `);
    }

    /**
     * Affiche le résultat final de la vérification de façon optimisée
     * Inclut des explications détaillées sur la signification des résultats
     */
    function showFinalResult(
        success,
        message,
        details = null,
        errorMessage = null
    ) {
        // Utiliser un fragment pour éviter les reflows multiples
        const fragment = $(document.createDocumentFragment());

        const $alertDiv = $(`
            <div class="alert ${
                success ? "alert-success" : "alert-danger"
            } mt-3">
                <h5 class="alert-heading">
                    <i class="bi bi-${
                        success ? "check-circle-fill" : "x-circle-fill"
                    }"></i>
                    ${
                        success
                            ? "Adresse email valide"
                            : "Adresse email non valide"
                    }
                </h5>
            </div>
        `);

        // Afficher les badges pour le type de résultat et le niveau de risque
        if (details && (details.result || details.risk)) {
            const $badgesContainer = $('<div class="d-flex mt-3 gap-2"></div>');

            // Variables pour l'explication finale
            let resultType = "";
            let riskLevel = "";

            // Déterminer le type de résultat
            if (details.result) {
                let resultClass = "";

                if (details.result.deliverable > 0) {
                    resultType = "Délivrable";
                    resultClass = "success";
                } else if (details.result.catch_all > 0) {
                    resultType = "Catch-all";
                    resultClass = "warning";
                } else if (details.result.undeliverable > 0) {
                    resultType = "Non délivrable";
                    resultClass = "danger";
                } else if (details.result.do_not_send > 0) {
                    resultType = "Ne pas envoyer";
                    resultClass = "danger";
                } else if (details.result.unknown > 0) {
                    resultType = "Inconnu";
                    resultClass = "secondary";
                }

                if (resultType && EMAIL_STATUS_DESCRIPTIONS) {
                    $badgesContainer.append(
                        `<span class="badge bg-${resultClass}" 
                            data-bs-toggle="tooltip" 
                            data-bs-placement="top"
                            title="${
                                EMAIL_STATUS_DESCRIPTIONS[resultType] || ""
                            }">${resultType}</span>`
                    );
                }
            }

            // Déterminer le niveau de risque
            if (details.risk) {
                let riskClass = "";

                if (details.risk.low > 0) {
                    riskLevel = "Faible";
                    riskClass = "success";
                } else if (details.risk.medium > 0) {
                    riskLevel = "Moyen";
                    riskClass = "warning";
                } else if (details.risk.high > 0) {
                    riskLevel = "Élevé";
                    riskClass = "danger";
                } else if (details.risk.unknown > 0) {
                    riskLevel = "Inconnu";
                    riskClass = "secondary";
                }

                if (riskLevel && RISK_LEVEL_DESCRIPTIONS) {
                    $badgesContainer.append(
                        `<span class="badge bg-${riskClass}"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"
                            title="${RISK_LEVEL_DESCRIPTIONS[riskLevel] || ""}"
                            >Risque: ${riskLevel}</span>`
                    );
                }
            }

            $alertDiv.append($badgesContainer);

            // Ajouter une explication détaillée sur la signification des résultats
            let explanationKey = "";

            if (resultType === "Délivrable" && riskLevel) {
                explanationKey = `deliverable_${riskLevel.toLowerCase()}`;
            } else if (resultType) {
                explanationKey = resultType.toLowerCase().replace("-", "_");
            }

            if (
                COMBINED_EXPLANATIONS &&
                COMBINED_EXPLANATIONS[explanationKey]
            ) {
                $alertDiv.append(`
                    <div class="mt-3 small bg-light p-3 rounded">
                        <i class="bi bi-info-circle-fill text-primary"></i>
                        <strong>Ce que cela signifie:</strong> ${COMBINED_EXPLANATIONS[explanationKey]}
                    </div>
                `);
            }
        } else {
            $alertDiv.append(`<p class="mb-0">${message}</p>`);
        }

        // Ajouter le message d'erreur si présent
        if (errorMessage) {
            $alertDiv.append(`
                <div class="mt-2 text-dark small">
                    <strong>Détails:</strong> ${errorMessage}
                </div>
            `);
        }

        fragment.append($alertDiv);
        $resultsDiv.append(fragment);

        // Initialiser les tooltips pour les badges
        initializeTooltips();

        // Faire défiler jusqu'au résultat
        $("html, body").animate(
            {
                scrollTop: $alertDiv.offset().top - 100,
            },
            500
        );
    }

    /**
     * Affiche les résultats SMTP améliorés avec des explications détaillées
     */
    function showSmtpResult(data) {
        // Déterminer la classe et l'icône en fonction du succès
        const headerClass = data.success ? "bg-success" : "bg-warning";
        let confidenceBadge = "";

        // Ajouter un badge de confiance si disponible avec tooltip
        if (data.details && data.details.confidence_level) {
            const confidenceClasses = {
                high: "bg-success",
                medium: "bg-info",
                low: "bg-warning",
                very_low: "bg-danger",
            };
            const confidenceLabels = {
                high: "Confiance élevée",
                medium: "Confiance moyenne",
                low: "Confiance faible",
                very_low: "Confiance très faible",
            };
            const confidenceTooltips = {
                high: "Résultat très fiable, forte probabilité que l'analyse soit correcte",
                medium: "Résultat moyennement fiable",
                low: "Fiabilité limitée, à prendre avec précaution",
                very_low:
                    "Fiabilité très faible, résultat à considérer comme incertain",
            };

            confidenceBadge = `<span class="badge ${
                confidenceClasses[data.details.confidence_level]
            } ms-2" data-bs-toggle="tooltip" title="${
                confidenceTooltips[data.details.confidence_level]
            }">
                ${confidenceLabels[data.details.confidence_level]}
            </span>`;
        }

        // Préparer le message d'avertissement si présent
        let warningHtml = "";
        if (data.details && data.details.warning) {
            warningHtml = `
                <div class="alert alert-warning mt-2 small">
                    <i class="bi bi-exclamation-triangle"></i> ${data.details.warning}
                </div>
            `;
        }

        // Préparer les détails de test DATA si disponibles
        let dataTestHtml = "";
        if (data.details && "data_test_success" in data.details) {
            const dataTestResult = data.details.data_test_success
                ? "Accepté"
                : "Rejeté";
            const dataTestClass = data.details.data_test_success
                ? "text-success"
                : "text-danger";
            const dataTestTooltip = data.details.data_test_success
                ? "Le serveur accepte l'envoi de données à cette adresse"
                : "Le serveur refuse l'envoi de données à cette adresse";

            dataTestHtml = `
                <div class="mt-2 small">
                    <strong>Test DATA:</strong> 
                    <span class="${dataTestClass}" data-bs-toggle="tooltip" title="${dataTestTooltip}">${dataTestResult}</span>
                </div>
            `;

            if (!data.details.data_test_success && data.details.data_response) {
                dataTestHtml += `
                    <small class="text-muted d-block mt-1">
                        ${data.details.data_response}
                    </small>
                `;
            }
        }

        // Ajouter une explication du résultat SMTP
        let smtpExplanationHtml = "";
        if (data.code && SMTP_CODE_EXPLANATIONS) {
            let explanation = SMTP_CODE_EXPLANATIONS[data.code];

            // Si nous avons un code étendu et pas d'explication standard, essayons de trouver l'explication du code étendu
            if (data.extended_code && !explanation) {
                const extendedKey = data.code + "-" + data.extended_code;
                explanation = SMTP_CODE_EXPLANATIONS[extendedKey];
            }

            if (explanation) {
                smtpExplanationHtml = `
                    <div class="mt-3 bg-light p-2 rounded small">
                        <strong>Explication du code ${data.code}${
                    data.extended_code ? " (" + data.extended_code + ")" : ""
                }:</strong> ${explanation}
                    </div>
                `;
            }
        }

        // Créer la carte avec tous les éléments
        const $smtpCard = $(`
            <div class="card mt-3">
                <div class="card-header ${headerClass} text-white">
                    <h5 class="mb-0">Résultat SMTP ${confidenceBadge}</h5>
                </div>
                <div class="card-body">
                    <p>${data.message}</p>
                    ${warningHtml}
                    ${dataTestHtml}
                    ${smtpExplanationHtml}
                    <small class="text-muted d-block mt-2">
                        Code: ${data.code}
                        ${
                            data.extended_code
                                ? ", Code étendu: " + data.extended_code
                                : ""
                        }
                        <br>
                        Réponse: ${
                            data.details
                                ? data.details.response || data.response
                                : data.response
                        }
                    </small>
                </div>
            </div>
        `);

        $resultsDiv.append($smtpCard);

        // Initialiser les tooltips
        initializeTooltips();
    }
});
