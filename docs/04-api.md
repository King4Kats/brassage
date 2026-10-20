# 04 — API

Toutes les URL sont relatives à `http://localhost:8097/index.php`. Elles demandent d'être **connecté**, soit par la session du navigateur, soit en *basic auth* pour les tests. Les requêtes qui **modifient** quelque chose doivent aussi envoyer le jeton CSRF de Nextcloud.

> 💡 **Pour comprendre — CSRF.** Sans protection, un site piégé pourrait faire envoyer à votre navigateur une requête « supprime cette mesure » vers Nextcloud, et votre session ouverte l'exécuterait. Nextcloud exige donc un jeton (`requesttoken`) que seules ses propres pages connaissent. `src/api.js` l'ajoute automatiquement. Pour tester avec `curl`, l'en-tête `OCS-APIRequest: true` combiné à la basic auth dispense de ce jeton.

```bash
# Exemple : l'atelier vu par Julien
curl -u julien:brassage2026 -H 'OCS-APIRequest: true' \
  http://localhost:8097/index.php/apps/brassage/api/atelier
```

## Format des erreurs

Toutes les erreurs de Brassage ont la forme `{ "erreur": "message lisible" }`, avec le code HTTP adapté :

| Code | Quand |
|---|---|
| 400 | Champ manquant ou valeur hors bornes (ex. densité 1048 au lieu de 1.048) |
| 403 | Connecté, mais pas le rôle suffisant |
| 404 | Introuvable, **ou** pas le droit de voir la machine (on ne révèle pas son existence) |
| 500 | Erreur imprévue : le détail est dans `data/nextcloud.log`, jamais dans la réponse |
| 503 | RoomVox n'est pas activé |

## Routes de Brassage (`/apps/brassage/api/…`)

### `GET /atelier`

Tout ce qu'il faut pour dessiner l'atelier : seulement les machines que l'utilisateur peut voir.

```jsonc
{
  "maintenant": "2026-10-20T09:33:34+00:00",   // heure du serveur (pour recaler le navigateur)
  "equipements": [{
    "roomId": "cuve-de-garde",
    "nom": "Cuve de garde",
    "type": "garde", "typeLibelle": "Cuve de garde", "capaciteL": 200,
    "validationAuto": false,
    "etapes": [{ "nom": "Maturation", "part": 70, "explication": "…" }, …],   // celles qui s'appliquent
    "etapesPersonnalisees": false,               // true = propres à cette machine
    "droits": { "role": "booker", "reserver": true, "gerer": false },
    "nettoyage": { "statut": "occupee", "nettoyeLe": "…", "nettoyePar": "Claire (responsable)" },
    "enCours": {
      "id": "…",            // NOTRE identifiant de réservation (à utiliser dans nos URL)
      "uidCalendrier": "…", // l'UID calendrier brut (à utiliser avec l'API RoomVox)
      "titre": "Brune de Noël",
      "debut": "…", "fin": "…",
      "statut": "confirmee",                     // ou "en_attente"
      "organisateur": { "uid": "claire", "nom": "Claire (responsable)" },
      "brassin": { "id": 5, "nom": "Brune de Noël", "style": "Brune épicée", "couleur": "brune", "volumeL": 180, "notes": null, … },
      "derniereTemperature": { "valeur": 2, "le": "…" },   // dernière mesure du journal, ou null
      "modifiable": false,                       // l'utilisateur peut-il renseigner le contenu ?
      "avancement": {
        "pourcentage": 40, "secondesRestantes": 5187600, "joursRestants": 61,
        "etapeCourante": 0,
        "etapes": [{ "nom": "Maturation", "explication": "…", "debut": "…", "fin": "…", "etat": "en_cours", "pourcentage": 57.1 }, …]
      }
    },
    "prochaine": null                            // même forme que enCours
  }],
  "gabarits": { "brassage": { "libelle": "…", "etapes": [ … ] }, … }
}
```

`nettoyage.statut` vaut `occupee`, `a_nettoyer` ou `propre`.

### `PUT /equipements/{roomId}`, rôle *manager*

Règle la machine. Corps : `{ "nom"?: string, "type": "brassage|fermenteur|garde|alambic", "capaciteL"?: int|null }`. Si `nom` change, la salle est **renommée dans RoomVox**.

### `PUT /equipements/{roomId}/etapes`, rôle *manager*

Remplace les étapes de la machine. Corps : `{ "etapes": [{ "nom": string, "part": int, "explication"?: string }, …] }`.

Règles : de 1 à 12 étapes ; `nom` de 1 à 64 caractères ; `part` entière, entre 1 et 1000 ; `explication` de 500 caractères maximum. Les parts sont relatives : `[1, 3]` donne 25 % et 75 % de la durée. La nouvelle liste s'applique **aussi à la réservation en cours**, puisque l'avancement est recalculé à chaque affichage.

### `DELETE /equipements/{roomId}/etapes`, rôle *manager*

Supprime les étapes personnalisées : la machine reprend celles de son type.

### `POST /equipements/{roomId}/nettoyage`, rôle *booker*

« J'ai nettoyé ». Enregistre la date et l'auteur. Pas de corps.

### `GET /equipements/{roomId}/creneau?debut=…&fin=…`, rôle *booker*

Le créneau respecte-t-il les **horaires d'ouverture** et le **délai maximum** réglés dans RoomVox ? Réponse : `{ "ok": true }` ou `{ "ok": false, "raison": "…" }` (dates ISO 8601, 400 si absentes ou illisibles). Le front l'appelle avant de réserver, parce que l'API de RoomVox ne vérifie pas ces règles ([D13](journal-de-bord.md#d13--vérifier-les-règles-de-réservation-avant-roomvox)). Les **conflits** avec d'autres réservations restent vérifiés par RoomVox.

### `PUT /equipements/{roomId}/reservations/{reservationId}/brassin`, rôle *booker* et organisateur, ou *manager*

`reservationId` = le champ `id` de la réservation dans `GET /atelier`. Pour une réservation simple, c'est son UID calendrier. Pour une **occurrence de série** (« tous les lundis »), c'est l'UID suivi de `#` et de la date de l'occurrence : chaque date a donc son propre contenu.

Crée ou met à jour le contenu d'une réservation. Corps : `{ "nom": string, "style"?: string, "couleur"?: string, "volumeL"?: int, "notes"?: string }`. La réservation doit exister chez RoomVox (sinon 404).

`couleur` ∈ `blanche, blonde, ambree, rousse, brune, noire, distillat`.

### `GET /brassins/{brassinId}/journal`, rôle *viewer*

Les entrées du journal, de la plus ancienne à la plus récente, avec `auteur` (le nom affiché).

### `POST /brassins/{brassinId}/journal`, rôle *booker*

Corps : `{ "type": "densite|temperature|note", "valeur"?: number, "texte"?: string }`.

| type | `valeur` | `texte` |
|---|---|---|
| `densite` | obligatoire, entre 0.98 et 1.2 | optionnel |
| `temperature` | obligatoire, entre −10 et 110 °C | optionnel |
| `note` | ignorée | obligatoire |

### `DELETE /brassins/{brassinId}/journal/{entreeId}`, auteur ou *manager*

## Routes de RoomVox réutilisées

On ne recode pas la réservation : le front appelle directement RoomVox.

| Route | Usage |
|---|---|
| `POST /apps/roomvox/api/rooms/{id}/bookings` `{summary, start, end}` | Réserver. RoomVox renvoie **409** si le créneau chevauche une réservation |
| `POST /apps/roomvox/api/rooms/{id}/bookings/{uid}/respond` `{action: "accept"\|"decline"}` | Valider ou refuser une demande (*manager*). `{uid}` = `uidCalendrier` |
| `DELETE /apps/roomvox/api/rooms/{id}/bookings/{uid}` | Annuler une réservation. Utilisé par les tests seulement ; en 1.6.1, réservé aux responsables (bug P16) |

RoomVox a aussi une **API publique** avec jeton (`/apps/roomvox/api/v1/…`), prévue pour des écrans d'affichage. On ne l'utilise pas, mais elle pourrait servir pour une tablette dans l'atelier. Voir la [doc RoomVox](https://github.com/nextcloud/roomvox/tree/main/docs).

## Commandes `occ`

```bash
docker exec -u www-data brassage-nc php occ brassage:demo --oui [--reinitialiser]
docker exec -u www-data brassage-nc php occ brassage:menage [--simulation] [--reservations-annulees]
```
