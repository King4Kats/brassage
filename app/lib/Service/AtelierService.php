<?php

declare(strict_types=1);

namespace OCA\Brassage\Service;

use DateTimeImmutable;
use OCA\Brassage\Db\BrassinMapper;
use OCA\Brassage\Db\Equipement;
use OCA\Brassage\Db\EquipementMapper;
use OCA\Brassage\Db\EtapeMapper;
use OCA\Brassage\Db\JournalEntreeMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Assemble la « photo » de l'atelier pour un utilisateur :
 * pour chaque cuve qu'il a le droit de voir → son type, sa réservation en
 * cours (qui, quoi, jusqu'à quand), l'avancement, la prochaine réservation,
 * et si elle doit être nettoyée.
 *
 * C'est le cœur de l'app : le contrôleur AtelierController ne fait que
 * l'appeler et renvoyer le résultat en JSON.
 */
class AtelierService {
    /**
     * Fenêtre de dates qu'on demande à RoomVox. Une fermentation + garde peut
     * durer plusieurs mois : on regarde 1 an en arrière et 1 an en avant.
     */
    private const FENETRE = 'P1Y';

    /**
     * Cache « email → compte Nextcloud » le temps d'une requête : la même
     * personne revient souvent (elle a réservé plusieurs cuves).
     *
     * @var array<string, \OCP\IUser|null>
     */
    private array $cacheEmails = [];

    public function __construct(
        private RoomVoxPasserelle $roomVox,
        private EquipementMapper $equipementMapper,
        private BrassinMapper $brassinMapper,
        private EtapeMapper $etapeMapper,
        private JournalEntreeMapper $journalMapper,
        private IUserManager $userManager,
        // ITimeFactory = « l'horloge » de Nextcloud. On l'utilise plutôt que
        // new DateTime() pour pouvoir la remplacer par une fausse horloge
        // dans les tests.
        private ITimeFactory $horloge,
    ) {
    }

    /**
     * @return list<array<string, mixed>> une entrée par cuve visible
     */
    public function pourUtilisateur(string $userId): array {
        $maintenant = DateTimeImmutable::createFromInterface($this->horloge->now());
        $depuis = $maintenant->sub(new \DateInterval(self::FENETRE));
        $jusqua = $maintenant->add(new \DateInterval(self::FENETRE));

        // On charge d'un coup tous nos réglages, brassins, étapes et dernières
        // températures (4 requêtes SQL en tout, quel que soit le nombre de cuves).
        $salles = $this->roomVox->salles();
        $roomIds = array_column($salles, 'id');
        $equipements = $this->equipementMapper->findAllParRoomId();
        $brassins = $this->brassinMapper->findParSalles($roomIds);
        $etapesPerso = $this->etapeMapper->findParSalles($roomIds);
        $temperatures = $this->journalMapper->dernieresMesures(
            array_map(fn ($b) => $b->getId(), array_values($brassins)),
            'temperature',
        );

        $atelier = [];
        foreach ($salles as $salle) {
            $role = $this->roomVox->role($userId, $salle['id']);
            if ($role === 'none') {
                continue; // pas le droit de voir cette cuve
            }

            $equipement = $equipements[$salle['id']] ?? null;
            $type = $equipement?->getType() ?? 'fermenteur';
            // Les étapes de CETTE machine (personnalisées, ou celles du type).
            $etapes = Etapes::pour($type, $etapesPerso[$salle['id']] ?? []);

            $reservations = $this->roomVox->reservations($salle['compte'], $depuis, $jusqua);

            // On range les réservations en trois paquets.
            $enCours = null;   // celle qui couvre « maintenant »
            $prochaine = null; // la première qui commence après maintenant
            $derniereFin = null; // fin de la dernière réservation terminée
            foreach ($reservations as $r) {
                if ($r['debut'] <= $maintenant && $maintenant < $r['fin']) {
                    $enCours ??= $r; // ??= : n'affecte que si $enCours est encore null
                } elseif ($r['debut'] > $maintenant) {
                    $prochaine ??= $r;
                } elseif ($r['fin'] <= $maintenant) {
                    $derniereFin = max($derniereFin ?? $r['fin'], $r['fin']);
                }
            }

            $atelier[] = [
                'roomId' => $salle['id'],
                'nom' => $salle['nom'],
                'description' => $salle['description'],
                'type' => $type,
                'typeLibelle' => Gabarits::libelle($type),
                'capaciteL' => $equipement?->getCapaciteL(),
                'validationAuto' => $salle['validationAuto'],
                'etapes' => $etapes,
                'etapesPersonnalisees' => isset($etapesPerso[$salle['id']]),
                // Les droits de l'utilisateur, pour que le front sache quels
                // boutons afficher. Le back RE-vérifie à chaque action : on ne
                // fait jamais confiance au front pour la sécurité.
                'droits' => [
                    'role' => $role,
                    'reserver' => in_array($role, ['booker', 'manager'], true),
                    'gerer' => $role === 'manager',
                ],
                'nettoyage' => $this->etatNettoyage($equipement, $derniereFin, $enCours !== null),
                'enCours' => $enCours ? $this->decrireReservation($enCours, $salle['id'], $etapes, $maintenant, $brassins, $temperatures, $userId, $role) : null,
                'prochaine' => $prochaine ? $this->decrireReservation($prochaine, $salle['id'], $etapes, $maintenant, $brassins, $temperatures, $userId, $role) : null,
            ];
        }

        return $atelier;
    }

    /**
     * Transforme une réservation en tableau prêt pour le JSON, avec son
     * brassin (le contenu) et son avancement.
     *
     * @param array<string, mixed> $r
     * @param list<array{nom: string, part: int, explication: string}> $etapes
     * @param array<string, \OCA\Brassage\Db\Brassin> $brassins
     * @param array<int, \OCA\Brassage\Db\JournalEntree> $temperatures dernière température par brassin
     * @return array<string, mixed>
     */
    private function decrireReservation(array $r, string $roomId, array $etapes, DateTimeImmutable $maintenant, array $brassins, array $temperatures, string $userId, string $role): array {
        $organisateur = $this->utilisateurParEmail($r['organisateurEmail']);
        $brassin = $brassins[BrassinMapper::cle($roomId, $r['id'])] ?? null;

        // La dernière température notée dans le journal de ce brassin : c'est
        // elle qu'affiche l'écran de la machine. null = aucune mesure (l'écran
        // affiche alors « --,- » plutôt qu'un chiffre inventé).
        $mesure = $brassin !== null ? ($temperatures[$brassin->getId()] ?? null) : null;
        $derniereTemperature = $mesure === null ? null : [
            'valeur' => $mesure->getValeur(),
            'le' => $mesure->getCreeLe()->format(\DateTimeInterface::ATOM),
        ];

        return [
            // id : NOTRE identifiant de la réservation (unique même pour une
            // occurrence de série) ; c'est lui qu'on utilise dans nos URL.
            // uidCalendrier : l'UID brut, pour les appels à RoomVox.
            'id' => $r['id'],
            'uidCalendrier' => $r['uidCalendrier'],
            'titre' => $r['titre'],
            'debut' => $r['debut']->format(\DateTimeInterface::ATOM),
            'fin' => $r['fin']->format(\DateTimeInterface::ATOM),
            'statut' => $r['statut'],
            'organisateur' => [
                // uid Nextcloud si on a retrouvé la personne (pour son avatar),
                // sinon null (réservation venue d'un client calendrier externe).
                'uid' => $organisateur?->getUID(),
                'nom' => $organisateur?->getDisplayName() ?: ($r['organisateurNom'] ?: $r['organisateurEmail']),
            ],
            'brassin' => $brassin,
            'derniereTemperature' => $derniereTemperature,
            // Peut-il renseigner le contenu ? L'organisateur de la réservation,
            // ou un responsable de la cuve.
            'modifiable' => $role === 'manager'
                || ($organisateur !== null && $organisateur->getUID() === $userId),
            'avancement' => Avancement::calculer($r['debut'], $r['fin'], $maintenant, $etapes),
        ];
    }

    /**
     * Une cuve est « à nettoyer » si une réservation s'est terminée après le
     * dernier nettoyage noté (ou s'il n'y en a jamais eu). Pendant une
     * réservation en cours, la question ne se pose pas : elle est utilisée.
     *
     * @return array{statut: string, nettoyeLe: string|null, nettoyePar: string|null}
     */
    private function etatNettoyage(?Equipement $equipement, ?DateTimeImmutable $derniereFin, bool $occupee): array {
        $nettoyeLe = $equipement?->getNettoyeLe();
        $statut = 'propre';
        if ($occupee) {
            $statut = 'occupee';
        } elseif ($derniereFin !== null && ($nettoyeLe === null || $nettoyeLe < $derniereFin)) {
            $statut = 'a_nettoyer';
        }

        $par = $equipement?->getNettoyePar();
        return [
            'statut' => $statut,
            'nettoyeLe' => $nettoyeLe?->format(\DateTimeInterface::ATOM),
            'nettoyePar' => $par ? ($this->userManager->getDisplayName($par) ?? $par) : null,
        ];
    }

    /**
     * RoomVox nous donne l'email de l'organisateur ; on cherche le compte
     * Nextcloud correspondant (pour afficher son vrai nom et son avatar).
     */
    private function utilisateurParEmail(string $email): ?\OCP\IUser {
        if ($email === '') {
            return null;
        }
        if (!array_key_exists($email, $this->cacheEmails)) {
            $comptes = $this->userManager->getByEmail($email);
            // On ignore les comptes techniques de salle (rb_…) : ils ont
            // une adresse mais ne sont pas des personnes.
            $comptes = array_values(array_filter($comptes, fn ($u) => !RoomVoxPasserelle::estCompteSalle($u->getUID())));
            $this->cacheEmails[$email] = $comptes[0] ?? null;
        }
        return $this->cacheEmails[$email];
    }
}
