/**
 * Couleurs des bières, utilisées pour « remplir » les cuves dans les dessins.
 * Les clés sont les mêmes que Brassin::COULEURS côté PHP.
 *
 * Les teintes suivent grossièrement l'échelle EBC (la couleur des bières, de
 * la plus claire à la plus foncée).
 */
export const COULEURS = {
	blanche: { libelle: 'Blanche', liquide: '#f3dd8a', mousse: '#fffaf0' },
	blonde: { libelle: 'Blonde', liquide: '#e8b23a', mousse: '#fff6dc' },
	ambree: { libelle: 'Ambrée', liquide: '#c9731f', mousse: '#fbe9cf' },
	rousse: { libelle: 'Rousse', liquide: '#a4471b', mousse: '#f6dcc4' },
	brune: { libelle: 'Brune', liquide: '#5e2c12', mousse: '#e8cfae' },
	noire: { libelle: 'Noire', liquide: '#231208', mousse: '#d9bf9a' },
	// Doré, comme dans le dessin d'alambic fourni.
	distillat: { libelle: 'Distillat (eau-de-vie)', liquide: '#ffb21e', mousse: '#fff1b8' },
}

/** Couleur par défaut quand le brassin n'a pas (encore) de couleur. */
export const COULEUR_INCONNUE = { libelle: '—', liquide: '#9aa6ad', mousse: '#e4e9ec' }

export const couleurDe = (cle) => COULEURS[cle] ?? COULEUR_INCONNUE
