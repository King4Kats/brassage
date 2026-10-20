"""
Tests de bout en bout de l'API Brassage (et des routes RoomVox qu'on utilise).

Ils parlent à un VRAI Nextcloud qui tourne (par défaut http://localhost:8098,
le conteneur jetable lancé par scripts/tester-api.sh), avec les comptes de la
démo. On vérifie surtout les DROITS : qui peut faire quoi.

Pourquoi ces tests comptent : ils passent par RoomVoxPasserelle.php, donc par
les classes INTERNES de RoomVox. Si une mise à jour de RoomVox les change, ce
sont ces tests qui le diront (voir docs/journal-de-bord.md, D3).

Uniquement la bibliothèque standard de Python (unittest, urllib) : rien à
installer. Lancement : ./scripts/tester-api.sh

Les tests supposent une démo FRAÎCHE (tester-api.sh s'en charge). La plupart
remettent les données dans leur état d'origine ; deux changent la démo pour
de bon (nettoyage du Fermenteur 3, validation de la demande de Sofia) : ils
vérifient l'état « avant » au début, et rien d'autre ne dépend de cet état.
"""

import base64
import json
import os
import unittest
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

URL = os.environ.get('BRASSAGE_URL', 'http://localhost:8098').rstrip('/')
MOT_DE_PASSE = 'brassage2026'  # comptes de DÉMO uniquement (voir DemoSeed.php)


def appel(utilisateur, methode, chemin, corps=None):
    """
    Une requête HTTP vers Nextcloud. Renvoie (code HTTP, JSON de la réponse).

    - utilisateur=None → requête anonyme ;
    - l'en-tête OCS-APIRequest + l'authentification « basic » dispensent du
      jeton CSRF (c'est le mode prévu pour les clients d'API) ;
    - ensure_ascii=True : les accents sont envoyés en \\u00e9, toujours valide.
    """
    entetes = {'OCS-APIRequest': 'true', 'Accept': 'application/json'}
    if utilisateur:
        jeton = base64.b64encode(f'{utilisateur}:{MOT_DE_PASSE}'.encode()).decode()
        entetes['Authorization'] = f'Basic {jeton}'
    donnees = None
    if corps is not None:
        donnees = json.dumps(corps, ensure_ascii=True).encode()
        entetes['Content-Type'] = 'application/json'

    requete = urllib.request.Request(f'{URL}/index.php{chemin}', data=donnees, method=methode, headers=entetes)
    try:
        with urllib.request.urlopen(requete, timeout=30) as reponse:
            return reponse.status, json.loads(reponse.read() or b'{}')
    except urllib.error.HTTPError as erreur:
        contenu = erreur.read()
        try:
            return erreur.code, json.loads(contenu or b'{}')
        except json.JSONDecodeError:
            return erreur.code, {'brut': contenu[:200].decode(errors='replace')}


def api(utilisateur, methode, chemin, corps=None):
    """Raccourci pour NOTRE API (/apps/brassage/api/…)."""
    return appel(utilisateur, methode, f'/apps/brassage/api{chemin}', corps)


def roomvox(utilisateur, methode, chemin, corps=None):
    """Raccourci pour l'API de RoomVox (/apps/roomvox/api/…)."""
    return appel(utilisateur, methode, f'/apps/roomvox/api{chemin}', corps)


def atelier(utilisateur):
    """L'atelier vu par quelqu'un, sous forme de dictionnaire roomId → équipement."""
    code, donnees = api(utilisateur, 'GET', '/atelier')
    assert code == 200, (code, donnees)
    return {e['roomId']: e for e in donnees['equipements']}


def iso(date):
    return date.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


class T1Atelier(unittest.TestCase):
    """Lecture de l'atelier et rôles."""

    def test_anonyme_refuse(self):
        code, _ = api(None, 'GET', '/atelier')
        self.assertEqual(code, 401)

    def test_roles_par_groupe(self):
        attendus = {'marc': 'viewer', 'julien': 'booker', 'claire': 'manager', 'admin': 'manager'}
        for utilisateur, role in attendus.items():
            with self.subTest(utilisateur=utilisateur):
                equipements = atelier(utilisateur)
                self.assertEqual(len(equipements), 6)
                self.assertEqual({e['droits']['role'] for e in equipements.values()}, {role})

    def test_sans_groupe_ne_voit_rien(self):
        # « intrus » est créé par tester-api.sh, sans aucun groupe.
        self.assertEqual(atelier('intrus'), {})

    def test_exemple_40_pourcent_60_jours(self):
        garde = atelier('marc')['cuve-de-garde']['enCours']['avancement']
        self.assertAlmostEqual(garde['pourcentage'], 40, delta=1)
        self.assertIn(garde['joursRestants'], (60, 61))  # 61 si un changement d'heure tombe dans la période

    def test_contenu_et_derniere_temperature(self):
        f2 = atelier('marc')['fermenteur-2']['enCours']
        self.assertEqual(f2['brassin']['nom'], 'Ambrée de la Vie')
        self.assertEqual(f2['derniereTemperature']['valeur'], 4)

    def test_reservation_en_attente(self):
        prochaine = atelier('marc')['cuve-de-brassage']['prochaine']
        self.assertEqual(prochaine['statut'], 'en_attente')


class T2Nettoyage(unittest.TestCase):

    def test_droits_puis_nettoyage(self):
        self.assertEqual(atelier('marc')['fermenteur-3']['nettoyage']['statut'], 'a_nettoyer')
        self.assertEqual(api('marc', 'POST', '/equipements/fermenteur-3/nettoyage')[0], 403)
        # Quelqu'un qui ne voit pas la machine reçoit 404, pas 403 (on ne révèle rien).
        self.assertEqual(api('intrus', 'POST', '/equipements/fermenteur-3/nettoyage')[0], 404)
        self.assertEqual(api('julien', 'POST', '/equipements/fermenteur-3/nettoyage')[0], 200)
        self.assertEqual(atelier('marc')['fermenteur-3']['nettoyage']['statut'], 'propre')


class T3Brassin(unittest.TestCase):
    """Le contenu d'une réservation : organisateur ou responsable seulement."""

    def setUp(self):
        resa = atelier('sofia')['fermenteur-1']['enCours']
        self.original = {k: resa['brassin'][k] for k in ('nom', 'style', 'couleur', 'volumeL', 'notes')}
        # « id » : notre identifiant de réservation (voir docs/04-api.md).
        self.chemin = f'/equipements/fermenteur-1/reservations/{urllib.parse.quote(resa["id"], safe="")}/brassin'

    def tearDown(self):
        api('sofia', 'PUT', self.chemin, self.original)

    def test_qui_peut_modifier(self):
        modif = dict(self.original, notes='test')
        self.assertEqual(api('marc', 'PUT', self.chemin, modif)[0], 403)    # simple membre
        self.assertEqual(api('julien', 'PUT', self.chemin, modif)[0], 403)  # brasseur, mais pas l'organisatrice
        self.assertEqual(api('sofia', 'PUT', self.chemin, modif)[0], 200)   # l'organisatrice
        self.assertEqual(api('claire', 'PUT', self.chemin, modif)[0], 200)  # responsable

    def test_validation(self):
        self.assertEqual(api('sofia', 'PUT', self.chemin, dict(self.original, nom='  '))[0], 400)
        self.assertEqual(api('sofia', 'PUT', self.chemin, dict(self.original, couleur='violette'))[0], 400)
        self.assertEqual(api('sofia', 'PUT', self.chemin, dict(self.original, volumeL=0))[0], 400)

    def test_reservation_inconnue(self):
        code, _ = api('claire', 'PUT', '/equipements/fermenteur-1/reservations/nexiste-pas/brassin', self.original)
        self.assertEqual(code, 404)


class T4Journal(unittest.TestCase):

    def setUp(self):
        self.brassin = atelier('marc')['fermenteur-1']['enCours']['brassin']['id']
        self.chemin = f'/brassins/{self.brassin}/journal'

    def test_lecture_par_un_membre(self):
        code, entrees = api('marc', 'GET', self.chemin)
        self.assertEqual(code, 200)
        self.assertGreaterEqual(len([e for e in entrees if e['type'] == 'densite']), 4)

    def test_ajout_validation_suppression(self):
        self.assertEqual(api('marc', 'POST', self.chemin, {'type': 'note', 'texte': 'x'})[0], 403)
        # 1048 au lieu de 1.048 : la faute de frappe classique, refusée.
        self.assertEqual(api('julien', 'POST', self.chemin, {'type': 'densite', 'valeur': 1048})[0], 400)
        self.assertEqual(api('julien', 'POST', self.chemin, {'type': 'note', 'texte': '  '})[0], 400)
        self.assertEqual(api('julien', 'POST', self.chemin, {'type': 'humeur', 'texte': 'ok'})[0], 400)

        code, entree = api('julien', 'POST', self.chemin, {'type': 'densite', 'valeur': 1.009, 'texte': 'test'})
        self.assertEqual(code, 201)
        # Seul·e l'auteur·rice (ou un·e responsable) peut supprimer.
        self.assertEqual(api('sofia', 'DELETE', f"{self.chemin}/{entree['id']}")[0], 403)
        self.assertEqual(api('julien', 'DELETE', f"{self.chemin}/{entree['id']}")[0], 200)

        code, entree = api('julien', 'POST', self.chemin, {'type': 'temperature', 'valeur': 18.5})
        self.assertEqual(code, 201)
        self.assertEqual(api('claire', 'DELETE', f"{self.chemin}/{entree['id']}")[0], 200)

    def test_entree_d_un_autre_brassin(self):
        # Supprimer l'entrée d'un brassin en passant par l'URL d'un autre → 404.
        autre = atelier('marc')['fermenteur-2']['enCours']['brassin']['id']
        code, entrees = api('marc', 'GET', f'/brassins/{autre}/journal')
        self.assertEqual(api('claire', 'DELETE', f"{self.chemin}/{entrees[0]['id']}")[0], 404)


class T5Reglages(unittest.TestCase):

    def tearDown(self):
        api('claire', 'PUT', '/equipements/fermenteur-2', {'nom': 'Fermenteur 2', 'type': 'fermenteur', 'capaciteL': 120})

    def test_reglages(self):
        corps = {'nom': 'Fermenteur 2 (test)', 'type': 'garde', 'capaciteL': 150}
        self.assertEqual(api('julien', 'PUT', '/equipements/fermenteur-2', corps)[0], 403)
        self.assertEqual(api('claire', 'PUT', '/equipements/fermenteur-2', dict(corps, type='banane'))[0], 400)
        self.assertEqual(api('claire', 'PUT', '/equipements/fermenteur-2', corps)[0], 200)
        f2 = atelier('marc')['fermenteur-2']
        # Le nom est changé CHEZ ROOMVOX (passerelle), le type chez nous.
        self.assertEqual((f2['nom'], f2['type'], f2['capaciteL']), ('Fermenteur 2 (test)', 'garde', 150))


class T6Etapes(unittest.TestCase):

    def tearDown(self):
        api('claire', 'DELETE', '/equipements/alambic/etapes')

    def test_droits_et_validation(self):
        bonnes = [{'nom': 'Chauffe', 'part': 1}, {'nom': 'Distillation', 'part': 3}]
        self.assertEqual(api('julien', 'PUT', '/equipements/alambic/etapes', {'etapes': bonnes})[0], 403)
        for mauvaises in ([], [{'nom': 'X', 'part': 0}], [{'nom': '', 'part': 1}],
                          [{'nom': 'X', 'part': 2.5}], [{'nom': f'E{i}', 'part': 1} for i in range(13)]):
            with self.subTest(etapes=mauvaises):
                self.assertEqual(api('claire', 'PUT', '/equipements/alambic/etapes', {'etapes': mauvaises})[0], 400)

    def test_personnaliser_puis_revenir(self):
        bonnes = [{'nom': 'Chauffe', 'part': 1}, {'nom': 'Distillation', 'part': 3}]
        self.assertEqual(api('claire', 'PUT', '/equipements/alambic/etapes', {'etapes': bonnes})[0], 200)
        alambic = atelier('marc')['alambic']
        self.assertTrue(alambic['etapesPersonnalisees'])
        # La réservation EN COURS utilise tout de suite les nouvelles étapes.
        self.assertEqual([e['nom'] for e in alambic['enCours']['avancement']['etapes']], ['Chauffe', 'Distillation'])

        self.assertEqual(api('claire', 'DELETE', '/equipements/alambic/etapes')[0], 200)
        alambic = atelier('marc')['alambic']
        self.assertFalse(alambic['etapesPersonnalisees'])
        self.assertEqual(len(alambic['etapes']), 5)


class T7Reservation(unittest.TestCase):
    """Réserver passe par l'API de RoomVox, puis on accroche le contenu chez nous."""

    def test_reserver_decrire_annuler(self):
        maintenant = datetime.now(timezone.utc)
        # Créneau qui chevauche la distillation en cours → refusé par RoomVox.
        conflit = {'summary': 'Conflit', 'start': iso(maintenant), 'end': iso(maintenant + timedelta(hours=1))}
        self.assertEqual(roomvox('julien', 'POST', '/rooms/alambic/bookings', conflit)[0], 409)
        # Un simple membre ne peut pas réserver.
        libre_debut = (maintenant + timedelta(days=20)).replace(hour=9, minute=0, second=0, microsecond=0)
        libre = {'summary': 'Test API', 'start': iso(libre_debut), 'end': iso(libre_debut + timedelta(hours=8))}
        self.assertEqual(roomvox('marc', 'POST', '/rooms/alambic/bookings', libre)[0], 403)

        code, reponse = roomvox('julien', 'POST', '/rooms/alambic/bookings', libre)
        self.assertEqual(code, 201, reponse)
        uid = reponse['uid']
        chemin = f'/equipements/alambic/reservations/{urllib.parse.quote(uid, safe="")}/brassin'
        try:
            brassin = {'nom': 'Test API', 'couleur': 'distillat', 'volumeL': 30}
            self.assertEqual(api('julien', 'PUT', chemin, brassin)[0], 200)   # l'organisateur
            self.assertEqual(api('sofia', 'PUT', chemin, brassin)[0], 403)    # une autre brasseuse
        finally:
            # Annulation par une responsable (voir le journal de bord, P16 :
            # RoomVox ne reconnaît pas l'organisateur pour l'annulation).
            self.assertEqual(roomvox('claire', 'DELETE', f'/rooms/alambic/bookings/{urllib.parse.quote(uid, safe="")}')[0], 200)
        # Réservation annulée → on ne peut plus y accrocher de contenu.
        self.assertEqual(api('julien', 'PUT', chemin, {'nom': 'Trop tard'})[0], 404)

    def test_validation_d_une_demande(self):
        demande = atelier('claire')['cuve-de-brassage']['prochaine']
        # Pour RoomVox, on utilise l'UID calendrier brut.
        chemin = f"/rooms/cuve-de-brassage/bookings/{urllib.parse.quote(demande['uidCalendrier'], safe='')}/respond"
        self.assertEqual(roomvox('julien', 'POST', chemin, {'action': 'accept'})[0], 403)
        self.assertEqual(roomvox('claire', 'POST', chemin, {'action': 'accept'})[0], 200)
        self.assertEqual(atelier('marc')['cuve-de-brassage']['prochaine']['statut'], 'confirmee')


class T8Creneau(unittest.TestCase):
    """
    Horaires d'ouverture et délai maximum (réglés dans RoomVox), vérifiés par
    NOTRE route avant de réserver : l'API de RoomVox ne le fait pas (D13).
    """

    def setUp(self):
        # Alambic : ouvert du lundi au vendredi de 8 h à 18 h, 30 jours à l'avance maximum.
        regles = {'enabled': True, 'rules': [{'days': [1, 2, 3, 4, 5], 'startTime': '08:00', 'endTime': '18:00'}]}
        code, reponse = roomvox('admin', 'PUT', '/rooms/alambic', {'availabilityRules': regles, 'maxBookingHorizon': 30})
        self.assertEqual(code, 200, reponse)

    def tearDown(self):
        roomvox('admin', 'PUT', '/rooms/alambic', {'availabilityRules': {'enabled': False, 'rules': []}, 'maxBookingHorizon': 0})

    def creneau(self, utilisateur, debut, fin):
        requete = urllib.parse.urlencode({'debut': debut.isoformat(), 'fin': fin.isoformat()})
        return api(utilisateur, 'GET', f'/equipements/alambic/creneau?{requete}')

    @staticmethod
    def prochain(jour_semaine, dans_jours_min=7):
        """
        Le prochain jour donné (0 = lundi, 5 = samedi), à 10:00 UTC.
        10:00 UTC = 11:00 ou 12:00 à Paris selon l'heure d'hiver ou d'été :
        dans les deux cas bien à l'intérieur de la plage 8 h – 18 h, donc le
        test ne dépend pas du changement d'heure.
        """
        d = datetime.now(timezone.utc) + timedelta(days=dans_jours_min)
        while d.weekday() != jour_semaine:
            d += timedelta(days=1)
        return d.replace(hour=10, minute=0, second=0, microsecond=0)

    def test_dans_les_horaires(self):
        mardi = self.prochain(1)
        code, reponse = self.creneau('julien', mardi, mardi + timedelta(hours=4))
        self.assertEqual((code, reponse.get('ok')), (200, True), reponse)

    def test_samedi_refuse(self):
        samedi = self.prochain(5)
        code, reponse = self.creneau('julien', samedi, samedi + timedelta(hours=2))
        self.assertEqual((code, reponse.get('ok')), (200, False))
        self.assertIn('horaires', reponse['raison'])

    def test_trop_loin_refuse(self):
        mardi = self.prochain(1, dans_jours_min=45)
        code, reponse = self.creneau('julien', mardi, mardi + timedelta(hours=2))
        self.assertEqual((code, reponse.get('ok')), (200, False))
        self.assertIn('30 jours', reponse['raison'])

    def test_droits_et_parametres(self):
        mardi = self.prochain(1)
        self.assertEqual(self.creneau('marc', mardi, mardi + timedelta(hours=1))[0], 403)
        self.assertEqual(api('julien', 'GET', '/equipements/alambic/creneau')[0], 400)
        self.assertEqual(api('julien', 'GET', '/equipements/alambic/creneau?debut=n%27importe&fin=quoi')[0], 400)


if __name__ == '__main__':
    unittest.main(verbosity=2)
