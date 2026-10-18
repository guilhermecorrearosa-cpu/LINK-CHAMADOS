@echo off
title Painel de Chamados - Mil Copias
cd /d "%~dp0"

echo =========================================================
echo    PAINEL DE CHAMADOS MIL COPIAS (COM DEFEITO)
echo =========================================================
echo.
echo Iniciando o servidor...
echo.

start "" http://localhost:3000
node server.js

pause
