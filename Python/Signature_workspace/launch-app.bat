@echo off
echo ================================================
echo    DEMARRAGE DE L'APPLICATION DE SIGNATURES
echo ================================================
echo.

cd /d "%~dp0"
echo Repertoire courant: %CD%

REM Chemin vers l'executable Python
set PYTHON_EXE=venv\Scripts\python.exe

REM Si l'executable Python n'existe pas dans l'environnement virtuel, le créer
if not exist "%PYTHON_EXE%" (
    echo Creation de l'environnement virtuel...
    python -m venv venv
    
    REM Vérifier si la création a réussi
    if not exist "%PYTHON_EXE%" (
        echo Impossible de creer l'environnement virtuel.
        echo Utilisation de Python systeme...
        set PYTHON_EXE=python
    )
)

REM Installation des dépendances
echo Verification des dependances...
"%PYTHON_EXE%" -c "import flask" 2>nul
if %errorlevel% neq 0 (
    echo Installation des packages necessaires...
    if exist "venv\Scripts\pip.exe" (
        venv\Scripts\pip install flask google-auth google-api-python-client beautifulsoup4 requests
    ) else (
        pip install flask google-auth google-api-python-client beautifulsoup4 requests
    )
)

REM Lancement de l'application
echo.
echo Demarrage de l'application...
echo L'application sera accessible a l'adresse http://localhost:5000
echo.
start "" http://localhost:5000

REM Lancer l'application
"%PYTHON_EXE%" signature-manager.py

echo.
echo L'application s'est arretee.
pause