#!/usr/bin/env bash
# Lance les tests unitaires PHP de l'app : tous les fichiers app/tests/*Test.php.
# Si PHP 8.2+ est installé sur la machine, on l'utilise ; sinon on passe par
# le PHP du conteneur Nextcloud (aucune installation nécessaire).
# Les tests d'API, eux, sont dans scripts/tester-api.sh.
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
CONTENEUR="${CONTENEUR:-brassage-nc}"
echecs=0

if command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
  for test in "$RACINE"/app/tests/*Test.php; do
    echo "═══ $(basename "$test")"
    php "$test" || echecs=$((echecs + 1))
  done
else
  export MSYS_NO_PATHCONV=1
  SOURCE="$RACINE/app"
  command -v cygpath >/dev/null 2>&1 && SOURCE="$(cygpath -w "$SOURCE")"
  docker exec "$CONTENEUR" rm -rf /tmp/brassage-tests
  docker exec "$CONTENEUR" mkdir -p /tmp/brassage-tests
  docker cp "$SOURCE/lib" "$CONTENEUR:/tmp/brassage-tests/lib"
  docker cp "$SOURCE/tests" "$CONTENEUR:/tmp/brassage-tests/tests"
  for test in "$RACINE"/app/tests/*Test.php; do
    echo "═══ $(basename "$test")"
    docker exec "$CONTENEUR" php "/tmp/brassage-tests/tests/$(basename "$test")" || echecs=$((echecs + 1))
  done
fi

if [[ $echecs -ne 0 ]]; then
  echo "✗ $echecs fichier(s) de tests en échec."
  exit 1
fi
