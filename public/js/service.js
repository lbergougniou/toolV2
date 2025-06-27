$(document).ready(function () {
    // Fonction de logging
    function log(message) {
        console.log(new Date().toISOString() + ": " + message);
    }

    // Initialisation de Select2 pour le type de service
    $("#serviceType").select2({
        placeholder: "Sélectionnez un type de service",
        allowClear: true,
        width: "100%",
    });

    // Gestionnaire pour le changement de type de service
    $("#serviceType").on("change", function () {
        const selectedType = $(this).val();
        const description = $(this).find("option:selected").data("description");

        if (selectedType && description) {
            $("#serviceDescription").text(description);

            // Vérifier si ce type de service nécessite un champ SDA
            const serviceConfig = servicesConfig[selectedType];
            if (
                serviceConfig &&
                serviceConfig.config &&
                serviceConfig.config.has_sda_field
            ) {
                $("#sdaNumberGroup").show();
            } else {
                $("#sdaNumberGroup").hide();
                $("#sdaNumber").val(""); // Vider le champ si pas nécessaire
            }
        } else {
            $("#serviceDescription").text("");
            $("#sdaNumberGroup").hide();
            $("#sdaNumber").val("");
        }
    });

    // Gestionnaire pour la soumission du formulaire
    $("#serviceForm").on("submit", function (e) {
        e.preventDefault();

        const serviceType = $("#serviceType").val();
        const idPdv = $("#idPdv").val().trim();
        const sdaNumber = $("#sdaNumber").val().trim();

        if (!serviceType || !idPdv) {
            showAlert(
                "Veuillez remplir tous les champs obligatoires.",
                "warning"
            );
            return;
        }

        // Validation du format ID PDV
        const idPdvPattern = /^[A-Za-z0-9_-]+$/;
        if (!idPdvPattern.test(idPdv)) {
            showAlert(
                "L'ID PDV ne doit contenir que des lettres, chiffres, tirets et underscores.",
                "warning"
            );
            return;
        }

        // Validation du numéro SDA si fourni
        if (sdaNumber && !/^\+33[0-9]{9}$/.test(sdaNumber)) {
            showAlert(
                "Le numéro SDA doit être au format international (+33xxxxxxxxx).",
                "warning"
            );
            return;
        }

        createService(serviceType, idPdv, sdaNumber);
    });

    /**
     * Affiche une alerte Bootstrap
     */
    function showAlert(message, type = "info") {
        const alertClass =
            type === "warning"
                ? "alert-warning"
                : type === "error"
                ? "alert-danger"
                : type === "success"
                ? "alert-success"
                : "alert-info";

        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;

        $("#result").html(alertHtml);
    }

    /**
     * Crée un service via l'API
     */
    function createService(serviceType, idPdv, sdaNumber = "") {
        log(
            `Création du service de type ${serviceType} avec ID PDV: ${idPdv}${
                sdaNumber ? " et SDA: " + sdaNumber : ""
            }`
        );

        // Afficher le loading
        $("#loading").removeClass("d-none");
        $("#createButton").prop("disabled", true);
        $("#result").html("");

        // Préparer les données
        const serviceData = {
            serviceType: serviceType,
            idPdv: idPdv,
            sdaNumber: sdaNumber,
        };

        // Envoyer la requête
        $.ajax({
            url: "../src/actions/create_service.php",
            method: "POST",
            contentType: "application/json",
            data: JSON.stringify(serviceData),
            dataType: "json",
        })
            .done(function (response) {
                log("Réponse reçue: " + JSON.stringify(response));
                handleCreateResponse(response);
            })
            .fail(function (jqXHR, textStatus, errorThrown) {
                console.error("Erreur AJAX:", textStatus, errorThrown);
                handleAjaxError(jqXHR, textStatus, errorThrown);
            })
            .always(function () {
                $("#loading").addClass("d-none");
                $("#createButton").prop("disabled", false);
            });
    }

    /**
     * Gère la réponse de création de service
     */
    function handleCreateResponse(response) {
        if (response.success) {
            let resultHtml = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h4 class="alert-heading">Service créé avec succès !</h4>
                    <hr>
                    <p class="mb-1"><strong>Nom du service :</strong> ${escapeHtml(
                        response.serviceName
                    )}</p>
                    <p class="mb-0"><strong>ID du service :</strong> ${escapeHtml(
                        response.serviceId
                    )}</p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;

            if (response.serviceDetails) {
                resultHtml += `
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">Détails du service</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-sm-3"><strong>Label :</strong></div>
                                <div class="col-sm-9">${escapeHtml(
                                    response.serviceDetails.label
                                )}</div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-sm-3"><strong>Produit :</strong></div>
                                <div class="col-sm-9">${escapeHtml(
                                    response.serviceDetails.product
                                )}</div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-sm-3"><strong>Statut :</strong></div>
                                <div class="col-sm-9">
                                    <span class="badge ${
                                        response.serviceDetails.status ===
                                        "OPEN"
                                            ? "bg-success"
                                            : "bg-secondary"
                                    }">
                                        ${escapeHtml(
                                            response.serviceDetails.status
                                        )}
                                    </span>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-sm-3"><strong>Activé :</strong></div>
                                <div class="col-sm-9">
                                    <span class="badge ${
                                        response.serviceDetails.enable
                                            ? "bg-success"
                                            : "bg-danger"
                                    }">
                                        ${
                                            response.serviceDetails.enable
                                                ? "Oui"
                                                : "Non"
                                        }
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            $("#result").html(resultHtml);

            // Réinitialiser le formulaire
            $("#serviceForm")[0].reset();
            $("#serviceType").val(null).trigger("change");
            $("#serviceDescription").text("");

            log("Service créé avec succès");
        } else {
            showAlert(
                "Erreur : " + escapeHtml(response.error || "Erreur inconnue"),
                "error"
            );
        }
    }

    /**
     * Gère les erreurs AJAX
     */
    function handleAjaxError(jqXHR, textStatus, errorThrown) {
        let errorMessage =
            "Une erreur est survenue lors de la création du service.";

        if (jqXHR.responseText) {
            try {
                const errorData = JSON.parse(jqXHR.responseText);
                errorMessage = errorData.error || errorMessage;
            } catch (e) {
                console.error("Erreur de parsing JSON:", e);
                log("Réponse brute: " + jqXHR.responseText);
                errorMessage +=
                    '<br><pre class="mt-2">' +
                    escapeHtml(jqXHR.responseText) +
                    "</pre>";
            }
        }

        showAlert(errorMessage, "error");
    }

    /**
     * Échappe les caractères HTML pour éviter les injections XSS
     */
    function escapeHtml(unsafe) {
        if (typeof unsafe !== "string") {
            unsafe = String(unsafe);
        }
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
