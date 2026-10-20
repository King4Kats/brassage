<?php

declare(strict_types=1);

namespace OCA\Brassage\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Calcule « où en est la cuve » à partir des dates de la réservation.
 *
 * Choix (voir docs/journal-de-bord.md) : l'avancement, c'est le TEMPS.
 *   pourcentage = temps écoulé depuis le début / durée totale de la réservation
 * Exemple : réservée du 1er au 31, on est le 13 → 40 %, reste 18 jours.
 *
 * Les étapes (celles de la machine, ou par défaut celles de son type) sont ensuite posées sur cette durée,
 * chacune avec sa part : on sait donc aussi quelle étape est « en cours ».
 *
 * Cette classe ne parle ni à la base ni à RoomVox : elle reçoit des dates et
 * renvoie un tableau. C'est volontaire, ça la rend facile à tester
 * (voir tests/AvancementTest.php) et à comprendre.
 */
final class Avancement {
    /**
     * @param DateTimeInterface $debut début de la réservation
     * @param DateTimeInterface $fin fin de la réservation
     * @param DateTimeInterface $maintenant « l'heure actuelle » — on la passe en
     *                                      paramètre pour pouvoir tester avec une date fixe
     * @param list<array{nom: string, part: int, explication: string}> $gabarit les étapes
     *        à poser sur la durée : celles de la machine si un·e responsable les a
     *        personnalisées, sinon celles de son type (voir Etapes::pour())
     * @return array{
     *     pourcentage: float,
     *     secondesRestantes: int,
     *     joursRestants: int,
     *     etapeCourante: int|null,
     *     etapes: list<array{nom: string, explication: string, debut: string, fin: string, etat: string, pourcentage: float}>
     * }
     */
    public static function calculer(
        DateTimeInterface $debut,
        DateTimeInterface $fin,
        DateTimeInterface $maintenant,
        array $gabarit,
    ): array {
        // On travaille en « timestamps » (nombre de secondes depuis 1970) :
        // c'est le plus simple pour faire des soustractions.
        $t0 = $debut->getTimestamp();
        $t1 = $fin->getTimestamp();
        $t = $maintenant->getTimestamp();

        // Une réservation de durée nulle ou négative ne devrait pas exister,
        // mais on ne veut pas diviser par zéro : on la considère d'une seconde.
        $duree = max(1, $t1 - $t0);

        // clamp() « borne » la valeur entre 0 et 1 : avant le début → 0 %,
        // après la fin → 100 %. Le test « $t >= $t1 » d'abord : sans lui, une
        // réservation de durée nulle resterait à 0 % pour toujours.
        $ratio = $t >= $t1 ? 1.0 : self::clamp(($t - $t0) / $duree, 0.0, 1.0);

        $secondesRestantes = max(0, $t1 - $t);

        // ── On pose les étapes sur la frise ──
        // Liste vide (ne devrait pas arriver) → une seule étape « En cours ».
        if ($gabarit === []) {
            $gabarit = [['nom' => 'En cours', 'part' => 1, 'explication' => '']];
        }
        $totalParts = array_sum(array_column($gabarit, 'part')) ?: 1;

        $etapes = [];
        $etapeCourante = null;
        $curseur = $t0; // début de l'étape qu'on est en train de placer

        foreach ($gabarit as $index => $etape) {
            $dureeEtape = $duree * $etape['part'] / $totalParts;
            $debutEtape = $curseur;
            // La dernière étape finit exactement à la fin de la réservation
            // (sinon les arrondis peuvent laisser une seconde de trou).
            $finEtape = $index === array_key_last($gabarit) ? $t1 : (int)round($curseur + $dureeEtape);

            if ($t >= $finEtape) {
                $etat = 'faite';
                $avancementEtape = 1.0;
            } elseif ($t >= $debutEtape) {
                $etat = 'en_cours';
                $avancementEtape = self::clamp(($t - $debutEtape) / max(1, $finEtape - $debutEtape), 0.0, 1.0);
                $etapeCourante = $index;
            } else {
                $etat = 'a_venir';
                $avancementEtape = 0.0;
            }

            $etapes[] = [
                'nom' => $etape['nom'],
                'explication' => $etape['explication'],
                'debut' => self::iso($debutEtape),
                'fin' => self::iso($finEtape),
                'etat' => $etat,
                'pourcentage' => round($avancementEtape * 100, 1),
            ];

            $curseur = $finEtape;
        }

        return [
            // Arrondi à 0,1 % : assez précis pour une jauge.
            'pourcentage' => round($ratio * 100, 1),
            'secondesRestantes' => $secondesRestantes,
            // ceil = arrondi au-dessus : s'il reste 2 h, on affiche « 1 jour »
            // et pas « 0 jour » (qui laisserait croire que c'est fini).
            'joursRestants' => (int)ceil($secondesRestantes / 86400),
            'etapeCourante' => $etapeCourante,
            'etapes' => $etapes,
        ];
    }

    private static function clamp(float $valeur, float $min, float $max): float {
        return max($min, min($max, $valeur));
    }

    private static function iso(int $timestamp): string {
        return (new DateTimeImmutable('@' . $timestamp))->format(DateTimeInterface::ATOM);
    }
}
