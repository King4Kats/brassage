# 01 — Démarrer

## Prérequis

- **Docker** avec le plugin **Compose** v2 (`docker compose version` doit répondre). Sous Linux, si `docker` demande `sudo`, ajouter son compte au groupe `docker`.
- Une connexion Internet pour **construire** l'image : téléchargement des images de base, de RoomVox et de l'app Agenda, toutes à des versions figées. Ensuite, plus besoin d'Internet.
- Les ports **8097** (Nextcloud) et **8026** (Mailpit) libres. Pour les changer, voir `docker-compose.yml`.
- Pour ouvrir la démo **depuis un autre PC** du réseau : `BRASSAGE_DOMAINES="localhost 192.168.1.20" docker compose up --build` (au premier lancement ; ensuite, voir le commentaire dans `docker-compose.yml`).

## Lancer

```bash
docker compose up --build
```

> 💡 **Pour comprendre — Docker en deux phrases.** Une *image* est un modèle figé (« Nextcloud + nos apps »). Un *conteneur* est une copie de ce modèle qui tourne. `--build` reconstruit l'image à partir de `docker/Dockerfile`, et `up` démarre les conteneurs décrits dans `docker-compose.yml`. Les données (comptes, réservations…) vivent dans un *volume* Docker, `nc_data`, qui survit aux redémarrages.

Ce qui se passe au premier lancement, dans l'ordre :

1. **Construction de l'image** (`docker/Dockerfile`) : Node compile le front, puis on part de l'image Nextcloud officielle et on y dépose l'Agenda, RoomVox (versions figées, empreintes SHA-256 vérifiées) et Brassage.
2. **Installation de Nextcloud** par l'image officielle (compte `admin`, base SQLite).
3. **Hook `post-installation`** (`docker/hooks/post-installation/10-configurer-demo.sh`) :
   - langue, fuseau horaire, emails vers Mailpit ;
   - activation de l'Agenda, de RoomVox puis de Brassage ;
   - `occ brassage:demo --oui`, qui crée les données de démo.
4. **Hook `before-starting`**, à chaque démarrage : il met à jour l'Agenda, RoomVox et Brassage si l'image contient une version plus récente, puis applique les migrations. **Si une migration échoue, le conteneur s'arrête** avec le message d'erreur, au lieu de démarrer sur une base incohérente.

C'est prêt quand on lit `[brassage] ✓ Démo prête`.

## Ce qu'il y a dans la démo

Les dates sont calculées **par rapport au jour où on lance la démo** : on voit donc toujours la même situation.

| Machine | Situation |
|---|---|
| **Alambic** | Distillation en cours (Sofia, 2 h faites sur 8) : vapeur, bulles, gouttes animées. Une autre distillation prévue dans 10 jours |
| **Cuve de brassage** | Brassage en cours (Julien). Une demande de Sofia **en attente de validation** dans 5 jours |
| **Fermenteur 1** | Fermentation à environ 43 %, avec une **courbe de densité** dans le journal |
| **Fermenteur 2** | Presque fini (environ 89 %), en garde à froid |
| **Fermenteur 3** | Libre, mais **à nettoyer** : sa dernière utilisation n'a pas été suivie d'un nettoyage |
| **Cuve de garde** | **40 %, environ 60 jours restants** |

## À tester

1. Connecté en `julien`, cliquer sur une machine : le panneau de détail montre les étapes, le contenu, le journal et le nettoyage.
2. Sur le **Fermenteur 3** : « J'ai nettoyé ».
3. **Réserver** une machine depuis le panneau. Un créneau déjà pris est refusé par RoomVox.
4. Ajouter une **mesure de densité** dans le journal du Fermenteur 1.
5. Se connecter en `claire` : sur la **Cuve de brassage**, « Demandes à valider » → Valider. L'email de confirmation arrive dans http://localhost:8026.
6. Se connecter en `marc` : il voit tout, mais aucun bouton d'action n'apparaît.
7. De nouveau en `claire`, ouvrir l'**Alambic** → *Étapes de la machine* : renommer une étape, changer une part, enregistrer. La frise « En ce moment » suit tout de suite.
8. Ouvrir l'app **Agenda**, créer un événement et ajouter une machine comme *ressource* : la réservation apparaît dans l'atelier.

## Repartir de zéro

```bash
docker compose down -v      # -v supprime aussi le volume de données
docker compose up --build
```

Pour seulement recréer la démo, sans réinstaller Nextcloud :

```bash
docker exec -u www-data brassage-nc php occ brassage:demo --oui --reinitialiser
```

## Ajouter ou supprimer une machine

C'est le travail de l'**admin**, dans RoomVox : *Paramètres d'administration → RoomVox* (http://localhost:8097/settings/admin/roomvox).

1. Créer une « salle » avec le nom de la machine.
2. Régler ses **droits par groupe** : `brassage-membres` voient, `brassage-brasseurs` réservent, `brassage-responsables` gèrent. **Sans droits, personne ne la voit dans l'atelier.**
3. Choisir si la réservation est **validée automatiquement** ou par un responsable.
4. Dans Brassage, ouvrir la machine → *Réglages de l'équipement* → choisir son **type** (dessin et étapes) et sa **contenance**. Les étapes peuvent ensuite être adaptées dans *Étapes de la machine*.

Supprimer une machine dans RoomVox supprime aussi son calendrier et ses réservations. Les données Brassage associées se nettoient ensuite avec :

```bash
docker exec -u www-data brassage-nc php occ brassage:menage --simulation   # voir
docker exec -u www-data brassage-nc php occ brassage:menage                # supprimer
```

Le contenu des **réservations annulées** se nettoie avec l'option `--reservations-annulees` (à tester d'abord avec `--simulation` : elle regarde les réservations sur ±5 ans).

Les **horaires d'ouverture** et le **délai maximum** d'une machine se règlent aussi dans RoomVox. Brassage les respecte quand on réserve depuis l'atelier.
