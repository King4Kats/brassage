<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès à la table oc_brassage_etapes (voir EquipementMapper pour le principe).
 *
 * @extends QBMapper<Etape>
 */
class EtapeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'brassage_etapes', Etape::class);
    }

    /**
     * Les étapes personnalisées de plusieurs machines, en UNE requête,
     * groupées par machine et dans l'ordre.
     *
     * @param string[] $roomIds
     * @return array<string, list<Etape>> roomId → étapes (absent = pas personnalisé)
     */
    public function findParSalles(array $roomIds): array {
        if ($roomIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('room_id', $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('room_id')
            ->addOrderBy('ordre');

        $resultat = [];
        foreach ($this->findEntities($qb) as $etape) {
            $resultat[$etape->getRoomId()][] = $etape;
        }
        return $resultat;
    }

    /** @return list<Etape> */
    public function findParSalle(string $roomId): array {
        return $this->findParSalles([$roomId])[$roomId] ?? [];
    }

    /**
     * Supprime toutes les étapes d'une machine (→ retour aux étapes par défaut).
     * Une seule requête DELETE plutôt qu'une par étape.
     */
    public function supprimerParSalle(string $roomId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)))
            ->executeStatement();
    }
}
