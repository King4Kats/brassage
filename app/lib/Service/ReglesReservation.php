<?php

declare(strict_types=1);

namespace OCA\Brassage\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Vérifie qu'un créneau respecte les règles de réservation d'une machine,
 * telles qu'un·e admin les règle dans RoomVox :
 *   - les PLAGES D'OUVERTURE (« du lundi au vendredi, 8 h – 18 h ») ;
 *   - le DÉLAI MAXIMUM (« pas plus de 60 jours à l'avance »).
 *
 * Pourquoi ce fichier ? (voir docs/journal-de-bord.md, D13)
 * RoomVox vérifie ces règles quand on réserve depuis l'app Agenda, mais PAS
 * quand on passe par son API HTTP, ce que fait notre bouton « Réserver ».
 * Sa fonction de vérification est privée (SchedulingPlugin::isWithinAvailability) :
 * on ne peut pas l'appeler. On REPREND donc ses règles à l'identique ici, et
 * le front nous demande notre avis avant d'appeler RoomVox.
 *
 * Classe « pure » (pas de base, pas de RoomVox) : testée dans
 * tests/ReglesReservationTest.php.
 */
final class ReglesReservation {
    /**
     * @param array{enabled?: bool, rules?: list<array{days?: list<int>, startTime?: string, endTime?: string}>} $regles
     *        format RoomVox : days = jours autorisés (0 = dimanche … 6 = samedi),
     *        startTime / endTime = « HH:MM », heure LOCALE de l'instance
     * @param int $horizonJours délai maximum en jours (0 = pas de limite)
     * @param DateTimeZone $fuseau fuseau de l'instance : les heures des règles
     *        sont des heures « murales » (8 h à Paris), pas des heures UTC
     * @return string|null null si c'est bon, sinon la raison du refus (affichable)
     */
    public static function verifier(
        array $regles,
        int $horizonJours,
        DateTimeInterface $debut,
        DateTimeInterface $fin,
        DateTimeInterface $maintenant,
        DateTimeZone $fuseau,
    ): ?string {
        if ($fin <= $debut) {
            return 'La fin doit être après le début.';
        }

        // Délai maximum : comme RoomVox, on regarde la date de DÉBUT.
        if ($horizonJours > 0) {
            $limite = DateTimeImmutable::createFromInterface($maintenant)->modify("+{$horizonJours} days");
            if ($debut > $limite) {
                return sprintf('On ne peut pas réserver cette machine plus de %d jours à l\'avance.', $horizonJours);
            }
        }

        // Plages d'ouverture : désactivées ou vides → aucune restriction.
        if (empty($regles['enabled']) || empty($regles['rules'])) {
            return null;
        }
        // On convertit en heure locale AVANT de comparer « 08:00 » et « 18:00 ».
        $debutLocal = DateTimeImmutable::createFromInterface($debut)->setTimezone($fuseau);
        $finLocale = DateTimeImmutable::createFromInterface($fin)->setTimezone($fuseau);
        foreach ($regles['rules'] as $regle) {
            if (self::tientDansLaRegle($debutLocal, $finLocale, $regle)) {
                return null; // il suffit qu'UNE règle convienne
            }
        }
        return 'Ce créneau est en dehors des horaires d\'ouverture de la machine.';
    }

    /**
     * Même logique que RoomVox (SchedulingPlugin::bookingFitsRule) :
     * - sur une seule journée : le jour est autorisé et les heures tiennent
     *   dans la plage ;
     * - sur plusieurs jours : TOUS les jours traversés sont autorisés, le
     *   début n'est pas avant l'ouverture et la fin pas après la fermeture.
     *
     * @param array{days?: list<int>, startTime?: string, endTime?: string} $regle
     */
    private static function tientDansLaRegle(DateTimeImmutable $debut, DateTimeImmutable $fin, array $regle): bool {
        $jours = array_map('intval', $regle['days'] ?? []);
        $ouverture = $regle['startTime'] ?? '00:00';
        $fermeture = $regle['endTime'] ?? '23:59';
        if ($jours === []) {
            return false;
        }

        // Comparer « 08:30 » >= « 08:00 » en texte marche, parce que le
        // format est toujours HH:MM sur deux chiffres.
        if ($debut->format('H:i') < $ouverture || $fin->format('H:i') > $fermeture) {
            return false;
        }

        // Chaque jour du créneau doit être un jour autorisé.
        // format('w') : 0 = dimanche … 6 = samedi, comme RoomVox.
        for ($jour = $debut->setTime(0, 0); $jour->format('Y-m-d') <= $fin->format('Y-m-d'); $jour = $jour->modify('+1 day')) {
            if (!in_array((int)$jour->format('w'), $jours, true)) {
                return false;
            }
        }
        return true;
    }
}
