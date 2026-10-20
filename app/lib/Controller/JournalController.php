<?php

declare(strict_types=1);

namespace OCA\Brassage\Controller;

use OCA\Brassage\Db\Brassin;
use OCA\Brassage\Db\BrassinMapper;
use OCA\Brassage\Db\JournalEntree;
use OCA\Brassage\Db\JournalEntreeMapper;
use OCA\Brassage\Exception\ErreurApi;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Le journal de bord d'un brassin : mesures de densité, de température, notes.
 *
 * Droits :
 * - lire : toute personne qui voit la cuve ;
 * - ajouter : toute personne qui peut réserver la cuve (on s'entraide pour
 *   relever les mesures) ;
 * - supprimer : l'auteur·rice de l'entrée, ou un·e responsable.
 */
class JournalController extends ControleurBase {
    public function __construct(
        IRequest $request,
        IUserSession $session,
        RoomVoxPasserelle $roomVox,
        LoggerInterface $logger,
        private BrassinMapper $brassins,
        private JournalEntreeMapper $journal,
        private IUserManager $userManager,
        private ITimeFactory $horloge,
    ) {
        parent::__construct($request, $session, $roomVox, $logger);
    }

    /** GET /api/brassins/{brassinId}/journal */
    #[NoAdminRequired]
    public function index(int $brassinId): JSONResponse {
        return $this->executer(function () use ($brassinId) {
            $brassin = $this->brassinOu404($brassinId);
            $this->exigerRole($brassin->getRoomId(), 'viewer');

            // On ajoute le nom affiché de l'auteur à chaque entrée.
            return array_map(fn (JournalEntree $e) => $e->jsonSerialize() + [
                'auteur' => $this->userManager->getDisplayName($e->getUserId()) ?? $e->getUserId(),
            ], $this->journal->findParBrassin($brassinId));
        });
    }

    /** POST /api/brassins/{brassinId}/journal  { type, valeur?, texte? } */
    #[NoAdminRequired]
    public function ajouter(int $brassinId, string $type, ?float $valeur = null, ?string $texte = null): JSONResponse {
        return $this->executer(function () use ($brassinId, $type, $valeur, $texte) {
            $brassin = $this->brassinOu404($brassinId);
            $this->exigerRole($brassin->getRoomId(), 'booker');

            if (!array_key_exists($type, JournalEntree::TYPES)) {
                throw ErreurApi::invalide('Type d\'entrée inconnu.');
            }
            $bornes = JournalEntree::TYPES[$type];
            $texte = trim((string)$texte);

            if ($bornes === null) {
                // Une note : il faut un texte, pas de valeur.
                if ($texte === '') {
                    throw ErreurApi::invalide('La note est vide.');
                }
                $valeur = null;
            } elseif ($valeur === null || $valeur < $bornes['min'] || $valeur > $bornes['max']) {
                // Une mesure : il faut une valeur dans les bornes.
                throw ErreurApi::invalide(sprintf('Valeur attendue entre %s et %s.', $bornes['min'], $bornes['max']));
            }
            if (mb_strlen($texte) > 2000) {
                throw ErreurApi::invalide('Texte trop long (2000 caractères max).');
            }

            $entree = new JournalEntree();
            $entree->setBrassinId($brassinId);
            $entree->setType($type);
            $entree->setValeur($valeur);
            $entree->setTexte($texte ?: null);
            $entree->setUserId($this->utilisateur());
            $entree->setCreeLe(\DateTime::createFromImmutable($this->horloge->now()));

            $entree = $this->journal->insert($entree);
            return $entree->jsonSerialize() + [
                'auteur' => $this->userManager->getDisplayName($entree->getUserId()) ?? $entree->getUserId(),
            ];
        }, Http::STATUS_CREATED);
    }

    /** DELETE /api/brassins/{brassinId}/journal/{entreeId} */
    #[NoAdminRequired]
    public function supprimer(int $brassinId, int $entreeId): JSONResponse {
        return $this->executer(function () use ($brassinId, $entreeId) {
            $brassin = $this->brassinOu404($brassinId);
            [, $role] = $this->exigerRole($brassin->getRoomId(), 'viewer');

            $entree = $this->journal->findDansBrassin($brassinId, $entreeId);
            if ($entree === null) {
                throw ErreurApi::introuvable('Entrée introuvable.');
            }
            if ($role !== 'manager' && $entree->getUserId() !== $this->utilisateur()) {
                throw ErreurApi::interdit('Seul·e l\'auteur·rice ou un·e responsable peut supprimer cette entrée.');
            }

            $this->journal->delete($entree);
            return ['supprime' => true];
        });
    }

    private function brassinOu404(int $brassinId): Brassin {
        $brassin = $this->brassins->findParId($brassinId);
        if ($brassin === null) {
            throw ErreurApi::introuvable('Brassin introuvable.');
        }
        return $brassin;
    }
}
