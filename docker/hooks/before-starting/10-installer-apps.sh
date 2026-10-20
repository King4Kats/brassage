#!/bin/sh
# Exécuté à CHAQUE démarrage du conteneur (par l'image Nextcloud officielle,
# en tant qu'utilisateur www-data, une fois Nextcloud installé).
#
# Rôle : copier l'Agenda, RoomVox et Brassage depuis l'image (/opt/apps-brassage) vers
# le dossier des apps de Nextcloud (/var/www/html/custom_apps), MAIS seulement
# si la version de l'image est plus récente que celle installée.
#
# Pourquoi « seulement si plus récente » ? Pendant le développement, on
# dépose des versions de travail avec scripts/deployer.sh : un simple
# redémarrage du conteneur ne doit pas les écraser par l'ancienne version
# de l'image.
set -eu

SOURCE=/opt/apps-brassage
CIBLE=/var/www/html/custom_apps
OCC="php /var/www/html/occ"

# Lit <version>…</version> dans le info.xml d'une app (vide si absente).
version_de() {
  [ -f "$1/appinfo/info.xml" ] || { echo ""; return; }
  sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$1/appinfo/info.xml" | head -n 1
}

# Ordre : Agenda et RoomVox d'abord, Brassage en dernier (il a besoin de RoomVox).
for app in calendar roomvox brassage; do
  image=$(version_de "$SOURCE/$app")
  installee=$(version_de "$CIBLE/$app")

  # version_compare de PHP : renvoie 1 si l'image est plus récente.
  if [ -z "$installee" ] || [ "$(php -r "echo (int) version_compare('$image', '$installee', '>');")" = "1" ]; then
    echo "[brassage] $app : installation de la version $image (installée : ${installee:-aucune})"
    rm -rf "${CIBLE:?}/$app"
    cp -R "$SOURCE/$app" "$CIBLE/$app"
  fi

  $OCC app:enable "$app" >/dev/null
done

# Applique les migrations de base de données si une version a changé.
# « No upgrade required » renvoie aussi le code 0, donc pas besoin de
# « || true ». Et surtout PAS de « || true » : si une migration échoue, on
# veut que le conteneur S'ARRÊTE avec un message clair, plutôt que de
# démarrer sur une base à moitié mise à jour (voir journal de bord, P15).
if ! sortie=$($OCC upgrade 2>&1); then
  echo "[brassage] ✗ ÉCHEC de « occ upgrade » : le conteneur s'arrête."
  echo "$sortie"
  exit 1
fi
