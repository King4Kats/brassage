<?php

declare(strict_types=1);

namespace OCA\Brassage\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Point d'entrée de l'app pour Nextcloud.
 *
 * Nextcloud appelle register() très tôt (pour déclarer des écouteurs
 * d'événements, des tableaux de bord…) puis boot() quand tout est chargé.
 * Notre app n'a besoin de rien de spécial : les contrôleurs et services sont
 * trouvés automatiquement par « autowiring » (Nextcloud lit les types des
 * paramètres des constructeurs et fabrique les objets tout seul).
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'brassage';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
    }
}
