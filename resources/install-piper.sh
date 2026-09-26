#!/usr/bin/env bash
# Télécharge Piper (synthèse vocale locale) et une voix, pour le plugin Sonos.
#
# Usage : install-piper.sh <dossier> <url_archive_piper> <nom_voix> <url_voix_sans_extension>
#
# Aucun paquet système : le binaire publié par Piper embarque onnxruntime et
# espeak-ng. Tout est écrit dans <dossier>, d'abord sous un nom provisoire,
# puis renommé : un téléchargement interrompu ne laisse jamais un modèle
# tronqué que le plugin prendrait pour installé.

set -euo pipefail

DOSSIER="$1"
ARCHIVE="$2"
VOIX="$3"
URL_VOIX="$4"

telecharger() {
    # $1 URL, $2 destination
    if command -v curl >/dev/null 2>&1; then
        curl -fL --retry 3 --connect-timeout 15 -sS -o "$2" "$1"
    else
        wget -q --tries=3 -O "$2" "$1"
    fi
}

echo "$(date '+%F %T') Début"
mkdir -p "$DOSSIER/voices"

if [[ -x "$DOSSIER/piper/piper" ]]; then
    echo "Piper est déjà présent."
else
    echo "Téléchargement de Piper : $ARCHIVE"
    telecharger "$ARCHIVE" "$DOSSIER/piper.tar.gz.part"
    rm -rf "$DOSSIER/piper.new"
    mkdir -p "$DOSSIER/piper.new"
    tar -xzf "$DOSSIER/piper.tar.gz.part" -C "$DOSSIER/piper.new"
    rm -f "$DOSSIER/piper.tar.gz.part"
    rm -rf "$DOSSIER/piper"
    mv "$DOSSIER/piper.new/piper" "$DOSSIER/piper"
    rm -rf "$DOSSIER/piper.new"
    echo "Piper installé."
fi

if [[ -s "$DOSSIER/voices/$VOIX.onnx" && -s "$DOSSIER/voices/$VOIX.onnx.json" ]]; then
    echo "La voix $VOIX est déjà présente."
else
    echo "Téléchargement de la voix $VOIX"
    telecharger "$URL_VOIX.onnx.json" "$DOSSIER/voices/$VOIX.onnx.json.part"
    telecharger "$URL_VOIX.onnx" "$DOSSIER/voices/$VOIX.onnx.part"
    mv "$DOSSIER/voices/$VOIX.onnx.json.part" "$DOSSIER/voices/$VOIX.onnx.json"
    mv "$DOSSIER/voices/$VOIX.onnx.part" "$DOSSIER/voices/$VOIX.onnx"
    echo "Voix $VOIX installée."
fi

# Essai : une phrase courte doit produire un fichier audio.
ESSAI="$(mktemp --suffix=.wav)"
if echo "Essai." | "$DOSSIER/piper/piper" --model "$DOSSIER/voices/$VOIX.onnx" --output_file "$ESSAI" >/dev/null 2>&1 && [[ -s "$ESSAI" ]]; then
    echo "Essai de synthèse réussi."
else
    echo "ERREUR : Piper est installé mais ne produit pas de son sur cette machine."
    rm -f "$ESSAI"
    exit 1
fi
rm -f "$ESSAI"
echo "$(date '+%F %T') Terminé"
