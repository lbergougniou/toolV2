/**
 * email_validator.js - Script pour la vérification d'emails
 */
$(document).ready(function () {
    // Cache des sélecteurs DOM fréquemment utilisés
    const $resultsDiv = $("#results");
    const $emailForm = $("#emailForm");
    const $emailInput = $("#email");
    const $submitButton = $emailForm.find('button[type="submit"]');

    // Configuration globale
    const MAX_RECONNECT_ATTEMPTS = 3;
    const THROTTLE_DELAY = 250; // ms

    /**
     * Fonction de throttling pour limiter les mises à jour UI fréquentes
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
     * Gestionnaire principal du formulaire
     */
    $emailForm.on("submit", function (e) {
        e.preventDefault();

        const email = $emailInput.val().trim();
        if (!email) return;

        // Réinitialisation de l'interface
        $resultsDiv.html('<ul class="list-group"></ul>');
        $submitButton.prop("disabled", true);

        // Configuration du processus de vérification
        const totalSteps = 4; // Format, MX, SMTP, Mailjet
        const completedSteps = {
            format: false,
            mx: false,
            smtp: false,
            mailjet: false,
        };

        // Initialiser la progression
        updateGlobalProgress(0, totalSteps);

        // Établir la connexion SSE
        let evtSource = createEventSource(email);
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
     * Crée une connexion EventSource
     */
    function createEventSource(email) {
        return new EventSource(
            `verif_email.php?stream=1&email=${encodeURIComponent(email)}`
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
                }, 1000 * reconnectAttempts);
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
        });
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

        // Nous n'affichons pas les détails de vérification ici car ils seront
        // déjà présents dans le résultat final, pour éviter la duplication
    }

    // La fonction createVerificationDetails a été supprimée car nous n'affichons
    // plus les détails de vérification dans les étapes intermédiaires

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

            // Déterminer le type de résultat
            if (details.result) {
                let resultType = "";
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

                if (resultType) {
                    $badgesContainer.append(
                        `<span class="badge bg-${resultClass}">${resultType}</span>`
                    );
                }
            }

            // Déterminer le niveau de risque
            if (details.risk) {
                let riskLevel = "";
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

                if (riskLevel) {
                    $badgesContainer.append(
                        `<span class="badge bg-${riskClass}">Risque: ${riskLevel}</span>`
                    );
                }
            }

            $alertDiv.append($badgesContainer);
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

        // Faire défiler jusqu'au résultat
        $("html, body").animate(
            {
                scrollTop: $alertDiv.offset().top - 100,
            },
            500
        );
    }

    /**
     * Affiche les résultats SMTP
     */
    function showSmtpResult(data) {
        const $smtpCard = $(`
            <div class="card mt-3">
                <div class="card-header ${
                    data.success ? "bg-success" : "bg-warning"
                } text-white">
                    <h5 class="mb-0">Résultat SMTP</h5>
                </div>
                <div class="card-body">
                    <p>${data.message}</p>
                    ${
                        data.details
                            ? `<small class="text-muted">Code: ${data.details.code}, Réponse: ${data.details.response}</small>`
                            : ""
                    }
                </div>
            </div>
        `);

        $resultsDiv.append($smtpCard);
    }
});
