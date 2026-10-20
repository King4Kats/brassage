# 05 — Développer

## La boucle de travail

```bash
docker compose up -d --build     # une fois : la démo tourne en fond
cd app && npm install && cd ..   # une fois : les dépendances du front

# … modifier du PHP, du JS, du CSS ou un SVG …

./scripts/deployer.sh            # compile le front, copie l'app dans le conteneur (≈ 3 s)
./scripts/deployer.sh --sans-build   # PHP seulement
./scripts/tester.sh              # tests unitaires : avancement, règles de réservation (quelques secondes)
./scripts/tester-api.sh          # tests d'API sur une installation neuve jetable (≈ 1 min 30)
./scripts/tester-api.sh --existant   # idem sur brassage-nc, plus rapide, MAIS réinitialise la démo
```

Ce sont des scripts Bash : sous Linux ou macOS, rien à faire. Sous Windows, les lancer depuis **Git Bash**.

`deployer.sh` copie `app/` dans `custom_apps/brassage` du conteneur (sans `node_modules`, `src`, `tests`). Il lance ensuite `occ app:enable` et `occ upgrade` (pour les migrations), puis recharge Apache pour vider le cache PHP.

### Piège n°1 : le cache du navigateur

Nextcloud ajoute `?v=<empreinte>` aux URL de nos JS et CSS, et cette empreinte dépend de la **version** dans `appinfo/info.xml`. Tant qu'elle ne change pas, le navigateur peut servir **l'ancien** `brassage-main.js`. Deux solutions :
- **monter la version** (par exemple `0.3.0` → `0.3.1`) avant `deployer.sh` ;
- ou forcer le rechargement avec **Ctrl+Maj+R**.

### Piège n°2 : pas de dossier « monté » dans `custom_apps`

Le réflexe Docker serait de monter `./app` dans le conteneur pour voir les changements tout de suite. Mais **un montage dans `custom_apps` casse l'installation initiale** de Nextcloud (« Cannot write into apps directory »). D'où la copie par `deployer.sh`.

### Piège n°3 : le hook `before-starting` et les versions

À chaque démarrage, le conteneur réinstalle l'Agenda, RoomVox et Brassage depuis l'image **si l'image a une version plus récente** que celle installée. Un redémarrage après `deployer.sh` **sans** montée de version n'écrase donc rien. En revanche, un `docker compose up --build` avec une version plus haute dans l'image remplace la copie de travail.

### Piège n°4 (Windows uniquement) : Git Bash et les chemins

Git Bash « traduit » les chemins qui commencent par `/` en chemins Windows, ce qui casse `docker exec … /var/www/html/…`. Les scripts posent `MSYS_NO_PATHCONV=1`. Pour les commandes tapées à la main, utiliser le même préfixe :

```bash
MSYS_NO_PATHCONV=1 docker exec -u www-data brassage-nc php occ app:list
```

## Les tests

| Quoi | Fichier | Lancer | Vérifie |
|---|---|---|---|
| Unitaires | `app/tests/*Test.php` | `./scripts/tester.sh` | Le calcul du %, des jours restants et des étapes ; les horaires d'ouverture et le délai maximum (cas limites et changement d'heure compris) |
| API, de bout en bout | `tests/api/test_api.py` | `./scripts/tester-api.sh` | **Les droits** (qui peut voir / réserver / modifier / supprimer), la validation des saisies, la réservation via RoomVox, les étapes, et indirectement **la passerelle RoomVox** |

Les tests d'API utilisent uniquement la bibliothèque standard de Python 3 (rien à installer). Par défaut, ils construisent l'image et lancent une installation **neuve et jetable** sur le port 8098, supprimée à la fin : la démo en cours n'est jamais touchée.

**Intégration continue** : `.github/workflows/tests.yml` lance les deux à chaque envoi sur GitHub. Un ✗ rouge sur un commit veut dire qu'un test casse.

> 💡 **Pour comprendre — Pourquoi tester l'API « de l'extérieur » ?** Les droits dépendent de plusieurs couches : groupes Nextcloud, rôles RoomVox, nos vérifications. Les tester en appelant la vraie API avec de vrais comptes vérifie l'ensemble, tel que le navigateur le voit. Ces tests passent aussi par les classes internes de RoomVox : si une mise à jour de RoomVox casse notre passerelle, ils échouent.

**Ajouter un test** : une méthode `test_…` dans une classe de `test_api.py`. Les classes s'exécutent dans l'ordre de leur nom (`T1…`, `T2…`). Les tests partent d'une démo fraîche ; un test qui modifie des données dont d'autres tests dépendent les remet en état dans `tearDown()`.

## Tester l'API à la main

```bash
curl -u claire:brassage2026 -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
  -X PUT -d '{"nom":"Fermenteur 3","type":"fermenteur","capaciteL":60}' \
  http://localhost:8097/index.php/apps/brassage/api/equipements/fermenteur-3
```

⚠ Windows uniquement : sous Git Bash, `curl -d` avec des **accents** envoie de l'UTF-8 invalide, et Nextcloud répond 500. Pour tester des accents, passer par le navigateur ou par un petit script Python. Le front, lui, n'a pas ce problème.

## Les journaux

```bash
docker logs -f brassage-nc                                         # Apache + hooks
MSYS_NO_PATHCONV=1 docker exec brassage-nc tail -f /var/www/html/data/nextcloud.log   # erreurs PHP (JSON)
```

Nos erreurs imprévues y apparaissent avec le message `Brassage : erreur inattendue`, et la trace complète.

## Conventions

- **Français partout** : noms de classes métier, variables, commentaires, messages. Exceptions : le vocabulaire imposé par Nextcloud et PHP (`Controller`, `Entity`, `Mapper`, `jsonSerialize`…) et les `id` des SVG, qui viennent du dessin d'origine.
- **Commentaires « pourquoi »**, abondants et pédagogiques, pour qu'un débutant puisse suivre le code de haut en bas. Si un passage surprend, c'est qu'il manque un commentaire : en ajouter un.
- **PHP** : `declare(strict_types=1)` partout, types sur tous les paramètres et retours, une classe par fichier, indentation 4 espaces.
- **JS** : modules ES, indentation par tabulation (comme Nextcloud), aucune variable globale. **Toute donnée serveur passe par `echapper()`** avant d'entrer dans du HTML.
- **CSS** : classes préfixées `brassage-`, nommage BEM (`bloc__element--variante`), couleurs via les variables de thème de Nextcloud.
- **Seul `RoomVoxPasserelle.php` importe `OCA\RoomVox\…`.**
- **Ne jamais modifier une migration déjà livrée** : en créer une nouvelle (`Version000200Date…`).

## Monter de version (Nextcloud, RoomVox, Agenda, Node, Mailpit)

**Toutes les versions sont figées** (voir [D11](journal-de-bord.md#d11--versions-figées)). Une montée de version est donc toujours un changement volontaire, visible dans Git.

**RoomVox ou l'Agenda** :
1. Lire le CHANGELOG ([RoomVox](https://github.com/nextcloud/roomvox/blob/main/CHANGELOG.md), [Agenda](https://github.com/nextcloud/calendar/blob/main/CHANGELOG.md)).
2. Télécharger la nouvelle archive depuis la page *Releases* et calculer son empreinte : `sha256sum fichier.tar.gz`.
3. Dans `docker/Dockerfile`, changer `ROOMVOX_VERSION` / `ROOMVOX_SHA256`, ou `CALENDAR_VERSION` / `CALENDAR_SHA256`.
4. `./scripts/tester-api.sh` : s'il passe, la passerelle est toujours compatible.

**Une image Docker** (Nextcloud, Node, Mailpit) :
```bash
docker pull nextcloud:32.0.16-apache
docker image inspect --format '{{index .RepoDigests 0}}' nextcloud:32.0.16-apache
```
Reporter le tag **et** l'empreinte (`@sha256:…`) dans `docker/Dockerfile` (ou `docker-compose.yml` pour Mailpit), puis `./scripts/tester-api.sh`. Pour Nextcloud, vérifier aussi que RoomVox supporte la version (`<nextcloud min-version max-version>` dans son `info.xml`).

## Passer en production (pistes, pas encore fait)

- Installer l'app dans un vrai Nextcloud : copier `app/` (avec `js/` compilé, sans `node_modules`) dans `custom_apps/brassage`, installer RoomVox et l'Agenda depuis le magasin d'apps, puis `occ app:enable brassage`.
- **Ne pas lancer `occ brassage:demo`** : elle crée de faux comptes avec un mot de passe connu. Elle refuse d'ailleurs de tourner sans l'option `--oui`.
- Base PostgreSQL ou MariaDB : rien à changer dans le code, le QueryBuilder de Nextcloud s'adapte.
