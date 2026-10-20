<?php

declare(strict_types=1);

namespace OCA\Brassage\Command;

use DateTime;
use DateTimeZone;
use OCA\Brassage\Db\Brassin;
use OCA\Brassage\Db\BrassinMapper;
use OCA\Brassage\Db\Equipement;
use OCA\Brassage\Db\EquipementMapper;
use OCA\Brassage\Db\EtapeMapper;
use OCA\Brassage\Db\JournalEntree;
use OCA\Brassage\Db\JournalEntreeMapper;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ brassage:demo --oui [--reinitialiser]
 *
 * Remplit Nextcloud avec un atelier de démonstration :
 * 4 personnes, 3 groupes, 6 équipements, des réservations passées / en
 * cours / à venir, leurs brassins et quelques mesures.
 *
 * Les dates sont calculées PAR RAPPORT À AUJOURD'HUI : quel que soit le jour
 * où on lance la démo, il y a toujours une cuve à 40 % avec 60 jours restants,
 * une brassée en cours, une cuve à nettoyer, etc.
 *
 * C'est une commande Symfony Console (la bibliothèque qu'utilise « occ ») :
 * configure() déclare le nom et les options, execute() fait le travail.
 */
class DemoSeed extends Command {
    /** Mot de passe de TOUS les comptes de démo (affiché dans le README). */
    public const MOT_DE_PASSE = 'brassage2026';

    /** Clé (dans la config de l'app) où on retient les salles créées par la démo. */
    private const CLE_SALLES_DEMO = 'demo_salles';

    private const GROUPE_RESPONSABLES = 'brassage-responsables';
    private const GROUPE_BRASSEURS = 'brassage-brasseurs';
    private const GROUPE_MEMBRES = 'brassage-membres';

    public function __construct(
        private RoomVoxPasserelle $roomVox,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
        private EquipementMapper $equipements,
        private BrassinMapper $brassins,
        private JournalEntreeMapper $journal,
        private EtapeMapper $etapes,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('brassage:demo')
            ->setDescription('Crée un atelier de démonstration (personnes, cuves, réservations, brassins)')
            ->addOption('reinitialiser', null, InputOption::VALUE_NONE, 'Supprime d\'abord l\'atelier de démo existant')
            // Garde-fou : cette commande crée des COMPTES au mot de passe connu de
            // tous. Lancée par erreur sur un vrai serveur, ce serait une faille.
            // On exige donc une confirmation explicite.
            ->addOption('oui', null, InputOption::VALUE_NONE, 'Confirme qu\'il s\'agit bien d\'une instance de DÉMO');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (!$input->getOption('oui')) {
            $output->writeln([
                '<error>Cette commande crée des comptes de démo (claire, julien, sofia, marc)</error>',
                '<error>avec le mot de passe « ' . self::MOT_DE_PASSE . ' », connu de tous.</error>',
                'À ne JAMAIS lancer sur un vrai serveur. Sur une démo, relancer avec --oui.',
            ]);
            return self::FAILURE;
        }
        if (!$this->roomVox->disponible()) {
            $output->writeln('<error>RoomVox doit être activé : occ app:enable roomvox</error>');
            return self::FAILURE;
        }

        $dejaCreees = json_decode($this->appConfig->getValueString('brassage', self::CLE_SALLES_DEMO, '[]'), true) ?: [];
        if ($dejaCreees !== []) {
            if (!$input->getOption('reinitialiser')) {
                $output->writeln('La démo existe déjà. Relancer avec --reinitialiser pour la recréer.');
                return self::SUCCESS;
            }
            $this->supprimerDemo($dejaCreees, $output);
        }

        // ── 1. Personnes et groupes ──
        // Les rôles RoomVox sont donnés à des groupes, pas à des personnes :
        // c'est plus simple à gérer (on ajoute quelqu'un au groupe, c'est tout).
        $this->groupe(self::GROUPE_RESPONSABLES, 'Brassage — responsables');
        $this->groupe(self::GROUPE_BRASSEURS, 'Brassage — brasseurs');
        $this->groupe(self::GROUPE_MEMBRES, 'Brassage — membres');

        $this->personne('claire', 'Claire (responsable)', [self::GROUPE_RESPONSABLES, self::GROUPE_BRASSEURS]);
        $this->personne('julien', 'Julien', [self::GROUPE_BRASSEURS]);
        $this->personne('sofia', 'Sofia', [self::GROUPE_BRASSEURS]);
        $this->personne('marc', 'Marc (membre)', [self::GROUPE_MEMBRES]);
        $output->writeln('Personnes : claire, julien, sofia, marc (mot de passe : ' . self::MOT_DE_PASSE . ')');

        // ── 2. Le scénario ──
        // ⚠ QUESTION OUVERTE (docs/journal-de-bord.md) : on ne sait pas encore
        // quelles machines l'asso possède. L'alambic (illustration de référence)
        // est en tête ; les autres types sont là en exemples. Adapter cette
        // liste quand on aura la réponse : c'est le seul endroit à toucher.
        //
        // Chaque équipement : son type, sa contenance, et ses réservations
        // décrites en « jours par rapport à aujourd'hui » (-12 = il y a 12 jours).
        $scenario = [
            [
                'nom' => 'Alambic', 'type' => 'alambic', 'litres' => 50, 'validationAuto' => false,
                'description' => 'Alambic cuivre 50 L à colonne.',
                'reservations' => [
                    // Distillation en cours : commencée il y a 2 h, encore 6 h.
                    ['debut' => '-2 hours', 'fin' => '+6 hours', 'qui' => 'sofia', 'confirmee' => true,
                        'brassin' => ['nom' => 'Eau-de-vie de bière', 'style' => 'Distillat', 'couleur' => 'distillat', 'volume' => 40],
                        'journal' => [['-2 hours', 'temperature', 78.0, 'Mise en chauffe'], ['-30 minutes', 'note', null, 'Têtes écartées (≈ 1 L)']]],
                    ['debut' => '+10 days 08:00', 'fin' => '+10 days 18:00', 'qui' => 'julien', 'confirmee' => true,
                        'brassin' => ['nom' => 'Eau-de-vie de malt', 'style' => 'Distillat', 'couleur' => 'distillat', 'volume' => 40]],
                ],
            ],
            [
                'nom' => 'Cuve de brassage', 'type' => 'brassage', 'litres' => 100, 'validationAuto' => false,
                'description' => 'Poste de brassage 100 L (empâtage + ébullition). Réservation à la journée, validée par un·e responsable.',
                'reservations' => [
                    // Brassée en cours : commencée il y a 3 h, encore 5 h.
                    ['debut' => '-3 hours', 'fin' => '+5 hours', 'qui' => 'julien', 'confirmee' => true,
                        'brassin' => ['nom' => 'IPA du Marais', 'style' => 'IPA', 'couleur' => 'blonde', 'volume' => 90],
                        'journal' => [['-2 hours', 'temperature', 66.5, 'Palier d\'empâtage'], ['-1 hours', 'note', null, 'Filtration un peu lente, rinçage OK']]],
                    // Demande en attente de validation.
                    ['debut' => '+5 days 09:00', 'fin' => '+5 days 17:00', 'qui' => 'sofia', 'confirmee' => false, 'brassin' => null],
                ],
            ],
            [
                'nom' => 'Fermenteur 1', 'type' => 'fermenteur', 'litres' => 120, 'validationAuto' => true,
                'description' => 'Fermenteur cylindro-conique inox 120 L.',
                'reservations' => [
                    ['debut' => '-12 days', 'fin' => '+16 days', 'qui' => 'sofia', 'confirmee' => true,
                        'brassin' => ['nom' => 'Blanche d\'automne', 'style' => 'Witbier', 'couleur' => 'blanche', 'volume' => 100],
                        'journal' => [
                            ['-12 days', 'densite', 1.048, 'Densité initiale'],
                            ['-12 days', 'temperature', 19.0, null],
                            ['-8 days', 'densite', 1.024, null],
                            ['-4 days', 'densite', 1.014, null],
                            ['-1 days', 'densite', 1.011, 'Ça ralentit, normal'],
                        ]],
                ],
            ],
            [
                'nom' => 'Fermenteur 2', 'type' => 'fermenteur', 'litres' => 120, 'validationAuto' => true,
                'description' => 'Fermenteur cylindro-conique inox 120 L.',
                'reservations' => [
                    ['debut' => '-25 days', 'fin' => '+3 days', 'qui' => 'julien', 'confirmee' => true,
                        'brassin' => ['nom' => 'Ambrée de la Vie', 'style' => 'Ale ambrée', 'couleur' => 'ambree', 'volume' => 110],
                        'journal' => [['-25 days', 'densite', 1.056, null], ['-15 days', 'densite', 1.014, null], ['-2 days', 'temperature', 4.0, 'Passage au froid']]],
                ],
            ],
            [
                // Libre mais à nettoyer : réservation finie il y a 2 jours.
                'nom' => 'Fermenteur 3', 'type' => 'fermenteur', 'litres' => 60, 'validationAuto' => true,
                'description' => 'Petit fermenteur 60 L pour les essais.',
                'reservations' => [
                    ['debut' => '-30 days', 'fin' => '-2 days', 'qui' => 'sofia', 'confirmee' => true,
                        'brassin' => ['nom' => 'Essai saison', 'style' => 'Saison', 'couleur' => 'blonde', 'volume' => 50]],
                ],
            ],
            [
                // Exemple de référence du cahier des charges : 40 % et 60 jours restants.
                'nom' => 'Cuve de garde', 'type' => 'garde', 'litres' => 200, 'validationAuto' => false,
                'description' => 'Cuve de garde réfrigérée 200 L.',
                'reservations' => [
                    ['debut' => '-40 days', 'fin' => '+60 days', 'qui' => 'claire', 'confirmee' => true,
                        'brassin' => ['nom' => 'Brune de Noël', 'style' => 'Brune épicée', 'couleur' => 'brune', 'volume' => 180],
                        'journal' => [['-40 days', 'temperature', 2.0, 'Mise en garde'], ['-10 days', 'note', null, 'Dégustation : encore jeune, ça s\'arrondit']]],
                ],
            ],
        ];

        // ── 3. Création ──
        $sallesCreees = [];
        foreach ($scenario as $item) {
            $roomId = $this->roomVox->creerSalle($item['nom'], $item['description'], $item['litres'], $item['validationAuto']);
            $sallesCreees[] = $roomId;

            // Droits : les membres voient, les brasseurs réservent, les responsables gèrent.
            $this->roomVox->definirDroitsGroupes($roomId, [self::GROUPE_MEMBRES], [self::GROUPE_BRASSEURS], [self::GROUPE_RESPONSABLES]);

            // Nos infos à nous sur l'équipement.
            $equipement = new Equipement();
            $equipement->setRoomId($roomId);
            $equipement->setType($item['type']);
            $equipement->setCapaciteL($item['litres']);
            // Nettoyé il y a 31 jours : avant la dernière réservation du
            // Fermenteur 3 (finie il y a 2 jours) → il sera « à nettoyer ».
            $equipement->setNettoyeLe($this->date('-31 days'));
            $equipement->setNettoyePar('claire');
            $this->equipements->insert($equipement);

            foreach ($item['reservations'] as $resa) {
                $uid = $this->roomVox->creerReservation(
                    $roomId,
                    $resa['brassin']['nom'] ?? 'Brassin à définir',
                    $this->date($resa['debut']),
                    $this->date($resa['fin']),
                    $resa['qui'],
                    $resa['confirmee'],
                );
                if ($resa['brassin'] !== null) {
                    $this->creerBrassin($roomId, $uid, $resa['qui'], $resa['brassin'], $resa['journal'] ?? []);
                }
            }
            $output->writeln("  ✓ {$item['nom']} ($roomId)");
        }

        $this->appConfig->setValueString('brassage', self::CLE_SALLES_DEMO, json_encode($sallesCreees));
        $output->writeln('<info>Atelier de démo prêt.</info>');
        return self::SUCCESS;
    }

    /**
     * @param array{nom: string, style: string, couleur: string, volume: int} $donnees
     * @param list<array{0: string, 1: string, 2: float|null, 3: string|null}> $journal
     */
    private function creerBrassin(string $roomId, string $uid, string $qui, array $donnees, array $journal): void {
        $brassin = new Brassin();
        $brassin->setRoomId($roomId);
        $brassin->setBookingUid($uid);
        $brassin->setNom($donnees['nom']);
        $brassin->setStyle($donnees['style']);
        $brassin->setCouleur($donnees['couleur']);
        $brassin->setVolumeL($donnees['volume']);
        $brassin->setUserId($qui);
        $brassin->setCreeLe(new DateTime());
        $brassin->setModifieLe(new DateTime());
        $brassin = $this->brassins->insert($brassin);

        foreach ($journal as [$quand, $type, $valeur, $texte]) {
            $entree = new JournalEntree();
            $entree->setBrassinId($brassin->getId());
            $entree->setType($type);
            $entree->setValeur($valeur);
            $entree->setTexte($texte);
            $entree->setUserId($qui);
            $entree->setCreeLe($this->date($quand));
            $this->journal->insert($entree);
        }
    }

    /**
     * Supprime les salles de la démo précédente et tout ce qu'on y avait accroché.
     *
     * @param string[] $roomIds
     */
    private function supprimerDemo(array $roomIds, OutputInterface $output): void {
        foreach ($this->brassins->findParSalles($roomIds) as $brassin) {
            foreach ($this->journal->findParBrassin($brassin->getId()) as $entree) {
                $this->journal->delete($entree);
            }
            $this->brassins->delete($brassin);
        }
        foreach ($roomIds as $roomId) {
            $equipement = $this->equipements->findParRoomId($roomId);
            if ($equipement !== null) {
                $this->equipements->delete($equipement);
            }
            $this->etapes->supprimerParSalle($roomId);
            $this->roomVox->supprimerSalle($roomId);
        }
        $this->appConfig->deleteKey('brassage', self::CLE_SALLES_DEMO);
        $output->writeln('Ancienne démo supprimée.');
    }

    private function groupe(string $id, string $nom): void {
        $groupe = $this->groupManager->get($id) ?? $this->groupManager->createGroup($id);
        $groupe?->setDisplayName($nom);
    }

    /** @param string[] $groupes */
    private function personne(string $uid, string $nom, array $groupes): void {
        $user = $this->userManager->get($uid) ?? $this->userManager->createUser($uid, self::MOT_DE_PASSE);
        if ($user === false || $user === null) {
            throw new \RuntimeException("Impossible de créer le compte $uid");
        }
        $user->setDisplayName($nom);
        // L'email est indispensable : c'est par lui que le calendrier (et donc
        // RoomVox) reconnaît l'organisateur d'une réservation.
        $user->setSystemEMailAddress("$uid@brassage.test");
        foreach ($groupes as $g) {
            $this->groupManager->get($g)?->addUser($user);
        }
    }

    /**
     * « -12 days », « +5 days 09:00 »… → une date, dans le fuseau de Paris.
     * On arrondit à la minute pour avoir des heures propres dans le calendrier.
     */
    private function date(string $relatif): DateTime {
        $date = new DateTime($relatif, new DateTimeZone('Europe/Paris'));
        $date->setTime((int)$date->format('H'), (int)$date->format('i'), 0);
        return $date;
    }
}
