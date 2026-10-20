<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Une étape personnalisée d'une machine (table oc_brassage_etapes).
 * Voir Equipement.php pour l'explication du fonctionnement d'une Entity.
 *
 * @method string getRoomId()
 * @method void setRoomId(string $roomId)
 * @method int getOrdre()
 * @method void setOrdre(int $ordre)
 * @method string getNom()
 * @method void setNom(string $nom)
 * @method int getPart()
 * @method void setPart(int $part)
 * @method string|null getExplication()
 * @method void setExplication(?string $explication)
 */
class Etape extends Entity {
    protected $roomId;
    protected $ordre;
    protected $nom;
    protected $part;
    protected $explication;

    public function __construct() {
        $this->addType('roomId', Types::STRING);
        $this->addType('ordre', Types::SMALLINT);
        $this->addType('nom', Types::STRING);
        $this->addType('part', Types::SMALLINT);
        $this->addType('explication', Types::TEXT);
    }

    /**
     * Le même format que les étapes de Gabarits.php : le reste du code
     * (Avancement, le front) ne voit pas la différence entre une étape par
     * défaut et une étape personnalisée.
     *
     * @return array{nom: string, part: int, explication: string}
     */
    public function enGabarit(): array {
        return [
            'nom' => $this->nom,
            'part' => $this->part,
            'explication' => $this->explication ?? '',
        ];
    }
}
