<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Le contenu d'une réservation : « ce qu'on brasse dans cette cuve ».
 * Voir Equipement.php pour l'explication du fonctionnement d'une Entity.
 *
 * @method string getRoomId()
 * @method void setRoomId(string $roomId)
 * @method string getBookingUid()
 * @method void setBookingUid(string $bookingUid)
 * @method string getNom()
 * @method void setNom(string $nom)
 * @method string|null getStyle()
 * @method void setStyle(?string $style)
 * @method string|null getCouleur()
 * @method void setCouleur(?string $couleur)
 * @method int|null getVolumeL()
 * @method void setVolumeL(?int $volumeL)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getCreeLe()
 * @method void setCreeLe(\DateTime $creeLe)
 * @method \DateTime getModifieLe()
 * @method void setModifieLe(\DateTime $modifieLe)
 */
class Brassin extends Entity implements JsonSerializable {
    /**
     * Couleurs de bière proposées. Ce sont des clés : la vraie couleur (le code
     * hexadécimal) est définie côté front dans src/couleurs.js.
     */
    public const COULEURS = ['blanche', 'blonde', 'ambree', 'rousse', 'brune', 'noire', 'distillat'];

    protected $roomId;
    protected $bookingUid;
    protected $nom;
    protected $style;
    protected $couleur;
    protected $volumeL;
    protected $notes;
    protected $userId;
    protected $creeLe;
    protected $modifieLe;

    public function __construct() {
        $this->addType('roomId', Types::STRING);
        $this->addType('bookingUid', Types::STRING);
        $this->addType('nom', Types::STRING);
        $this->addType('style', Types::STRING);
        $this->addType('couleur', Types::STRING);
        $this->addType('volumeL', Types::INTEGER);
        $this->addType('notes', Types::TEXT);
        $this->addType('userId', Types::STRING);
        $this->addType('creeLe', Types::DATETIME);
        $this->addType('modifieLe', Types::DATETIME);
    }

    public function jsonSerialize(): array {
        return [
            // getId() vient de Entity : toute Entity a une colonne « id ».
            'id' => $this->getId(),
            'roomId' => $this->roomId,
            'bookingUid' => $this->bookingUid,
            'nom' => $this->nom,
            'style' => $this->style,
            'couleur' => $this->couleur,
            'volumeL' => $this->volumeL,
            'notes' => $this->notes,
            'userId' => $this->userId,
            'creeLe' => $this->creeLe?->format(\DateTimeInterface::ATOM),
            'modifieLe' => $this->modifieLe?->format(\DateTimeInterface::ATOM),
        ];
    }
}
