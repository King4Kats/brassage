<?php

declare(strict_types=1);

namespace OCA\Brassage\Service;

use OCA\Brassage\Db\Etape;
use OCA\Brassage\Db\EtapeMapper;
use OCA\Brassage\Exception\ErreurApi;
use OCP\IDBConnection;

/**
 * Les étapes d'une machine : personnalisées si un·e responsable les a
 * définies, sinon celles par défaut de son type (Gabarits.php).
 *
 * Tout le reste du code passe par ici pour savoir « quelles étapes
 * appliquer » : un seul endroit décide de la règle.
 */
class Etapes {
    /** Bornes de validation (évite les listes absurdes ou les fautes de frappe). */
    public const MAX_ETAPES = 12;
    public const MAX_PART = 1000;

    public function __construct(
        private EtapeMapper $mapper,
        private IDBConnection $db,
    ) {
    }

    /**
     * Les étapes à appliquer à une machine.
     *
     * @param list<Etape> $personnalisees ses étapes en base (peut être vide)
     * @return list<array{nom: string, part: int, explication: string}>
     */
    public static function pour(string $type, array $personnalisees): array {
        if ($personnalisees === []) {
            return Gabarits::etapes($type);
        }
        return array_map(fn (Etape $e) => $e->enGabarit(), $personnalisees);
    }

    /**
     * Remplace TOUTES les étapes d'une machine par $etapes, après validation.
     *
     * On fait « tout supprimer puis tout réinsérer » dans une TRANSACTION :
     * si une insertion échoue au milieu, la base revient à l'état d'avant
     * (rollBack) au lieu de laisser une liste à moitié écrite.
     *
     * @param mixed $etapes ce qu'a envoyé le navigateur (on ne lui fait pas confiance)
     * @return list<array{nom: string, part: int, explication: string}> les étapes enregistrées
     */
    public function remplacer(string $roomId, mixed $etapes): array {
        $propres = $this->valider($etapes);

        $this->db->beginTransaction();
        try {
            $this->mapper->supprimerParSalle($roomId);
            foreach ($propres as $ordre => $e) {
                $etape = new Etape();
                $etape->setRoomId($roomId);
                $etape->setOrdre($ordre);
                $etape->setNom($e['nom']);
                $etape->setPart($e['part']);
                $etape->setExplication($e['explication'] !== '' ? $e['explication'] : null);
                $this->mapper->insert($etape);
            }
            $this->db->commit();
        } catch (\Throwable $erreur) {
            $this->db->rollBack();
            throw $erreur;
        }
        return $propres;
    }

    /** Retour aux étapes par défaut du type : on efface les personnalisées. */
    public function reinitialiser(string $roomId): void {
        $this->mapper->supprimerParSalle($roomId);
    }

    /**
     * Vérifie et nettoie la liste reçue. Lève une ErreurApi (400) au premier
     * problème, avec un message qui dit quelle étape est en cause.
     *
     * @return list<array{nom: string, part: int, explication: string}>
     */
    private function valider(mixed $etapes): array {
        if (!is_array($etapes) || $etapes === [] || !array_is_list($etapes)) {
            throw ErreurApi::invalide('Il faut au moins une étape.');
        }
        if (count($etapes) > self::MAX_ETAPES) {
            throw ErreurApi::invalide(sprintf('%d étapes maximum.', self::MAX_ETAPES));
        }

        $propres = [];
        foreach ($etapes as $i => $e) {
            $numero = $i + 1; // pour des messages lisibles (« étape 2 »)
            $nom = trim((string)($e['nom'] ?? ''));
            $part = $e['part'] ?? null;
            $explication = trim((string)($e['explication'] ?? ''));

            if ($nom === '' || mb_strlen($nom) > 64) {
                throw ErreurApi::invalide("Étape $numero : le nom est obligatoire (64 caractères max).");
            }
            // is_numeric + vérification « entier » : on accepte 25 ou "25", pas 2.5 ni "abc".
            if (!is_numeric($part) || (int)$part != $part || (int)$part < 1 || (int)$part > self::MAX_PART) {
                throw ErreurApi::invalide(sprintf('Étape %d : la part doit être un nombre entier entre 1 et %d.', $numero, self::MAX_PART));
            }
            if (mb_strlen($explication) > 500) {
                throw ErreurApi::invalide("Étape $numero : explication trop longue (500 caractères max).");
            }
            $propres[] = ['nom' => $nom, 'part' => (int)$part, 'explication' => $explication];
        }
        return $propres;
    }
}
