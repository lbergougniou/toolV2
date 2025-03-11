@echo off
echo ================================================
echo    DEMARRAGE DU VERIFICATEUR D'EMAIL
echo ================================================
echo.

SET PORT=3000
SET DOCUMENT_ROOT=C:\Users\Luc\github\toolV2\public

echo Repertoire courant: %CD%
echo.
echo Demarrage du serveur PHP sur le port %PORT%...
echo Dossier racine : "%DOCUMENT_ROOT%"
echo.
echo L'application sera accessible a l'adresse http://localhost:%PORT%/verif_email.php
echo.

REM Ouvrir le navigateur avec l'URL
start "" http://localhost:%PORT%/verif_email.php

REM Lancer le serveur PHP
echo Appuyez sur Ctrl+C pour arreter le serveur.
php -S localhost:%PORT% -t "%DOCUMENT_ROOT%"

echo.
echo Le serveur s'est arrete.
pause