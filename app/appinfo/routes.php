<?php

declare(strict_types=1);

/**
 * Table des routes : « telle URL + tel verbe HTTP → telle méthode de contrôleur ».
 *
 * Convention Nextcloud : 'name' => 'atelier#index' veut dire
 *   classe  OCA\Brassage\Controller\AtelierController
 *   méthode index()
 * Les URL sont relatives à /index.php/apps/brassage.
 *
 * Les {paramètres} entre accolades sont passés automatiquement aux méthodes
 * qui ont un argument du même nom (ex. {roomId} → function show(string $roomId)).
 */
return [
    'routes' => [
        // La page HTML de l'app (le « squelette » dans lequel le JS dessine tout).
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Vue d'ensemble : tout le matériel + réservation en cours + avancement.
        ['name' => 'atelier#index', 'url' => '/api/atelier', 'verb' => 'GET'],

        // Réglages d'un équipement (type, contenance) et nettoyage.
        ['name' => 'equipement#update', 'url' => '/api/equipements/{roomId}', 'verb' => 'PUT'],
        ['name' => 'equipement#nettoyer', 'url' => '/api/equipements/{roomId}/nettoyage', 'verb' => 'POST'],
        // Ce créneau respecte-t-il les horaires d'ouverture et le délai maximum ?
        // (appelé par le front AVANT de réserver chez RoomVox, voir ReglesReservation.php)
        ['name' => 'equipement#verifier_creneau', 'url' => '/api/equipements/{roomId}/creneau', 'verb' => 'GET'],
        // Étapes personnalisées d'une machine (PUT = remplacer, DELETE = revenir à celles du type).
        ['name' => 'equipement#enregistrer_etapes', 'url' => '/api/equipements/{roomId}/etapes', 'verb' => 'PUT'],
        ['name' => 'equipement#reinitialiser_etapes', 'url' => '/api/equipements/{roomId}/etapes', 'verb' => 'DELETE'],

        // Contenu d'une réservation (le « brassin ») : on le crée/modifie par
        // l'identifiant de la réservation (champ « id » de l'API atelier : l'UID
        // calendrier, suivi de « #date » pour une occurrence de série).
        ['name' => 'brassin#enregistrer', 'url' => '/api/equipements/{roomId}/reservations/{bookingUid}/brassin', 'verb' => 'PUT'],

        // Journal de bord d'un brassin (mesures et notes).
        ['name' => 'journal#index', 'url' => '/api/brassins/{brassinId}/journal', 'verb' => 'GET'],
        ['name' => 'journal#ajouter', 'url' => '/api/brassins/{brassinId}/journal', 'verb' => 'POST'],
        ['name' => 'journal#supprimer', 'url' => '/api/brassins/{brassinId}/journal/{entreeId}', 'verb' => 'DELETE'],
    ],
];
