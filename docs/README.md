# Documentation de Brassage

Cette doc s'adresse à deux publics :

- **les développeurs**, qui veulent comprendre vite comment c'est construit et le faire évoluer ;
- **les personnes qui découvrent** une partie de ces technologies. Les passages marqués **💡 Pour comprendre** expliquent les notions de base. Ceux qui les connaissent peuvent les sauter.

| Page | Contenu |
|---|---|
| [01 — Démarrer](01-demarrer.md) | Lancer la démo, les comptes, ce qu'il y a à voir |
| [02 — Architecture](02-architecture.md) | Les briques, le modèle de données, le parcours d'une réservation |
| [03 — Front et animations](03-front-et-animations.md) | Fichiers JS, dessins SVG, « contrat d'animation », GSAP, ajouter un type de machine |
| [04 — API](04-api.md) | Les routes HTTP de Brassage, et celles de RoomVox qu'on réutilise |
| [05 — Développer](05-developper.md) | Boucle de développement, tests, conventions, pièges |
| [Journal de bord](journal-de-bord.md) | Décisions prises (et pourquoi), problèmes rencontrés, questions ouvertes |

## En une page

| Techno | Rôle dans le projet | Pourquoi elle |
|---|---|---|
| **Nextcloud 32** | La plateforme : comptes, groupes, agenda, fichiers | L'asso l'utilise (ou va l'utiliser) ; on ne recrée pas de gestion de comptes |
| **RoomVox 1.6.1** | Réservation des machines (créneaux, conflits, droits, validation, emails) | Fait déjà 80 % du besoin, open source (AGPL), maintenu par VoxCloud |
| **PHP 8.3** (App Framework Nextcloud) | Le back de Brassage : API, base de données, droits | Le langage des apps Nextcloud |
| **SQLite** (démo) | Base de données | Zéro installation. En production : PostgreSQL ou MariaDB, sans changer le code |
| **JavaScript** (sans framework) + **Vite 6** | Le front : atelier, panneau de détail, formulaires | App petite → du JS simple, lisible par tous. Vite assemble les fichiers |
| **GSAP 3.13** | Animations des machines (liquide, bulles, vapeur, gouttes, jauges) | La référence des animations web, gratuite depuis 2025, très bien documentée |
| **SVG** | Dessins des machines | Vectoriel (net à toutes les tailles) et animable pièce par pièce |
| **Docker** | Démo en une commande | Personne n'a à installer PHP, Nextcloud ou Node |
| **Python 3** (bibliothèque standard) | Tests d'API de bout en bout | Présent sur toutes les machines Linux, rien à installer |
| **GitHub Actions** | Intégration continue : tests à chaque envoi | Gratuit pour un dépôt public, intégré à GitHub |
| **Mailpit** | Faux serveur mail de la démo | On voit les emails de RoomVox sans rien envoyer pour de vrai |
