<?php

declare(strict_types=1);

namespace OCA\Brassage\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès à la table oc_brassage_journal.
 *
 * @extends QBMapper<JournalEntree>
 */
class JournalEntreeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'brassage_journal', JournalEntree::class);
    }

    /**
     * Le journal d'un brassin, du plus ancien au plus récent (ordre de lecture
     * naturel pour une courbe de densité).
     *
     * @return JournalEntree[]
     */
    public function findParBrassin(int $brassinId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('brassin_id', $qb->createNamedParameter($brassinId, IQueryBuilder::PARAM_INT)))
            ->orderBy('cree_le', 'ASC')
            ->addOrderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * La DERNIÈRE mesure d'un type (ex. « temperature ») pour plusieurs
     * brassins d'un coup : c'est ce qu'affiche l'écran de la machine.
     *
     * Une seule requête, triée du plus récent au plus ancien : on garde la
     * première ligne rencontrée pour chaque brassin.
     *
     * @param int[] $brassinIds
     * @return array<int, JournalEntree> brassinId → dernière mesure
     */
    public function dernieresMesures(array $brassinIds, string $type): array {
        if ($brassinIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('brassin_id', $qb->createNamedParameter($brassinIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('cree_le', 'DESC')
            ->addOrderBy('id', 'DESC');

        $resultat = [];
        foreach ($this->findEntities($qb) as $entree) {
            $resultat[$entree->getBrassinId()] ??= $entree;
        }
        return $resultat;
    }

    /**
     * Une entrée précise, en vérifiant qu'elle appartient bien au brassin
     * demandé (sinon on pourrait supprimer l'entrée d'un autre brassin en
     * bricolant l'URL).
     */
    public function findDansBrassin(int $brassinId, int $entreeId): ?JournalEntree {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entreeId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('brassin_id', $qb->createNamedParameter($brassinId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
