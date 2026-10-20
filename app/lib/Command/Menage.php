<?php

declare(strict_types=1);

namespace OCA\Brassage\Command;

use OCA\Brassage\Db\BrassinMapper;
use OCA\Brassage\Db\EquipementMapper;
use OCA\Brassage\Db\EtapeMapper;
use OCA\Brassage\Db\JournalEntreeMapper;
use OCA\Brassage\Service\RoomVoxPasserelle;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ brassage:menage [--simulation] [--reservations-annulees]
 *
 * Quand un admin supprime une machine dans RoomVox, RoomVox ne nous prévient
 * pas (il n'émet pas d'événement). Nos lignes (réglages, étapes, brassins,
 * journal) restent donc en base, invisibles mais inutiles. Cette commande les retire.
 *
 * --simulation : affiche ce qui serait supprimé, sans rien supprimer.
 *
 * --reservations-annulees : supprime AUSSI le contenu (brassin + journal) des
 *   réservations qui n'existent plus dans le calendrier de leur machine
 *   (annulées ou supprimées). Option à part, parce qu'elle efface de
 *   l'historique : on regarde les réservations sur ±5 ans autour d'aujourd'hui,
 *   un brassin dont la réservation est plus ancienne serait considéré comme
 *   orphelin. Toujours lancer d'abord avec --simulation.
 */
class Menage extends Command {
    public function __construct(
        private RoomVoxPasserelle $roomVox,
        private EquipementMapper $equipements,
        private BrassinMapper $brassins,
        private JournalEntreeMapper $journal,
        private EtapeMapper $etapes,
        private IDBConnection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('brassage:menage')
            ->setDescription('Supprime les données Brassage des machines qui n\'existent plus dans RoomVox')
            ->addOption('simulation', null, InputOption::VALUE_NONE, 'Affiche sans supprimer')
            ->addOption('reservations-annulees', null, InputOption::VALUE_NONE, 'Supprime aussi le contenu des réservations annulées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (!$this->roomVox->disponible()) {
            $output->writeln('<error>RoomVox doit être activé.</error>');
            return self::FAILURE;
        }
        $simulation = (bool)$input->getOption('simulation');

        // ⚠ salles() ne renvoie que les salles ACTIVES : une salle seulement
        // désactivée dans RoomVox n'est pas supprimée, on ne touche pas à ses
        // données. On compare donc avec TOUTES les salles connues.
        $existantes = $this->roomVox->identifiantsDeToutesLesSalles();

        // Les room_id présents chez nous, toutes tables confondues.
        $chezNous = [];
        foreach (['brassage_equipements', 'brassage_brassins', 'brassage_etapes'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('room_id')->from($table);
            $chezNous = array_merge($chezNous, $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
        }
        $chezNous = array_unique($chezNous);

        $orphelins = array_values(array_diff($chezNous, $existantes));
        if ($input->getOption('reservations-annulees')) {
            $this->menageReservations(array_values(array_intersect($chezNous, $existantes)), $simulation, $output);
        }
        if ($orphelins === []) {
            $output->writeln('Aucune machine supprimée à nettoyer.');
            return self::SUCCESS;
        }

        foreach ($orphelins as $roomId) {
            $brassins = $this->brassins->findParSalles([$roomId]);
            $output->writeln(sprintf('%s %s : %d brassin(s)', $simulation ? '[simulation]' : 'Suppression', $roomId, count($brassins)));
            if ($simulation) {
                continue;
            }
            foreach ($brassins as $brassin) {
                foreach ($this->journal->findParBrassin($brassin->getId()) as $entree) {
                    $this->journal->delete($entree);
                }
                $this->brassins->delete($brassin);
            }
            $equipement = $this->equipements->findParRoomId($roomId);
            if ($equipement !== null) {
                $this->equipements->delete($equipement);
            }
            $this->etapes->supprimerParSalle($roomId);
        }
        return self::SUCCESS;
    }

    /**
     * Brassins dont la réservation a disparu du calendrier de la machine.
     *
     * @param string[] $roomIds machines qui existent encore
     */
    private function menageReservations(array $roomIds, bool $simulation, OutputInterface $output): void {
        $maintenant = new \DateTimeImmutable();
        $fenetre = new \DateInterval('P5Y');
        $total = 0;
        foreach ($roomIds as $roomId) {
            $salle = $this->roomVox->salle($roomId);
            if ($salle === null) {
                continue; // machine désactivée : on n'y touche pas
            }
            // Les identifiants de toutes les réservations encore présentes.
            $presentes = array_column($this->roomVox->reservations($salle['compte'], $maintenant->sub($fenetre), $maintenant->add($fenetre)), 'id');
            foreach ($this->brassins->findParSalles([$roomId]) as $brassin) {
                if (in_array($brassin->getBookingUid(), $presentes, true)) {
                    continue;
                }
                $total++;
                $output->writeln(sprintf('%s brassin « %s » (%s) : réservation introuvable', $simulation ? '[simulation]' : 'Suppression', $brassin->getNom(), $roomId));
                if ($simulation) {
                    continue;
                }
                foreach ($this->journal->findParBrassin($brassin->getId()) as $entree) {
                    $this->journal->delete($entree);
                }
                $this->brassins->delete($brassin);
            }
        }
        $output->writeln($total === 0 ? 'Aucun contenu de réservation annulée.' : "$total contenu(s) de réservations annulées.");
    }
}
