# 02 — Architecture

## L'idée en une phrase

**RoomVox gère les réservations, Brassage gère tout le reste** : contenu, avancement, mesures, nettoyage, et la vue animée. On ne recode pas ce que RoomVox fait déjà bien (créneaux, conflits, droits, validation, emails, synchronisation avec les agendas).

```mermaid
flowchart LR
    subgraph Navigateur
        A[App Agenda<br/>Thunderbird, iPhone…]
        B[Atelier Brassage<br/>JS + GSAP]
    end
    subgraph Nextcloud
        RV[RoomVox<br/>salles · réservations · droits]
        BR[Brassage<br/>PHP]
        CAL[(Calendriers CalDAV<br/>1 par machine)]
        DB[(Tables brassage_*)]
    end
    A -- CalDAV : invite la machine --> RV
    B -- GET /api/atelier, PUT brassin… --> BR
    B -- POST réservation, valider --> RV
    BR -- appels PHP via RoomVoxPasserelle --> RV
    RV --- CAL
    BR --- DB
```

> 💡 **Pour comprendre — CalDAV.** C'est le protocole standard des agendas, comme IMAP pour les emails. Nextcloud stocke les agendas en CalDAV, ce qui permet à n'importe quel client (Apple Calendar, Thunderbird, Outlook…) de les lire et de les modifier. RoomVox crée **un calendrier par salle** : une réservation est simplement un événement dans le calendrier de la machine.

## Qui stocke quoi

| Information | Où | Pourquoi là |
|---|---|---|
| La machine (nom, validation auto ou non) | RoomVox (`oc_appconfig`) | C'est une « salle » RoomVox, visible aussi depuis l'Agenda |
| Les droits (qui voit / réserve / gère) | RoomVox (`oc_appconfig`) | On réutilise son système de rôles par groupe |
| Les réservations (qui, quand, statut) | Calendrier CalDAV de la machine | C'est le principe de RoomVox ; c'est la **source de vérité** |
| Type de machine, contenance, dernier nettoyage | `oc_brassage_equipements` | RoomVox ne connaît que des salles de réunion |
| Contenu d'une réservation (bière, style, volume, notes) | `oc_brassage_brassins` | Spécifique au brassage |
| Mesures et notes | `oc_brassage_journal` | Spécifique au brassage |
| Étapes **par défaut** d'un type de machine | Code : `lib/Service/Gabarits.php` | Valeurs de démo, à faire valider ([Q2](journal-de-bord.md#questions-ouvertes)) |
| Étapes **personnalisées** d'une machine | `oc_brassage_etapes` | Un·e responsable les règle dans le panneau ; sans elles, on prend celles du type ([D10](journal-de-bord.md#d10--étapes-modifiables-par-machine)) |

### Le lien entre les deux mondes

```mermaid
erDiagram
    SALLE_ROOMVOX ||--o| brassage_equipements : "room_id"
    SALLE_ROOMVOX ||--o{ RESERVATION_CALDAV : "1 calendrier"
    RESERVATION_CALDAV ||--o| brassage_brassins : "room_id + booking_uid (id de réservation)"
    brassage_brassins ||--o{ brassage_journal : "brassin_id"
    SALLE_ROOMVOX ||--o{ brassage_etapes : "room_id (optionnel)"

    brassage_equipements {
        bigint id PK
        string room_id UK "id de la salle RoomVox"
        string type "brassage | fermenteur | garde | alambic"
        int capacite_l
        datetime nettoye_le
        string nettoye_par
    }
    brassage_brassins {
        bigint id PK
        string room_id
        string booking_uid "id de réservation : UID, ou UID#date pour une série"
        string nom
        string style
        string couleur "clé de palette"
        int volume_l
        text notes
        string user_id
    }
    brassage_etapes {
        bigint id PK
        string room_id
        smallint ordre "0, 1, 2…"
        string nom
        smallint part "part de la durée"
        text explication
    }
    brassage_journal {
        bigint id PK
        bigint brassin_id FK
        string type "note | densite | temperature"
        float valeur
        text texte
        string user_id
        datetime cree_le
    }
```

Il n'y a **pas de clé étrangère** vers RoomVox : ses données ne sont pas dans des tables SQL qu'on pourrait référencer. Conséquences :
- une machine **sans ligne** dans `brassage_equipements` est traitée comme un « Fermenteur » par défaut ;
- une machine **supprimée** dans RoomVox laisse des lignes orphelines chez nous. Elles sont ignorées à l'affichage, et `occ brassage:menage` les supprime ;
- de même pour une **réservation annulée** : son contenu reste en base jusqu'à `occ brassage:menage --reservations-annulees`.

## Les fichiers PHP (`app/lib/`)

```
lib/
├── AppInfo/Application.php      point d'entrée de l'app pour Nextcloud
├── Controller/                  portes d'entrée HTTP (une méthode = une route)
│   ├── ControleurBase.php       commun : erreurs → JSON, vérification des rôles
│   ├── PageController.php       la page HTML
│   ├── AtelierController.php    GET /api/atelier
│   ├── EquipementController.php réglages, étapes, nettoyage, vérification de créneau
│   ├── BrassinController.php    contenu d'une réservation
│   └── JournalController.php    mesures et notes
├── Service/
│   ├── RoomVoxPasserelle.php    ⚠ LE SEUL fichier qui connaît les classes de RoomVox
│   ├── AtelierService.php       assemble la « photo » de l'atelier pour un utilisateur
│   ├── Avancement.php           calcul du % et des étapes (pur, testé)
│   ├── Etapes.php               quelles étapes appliquer (personnalisées ou par défaut) + validation
│   ├── ReglesReservation.php    horaires d'ouverture + délai maximum (pur, testé)
│   └── Gabarits.php             étapes par défaut de chaque type de machine
├── Db/                          une Entity + un Mapper par table
├── Migration/                   création des tables
├── Exception/ErreurApi.php      erreurs « attendues » (400, 403, 404…)
└── Command/                     occ brassage:demo, occ brassage:menage
```

> 💡 **Pour comprendre — Contrôleur, Service, Mapper.** C'est le découpage classique d'une app web, en trois couches :
> - le **contrôleur** reçoit la requête HTTP et renvoie la réponse ;
> - le **service** contient la logique (« quelle réservation est en cours ? ») ;
> - le **mapper** lit et écrit en base.
>
> Chaque couche ne parle qu'à la suivante. On sait ainsi toujours où chercher.

> 💡 **Pour comprendre — Injection de dépendances.** Aucun fichier n'écrit `new AtelierService(...)`. Les classes déclarent ce dont elles ont besoin dans leur constructeur, et **Nextcloud les fabrique tout seul** en lisant les types des paramètres (c'est l'« autowiring »). C'est aussi ce qui permettrait de remplacer l'horloge (`ITimeFactory`) par une fausse dans des tests.

### La passerelle RoomVox

`RoomVoxPasserelle` est un **adaptateur** : il traduit le vocabulaire de RoomVox (`rooms`, `bookings`, `PARTSTAT=ACCEPTED`) en le nôtre (`salles`, `réservations`, `statut: confirmee`).

On y appelle **directement les services PHP de RoomVox** (`RoomService`, `CalDAVService`, `PermissionService`) au lieu de son API HTTP. C'est plus rapide et il n'y a pas de jeton à gérer. Le risque : ce ne sont **pas des API publiques garanties**, et une mise à jour de RoomVox peut les changer. D'où la règle : **aucun autre fichier n'importe `OCA\RoomVox\…`**. Si RoomVox change, on ne répare qu'ici. Voir [le journal](journal-de-bord.md#d3--appeler-roomvox-en-php-via-une-passerelle).

## Le parcours d'une réservation

```mermaid
sequenceDiagram
    actor J as Julien
    participant F as Front Brassage
    participant RV as RoomVox (API HTTP)
    participant BR as Brassage (API)
    participant CAL as Calendrier de la machine
    actor C as Claire (responsable)

    J->>F: « Réserver » (dates + bière)
    F->>BR: GET …/creneau (horaires d'ouverture, délai maximum)
    BR-->>F: ok
    F->>RV: POST /apps/roomvox/api/rooms/{id}/bookings
    RV->>RV: droits ? conflit de créneau ?
    RV->>CAL: crée l'événement (ACCEPTED ou TENTATIVE)
    RV-->>C: email « demande à valider » (si validation manuelle)
    RV-->>F: { uid }
    F->>BR: PUT …/reservations/{id}/brassin (nom, style, volume…)
    BR->>RV: la réservation existe ? Julien en est l'organisateur ?
    BR-->>F: brassin enregistré
    C->>F: « Valider »
    F->>RV: POST …/bookings/{uid}/respond {action: accept}
    RV->>CAL: PARTSTAT=ACCEPTED
    RV-->>J: email de confirmation
```

Une réservation faite **depuis l'Agenda** suit le même chemin côté RoomVox (via CalDAV au lieu de l'API HTTP). Elle apparaît dans l'atelier avec « Contenu non renseigné », et l'organisateur peut le compléter depuis le panneau.

## Les droits

On réutilise **les rôles RoomVox** sans en inventer d'autres :

| Rôle RoomVox | Dans Brassage |
|---|---|
| *(aucun)* | Ne voit pas la machine (l'API répond 404, pas 403 : on ne révèle pas qu'elle existe) |
| `viewer` | Voit la machine, son contenu, son journal |
| `booker` | + réserve, ajoute au journal, déclare le nettoyage, renseigne le contenu **de ses propres réservations** |
| `manager` | + renseigne le contenu de toutes les réservations, valide les demandes, règle la machine (nom, type, contenance, **étapes**), supprime toute entrée du journal |
| admin Nextcloud | Toujours `manager` ; seul à pouvoir créer et supprimer des machines (règle de RoomVox) |

**Le front n'est jamais une sécurité** : il cache les boutons inutiles, mais **chaque route PHP revérifie les droits** (`ControleurBase::exigerRole()`).

## Le calcul de l'avancement

Choix retenu : **l'avancement, c'est le temps** (voir [D5](journal-de-bord.md#d5--lavancement--le-temps-écoulé)).

```
pourcentage = (maintenant − début de la réservation) / (fin − début)
```

Les étapes de la machine sont posées sur la durée de la réservation, chacune selon sa **part**. Ce sont ses étapes personnalisées si un·e responsable en a défini, sinon celles de son type (`Gabarits.php`). C'est `Etapes::pour()` qui choisit. Un fermenteur réservé 28 jours avec « Fermentation principale » à 25 % donne une étape de 7 jours.

Le calcul est fait **côté serveur** (`Avancement.php`, testé), puis **refait côté navigateur** toutes les 30 secondes (`src/temps.js`) pour que les jauges avancent sans recharger. Le navigateur se recale sur l'heure du serveur, au cas où l'horloge de l'ordinateur serait fausse.
