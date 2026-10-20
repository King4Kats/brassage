<?php

declare(strict_types=1);

namespace OCA\Brassage\Controller;

use OCA\Brassage\AppInfo\Application;
use OCA\Brassage\Exception\ErreurApi;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Ce que tous nos contrôleurs d'API ont en commun.
 *
 * Un « contrôleur », c'est la porte d'entrée d'une requête HTTP : il lit les
 * paramètres, appelle le bon service, et renvoie une réponse. Il ne doit pas
 * contenir de logique métier compliquée (ça, c'est le rôle des services).
 *
 * « abstract » : on ne peut pas utiliser cette classe directement, seulement
 * en hériter (class AtelierController extends ControleurBase).
 */
abstract class ControleurBase extends Controller {
    /** Ordre des rôles RoomVox, du plus faible au plus fort. */
    private const NIVEAUX = ['none' => 0, 'viewer' => 1, 'booker' => 2, 'manager' => 3];

    public function __construct(
        IRequest $request,
        protected IUserSession $session,
        protected RoomVoxPasserelle $roomVox,
        protected LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Exécute $action et transforme le résultat en réponse JSON :
     * - tout va bien → 200 (ou $statut) avec le résultat ;
     * - ErreurApi → son code HTTP et son message ;
     * - n'importe quelle autre erreur → 500, et on la note dans le journal
     *   Nextcloud (data/nextcloud.log) pour pouvoir déboguer.
     */
    protected function executer(callable $action, int $statut = Http::STATUS_OK): JSONResponse {
        try {
            if (!$this->roomVox->disponible()) {
                throw ErreurApi::roomVoxAbsent();
            }
            return new JSONResponse($action(), $statut);
        } catch (ErreurApi $e) {
            return new JSONResponse(['erreur' => $e->getMessage()], $e->statutHttp());
        } catch (\Throwable $e) {
            // On ne renvoie PAS le détail technique au navigateur (ça pourrait
            // révéler des infos internes), seulement dans le journal serveur.
            $this->logger->error('Brassage : erreur inattendue', ['exception' => $e]);
            return new JSONResponse(['erreur' => 'Erreur interne, voir le journal Nextcloud.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** L'identifiant de la personne connectée. */
    protected function utilisateur(): string {
        $user = $this->session->getUser();
        if ($user === null) {
            // Ne devrait pas arriver : Nextcloud refuse déjà les requêtes non
            // connectées sur nos routes. Mais on ne suppose jamais.
            throw new ErreurApi('Non connecté.', Http::STATUS_UNAUTHORIZED);
        }
        return $user->getUID();
    }

    /**
     * Vérifie que la salle existe et que l'utilisateur a AU MOINS le rôle
     * demandé dessus. Renvoie la salle et le rôle réel.
     *
     * @param 'viewer'|'booker'|'manager' $roleMinimum
     * @return array{0: array<string, mixed>, 1: string} [salle, rôle]
     */
    protected function exigerRole(string $roomId, string $roleMinimum): array {
        $salle = $this->roomVox->salle($roomId);
        $role = $this->roomVox->role($this->utilisateur(), $roomId);

        // Quelqu'un qui n'a même pas le droit de VOIR la cuve reçoit « introuvable »,
        // pas « interdit » : on ne lui révèle pas qu'elle existe.
        if ($salle === null || $role === 'none') {
            throw ErreurApi::introuvable('Équipement introuvable.');
        }
        if (self::NIVEAUX[$role] < self::NIVEAUX[$roleMinimum]) {
            throw ErreurApi::interdit();
        }
        return [$salle, $role];
    }
}
