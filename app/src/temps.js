/**
 * L'heure « du serveur » côté navigateur, et le recalcul de l'avancement.
 *
 * Pourquoi ? Le serveur nous donne l'avancement au moment de la requête.
 * Pour que les jauges avancent en direct sans redemander toutes les données
 * chaque minute, le front refait le même calcul que Avancement.php avec
 * l'heure courante.
 *
 * Mais l'horloge de l'ordinateur peut être fausse de quelques minutes : on
 * mesure donc l'écart entre l'heure du serveur (champ « maintenant » de
 * l'API) et celle du navigateur, et on le corrige.
 */

let ecart = 0 // en millisecondes : heure serveur - heure navigateur

/** À appeler à chaque réponse de l'API atelier. */
export function synchroniser(maintenantServeurIso) {
	ecart = new Date(maintenantServeurIso).getTime() - Date.now()
}

/** L'heure actuelle, recalée sur le serveur (en millisecondes). */
export const maintenant = () => Date.now() + ecart

/**
 * Même calcul que Avancement::calculer() en PHP, mais sur les étapes déjà
 * calculées par le serveur (on réutilise leurs dates de début et de fin).
 *
 * @param {object} reservation objet « enCours » de l'API
 * @returns {{ ratio: number, secondesRestantes: number, etapeCourante: number|null, ratioEtape: number }}
 */
export function avancementEnDirect(reservation) {
	const t = maintenant()
	const debut = new Date(reservation.debut).getTime()
	const fin = new Date(reservation.fin).getTime()
	// t >= fin d'abord : sinon une réservation de durée nulle resterait à 0 %.
	const ratio = t >= fin ? 1 : borner((t - debut) / Math.max(1, fin - debut))

	let etapeCourante = null
	let ratioEtape = 0
	reservation.avancement.etapes.forEach((etape, i) => {
		const d = new Date(etape.debut).getTime()
		const f = new Date(etape.fin).getTime()
		if (t >= d && t < f) {
			etapeCourante = i
			ratioEtape = borner((t - d) / Math.max(1, f - d))
		}
	})

	return {
		ratio,
		secondesRestantes: Math.max(0, Math.round((fin - t) / 1000)),
		etapeCourante,
		ratioEtape,
	}
}

const borner = (x) => Math.max(0, Math.min(1, x))
