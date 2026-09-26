# Plugin Sonos

Pilote les enceintes Sonos depuis Jeedom, en réseau local, sans compte Sonos,
sans cloud et sans paquet à installer. Le plugin sait aussi **faire parler
les enceintes** : annonces écrites dans un scénario, dites par une voix de
synthèse, et messages vocaux enregistrés au micro depuis le dashboard.

## Ce que fait le plugin

| Domaine | Commandes |
|---|---|
| Lecture | Lecture, Pause, Stop, Précédent, Suivant, Lecture ou pause |
| Volume | Régler le volume, Volume +, Volume −, Couper le son, Rétablir le son |
| Musique | Jouer un favori (vos favoris Sonos), Jouer un son (URL ou fichier) |
| Groupes | Rejoindre le groupe de…, Quitter le groupe |
| Réglages | Graves, Aigus, Loudness, Minuterie de veille |
| Barres de son | Passer sur la TV, Mode nuit, Dialogues renforcés, info **TV en cours** |
| Voix | **Annonce** (texte), **Message vocal** (micro), Rejouer la dernière annonce, Préparer une annonce |

Informations relevées chaque minute, et juste après chaque ordre : statut,
titre, artiste, album, station de radio, pochette, source (TV, radio, file
d'attente, appli…), volume, muet, groupe, minuterie, réglages.

## Installation

1. Activez le plugin.
2. Sur sa page, cliquez sur **Rechercher des Sonos**. La recherche démarre
   aussitôt dans un panneau de la page, avec un compteur : le plugin écoute
   les annonces du réseau (SSDP) et interroge toutes les adresses du
   sous-réseau de Jeedom ; la première enceinte trouvée donne ensuite la
   liste complète du foyer. Comptez 5 à 10 secondes. Le bilan dit ce qui a
   été interrogé ; si vos enceintes sont sur un autre sous-réseau (VLAN,
   Wi-Fi invité), saisissez-le dans le panneau. Vous pouvez aussi **ajouter
   une enceinte par son adresse IP**.
3. Cochez les enceintes à créer, puis **Créer les enceintes cochées**. Les enceintes appairées (arrières, caisson,
   seconde enceinte d'une paire stéréo) ne sont pas proposées : elles se
   pilotent par leur enceinte principale.

Une enceinte est reconnue par son identifiant Sonos, pas par son adresse : si
son adresse IP change, le plugin la corrige seul.

## Les annonces

### Configurer la voix

Dans la configuration du plugin, choisissez le moteur :

- **Piper** : synthèse vocale locale, gratuite, qui fonctionne sans Internet.
  Choisissez une voix (Siwis, Tom, Jessica, Pierre, Gilles) puis cliquez sur
  **Télécharger Piper et cette voix** : le programme (environ 50 Mo) et la voix
  (environ 65 Mo) sont téléchargés dans le dossier du plugin, sans apt ni pip.
  Une phrase se fabrique en moins d'une seconde sur un PC, en quelques
  secondes sur un Raspberry Pi.
- **OpenAI** : voix plus expressives, payantes à l'usage, qui demandent une
  clé API. Le modèle `gpt-4o-mini-tts` accepte une consigne de ton (« calme et
  chaleureux », « enjoué »…).

Avec **Moteur de secours** coché, l'autre moteur prend le relais si le premier
échoue. Une phrase déjà dite est gardée en cache : elle ne se refabrique pas.
Le bloc **Essai** fait écouter la voix dans le navigateur.

### Dans un scénario

Commande **Annonce** de l'enceinte :

- **Message** : le texte à dire. Les tags de scénario y sont remplacés comme
  partout ailleurs (`Il fait #[Extérieur][Météo][Température]# degrés`) ;
- **Titre** : vide, l'annonce prend les réglages de l'équipement. Sinon, un
  volume et des mots-clés, dans n'importe quel ordre :

| Titre | Effet |
|---|---|
| `40` ou `40 %` | volume de cette annonce |
| `carillon` | un « ding-dong » avant le message |
| `sans-carillon` | pas de carillon, même s'il est coché sur l'équipement |
| `urgent` | passe outre la plage de nuit : volume normal, jamais retenue |

Exemple pour une alarme : titre `70 carillon urgent`, message `Fumée détectée
dans la cuisine`.

Commande **Jouer un son** : même principe, avec dans le message une URL
`http://…` ou le chemin d'un fichier audio de la machine Jeedom.

**Rejouer la dernière annonce** relit le dernier message, sans refabriquer la
voix : pratique quand on n'a pas bien entendu, ou pour un message laissé au
micro.

**Préparer une annonce** fabrique la voix d'un texte sans la jouer. Placée au
démarrage de Jeedom ou dans un scénario de nuit, elle rend instantanées les
annonces fixes (« Quelqu'un sonne à la porte »), même sur un Raspberry Pi.

### Le carillon

Coché sur l'équipement, un « ding-dong » précède chaque annonce, pour
attirer l'attention avant les premiers mots. Il est calculé par le plugin :
aucun fichier à fournir, et le même son sur toutes les enceintes.

### Toutes les enceintes

Le bouton **Toutes les enceintes** de la page du plugin crée un équipement
qui envoie ses annonces, sons et messages vocaux à chaque enceinte active.
La voix n'est fabriquée qu'une fois ; chaque enceinte la joue à son volume,
avec son carillon. Un titre avec volume s'applique à toutes.

### La plage de nuit

Dans la configuration du plugin : de telle heure à telle heure, les
annonces passent au **volume de nuit**, qui sert de plafond. En cochant
**Retenir les annonces des scénarios**, les scénarios se taisent la nuit. Ce
que quelqu'un déclenche lui-même (micro, bouton du dashboard, « Rejouer »)
passe toujours, au volume de nuit. Une annonce `urgent` ignore la plage.

### Par-dessus le son, ou en interrompant

Sur les enceintes **Sonos S2**, le plugin passe par l'API locale des
enceintes, comme Home Assistant : la musique **ou la TV** baissent, le
message passe, puis tout reprend exactement où il en était, sans que le
plugin ait à s'en occuper.

Sur les enceintes **S1**, qui n'ont pas cette API, le plugin note ce qui
joue (source, morceau, position, volume), passe le message, puis rétablit le
tout. La commande rend la main à la fin du message.

Le réglage **Méthode** de l'équipement permet de forcer l'une ou l'autre.

### Le message vocal au micro

La commande **Message vocal** affiche un bouton micro sur le dashboard : un
clic pour enregistrer, un second pour envoyer. Le message est joué comme une
annonce, au volume des annonces. Le même bouton se trouve sur la page de
l'équipement.

Le navigateur n'autorise le micro que sur une page en **HTTPS** : ouvrez
Jeedom par son adresse `https://` pour enregistrer. L'enregistrement est
converti en MP3 par ffmpeg, s'il est présent (c'est le cas sur la plupart des
installations Jeedom) ; sans ffmpeg, seul Safari enregistre dans un format
que les Sonos lisent.

### Adresse de Jeedom pour les enceintes

L'enceinte vient chercher chaque message sur Jeedom, en HTTP, à l'adresse
interne configurée dans Jeedom. Le bouton **Tester l'accès des enceintes**,
dans la configuration du plugin, fait jouer un court carillon à chaque
enceinte et vérifie qu'elle est bien venue le chercher : c'est la première
chose à faire quand une annonce reste muette. Si elle ne convient pas (Jeedom en Docker, en
HTTPS seulement…), renseignez **Adresse de Jeedom pour les enceintes** dans
la configuration du plugin, en `http://`. Les fichiers audio portent des noms
aléatoires ; les messages enregistrés sont effacés au bout d'un jour, le cache
des synthèses est limité à 150 Mo.

## Moteur de synthèse vocale de Jeedom

Le plugin peut fabriquer la voix de tout Jeedom : **Réglages → Système →
Configuration → onglet Général, TTS**, choisissez **Plugin Sonos**. Les autres
plugins qui utilisent la synthèse vocale de Jeedom profitent alors de Piper
ou d'OpenAI.

## Favoris et groupes

**Jouer un favori** propose vos favoris Sonos (radios, listes de lecture,
albums), relus toutes les heures et à chaque clic sur **Rafraîchir**. Dans un
scénario, le nom du favori suffit, sans respecter la casse.

**TV en cours** vaut 1 quand la barre de son joue le son de la TV : la
condition utile pour baisser les volets, tamiser la lumière ou ne pas parler
pendant un film.

**Rejoindre le groupe de** met l'enceinte dans le groupe d'une autre ; les
ordres de lecture d'une enceinte groupée vont au coordinateur du groupe,
comme dans l'application Sonos. **Passer sur la TV** fait d'abord quitter son
groupe à la barre de son.

## En cas de problème

- **Page Santé** de Jeedom : enceintes joignables, état du moteur de voix.
- **Onglet Diagnostic** de l'équipement : le dernier relevé et les possibilités
  de l'enceinte (annonces par-dessus le son ou non, barre de son).
- **Une annonce n'est pas entendue** : **Tester l'accès des enceintes**, dans
  la configuration du plugin. Vérifiez aussi la plage de nuit.
- Journal **sonosbe** en mode Debug pour le détail de chaque échange.
