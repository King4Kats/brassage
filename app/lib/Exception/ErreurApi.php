<?php

declare(strict_types=1);

namespace OCA\Brassage\Exception;

use OCP\AppFramework\Http;

/**
 * Une erreur « attendue » qu'on veut renvoyer proprement au navigateur,
 * avec un code HTTP et un message lisible.
 *
 * Plutôt que d'écrire « return new JSONResponse([...], 403) » partout, le code
 * métier lance (throw) une ErreurApi, et ControleurBase::executer() la
 * transforme en réponse JSON. Le code reste ainsi lisible de haut en bas.
 *
 * Les petites méthodes statiques (introuvable(), interdit()…) sont des
 * « constructeurs nommés » : throw ErreurApi::interdit() se lit mieux que
 * throw new ErreurApi('...', 403).
 */
class ErreurApi extends \RuntimeException {
    public function __construct(string $message, private int $statutHttp) {
        parent::__construct($message);
    }

    public function statutHttp(): int {
        return $this->statutHttp;
    }

    /** 400 : la requête est mal formée (champ manquant, valeur absurde…). */
    public static function invalide(string $message): self {
        return new self($message, Http::STATUS_BAD_REQUEST);
    }

    /** 403 : connecté, mais pas le droit. */
    public static function interdit(string $message = 'Vous n\'avez pas les droits pour cette action.'): self {
        return new self($message, Http::STATUS_FORBIDDEN);
    }

    /** 404 : la chose demandée n'existe pas (ou plus). */
    public static function introuvable(string $message): self {
        return new self($message, Http::STATUS_NOT_FOUND);
    }

    /** 503 : RoomVox n'est pas installé, on ne peut rien faire. */
    public static function roomVoxAbsent(): self {
        return new self('RoomVox n\'est pas installé ou pas activé : Brassage en a besoin.', Http::STATUS_SERVICE_UNAVAILABLE);
    }
}
