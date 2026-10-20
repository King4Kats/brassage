# Image Docker « tout compris » : Nextcloud 32 + Agenda + RoomVox + Brassage.
#
# Construite en DEUX étapes (« multi-stage build ») :
#   1. une image Node qui compile le front (Vite) → js/brassage-main.js ;
#   2. l'image Nextcloud officielle, dans laquelle on dépose l'Agenda, RoomVox
#      et notre app déjà compilée. Node n'est pas dans l'image finale : elle reste légère.
#
# Utilisée par docker-compose.yml (« docker compose up --build »).
#
# REPRODUCTIBILITÉ : toutes les versions sont FIGÉES.
# - Les images de base sont désignées par leur tag exact ET leur empreinte
#   (@sha256:…). Le tag est là pour les humains ; c'est l'empreinte qui fait
#   foi : même si quelqu'un republie « 32.0.15-apache », Docker prendra
#   exactement la même image qu'aujourd'hui, sur Linux comme sur Mac (l'empreinte
#   est celle de la liste multi-architecture).
# - Les apps (RoomVox, Agenda) sont téléchargées à une version précise, et
#   leur empreinte SHA-256 est vérifiée.
# Pour monter une version : voir docs/05-developper.md (« Monter de version »).

# ─────────────── Étape 1 : compilation du front ───────────────
# Node 20 : c'est la version qu'attendent les paquets @nextcloud/*.
FROM node:20.20.2-alpine3.23@sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293 AS front
WORKDIR /app
# On copie d'abord SEULEMENT les fichiers de dépendances : tant qu'ils ne
# changent pas, Docker réutilise le cache de « npm ci » (beaucoup plus rapide).
COPY app/package.json app/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY app/vite.config.js ./
COPY app/src ./src
RUN npm run build

# ─────────────── Étape 2 : Nextcloud + apps ───────────────
FROM nextcloud:32.0.15-apache@sha256:45afe3bebd1eef20b402cafcb87206e3d9f3a27dbc514a21ab8e124d97bbfe8c

# RoomVox : version figée + empreinte SHA-256 vérifiée. Si le fichier
# téléchargé était modifié (ou corrompu), la construction échoue.
ARG ROOMVOX_VERSION=1.6.1
ARG ROOMVOX_SHA256=6002c625688f42f2ad2662ae0eb1f72db2cd0db017335cf4d9f36f61b6079de1
RUN set -eux; \
    curl -fsSL -o /tmp/roomvox.tar.gz \
      "https://github.com/nextcloud/roomvox/releases/download/v${ROOMVOX_VERSION}/roomvox-${ROOMVOX_VERSION}.tar.gz"; \
    echo "${ROOMVOX_SHA256}  /tmp/roomvox.tar.gz" | sha256sum -c -; \
    mkdir -p /opt/apps-brassage; \
    tar xzf /tmp/roomvox.tar.gz -C /opt/apps-brassage; \
    rm /tmp/roomvox.tar.gz

# L'app Agenda, même principe. Avant, elle était téléchargée « en dernière
# version » au premier lancement : deux personnes pouvaient donc obtenir deux
# versions différentes. Maintenant elle est figée et vérifiée comme RoomVox.
ARG CALENDAR_VERSION=6.6.2
ARG CALENDAR_SHA256=7e83632d4436d3037a34d1c73cbc06d5ccb6e8fc10f096a86a43a0515588529c
RUN set -eux; \
    curl -fsSL -o /tmp/calendar.tar.gz \
      "https://github.com/nextcloud-releases/calendar/releases/download/v${CALENDAR_VERSION}/calendar-v${CALENDAR_VERSION}.tar.gz"; \
    echo "${CALENDAR_SHA256}  /tmp/calendar.tar.gz" | sha256sum -c -; \
    tar xzf /tmp/calendar.tar.gz -C /opt/apps-brassage; \
    rm /tmp/calendar.tar.gz

# Notre app, sans les sources du front ni node_modules (inutiles à l'exécution).
COPY app/appinfo /opt/apps-brassage/brassage/appinfo
COPY app/lib /opt/apps-brassage/brassage/lib
COPY app/templates /opt/apps-brassage/brassage/templates
COPY app/css /opt/apps-brassage/brassage/css
COPY app/img /opt/apps-brassage/brassage/img
COPY --from=front /app/js /opt/apps-brassage/brassage/js

# Les « hooks » : scripts que l'image Nextcloud exécute à des moments précis.
#   post-installation : une seule fois, juste après la toute première installation ;
#   before-starting   : à CHAQUE démarrage du conteneur.
# Voir docker/hooks/*.sh pour le détail.
COPY docker/hooks/post-installation/ /docker-entrypoint-hooks.d/post-installation/
COPY docker/hooks/before-starting/ /docker-entrypoint-hooks.d/before-starting/
RUN chmod +x /docker-entrypoint-hooks.d/*/*.sh \
    && chown -R www-data:www-data /opt/apps-brassage
