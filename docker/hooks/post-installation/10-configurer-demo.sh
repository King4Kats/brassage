#!/bin/sh
# Exécuté UNE SEULE FOIS, juste après la toute première installation de
# Nextcloud (volume vide). Prépare une démo prête à l'emploi.
set -eu

OCC="php /var/www/html/occ"

echo "[brassage] Configuration de la démo…"

# Fuseau et langue par défaut : France.
$OCC config:system:set default_language --value=fr
$OCC config:system:set default_locale --value=fr_FR
$OCC config:system:set default_timezone --value=Europe/Paris

# Les emails partent vers Mailpit (un faux serveur mail qui garde tout pour
# qu'on puisse les lire sur http://localhost:8026). Rien ne sort vraiment.
$OCC config:system:set mail_smtpmode --value=smtp
$OCC config:system:set mail_smtphost --value=mailpit
$OCC config:system:set mail_smtpport --value=1025 --type=integer
$OCC config:system:set mail_from_address --value=atelier
$OCC config:system:set mail_domain --value=brassage.test
# L'admin a besoin d'une adresse pour recevoir les notifications de test.
$OCC user:setting admin settings email admin@brassage.test

# La fenêtre « Bienvenue dans Nextcloud Hub » cache l'écran : on la coupe.
# « || true » volontaire : selon les versions de Nextcloud, cette app peut ne
# pas exister, et ce n'est pas une erreur.
$OCC app:disable firstrunwizard >/dev/null 2>&1 || true

# Les apps de l'image (Agenda, RoomVox, Brassage) : même script qu'au
# démarrage. Elles sont toutes DANS l'image, à une version figée : plus
# besoin d'Internet au premier lancement.
sh /docker-entrypoint-hooks.d/before-starting/10-installer-apps.sh

# RoomVox demande à l'admin s'il accepte d'envoyer des statistiques d'usage
# anonymes à ses auteurs (désactivé tant qu'on ne dit pas oui). Sur une démo,
# on ne pose pas la question. Pour l'asso : à décider en connaissance de cause.
$OCC config:app:set roomvox telemetry_never_ask --value=true >/dev/null

# Et enfin les données de démo (--oui : on confirme que c'est bien une démo).
$OCC brassage:demo --oui
echo "[brassage] ✓ Démo prête : http://localhost:8097/apps/brassage/"
