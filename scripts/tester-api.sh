#!/usr/bin/env bash
# Tests de bout en bout de l'API (tests/api/test_api.py).
#
#   ./scripts/tester-api.sh              → reconstruit l'image et lance un
#                                          conteneur JETABLE sur le port 8098 :
#                                          installation neuve, tests, puis tout
#                                          est supprimé. Ta démo n'est pas touchée.
#   ./scripts/tester-api.sh --existant   → teste le conteneur brassage-nc déjà
#                                          lancé (plus rapide), MAIS réinitialise
#                                          sa démo (tes modifications sont perdues).
#
# C'est aussi ce que lance GitHub Actions à chaque envoi (.github/workflows/tests.yml).
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
export MSYS_NO_PATHCONV=1   # Git Bash (Windows) : ne pas « traduire » les chemins du conteneur

# … mais alors les chemins de NOTRE machine doivent être donnés au format
# Windows à docker et à Python. cygpath n'existe que sous Git Bash : sous
# Linux/macOS, le chemin est renvoyé tel quel.
chemin_hote() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }

# Python 3 : « python3 » sous Linux/macOS, souvent « python » sous Windows.
PYTHON="$(command -v python3 || command -v python || true)"
[ -n "$PYTHON" ] || { echo "✗ Python 3 est nécessaire pour les tests d'API."; exit 1; }

if [[ "${1:-}" == "--existant" ]]; then
  CONTENEUR="${CONTENEUR:-brassage-nc}"
  URL="${BRASSAGE_URL:-http://localhost:8097}"
  echo "→ Réinitialisation de la démo dans $CONTENEUR…"
  docker exec -u www-data "$CONTENEUR" php occ brassage:demo --oui --reinitialiser >/dev/null
else
  CONTENEUR="brassage-test-api"
  PORT="${PORT:-8098}"
  URL="http://localhost:$PORT"
  IMAGE="brassage-demo:test"

  echo "→ Construction de l'image…"
  docker build -q -f "$(chemin_hote "$RACINE/docker/Dockerfile")" -t "$IMAGE" "$(chemin_hote "$RACINE")" >/dev/null

  # Nettoyage garanti à la fin, même si un test échoue (trap … EXIT).
  nettoyer() {
    docker rm -f "$CONTENEUR" >/dev/null 2>&1 || true
    docker volume rm "$CONTENEUR-data" >/dev/null 2>&1 || true
  }
  nettoyer
  trap nettoyer EXIT

  echo "→ Installation neuve dans un conteneur jetable (≈ 1 à 2 min)…"
  docker run -d --name "$CONTENEUR" -p "$PORT:80" \
    -e SQLITE_DATABASE=nextcloud \
    -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=brassage2026 \
    -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
    -v "$CONTENEUR-data:/var/www/html" "$IMAGE" >/dev/null

  # On attend la fin de l'installation, au plus 5 minutes. Si le conteneur
  # s'arrête (hook en échec, migration ratée…), on affiche ses logs et on sort.
  for _ in $(seq 1 100); do
    if [[ "$(docker inspect -f '{{.State.Running}}' "$CONTENEUR")" != "true" ]]; then
      echo "✗ Le conteneur s'est arrêté pendant l'installation :"; docker logs "$CONTENEUR" 2>&1 | tail -40; exit 1
    fi
    docker logs "$CONTENEUR" 2>&1 | grep -q "resuming normal operations" && break
    sleep 3
  done
  docker logs "$CONTENEUR" 2>&1 | grep -q "Démo prête" || { echo "✗ Démo non prête :"; docker logs "$CONTENEUR" 2>&1 | tail -40; exit 1; }

  # Pas de Mailpit ici : on coupe l'envoi des emails (mode « null » de Nextcloud).
  docker exec -u www-data "$CONTENEUR" php occ config:system:set mail_smtpmode --value=null >/dev/null
fi

# Un compte sans aucun groupe, pour vérifier qu'il ne voit rien.
if ! docker exec -u www-data "$CONTENEUR" php occ user:info intrus >/dev/null 2>&1; then
  docker exec -u www-data -e OC_PASS=brassage2026 "$CONTENEUR" php occ user:add --password-from-env intrus >/dev/null
fi

echo "→ Tests d'API sur $URL"
resultat=0
BRASSAGE_URL="$URL" "$PYTHON" -I "$(chemin_hote "$RACINE/tests/api/test_api.py")" || resultat=$?

# En cas d'échec, les dernières erreurs PHP aident souvent à comprendre
# (le conteneur jetable est supprimé juste après : c'est le moment de les lire).
if [[ $resultat -ne 0 ]]; then
  echo "✗ Des tests échouent. Dernières lignes du journal Nextcloud :"
  docker exec "$CONTENEUR" tail -n 20 /var/www/html/data/nextcloud.log || true
fi

if [[ "${1:-}" == "--existant" ]]; then
  echo "→ Remise en état de la démo…"
  docker exec -u www-data "$CONTENEUR" php occ brassage:demo --oui --reinitialiser >/dev/null
fi
exit $resultat
