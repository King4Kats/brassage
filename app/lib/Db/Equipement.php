<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Une ligne de la table oc_brassage_equipements.
 *
 * Une « Entity » Nextcloud, c'est un objet PHP qui représente une ligne de table.
 * On déclare une propriété par colonne, en camelCase : la colonne room_id
 * devient la propriété $roomId. Nextcloud génère tout seul les getters/setters
 * (getRoomId(), setRoomId()…) grâce à la méthode magique __call de Entity.
 * C'est pour ça qu'on ne les écrit pas, mais qu'on les documente avec @method
 * (pour que l'éditeur de code les connaisse).
 *
 * @method string getRoomId()
 * @method void setRoomId(string $roomId)
 * @method string getType()
 * @method void setType(string $type)
 * @method int|null getCapaciteL()
 * @method void setCapaciteL(?int $capaciteL)
 * @method \DateTime|null getNettoyeLe()
 * @method void setNettoyeLe(?\DateTime $nettoyeLe)
 * @method string|null getNettoyePar()
 * @method void setNettoyePar(?string $nettoyePar)
 */
class Equipement extends Entity implements JsonSerializable {
    /** Les 4 types connus. Toute autre valeur est refusée par le contrôleur. */
    public const TYPES = ['brassage', 'fermenteur', 'garde', 'alambic'];

    // « protected » et pas « private » : la classe parente Entity doit pouvoir
    // les lire et les écrire.
    protected $roomId;
    protected $type;
    protected $capaciteL;
    protected $nettoyeLe;
    protected $nettoyePar;

    public function __construct() {
        // On dit à Nextcloud le type de chaque colonne, pour qu'il convertisse
        // correctement (ex. la chaîne « 2026-10-20 10:00:00 » de la base devient
        // un vrai objet \DateTime).
        $this->addType('roomId', Types::STRING);
        $this->addType('type', Types::STRING);
        $this->addType('capaciteL', Types::INTEGER);
        $this->addType('nettoyeLe', Types::DATETIME);
        $this->addType('nettoyePar', Types::STRING);
    }

    /** Ce que l'API renvoie en JSON quand on fait json_encode($equipement). */
    public function jsonSerialize(): array {
        return [
            'roomId' => $this->roomId,
            'type' => $this->type,
            'capaciteL' => $this->capaciteL,
            // Format ISO 8601 (« 2026-10-20T10:00:00+00:00 ») : le JavaScript
            // sait le lire directement avec new Date(...).
            'nettoyeLe' => $this->nettoyeLe?->format(\DateTimeInterface::ATOM),
            'nettoyePar' => $this->nettoyePar,
        ];
    }
}
