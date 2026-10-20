/**
 * Les dessins SVG des équipements.
 *
 * Chaque dessin est un fichier .svg dans src/dessins/. Le suffixe « ?raw »
 * demande à Vite d'importer le fichier comme une simple chaîne de caractères,
 * qu'on insère ensuite dans la page. Avantage : les dessins restent de vrais
 * fichiers SVG, qu'on peut ouvrir et retoucher dans Inkscape/Figma.
 *
 * alambic.svg est l'illustration de référence du projet ; les trois autres
 * reprennent son style (mêmes dégradés) et son « contrat d'animation » : les mêmes id
 * (#liquid-level, #tank-bubbles, #light-running…) ont le même rôle partout.
 * Le détail est dans docs/03-front-et-animations.md.
 */
import alambic from './dessins/alambic.svg?raw'
import brassage from './dessins/brassage.svg?raw'
import fermenteur from './dessins/fermenteur.svg?raw'
import garde from './dessins/garde.svg?raw'
import { couleurDe } from './couleurs.js'

const SOURCES = { alambic, brassage, fermenteur, garde }

let compteur = 0

/**
 * Fabrique un <svg> prêt à l'emploi pour un équipement.
 *
 * Problème à régler : dans une page HTML, un id doit être UNIQUE. Or si on
 * affiche deux fermenteurs, on aurait deux fois id="liquid-level", deux fois
 * id="gold"… et les dégradés de la 2e cuve pointeraient vers ceux de la 1re.
 * Solution : on préfixe tous les id (et toutes les références url(#…)) avec
 * un préfixe propre à cette copie du dessin : « d3-liquid-level ».
 *
 * @param {string} type brassage | fermenteur | garde | alambic
 * @param {string|null} couleur clé de couleur de la bière (voir couleurs.js)
 * @returns {{ svg: SVGSVGElement, partie: (id: string) => Element|null, parties: (sel: string) => Element[] }}
 */
export function creerDessin(type, couleur) {
	const prefixe = `d${++compteur}-`
	const source = SOURCES[type] ?? SOURCES.fermenteur

	const texte = source
		// id="x" → id="d3-x"
		.replace(/\bid="([^"]+)"/g, `id="${prefixe}$1"`)
		// url(#x) → url(#d3-x)   (dégradés, clipPath)
		.replace(/url\(#([^)]+)\)/g, `url(#${prefixe}$1)`)
		// aria-labelledby="title desc" référence aussi des id
		.replace(/aria-labelledby="([^"]+)"/g, (_, ids) => `aria-labelledby="${ids.split(' ').map((i) => prefixe + i).join(' ')}"`)

	// DOMParser transforme le texte en vrais éléments, sans exécuter de script
	// (plus sûr que innerHTML, même si ces fichiers sont à nous).
	const doc = new DOMParser().parseFromString(texte, 'image/svg+xml')
	const svg = document.importNode(doc.documentElement, true)
	svg.classList.add('brassage-dessin')
	svg.setAttribute('preserveAspectRatio', 'xMidYMid meet')

	const partie = (id) => svg.querySelector(`#${CSS.escape(prefixe + id)}`)
	const parties = (selecteur) => [...svg.querySelectorAll(selecteur)]

	teindre(partie('gold'), couleur)
	return { svg, partie, parties }
}

/**
 * Recolore le dégradé du liquide (#gold) avec la couleur de la bière.
 * Le haut du liquide est un peu plus clair, le bas un peu plus foncé :
 * ça donne du volume.
 */
function teindre(degrade, cleCouleur) {
	if (!degrade || !cleCouleur) return
	const { liquide } = couleurDe(cleCouleur)
	const arrets = degrade.querySelectorAll('stop')
	const teintes = [eclaircir(liquide, 0.25), liquide, eclaircir(liquide, -0.25)]
	arrets.forEach((arret, i) => arret.setAttribute('stop-color', teintes[Math.min(i, teintes.length - 1)]))
}

/**
 * Éclaircit (facteur > 0) ou assombrit (facteur < 0) une couleur « #rrggbb ».
 * On mélange chaque composante avec du blanc (ou du noir).
 */
function eclaircir(hex, facteur) {
	const n = parseInt(hex.slice(1), 16)
	const composantes = [(n >> 16) & 255, (n >> 8) & 255, n & 255]
	const cible = facteur > 0 ? 255 : 0
	const f = Math.abs(facteur)
	const [r, g, b] = composantes.map((c) => Math.round(c + (cible - c) * f))
	return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)
}
