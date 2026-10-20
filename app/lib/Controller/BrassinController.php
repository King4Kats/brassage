<?php

declare(strict_types=1);

namespace OCA\Brassage\Controller;

use OCA\Brassage\Db\Brassin;
use OCA\Brassage\Db\BrassinMapper;
use OCA\Brassage\Exception\ErreurApi;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * PUT /api/equipements/{roomId}/reservations/{bookingUid}/brassin
 * Crée ou met à jour « ce qu'il y a dans la cuve » pour une réservation.
 *
 * La réservation elle-même est faite par RoomVox (depuis le calendrier ou
 * depuis notre bouton « Réserver », qui appelle l'API de RoomVox). Nous, on
 * accroche seulement le contenu à cette réservation.
 */
class BrassinController extends ControleurBase {
    public function __construct(
        IRequest $request,
        IUserSession $session,
        RoomVoxPasserelle $roomVox,
        LoggerInterface $logger,
        private BrassinMapper $mapper,
        private ITimeFactory $horloge,
    ) {
        parent::__construct($request, $session, $roomVox, $logger);
    }

    #[NoAdminRequired]
    public function enregistrer(
        string $roomId,
        string $bookingUid,
        string $nom,
        ?string $style = null,
        ?string $couleur = null,
        ?int $volumeL = null,
        ?string $notes = null,
    ): JSONResponse {
        return $this->executer(function () use ($roomId, $bookingUid, $nom, $style, $couleur, $volumeL, $notes) {
            // 1. Droits sur la cuve : au moins « peut réserver ».
            [$salle, $role] = $this->exigerRole($roomId, 'booker');

            // 2. La réservation existe-t-elle vraiment chez RoomVox ?
            $maintenant = \DateTimeImmutable::createFromInterface($this->horloge->now());
            $reservation = $this->roomVox->reservation($salle['compte'], $bookingUid, $maintenant);
            if ($reservation === null) {
                throw ErreurApi::introuvable('Réservation introuvable (annulée ?).');
            }

            // 3. Seul l'organisateur de la réservation, ou un responsable,
            //    peut décrire son contenu. On compare les emails, parce que
            //    c'est ce que le calendrier connaît de l'organisateur.
            $emailUtilisateur = strtolower((string)$this->session->getUser()?->getEMailAddress());
            $estOrganisateur = $emailUtilisateur !== '' && $emailUtilisateur === $reservation['organisateurEmail'];
            if ($role !== 'manager' && !$estOrganisateur) {
                throw ErreurApi::interdit('Seul·e l\'organisateur·rice de la réservation ou un·e responsable peut renseigner le contenu.');
            }

            // 4. Validation des champs (on ne fait jamais confiance au navigateur).
            $nom = trim($nom);
            if ($nom === '' || mb_strlen($nom) > 128) {
                throw ErreurApi::invalide('Le nom de la bière est obligatoire (128 caractères max).');
            }
            if ($couleur !== null && $couleur !== '' && !in_array($couleur, Brassin::COULEURS, true)) {
                throw ErreurApi::invalide('Couleur inconnue.');
            }
            if ($volumeL !== null && ($volumeL < 1 || $volumeL > 100000)) {
                throw ErreurApi::invalide('Volume invalide (en litres).');
            }
            if ($style !== null && mb_strlen($style) > 64) {
                throw ErreurApi::invalide('Style trop long (64 caractères max).');
            }

            // 5. Création ou mise à jour.
            $brassin = $this->mapper->findParReservation($roomId, $bookingUid);
            $nouveau = $brassin === null;
            $instant = \DateTime::createFromImmutable($maintenant);
            if ($nouveau) {
                $brassin = new Brassin();
                $brassin->setRoomId($roomId);
                $brassin->setBookingUid($bookingUid);
                $brassin->setUserId($this->utilisateur());
                $brassin->setCreeLe($instant);
            }
            $brassin->setNom($nom);
            // « ?: null » transforme une chaîne vide en null (champ effacé).
            $brassin->setStyle(trim((string)$style) ?: null);
            $brassin->setCouleur($couleur ?: null);
            $brassin->setVolumeL($volumeL);
            $brassin->setNotes(trim((string)$notes) ?: null);
            $brassin->setModifieLe($instant);

            return $nouveau ? $this->mapper->insert($brassin) : $this->mapper->update($brassin);
        });
    }
}
