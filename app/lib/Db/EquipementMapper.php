<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Le « Mapper » fait le lien entre la table et les objets Equipement.
 *
 * QBMapper (fourni par Nextcloud) sait déjà faire insert(), update(), delete().
 * On ajoute seulement les recherches dont on a besoin, écrites avec le
 * QueryBuilder (qui fabrique le SQL pour nous et protège des injections SQL :
 * on ne colle jamais une valeur dans la requête, on passe par createNamedParameter).
 *
 * @extends QBMapper<Equipement>
 */
class EquipementMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        // Nom de la table SANS le préfixe oc_, et la classe des objets renvoyés.
        parent::__construct($db, 'brassage_equipements', Equipement::class);
    }

    /**
     * Tous les équipements, indexés par room_id pour les retrouver vite.
     *
     * @return array<string, Equipement>
     */
    public function findAllParRoomId(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());

        $resultat = [];
        foreach ($this->findEntities($qb) as $equipement) {
            $resultat[$equipement->getRoomId()] = $equipement;
        }
        return $resultat;
    }

    /**
     * L'équipement d'une salle RoomVox, ou null s'il n'a jamais été réglé.
     */
    public function findParRoomId(string $roomId): ?Equipement {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)));

        try {
            // findEntity() lève une exception s'il n'y a aucune ligne :
            // on la transforme en « null », plus simple à manipuler.
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
