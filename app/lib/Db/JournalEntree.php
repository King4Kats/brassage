<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Une ligne du journal d'un brassin : une mesure (densité, température) ou une note.
 *
 * @method int getBrassinId()
 * @method void setBrassinId(int $brassinId)
 * @method string getType()
 * @method void setType(string $type)
 * @method float|null getValeur()
 * @method void setValeur(?float $valeur)
 * @method string|null getTexte()
 * @method void setTexte(?string $texte)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getCreeLe()
 * @method void setCreeLe(\DateTime $creeLe)
 */
class JournalEntree extends Entity implements JsonSerializable {
    /**
     * Types d'entrée autorisés, avec les bornes « raisonnables » des valeurs.
     * Les bornes évitent les fautes de frappe (1048 au lieu de 1.048 pour une
     * densité, par exemple). null = pas de valeur numérique (simple note).
     */
    public const TYPES = [
        'note' => null,
        'densite' => ['min' => 0.98, 'max' => 1.2],
        'temperature' => ['min' => -10.0, 'max' => 110.0],
    ];

    protected $brassinId;
    protected $type;
    protected $valeur;
    protected $texte;
    protected $userId;
    protected $creeLe;

    public function __construct() {
        $this->addType('brassinId', Types::BIGINT);
        $this->addType('type', Types::STRING);
        $this->addType('valeur', Types::FLOAT);
        $this->addType('texte', Types::TEXT);
        $this->addType('userId', Types::STRING);
        $this->addType('creeLe', Types::DATETIME);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'brassinId' => $this->brassinId,
            'type' => $this->type,
            'valeur' => $this->valeur,
            'texte' => $this->texte,
            'userId' => $this->userId,
            'creeLe' => $this->creeLe?->format(\DateTimeInterface::ATOM),
        ];
    }
}
