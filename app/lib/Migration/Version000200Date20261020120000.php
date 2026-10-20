<?php

declare(strict_types=1);

namespace OCA\Brassage\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Version 0.2.0 : étapes personnalisées par machine.
 *
 * On ne touche PAS à la migration 0.1.0 (déjà installée chez tout le monde) :
 * on ajoute une nouvelle migration. Nextcloud l'exécute au prochain
 * « occ upgrade », parce que la version dans info.xml a changé.
 *
 * Si une machine n'a AUCUNE ligne ici, elle utilise les étapes par défaut de
 * son type (lib/Service/Gabarits.php). Dès qu'un·e responsable enregistre
 * ses propres étapes, ce sont elles qui comptent.
 */
class Version000200Date20261020120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('brassage_etapes')) {
            $table = $schema->createTable('brassage_etapes');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            // La machine (salle RoomVox) à laquelle appartient l'étape.
            $table->addColumn('room_id', Types::STRING, ['notnull' => true, 'length' => 128]);
            // Position dans la liste : 0, 1, 2…
            $table->addColumn('ordre', Types::SMALLINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('nom', Types::STRING, ['notnull' => true, 'length' => 64]);
            // Part de la durée totale (même principe que Gabarits.php : on
            // divise par la somme des parts, donc pas besoin de faire 100).
            $table->addColumn('part', Types::SMALLINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('explication', Types::TEXT, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            // On lit toujours « les étapes d'une machine, dans l'ordre ».
            $table->addIndex(['room_id', 'ordre'], 'brassage_etapes_salle');
        }

        return $schema;
    }
}
