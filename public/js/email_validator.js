/**
 * email_validator.js - Script de gestion de l'interface utilisateur pour la vérification d'emails
 * 
 * Optimisations:
 * - Code restructuré avec des fonctions plus modulaires
 * - Amélioré la gestion des événements SSE
 * - Éliminé les redondances dans le traitement des étapes
 * - Meilleure séparation des responsabilités
 */
document.addEventListener("DOMContentLoaded", () => {
    // Éléments DOM réutilisés
    const resultsDiv = document.getElementById("results");
    const emailForm = document.getElementById("emailForm");
    
    /**
     * Gestionnaire principal du formulaire
     */
    emailForm.addEventListener("submit", handleFormSubmit);
    
    /**
     * Gère la soumission du formulaire
     * @param {Event} e - L'événement de soumission
     */
    function handleFormSubmit(e) {
        e.preventDefault();
        
        const email = document.getElementById("email").value;
        const submitButton = emailForm.querySelector('button[type="submit"]');
        
        // Réinitialisation de l'interface
        resultsDiv.innerHTML = '<ul class="list-group"></ul>';
        submitButton.disabled = true;
        
        // Configuration du processus de vérification
        const totalSteps = 4; // Format, MX, SMTP, Mailjet
        let currentStep = 0;
        let completedSteps = {
            format: false,
            mx: false,
            smtp: false,
            mailjet: false
        };
        
        // Initialiser la progression
        updateGlobalProgress(currentStep, totalSteps);
        
        // Établir la connexion SSE
        const evtSource = new EventSource(
            `verif_email.php?stream=1&email=${encodeURIComponent(email)}`
        );
        
        // Configuration des gestionnaires d'événements
        setupEventListeners(evtSource, submitButton, totalSteps, completedSteps);
    }
    
    /**
     * Configure tous les écouteurs d'événements SSE
     */
    function setupEventListeners(evtSource, submitButton, totalSteps, completedSteps) {
        // Gestion des étapes de vérification
        evtSource.addEventListener("step", (e) => {
            const data = JSON.parse(e.data);
            processStepEvent(data, totalSteps, completedSteps);
        });
        
        // Suivi du job Mailjet
        evtSource.addEventListener("job_status", (e) => {
            const data = JSON.parse(e.data);
            showJobStatus(data);
        });
        
        // Maintien de la connexion
        evtSource.addEventListener("heartbeat", () => {
            // Ne rien faire, juste maintenir la connexion
        });
        
        // Résultat final
        evtSource.addEventListener("result", (e) => {
            const data = JSON.parse(e.data);
            showFinalResult(data.success, data.message, data.details);
            cleanupEventSource(evtSource, submitButton);
        });
        
        // Résultat SMTP spécifique
        evtSource.addEventListener("smtp_result", (e) => {
            const data = JSON.parse(e.data);
            if (!data.success) {
                showSmtpResult(data);
            }
        });
        
        // Gestion des erreurs
        evtSource.addEventListener("error", (e) => {
            handleEventError(e, evtSource, submitButton);
        });
    }
    
    /**
     * Traite les événements d'étape et met à jour la progression
     */
    function processStepEvent(data, totalSteps, completedSteps) {
        // Déterminer quelle étape est concernée et si elle est terminée
        if (data.message.includes("Vérification du format") && data.success === true && !completedSteps.format) {
            completedSteps.format = true;
            updateProgressCounter(completedSteps, totalSteps);
        } 
        else if (data.message.includes("Vérification des serveurs mail") && data.success === true && !completedSteps.mx) {
            completedSteps.mx = true;
            updateProgressCounter(completedSteps, totalSteps);
        }
        else if (data.message.includes("Test de l'adresse email via SMTP") && data.success !== null && !completedSteps.smtp) {
            completedSteps.smtp = true;
            updateProgressCounter(completedSteps, totalSteps);
        }
        else if (data.message.includes("Vérification avancée Mailjet") && data.success !== null && !completedSteps.mailjet) {
            completedSteps.mailjet = true;
            updateProgressCounter(completedSteps, totalSteps);
        }
        
        // Afficher le résultat de l'étape
        showStepResult(data.message, data.success, null, data.details);
    }
    
    /**
     * Met à jour le compteur de progression global
     */
    function updateProgressCounter(completedSteps, totalSteps) {
        const currentStep = Object.values(completedSteps).filter(Boolean).length;
        updateGlobalProgress(currentStep, totalSteps);
    }
    
    /**
     * Nettoie les ressources EventSource et réactive le bouton
     */
    function cleanupEventSource(evtSource, submitButton) {
        evtSource.close();
        submitButton.disabled = false;
    }
    
    /**
     * Gère les erreurs d'événements SSE
     */
    function handleEventError(e, evtSource, submitButton) {
        if (e.data) {
            const data = JSON.parse(e.data);
            showFinalResult(
                false,
                data.message || "Erreur de vérification",
                null,
                data.errorMessage
            );
        } else {
            showFinalResult(
                false,
                "Erreur de connexion",
                null,
                "Erreur de connexion avec le serveur"
            );
        }
        cleanupEventSource(evtSource, submitButton);
    }
    
    /**
     * Affiche ou met à jour une étape de vérification avec son statut
     */
    function showStepResult(message, success = null, errorMessage = null, details = null) {
        const listGroup = resultsDiv.querySelector(".list-group");
        
        // Rechercher l'élément existant ou en créer un nouveau
        let stepItem = Array.from(
            listGroup.querySelectorAll(".list-group-item")
        ).find((item) => item.textContent.includes(message));
        
        if (!stepItem) {
            listGroup.insertAdjacentHTML("beforeend", `
                <li class="list-group-item">
                    <i class="bi bi-hourglass text-primary"></i> 
                    ${message}
                    <div class="error-details"></div>
                    <div class="job-status-details d-none"></div>
                    <div class="verification-details d-none"></div>
                </li>
            `);
            stepItem = listGroup.lastElementChild;
        }
        
        // Mettre à jour l'icône de statut
        const icon = stepItem.querySelector("i");
        icon.className = success === null 
            ? "bi bi-hourglass text-primary"
            : success 
                ? "bi bi-check-circle-fill text-success" 
                : "bi bi-x-circle-fill text-danger";
        
        // Afficher le message d'erreur si présent
        const errorDetailsDiv = stepItem.querySelector(".error-details");
        if (errorMessage) {
            errorDetailsDiv.innerHTML = `<small class="text-muted mt-2 d-block">${errorMessage}</small>`;
        }
        
        return stepItem;
    }
    
    /**
     * Met à jour la barre de progression globale
     */
    function updateGlobalProgress(currentStep, totalSteps) {
        // Rechercher la barre de progression existante ou en créer une nouvelle
        let progressContainer = document.querySelector(".global-progress-container");
        
        if (!progressContainer) {
            const progressHtml = `
                <div class="global-progress-container mt-3 mb-3">
                    <small class="text-muted">Étape ${currentStep}/${totalSteps}</small>
                    <div class="progress mt-1" style="height: 8px;">
                        <div class="progress-bar bg-primary" role="progressbar" 
                             style="width: ${(currentStep / totalSteps) * 100}%" 
                             aria-valuenow="${currentStep}" aria-valuemin="0" aria-valuemax="${totalSteps}">
                        </div>
                    </div>
                </div>
            `;
            
            const listGroup = resultsDiv.querySelector(".list-group");
            if (listGroup) {
                listGroup.insertAdjacentHTML("beforebegin", progressHtml);
            } else {
                resultsDiv.insertAdjacentHTML("afterbegin", progressHtml);
            }
        } else {
            // Mettre à jour la barre de progression existante
            const progressBar = progressContainer.querySelector(".progress-bar");
            const progressText = progressContainer.querySelector("small");
            
            if (progressText && progressBar) {
                progressText.textContent = `Étape ${currentStep}/${totalSteps}`;
                progressBar.style.width = `${(currentStep / totalSteps) * 100}%`;
                progressBar.setAttribute("aria-valuenow", currentStep);
            }
        }
    }
    
    /**
     * Affiche les informations de progression du job de vérification
     */
    function showJobStatus(statusData) {
        const listGroup = resultsDiv.querySelector(".list-group");
        
        const jobItem = Array.from(
            listGroup.querySelectorAll(".list-group-item")
        ).find((item) => item.textContent.includes("Vérification avancée Mailjet"));
        
        if (!jobItem) return;
        
        const jobStatusDiv = jobItem.querySelector(".job-status-details");
        jobStatusDiv.classList.remove("d-none");
        
        const statusText = `Tentative ${statusData.attempt} - Statut: ${statusData.status}`;
        jobStatusDiv.innerHTML = `<div class="mt-2"><small class="text-muted">${statusText}</small></div>`;
    }
    
    /**
     * Affiche le résultat final de la vérification
     */
    function showFinalResult(success, message, details = null, errorMessage = null) {
        let alertHtml = `
            <div class="alert ${success ? "alert-success" : "alert-danger"} mt-3">
                <h5 class="alert-heading">
                    <i class="bi bi-${success ? "check-circle-fill" : "x-circle-fill"}"></i>
                    ${success ? "Adresse email valide" : "Adresse email non valide"}
                </h5>
        `;
        
        // Afficher les badges pour le type de résultat et le niveau de risque
        if (details && (details.result || details.risk)) {
            let resultType = "";
            let resultClass = "";
            if (details.result) {
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
            }
            
            let riskLevel = "";
            let riskClass = "";
            if (details.risk) {
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
            }
            
            alertHtml += `<div class="d-flex mt-3 gap-2">`;
            
            if (resultType) {
                alertHtml += `<span class="badge bg-${resultClass}">${resultType}</span>`;
            }
            
            if (riskLevel) {
                alertHtml += `<span class="badge bg-${riskClass}">Risque: ${riskLevel}</span>`;
            }
            
            alertHtml += `</div>`;
        } else {
            alertHtml += `<p class="mb-0">${message}</p>`;
        }
        
        alertHtml += `</div>`;
        resultsDiv.insertAdjacentHTML("beforeend", alertHtml);
    }
    
    /**
     * Affiche les résultats SMTP
     */
    function showSmtpResult(data) {
        const smtpHtml = `
            <div class="card mt-3">
                <div class="card-header ${data.success ? "bg-success" : "bg-warning"} text-white">
                    <h5 class="mb-0">Résultat SMTP</h5>
                </div>
                <div class="card-body">
                    <p>${data.message}</p>
                    ${data.details ? `<small class="text-muted">Code: ${data.details.code}, Réponse: ${data.details.response}</small>` : ""}
                </div>
            </div>
        `;
        
        resultsDiv.insertAdjacentHTML("beforeend", smtpHtml);
    }
});