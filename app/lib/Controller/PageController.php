<?php

declare(strict_types=1);

namespace OCA\Brassage\Controller;

use OCA\Brassage\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Sert la page HTML de l'app. Elle est presque vide : c'est le JavaScript
 * (src/main.js, compilé dans js/brassage-main.js) qui dessine tout.
 */
class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Les « attributs » PHP entre #[ ] règlent la sécurité Nextcloud :
     * - NoAdminRequired : tout utilisateur connecté peut ouvrir la page
     *   (par défaut, Nextcloud réserve tout aux admins) ;
     * - NoCSRFRequired : c'est une simple page qu'on ouvre depuis un lien,
     *   il n'y a pas de jeton CSRF à vérifier. Les routes d'API qui
     *   MODIFIENT des données, elles, gardent la protection CSRF.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // On demande à Nextcloud d'inclure notre JS et notre CSS dans la page.
        Util::addScript(Application::APP_ID, 'brassage-main');
        Util::addStyle(Application::APP_ID, 'brassage');

        // templates/main.php
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
