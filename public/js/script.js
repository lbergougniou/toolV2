$(document).ready(function () {
    // Variables globales
    let selectedSdas = [];

    function resetApplication() {
        selectedSdas = [];
        $("#result").html("");
        $("#cableButton").hide();
        $("#quantity").val(1); // Remet la quantité à 1
        $("#confirmationModal").hide();
    }

    // Gestionnaires d'événements pour fermer la modal et réinitialiser
    $("#cancelCable, #closeModal").on("click", function () {
        resetApplication();
    });

    // Fonction de logging
    function log(message) {
        console.log(new Date().toISOString() + ": " + message);
    }
    function showNotification(message) {
        var notification = $("#notification");
        notification.text(message);
        notification.addClass("show");
        setTimeout(function () {
            notification.removeClass("show");
        }, 10000);
    }

    // Initialisation de Select2
    $("#service")
        .select2({
            placeholder: "Sélectionnez un service",
            allowClear: true,
        })
        .on("select2:open", function (e) {
            // Déclencher la focalisation après un court délai
            setTimeout(function () {
                $(".select2-search__field").focus();
            }, 0);
        });

    // Correction pour les appareils mobiles
    $(document).on("select2:open", () => {
        document
            .querySelector(".select2-container--open .select2-search__field")
            .focus();
    });

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

    // Fonction pour générer le message de confirmation
    function generateConfirmationMessage(
        sdaCount,
        prefix,
        serviceLabel,
        sdaList
    ) {
        const sdaPlural = sdaCount > 1 ? "SDA" : "SDA";

        // Version texte brut
        const plainTextMessage = `Bonjour,

Tu trouveras ci-dessous ta commande de ${sdaCount} ${sdaPlural} en ${prefix} sur le service ${serviceLabel.trim()} :
${sdaList.map((sda) => "• " + sda.number).join("\n")}

Belle journée
Luc`;

        // Version HTML pour Gmail
        const htmlMessage = `
<p>Bonjour,</p>
<p>Tu trouveras ci-dessous ta commande de ${sdaCount} ${sdaPlural} en ${prefix} sur le service ${serviceLabel.trim()} :</p>
<ul>
${sdaList.map((sda) => `    <li>${sda.number}</li>`).join("\n")}
</ul>
<p>Belle journée<br>
Luc</p>`;

        return { plainText: plainTextMessage, html: htmlMessage };
    }

    // Fonctions pour gérer l'affichage de la modal
    function showInitialConfirmation() {
        $("#modalTitle").text("Confirmation de câblage");
        $("#initialButtons").show();
        $("#postCableButtons").hide();
        $("#confirmationModal").show();
    }

    function showPostCableConfirmation(message) {
        $("#modalTitle").text("Câblage réussi");
        $("#confirmationMessage").html(message.html);
        $("#initialButtons").hide();
        $("#postCableButtons").show();
    }

    // Gestionnaire d'événement pour le bouton de recherche
    $("#searchButton").on("click", function () {
        const service = $("#service").val();
        const quantity = $("#quantity").val();
        const prefix = $("#prefix").val();

        if (!service || !quantity || !prefix) {
            alert("Veuillez remplir tous les champs avant de rechercher.");
            return;
        }

        // Réinitialiser les résultats précédents
        resetApplication();

        $("#result").html("<p>Recherche des SDA disponibles...</p>");

        $.ajax({
            url: "/toolV2/src/actions/test_availability.php",
            method: "POST",
            data: { quantity, prefix },
            dataType: "json",
        })
            .done(function (response) {
                log("Réponse reçue: " + JSON.stringify(response));
                handleSearchResponse(response);
            })
            .fail(handleAjaxError);
    });

    // Fonction pour gérer la réponse de la recherche
    function handleSearchResponse(response) {
        if (response.error) {
            $("#result").html(
                `<p class="error">${escapeHtml(response.error)}</p>`
            );
            $("#cableButton").hide();
        } else if (response.sdas && response.sdas.length > 0) {
            displaySearchResults(response);
        } else if (response.message) {
            $("#result").html(`<p>${escapeHtml(response.message)}</p>`);
            $("#cableButton").hide();
        } else {
            $("#result").html("<p>Réponse inattendue du serveur</p>");
            $("#cableButton").hide();
        }
    }

    // Fonction pour afficher les résultats de la recherche
    function displaySearchResults(response) {
        let resultHtml = `<h2>SDA sélectionnés :</h2>
            <p>Total disponible : ${response.totalAvailable}, Sélectionnés : ${response.selectedCount}</p>
            <ul>`;

        selectedSdas = response.sdas.map(function (sda) {
            const formattedNumber = "0" + sda.number.substring(3);
            resultHtml += `<li>${escapeHtml(formattedNumber)}</li>`;
            return { number: formattedNumber };
        });

        resultHtml += "</ul>";
        $("#result").html(resultHtml);
        $("#cableButton").show();
    }

    // Gestionnaire d'événement pour le bouton de câblage
    $("#cableButton").on("click", function () {
        const service = $("#service").val();
        const serviceLabel = $("#service option:selected").text();
        const prefix = $("#prefix").val();
        const sdaCount = selectedSdas.length;

        if (!service || sdaCount === 0) {
            alert(
                "Veuillez sélectionner un service et au moins un SDA avant de câbler."
            );
            return;
        }

        const confirmationMessage = `Êtes-vous sûr de vouloir câbler ${sdaCount} SDA(s) en ${prefix} sur le service ${serviceLabel} ?`;
        $("#confirmationMessage").text(confirmationMessage);
        $("#sdaList").html(
            selectedSdas.map((sda) => `<li>${sda.number}</li>`).join("")
        );

        showInitialConfirmation();
    });

    // Gestionnaire d'événement pour confirmer le câblage
    $("#confirmCable").on("click", function () {
        $.ajax({
            url: "/toolV2/src/actions/cable_sda.php",
            method: "POST",
            contentType: "application/json",
            data: JSON.stringify({
                serviceId: $("#service").val(),
                newSdas: selectedSdas.map((sda) => sda.number),
            }),
            dataType: "json",
        })
            .done(handleCableResponse)
            .fail(handleAjaxError);
    });

    // Fonction pour gérer la réponse du câblage
    function handleCableResponse(response) {
        if (response.success) {
            const sdaCount = selectedSdas.length;
            const serviceLabel = $("#service option:selected").text();
            const prefix = $("#prefix").val();

            const messages = generateConfirmationMessage(
                sdaCount,
                prefix,
                serviceLabel,
                selectedSdas
            );

            if (sdaCount > 1) {
                showNotification(
                    "Les " +
                        sdaCount +
                        " en " +
                        prefix +
                        " sont cablées sur le service : " +
                        serviceLabel
                );
            } else {
                showNotification(
                    "La en " +
                        prefix +
                        " est cablées sur le service : " +
                        serviceLabel
                );
            }
            showPostCableConfirmation(messages);
            $("#confirmationModal").data("messages", messages);
        } else {
            alert(
                "Erreur lors du câblage : " +
                    (response.error || "Erreur inconnue")
            );
            resetApplication();
        }
    }

    // Fonction pour gérer les erreurs AJAX
    function handleAjaxError(jqXHR, textStatus, errorThrown) {
        console.error("Erreur AJAX:", textStatus, errorThrown);
        $("#result").html(
            '<p class="error">Une erreur est survenue lors de la recherche.</p>'
        );
        if (jqXHR.responseText) {
            log("Réponse brute: " + jqXHR.responseText);
            $("#result").append(`<pre>${escapeHtml(jqXHR.responseText)}</pre>`);
        }
        $("#cableButton").hide();
    }

    // Gestionnaires d'événements pour fermer la modal
    $("#cancelCable, #closeModal").on("click", function () {
        $("#confirmationModal").hide();
    });

    // Gestionnaire d'événement pour copier le message

    $("#copyMessage").on("click", function () {
        const messages = $("#confirmationModal").data("messages");

        if (navigator.clipboard && navigator.clipboard.write) {
            navigator.clipboard
                .write([
                    new ClipboardItem({
                        "text/plain": new Blob([messages.plainText], {
                            type: "text/plain",
                        }),
                        "text/html": new Blob([messages.html], {
                            type: "text/html",
                        }),
                    }),
                ])
                .then(
                    function () {
                        showNotification(
                            "Message copié dans le presse-papiers !"
                        );
                    },
                    function (err) {
                        console.error("Erreur lors de la copie : ", err);
                        fallbackCopy(messages.plainText);
                    }
                );
        } else {
            fallbackCopy(messages.plainText);
        }
    });

    function fallbackCopy(text) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand("copy");
            showNotification("Message copié dans le presse-papiers !");
        } catch (err) {
            console.error("Erreur lors de la copie : ", err);
            showNotification(
                "Impossible de copier le message. Veuillez le copier manuellement."
            );
        }
        document.body.removeChild(textArea);
    }
});
