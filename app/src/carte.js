/**
 * Une carte de l'atelier = un équipement : son dessin animé, son avancement,
 * ce qu'il contient, et qui l'utilise.
 *
 * creerCarte() renvoie un objet avec :
 * - element      : l'élément HTML à insérer dans la page ;
 * - mettreAJour  : à appeler avec les nouvelles données (rafraîchissement) ;
 * - tic          : recalcule l'avancement « en direct » (appelé chaque minute) ;
 * - detruire     : arrête les animations avant de retirer la carte.
 */
import { generateUrl } from '@nextcloud/router'
import { animerArc, animerCompteur, animerDessin } from './animations.js'
import { couleurDe } from './couleurs.js'
import { creerDessin } from './dessins.js'
import { date, dateHeure, duree, echapper } from './format.js'
import { avancementEnDirect } from './temps.js'

/** Longueur du cercle de l'arc de progression (2 × π × rayon 27). */
const LONGUEUR_ARC = 2 * Math.PI * 27

/** Statut affiché sur la pastille, et sa couleur (classe CSS). */
const STATUTS = {
	'en-cours': 'En cours',
	attente: 'En attente de validation',
	'a-nettoyer': 'À nettoyer',
	libre: 'Libre',
}

/** Le statut d'un équipement, déduit des données de l'API. */
export function statutDe(equipement) {
	if (equipement.enCours) return equipement.enCours.statut === 'en_attente' ? 'attente' : 'en-cours'
	if (equipement.nettoyage.statut === 'a_nettoyer') return 'a-nettoyer'
	return 'libre'
}

/** Remplissage de la cuve (0 à 1) : volume du brassin / contenance. */
function niveauDe(equipement) {
	const resa = equipement.enCours
	if (!resa) return 0
	const volume = resa.brassin?.volumeL
	const capacite = equipement.capaciteL
	// Sans info, on dessine une cuve « bien remplie » plutôt que vide.
	if (!volume || !capacite) return 0.7
	return Math.min(1, volume / capacite)
}

/** URL de l'avatar Nextcloud d'une personne. */
export const avatar = (uid, taille = 64) => generateUrl('/avatar/{uid}/{taille}', { uid, taille })

/**
 * @param {object} equipement un élément du tableau « equipements » de l'API
 * @param {(roomId: string) => void} ouvrir appelé quand on clique sur la carte
 */
export function creerCarte(equipement, ouvrir) {
	const element = document.createElement('article')
	element.className = 'brassage-carte'
	// tabindex + role : la carte est « cliquable » au clavier aussi (Entrée / Espace).
	element.tabIndex = 0
	element.setAttribute('role', 'button')
	element.addEventListener('click', () => ouvrir(equipement.roomId))
	element.addEventListener('keydown', (e) => {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault()
			ouvrir(equipement.roomId)
		}
	})

	let donnees = equipement
	let dessin = null
	let animation = null
	let typeDessine = null
	let couleurDessinee = null
	// Dernier pourcentage affiché : au rafraîchissement, le compteur repart de
	// là (et pas de 0), sinon on reverrait l'animation de départ à chaque fois.
	let dernierRatio = 0

	function rendre() {
		const statut = statutDe(donnees)
		const resa = donnees.enCours
		element.dataset.statut = statut
		element.setAttribute('aria-label', `${donnees.nom} — ${STATUTS[statut]}`)

		// Le dessin n'est recréé que si le type ou la couleur change (sinon on
		// casserait les animations en cours à chaque rafraîchissement).
		const couleur = resa?.brassin?.couleur ?? null
		const dessinAChanger = typeDessine !== donnees.type || couleurDessinee !== couleur

		element.innerHTML = `
			<header class="brassage-carte__entete">
				<div>
					<h3 class="brassage-carte__nom">${echapper(donnees.nom)}</h3>
					<span class="brassage-carte__type">${echapper(donnees.typeLibelle)}${donnees.capaciteL ? ` · ${donnees.capaciteL} L` : ''}</span>
				</div>
				<span class="brassage-pastille brassage-pastille--${statut}">${STATUTS[statut]}</span>
			</header>
			<div class="brassage-carte__scene"></div>
			${resa ? blocAvancement(resa) : blocLibre(donnees)}
			${donnees.prochaine ? `
				<footer class="brassage-carte__prochaine">
					<span class="brassage-etiquette">Prochaine</span>
					${echapper(donnees.prochaine.brassin?.nom ?? donnees.prochaine.titre)} —
					${echapper(donnees.prochaine.organisateur.nom)}, ${dateHeure(donnees.prochaine.debut)}
					${donnees.prochaine.statut === 'en_attente' ? '<em>(à valider)</em>' : ''}
				</footer>` : ''}
		`

		// innerHTML a vidé la scène : on y remet le dessin (le même objet SVG,
		// donc ses animations continuent sans à-coup).
		if (dessinAChanger) {
			animation?.detruire()
			dessin = creerDessin(donnees.type, couleur)
			typeDessine = donnees.type
			couleurDessinee = couleur
			animation = null
		}
		element.querySelector('.brassage-carte__scene').appendChild(dessin.svg)

		const etat = etatDessin()
		// On attend que le SVG soit vraiment dans la page (getBBox en a besoin).
		if (animation) animation.mettreAJour(etat)
		else requestAnimationFrame(() => { animation = animerDessin(dessin, etat) })

		if (resa) afficherAvancement(resa.avancement.pourcentage / 100)
	}

	/** Compteur + arc, en partant de la dernière valeur affichée. */
	function afficherAvancement(ratio) {
		const pct = element.querySelector('.brassage-pct')
		const arc = element.querySelector('.brassage-arc__valeur')
		pct.dataset.valeur ??= String(Math.round(dernierRatio * 1000) / 10)
		arc.style.strokeDasharray = String(LONGUEUR_ARC)
		arc.style.strokeDashoffset ||= String(LONGUEUR_ARC * (1 - dernierRatio))
		animerCompteur(pct, Math.round(ratio * 1000) / 10, { suffixe: ' %' })
		animerArc(arc, ratio)
		dernierRatio = ratio
	}

	/** L'état passé au moteur d'animation, recalculé avec l'heure actuelle. */
	function etatDessin() {
		const resa = donnees.enCours
		const direct = resa ? avancementEnDirect(resa) : null
		return {
			type: donnees.type,
			actif: Boolean(resa) && resa.statut === 'confirmee',
			enAttente: resa?.statut === 'en_attente' || donnees.prochaine?.statut === 'en_attente',
			niveau: niveauDe(donnees),
			progression: direct?.ratio ?? 0,
			progressionEtape: direct?.ratioEtape ?? 0,
			etapeCourante: direct?.etapeCourante ?? null,
			temperature: resa?.derniereTemperature?.valeur ?? null,
		}
	}

	/** Avancement en direct, sans reconstruire la carte. */
	function tic() {
		const resa = donnees.enCours
		if (!resa) return
		const direct = avancementEnDirect(resa)
		afficherAvancement(direct.ratio)
		element.querySelector('.brassage-reste').textContent = direct.ratio >= 1 ? 'Terminé' : `Reste ${duree(direct.secondesRestantes)}`
		const etape = resa.avancement.etapes[direct.etapeCourante]
		if (etape) element.querySelector('.brassage-etape').textContent = etape.nom
		animation?.mettreAJour(etatDessin())
	}

	rendre()

	return {
		element,
		mettreAJour(nouveau) {
			donnees = nouveau
			rendre()
		},
		tic,
		detruire() {
			animation?.detruire()
			element.remove()
		},
	}
}

/** Le bloc « 40 % · reste 60 j · Maturation » + contenu + qui. */
function blocAvancement(resa) {
	const { avancement, brassin, organisateur } = resa
	const etape = avancement.etapes[avancement.etapeCourante]
	const couleur = couleurDe(brassin?.couleur)
	return `
		<div class="brassage-carte__avancement">
			<svg class="brassage-arc" viewBox="0 0 64 64" aria-hidden="true">
				<circle class="brassage-arc__fond" cx="32" cy="32" r="27"/>
				<!-- rotate(-90) : l'arc commence en haut, comme une horloge -->
				<circle class="brassage-arc__valeur" cx="32" cy="32" r="27" transform="rotate(-90 32 32)"/>
			</svg>
			<div class="brassage-carte__chiffres">
				<strong class="brassage-pct">0 %</strong>
				<span class="brassage-reste">${avancement.pourcentage >= 100 ? 'Terminé' : `Reste ${duree(avancement.secondesRestantes)}`}</span>
				<span class="brassage-etape">${echapper(etape?.nom ?? '')}</span>
			</div>
		</div>
		<div class="brassage-carte__contenu">
			<span class="brassage-goutte" style="--couleur-biere: ${couleur.liquide}"></span>
			${brassin
				? `<span><strong>${echapper(brassin.nom)}</strong>${brassin.style ? ` · ${echapper(brassin.style)}` : ''}${brassin.volumeL ? ` · ${brassin.volumeL} L` : ''}</span>`
				: '<span class="brassage-discret">Contenu non renseigné</span>'}
		</div>
		<div class="brassage-carte__qui">
			${organisateur.uid ? `<img class="brassage-avatar" src="${avatar(organisateur.uid)}" alt="" loading="lazy">` : ''}
			<span>${echapper(organisateur.nom)} · du ${date(resa.debut)} au ${date(resa.fin)}</span>
		</div>
	`
}

/** Le bloc d'une cuve sans réservation en cours. */
function blocLibre(equipement) {
	const n = equipement.nettoyage
	const message = n.statut === 'a_nettoyer'
		? 'Utilisée récemment : pensez à la nettoyer avant la prochaine fois.'
		: n.nettoyeLe ? `Propre — nettoyée le ${date(n.nettoyeLe)}${n.nettoyePar ? ` par ${echapper(n.nettoyePar)}` : ''}.` : 'Disponible.'
	return `<p class="brassage-carte__libre">${message}</p>`
}
