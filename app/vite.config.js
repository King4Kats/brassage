// Configuration de Vite, l'outil qui « compile » notre JavaScript.
//
// Pourquoi compiler ? On écrit le code en plusieurs fichiers (src/*.js) qui
// s'importent entre eux, et on utilise des paquets npm (gsap…). Le navigateur
// a besoin d'UN fichier qui contient tout : Vite le fabrique dans
// js/brassage-main.js, que PageController.php demande à Nextcloud d'inclure.
//
// Format « iife » (une fonction qui s'exécute tout de suite) plutôt que module ES :
// c'est le format le plus simple à charger dans Nextcloud, sans souci
// d'ordre de chargement ni de type="module".
import { defineConfig } from 'vite'

export default defineConfig(({ mode }) => ({
	build: {
		outDir: 'js',
		// On ne vide pas js/ : il pourrait contenir d'autres fichiers un jour.
		emptyOutDir: false,
		// Carte des sources (.map) en mode dev : les erreurs dans la console du
		// navigateur pointent alors vers nos vrais fichiers src/…, pas vers le
		// fichier compilé illisible.
		sourcemap: mode === 'development',
		minify: mode !== 'development',
		lib: {
			entry: 'src/main.js',
			name: 'Brassage',
			formats: ['iife'],
			fileName: () => 'brassage-main.js',
		},
	},
}))
