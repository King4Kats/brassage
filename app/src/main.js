/**
 * Point d'entrée du front : c'est le fichier que Vite compile en
 * js/brassage-main.js.
 *
 * Le déroulé :
 * 1. on trouve #brassage-app (dans templates/main.php) ;
 * 2. on charge l'atelier depuis l'API ;
 * 3. on dessine une carte par équipement (carte.js) ;
 * 4. toutes les 30 s, les cartes recalculent leur avancement (sans requête) ;
 *    toutes les 5 min (et quand on revient sur l'onglet), on recharge les données.
 *
 * Pas de framework (Vue, React…) : l'app est petite, du JavaScript « simple »
 * est plus facile à lire pour tout le monde. Voir docs/journal-de-bord.md.
 */
import { chargerAtelier } from './api.js'
import { animerEntree } from './animations.js'
import { creerCarte, statutDe } from './carte.js'
import { echapper, heure } from './format.js'
import { creerPanneau } from './panneau.js'
import { synchroniser } from './temps.js'

const TIC_MS = 30 * 1000
const RECHARGEMENT_MS = 5 * 60 * 1000

function demarrer() {
	const racine = document.getElementById('brassage-app')
	if (!racine) return

	racine.innerHTML = `
		<header class="brassage-entete">
			<div>
				<h2 class="brassage-titre">L'atelier</h2>
				<p class="brassage-discret brassage-maj" aria-live="polite"></p>
			</div>
			<ul class="brassage-resume-atelier" aria-label="Résumé"></ul>
		</header>
		<div class="brassage-grille"></div>
		<div class="brassage-toasts" aria-live="assertive"></div>
	`
	const grille = racine.querySelector('.brassage-grille')

	/** Les cartes affichées, par roomId. */
	const cartes = new Map()

	const panneau = creerPanneau(racine, { surChangement: recharger, notifier })

	let derniersEquipements = []
	const ouvrir = (roomId) => {
		const equipement = derniersEquipements.find((e) => e.roomId === roomId)
		if (equipement) panneau.ouvrir(equipement, cartes.get(roomId)?.element)
	}

	async function recharger() {
		let donnees
		try {
			donnees = await chargerAtelier()
		} catch (err) {
			// Au premier chargement, on affiche l'erreur à la place de la grille ;
			// ensuite, une simple notification (on garde l'affichage précédent).
			if (cartes.size === 0) grille.innerHTML = `<p class="brassage-erreur">${echapper(err.message)}</p>`
			else notifier(err.message, true)
			return
		}

		synchroniser(donnees.maintenant)
		derniersEquipements = donnees.equipements
		const premiereFois = cartes.size === 0

		if (donnees.equipements.length === 0) {
			grille.innerHTML = `<p class="brassage-vide">Aucun équipement visible.<br>
				<span class="brassage-discret">Un·e admin crée les équipements dans RoomVox (une « salle » par machine)
				et vous donne au moins le droit de les voir.</span></p>`
		}

		// Mise à jour « intelligente » : on garde les cartes existantes (et leurs
		// animations), on ajoute les nouvelles, on retire celles qui ont disparu.
		const vus = new Set()
		for (const equipement of donnees.equipements) {
			vus.add(equipement.roomId)
			const existante = cartes.get(equipement.roomId)
			if (existante) {
				existante.mettreAJour(equipement)
			} else {
				const carte = creerCarte(equipement, ouvrir)
				cartes.set(equipement.roomId, carte)
				grille.appendChild(carte.element)
			}
		}
		for (const [roomId, carte] of cartes) {
			if (!vus.has(roomId)) {
				carte.detruire()
				cartes.delete(roomId)
			}
		}

		if (premiereFois) animerEntree([...cartes.values()].map((c) => c.element))
		afficherResume(racine, donnees.equipements)
		racine.querySelector('.brassage-maj').textContent = `Mis à jour à ${heure(donnees.maintenant)}`
		panneau.rafraichir(donnees.equipements)
	}

	function notifier(message, erreur = false) {
		const toast = document.createElement('div')
		toast.className = `brassage-toast ${erreur ? 'brassage-toast--erreur' : ''}`
		toast.textContent = message
		racine.querySelector('.brassage-toasts').appendChild(toast)
		setTimeout(() => toast.remove(), erreur ? 7000 : 3500)
	}

	recharger()
	setInterval(() => {
		cartes.forEach((c) => c.tic())
		panneau.tic()
	}, TIC_MS)
	setInterval(recharger, RECHARGEMENT_MS)
	// Quand on revient sur l'onglet après un moment, les données ont pu changer.
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') recharger()
	})
}

/** Les petits compteurs en haut : « 3 en cours · 1 à valider · 2 libres… ». */
function afficherResume(racine, equipements) {
	const compte = { 'en-cours': 0, attente: 0, 'a-nettoyer': 0, libre: 0 }
	equipements.forEach((e) => compte[statutDe(e)]++)
	const libelles = { 'en-cours': 'en cours', attente: 'en attente', 'a-nettoyer': 'à nettoyer', libre: 'libre' }
	racine.querySelector('.brassage-resume-atelier').innerHTML = Object.entries(compte)
		.filter(([, n]) => n > 0)
		.map(([statut, n]) => `<li class="brassage-pastille brassage-pastille--${statut}">${n} ${libelles[statut]}${n > 1 && statut === 'libre' ? 's' : ''}</li>`)
		.join('')
}

// Le script peut être chargé avant que la page soit prête : on attend.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demarrer)
else demarrer()
