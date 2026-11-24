#!/bin/bash
DIR="${1:-$PWD}"

# Detecta e abre no navegador padrão do sistema
if command -v xdg-open > /dev/null; then
  xdg-open "http://localhost/terminal/index.php?dir=$DIR"
elif command -v gnome-open > /dev/null; then
  gnome-open "http://localhost/terminal/index.php?dir=$DIR"
fi
