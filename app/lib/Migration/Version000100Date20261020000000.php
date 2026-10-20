<?php

declare(strict_types=1);

namespace OCA\Brassage\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Création des tables de l'app.
 *
 * Comment ça marche : à l'activation de l'app (ou à un « occ upgrade »),
 * Nextcloud regarde les fichiers de lib/Migration/ et exécute ceux qu'il n'a
 * jamais exécutés (il garde la liste dans la table oc_migrations).
 * Le nom du fichier encode la version de l'app (000100 = 0.1.0) et une date :
 * c'est ce qui donne l'ordre d'exécution.
 *
 * Règle d'or : on ne modifie JAMAIS une migration déjà livrée. Pour changer
 * le schéma, on crée une nouvelle migration (Version000200Date…).
 *
 * Ce qu'on stocke ici, c'est UNIQUEMENT ce que RoomVox ne sait pas stocker.
 * Les salles (nos cuves) et les réservations restent chez RoomVox.
 * On fait le lien par l'identifiant de salle RoomVox (room_id) et par l'UID
 * de la réservation (booking_uid, l'identifiant de l'événement calendrier).
 */
class Version000100Date20261020000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── 1. Équipements : ce qu'on sait EN PLUS sur une salle RoomVox ──
        // Nextcloud ajoute tout seul le préfixe « oc_ » : la table s'appellera
        // oc_brassage_equipements.
        if (!$schema->hasTable('brassage_equipements')) {
            $table = $schema->createTable('brassage_equipements');
            // Clé primaire auto-incrémentée, classique.
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            // Identifiant de la salle chez RoomVox (ex. « fermenteur-1 »).
            $table->addColumn('room_id', Types::STRING, ['notnull' => true, 'length' => 128]);
            // Type d'équipement : brassage | fermenteur | garde | alambic.
            // Il décide du dessin affiché et des étapes proposées.
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'fermenteur']);
            // Contenance en litres (affichage seulement).
            $table->addColumn('capacite_l', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            // Date du dernier nettoyage. Si une réservation s'est terminée APRÈS
            // cette date, la cuve est « à nettoyer ».
            $table->addColumn('nettoye_le', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('nettoye_par', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->setPrimaryKey(['id']);
            // Une seule ligne par salle RoomVox.
            $table->addUniqueIndex(['room_id'], 'brassage_equip_room');
        }

        // ── 2. Brassins : le contenu d'UNE réservation ──
        if (!$schema->hasTable('brassage_brassins')) {
            $table = $schema->createTable('brassage_brassins');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('room_id', Types::STRING, ['notnull' => true, 'length' => 128]);
            // UID de l'événement calendrier créé par RoomVox. Un UID iCalendar
            // peut être long (Apple en génère de 36 à plus de 100 caractères).
            $table->addColumn('booking_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
            // Ce qu'il y a dans la cuve.
            $table->addColumn('nom', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('style', Types::STRING, ['notnull' => false, 'length' => 64]);
            // Couleur de la bière (clé de la palette côté front : blonde, ambree…),
            // utilisée pour « remplir » la cuve dans le dessin.
            $table->addColumn('couleur', Types::STRING, ['notnull' => false, 'length' => 32]);
            $table->addColumn('volume_l', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('notes', Types::TEXT, ['notnull' => false]);
            // Qui a renseigné le contenu (identifiant Nextcloud).
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('cree_le', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('modifie_le', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            // Un brassin par réservation, pas plus.
            $table->addUniqueIndex(['room_id', 'booking_uid'], 'brassage_brassin_resa');
        }

        // ── 3. Journal : mesures et notes au fil du brassin ──
        if (!$schema->hasTable('brassage_journal')) {
            $table = $schema->createTable('brassage_journal');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('brassin_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            // note | densite | temperature
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
            // Valeur numérique pour les mesures (ex. 1.048 pour une densité,
            // 19.5 pour des °C). Vide pour une simple note.
            $table->addColumn('valeur', Types::FLOAT, ['notnull' => false]);
            $table->addColumn('texte', Types::TEXT, ['notnull' => false]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('cree_le', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            // On lit toujours le journal « d'un brassin », donc on indexe dessus.
            $table->addIndex(['brassin_id'], 'brassage_journal_brassin');
        }

        return $schema;
    }
}
