<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès à la table oc_brassage_brassins (voir EquipementMapper pour le principe).
 *
 * @extends QBMapper<Brassin>
 */
class BrassinMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'brassage_brassins', Brassin::class);
    }

    /**
     * Le brassin lié à une réservation précise, ou null.
     */
    public function findParReservation(string $roomId, string $bookingUid): ?Brassin {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)))
            ->andWhere($qb->expr()->eq('booking_uid', $qb->createNamedParameter($bookingUid)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Un brassin par son id, ou null.
     */
    public function findParId(int $id): ?Brassin {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            // PARAM_INT : on précise que c'est un nombre (sinon il serait envoyé
            // comme une chaîne, ce qui marche mais est moins propre).
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Tous les brassins de plusieurs salles d'un coup, indexés par
     * « roomId|bookingUid ». Une seule requête SQL au lieu d'une par cuve :
     * c'est ce qu'on appelle éviter le problème « N+1 ».
     *
     * @param string[] $roomIds
     * @return array<string, Brassin>
     */
    public function findParSalles(array $roomIds): array {
        if ($roomIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('room_id', $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_STR_ARRAY)));

        $resultat = [];
        foreach ($this->findEntities($qb) as $brassin) {
            $resultat[self::cle($brassin->getRoomId(), $brassin->getBookingUid())] = $brassin;
        }
        return $resultat;
    }

    /** La clé utilisée par findParSalles(), pour que l'appelant fabrique la même. */
    public static function cle(string $roomId, string $bookingUid): string {
        return $roomId . '|' . $bookingUid;
    }
}
