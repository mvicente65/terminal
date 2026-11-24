@echo off
set DIRETORIO=%~1
if "%DIRETORIO%"=="" set DIRETORIO=%CD%

:: Abre o navegador padrão passando a URL com o diretório codificado
start http://localhost/terminal/index.php?dir=%DIRETORIO%
