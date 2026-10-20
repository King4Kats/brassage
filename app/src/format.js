/**
 * Petites fonctions d'affichage (dates, durées). Elles utilisent Intl, l'API
 * du navigateur qui sait écrire les dates « à la française ».
 */

const formatDate = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
const formatDateHeure = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const formatHeure = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' })

/** « lun. 20 oct. » */
export const date = (iso) => formatDate.format(new Date(iso))

/** « lun. 20 oct., 14:30 » */
export const dateHeure = (iso) => formatDateHeure.format(new Date(iso))

/** « 14:30 » */
export const heure = (iso) => formatHeure.format(new Date(iso))

/**
 * Durée restante lisible : « 60 j 1 h », « 5 h 12 min », « 3 min ».
 * On ne garde que les deux unités les plus grandes : au-delà, c'est du bruit.
 * @param {number} secondes
 */
export function duree(secondes) {
	if (secondes <= 0) return 'terminé'
	const j = Math.floor(secondes / 86400)
	const h = Math.floor((secondes % 86400) / 3600)
	const min = Math.floor((secondes % 3600) / 60)
	if (j > 0) return h > 0 ? `${j} j ${h} h` : `${j} j`
	if (h > 0) return min > 0 ? `${h} h ${min} min` : `${h} h`
	return `${Math.max(1, min)} min`
}

/**
 * Échappe le texte avant de l'insérer dans du HTML.
 * INDISPENSABLE : sans ça, quelqu'un qui appelle sa bière
 * « <img src=x onerror=alert(1)> » exécuterait du code chez tout le monde
 * (faille « XSS »). Toute donnée venant du serveur passe par ici.
 */
export function echapper(texte) {
	return String(texte ?? '')
		.replaceAll('&', '&amp;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;')
		.replaceAll('"', '&quot;')
		.replaceAll("'", '&#39;')
}
