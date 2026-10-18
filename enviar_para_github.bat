@echo off
title Enviando para o GitHub...
cd /d "%~dp0"

echo =========================================================
echo    ENVIANDO PROJETO PARA O GITHUB (LINK-CHAMADOS)
echo =========================================================
echo.
echo O Git vai abrir uma janela no seu navegador para autorizar o GitHub.
echo.
git push -u origin main
echo.
echo =========================================================
echo    CONCLUIDO!
echo =========================================================
pause
