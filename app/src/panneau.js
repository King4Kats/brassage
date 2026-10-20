/**
 * Le panneau de détail, qui glisse depuis la droite quand on clique sur une
 * carte. On y trouve tout ce qui concerne UN équipement :
 *   1. le dessin en grand ;
 *   2. la frise des étapes (« que se passe-t-il dans la cuve ? ») ;
 *   3. le contenu (bière, style, volume) — modifiable par l'organisateur ;
 *   4. la validation des demandes (responsables) ;
 *   5. le journal de mesures (densité, température, notes) ;
 *   6. le nettoyage ;
 *   7. réserver ;
 *   8. les réglages de l'équipement (responsables).
 *
 * Chaque action appelle l'API (api.js) puis demande à main.js de recharger
 * l'atelier via le rappel « surChangement ».
 */
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { gsap } from 'gsap'
import * as api from './api.js'
import { animerDessin, animerNettoyage } from './animations.js'
import { avatar } from './carte.js'
import { COULEURS, couleurDe } from './couleurs.js'
import { creerDessin } from './dessins.js'
import { date, dateHeure, duree, echapper, heure } from './format.js'
import { avancementEnDirect, maintenant } from './temps.js'

/** Durée proposée par défaut à la réservation, selon le type (en heures). */
const DUREE_PAR_DEFAUT = { brassage: 8, alambic: 8, fermenteur: 28 * 24, garde: 60 * 24 }

/** Libellés des types d'équipement (les mêmes que Gabarits.php côté PHP). */
const LIBELLES_TYPES = { brassage: 'Cuve de brassage', fermenteur: 'Fermenteur', garde: 'Cuve de garde', alambic: 'Alambic' }

const TYPES_JOURNAL = {
	densite: { libelle: 'Densité', unite: '', pas: '0.001', exemple: '1.048' },
	temperature: { libelle: 'Température', unite: ' °C', pas: '0.1', exemple: '19.5' },
	note: { libelle: 'Note', unite: '', pas: null, exemple: '' },
}

/**
 * @param {HTMLElement} racine l'élément de l'app
 * @param {{ surChangement: () => Promise<void>, notifier: (message: string, erreur?: boolean) => void }} rappels
 */
export function creerPanneau(racine, { surChangement, notifier }) {
	// Le fond sombre derrière le panneau (cliquer dessus ferme le panneau).
	const voile = document.createElement('div')
	voile.className = 'brassage-voile'
	voile.addEventListener('click', fermer)

	const panneau = document.createElement('aside')
	panneau.className = 'brassage-panneau'
	panneau.setAttribute('role', 'dialog')
	panneau.setAttribute('aria-modal', 'true')
	panneau.setAttribute('aria-labelledby', 'brassage-panneau-titre')
	racine.append(voile, panneau)

	// Échap ferme le panneau (attendu par les utilisateurs clavier).
	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && estOuvert()) fermer()
	})

	// « Piège à focus » : tant que le panneau est ouvert, Tab et Maj+Tab
	// tournent en boucle DANS le panneau, au lieu de partir dans la page
	// derrière (qu'on ne voit plus). C'est ce qu'on attend d'une fenêtre modale.
	panneau.addEventListener('keydown', (e) => {
		if (e.key !== 'Tab') return
		const focusables = [...panneau.querySelectorAll('button, [href], input, select, textarea, summary, [tabindex]:not([tabindex="-1"])')]
			.filter((el) => !el.disabled && el.offsetParent !== null)
		if (focusables.length === 0) return
		const premier = focusables[0]
		const dernier = focusables[focusables.length - 1]
		if (e.shiftKey && document.activeElement === premier) {
			e.preventDefault()
			dernier.focus()
		} else if (!e.shiftKey && document.activeElement === dernier) {
			e.preventDefault()
			premier.focus()
		}
	})

	/**
	 * « inert » rend le reste de l'atelier inactif (ni clic, ni focus, ignoré par
	 * les lecteurs d'écran) pendant que le panneau est ouvert.
	 */
	function rendreFondInerte(inerte) {
		racine.querySelectorAll('.brassage-entete, .brassage-grille').forEach((el) => { el.inert = inerte })
	}

	/**
	 * La « signature » d'un équipement SANS les champs qui changent tout seuls
	 * avec le temps (avancement). Si elle ne change pas, inutile de redessiner
	 * le panneau : on évite de relancer ses animations toutes les 5 minutes.
	 */
	const signature = (e) => JSON.stringify(e, (cle, valeur) => (cle === 'avancement' ? undefined : valeur))

	let equipement = null
	let animation = null
	let derniereCarte = null // pour rendre le focus à la carte en fermant
	// Vrai dès qu'on tape dans un champ du panneau. Tant que c'est vrai, les
	// rafraîchissements automatiques ne redessinent PAS le panneau (sinon la
	// saisie en cours serait effacée toutes les 5 minutes).
	let saisieEnCours = false
	panneau.addEventListener('input', () => { saisieEnCours = true })

	const estOuvert = () => panneau.classList.contains('est-ouvert')

	function ouvrir(donnees, carteOrigine) {
		const dejaOuvert = estOuvert()
		equipement = donnees
		derniereCarte = carteOrigine ?? derniereCarte
		rendre()
		if (!dejaOuvert) {
			panneau.classList.add('est-ouvert')
			voile.classList.add('est-ouvert')
			rendreFondInerte(true)
			// Le panneau glisse depuis la droite, le voile apparaît en fondu.
			gsap.fromTo(panneau, { xPercent: 100 }, { xPercent: 0, duration: 0.45, ease: 'power3.out' })
			gsap.fromTo(voile, { opacity: 0 }, { opacity: 1, duration: 0.3 })
			panneau.querySelector('.brassage-panneau__fermer')?.focus()
		}
	}

	function fermer() {
		gsap.to(panneau, {
			xPercent: 100,
			duration: 0.35,
			ease: 'power2.in',
			onComplete: () => {
				panneau.classList.remove('est-ouvert')
				animation?.detruire()
				animation = null
				equipement = null
			},
		})
		gsap.to(voile, { opacity: 0, duration: 0.3, onComplete: () => voile.classList.remove('est-ouvert') })
		// D'abord rendre le fond actif : un élément « inert » ne peut pas recevoir le focus.
		rendreFondInerte(false)
		derniereCarte?.focus()
	}

	/** Appelé par main.js après un rechargement : on met à jour si ouvert. */
	function rafraichir(equipements) {
		if (!equipement) return
		const nouveau = equipements.find((e) => e.roomId === equipement.roomId)
		if (!nouveau) return fermer()
		// Rien d'important n'a changé, ou on est en train de taper : on garde les
		// données à jour sans redessiner (tic() fait avancer les chiffres).
		if (saisieEnCours || signature(nouveau) === signature(equipement)) {
			equipement = nouveau
			tic()
			return
		}
		ouvrir(nouveau)
	}

	/**
	 * Toutes les 30 s (appelé par main.js) : le pourcentage, le temps restant
	 * et le curseur de la frise avancent, sans redessiner le panneau.
	 */
	function tic() {
		const resa = equipement?.enCours
		if (!resa || !estOuvert()) return
		const direct = avancementEnDirect(resa)
		const pct = panneau.querySelector('.brassage-resume__pct')
		const reste = panneau.querySelector('.brassage-resume__reste')
		const curseur = panneau.querySelector('.brassage-frise__curseur')
		if (pct) pct.textContent = `${Math.round(direct.ratio * 100)} %`
		if (reste) reste.innerHTML = direct.ratio >= 1 ? 'Terminé' : `Encore <strong>${duree(direct.secondesRestantes)}</strong>`
		if (curseur) gsap.to(curseur, { left: `${direct.ratio * 100}%`, duration: 0.6 })
	}

	// ─────────────────────────── Rendu ───────────────────────────

	function rendre() {
		const e = equipement
		const resa = e.enCours
		animation?.detruire()
		saisieEnCours = false

		panneau.innerHTML = `
			<header class="brassage-panneau__entete">
				<div>
					<h2 id="brassage-panneau-titre">${echapper(e.nom)}</h2>
					<p class="brassage-discret">${echapper(e.typeLibelle)}${e.capaciteL ? ` · ${e.capaciteL} L` : ''}${e.description ? ` — ${echapper(e.description)}` : ''}</p>
				</div>
				<button type="button" class="brassage-panneau__fermer" aria-label="Fermer">✕</button>
			</header>
			<div class="brassage-panneau__scene"></div>
			${resa ? sectionEnCours(e, resa) : ''}
			${sectionValidation(e)}
			${resa?.brassin ? '<section class="brassage-section" data-section="journal"><h3>Journal de bord</h3><p class="brassage-discret">Chargement…</p></section>' : ''}
			${sectionNettoyage(e)}
			${e.prochaine ? sectionProchaine(e.prochaine) : ''}
			${e.droits.reserver ? sectionReserver(e) : ''}
			${e.droits.gerer ? sectionReglages(e) : ''}
			${e.droits.gerer ? '<section class="brassage-section" data-section="etapes"></section>' : ''}
			<p class="brassage-panneau__pied">
				<a href="${echapper(lienRoomVox())}">Voir toutes les réservations dans RoomVox →</a>
			</p>
		`

		panneau.querySelector('.brassage-panneau__fermer').addEventListener('click', fermer)

		// Le dessin en grand, animé comme sur la carte.
		const dessin = creerDessin(e.type, resa?.brassin?.couleur ?? null)
		panneau.querySelector('.brassage-panneau__scene').appendChild(dessin.svg)
		const direct = resa ? avancementEnDirect(resa) : null
		requestAnimationFrame(() => {
			animation = animerDessin(dessin, {
				type: e.type,
				actif: Boolean(resa) && resa.statut === 'confirmee',
				enAttente: resa?.statut === 'en_attente',
				niveau: resa ? (resa.brassin?.volumeL && e.capaciteL ? Math.min(1, resa.brassin.volumeL / e.capaciteL) : 0.7) : 0,
				progression: direct?.ratio ?? 0,
				progressionEtape: direct?.ratioEtape ?? 0,
				etapeCourante: direct?.etapeCourante ?? null,
				temperature: resa?.derniereTemperature?.valeur ?? null,
			})
		})

		brancherFormulaires()
		if (e.droits.gerer) brancherEditeurEtapes(panneau.querySelector('[data-section="etapes"]'), e)
		if (resa?.brassin) chargerJournal(resa.brassin.id)

		// Le curseur de la frise glisse jusqu'à « maintenant ».
		const curseur = panneau.querySelector('.brassage-frise__curseur')
		if (curseur && direct) gsap.fromTo(curseur, { left: '0%' }, { left: `${direct.ratio * 100}%`, duration: 1.2, ease: 'power2.out' })
	}

	function sectionEnCours(e, resa) {
		const { avancement, organisateur, brassin } = resa
		const direct = avancementEnDirect(resa)
		const total = new Date(resa.fin) - new Date(resa.debut)

		// La frise : un segment par étape, de largeur proportionnelle à sa durée.
		const segments = avancement.etapes.map((etape, i) => {
			const largeur = ((new Date(etape.fin) - new Date(etape.debut)) / total) * 100
			// maintenant() = l'heure du SERVEUR (recalée), comme partout ailleurs.
			const etat = i === direct.etapeCourante ? 'en_cours' : (new Date(etape.fin).getTime() <= maintenant() ? 'faite' : 'a_venir')
			return `<div class="brassage-frise__segment brassage-frise__segment--${etat}" style="width:${largeur}%" title="${echapper(etape.nom)}"></div>`
		}).join('')

		const etapes = avancement.etapes.map((etape, i) => {
			const enCours = i === direct.etapeCourante
			return `
				<li class="brassage-etapes__item ${enCours ? 'est-courante' : ''}">
					<strong>${echapper(etape.nom)}</strong>
					<span class="brassage-discret">${dateHeure(etape.debut)} → ${dateHeure(etape.fin)}</span>
					${enCours ? `<p>${echapper(etape.explication)}</p>` : ''}
				</li>`
		}).join('')

		return `
			<section class="brassage-section">
				<h3>En ce moment</h3>
				<div class="brassage-resume">
					<div class="brassage-resume__pct">${Math.round(direct.ratio * 100)} %</div>
					<div>
						<div><span class="brassage-resume__reste">${direct.ratio >= 1 ? 'Terminé' : `Encore <strong>${duree(direct.secondesRestantes)}</strong>`}</span> · fin ${dateHeure(resa.fin)}</div>
						<div class="brassage-carte__qui">
							${organisateur.uid ? `<img class="brassage-avatar" src="${avatar(organisateur.uid)}" alt="">` : ''}
							Réservé par <strong>${echapper(organisateur.nom)}</strong>${resa.statut === 'en_attente' ? ' <em>(en attente de validation)</em>' : ''}
						</div>
					</div>
				</div>
				<div class="brassage-frise" aria-hidden="true">
					${segments}
					<span class="brassage-frise__curseur"></span>
				</div>
				<ol class="brassage-etapes">${etapes}</ol>
			</section>
			<section class="brassage-section">
				<h3>Dans la cuve</h3>
				${brassin ? `
					<p class="brassage-carte__contenu">
						<span class="brassage-goutte" style="--couleur-biere:${couleurDe(brassin.couleur).liquide}"></span>
						<span><strong>${echapper(brassin.nom)}</strong>${brassin.style ? ` · ${echapper(brassin.style)}` : ''}${brassin.volumeL ? ` · ${brassin.volumeL} L` : ''} · ${echapper(couleurDe(brassin.couleur).libelle)}</span>
					</p>
					${brassin.notes ? `<p class="brassage-notes">${echapper(brassin.notes)}</p>` : ''}
				` : '<p class="brassage-discret">Le contenu n\'a pas encore été renseigné.</p>'}
				${resa.modifiable ? formulaireBrassin(resa) : ''}
			</section>
		`
	}

	function formulaireBrassin(resa) {
		const b = resa.brassin ?? {}
		return `
			<details class="brassage-details" ${resa.brassin ? '' : 'open'}>
				<summary>${resa.brassin ? 'Modifier le contenu' : 'Renseigner le contenu'}</summary>
				<form class="brassage-form" data-form="brassin" data-id="${echapper(resa.id)}">
					${champsBrassin(b)}
					<button type="submit" class="primary">Enregistrer</button>
				</form>
			</details>`
	}

	/** Les champs « bière » (réutilisés par le formulaire de réservation). */
	function champsBrassin(b = {}) {
		const options = Object.entries(COULEURS)
			.map(([cle, c]) => `<option value="${cle}" ${b.couleur === cle ? 'selected' : ''}>${c.libelle}</option>`).join('')
		return `
			<label>Nom de la bière<input name="nom" required maxlength="128" value="${echapper(b.nom ?? '')}" placeholder="ex. Blonde de printemps"></label>
			<div class="brassage-form__ligne">
				<label>Style<input name="style" maxlength="64" value="${echapper(b.style ?? '')}" placeholder="ex. IPA"></label>
				<label>Couleur<select name="couleur"><option value="">—</option>${options}</select></label>
				<label>Volume (L)<input name="volumeL" type="number" min="1" max="100000" value="${b.volumeL ?? ''}"></label>
			</div>
			<label>Notes<textarea name="notes" rows="2" placeholder="Recette, houblons, levure…">${echapper(b.notes ?? '')}</textarea></label>`
	}

	function sectionValidation(e) {
		if (!e.droits.gerer) return ''
		const demandes = [e.enCours, e.prochaine].filter((r) => r?.statut === 'en_attente')
		if (demandes.length === 0) return ''
		return `
			<section class="brassage-section brassage-section--alerte">
				<h3>Demandes à valider</h3>
				${demandes.map((r) => `
					<div class="brassage-demande">
						<span><strong>${echapper(r.organisateur.nom)}</strong> — ${echapper(r.brassin?.nom ?? r.titre)}<br>
						<span class="brassage-discret">${dateHeure(r.debut)} → ${dateHeure(r.fin)}</span></span>
						<span class="brassage-demande__boutons">
							<button type="button" class="primary" data-action="accepter" data-uid="${echapper(r.uidCalendrier)}">Valider</button>
							<button type="button" data-action="refuser" data-uid="${echapper(r.uidCalendrier)}">Refuser</button>
						</span>
					</div>`).join('')}
			</section>`
	}

	function sectionNettoyage(e) {
		const n = e.nettoyage
		const texte = {
			occupee: 'En cours d\'utilisation.',
			a_nettoyer: 'À nettoyer : la dernière utilisation n\'a pas été suivie d\'un nettoyage.',
			propre: 'Propre.',
		}[n.statut]
		const derniere = n.nettoyeLe ? ` Dernier nettoyage : ${date(n.nettoyeLe)}${n.nettoyePar ? ` par ${echapper(n.nettoyePar)}` : ''}.` : ''
		const bouton = e.droits.reserver && n.statut !== 'occupee'
			? '<button type="button" data-action="nettoyer">🧽 J\'ai nettoyé</button>' : ''
		return `
			<section class="brassage-section brassage-section--${n.statut}">
				<h3>Nettoyage</h3>
				<div class="brassage-nettoyage"><p>${texte}${derniere}</p>${bouton}</div>
			</section>`
	}

	function sectionProchaine(r) {
		return `
			<section class="brassage-section">
				<h3>Prochaine réservation</h3>
				<p>${echapper(r.brassin?.nom ?? r.titre)} — <strong>${echapper(r.organisateur.nom)}</strong>
				${r.statut === 'en_attente' ? '<em>(en attente de validation)</em>' : ''}<br>
				<span class="brassage-discret">${dateHeure(r.debut)} → ${dateHeure(r.fin)}</span></p>
			</section>`
	}

	function sectionReserver(e) {
		// Par défaut : à partir de la prochaine heure pleine, pour la durée
		// habituelle de ce type de machine.
		const debut = new Date()
		debut.setMinutes(0, 0, 0)
		debut.setHours(debut.getHours() + 1)
		const fin = new Date(debut.getTime() + (DUREE_PAR_DEFAUT[e.type] ?? 8) * 3600 * 1000)
		return `
			<section class="brassage-section">
				<details class="brassage-details">
					<summary>Réserver ${echapper(e.nom)}</summary>
					<p class="brassage-discret">${e.validationAuto ? 'La réservation est confirmée tout de suite si le créneau est libre.' : 'Un·e responsable devra valider la demande.'}
					On peut aussi réserver depuis l'app Agenda, en ajoutant « ${echapper(e.nom)} » comme ressource.</p>
					<form class="brassage-form" data-form="reserver">
						<div class="brassage-form__ligne">
							<label>Début<input name="debut" type="datetime-local" required value="${pourChampDate(debut)}"></label>
							<label>Fin<input name="fin" type="datetime-local" required value="${pourChampDate(fin)}"></label>
						</div>
						${champsBrassin()}
						<button type="submit" class="primary">Réserver</button>
					</form>
				</details>
			</section>`
	}

	function sectionReglages(e) {
		return `
			<section class="brassage-section">
				<details class="brassage-details">
					<summary>Réglages de l'équipement</summary>
					<form class="brassage-form" data-form="reglages">
						<label>Nom<input name="nom" required maxlength="128" value="${echapper(e.nom)}"></label>
						<div class="brassage-form__ligne">
							<label>Type<select name="type">${Object.entries(LIBELLES_TYPES).map(([v, l]) => `<option value="${v}" ${e.type === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
							<label>Contenance (L)<input name="capaciteL" type="number" min="1" max="100000" value="${e.capaciteL ?? ''}"></label>
						</div>
						<p class="brassage-discret">Le type choisit le dessin et les étapes. Le nom est aussi celui affiché dans l'Agenda. Les droits et la validation se règlent dans RoomVox.</p>
						<button type="submit">Enregistrer</button>
					</form>
				</details>
			</section>`
	}

	// ─────────────────────────── Éditeur d'étapes ───────────────────────────
	//
	// On travaille sur un « brouillon » (une copie de la liste). Rien n'est
	// envoyé au serveur avant « Enregistrer ».
	//
	// Pour ne pas perdre le curseur pendant la frappe, on ne redessine la liste
	// que sur les changements de STRUCTURE (ajouter, supprimer, déplacer). Une
	// frappe dans un champ met seulement à jour le brouillon et les durées affichées.

	function brancherEditeurEtapes(section, e) {
		const brouillon = e.etapes.map((etape) => ({ ...etape }))
		// La durée sur laquelle on montre l'exemple : la réservation en cours,
		// sinon la prochaine, sinon la durée habituelle du type.
		const resa = e.enCours ?? e.prochaine
		const dureeExemple = resa
			? (new Date(resa.fin) - new Date(resa.debut)) / 1000
			: (DUREE_PAR_DEFAUT[e.type] ?? 8) * 3600

		section.innerHTML = `
			<details class="brassage-details">
				<summary>Étapes de la machine</summary>
				<p class="brassage-discret">
					${e.etapesPersonnalisees
						? 'Étapes <strong>personnalisées</strong> pour cette machine.'
						: `Étapes par défaut du type « ${echapper(e.typeLibelle)} ». En les modifiant, elles deviennent propres à cette machine.`}
					Chaque étape prend une <strong>part</strong> de la durée de la réservation
					(les parts n'ont pas besoin de faire 100). Exemple affiché pour ${resa ? 'la réservation actuelle' : 'une réservation habituelle'} de ${duree(dureeExemple)}.
				</p>
				<ol class="brassage-etapes-edition"></ol>
				<div class="brassage-etapes-edition__actions">
					<button type="button" data-etapes="ajouter">+ Ajouter une étape</button>
				</div>
				<div class="brassage-etapes-edition__actions">
					<button type="button" class="primary" data-etapes="enregistrer">Enregistrer les étapes</button>
					${e.etapesPersonnalisees ? '<button type="button" data-etapes="reinitialiser">Revenir aux étapes du type</button>' : ''}
				</div>
			</details>`

		const liste = section.querySelector('.brassage-etapes-edition')

		function dessinerListe() {
			liste.innerHTML = brouillon.map((etape, i) => `
				<li class="brassage-etape-edition" data-index="${i}">
					<div class="brassage-etape-edition__ligne">
						<input name="nom" maxlength="64" required value="${echapper(etape.nom)}" aria-label="Nom de l'étape ${i + 1}" placeholder="Nom de l'étape">
						<label class="brassage-etape-edition__part">Part
							<input name="part" type="number" min="1" max="1000" step="1" value="${etape.part}">
						</label>
						<span class="brassage-etape-edition__duree brassage-discret"></span>
						<span class="brassage-etape-edition__boutons">
							<button type="button" class="brassage-icone" data-deplacer="-1" ${i === 0 ? 'disabled' : ''} aria-label="Monter">↑</button>
							<button type="button" class="brassage-icone" data-deplacer="1" ${i === brouillon.length - 1 ? 'disabled' : ''} aria-label="Descendre">↓</button>
							<button type="button" class="brassage-icone" data-retirer ${brouillon.length === 1 ? 'disabled' : ''} aria-label="Supprimer l'étape">✕</button>
						</span>
					</div>
					<textarea name="explication" rows="2" maxlength="500" placeholder="Ce qui se passe pendant cette étape (affiché aux membres)">${echapper(etape.explication)}</textarea>
				</li>`).join('')
			afficherDurees()
		}

		/** « 25 % · 7 j » à côté de chaque étape, recalculé à chaque frappe. */
		function afficherDurees() {
			const total = brouillon.reduce((somme, etape) => somme + (Number(etape.part) || 0), 0) || 1
			liste.querySelectorAll('.brassage-etape-edition__duree').forEach((span, i) => {
				const ratio = (Number(brouillon[i].part) || 0) / total
				span.textContent = `${Math.round(ratio * 100)} % · ${duree(dureeExemple * ratio)}`
			})
		}

		// Une seule écoute sur la liste plutôt qu'une par champ (« délégation
		// d'événements ») : ça marche aussi pour les lignes ajoutées plus tard.
		liste.addEventListener('input', (ev) => {
			const ligne = ev.target.closest('[data-index]')
			if (!ligne) return
			const etape = brouillon[Number(ligne.dataset.index)]
			etape[ev.target.name] = ev.target.name === 'part' ? Number(ev.target.value) : ev.target.value
			if (ev.target.name === 'part') afficherDurees()
		})

		liste.addEventListener('click', (ev) => {
			const ligne = ev.target.closest('[data-index]')
			if (!ligne) return
			const i = Number(ligne.dataset.index)
			if (ev.target.matches('[data-retirer]')) {
				brouillon.splice(i, 1)
				dessinerListe()
			} else if (ev.target.matches('[data-deplacer]')) {
				const j = i + Number(ev.target.dataset.deplacer)
				// Échange des deux étapes : [a, b] = [b, a]
				;[brouillon[i], brouillon[j]] = [brouillon[j], brouillon[i]]
				dessinerListe()
				liste.querySelector(`[data-index="${j}"] [data-deplacer="${ev.target.dataset.deplacer}"]`)?.focus()
			}
		})

		section.querySelector('[data-etapes="ajouter"]').addEventListener('click', () => {
			brouillon.push({ nom: '', part: 10, explication: '' })
			dessinerListe()
			liste.lastElementChild.querySelector('input[name="nom"]').focus()
		})

		section.querySelector('[data-etapes="enregistrer"]').addEventListener('click', (ev) => action(ev.currentTarget, async () => {
			await api.enregistrerEtapes(e.roomId, brouillon)
			notifier('Étapes enregistrées.')
			await surChangement()
		}))

		section.querySelector('[data-etapes="reinitialiser"]')?.addEventListener('click', (ev) => action(ev.currentTarget, async () => {
			await api.reinitialiserEtapes(e.roomId)
			notifier(`Retour aux étapes du type « ${e.typeLibelle} ».`)
			await surChangement()
		}))

		dessinerListe()
	}

	// ─────────────────────────── Journal ───────────────────────────

	async function chargerJournal(brassinId) {
		const section = panneau.querySelector('[data-section="journal"]')
		try {
			const entrees = await api.chargerJournal(brassinId)
			// Le panneau a pu changer d'équipement pendant le chargement.
			if (!section.isConnected) return
			section.innerHTML = `<h3>Journal de bord</h3>${courbeDensite(entrees)}${listeJournal(entrees)}${equipement.droits.reserver ? formulaireJournal() : ''}`
			brancherJournal(section, brassinId)
		} catch (err) {
			section.innerHTML = `<h3>Journal de bord</h3><p class="brassage-erreur">${echapper(err.message)}</p>`
		}
	}

	/**
	 * Petite courbe de la densité dans le temps. La densité baisse pendant la
	 * fermentation (le sucre devient de l'alcool) : quand elle ne bouge plus,
	 * la fermentation est finie.
	 */
	function courbeDensite(entrees) {
		const points = entrees.filter((e) => e.type === 'densite')
		if (points.length < 2) return ''
		const L = 320, H = 110, M = 18 // largeur, hauteur, marge
		const temps = points.map((p) => new Date(p.creeLe).getTime())
		const valeurs = points.map((p) => p.valeur)
		const [tMin, tMax] = [Math.min(...temps), Math.max(...temps)]
		const [vMin, vMax] = [Math.min(...valeurs), Math.max(...valeurs)]
		const x = (t) => M + ((t - tMin) / Math.max(1, tMax - tMin)) * (L - 2 * M)
		const y = (v) => M + (1 - (v - vMin) / Math.max(0.0001, vMax - vMin)) * (H - 2 * M)
		const coords = points.map((p, i) => `${x(temps[i]).toFixed(1)},${y(p.valeur).toFixed(1)}`)
		return `
			<figure class="brassage-courbe">
				<svg viewBox="0 0 ${L} ${H}" role="img" aria-label="Densité : de ${valeurs[0]} à ${valeurs.at(-1)}">
					<polyline points="${coords.join(' ')}" fill="none" stroke="var(--brassage-accent)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
					${coords.map((c) => `<circle cx="${c.split(',')[0]}" cy="${c.split(',')[1]}" r="4" fill="var(--brassage-accent)"/>`).join('')}
					<text x="${M}" y="12" class="brassage-courbe__legende">${vMax.toFixed(3)}</text>
					<text x="${M}" y="${H - 4}" class="brassage-courbe__legende">${vMin.toFixed(3)}</text>
				</svg>
				<figcaption class="brassage-discret">Densité au fil du temps (plus elle baisse, plus le sucre est devenu de l'alcool).</figcaption>
			</figure>`
	}

	function listeJournal(entrees) {
		if (entrees.length === 0) return '<p class="brassage-discret">Aucune entrée pour l\'instant.</p>'
		const moi = getCurrentUser()?.uid
		return `<ul class="brassage-journal">${[...entrees].reverse().map((e) => {
			const t = TYPES_JOURNAL[e.type]
			const supprimable = equipement.droits.gerer || e.userId === moi
			return `
				<li class="brassage-journal__entree">
					<span class="brassage-journal__type brassage-journal__type--${e.type}">${t.libelle}</span>
					<span>${e.valeur !== null ? `<strong>${String(e.valeur).replace('.', ',')}${t.unite}</strong> ` : ''}${echapper(e.texte ?? '')}
					<br><span class="brassage-discret">${echapper(e.auteur)} · ${date(e.creeLe)} ${heure(e.creeLe)}</span></span>
					${supprimable ? `<button type="button" class="brassage-icone" data-supprimer="${e.id}" aria-label="Supprimer">🗑</button>` : ''}
				</li>`
		}).join('')}</ul>`
	}

	function formulaireJournal() {
		return `
			<form class="brassage-form brassage-form--journal" data-form="journal">
				<div class="brassage-form__ligne">
					<label>Type<select name="type">${Object.entries(TYPES_JOURNAL).map(([v, t]) => `<option value="${v}">${t.libelle}</option>`).join('')}</select></label>
					<label data-champ="valeur">Valeur<input name="valeur" type="number" step="0.001" placeholder="1.048"></label>
				</div>
				<label>Commentaire<input name="texte" maxlength="2000" placeholder="optionnel pour une mesure"></label>
				<button type="submit">Ajouter au journal</button>
			</form>`
	}

	function brancherJournal(section, brassinId) {
		const form = section.querySelector('[data-form="journal"]')
		if (form) {
			// Le champ « valeur » s'adapte au type choisi (pas de valeur pour une note).
			const ajuster = () => {
				const t = TYPES_JOURNAL[form.type.value]
				form.querySelector('[data-champ="valeur"]').hidden = t.pas === null
				form.valeur.step = t.pas ?? 'any'
				form.valeur.placeholder = t.exemple
			}
			form.type.addEventListener('change', ajuster)
			ajuster()

			form.addEventListener('submit', (ev) => soumettre(ev, async () => {
				const type = form.type.value
				await api.ajouterAuJournal(brassinId, {
					type,
					valeur: TYPES_JOURNAL[type].pas === null || form.valeur.value === '' ? null : Number(form.valeur.value),
					texte: form.texte.value,
				})
				notifier('Ajouté au journal.')
				chargerJournal(brassinId)
			}))
		}
		section.querySelectorAll('[data-supprimer]').forEach((bouton) => bouton.addEventListener('click', async () => {
			try {
				await api.supprimerDuJournal(brassinId, bouton.dataset.supprimer)
				chargerJournal(brassinId)
			} catch (err) {
				notifier(err.message, true)
			}
		}))
	}

	// ─────────────────────────── Actions ───────────────────────────

	function brancherFormulaires() {
		const e = equipement
		const valeursBrassin = (form) => ({
			nom: form.nom.value,
			style: form.style.value,
			couleur: form.couleur.value || null,
			volumeL: form.volumeL.value ? Number(form.volumeL.value) : null,
			notes: form.notes.value,
		})

		panneau.querySelector('[data-form="brassin"]')?.addEventListener('submit', (ev) => soumettre(ev, async () => {
			await api.enregistrerBrassin(e.roomId, ev.target.dataset.id, valeursBrassin(ev.target))
			notifier('Contenu enregistré.')
			await surChangement()
		}))

		panneau.querySelector('[data-form="reserver"]')?.addEventListener('submit', (ev) => soumettre(ev, async () => {
			const form = ev.target
			const debut = new Date(form.debut.value)
			const fin = new Date(form.fin.value)
			if (!(fin > debut)) throw new Error('La fin doit être après le début.')
			// 0. Horaires d'ouverture et délai maximum : RoomVox ne les vérifie pas
			//    via son API, on le fait nous-mêmes (voir ReglesReservation.php).
			const verification = await api.verifierCreneau(e.roomId, debut, fin)
			if (!verification.ok) throw new Error(verification.raison)
			// 1. RoomVox crée la réservation (et vérifie qu'elle ne chevauche rien).
			const { uid } = await api.reserver(e.roomId, { titre: form.nom.value, debut, fin })
			// 2. On y accroche le contenu.
			await api.enregistrerBrassin(e.roomId, uid, valeursBrassin(form))
			notifier(e.validationAuto ? 'Réservation confirmée.' : 'Demande envoyée : un·e responsable doit la valider.')
			await surChangement()
		}))

		const formReglages = panneau.querySelector('[data-form="reglages"]')
		if (formReglages) {
			// Confort : si le nom commence par le libellé de l'ancien type
			// (« Fermenteur 3 »), on le remplace par le nouveau (« Cuve de garde 3 »).
			// Un nom personnalisé (« La Grosse Bertha ») n'est jamais touché.
			let typeAvant = formReglages.type.value
			formReglages.type.addEventListener('change', () => {
				const ancien = LIBELLES_TYPES[typeAvant]
				const nouveau = LIBELLES_TYPES[formReglages.type.value]
				if (formReglages.nom.value.startsWith(ancien)) {
					formReglages.nom.value = nouveau + formReglages.nom.value.slice(ancien.length)
				}
				typeAvant = formReglages.type.value
			})

			formReglages.addEventListener('submit', (ev) => soumettre(ev, async () => {
				await api.reglerEquipement(e.roomId, {
					nom: formReglages.nom.value,
					type: formReglages.type.value,
					capaciteL: formReglages.capaciteL.value ? Number(formReglages.capaciteL.value) : null,
				})
				notifier('Réglages enregistrés.')
				await surChangement()
			}))
		}

		panneau.querySelectorAll('[data-action="accepter"], [data-action="refuser"]').forEach((bouton) => bouton.addEventListener('click', () => action(bouton, async () => {
			const accepter = bouton.dataset.action === 'accepter'
			await api.repondreDemande(e.roomId, bouton.dataset.uid, accepter)
			notifier(accepter ? 'Demande validée.' : 'Demande refusée.')
			await surChangement()
		})))

		panneau.querySelector('[data-action="nettoyer"]')?.addEventListener('click', (ev) => action(ev.currentTarget, async () => {
			await api.declarerNettoyage(e.roomId)
			animerNettoyage(panneau.querySelector('.brassage-panneau__scene'))
			notifier('Merci ! Cuve notée comme propre.')
			await surChangement()
		}))
	}

	/** Envoi d'un formulaire : bouton désactivé pendant l'envoi, erreur affichée. */
	async function soumettre(ev, travail) {
		ev.preventDefault()
		const bouton = ev.target.querySelector('[type="submit"]')
		await action(bouton, travail)
	}

	async function action(bouton, travail) {
		// On vient de valider la saisie : le rechargement qui suit peut redessiner.
		saisieEnCours = false
		bouton.disabled = true
		try {
			await travail()
		} catch (err) {
			notifier(err.message, true)
		} finally {
			// Le bouton a pu disparaître (le panneau a été redessiné) : pas grave.
			bouton.disabled = false
		}
	}

	return { ouvrir, fermer, rafraichir, tic, estOuvert, roomIdOuvert: () => equipement?.roomId ?? null }
}

/** Date → valeur d'un champ <input type="datetime-local"> (« 2026-10-20T14:00 », heure locale). */
function pourChampDate(d) {
	const p = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

const lienRoomVox = () => generateUrl('/apps/roomvox/')
