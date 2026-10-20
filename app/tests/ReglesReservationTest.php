<?php

declare(strict_types=1);

/**
 * Tests des règles de réservation (lib/Service/ReglesReservation.php).
 * Même principe que AvancementTest.php : du PHP simple, sans PHPUnit.
 */

require __DIR__ . '/../lib/Service/ReglesReservation.php';

use OCA\Brassage\Service\ReglesReservation;

$echecs = 0;

function verifier(string $description, mixed $obtenu, mixed $attendu): void {
    global $echecs;
    if ($obtenu === $attendu) {
        echo "  ✓ $description\n";
        return;
    }
    $echecs++;
    echo "  ✗ $description\n      attendu : " . var_export($attendu, true) . "\n      obtenu  : " . var_export($obtenu, true) . "\n";
}

$paris = new DateTimeZone('Europe/Paris');
$d = fn (string $quand) => new DateTimeImmutable($quand, $paris);
$maintenant = $d('2026-10-19 12:00'); // un lundi
// Ouvert du lundi (1) au vendredi (5), de 8 h à 18 h.
$semaine = ['enabled' => true, 'rules' => [['days' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '18:00']]];
$ok = fn ($regles, $horizon, $debut, $fin) => ReglesReservation::verifier($regles, $horizon, $d($debut), $d($fin), $maintenant, $paris) === null;

echo "Sans règle\n";
verifier('tout est permis', $ok([], 0, '2026-10-25 02:00', '2026-10-25 23:00'), true);
verifier('règles désactivées = aucune restriction', $ok(['enabled' => false, 'rules' => $semaine['rules']], 0, '2026-10-25 02:00', '2026-10-25 03:00'), true);

echo "Plages d'ouverture\n";
verifier('mardi 9 h – 17 h : accepté', $ok($semaine, 0, '2026-10-20 09:00', '2026-10-20 17:00'), true);
verifier('pile aux bornes 8 h – 18 h : accepté', $ok($semaine, 0, '2026-10-20 08:00', '2026-10-20 18:00'), true);
verifier('mardi 7 h – 10 h : refusé (trop tôt)', $ok($semaine, 0, '2026-10-20 07:00', '2026-10-20 10:00'), false);
verifier('samedi : refusé', $ok($semaine, 0, '2026-10-24 09:00', '2026-10-24 12:00'), false);
verifier('lundi → mercredi (jours ouvrés) : accepté', $ok($semaine, 0, '2026-10-19 13:00', '2026-10-21 17:00'), true);
verifier('vendredi → lundi (traverse le week-end) : refusé', $ok($semaine, 0, '2026-10-23 09:00', '2026-10-26 17:00'), false);
verifier('heure UTC convertie : 07:00 UTC = 09:00 Paris (été) → accepté',
    ReglesReservation::verifier($semaine, 0, new DateTimeImmutable('2026-10-20 07:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-20 09:00', new DateTimeZone('UTC')), $maintenant, $paris), null);
$deuxPlages = ['enabled' => true, 'rules' => [$semaine['rules'][0], ['days' => [6], 'startTime' => '10:00', 'endTime' => '12:00']]];
verifier('samedi 10 h – 12 h accepté grâce à une 2e règle', $ok($deuxPlages, 0, '2026-10-24 10:00', '2026-10-24 12:00'), true);

echo "Délai maximum\n";
verifier('dans 30 jours avec un délai de 60 : accepté', $ok([], 60, '2026-11-18 09:00', '2026-11-18 10:00'), true);
verifier('dans 90 jours avec un délai de 60 : refusé', $ok([], 60, '2027-01-17 09:00', '2027-01-17 10:00'), false);
verifier('délai 0 = illimité', $ok([], 0, '2030-01-01 09:00', '2030-01-01 10:00'), true);

echo "Créneau incohérent\n";
verifier('fin avant début : refusé', $ok([], 0, '2026-10-20 10:00', '2026-10-20 09:00'), false);

echo $echecs === 0 ? "\nTous les tests passent.\n" : "\n$echecs test(s) en échec.\n";
exit($echecs === 0 ? 0 : 1);
