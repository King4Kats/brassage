<?php

declare(strict_types=1);

namespace OCA\Brassage\Service;

use DateTimeImmutable;
use OCA\RoomVox\Service\CalDAVService;
use OCA\RoomVox\Service\PermissionService;
use OCA\RoomVox\Service\RoomService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;

/**
 * La « passerelle » vers RoomVox : le SEUL fichier de notre app qui connaît
 * les classes de RoomVox.
 *
 * Pourquoi un fichier dédié ? Parce que RoomVox n'est pas à nous : à chaque
 * mise à jour, ses classes internes peuvent changer. Si ça casse, on sait
 * exactement où regarder et on ne répare qu'ici. C'est le patron de
 * conception « Adaptateur » (Adapter) : on traduit le monde de RoomVox
 * (rooms, bookings, partstat…) en notre vocabulaire (salles, réservations, statut).
 *
 * Pourquoi on appelle les services PHP de RoomVox plutôt que son API HTTP ?
 * Parce qu'on tourne dans le même Nextcloud : un appel PHP direct est plus
 * rapide qu'une requête HTTP vers soi-même, et n'a pas besoin de jeton.
 * Contrepartie : ces services sont « internes » à RoomVox (pas une API
 * publique garantie), d'où l'importance de tout garder dans ce fichier.
 */
class RoomVoxPasserelle {
    /** Préfixe des comptes techniques que RoomVox crée pour chaque salle. */
    private const PREFIXE_COMPTE_SALLE = 'rb_';

    public function __construct(
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private IConfig $config,
        // Le « conteneur » d'injection de dépendances de Nextcloud. On l'utilise
        // pour récupérer les services RoomVox SEULEMENT au moment où on en a
        // besoin : si on les demandait dans ce constructeur et que RoomVox
        // n'était pas installé, toute notre app planterait au démarrage.
        private ContainerInterface $conteneur,
    ) {
    }

    /** RoomVox est-il installé et activé ? */
    public function disponible(): bool {
        return $this->appManager->isEnabledForAnyone('roomvox');
    }

    /**
     * Le fuseau horaire de l'instance (réglage « default_timezone » de
     * Nextcloud). Sert à interpréter les heures « murales » : plages
     * d'ouverture (8 h – 18 h) et réservations « journée entière ».
     */
    public function fuseau(): \DateTimeZone {
        try {
            return new \DateTimeZone($this->config->getSystemValueString('default_timezone', 'UTC'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC'); // valeur invalide dans config.php
        }
    }

    /**
     * Toutes les salles RoomVox actives.
     *
     * @return list<array{id: string, compte: string, nom: string, description: string, capacite: int, validationAuto: bool, regles: array, horizonJours: int}>
     */
    public function salles(): array {
        $salles = [];
        foreach ($this->roomService()->getAllRooms() as $room) {
            // Une salle « désactivée » dans RoomVox ne peut pas être réservée :
            // on ne l'affiche pas dans l'atelier.
            if (!($room['active'] ?? true)) {
                continue;
            }
            $salles[] = [
                'id' => (string)$room['id'],
                // userId = le compte technique « rb_xxx » qui porte le calendrier de la salle.
                'compte' => (string)$room['userId'],
                'nom' => (string)$room['name'],
                'description' => (string)($room['description'] ?? ''),
                'capacite' => (int)($room['capacity'] ?? 0),
                'validationAuto' => (bool)($room['autoAccept'] ?? false),
                // Règles de réservation réglées dans RoomVox (voir ReglesReservation.php).
                'regles' => is_array($room['availabilityRules'] ?? null) ? $room['availabilityRules'] : [],
                'horizonJours' => (int)($room['maxBookingHorizon'] ?? 0),
            ];
        }
        return $salles;
    }

    /**
     * Les id de TOUTES les salles RoomVox, actives ou non (utilisé par
     * occ brassage:menage pour repérer les salles supprimées).
     *
     * @return string[]
     */
    public function identifiantsDeToutesLesSalles(): array {
        return array_map(fn ($room) => (string)$room['id'], $this->roomService()->getAllRooms());
    }

    /**
     * Une salle par son id, ou null.
     *
     * @return array{id: string, compte: string, nom: string, description: string, capacite: int, validationAuto: bool, regles: array, horizonJours: int}|null
     */
    public function salle(string $roomId): ?array {
        foreach ($this->salles() as $salle) {
            if ($salle['id'] === $roomId) {
                return $salle;
            }
        }
        return null;
    }

    /**
     * Les réservations d'une salle entre deux dates, déjà « nettoyées » :
     * - on enlève les réservations refusées ou annulées ;
     * - on convertit les dates texte en objets DateTimeImmutable ;
     * - on traduit le statut calendrier (PARTSTAT) en notre vocabulaire ;
     * - on donne à chaque réservation un IDENTIFIANT unique (« id ») :
     *     · réservation simple → son UID calendrier ;
     *     · réservation RÉPÉTÉE (« tous les lundis ») → UID + « # » + date de
     *       l'occurrence. Toutes les occurrences partagent le même UID dans le
     *       calendrier ; sans ça, elles partageraient aussi le même contenu.
     *   « uidCalendrier » garde l'UID brut, dont RoomVox a besoin pour valider
     *   ou annuler (il traite la série entière).
     *
     * @return list<array{id: string, uidCalendrier: string, titre: string, debut: DateTimeImmutable, fin: DateTimeImmutable, organisateurEmail: string, organisateurNom: string, statut: string}>
     */
    public function reservations(string $compteSalle, DateTimeImmutable $depuis, DateTimeImmutable $jusqua): array {
        $brutes = $this->calDavService()->getBookings(
            $compteSalle,
            $depuis->format(\DateTimeInterface::ATOM),
            $jusqua->format(\DateTimeInterface::ATOM),
        );

        $reservations = [];
        foreach ($brutes as $b) {
            // PARTSTAT = « participation status » en iCalendar. Pour une salle :
            //   ACCEPTED  → réservation confirmée
            //   TENTATIVE → en attente de validation par un responsable
            //   DECLINED  → refusée (conflit, pas les droits…)
            $partstat = strtoupper((string)($b['partstat'] ?? ''));
            $statutEvenement = strtoupper((string)($b['status'] ?? ''));
            if ($partstat === 'DECLINED' || $statutEvenement === 'CANCELLED') {
                continue;
            }
            if (empty($b['dtstart']) || empty($b['dtend'])) {
                continue;
            }

            $uid = (string)$b['uid'];
            $occurrence = (string)($b['recurrenceId'] ?? '');
            $reservations[] = [
                'id' => $occurrence !== '' ? $uid . '#' . $occurrence : $uid,
                'uidCalendrier' => $uid,
                'titre' => (string)($b['summary'] ?? ''),
                'debut' => $this->lireDate((string)$b['dtstart']),
                'fin' => $this->lireDate((string)$b['dtend']),
                'organisateurEmail' => strtolower((string)($b['organizer'] ?? '')),
                'organisateurNom' => (string)($b['organizerName'] ?? ''),
                'statut' => $partstat === 'ACCEPTED' ? 'confirmee' : 'en_attente',
            ];
        }

        // Tri par date de début, la plus proche d'abord.
        usort($reservations, fn ($a, $b) => $a['debut'] <=> $b['debut']);
        return $reservations;
    }

    /**
     * Les dates RoomVox sont soit complètes (« 2026-10-20T09:00:00+02:00 »),
     * soit une simple date pour une réservation « journée entière »
     * (« 2026-10-20 »). Une date seule n'a pas de fuseau : sans précaution,
     * PHP la lirait en UTC (minuit UTC = 2 h du matin à Paris). On la lit
     * donc dans le fuseau de l'instance.
     */
    private function lireDate(string $texte): DateTimeImmutable {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texte)) {
            return new DateTimeImmutable($texte . ' 00:00:00', $this->fuseau());
        }
        return new DateTimeImmutable($texte);
    }

    /**
     * Une réservation précise par son identifiant (voir « id » ci-dessus), ou null.
     *
     * On repasse par reservations() plutôt que par getBookingByUid() de
     * RoomVox : ce dernier suppose que le fichier calendrier s'appelle
     * « <uid>.ics », ce qui n'est pas garanti pour une réservation faite
     * depuis un autre client calendrier (Apple, Thunderbird…).
     *
     * @return array{id: string, uidCalendrier: string, titre: string, debut: DateTimeImmutable, fin: DateTimeImmutable, organisateurEmail: string, organisateurNom: string, statut: string}|null
     */
    public function reservation(string $compteSalle, string $id, DateTimeImmutable $autourDe): ?array {
        $fenetre = new \DateInterval('P2Y');
        foreach ($this->reservations($compteSalle, $autourDe->sub($fenetre), $autourDe->add($fenetre)) as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Le rôle d'un utilisateur sur une salle, tel que RoomVox le calcule :
     * « manager » (responsable), « booker » (peut réserver), « viewer »
     * (peut voir) ou « none ». Un admin Nextcloud est toujours responsable.
     */
    public function role(string $userId, string $roomId): string {
        if ($this->groupManager->isAdmin($userId)) {
            return 'manager';
        }
        return $this->permissionService()->getEffectiveRole($userId, $roomId);
    }

    /**
     * Renomme une salle RoomVox. Le nom est stocké chez RoomVox (c'est lui
     * que voient aussi l'app Agenda, Thunderbird, etc.), pas chez nous.
     * updateRoom() ne modifie que les champs qu'on lui donne.
     */
    public function renommerSalle(string $roomId, string $nom): void {
        $this->roomService()->updateRoom($roomId, ['name' => $nom]);
    }

    // ── Écriture : utilisé par la commande de démo (occ brassage:demo) ──
    // L'app elle-même ne crée pas de salle ni de réservation en PHP : dans
    // l'interface, on passe par l'API HTTP de RoomVox (voir src/api.js), pour
    // profiter de toutes ses vérifications et notifications.

    /**
     * Crée une salle RoomVox avec son calendrier. Renvoie son id.
     */
    public function creerSalle(string $nom, string $description, int $capacite, bool $validationAuto): string {
        $room = $this->roomService()->createRoomWithCalendar([
            'name' => $nom,
            'description' => $description,
            'capacity' => $capacite,
            // « other » : RoomVox ne connaît que des types de salles de réunion.
            // Notre vrai type (fermenteur…) est stocké chez nous.
            'roomType' => 'other',
            'autoAccept' => $validationAuto,
        ], $this->calDavService());
        return (string)$room['id'];
    }

    /**
     * Supprime une salle RoomVox et son calendrier (et donc ses réservations).
     */
    public function supprimerSalle(string $roomId): void {
        $room = $this->roomService()->getRoom($roomId);
        if ($room === null) {
            return;
        }
        $this->calDavService()->deleteCalendar((string)$room['userId']);
        $this->roomService()->deleteRoom($roomId);
        $this->permissionService()->deletePermissions($roomId);
    }

    /**
     * Donne les droits d'une salle à des GROUPES Nextcloud.
     *
     * @param string[] $voient groupes « viewer »
     * @param string[] $reservent groupes « booker »
     * @param string[] $gerent groupes « manager »
     */
    public function definirDroitsGroupes(string $roomId, array $voient, array $reservent, array $gerent): void {
        // Le format attendu par RoomVox : une liste de {type, id}.
        $entrees = fn (array $groupes) => array_map(fn ($g) => ['type' => 'group', 'id' => $g], $groupes);
        $this->permissionService()->setPermissions($roomId, [
            'viewers' => $entrees($voient),
            'bookers' => $entrees($reservent),
            'managers' => $entrees($gerent),
        ]);
    }

    /**
     * Crée une réservation directement dans le calendrier de la salle.
     * $confirmee = false → elle reste « en attente de validation ».
     * Renvoie l'UID de la réservation.
     */
    public function creerReservation(string $roomId, string $titre, \DateTimeInterface $debut, \DateTimeInterface $fin, string $organisateurUid, bool $confirmee): string {
        $room = $this->roomService()->getRoom($roomId);
        if ($room === null) {
            throw new \InvalidArgumentException("Salle RoomVox inconnue : $roomId");
        }
        return $this->calDavService()->createBooking((string)$room['userId'], [
            'summary' => $titre,
            'start' => $debut,
            'end' => $fin,
            'organizer' => $organisateurUid,
            'roomEmail' => (string)($room['email'] ?? ''),
            'autoAccept' => $confirmee,
        ]);
    }

    /** Ce compte Nextcloud est-il un compte technique de salle (rb_xxx) ? */
    public static function estCompteSalle(string $userId): bool {
        return str_starts_with($userId, self::PREFIXE_COMPTE_SALLE);
    }

    // ── Accès paresseux (« lazy ») aux services RoomVox ──
    // Ils ne sont demandés au conteneur qu'au premier appel.

    private function roomService(): RoomService {
        return $this->conteneur->get(RoomService::class);
    }

    private function calDavService(): CalDAVService {
        return $this->conteneur->get(CalDAVService::class);
    }

    private function permissionService(): PermissionService {
        return $this->conteneur->get(PermissionService::class);
    }
}
