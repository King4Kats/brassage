<?php

declare(strict_types=1);

namespace OCA\Brassage\Controller;

use OCA\Brassage\Db\Equipement;
use OCA\Brassage\Db\EquipementMapper;
use OCA\Brassage\Exception\ErreurApi;
use OCA\Brassage\Service\Etapes;
use OCA\Brassage\Service\ReglesReservation;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Réglages propres à Brassage sur un équipement (type, contenance) et
 * déclaration du nettoyage.
 */
class EquipementController extends ControleurBase {
    public function __construct(
        IRequest $request,
        IUserSession $session,
        RoomVoxPasserelle $roomVox,
        LoggerInterface $logger,
        private EquipementMapper $mapper,
        private Etapes $etapes,
        private ITimeFactory $horloge,
    ) {
        parent::__construct($request, $session, $roomVox, $logger);
    }

    /**
     * PUT /api/equipements/{roomId}  { type, capaciteL, nom? }
     * Réservé aux responsables de la cuve.
     *
     * Les paramètres $type, $capaciteL et $nom sont remplis automatiquement par
     * Nextcloud à partir du corps JSON de la requête (même nom = même valeur).
     * $nom est optionnel : s'il est fourni et différent, on renomme aussi la
     * salle chez RoomVox (le nom affiché partout vient de lui).
     */
    #[NoAdminRequired]
    public function update(string $roomId, string $type, ?int $capaciteL = null, ?string $nom = null): JSONResponse {
        return $this->executer(function () use ($roomId, $type, $capaciteL, $nom) {
            [$salle] = $this->exigerRole($roomId, 'manager');

            if (!in_array($type, Equipement::TYPES, true)) {
                throw ErreurApi::invalide('Type d\'équipement inconnu.');
            }
            if ($capaciteL !== null && ($capaciteL < 1 || $capaciteL > 100000)) {
                throw ErreurApi::invalide('Contenance invalide (en litres).');
            }
            $nom = $nom !== null ? trim($nom) : null;
            if ($nom !== null && ($nom === '' || mb_strlen($nom) > 128)) {
                throw ErreurApi::invalide('Le nom est obligatoire (128 caractères max).');
            }

            // On valide TOUT avant d'écrire quoi que ce soit : sinon une erreur
            // sur la contenance laisserait la salle renommée à moitié.
            if ($nom !== null && $nom !== $salle['nom']) {
                $this->roomVox->renommerSalle($roomId, $nom);
            }

            $equipement = $this->trouverOuCreer($roomId);
            $equipement->setType($type);
            $equipement->setCapaciteL($capaciteL);
            return $this->enregistrer($equipement);
        });
    }

    /**
     * POST /api/equipements/{roomId}/nettoyage
     * « J'ai nettoyé la cuve. » Toute personne qui peut réserver la cuve
     * peut le déclarer : dans une asso, c'est souvent le dernier qui l'a utilisée.
     */
    #[NoAdminRequired]
    public function nettoyer(string $roomId): JSONResponse {
        return $this->executer(function () use ($roomId) {
            $this->exigerRole($roomId, 'booker');

            $equipement = $this->trouverOuCreer($roomId);
            // now() renvoie un DateTimeImmutable ; la colonne attend un \DateTime.
            $equipement->setNettoyeLe(\DateTime::createFromImmutable($this->horloge->now()));
            $equipement->setNettoyePar($this->utilisateur());
            return $this->enregistrer($equipement);
        });
    }

    /**
     * PUT /api/equipements/{roomId}/etapes  { etapes: [{ nom, part, explication? }, …] }
     * Remplace les étapes de la machine. Réservé aux responsables.
     *
     * La nouvelle liste s'applique aussi à la réservation EN COURS : l'avancement
     * est recalculé à chaque affichage, rien n'est figé au moment de réserver.
     *
     * Note : les noms de routes en snake_case (enregistrer_etapes) sont
     * convertis par Nextcloud en méthodes camelCase (enregistrerEtapes).
     */
    #[NoAdminRequired]
    public function enregistrerEtapes(string $roomId, mixed $etapes = null): JSONResponse {
        return $this->executer(function () use ($roomId, $etapes) {
            $this->exigerRole($roomId, 'manager');
            return ['etapes' => $this->etapes->remplacer($roomId, $etapes)];
        });
    }

    /**
     * GET /api/equipements/{roomId}/creneau?debut=…&fin=…   (dates ISO 8601)
     * Réponse : { ok: true } ou { ok: false, raison: "…" }.
     *
     * Vérifie les horaires d'ouverture et le délai maximum réglés dans RoomVox.
     * Ce n'est PAS une barrière de sécurité (l'API de RoomVox reste appelable
     * directement) : c'est pour que notre bouton « Réserver » respecte les
     * mêmes règles que l'app Agenda. Voir le journal de bord, D13.
     * Les conflits avec d'autres réservations, eux, sont vérifiés par RoomVox.
     */
    #[NoAdminRequired]
    public function verifierCreneau(string $roomId, string $debut = '', string $fin = ''): JSONResponse {
        return $this->executer(function () use ($roomId, $debut, $fin) {
            [$salle] = $this->exigerRole($roomId, 'booker');
            // D'abord le cas « vide » : new DateTimeImmutable('') ne lève pas
            // d'erreur, il renvoie… maintenant. Ce serait une fausse date valide.
            if ($debut === '' || $fin === '') {
                throw ErreurApi::invalide('Les paramètres debut et fin sont obligatoires.');
            }
            try {
                $debutDt = new \DateTimeImmutable($debut);
                $finDt = new \DateTimeImmutable($fin);
            } catch (\Exception) {
                throw ErreurApi::invalide('Dates invalides (format attendu : ISO 8601).');
            }

            $raison = ReglesReservation::verifier(
                $salle['regles'],
                $salle['horizonJours'],
                $debutDt,
                $finDt,
                $this->horloge->now(),
                $this->roomVox->fuseau(),
            );
            return $raison === null ? ['ok' => true] : ['ok' => false, 'raison' => $raison];
        });
    }

    /**
     * DELETE /api/equipements/{roomId}/etapes
     * Revient aux étapes par défaut du type de machine.
     */
    #[NoAdminRequired]
    public function reinitialiserEtapes(string $roomId): JSONResponse {
        return $this->executer(function () use ($roomId) {
            $this->exigerRole($roomId, 'manager');
            $this->etapes->reinitialiser($roomId);
            return ['reinitialise' => true];
        });
    }

    /**
     * Les équipements n'ont une ligne en base qu'une fois réglés : avant,
     * on utilise les valeurs par défaut. D'où ce « trouver ou créer ».
     */
    private function trouverOuCreer(string $roomId): Equipement {
        $equipement = $this->mapper->findParRoomId($roomId);
        if ($equipement === null) {
            $equipement = new Equipement();
            $equipement->setRoomId($roomId);
            $equipement->setType('fermenteur');
        }
        return $equipement;
    }

    /** insert() si nouveau (pas encore d'id), update() sinon. */
    private function enregistrer(Equipement $equipement): Equipement {
        return $equipement->getId() === null
            ? $this->mapper->insert($equipement)
            : $this->mapper->update($equipement);
    }
}
