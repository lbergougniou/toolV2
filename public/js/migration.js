$(document).ready(function () {
    console.log("=== SCRIPT MIGRATION CHARGÉ ===");

    // Variables globales
    var selectedSdas = [];

    function resetApplication() {
        selectedSdas = [];
        $("#sdaInput").val("");
        $("#service").val("").trigger("change");
        updateButtonStates();
        // On ne vide plus #result pour garder le message de confirmation
    }

    function updateButtonStates() {
        var serviceSelected = $("#service").val();
        var serviceLabel = $("#service option:selected").text().trim();

        console.log("=== UPDATE BUTTON STATES ===");
        console.log("Service sélectionné:", serviceSelected);
        console.log("Label du service:", serviceLabel);

        // Le bouton supprimer est visible pour tous les services (simplification)
        if (serviceSelected) {
            console.log("Service sélectionné - affichage des boutons");
            $("#deleteButton").show();
            $("#deleteButtonCol").show();
            $("#migrateButtonCol").removeClass("col-12").addClass("col-md-6");
        } else {
            console.log(
                "Aucun service sélectionné - masquage du bouton supprimer"
            );
            $("#deleteButton").hide();
            $("#deleteButtonCol").hide();
            $("#migrateButtonCol").removeClass("col-md-6").addClass("col-12");
        }

        console.log(
            "Bouton supprimer visible:",
            $("#deleteButton").is(":visible")
        );
        console.log("Bouton supprimer existe:", $("#deleteButton").length);
    }

    // Initialisation de Select2 avec thème Bootstrap
    $("#service")
        .select2({
            theme: "bootstrap-5",
            placeholder: "Sélectionnez un service",
            allowClear: true,
            width: "100%",
        })
        .on("select2:open", function (e) {
            setTimeout(function () {
                $(".select2-search__field").focus();
            }, 100);
        })
        .on("change", function () {
            console.log("Service changé, mise à jour des boutons");
            updateButtonStates();
        });

    // Force le focus sur la recherche à chaque ouverture (fix mobile)
    $(document).on("select2:open", function () {
        setTimeout(function () {
            var searchField = document.querySelector(
                ".select2-container--open .select2-search__field"
            );
            if (searchField) {
                searchField.focus();
            }
        }, 150);
    });

    // Initialiser l'état des boutons
    console.log("Initialisation des boutons");
    updateButtonStates();

    // Fonction pour échapper les caractères HTML
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

    // Test d'existence des éléments
    console.log("=== TEST ÉLÉMENTS DOM ===");
    console.log("Bouton migrer existe:", $("#migrateButton").length);
    console.log("Bouton supprimer existe:", $("#deleteButton").length);
    console.log("Service select existe:", $("#service").length);

    // Gestionnaire d'événement pour le bouton de migration
    $("#migrateButton").on("click", function () {
        console.log("=== BOUTON MIGRER CLIQUÉ ===");

        var service = $("#service").val();
        var serviceLabel = $("#service option:selected").text();
        var sdaInput = $("#sdaInput").val();

        if (!service || !sdaInput.trim()) {
            alert(
                "Veuillez sélectionner un service et saisir au moins un SDA."
            );
            return;
        }

        // Parser et valider les SDA
        var numbers = sdaInput
            .replace(/\s/g, "")
            .split(",")
            .filter(function (num) {
                return num.length > 0;
            });
        var validSdas = [];
        var errors = [];

        for (var i = 0; i < numbers.length; i++) {
            var num = numbers[i];
            if (!/^\d{9}$/.test(num)) {
                errors.push(
                    "Numéro " +
                        (i + 1) +
                        " (" +
                        num +
                        ") : doit contenir exactement 9 chiffres"
                );
            } else {
                validSdas.push("+33" + num);
            }
        }

        if (errors.length > 0) {
            alert("Erreurs de format :\n" + errors.join("\n"));
            return;
        }

        selectedSdas = validSdas;

        // Afficher un message de traitement
        $("#result").html(
            '<div class="alert alert-info"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Migration en cours...</div>'
        );

        $.ajax({
            url: "../src/actions/migrate_sda.php",
            method: "POST",
            contentType: "application/json",
            data: JSON.stringify({
                serviceId: service,
                newSdas: selectedSdas,
            }),
            dataType: "json",
            timeout: 30000,
        })
            .done(function (response) {
                console.log("=== REPONSE AJAX MIGRATION ===");
                console.log("response.success:", response.success);
                console.log("response:", response);

                if (response.success) {
                    $("#result").html(
                        '<div class="alert alert-success">' +
                            '<h4 class="alert-heading">✅ Migration réussie !</h4>' +
                            "<p><strong>Service :</strong> " +
                            escapeHtml(serviceLabel) +
                            "</p>" +
                            "<p><strong>" +
                            response.addedSdaCount +
                            "</strong> SDA ont été câblés avec succès !</p>" +
                            "<hr>" +
                            '<div class="row">' +
                            '<div class="col-md-6">' +
                            '<p class="mb-1"><strong>SDA avant :</strong> ' +
                            response.sdaCountBefore +
                            "</p>" +
                            "</div>" +
                            '<div class="col-md-6">' +
                            '<p class="mb-1"><strong>SDA après :</strong> ' +
                            response.sdaCountAfter +
                            "</p>" +
                            "</div>" +
                            "</div>" +
                            '<p class="mb-0"><strong>Total SDA sur le service :</strong> ' +
                            response.totalSdaCount +
                            "</p>" +
                            "</div>"
                    );

                    if (
                        response.alreadyExists &&
                        response.alreadyExists.length > 0
                    ) {
                        $("#result").append(
                            '<div class="alert alert-warning">' +
                                "<strong>ℹ️ Information :</strong> " +
                                response.alreadyExists.length +
                                " SDA étaient déjà câblés sur ce service :<br>" +
                                '<small class="text-muted">' +
                                response.alreadyExists.join(", ") +
                                "</small>" +
                                "</div>"
                        );
                    }

                    resetApplication();
                } else {
                    $("#result").html(
                        '<div class="alert alert-danger">' +
                            '<h4 class="alert-heading">❌ Erreur lors de la migration</h4>' +
                            "<p><strong>Service :</strong> " +
                            escapeHtml(serviceLabel) +
                            "</p>" +
                            '<p class="mb-0">' +
                            escapeHtml(response.error || "Erreur inconnue") +
                            "</p>" +
                            "</div>"
                    );

                    if (
                        response.alreadyExists &&
                        response.alreadyExists.length > 0
                    ) {
                        $("#result").append(
                            '<div class="alert alert-info">' +
                                "<strong>SDA déjà existants :</strong><br>" +
                                "<small>" +
                                response.alreadyExists.join(", ") +
                                "</small>" +
                                "</div>"
                        );
                    }

                    resetApplication();
                }
            })
            .fail(function (jqXHR, textStatus, errorThrown) {
                console.log(
                    "=== ERREUR AJAX MIGRATION ===",
                    textStatus,
                    errorThrown
                );
                $("#result").html(
                    '<div class="alert alert-danger">' +
                        '<h4 class="alert-heading">❌ Erreur lors de la migration</h4>' +
                        "<p>Erreur AJAX : " +
                        escapeHtml(textStatus) +
                        "</p>" +
                        "<details>" +
                        '<summary class="btn btn-outline-danger btn-sm">Détails techniques</summary>' +
                        '<pre class="mt-2 p-2 bg-light rounded small">' +
                        escapeHtml(jqXHR.responseText || "Aucune réponse") +
                        "</pre>" +
                        "</details>" +
                        "</div>"
                );
            });
    });

    // GESTIONNAIRE D'ÉVÉNEMENT POUR LE BOUTON DE SUPPRESSION
    console.log("=== ATTACHEMENT ÉVÉNEMENT BOUTON SUPPRIMER ===");

    // Méthode 1: Event handler direct
    $("#deleteButton").on("click", function () {
        console.log("=== BOUTON SUPPRIMER CLIQUÉ (méthode 1) ===");
        handleDeleteClick();
    });

    // Méthode 2: Event delegation (au cas où le bouton serait ajouté dynamiquement)
    $(document).on("click", "#deleteButton", function () {
        console.log("=== BOUTON SUPPRIMER CLIQUÉ (méthode 2) ===");
        handleDeleteClick();
    });

    // Test du bouton au chargement
    setTimeout(function () {
        console.log("=== TEST BOUTON APRÈS CHARGEMENT ===");
        console.log("Bouton supprimer existe:", $("#deleteButton").length);
        console.log(
            "Bouton supprimer visible:",
            $("#deleteButton").is(":visible")
        );
        console.log(
            "Bouton supprimer HTML:",
            $("#deleteButton")[0]
                ? $("#deleteButton")[0].outerHTML
                : "NON TROUVÉ"
        );

        // Test manuel du clic
        if ($("#deleteButton").length > 0) {
            console.log("Test manuel du clic...");
            // $("#deleteButton")[0].click(); // Décommentez pour test automatique
        }
    }, 2000);

    function handleDeleteClick() {
        console.log("=== FONCTION HANDLE DELETE CLICK ===");

        var service = $("#service").val();
        var serviceLabel = $("#service option:selected").text();

        console.log("Service sélectionné:", service);
        console.log("Label du service:", serviceLabel);

        if (!service) {
            console.log("Aucun service sélectionné - affichage alerte");
            alert("Veuillez sélectionner un service.");
            return;
        }

        console.log("Affichage de la confirmation de suppression");

        // Afficher une modal de confirmation intégrée
        $("#result").html(
            '<div class="alert alert-warning">' +
                '<h4 class="alert-heading">⚠️ Confirmation de suppression</h4>' +
                "<p><strong>Service :</strong> " +
                escapeHtml(serviceLabel) +
                "</p>" +
                '<p class="text-danger"><strong>Cette action est irréversible !</strong></p>' +
                "<hr>" +
                '<div class="d-grid gap-2 d-md-flex justify-content-md-end">' +
                '<button type="button" class="btn btn-secondary me-md-2" id="cancelDelete">Annuler</button>' +
                '<button type="button" class="btn btn-danger" id="confirmDelete">Confirmer la suppression</button>' +
                "</div>" +
                "</div>"
        );

        // Gestionnaire pour annuler
        $("#cancelDelete").on("click", function () {
            console.log("Suppression annulée par l'utilisateur");
            $("#result").html(
                '<div class="alert alert-info">Suppression annulée.</div>'
            );
            setTimeout(function () {
                $("#result").html("");
            }, 2000);
        });

        // Gestionnaire pour confirmer
        $("#confirmDelete").on("click", function () {
            console.log("=== SUPPRESSION CONFIRMÉE ===");

            // Afficher un message de traitement
            $("#result").html(
                '<div class="alert alert-warning">' +
                    '<div class="spinner-border spinner-border-sm me-2" role="status"></div>' +
                    "Suppression en cours par lots...<br>" +
                    '<small class="text-muted">Cette opération peut prendre plusieurs minutes selon le nombre de SDA.</small>' +
                    "</div>"
            );

            console.log("Envoi de la requête AJAX de suppression");
            console.log("URL:", "../src/actions/delete_sda.php");
            console.log("Service ID:", service);

            $.ajax({
                url: "../src/actions/delete_sda.php",
                method: "POST",
                contentType: "application/json",
                data: JSON.stringify({
                    serviceId: service,
                }),
                dataType: "json",
                timeout: 300000, // Timeout de 5 minutes
            })
                .done(function (response) {
                    console.log("=== RÉPONSE SUPPRESSION REÇUE ===");
                    console.log("response:", response);

                    if (response.success) {
                        var resultHtml =
                            '<div class="alert alert-success">' +
                            '<h4 class="alert-heading">✅ Suppression réussie !</h4>' +
                            "<p><strong>Service :</strong> " +
                            escapeHtml(serviceLabel) +
                            "</p>";

                        resultHtml +=
                            "<p><strong>" +
                            response.deletedSdaCount +
                            "</strong> SDA ont été supprimés";

                        if (response.batchesProcessed) {
                            resultHtml +=
                                " en <strong>" +
                                response.batchesProcessed +
                                "</strong> lots";
                            if (response.batchSize) {
                                resultHtml +=
                                    " de <strong>" +
                                    response.batchSize +
                                    "</strong> SDA";
                            }
                        }
                        resultHtml += "</p>";

                        resultHtml +=
                            "<p><strong>Service désactivé :</strong> " +
                            (response.serviceDisabled ? "Oui" : "Non") +
                            "</p>";

                        resultHtml +=
                            "<hr>" +
                            '<div class="row">' +
                            '<div class="col-md-6">' +
                            '<p class="mb-1"><strong>SDA avant :</strong> ' +
                            response.sdaCountBefore +
                            "</p>" +
                            "</div>" +
                            '<div class="col-md-6">' +
                            '<p class="mb-1"><strong>SDA après :</strong> ' +
                            response.sdaCountAfter +
                            "</p>" +
                            "</div>" +
                            "</div>";

                        if (response.batchesProcessed > 1) {
                            resultHtml +=
                                '<div class="alert alert-info mt-2 mb-0">' +
                                "<strong>ℹ️ Information :</strong> La suppression a été effectuée en " +
                                response.batchesProcessed +
                                " lots pour éviter les timeouts." +
                                "</div>";
                        }

                        resultHtml += "</div>";
                        $("#result").html(resultHtml);

                        // Supprimer le service de la liste déroulante
                        $("#service option[value='" + service + "']").remove();
                        $("#service").val("").trigger("change");

                        // Réinitialiser les champs
                        $("#sdaInput").val("");

                        console.log("Suppression terminée avec succès");
                    } else {
                        console.log(
                            "Erreur lors de la suppression:",
                            response.error
                        );

                        $("#result").html(
                            '<div class="alert alert-danger">' +
                                '<h4 class="alert-heading">❌ Erreur lors de la suppression</h4>' +
                                "<p><strong>Service :</strong> " +
                                escapeHtml(serviceLabel) +
                                "</p>" +
                                '<p class="mb-0">' +
                                escapeHtml(
                                    response.error || "Erreur inconnue"
                                ) +
                                "</p>" +
                                "</div>"
                        );
                    }
                })
                .fail(function (jqXHR, textStatus, errorThrown) {
                    console.log("=== ERREUR AJAX SUPPRESSION ===");
                    console.log("textStatus:", textStatus);
                    console.log("errorThrown:", errorThrown);
                    console.log("jqXHR:", jqXHR);
                    console.log("responseText:", jqXHR.responseText);

                    $("#result").html(
                        '<div class="alert alert-danger">' +
                            '<h4 class="alert-heading">❌ Erreur lors de la suppression</h4>' +
                            "<p>Erreur AJAX : " +
                            escapeHtml(textStatus) +
                            "</p>" +
                            "<details>" +
                            '<summary class="btn btn-outline-danger btn-sm">Détails techniques</summary>' +
                            '<pre class="mt-2 p-2 bg-light rounded small">' +
                            escapeHtml(jqXHR.responseText || "Aucune réponse") +
                            "</pre>" +
                            "</details>" +
                            "</div>"
                    );
                });
        });
    }
});
