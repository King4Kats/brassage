/**
 * Les animations GSAP des dessins d'équipements.
 *
 * GSAP en 30 secondes :
 * - gsap.to(element, { x: 100, duration: 1 }) → anime « vers » une valeur ;
 * - repeat: -1 → recommence à l'infini ; yoyo: true → fait l'aller-retour ;
 * - ease → la « courbe » du mouvement (sine.inOut = doux au début et à la fin) ;
 * - gsap.context() → regroupe toutes les animations d'un dessin pour pouvoir
 *   les arrêter d'un coup (ctx.revert()) quand la carte disparaît. Sans ça, des
 *   animations continueraient de tourner sur des éléments supprimés (fuite mémoire).
 *
 * Le principe : une fonction animerDessin() reçoit un dessin (voir dessins.js)
 * et un « état » (la cuve est-elle utilisée ? remplie à combien ?). Elle
 * renvoie un petit objet avec mettreAJour(etat) et detruire().
 *
 * Accessibilité : si la personne a demandé « réduire les animations » dans
 * son système (prefers-reduced-motion), on place les éléments à leur position
 * finale sans boucle. Les infos restent visibles, seul le mouvement disparaît.
 */
import { gsap } from 'gsap'

const mouvementReduit = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches

/** Position des aiguilles de cadran (en degrés) : au repos / en marche. */
const AIGUILLE_REPOS = -55
const AIGUILLE_MARCHE = 25

/** Les groupes « décoratifs » qui ne s'affichent que quand la cuve tourne. */
const DECOR = ['tank-bubbles', 'column-bubbles', 'product-drops', 'steam', 'flames', 'airlock-bubble']

/**
 * @typedef {object} EtatDessin
 * @property {string}  type        brassage | fermenteur | garde | alambic
 * @property {boolean} actif       une réservation est en cours
 * @property {boolean} enAttente   la réservation attend une validation
 * @property {number}  niveau      remplissage de la cuve, de 0 à 1
 * @property {number}  progression avancement de la réservation, de 0 à 1
 * @property {number}  progressionEtape avancement de l'étape en cours, de 0 à 1
 * @property {number|null} etapeCourante index de l'étape en cours
 * @property {number|null} temperature dernière température du journal (°C), null si aucune
 */

/**
 * @param {ReturnType<import('./dessins.js').creerDessin>} dessin
 * @param {EtatDessin} etatInitial
 */
export function animerDessin(dessin, etatInitial) {
	const { partie } = dessin
	let etat = etatInitial
	// Les boucles « décoratives » (bulles, vapeur…) : recréées seulement quand
	// la cuve passe de libre à utilisée (ou l'inverse).
	let boucles = null
	let actifPrecedent = null

	// Le contexte qui « possède » toutes nos animations (voir en-tête).
	const ctx = gsap.context(() => {}, dessin.svg)

	function appliquer(animer) {
		ctx.add(() => {
			placerNiveau(animer)
			placerAiguille(animer)
			placerVoyants()
			placerEcran()
			if (etat.type === 'garde') remplirBouteilles(animer)

			if (etat.actif !== actifPrecedent) {
				boucles?.kill()
				boucles = etat.actif ? lancerBoucles() : arreterDecor()
				actifPrecedent = etat.actif
			}
		})
	}

	// ── Niveau du liquide ──
	function placerNiveau(animer) {
		const rect = partie('liquid-level')
		const surface = partie('liquid-surface')
		if (!rect) return

		// Les limites du hublot sont écrites dans le SVG (data-haut / data-bas).
		const haut = Number(rect.dataset.haut)
		const bas = Number(rect.dataset.bas)
		// On retient la position d'origine du rectangle (lue une seule fois),
		// pour savoir de combien décaler la ligne de surface.
		rect.dataset.y0 ??= rect.getAttribute('y')
		const y0 = Number(rect.dataset.y0)

		let niveau = etat.niveau
		// L'alambic se vide au fil de la distillation (l'alcool s'évapore vers
		// le condenseur) : il garde 40 % de son contenu à la fin.
		if (etat.type === 'alambic' && etat.actif) niveau *= 1 - 0.6 * etat.progression

		const y = bas - niveau * (bas - haut)
		const proprietes = { attr: { y, height: bas + 20 - y } }
		const duree = animer ? 1.6 : 0

		gsap.to(rect, { ...proprietes, duration: duree, ease: 'power2.inOut' })
		if (surface) {
			gsap.to(surface, { y: y - y0, opacity: niveau > 0.02 ? 1 : 0, duration: duree, ease: 'power2.inOut' })
		}
	}

	// ── Aiguille du cadran (thermomètre / manomètre) ──
	function placerAiguille(animer) {
		const aiguille = partie('gauge-main-needle')
		if (!aiguille) return
		// On tourne autour du centre du cadran (voir pivotLocal plus bas).
		gsap.to(aiguille, {
			rotation: etat.actif ? AIGUILLE_MARCHE : AIGUILLE_REPOS,
			transformOrigin: pivotLocal(aiguille),
			duration: animer ? 1.2 : 0,
			ease: 'back.out(2)',
		})
	}

	// ── Voyants : jaune = en marche, vert = prêt, gris = au repos ──
	function placerVoyants() {
		gsap.set(partie('light-running'), { opacity: etat.actif ? 1 : 0.25 })
		gsap.set(partie('light-ready'), { opacity: !etat.actif && !etat.enAttente ? 1 : 0.25 })
		gsap.set(partie('light-idle'), { opacity: etat.actif ? 0.25 : 1 })
	}

	// ── Écran du groupe froid / du boîtier (#temp-display) ──
	// Il affiche la DERNIÈRE température notée dans le journal. Sans mesure,
	// on affiche des tirets : mieux vaut « on ne sait pas » qu'un faux chiffre.
	function placerEcran() {
		const ecran = partie('temp-display')
		if (!ecran) return
		const t = etat.temperature
		ecran.textContent = t === null || t === undefined ? '--,-°' : `${t.toFixed(1).replace('.', ',')}°`
		// Couleur selon la plage : froid (bleu), tiède (vert), chaud (jaune).
		ecran.setAttribute('fill', t === null || t === undefined ? '#647383' : t < 12 ? '#7fd0ff' : t < 40 ? '#8ecf8a' : '#ffc52e')
	}

	// ── Bouteilles de la cuve de garde ──
	// Elles se remplissent l'une après l'autre pendant la DERNIÈRE étape
	// (« Conditionnement »), et restent pleines une fois la garde finie.
	function remplirBouteilles(animer) {
		const derniere = 2 // index de « Conditionnement » dans le gabarit garde
		let p = 0
		if (etat.actif && etat.etapeCourante === derniere) p = etat.progressionEtape
		else if (etat.actif && etat.etapeCourante !== null && etat.etapeCourante > derniere) p = 1

		for (let i = 0; i < 4; i++) {
			const fond = partie(`bottle-fill-${i + 1}`)
			if (!fond) continue
			// Bouteille i remplie entre p = i/4 et p = (i+1)/4.
			const remplissage = Math.max(0, Math.min(1, p * 4 - i))
			const hauteur = 168 * remplissage // 168 = hauteur utile d'une bouteille
			gsap.to(fond, { attr: { y: 654 - hauteur, height: hauteur }, duration: animer ? 1 : 0 })
		}
	}

	// ── Décor animé quand la cuve tourne ──
	function lancerBoucles() {
		if (mouvementReduit()) return montrerDecorFige()

		// Si la cuve était libre juste avant, arreterDecor() a caché ces groupes :
		// on les ré-affiche (chaque bulle gère ensuite sa propre opacité).
		gsap.set(DECOR.map(partie).filter(Boolean), { opacity: 1 })

		const tl = gsap.timeline()
		const surface = partie('liquid-surface')

		// La vague : la ligne de surface glisse de gauche à droite.
		if (surface) tl.add(gsap.to(surface, { x: -24, duration: 1.8, ease: 'sine.inOut', yoyo: true, repeat: -1 }), 0)

		// Les bulles montent depuis le fond du hublot jusqu'à la surface.
		faireMonter(tl, partie('tank-bubbles'), 0.9)

		// Le voyant « en marche » clignote doucement.
		tl.add(gsap.to(partie('light-running'), { opacity: 0.35, duration: 0.8, yoyo: true, repeat: -1, ease: 'sine.inOut' }), 0)

		// Puis ce qui est propre à chaque machine.
		const specifique = {
			alambic: bouclesAlambic,
			brassage: bouclesBrassage,
			fermenteur: bouclesFermenteur,
			garde: bouclesGarde,
		}[etat.type]
		specifique?.(tl)

		return tl
	}

	function bouclesAlambic(tl) {
		// La vapeur circule dans le col de cygne : on fait défiler des tirets
		// le long du tuyau (stroke-dashoffset), ce qui donne l'impression d'un flux.
		const flux = partie('vapor-flow')
		if (flux) {
			gsap.set(flux, { attr: { 'stroke-dasharray': '26 18' } })
			tl.add(gsap.to(flux, { attr: { 'stroke-dashoffset': -88 }, duration: 1.4, ease: 'none', repeat: -1 }), 0)
		}
		faireMonter(tl, partie('column-bubbles'), 1.4)
		// Les gouttes d'eau-de-vie tombent dans le récipient, l'une après l'autre.
		;[partie('drop-1'), partie('drop-2')].forEach((goutte, i) => {
			if (!goutte) return
			tl.add(gsap.fromTo(goutte,
				{ y: -10, opacity: 0, scale: 0.6, transformOrigin: '50% 0%' },
				{ y: 50, opacity: 1, scale: 1, duration: 0.9, ease: 'power2.in', repeat: -1, repeatDelay: 0.6, delay: i * 0.75 }), 0)
		})
		// Le serpentin du condenseur « respire » (la chaleur).
		tl.add(gsap.to(partie('coil-copper'), { opacity: 0.75, duration: 1.2, yoyo: true, repeat: -1, ease: 'sine.inOut' }), 0)
		tremblerAiguille(tl, partie('gauge-column-needle'))
	}

	function bouclesBrassage(tl) {
		// Les flammes vacillent (étirées en hauteur, depuis leur base).
		dessin.svg.querySelectorAll(`[id$="flames"] path`).forEach((flamme, i) => {
			tl.add(gsap.to(flamme, { scaleY: 0.7, transformOrigin: '50% 100%', duration: 0.25 + (i % 3) * 0.08, yoyo: true, repeat: -1, ease: 'sine.inOut' }), 0)
		})
		// La vapeur monte et se dissipe.
		dessin.svg.querySelectorAll(`[id$="steam"] circle`).forEach((nuage, i) => {
			gsap.set(nuage, { transformOrigin: '50% 50%' })
			tl.add(gsap.to(nuage, {
				keyframes: { y: [20, -60], scale: [0.6, 1.4], opacity: [0, 0.7, 0] },
				duration: 2.4,
				ease: 'sine.out',
				repeat: -1,
				delay: i * 0.45,
			}), 0)
		})
		// Le fourquet brasse : petit balancement autour de sa poignée.
		tl.add(gsap.to(partie('paddle'), { rotation: 8, svgOrigin: '600 150', duration: 1.1, yoyo: true, repeat: -1, ease: 'sine.inOut' }), 0)
		// La pompe tourne, le moût circule.
		const rotor = partie('pump-rotor')
		if (rotor) tl.add(gsap.to(rotor, { rotation: 360, transformOrigin: pivotLocal(rotor), duration: 1.2, ease: 'none', repeat: -1 }), 0)
		defiler(tl, partie('recirc-flow'), -72)
	}

	function bouclesFermenteur(tl) {
		// Le barboteur fait « bloup » : une bulle monte et éclate, régulièrement.
		const bulle = partie('airlock-bubble')
		if (bulle) {
			tl.add(gsap.fromTo(bulle,
				{ y: 0, scale: 1, opacity: 0.9, transformOrigin: '50% 50%' },
				{ y: -26, scale: 1.4, opacity: 0, duration: 0.7, ease: 'power1.in', repeat: -1, repeatDelay: 1.3 }), 0)
		}
		defiler(tl, partie('cooling-flow'), -64)
	}

	function bouclesGarde(tl) {
		// Le givre scintille.
		dessin.svg.querySelectorAll(`[id$="frost"] path`).forEach((cristal, i) => {
			tl.add(gsap.to(cristal, { opacity: 0.2, scale: 0.7, transformOrigin: '50% 50%', duration: 1.4, yoyo: true, repeat: -1, delay: i * 0.3, ease: 'sine.inOut' }), 0)
		})
		defiler(tl, partie('cooling-flow'), -64)
	}

	// ── Petits outils réutilisés ──

	/** Fait monter en boucle les cercles d'un groupe (bulles). */
	function faireMonter(tl, groupe, vitesse) {
		if (!groupe) return
		groupe.querySelectorAll('circle').forEach((c, i) => {
			const hauteur = 40 + (i % 3) * 25
			// keyframes : plusieurs valeurs réparties sur la durée. La bulle
			// apparaît, monte, puis s'efface avant de recommencer.
			tl.add(gsap.to(c, {
				keyframes: { y: [0, -hauteur], opacity: [0, 1, 1, 0] },
				duration: (1.6 + (i % 2) * 0.6) / vitesse,
				ease: 'power1.in',
				repeat: -1,
				delay: i * 0.35,
			}), 0)
		})
	}

	/** Fait défiler des tirets le long d'un tuyau (effet « ça circule »). */
	function defiler(tl, tuyau, decalage) {
		if (!tuyau) return
		tl.add(gsap.to(tuyau, { attr: { 'stroke-dashoffset': decalage }, duration: 1.2, ease: 'none', repeat: -1 }), 0)
	}

	/** Une aiguille qui tremble autour de sa position de marche. */
	function tremblerAiguille(tl, aiguille) {
		if (!aiguille) return
		const pivot = pivotLocal(aiguille)
		tl.add(gsap.fromTo(aiguille,
			{ rotation: AIGUILLE_MARCHE - 6, transformOrigin: pivot },
			{ rotation: AIGUILLE_MARCHE + 6, transformOrigin: pivot, duration: 0.6, yoyo: true, repeat: -1, ease: 'sine.inOut' }), 0)
	}

	/**
	 * Les aiguilles et le rotor sont dessinés autour du point (0,0) de leur
	 * groupe (le groupe est ensuite déplacé avec transform="translate(…)").
	 * GSAP, lui, mesure le pivot depuis le coin haut-gauche de la « boîte »
	 * de l'élément (getBBox). On calcule donc où se trouve (0,0) dans cette
	 * boîte, en pixels.
	 */
	function pivotLocal(element) {
		const boite = element.getBBox()
		return `${-boite.x}px ${-boite.y}px`
	}

	/** Cuve libre : on cache tout ce qui « bouge » (bulles, vapeur, gouttes…). */
	function arreterDecor() {
		gsap.set(DECOR.map(partie).filter(Boolean), { opacity: 0 })
		gsap.set(partie('liquid-surface'), { x: 0 })
		return null
	}

	/** Mouvement réduit : la cuve tourne mais on montre le décor immobile. */
	function montrerDecorFige() {
		gsap.set(DECOR.map(partie).filter(Boolean), { opacity: 0.8 })
		return null
	}

	appliquer(true)

	return {
		/** Nouvelles données (rafraîchissement) : on anime vers le nouvel état. */
		mettreAJour(nouvelEtat) {
			etat = nouvelEtat
			appliquer(true)
		},
		/** La carte va disparaître : on arrête et on nettoie tout. */
		detruire() {
			boucles?.kill()
			ctx.revert()
		},
	}
}

/**
 * Anime un compteur de 0 (ou de sa valeur actuelle) jusqu'à « cible ».
 * Utilisé pour le pourcentage d'avancement.
 */
export function animerCompteur(element, cible, { decimales = 0, suffixe = '' } = {}) {
	const depart = Number(element.dataset.valeur ?? 0)
	element.dataset.valeur = String(cible)
	const objet = { v: depart }
	const ecrire = () => { element.textContent = objet.v.toFixed(decimales).replace('.', ',') + suffixe }
	if (mouvementReduit()) {
		objet.v = cible
		ecrire()
		return
	}
	gsap.to(objet, { v: cible, duration: 1.4, ease: 'power2.out', onUpdate: ecrire })
}

/** Remplit un arc de progression SVG (cercle avec stroke-dasharray). */
export function animerArc(cercle, ratio) {
	// Astuce classique : le trait du cercle est un unique « tiret » aussi long
	// que le cercle (stroke-dasharray). En décalant ce tiret (stroke-dashoffset),
	// on n'en montre qu'une partie : 0 = cercle plein, longueur = cercle vide.
	// On calcule la longueur à partir du rayon (2πr) plutôt qu'avec
	// getTotalLength() : ce dernier plante si l'élément n'est pas encore
	// affiché dans la page.
	const longueur = 2 * Math.PI * Number(cercle.getAttribute('r'))
	gsap.set(cercle, { strokeDasharray: longueur })
	gsap.to(cercle, {
		strokeDashoffset: longueur * (1 - ratio),
		duration: mouvementReduit() ? 0 : 1.4,
		ease: 'power2.out',
	})
}

/** Entrée des cartes : elles apparaissent l'une après l'autre (« stagger »). */
export function animerEntree(cartes) {
	if (mouvementReduit() || cartes.length === 0) return
	gsap.from(cartes, { y: 28, opacity: 0, duration: 0.7, ease: 'power3.out', stagger: 0.08 })
}

/** Petite explosion d'étincelles quand on déclare une cuve nettoyée. */
export function animerNettoyage(conteneur) {
	if (mouvementReduit()) return
	for (let i = 0; i < 14; i++) {
		const etincelle = document.createElement('span')
		etincelle.className = 'brassage-etincelle'
		conteneur.appendChild(etincelle)
		const angle = (i / 14) * Math.PI * 2
		gsap.fromTo(etincelle,
			{ x: 0, y: 0, scale: 1, opacity: 1 },
			{
				x: Math.cos(angle) * (60 + Math.random() * 40),
				y: Math.sin(angle) * (60 + Math.random() * 40),
				scale: 0,
				opacity: 0,
				duration: 0.9,
				ease: 'power2.out',
				onComplete: () => etincelle.remove(),
			})
	}
}
