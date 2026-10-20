#!/usr/bin/env bash
# Copie l'app « brassage » dans le conteneur Nextcloud local et la (ré)active.
# À lancer depuis la racine du dépôt après une modification :
#   ./scripts/deployer.sh            → PHP + front (lance npm run build)
#   ./scripts/deployer.sh --sans-build → PHP seulement (front déjà compilé)
#
# Pourquoi docker cp et pas un « volume » monté ? Monter un dossier dans
# custom_apps casse l'installation initiale de Nextcloud (il n'arrive plus à
# écrire dans le dossier des apps). Voir docs/journal-de-bord.md.
set -euo pipefail

CONTENEUR="${CONTENEUR:-brassage-nc}"
RACINE="$(cd "$(dirname "$0")/.." && pwd)"

# Sous Git Bash (Windows), MSYS « traduit » les chemins qui commencent par /
# en chemins Windows, ce qui casse les chemins À L'INTÉRIEUR du conteneur.
# On coupe cette traduction (sans effet sous Linux/macOS).
export MSYS_NO_PATHCONV=1

if [[ "${1:-}" != "--sans-build" ]]; then
  echo "→ Compilation du front (Vite)…"
  (cd "$RACINE/app" && npm run build --silent)
fi

echo "→ Copie de l'app dans $CONTENEUR…"
# On envoie l'app sous forme d'archive tar par l'entrée standard (-i), SANS
# node_modules ni les sources du front (src/) : seulement ce qui sert à
# l'exécution. Puis on remplace l'ancienne version d'un coup, pour ne pas
# laisser traîner des fichiers supprimés entre-temps.
tar -C "$RACINE/app" --exclude=node_modules --exclude=src --exclude=tests -cf - .   | docker exec -i "$CONTENEUR" sh -c '
      rm -rf /tmp/brassage && mkdir /tmp/brassage && tar -xf - -C /tmp/brassage
      rm -rf /var/www/html/custom_apps/brassage
      mv /tmp/brassage /var/www/html/custom_apps/brassage
      chown -R www-data:www-data /var/www/html/custom_apps/brassage
    '

echo "→ Activation / migrations…"
docker exec -u www-data "$CONTENEUR" php occ app:enable brassage
# upgrade : applique les migrations si la version de info.xml a changé.
# Sans « || true » : si une migration échoue, le script s'arrête (set -e) et
# on voit pourquoi, au lieu de continuer sur une base à moitié à jour.
if ! sortie=$(docker exec -u www-data "$CONTENEUR" php occ upgrade 2>&1); then
  echo "✗ ÉCHEC de « occ upgrade » :"
  echo "$sortie"
  exit 1
fi

# Apache garde le PHP compilé en mémoire (opcache) : on le vide pour que nos
# modifications soient prises en compte tout de suite. Ici « || true » est
# volontaire : un rechargement raté n'abîme rien, il retarde juste la prise en
# compte (un « docker restart brassage-nc » fait la même chose en plus fort).
docker exec "$CONTENEUR" sh -c 'apachectl -k graceful 2>/dev/null || true'
echo "✓ Déployé : http://localhost:8097/apps/brassage/"
