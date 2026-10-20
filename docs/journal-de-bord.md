# Journal de bord

Ce document garde la **réflexion** du projet : ce qu'on a décidé et pourquoi, ce qu'on a écarté, les problèmes rencontrés et leur solution, les questions encore ouvertes. Le code dit *comment*, ce journal dit *pourquoi*.

Format des décisions : **contexte → décision → conséquences**. C'est une version allégée des *ADR* (Architecture Decision Records), une pratique courante pour qu'une équipe sache plus tard pourquoi le code est comme il est.

---

## Chronologie

| Date | Étape |
|---|---|
| 2026-10-20 | Besoin exprimé par l'association : partager et suivre ses machines de production. Étude de RoomVox comme base. |
| 2026-10-20 | Cadrage : le besoin porte sur les **machines de production** (brassage, distillation), pas sur le suivi des fûts. |
| 2026-10-20 | Choix : app compagnon par-dessus RoomVox, avancement par le temps, démo avec les 4 types de machines. |
| 2026-10-20 | Intégration de l'illustration de l'alambic (`alambic.svg`), qui devient la référence de style des dessins. |
| 2026-10-20 | Première version : API, atelier animé, panneau de détail, démo Docker en une commande, doc. Dépôt GitHub `King4Kats/brassage` (rendu public ensuite, sous licence AGPL-3.0). |
| 2026-10-20 | 0.2.0 : étapes modifiables par machine (D10). |
| 2026-10-20 | Revue technique du projet, puis : erreurs de migration plus jamais masquées, versions figées (D11), tests d'API et intégration continue (D12). |
| 2026-10-20 | Veille sur le suivi communautaire de RoomVox. 0.3.0 : règles de réservation (D13), réservations répétées et journées entières, ménage des réservations annulées, garde-fous de démo, accessibilité ; performances mesurées (D14) ; pas de traduction (D15). |

---

## Décisions

### D1 — Une app « compagnon » au-dessus de RoomVox, pas un fork

**Contexte.** RoomVox fait la réservation de salles dans Nextcloud : créneaux, conflits, rôles par groupe, validation par un responsable, emails, synchronisation avec n'importe quel agenda (CalDAV), et même Microsoft Exchange. Il nous manque la vue d'atelier, le contenu des cuves, l'avancement, les mesures et le nettoyage.

**Options.**
- *Forker RoomVox* (copier son code et le modifier) : contrôle total, mais environ 15 000 lignes à maintenir nous-mêmes, plus de mises à jour officielles, et du code dont on n'a pas besoin (Exchange, télémétrie, licences).
- *App compagnon* : RoomVox installé tel quel depuis le magasin d'apps, et notre app « Brassage » qui s'appuie dessus.

**Décision.** App compagnon : on s'appuie sur RoomVox tel quel, et on ne développe que ce qu'il ne couvre pas.

**Conséquences.**
- ✅ RoomVox se met à jour seul, et ses correctifs profitent à l'asso.
- ✅ Notre code reste petit : ≈ 4 600 lignes de PHP et JS (v0.3.0), dont une grande part de commentaires.
- ⚠ Dépendance à des classes internes de RoomVox → voir D3.
- ⚠ Ce que RoomVox ne sait pas faire et qu'on ne veut pas lui ajouter, on le stocke à côté (D2).

### D2 — Chaque machine est une « salle » RoomVox ; le reste vit dans nos tables

**Contexte.** RoomVox ne connaît que des salles (types : réunion, studio, amphithéâtre…).

**Décision.** Une machine = une salle RoomVox de type `other`. Nos tables `brassage_*` ajoutent :
- le vrai type (brassage, fermenteur, garde, alambic) ;
- la contenance ;
- le nettoyage ;
- le contenu de chaque réservation et son journal.

Le lien se fait par `room_id` (l'identifiant de la salle) et `booking_uid` (l'identifiant de la réservation : l'UID de l'événement calendrier, suivi de la date pour une occurrence de série, voir P18).

**Conséquences.**
- ✅ Les machines apparaissent dans l'Agenda comme des ressources réservables, sans rien coder.
- ⚠ Pas de clé étrangère possible vers RoomVox : une machine supprimée ou une réservation annulée laisse des lignes orphelines. Elles sont ignorées à l'affichage, et supprimées par `occ brassage:menage` (voir P20).

### D3 — Appeler RoomVox en PHP, via une passerelle

**Contexte.** Pour lire les salles, les réservations et les droits, deux options :
- l'API HTTP de RoomVox (requête vers soi-même, jeton à gérer) ;
- ses services PHP, puisqu'on tourne dans le même Nextcloud.

**Décision.** Services PHP (`RoomService`, `CalDAVService`, `PermissionService`), mais **uniquement dans `lib/Service/RoomVoxPasserelle.php`**. C'est le patron *Adaptateur*.

**Conséquences.**
- ✅ Rapide, pas d'authentification à gérer.
- ⚠ Ces services ne sont pas une API publique garantie. À chaque montée de version de RoomVox, revérifier la passerelle ([procédure](05-developper.md#monter-de-version-nextcloud-roomvox-agenda-node-mailpit)).
- Les services sont récupérés **paresseusement** depuis le conteneur d'injection : si RoomVox est désactivé, Brassage ne plante pas au chargement, il répond un 503 explicite.

### D4 — Pour réserver, le front appelle l'API HTTP de RoomVox

**Contexte.** Créer une réservation implique de nombreuses vérifications : droits, conflits avec les autres réservations (et avec Exchange s'il est branché), emails aux responsables.

**Décision.** Le bouton « Réserver » appelle `POST /apps/roomvox/api/rooms/{id}/bookings`, puis notre API pour accrocher le contenu. On ne recopie pas cette logique.

**Conséquences.**
- ✅ Même comportement qu'une réservation faite par RoomVox lui-même.
- ⚠ Deux requêtes successives, donc pas atomique. Si la seconde échoue, la réservation existe sans contenu. Ce n'est pas grave : elle s'affiche « Contenu non renseigné » et l'organisateur peut compléter.

### D5 — L'avancement = le temps écoulé

**Contexte.** Il faut un indicateur simple de « où en est la machine », compréhensible par des membres qui ne sont pas brasseurs. Le besoin exprimé : un pourcentage et un temps restant, par exemple « 40 % — encore 60 jours ».

**Options écartées.**
- Saisie manuelle du % : demande que quelqu'un pense à le mettre à jour.
- Avancement « réel » à partir de mesures (densité cible) : demande des connaissances de brassage qu'on n'a pas encore.

**Décision.**
- `% = temps écoulé / durée de la réservation`.
- Les **étapes** (« Fermentation principale », « Garde à froid »…) sont posées sur cette durée selon des **parts** fixes par type (`Gabarits.php`), avec une explication pour les débutants.

**Conséquences.**
- ✅ Zéro saisie, toujours à jour.
- ⚠ C'est un **planning théorique** : si le brassage prend du retard, l'app ne le sait pas.
- ⚠ Les étapes et leurs parts sont des **valeurs de démo**, à faire valider (Q2).

### D6 — Pas de framework front

**Contexte.** Les apps Nextcloud récentes utilisent souvent Vue et `@nextcloud/vue`.

**Décision.** JavaScript en modules ES, sans framework, compilé par Vite en un seul fichier. On utilise seulement `@nextcloud/router` et `@nextcloud/auth` (URL et jeton CSRF) et GSAP.

**Pourquoi.**
- L'interface est petite : une grille et un panneau.
- Une partie de l'équipe débute en développement web : moins de couches à comprendre.
- Les animations GSAP manipulent directement le SVG, sans combat avec un DOM virtuel.

**Conséquences.**
- ✅ Bundle de 156 Ko (52 Ko compressé, dont l'essentiel est GSAP), code lisible de haut en bas.
- ⚠ Le HTML est construit avec des gabarits de chaînes : **obligation d'échapper** toute donnée serveur (`echapper()`). Si l'interface grossit beaucoup, passer à Vue sera raisonnable.

### D7 — Les dessins sont de vrais fichiers SVG, avec un « contrat d'`id` »

**Contexte.** Le projet dispose d'une illustration SVG d'alambic très soignée, avec des `id` explicites pensés pour GSAP.

**Décision.**
- Les 3 autres machines sont dessinées dans le même style (mêmes dégradés, même cadrage 1200 × 760).
- Elles partagent les mêmes `id` pour les mêmes rôles (`liquid-level`, `tank-bubbles`, `light-running`…). Un seul moteur d'animation sert pour tous.
- Les fichiers sont importés tels quels (`?raw` de Vite) : on peut les retoucher dans Inkscape ou Figma.
- Seul ajout au fichier d'origine : `data-haut` et `data-bas` sur `#liquid-level`.

**Conséquences.** Voir le [contrat complet](03-front-et-animations.md#les-dessins-svg-et-leur--contrat-danimation-).

### D8 — Démo Docker « tout compris », données relatives à aujourd'hui

**Contexte.** Les développeurs de l'association doivent pouvoir tester en local, sans rien installer d'autre que Docker.

**Décision.**
- Une image construite en deux étapes : Node compile le front, puis Nextcloud 32 reçoit l'Agenda, RoomVox (versions figées, empreintes SHA-256 vérifiées, voir D11) et Brassage.
- Des hooks de l'image officielle configurent tout (`post-installation`) et mettent les apps à jour (`before-starting`).
- La commande `occ brassage:demo --oui` crée un atelier dont les dates sont **relatives au jour du lancement**.
- Mailpit attrape les emails.

**Conséquences.**
- ✅ `docker compose up --build`, puis environ 3 minutes, et tout marche ; la démo montre toujours les mêmes situations.
- ⚠ SQLite : bien pour une démo, pas pour la production.

### D9 — Réutiliser les rôles RoomVox, sans en inventer

**Décision.** *viewer / booker / manager* + admin, donnés à des **groupes** Nextcloud (`brassage-membres`, `-brasseurs`, `-responsables`). Les règles précises sont dans [02 — Architecture](02-architecture.md#les-droits).

À noter :
- seul l'organisateur d'une réservation, ou un responsable, peut décrire son contenu ;
- tout brasseur peut ajouter une mesure ou déclarer le nettoyage, pour faciliter l'entraide entre membres.

### D10 — Étapes modifiables par machine

**Contexte.** Retour de test : les étapes affichées étaient codées en dur par type de machine (D5), donc impossibles à adapter au fonctionnement réel de l'association. Deux pistes :
- **(a)** étapes modifiables par un·e responsable ;
- **(b)** un bouton « Étape suivante ».

Piste retenue : (a).

**Décision.**
- Nouvelle table `brassage_etapes` (migration 0.2.0), avec une liste d'étapes **par machine**.
- Pas de ligne pour une machine → étapes par défaut de son type. Il n'y a donc **rien à migrer** pour les machines existantes.
- `Etapes::pour()` est l'unique endroit qui choisit la liste.
- `Avancement::calculer()` reçoit désormais la liste d'étapes au lieu du type : il reste « pur » et testable.
- L'enregistrement **remplace toute la liste** dans une transaction : pas d'état à moitié écrit.

**Conséquences.**
- ✅ L'asso adapte les étapes à ses vraies machines, sans développeur.
- ✅ Les parts restent relatives : les étapes s'adaptent toujours à la durée réservée.
- ⚠ Changer les étapes modifie **aussi l'affichage des réservations en cours et passées**, car rien n'est figé au moment de réserver. C'est voulu pour la simplicité. Si on veut un historique fidèle un jour, il faudra copier les étapes dans le brassin à la réservation.
- ⚠ La piste (b), suivre l'étape réelle plutôt que l'horloge, reste possible plus tard.

### D11 — Versions figées

**Contexte.** La revue technique a relevé que `nextcloud:32-apache` et `node:20-alpine` suivaient la dernière version publiée, et que l'Agenda était téléchargé « en dernière version » au premier lancement. Deux personnes pouvaient donc obtenir deux installations différentes, et un `docker compose up --build` pouvait casser du jour au lendemain sans qu'on ait rien changé.

**Décision.**
- Images désignées par **tag exact + empreinte** (`nextcloud:32.0.15-apache@sha256:…`). L'empreinte est celle de la liste multi-architecture, donc valable sur Linux x86, ARM et Mac.
- L'Agenda est intégré à l'image comme RoomVox : version précise et empreinte SHA-256 vérifiée.
- Plus besoin d'Internet au premier lancement, seulement pour construire l'image.

**Conséquences.**
- ✅ Même installation pour tout le monde, à tout moment.
- ⚠ Les correctifs de sécurité n'arrivent plus tout seuls : il faut monter les versions volontairement ([procédure](05-developper.md#monter-de-version-nextcloud-roomvox-agenda-node-mailpit)). Pour une démo locale, c'est le bon compromis ; pour un serveur exposé, prévoir une revue mensuelle.

### D12 — Tests d'API de bout en bout + intégration continue

**Contexte.** Seul le calcul d'avancement était testé. Or le plus risqué, ce sont **les droits**, et la **passerelle RoomVox**, qui repose sur des classes internes susceptibles de changer.

**Décision.**
- `tests/api/test_api.py` : 22 tests en Python standard (v0.3.0), qui appellent la vraie API avec les comptes de démo (anonyme, sans groupe, membre, brasseurs, responsable, admin).
- `scripts/tester-api.sh` les lance sur une installation **neuve et jetable**, pour ne jamais toucher à la démo de quelqu'un.
- GitHub Actions lance tests unitaires et tests d'API à chaque envoi.

**Conséquences.**
- ✅ Une régression de droits, ou une mise à jour de RoomVox incompatible, se voit tout de suite (✗ rouge sur GitHub).
- ✅ Les tests ont trouvé dès leur premier passage un bug de RoomVox (P16).
- ⚠ Pas de tests du front (animations, formulaires) : ce serait le travail d'outils comme Playwright, à envisager si l'interface grossit.

### D13 — Vérifier les règles de réservation avant RoomVox

**Contexte.** La revue technique (point 3) : RoomVox vérifie les horaires d'ouverture et le délai maximum d'une machine quand on réserve **depuis l'Agenda**, mais pas via son API HTTP, que notre bouton « Réserver » utilise. Sa fonction de vérification est privée.

**Décision.**
- `ReglesReservation.php` reprend **à l'identique** la règle de RoomVox (`SchedulingPlugin::bookingFitsRule`), avec les heures comparées dans le fuseau de l'instance. C'est une fonction pure, testée par 15 cas dont le changement d'heure.
- Une route `GET …/creneau`, que le front appelle avant RoomVox.

**Conséquences.**
- ✅ Notre bouton respecte les mêmes règles que l'Agenda.
- ⚠ Ce n'est **pas une barrière de sécurité** : quelqu'un d'habile peut appeler l'API de RoomVox directement. La vraie correction est chez RoomVox. On ne la signale pas pour l'instant (voir [Suivi communautaire de RoomVox](#suivi-communautaire-de-roomvox-état-au-2026-10-20)).
- ⚠ Si RoomVox change ses règles, il faudra suivre. Les tests d'API (`T8Creneau`) vérifient le comportement de bout en bout.

### D14 — Performances : mesurer, puis ne rien changer (pour l'instant)

**Contexte.** La revue technique (point 7) : à chaque affichage de l'atelier, RoomVox relit et décode **tous** les événements du calendrier de chaque machine.

**Mesure** (médiane de 5 appels à `GET /api/atelier`, conteneur jetable, poste de développement) :

| Historique | Temps |
|---|---|
| Démo (≈ 10 réservations) | 0,24 s |
| + 250 réservations passées | 0,25 s |
| + 1 000 réservations passées sur **une seule** machine | 0,40 s |

Soit ≈ 0,16 ms par réservation d'historique.

**Décision.** Ne rien optimiser maintenant. À raison de quelques dizaines de réservations par machine et par an, il faudrait des années pour que ce soit perceptible. Optimiser voudrait dire réécrire la lecture du calendrier en dehors de RoomVox, donc dupliquer sa logique (contraire à D3).

**À revoir si** l'atelier dépasse ≈ 1 s, ou ≈ 3 000 réservations au total. Pistes : requête CalDAV par période (index `firstoccurence`/`lastoccurence` de Nextcloud) ou cache de quelques minutes.

### D15 — Pas de traduction (pour l'instant)

**Contexte.** La revue technique : les textes sont écrits en dur en français, sans `$l->t()` ni `t()`.

**Décision.** On reste en français. L'app sert une association francophone, et rendre tout traduisible ajouterait une couche (`l10n/`, extraction des chaînes) qui alourdit la lecture du code pour un débutant.

**Conséquence.** Pas de publication possible sur l'App Store de Nextcloud en l'état. Si ça devient un objectif, il faudra passer tous les textes PHP par `IL10N::t()`, et les textes JS par `@nextcloud/l10n`.

## Suivi communautaire de RoomVox (état au 2026-10-20)

- **Aucune issue** sur le bug d'annulation (P16) ni sur les règles non vérifiées par l'API (D13) : on semble être les premiers à les voir. Le code de leur branche principale a toujours ces deux défauts.
- **Projet actif et réactif** : une version toutes les 2 à 3 semaines (1.6.1 du 11/10 = correctif de sécurité). Les issues voisines ont été corrigées en quelques jours : #5 (organisateur sans email), #7 (délai maximum ignoré sans prévenir), #10 (annulation non notifiée), #46 (séries vérifiées sur la 1re date seulement).
- **Pas d'espace de discussion** (GitHub Discussions désactivé). Sur le forum Nextcloud, RoomVox n'apparaît que dans des listes de compatibilité, et chez l'auteur de **NC Connector** (extension Thunderbird et Outlook), qui le recommande et l'intègre.
- **Décision** : on documente, on **ne signale pas** pour l'instant. On garde le contournement dans les tests.

---

## Problèmes rencontrés

| # | Problème | Cause | Solution |
|---|---|---|---|
| P1 | Docker inaccessible au démarrage (poste Windows) | Docker Desktop s'arrête entre deux sessions | Le relancer |
| P2 | `docker cp` : « CreateFile C:\c: … » | `MSYS_NO_PATHCONV=1` empêche Git Bash de convertir le chemin **côté Windows** | Les scripts envoient l'app en `tar` par l'entrée standard : plus de chemin hôte à convertir |
| P3 | `getTotalLength` : « non-rendered element » | L'arc de progression était mesuré avant que la carte soit insérée dans la page | Longueur calculée avec 2πr à partir du rayon |
| P4 | Fond de page transparent (on voyait le fond d'écran Nextcloud) | Le gabarit n'utilisait pas le conteneur standard `#app-content` | `templates/main.php` utilise `#app-content`, et `.brassage` a un fond explicite |
| P5 | Les modifications de JS n'apparaissaient pas | Cache navigateur : `?v=` dépend de la version de l'app | Monter la version dans `info.xml` à chaque livraison du front |
| P6 | Fenêtre « Nextcloud Hub » par-dessus l'atelier | App `firstrunwizard` | Désactivée dans le hook d'installation |
| P7 | Changer le type « Fermenteur 3 » en cuve de garde laissait le nom « Fermenteur 3 » | Le nom appartient à RoomVox, le réglage ne touchait que nos tables | Champ « Nom » dans les réglages (renomme dans RoomVox), et remplacement automatique du libellé du type dans le nom |
| P8 | « 61 jours » au lieu de 60 pour une garde de 100 jours à 40 % | Passage à l'heure d'hiver pendant la période : la durée réelle fait une heure de plus | Pas un bug : l'affichage donne jours + heures (« 60 j 1 h ») |
| P9 | Une réservation de durée nulle restait à 0 % | Le ratio était calculé avant de tester « déjà fini ? » | Test `maintenant >= fin` en premier (PHP et JS), couvert par `app/tests/AvancementTest.php` |
| P10 | Animations figées pendant les vérifications automatiques | Le navigateur de test en arrière-plan gèle `requestAnimationFrame` | Rien à corriger dans l'app ; vérifier les animations dans un vrai onglet |
| P11 | `npm warn EBADENGINE` | Les paquets `@nextcloud/*` demandent Node 20, la machine de dev a Node 22 | Sans effet ; l'image Docker compile avec Node 20 |
| P12 | Risque : le rafraîchissement automatique (toutes les 5 min) aurait effacé une saisie en cours dans le panneau | Le panneau est entièrement redessiné à chaque rechargement | `saisieEnCours` : on ne redessine pas tant qu'on tape ; remis à zéro à l'envoi d'un formulaire |
| P13 | `Fontconfig error: No writable cache directories` dans les logs au premier lancement | Message de l'image Nextcloud quand occ génère des avatars ou aperçus | Sans effet, à ignorer |
| P14 | L'écran du Fermenteur 2 affichait 18,5° alors qu'il était en garde à froid à 4 °C (retour de test) | La température était écrite en dur dans le dessin SVG | L'écran (`#temp-display`) affiche la **dernière température du journal** (une requête pour toutes les machines), « --,- » s'il n'y en a pas ; couleur bleu / vert / jaune selon la plage |
| P15 | Une migration de base ratée serait passée inaperçue (relevé par la revue technique) | `occ upgrade … \|\| true` dans le hook de démarrage et dans `deployer.sh` : « si ça échoue, continue » | Plus de `\|\| true` : le conteneur s'arrête (ou le script échoue) en affichant l'erreur. « Rien à mettre à jour » renvoie bien 0, vérifié |
| P16 | Un brasseur ne peut pas **annuler sa propre réservation** via l'API de RoomVox (403) ; seul un responsable le peut | **Bug de RoomVox 1.6.1** : `BookingApiController::destroy` compare l'email de l'organisateur à l'identifiant Nextcloud (`julien@…` ≠ `julien`) | Contourné dans les tests (annulation par un responsable). Supprimer l'événement depuis l'app Agenda passe par un autre chemin (CalDAV), a priori non touché, mais pas encore vérifié. Non signalé pour l'instant (Q6) |
| P17 | Sous Linux, `./scripts/deployer.sh` aurait répondu « Permission denied » | Scripts créés sous Windows : Git les avait enregistrés sans le droit d'exécution (mode 100644) | `git update-index --chmod=+x` sur tous les `.sh` (mode 100755) |
| P18 | Une réservation **répétée** (« tous les lundis ») partageait un seul contenu pour toutes ses dates | Toutes les occurrences d'une série ont le même UID calendrier, et le brassin était rangé par UID | Identifiant de réservation `UID#date` pour une occurrence (`uidCalendrier` garde l'UID brut pour RoomVox) ; vérifié avec une série de 4 lundis |
| P19 | Une réservation « journée entière » pouvait être décalée de 1 à 2 h | La date seule (« 2026-10-20 ») était lue en UTC | Lue dans le fuseau de l'instance (`default_timezone`) |
| P20 | Le contenu des **réservations annulées** restait en base pour toujours | `brassage:menage` ne traitait que les machines supprimées | Option `--reservations-annulees` (vérifiée : détecte les orphelins, ne touche pas aux contenus valides) |
| P21 | `occ brassage:demo` lancée par erreur sur un vrai serveur créerait des comptes au mot de passe connu | Pas de garde-fou | La commande exige `--oui` et explique pourquoi |
| P22 | Le panneau se redessinait toutes les 5 min (animations relancées) ; Tab sortait du panneau ; contraste des pastilles insuffisant | Revue technique, points mineurs | Signature des données sans `avancement` + `tic()` ; `inert` + piège à focus ; couleurs assombries (≥ 4,5:1) |
| P23 | Dans les dessins, des pièces semblaient flotter : barboteur du fermenteur détaché du dôme ; sur l'alambic, tuyau vers le condenseur décollé de la colonne, vanne isolée, deux bouts de tuyau du condenseur dans le vide, tuyau du bas sans arrivée ; bouteilles de la cuve de garde sans lien avec la cuve (retour de test) | Coordonnées SVG qui ne se touchaient pas, et tube du barboteur sans contour (invisible) | Pièces raccordées, retouches notées en tête de `alambic.svg` ; tube du barboteur descendu jusqu'au dôme avec contour et bride ; tuyau de soutirage de la cuve de garde vers les bouteilles. Vérifié sur les 5 cartes, sur fond sombre |

---

## Questions ouvertes

**Q1 — Quelles machines l'asso a-t-elle vraiment ?** Le parc réel n'est pas encore connu : une seule machine polyvalente, ou plusieurs cuves spécialisées ? La démo montre les 4 types possibles, avec l'alambic en tête. **À recueillir auprès des membres :** quelles machines, combien, et combien de temps dure une utilisation ? Adapter ensuite la liste `$scenario` dans `lib/Command/DemoSeed.php` (le seul endroit à toucher).

**Q2 — Les étapes par défaut sont-elles justes ?** Depuis la 0.2.0, chaque machine peut avoir ses propres étapes (D10). Les valeurs par défaut de `Gabarits.php` restent des valeurs de démo, à faire valider par les brasseurs. Il reste aussi à décider si on veut l'option « Étape suivante » (suivre la réalité plutôt que l'horloge).

**Q3 — Notifications.** RoomVox envoie déjà les emails de réservation et de validation. Faut-il des rappels Brassage, comme « la cuve X est à nettoyer depuis 3 jours » ou « fin de garde demain » ?

**Q4 — Écran dans l'atelier.** L'API publique de RoomVox (avec jeton) permettrait une page plein écran sur une tablette accrochée au mur. Intéressant ?

**Q5 — Où héberger pour de vrai ?** L'asso a-t-elle déjà un Nextcloud ? (Version 32 minimum, à cause de RoomVox.)

**Q6 — Signaler P16 et D13 à RoomVox ?** Pour l'instant **non** (décision du 2026-10-20) : on documente seulement. Si on change d'avis : une issue sur https://github.com/nextcloud/roomvox/issues, avec le correctif (comparer l'email de l'organisateur à l'email du compte connecté).

**Q7 — Le « lot » qui passe de machine en machine.** Aujourd'hui, un brassin = une réservation. En vrai, une même bière passe du brassage au fermenteur puis à la garde, et son journal est éclaté. Faut-il une notion de **lot** qui relie plusieurs réservations ? À voir avec les brasseurs avant de toucher au modèle de données.
