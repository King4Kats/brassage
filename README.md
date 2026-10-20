# Brassage — l'atelier de l'asso dans Nextcloud

Une app Nextcloud pour **partager les machines de l'association** (alambic, cuves de brassage, fermenteurs, cuves de garde) :

- **réserver** une machine depuis l'agenda ou depuis l'atelier ;
- **voir d'un coup d'œil** qui l'utilise, ce qu'il y a dedans et où ça en est (« 40 % — encore 60 jours ») ;
- suivre les **étapes**, noter des **mesures** (densité, température) et le **nettoyage**.

Elle s'appuie sur [RoomVox](https://apps.nextcloud.com/apps/roomvox), une app Nextcloud open source de réservation de salles. Chaque machine est une « salle » RoomVox. Brassage ajoute par-dessus la vue d'atelier animée (GSAP) et ce qui est propre au brassage.

## Lancer la démo (5 minutes)

Il faut **Docker** avec le plugin **Compose** : `docker compose version` doit répondre.

```bash
git clone https://github.com/King4Kats/brassage.git
cd brassage
docker compose up --build
```

Le premier lancement prend 2 à 3 minutes : il construit l'image, installe Nextcloud, l'Agenda, RoomVox et Brassage, puis crée un atelier de démo. On sait que c'est prêt quand le terminal affiche `[brassage] ✓ Démo prête`.

| | Adresse |
|---|---|
| **L'atelier** | http://localhost:8097/apps/brassage/ |
| Gérer les machines (admin) | http://localhost:8097/settings/admin/roomvox |
| Emails envoyés (faux serveur mail) | http://localhost:8026 |

### Comptes de démo

Mot de passe pour tous : `brassage2026`

| Compte | Rôle | Ce qu'il peut faire |
|---|---|---|
| `admin` | Admin Nextcloud | Tout, y compris créer et supprimer des machines dans RoomVox |
| `claire` | Responsable | Valider les demandes, régler les machines, + tout ce que font les brasseurs |
| `julien`, `sofia` | Brasseurs | Réserver, décrire le contenu, ajouter des mesures, déclarer le nettoyage |
| `marc` | Membre | Voir l'atelier, sans rien modifier |

### Commandes utiles

```bash
docker compose down        # arrêter (les données sont gardées)
docker compose down -v     # tout effacer et repartir de zéro au prochain « up »
docker exec -u www-data brassage-nc php occ brassage:demo --oui --reinitialiser   # recréer la démo
./scripts/tester.sh        # tests unitaires (avancement, règles de réservation)
./scripts/tester-api.sh    # tests d'API sur une installation neuve jetable (≈ 1 min 30, Python 3 requis)
```

Les mêmes tests tournent automatiquement sur GitHub à chaque envoi (onglet *Actions*).

## Documentation

Tout est dans [`docs/`](docs/README.md) :

1. [Démarrer](docs/01-demarrer.md) : installation, comptes, ce qu'on peut tester.
2. [Architecture](docs/02-architecture.md) : comment Brassage et RoomVox s'emboîtent, avec schémas.
3. [Front et animations](docs/03-front-et-animations.md) : dessins SVG, GSAP, comment ajouter une machine.
4. [API](docs/04-api.md) : toutes les routes, avec exemples.
5. [Développer](docs/05-developper.md) : boucle de dev, tests, conventions.
6. [Journal de bord](docs/journal-de-bord.md) : **pourquoi** on a fait ces choix, les problèmes rencontrés, les questions ouvertes.

## Licence

AGPL-3.0, comme RoomVox et Nextcloud. L'illustration de l'alambic (`app/src/dessins/alambic.svg`) a été réalisée pour le projet par King4Kats.
