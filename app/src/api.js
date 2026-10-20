/**
 * Tous les appels HTTP du front, au même endroit.
 *
 * Deux API différentes :
 * - la NÔTRE (/apps/brassage/api/…) : atelier, brassins, journal, nettoyage ;
 * - celle de ROOMVOX (/apps/roomvox/api/…) : créer une réservation, la valider.
 *   On réutilise RoomVox au lieu de recoder la réservation : il gère déjà les
 *   conflits de créneaux, les droits, les emails aux responsables.
 */
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

/**
 * fetch() avec ce que Nextcloud attend :
 * - le jeton « requesttoken » : protection CSRF (une autre page web ne peut
 *   pas faire agir notre session à notre insu, car elle ne connaît pas ce jeton) ;
 * - du JSON dans les deux sens.
 * Si la réponse est une erreur (400, 403…), on lève une Error avec le message
 * renvoyé par le serveur, pour l'afficher tel quel à l'utilisateur.
 *
 * @param {string} chemin chemin après /index.php (ex. '/apps/brassage/api/atelier')
 * @param {object} [options]
 * @param {string} [options.methode='GET']
 * @param {object} [options.corps] objet envoyé en JSON
 */
async function requete(chemin, { methode = 'GET', corps } = {}) {
	const reponse = await fetch(generateUrl(chemin), {
		method: methode,
		headers: {
			requesttoken: getRequestToken() ?? '',
			Accept: 'application/json',
			...(corps ? { 'Content-Type': 'application/json' } : {}),
		},
		body: corps ? JSON.stringify(corps) : undefined,
	})

	// Certaines réponses n'ont pas de corps JSON : on ne plante pas pour ça.
	const donnees = await reponse.json().catch(() => ({}))
	if (!reponse.ok) {
		// Notre API répond { erreur }, RoomVox répond { error }.
		throw new Error(donnees.erreur ?? donnees.error ?? `Erreur ${reponse.status}`)
	}
	return donnees
}

// encodeURIComponent : un UID de réservation peut contenir @, +, etc.
const enc = encodeURIComponent

// ── Notre API ──

export const chargerAtelier = () => requete('/apps/brassage/api/atelier')

/** reservationId = le champ « id » de la réservation dans l'API atelier. */
export const enregistrerBrassin = (roomId, reservationId, brassin) =>
	requete(`/apps/brassage/api/equipements/${enc(roomId)}/reservations/${enc(reservationId)}/brassin`, { methode: 'PUT', corps: brassin })

export const declarerNettoyage = (roomId) =>
	requete(`/apps/brassage/api/equipements/${enc(roomId)}/nettoyage`, { methode: 'POST' })

export const reglerEquipement = (roomId, reglages) =>
	requete(`/apps/brassage/api/equipements/${enc(roomId)}`, { methode: 'PUT', corps: reglages })

/**
 * Le créneau respecte-t-il les horaires et le délai maximum de la machine ?
 * @returns {Promise<{ok: boolean, raison?: string}>}
 */
export const verifierCreneau = (roomId, debut, fin) =>
	requete(`/apps/brassage/api/equipements/${enc(roomId)}/creneau?debut=${enc(debut.toISOString())}&fin=${enc(fin.toISOString())}`)

/** Remplace les étapes d'une machine. etapes = [{ nom, part, explication }] */
export const enregistrerEtapes = (roomId, etapes) =>
	requete(`/apps/brassage/api/equipements/${enc(roomId)}/etapes`, { methode: 'PUT', corps: { etapes } })

/** Revient aux étapes par défaut du type de machine. */
export const reinitialiserEtapes = (roomId) =>
	requete(`/apps/brassage/api/equipements/${enc(roomId)}/etapes`, { methode: 'DELETE' })

export const chargerJournal = (brassinId) => requete(`/apps/brassage/api/brassins/${brassinId}/journal`)

export const ajouterAuJournal = (brassinId, entree) =>
	requete(`/apps/brassage/api/brassins/${brassinId}/journal`, { methode: 'POST', corps: entree })

export const supprimerDuJournal = (brassinId, entreeId) =>
	requete(`/apps/brassage/api/brassins/${brassinId}/journal/${entreeId}`, { methode: 'DELETE' })

// ── API de RoomVox ──

/**
 * Crée une réservation via RoomVox. Il refuse lui-même si le créneau est
 * déjà pris (409) ou si on n'a pas le droit (403).
 * @returns {Promise<{uid: string}>}
 */
export const reserver = (roomId, { titre, debut, fin }) =>
	requete(`/apps/roomvox/api/rooms/${enc(roomId)}/bookings`, {
		methode: 'POST',
		corps: { summary: titre, start: debut.toISOString(), end: fin.toISOString() },
	})

/** Un·e responsable valide (accept) ou refuse (decline) une demande. */
export const repondreDemande = (roomId, bookingUid, accepter) =>
	requete(`/apps/roomvox/api/rooms/${enc(roomId)}/bookings/${enc(bookingUid)}/respond`, {
		methode: 'POST',
		corps: { action: accepter ? 'accept' : 'decline' },
	})
