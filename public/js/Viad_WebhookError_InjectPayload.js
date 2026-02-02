/**
 * Injecteur de Payloads ViaDialog
 * Script pour envoyer des payloads JSON à l'endpoint /viaflow/close-call
 */

class PayloadInjector {
    constructor() {
        this.form = document.getElementById("injectionForm");
        this.submitBtn = document.getElementById("submitBtn");
        this.progressContainer = document.getElementById("progressContainer");
        this.resultsContainer = document.getElementById("results");
        this.resultsContent = document.getElementById("resultsContent");
        this.progressBar = document.getElementById("progressBar");

        this.stats = {
            total: document.getElementById("statTotal"),
            success: document.getElementById("statSuccess"),
            error: document.getElementById("statError"),
            processed: document.getElementById("statProcessed"),
        };

        this.successCount = 0;
        this.errorCount = 0;
        this.isRunning = false;

        this.init();
    }

    init() {
        this.form.addEventListener("submit", (e) => this.handleSubmit(e));
    }

    async handleSubmit(e) {
        e.preventDefault();

        if (this.isRunning) {
            return;
        }

        const config = this.getFormConfig();

        // Validation du JSON
        let payloadsArray;
        try {
            payloadsArray = JSON.parse(config.payloadsText);
            if (!Array.isArray(payloadsArray)) {
                throw new Error("Le JSON doit être un array");
            }
        } catch (error) {
            this.showError("Erreur de parsing JSON : " + error.message);
            return;
        }

        // Démarrage
        this.isRunning = true;
        this.submitBtn.disabled = true;
        this.submitBtn.innerHTML =
            '<i class="bi bi-hourglass-split"></i> Injection en cours...';

        // Réinitialisation
        this.resetStats();
        this.showProgress();
        this.stats.total.textContent = payloadsArray.length;

        // Traitement des payloads
        await this.processPayloads(payloadsArray, config);

        // Affichage du résumé
        this.displaySummary(payloadsArray.length);

        // Fin
        this.isRunning = false;
        this.submitBtn.disabled = false;
        this.submitBtn.innerHTML =
            '<i class="bi bi-rocket-takeoff"></i> Lancer l\'injection';
    }

    getFormConfig() {
        return {
            targetUrl: document.getElementById("targetUrl").value,
            authHeader: document.getElementById("authHeader").value,
            payloadsText: document.getElementById("payloads").value,
            delayEnabled: document.getElementById("delayEnabled").checked,
            delay: parseInt(document.getElementById("delay").value) || 0,
        };
    }

    resetStats() {
        this.successCount = 0;
        this.errorCount = 0;
        this.resultsContent.innerHTML = "";
        this.stats.success.textContent = "0";
        this.stats.error.textContent = "0";
        this.stats.processed.textContent = "0";
    }

    showProgress() {
        this.progressContainer.style.display = "block";
        this.resultsContainer.style.display = "block";
    }

    updateProgress(current, total) {
        const progress = Math.round((current / total) * 100);
        this.progressBar.style.width = progress + "%";
        this.progressBar.textContent = progress + "%";
        this.stats.processed.textContent = current;
    }

    async processPayloads(payloadsArray, config) {
        for (let i = 0; i < payloadsArray.length; i++) {
            const item = payloadsArray[i];
            this.updateProgress(i + 1, payloadsArray.length);

            try {
                const payloadData = JSON.parse(item.payload);
                await this.sendPayload(item, payloadData, config, i + 1);
            } catch (error) {
                this.handleError(item, error, i + 1);
            }

            // Délai entre les requêtes
            if (
                config.delayEnabled &&
                config.delay > 0 &&
                i < payloadsArray.length - 1
            ) {
                await this.sleep(config.delay);
            }
        }
    }

    async sendPayload(item, payloadData, config, index) {
        // Utilisation du proxy PHP pour éviter CORS
        const formData = new FormData();
        formData.append("targetUrl", config.targetUrl);
        formData.append("authHeader", config.authHeader);
        formData.append("payload", item.payload);

        const response = await fetch("proxy.php", {
            method: "POST",
            body: formData,
        });

        const result = await response.json();

        if (response.ok && result.httpCode >= 200 && result.httpCode < 300) {
            this.handleSuccess(item, payloadData, result, index);
        } else {
            this.handleHttpError(item, payloadData, result, index);
        }
    }

    handleSuccess(item, payloadData, result, index) {
        this.successCount++;
        this.stats.success.textContent = this.successCount;

        const resultHtml = `
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <h6 class="alert-heading">
                    <i class="bi bi-check-circle-fill"></i> Succès #${index}
                </h6>
                <hr>
                <p class="mb-1">
                    <strong>ID:</strong> ${item.id} | 
                    <strong>Call ID:</strong> ${payloadData.id} | 
                    <strong>Status:</strong> ${result.httpCode || "OK"}
                </p>
                <p class="mb-0">
                    <strong>Service:</strong> ${
                        payloadData.serviceLabel || "N/A"
                    } | 
                    <strong>Event:</strong> ${payloadData.event}
                </p>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        this.prependResult(resultHtml);
    }

    handleHttpError(item, payloadData, result, index) {
        this.errorCount++;
        this.stats.error.textContent = this.errorCount;

        const errorMsg = result.error || result.response || "Erreur inconnue";

        const resultHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <h6 class="alert-heading">
                    <i class="bi bi-x-circle-fill"></i> Erreur HTTP #${index}
                </h6>
                <hr>
                <p class="mb-1">
                    <strong>ID:</strong> ${item.id} | 
                    <strong>Call ID:</strong> ${payloadData.id}
                </p>
                <p class="mb-1">
                    <strong>Status:</strong> ${result.httpCode || "N/A"}
                </p>
                <details>
                    <summary class="text-muted" style="cursor: pointer;">Voir la réponse</summary>
                    <pre class="mt-2 p-2 bg-light border rounded small">${this.escapeHtml(
                        String(errorMsg).substring(0, 500)
                    )}</pre>
                </details>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        this.prependResult(resultHtml);
    }

    handleError(item, error, index) {
        this.errorCount++;
        this.stats.error.textContent = this.errorCount;

        const resultHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <h6 class="alert-heading">
                    <i class="bi bi-exclamation-triangle-fill"></i> Exception #${index}
                </h6>
                <hr>
                <p class="mb-1"><strong>ID:</strong> ${item.id}</p>
                <p class="mb-0"><strong>Erreur:</strong> ${this.escapeHtml(
                    error.message
                )}</p>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        this.prependResult(resultHtml);
    }

    displaySummary(total) {
        const successRate = Math.round((this.successCount / total) * 100);

        const summaryHtml = `
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <h5 class="alert-heading">
                    <i class="bi bi-bar-chart-fill"></i> Résumé de l'injection
                </h5>
                <hr>
                <div class="row text-center">
                    <div class="col-3">
                        <h4 class="mb-0">${total}</h4>
                        <small>Total traité</small>
                    </div>
                    <div class="col-3">
                        <h4 class="text-success mb-0">${this.successCount}</h4>
                        <small>Succès</small>
                    </div>
                    <div class="col-3">
                        <h4 class="text-danger mb-0">${this.errorCount}</h4>
                        <small>Erreurs</small>
                    </div>
                    <div class="col-3">
                        <h4 class="text-primary mb-0">${successRate}%</h4>
                        <small>Taux de réussite</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        this.prependResult(summaryHtml);
    }

    prependResult(html) {
        this.resultsContent.insertAdjacentHTML("afterbegin", html);
    }

    showError(message) {
        alert(message);
    }

    sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialisation au chargement de la page
document.addEventListener("DOMContentLoaded", () => {
    new PayloadInjector();
});
