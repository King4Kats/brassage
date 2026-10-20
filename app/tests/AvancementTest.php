<?php

declare(strict_types=1);

/**
 * Tests du calcul d'avancement (lib/Service/Avancement.php).
 *
 * Volontairement SANS PHPUnit ni Nextcloud : Avancement est une classe
 * « pure » (des dates en entrée, un tableau en sortie), donc un simple PHP
 * suffit. On lance :
 *
 *   ./scripts/tester.sh
 *   (ou, si PHP 8.2+ est installé sur la machine : php app/tests/AvancementTest.php)
 *
 * Chaque test affiche ✓ ou ✗ ; le script sort en erreur (code 1) si un test
 * échoue, ce qui permet de le brancher plus tard dans une intégration continue.
 */

require __DIR__ . '/../lib/Service/Gabarits.php';
require __DIR__ . '/../lib/Service/Avancement.php';

use OCA\Brassage\Service\Avancement;
use OCA\Brassage\Service\Gabarits;

$echecs = 0;

/** Vérifie qu'une valeur est celle attendue, et affiche le résultat. */
function verifier(string $description, mixed $obtenu, mixed $attendu): void {
    global $echecs;
    if ($obtenu === $attendu) {
        echo "  ✓ $description\n";
        return;
    }
    $echecs++;
    echo "  ✗ $description\n      attendu : " . var_export($attendu, true) . "\n      obtenu  : " . var_export($obtenu, true) . "\n";
}

$utc = new DateTimeZone('UTC');
$d = fn (string $quand) => new DateTimeImmutable($quand, $utc);

echo "Exemple de référence : 40 % et 60 jours restants\n";
$r = Avancement::calculer($d('2026-01-01'), $d('2026-04-11'), $d('2026-02-10'), Gabarits::etapes('garde')); // 100 jours, 40 écoulés
verifier('pourcentage', $r['pourcentage'], 40.0);
verifier('jours restants', $r['joursRestants'], 60);
verifier('étape en cours = Maturation (70 % de la durée)', $r['etapes'][$r['etapeCourante']]['nom'], 'Maturation');

echo "Avant le début\n";
$r = Avancement::calculer($d('2026-03-01'), $d('2026-03-29'), $d('2026-02-01'), Gabarits::etapes('fermenteur'));
verifier('0 %', $r['pourcentage'], 0.0);
verifier('aucune étape en cours', $r['etapeCourante'], null);
verifier('toutes les étapes à venir', array_unique(array_column($r['etapes'], 'etat')), ['a_venir']);

echo "Après la fin\n";
$r = Avancement::calculer($d('2026-03-01'), $d('2026-03-29'), $d('2026-05-01'), Gabarits::etapes('fermenteur'));
verifier('100 %', $r['pourcentage'], 100.0);
verifier('0 jour restant', $r['joursRestants'], 0);
verifier('toutes les étapes faites', array_unique(array_column($r['etapes'], 'etat')), ['faite']);

echo "Découpage des étapes\n";
$r = Avancement::calculer($d('2026-03-01 08:00'), $d('2026-03-01 18:00'), $d('2026-03-01 09:00'), Gabarits::etapes('alambic')); // 10 h
verifier('mise en chauffe = 15 % de 10 h = 1 h 30', $r['etapes'][0]['fin'], '2026-03-01T09:30:00+00:00');
verifier('la dernière étape finit pile à la fin de la réservation', end($r['etapes'])['fin'], '2026-03-01T18:00:00+00:00');
verifier('reste 1 jour (arrondi au-dessus) quand il reste 9 h', $r['joursRestants'], 1);

echo "Type inconnu → gabarit fermenteur, sans planter\n";
verifier('4 étapes', count(Gabarits::etapes('cocotte-minute')), 4);

echo "Étapes personnalisées (parts qui ne font pas 100)\n";
$perso = [['nom' => 'Chauffe', 'part' => 1, 'explication' => ''], ['nom' => 'Distillation', 'part' => 3, 'explication' => '']];
$r = Avancement::calculer($d('2026-03-01 08:00'), $d('2026-03-01 16:00'), $d('2026-03-01 11:00'), $perso); // 8 h
verifier('1 part sur 4 = 2 h → la chauffe finit à 10:00', $r['etapes'][0]['fin'], '2026-03-01T10:00:00+00:00');
verifier('à 11:00 on distille', $r['etapes'][$r['etapeCourante']]['nom'], 'Distillation');

echo "Liste d'étapes vide → une étape « En cours », sans planter\n";
$r = Avancement::calculer($d('2026-03-01'), $d('2026-03-02'), $d('2026-03-01 12:00'), []);
verifier('1 étape', count($r['etapes']), 1);

echo "Réservation de durée nulle → pas de division par zéro\n";
$r = Avancement::calculer($d('2026-03-01'), $d('2026-03-01'), $d('2026-03-01'), Gabarits::etapes('brassage'));
verifier('100 %', $r['pourcentage'], 100.0);

echo $echecs === 0 ? "\nTous les tests passent.\n" : "\n$echecs test(s) en échec.\n";
exit($echecs === 0 ? 0 : 1);
