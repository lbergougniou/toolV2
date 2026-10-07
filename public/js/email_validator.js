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
 *
 * Toutes les données reçues du serveur (réponses SMTP, DNS, Mailjet) sont échappées
 * avant insertion dans le DOM.
 */
$(document).ready(function () {
    // Vérification que les constantes sont bien définies
    if (
        typeof EMAIL_STATUS_DESCRIPTIONS === "undefined" ||
        typeof RISK_LEVEL_DESCRIPTIONS === "undefined" ||
        typeof COMBINED_EXPLANATIONS === "undefined" ||
        typeof SMTP_CODE_EXPLANATIONS === "undefined"
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
    const THROTTLE_DELAY = 250; // ms - Limite le taux de mise à jour de l'UI

    // Libellés des statuts / risques Mailjet (clés renvoyées par le serveur)
    const MAILJET_STATUS = {
        deliverable: { label: "Délivrable", cls: "success" },
        catch_all: { label: "Catch-all", cls: "warning" },
        undeliverable: { label: "Non délivrable", cls: "danger" },
        do_not_send: { label: "Ne pas envoyer", cls: "danger" },
        unknown: { label: "Inconnu", cls: "secondary" },
    };
    const MAILJET_RISK = {
        low: { label: "Faible", cls: "success" },
        medium: { label: "Moyen", cls: "warning" },
        high: { label: "Élevé", cls: "danger" },
        unknown: { label: "Inconnu", cls: "secondary" },
    };

    // Libellés des statuts SMTP
    const SMTP_STATUS = {
        valid: { label: "Adresse acceptée", cls: "bg-success" },
        catch_all: { label: "Domaine catch-all", cls: "bg-warning" },
        untestable: { label: "Non testable", cls: "bg-secondary" },
        temporary_error: { label: "Erreur temporaire", cls: "bg-warning" },
        sender_rejected: { label: "Expéditeur refusé", cls: "bg-secondary" },
        rejected: { label: "Rejet de politique", cls: "bg-secondary" },
        mailbox_full: { label: "Boîte pleine", cls: "bg-warning" },
        invalid: { label: "Adresse refusée", cls: "bg-danger" },
        unknown_error: { label: "Non concluant", cls: "bg-secondary" },
    };

    /**
     * Échappe une valeur pour une insertion sûre dans du HTML
     */
    function escapeHtml(value) {
        if (value === null || value === undefined) return "";
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

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
        $resultsDiv.find('[data-bs-toggle="tooltip"]').each(function () {
            bootstrap.Tooltip.getOrCreateInstance(this);
        });
    }

    /**
     * Définition des étapes de la checklist (dans l'ordre d'exécution)
     * weight : poids de l'étape dans la barre de progression
     */
    function buildSteps(useMailjet) {
        const steps = [
            { id: "format", label: "Vérification du format", weight: 1 },
            { id: "mx", label: "Serveurs mail du domaine (DNS)", weight: 1 },
            { id: "smtp_connect", label: "Connexion SMTP au serveur destinataire", weight: 2 },
            { id: "smtp_mailbox", label: "Test de la boîte mail (RCPT TO)", weight: 1 },
            { id: "smtp_catchall", label: "Détection catch-all", weight: 1 },
        ];
        // Étapes Mailjet toujours présentes : sans la case cochée, Mailjet sert de repli
        // si le test SMTP n'est pas réalisable (sinon les étapes sont ignorées)
        const suffix = useMailjet ? "" : " (si SMTP non testable)";
        steps.push(
            { id: "mailjet_launch", label: "Envoi à Mailjet" + suffix, weight: 1 },
            { id: "mailjet_job", label: "Analyse Mailjet" + suffix, weight: 6 }
        );
        steps.push({ id: "done", label: "Vérification terminée", weight: 0 });
        return steps;
    }

    // Rendu des statuts d'étape
    const STEP_STATUS = {
        pending: { icon: "bi bi-circle text-secondary", text: "text-muted" },
        running: { icon: null, text: "fw-semibold" },
        success: { icon: "bi bi-check-circle-fill text-success", text: "" },
        warning: { icon: "bi bi-exclamation-triangle-fill text-warning", text: "" },
        error: { icon: "bi bi-x-circle-fill text-danger", text: "" },
        skipped: { icon: "bi bi-dash-circle text-secondary", text: "text-muted text-decoration-line-through" },
    };
    const FINAL_STATUSES = ["success", "warning", "error", "skipped"];

    // Durée typique d'un job Mailjet (pour faire avancer la barre pendant l'attente)
    const MAILJET_EXPECTED_MS = 45000;

    /**
     * Gestionnaire principal du formulaire
     */
    $emailForm.on("submit", function (e) {
        e.preventDefault();

        const email = $emailInput.val().trim();
        if (!email) return;

        $submitButton.prop("disabled", true);

        const useMailjet = $("#use-mailjet").is(":checked");
        const checklist = createChecklist(buildSteps(useMailjet));

        // Établir la connexion SSE
        const evtSource = createEventSource(email, useMailjet);
        setupEventListeners(evtSource, checklist);
    });

    /**
     * Crée une connexion EventSource vers le backend
     */
    function createEventSource(email, useMailjet) {
        const mailjetParam = useMailjet ? "1" : "0";
        return new EventSource(
            `verif_email.php?stream=1&email=${encodeURIComponent(email)}&mailjet=${mailjetParam}`
        );
    }

    /**
     * Affiche la checklist complète (toutes les étapes "en attente") et la barre de progression
     * Retourne un objet de pilotage { setStep, finish }
     */
    function createChecklist(steps) {
        const state = new Map(steps.map((s) => [s.id, "pending"]));
        const totalWeight = steps.reduce((sum, s) => sum + s.weight, 0);
        let mailjetStartedAt = null;
        let mailjetTimer = null;

        $resultsDiv.html(`
            <div class="global-progress-container mt-3 mb-3">
                <div class="d-flex justify-content-between">
                    <small class="progress-label text-muted"></small>
                    <small class="progress-percent text-muted"></small>
                </div>
                <div class="progress mt-1" style="height: 10px;">
                    <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated"
                        role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width: 0%"></div>
                </div>
            </div>
            <ul class="list-group verification-steps"></ul>
        `);

        const $list = $resultsDiv.find(".verification-steps");
        const $bar = $resultsDiv.find(".progress-bar");
        const items = new Map();

        steps.forEach((step) => {
            const $item = $(`
                <li class="list-group-item d-flex align-items-start gap-2">
                    <span class="step-icon" style="width: 1.25rem"></span>
                    <div class="flex-grow-1">
                        <div class="step-label"></div>
                        <small class="step-detail text-muted d-block" style="white-space: pre-wrap"></small>
                        <div class="job-status-details small text-muted d-none"></div>
                    </div>
                </li>
            `);
            $item.find(".step-label").text(step.label);
            $list.append($item);
            items.set(step.id, $item);
            render(step.id, "pending");
        });
        updateProgress();

        function render(id, status, detail, iconOverride) {
            const $item = items.get(id);
            const conf = STEP_STATUS[status] || STEP_STATUS.pending;
            const icon = iconOverride || conf.icon;
            const iconHtml = icon
                ? `<i class="${icon}"></i>`
                : `<span class="spinner-border spinner-border-sm text-primary" role="status"></span>`;

            $item.find(".step-icon").html(iconHtml);
            $item.find(".step-label").attr("class", `step-label ${conf.text}`);
            $item.toggleClass("list-group-item-light", status === "running");
            if (detail !== undefined && detail !== null) {
                $item.find(".step-detail").text(detail);
            }
        }

        function updateProgress() {
            let done = 0;
            let current = null;
            steps.forEach((step) => {
                const status = state.get(step.id);
                if (FINAL_STATUSES.includes(status)) {
                    done += step.weight;
                } else if (status === "running" && !current) {
                    current = step;
                }
            });

            // Pendant l'analyse Mailjet, la barre avance avec le temps écoulé (90 % max de l'étape)
            if (current && current.id === "mailjet_job" && mailjetStartedAt) {
                const ratio = Math.min(0.9, (Date.now() - mailjetStartedAt) / MAILJET_EXPECTED_MS);
                done += current.weight * ratio;
            }

            const percent = totalWeight ? Math.round((done / totalWeight) * 100) : 0;
            const finished = FINAL_STATUSES.includes(state.get("done"));
            const doneCount = steps.filter((s) => s.id !== "done" && FINAL_STATUSES.includes(state.get(s.id))).length;
            const label = finished
                ? "Vérification terminée"
                : current
                ? `Étape ${doneCount + 1}/${steps.length - 1} : ${current.label}`
                : `Étape ${doneCount}/${steps.length - 1}`;

            $bar.css("width", `${percent}%`).attr("aria-valuenow", percent);
            $resultsDiv.find(".progress-label").text(label);
            $resultsDiv.find(".progress-percent").text(`${percent} %`);
        }

        return {
            items,

            /**
             * Met à jour une étape. Les étapes précédentes encore "en attente"
             * sont marquées comme ignorées (elles n'ont pas été exécutées).
             */
            setStep(id, status, detail) {
                if (!state.has(id)) return;

                if (status === "running") {
                    for (const step of steps) {
                        if (step.id === id) break;
                        if (state.get(step.id) === "pending") {
                            state.set(step.id, "skipped");
                            render(step.id, "skipped");
                        }
                    }
                }

                state.set(id, status);
                render(id, status, detail);

                if (id === "mailjet_job") {
                    if (status === "running" && !mailjetTimer) {
                        mailjetStartedAt = Date.now();
                        mailjetTimer = setInterval(updateProgress, 1000);
                    } else if (status !== "running" && mailjetTimer) {
                        clearInterval(mailjetTimer);
                        mailjetTimer = null;
                    }
                }

                updateProgress();
            },

            /**
             * Termine la vérification : étapes restantes ignorées (ou interrompues),
             * barre à 100 % colorée selon le verdict, étape finale cochée
             */
            finish(level, note) {
                if (mailjetTimer) {
                    clearInterval(mailjetTimer);
                    mailjetTimer = null;
                }

                steps.forEach((step) => {
                    const status = state.get(step.id);
                    if (step.id === "done") return;
                    if (status === "running") {
                        state.set(step.id, "error");
                        render(step.id, "error", "Interrompu");
                    } else if (status === "pending") {
                        state.set(step.id, "skipped");
                        render(step.id, "skipped", note || null);
                    }
                });

                const doneStatus = { success: "success", warning: "warning", danger: "error" }[level] || "success";
                // Étape finale toujours cochée, colorée selon le verdict
                state.set("done", doneStatus);
                render("done", doneStatus, null, `bi bi-check-circle-fill text-${level === "danger" ? "danger" : level === "warning" ? "warning" : "success"}`);
                items.get("done").find(".step-label").addClass("fw-semibold");

                $bar
                    .removeClass("progress-bar-striped progress-bar-animated bg-primary")
                    .addClass(`bg-${level === "danger" ? "danger" : level === "warning" ? "warning" : "success"}`)
                    .css("width", "100%");
                updateProgress();
            },
        };
    }

    /**
     * Configure tous les écouteurs d'événements SSE
     */
    function setupEventListeners(evtSource, checklist) {
        let finished = false;

        const finish = (level, note) => {
            finished = true;
            checklist.finish(level, note);
            cleanupEventSource(evtSource);
        };

        // Utiliser des versions throttled pour les mises à jour fréquentes
        const throttledJobStatus = throttle(showJobStatus, THROTTLE_DELAY);

        // Avancement des étapes
        evtSource.addEventListener("step", (e) => {
            const data = JSON.parse(e.data);
            checklist.setStep(data.step, data.status, data.detail);
        });

        // Suivi du job Mailjet (throttled)
        evtSource.addEventListener("job_status", (e) => {
            const data = JSON.parse(e.data);
            throttledJobStatus(data, checklist.items);
        });

        // Maintien de la connexion
        evtSource.addEventListener("heartbeat", () => {});

        // Résultat SMTP détaillé (affiché sauf si l'adresse est simplement acceptée)
        evtSource.addEventListener("smtp_result", (e) => {
            const data = JSON.parse(e.data);
            if (data.status !== "valid") {
                showSmtpResult(data);
            }
        });

        // Résultat final
        evtSource.addEventListener("result", (e) => {
            const data = JSON.parse(e.data);
            const level = data.level || (data.success ? "success" : "danger");
            finish(level, data.from_cache ? "Résultat issu du cache" : null);
            showFinalResult(data);
        });

        // Erreurs : événement "error" envoyé par le serveur (avec data)
        // ou erreur native de connexion (sans data)
        evtSource.addEventListener("error", (e) => {
            if (finished) return;

            let data;
            if (e.data) {
                try {
                    data = JSON.parse(e.data);
                } catch (error) {
                    data = { message: "Erreur de communication", errorMessage: "Impossible de traiter la réponse du serveur" };
                }
            } else {
                data = { message: "Erreur de connexion", errorMessage: "Connexion perdue avec le serveur" };
            }

            finish("danger");
            showFinalResult({
                success: false,
                level: "danger",
                message: data.message || "Erreur de vérification",
                errorMessage: data.errorMessage,
            });
        });
    }

    /**
     * Nettoie les ressources EventSource et réactive le bouton
     */
    function cleanupEventSource(evtSource) {
        evtSource.close();
        $submitButton.prop("disabled", false);
    }

    /**
     * Affiche les informations de progression du job de vérification
     */
    function showJobStatus(statusData, items) {
        const $jobItem = items.get("mailjet_job");
        if (!$jobItem) return;

        const $jobStatusDiv = $jobItem.find(".job-status-details");
        $jobStatusDiv.removeClass("d-none");

        let html = `Tentative ${escapeHtml(statusData.attempt)} - Statut Mailjet : ${escapeHtml(statusData.status)}`;
        if (statusData.error) {
            html += `<span class="text-warning d-block">Erreur Mailjet (nouvel essai) : ${escapeHtml(statusData.error)}</span>`;
        }
        $jobStatusDiv.html(html);
    }

    /**
     * Construit le bloc "Retour Mailjet" : statut, risque, motif et compteurs bruts
     */
    function buildMailjetDetails(details) {
        const status = MAILJET_STATUS[details.status] || MAILJET_STATUS.unknown;
        const risk = MAILJET_RISK[details.risk_level] || MAILJET_RISK.unknown;

        let html = `
            <div class="d-flex mt-3 gap-2">
                <span class="badge bg-${status.cls}" data-bs-toggle="tooltip" data-bs-placement="top"
                    title="${escapeHtml(EMAIL_STATUS_DESCRIPTIONS[status.label] || "")}">${escapeHtml(status.label)}</span>
                <span class="badge bg-${risk.cls}" data-bs-toggle="tooltip" data-bs-placement="top"
                    title="${escapeHtml(RISK_LEVEL_DESCRIPTIONS[risk.label] || "")}">Risque : ${escapeHtml(risk.label)}</span>
            </div>
        `;

        // Explication détaillée sur la signification des résultats
        const explanationKey =
            details.status === "deliverable" ? `deliverable_${details.risk_level}` : details.status;
        if (COMBINED_EXPLANATIONS[explanationKey]) {
            html += `
                <div class="mt-3 small bg-light p-3 rounded">
                    <i class="bi bi-info-circle-fill text-primary"></i>
                    <strong>Ce que cela signifie :</strong> ${escapeHtml(COMBINED_EXPLANATIONS[explanationKey])}
                </div>
            `;
        }

        // Motif renvoyé par Mailjet (erreur du job) et compteurs bruts du résumé
        const counters = (obj) =>
            Object.entries(obj || {})
                .filter(([, v]) => Number(v) > 0)
                .map(([k, v]) => `${escapeHtml(k)}: ${escapeHtml(v)}`)
                .join(", ");
        const resultCounters = counters(details.result);
        const riskCounters = counters(details.risk);

        html += `<div class="mt-2 small text-dark"><strong>Retour Mailjet :</strong>`;
        if (details.job_error) {
            html += `<div class="text-danger">Motif : ${escapeHtml(details.job_error)}</div>`;
        }
        html += `<div class="text-muted">Résultat : ${resultCounters || "aucun"} — Risque : ${riskCounters || "aucun"}</div></div>`;

        return html;
    }

    /**
     * Affiche le résultat final de la vérification
     * data : { success, level (success|warning|danger), message, errorMessage, details }
     */
    function showFinalResult(data) {
        const level = data.level || (data.success ? "success" : "danger");
        const titles = {
            success: "Adresse email valide",
            warning: "Adresse email à vérifier",
            danger: "Adresse email non valide",
        };
        const icons = {
            success: "check-circle-fill",
            warning: "exclamation-triangle-fill",
            danger: "x-circle-fill",
        };

        const $alertDiv = $(`
            <div class="alert alert-${level} mt-3">
                <h5 class="alert-heading">
                    <i class="bi bi-${icons[level]}"></i> ${titles[level]}
                </h5>
                <p class="mb-0">${escapeHtml(data.message)}</p>
            </div>
        `);

        const details = data.details || {};
        if (details.status && (details.result || details.risk)) {
            $alertDiv.append(buildMailjetDetails(details));
        }

        // Ajouter le message d'erreur si présent
        if (data.errorMessage) {
            $alertDiv.append(`
                <div class="mt-2 text-dark small">
                    <strong>Détails :</strong> ${escapeHtml(data.errorMessage)}
                </div>
            `);
        }

        if (data.from_cache) {
            $alertDiv.append(`<div class="mt-2 small text-muted"><i class="bi bi-clock-history"></i> Résultat issu du cache (moins de 10 minutes)</div>`);
        }

        $resultsDiv.append($alertDiv);

        // Initialiser les tooltips pour les badges
        initializeTooltips();

        // Faire défiler jusqu'au résultat
        $("html, body").animate({ scrollTop: $alertDiv.offset().top - 100 }, 500);
    }

    /**
     * Affiche le résultat SMTP détaillé avec explications
     */
    function showSmtpResult(data) {
        const details = data.details || {};
        const status = SMTP_STATUS[data.status] || SMTP_STATUS.unknown_error;

        const confidenceLabels = {
            high: ["bg-success", "Confiance élevée"],
            medium: ["bg-info", "Confiance moyenne"],
            low: ["bg-warning", "Confiance faible"],
            none: ["bg-secondary", "Non concluant"],
        };
        const confidence = confidenceLabels[details.confidence_level];
        const confidenceBadge = confidence
            ? `<span class="badge ${confidence[0]} ms-2">${confidence[1]}</span>`
            : "";

        let warningHtml = "";
        if (details.warning) {
            warningHtml = `
                <div class="alert alert-warning mt-2 small">
                    <i class="bi bi-exclamation-triangle"></i> ${escapeHtml(details.warning)}
                </div>
            `;
        }

        // Explication du code SMTP (code étendu en priorité)
        let smtpExplanationHtml = "";
        if (typeof data.code === "number") {
            const explanation =
                SMTP_CODE_EXPLANATIONS[`${data.code}-${data.extended_code}`] ||
                SMTP_CODE_EXPLANATIONS[data.code];
            if (explanation) {
                smtpExplanationHtml = `
                    <div class="mt-3 bg-light p-2 rounded small">
                        <strong>Explication du code ${escapeHtml(data.code)}${
                            data.extended_code ? " (" + escapeHtml(data.extended_code) + ")" : ""
                        } :</strong> ${escapeHtml(explanation)}
                    </div>
                `;
            }
        }

        const $smtpCard = $(`
            <div class="card mt-3">
                <div class="card-header ${status.cls} text-white">
                    <h5 class="mb-0">Résultat SMTP : ${escapeHtml(status.label)} ${confidenceBadge}</h5>
                </div>
                <div class="card-body">
                    <p>${escapeHtml(data.message)}</p>
                    ${warningHtml}
                    ${smtpExplanationHtml}
                    <small class="text-muted d-block mt-2">
                        ${details.mx_host ? "Serveur : " + escapeHtml(details.mx_host) + "<br>" : ""}
                        Code : ${escapeHtml(data.code)}${data.extended_code ? ", code étendu : " + escapeHtml(data.extended_code) : ""}
                        <br>
                        Réponse : <span style="white-space: pre-wrap">${escapeHtml(data.response)}</span>
                    </small>
                </div>
            </div>
        `);

        $resultsDiv.append($smtpCard);
    }
});
