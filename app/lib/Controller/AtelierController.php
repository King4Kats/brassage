<?php

declare(strict_types=1);

namespace OCA\Brassage\Controller;

use OCA\Brassage\Service\AtelierService;
use OCA\Brassage\Service\Gabarits;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /api/atelier : tout ce qu'il faut pour dessiner la vue d'ensemble.
 */
class AtelierController extends ControleurBase {
    public function __construct(
        IRequest $request,
        IUserSession $session,
        RoomVoxPasserelle $roomVox,
        LoggerInterface $logger,
        private AtelierService $atelier,
        private ITimeFactory $horloge,
    ) {
        parent::__construct($request, $session, $roomVox, $logger);
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        return $this->executer(fn () => [
            // L'heure du serveur : le front s'en sert pour faire avancer les
            // jauges sans redemander toutes les données à chaque seconde.
            'maintenant' => $this->horloge->now()->format(\DateTimeInterface::ATOM),
            'equipements' => $this->atelier->pourUtilisateur($this->utilisateur()),
            // Les gabarits d'étapes, pour l'aide « que se passe-t-il ? ».
            'gabarits' => Gabarits::PAR_TYPE,
        ]);
    }
}
