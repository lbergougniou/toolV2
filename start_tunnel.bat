@echo off
echo ===============================================
echo Demarrage du tunnel SSH pour Scorimmo PreProd
echo ===============================================
echo.
echo Port local : 13306
echo Serveur distant : scorimmo-preprod8.mysql:3306
echo.
echo Pour arreter le tunnel, fermez cette fenetre ou appuyez sur Ctrl+C
echo.
echo ===============================================
echo.

ssh -L 13306:scorimmo-preprod8.mysql:3306 scorimmopp@scorimmo.gw.oxv.fr -i C:/Users/Luc/.ssh/id_rsa -N

echo.
echo Le tunnel SSH a ete ferme.
pause
