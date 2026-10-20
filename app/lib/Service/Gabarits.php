<?php

declare(strict_types=1);

namespace OCA\Brassage\Service;

/**
 * Les « gabarits » d'étapes : pour chaque type d'équipement, la liste des
 * étapes qui s'y déroulent, et la PART de la durée totale que prend chacune.
 *
 * Pourquoi des parts et pas des durées fixes ? Parce que c'est la réservation
 * RoomVox qui fixe la durée réelle. Si un fermenteur est réservé 28 jours,
 * « Fermentation principale » (part 25) dure 25 % de 28 jours = 7 jours.
 * S'il est réservé 60 jours, elle dure 15 jours. Pas besoin de tout ressaisir.
 *
 * Les parts n'ont pas besoin de faire 100 : on divise par leur somme. On les
 * garde en « pourcentages » pour que ce soit lisible.
 *
 * Les textes « explication » sont pour les membres qui n'y connaissent rien :
 * ils sont affichés dans le panneau de détail de la cuve.
 *
 * ⚠ Ce sont des valeurs de DÉMO, à faire valider par les brasseurs de l'asso.
 */
final class Gabarits {
    /** @var array<string, array{libelle: string, etapes: list<array{nom: string, part: int, explication: string}>}> */
    public const PAR_TYPE = [
        'brassage' => [
            'libelle' => 'Cuve de brassage',
            'etapes' => [
                ['nom' => 'Empâtage', 'part' => 30, 'explication' => 'Le malt concassé trempe dans l\'eau chaude (≈ 65 °C) : l\'amidon se transforme en sucres.'],
                ['nom' => 'Filtration & rinçage', 'part' => 20, 'explication' => 'On sépare le jus sucré (le « moût ») des drêches, puis on rince les grains pour récupérer le sucre restant.'],
                ['nom' => 'Ébullition', 'part' => 20, 'explication' => 'Le moût bout environ une heure ; on y ajoute le houblon (amertume et arômes).'],
                ['nom' => 'Refroidissement', 'part' => 10, 'explication' => 'On refroidit vite le moût à ≈ 20 °C pour pouvoir y mettre les levures.'],
                ['nom' => 'Transfert & nettoyage', 'part' => 20, 'explication' => 'Le moût part dans un fermenteur, puis on nettoie la cuve.'],
            ],
        ],
        'fermenteur' => [
            'libelle' => 'Fermenteur',
            'etapes' => [
                ['nom' => 'Fermentation principale', 'part' => 25, 'explication' => 'Les levures mangent le sucre et produisent alcool et gaz carbonique : ça bulle beaucoup.'],
                ['nom' => 'Fermentation secondaire', 'part' => 35, 'explication' => 'La fermentation ralentit, les levures « nettoient » les arômes indésirables.'],
                ['nom' => 'Garde à froid', 'part' => 30, 'explication' => 'On refroidit pour que la bière s\'éclaircisse : les levures tombent au fond.'],
                ['nom' => 'Soutirage', 'part' => 10, 'explication' => 'On transfère la bière claire vers la garde ou l\'embouteillage, sans le dépôt.'],
            ],
        ],
        'garde' => [
            'libelle' => 'Cuve de garde',
            'etapes' => [
                ['nom' => 'Maturation', 'part' => 70, 'explication' => 'La bière repose au frais et ses arômes s\'arrondissent.'],
                ['nom' => 'Clarification', 'part' => 20, 'explication' => 'Les dernières particules se déposent, la bière devient limpide.'],
                ['nom' => 'Conditionnement', 'part' => 10, 'explication' => 'Mise en bouteilles ou en fûts.'],
            ],
        ],
        'alambic' => [
            'libelle' => 'Alambic',
            'etapes' => [
                ['nom' => 'Mise en chauffe', 'part' => 15, 'explication' => 'On chauffe doucement la bière (ou le moût fermenté) dans la cuve de l\'alambic.'],
                ['nom' => 'Têtes', 'part' => 10, 'explication' => 'Les premières vapeurs condensées sont écartées : elles contiennent des alcools indésirables.'],
                ['nom' => 'Cœur de chauffe', 'part' => 55, 'explication' => 'La partie qu\'on garde : l\'eau-de-vie.'],
                ['nom' => 'Queues', 'part' => 10, 'explication' => 'La fin de distillation, moins bonne, est mise de côté.'],
                ['nom' => 'Nettoyage', 'part' => 10, 'explication' => 'On vide et on nettoie l\'alambic.'],
            ],
        ],
    ];

    /**
     * Les étapes d'un type. Type inconnu → on retombe sur le fermenteur
     * plutôt que de planter.
     *
     * @return list<array{nom: string, part: int, explication: string}>
     */
    public static function etapes(string $type): array {
        return (self::PAR_TYPE[$type] ?? self::PAR_TYPE['fermenteur'])['etapes'];
    }

    public static function libelle(string $type): string {
        return (self::PAR_TYPE[$type] ?? self::PAR_TYPE['fermenteur'])['libelle'];
    }
}
