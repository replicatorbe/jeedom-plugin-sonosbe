# Changelog

## 0.2.1 — 26/09/2026

- Recherche des enceintes : elle ne partait pas quand on validait le champ
  « sous-réseau » vide (la fenêtre de Jeedom le traite comme Annuler). Elle
  démarre désormais dès le clic, dans un panneau de la page, avec un
  compteur, puis un bilan de ce qui a été interrogé (sous-réseau, nombre
  d'adresses, réponses SSDP, durée). Autre sous-réseau et création des
  enceintes cochées se font dans ce même panneau.
- Annonces SSDP envoyées trois fois : une enceinte en veille rate parfois
  la première.

## 0.2.0 — 26/09/2026

- Carillon avant les annonces : réglage par enceinte, ou mot-clé
  `carillon` / `sans-carillon` dans le titre.
- Titre des commandes d'annonce : volume et mots-clés (`40 carillon urgent`).
- Équipement « Toutes les enceintes » : une annonce, un son ou un message
  vocal sur chaque enceinte active, voix fabriquée une seule fois.
- Plage de nuit : volume plafonné, annonces des scénarios retenues si on le
  souhaite ; `urgent` passe toujours.
- Commandes « Rejouer la dernière annonce » et « Préparer une annonce ».
- Info « TV en cours » sur les barres de son.
- Bouton « Tester l'accès des enceintes » : chaque enceinte joue un
  carillon, le plugin vérifie qu'elle est venue le chercher.
- Les fichiers audio sont servis par un script du plugin : le .htaccess de
  Jeedom refusait les WAV (voix sans ffmpeg, carillon) et les M4A.

## 0.1.1 — 26/09/2026

- Recherche SSDP : les réponses des enceintes étaient toutes rejetées.
- Une enceinte seule qui change d'adresse est retrouvée par une recherche
  horaire sur le réseau.
- Annonce avec interruption : la lecture et le volume sont rétablis même
  si le message échoue en route ; rien ne rejoue l'annonce ensuite.
- Deux annonces rapprochées se suivent au lieu de se couper.
- Une enceinte éteinte n'est plus déclarée joignable parce que le
  coordinateur de son groupe répond.
- Un ordre réussi n'est plus signalé en échec quand le relevé qui suit
  échoue.
- Les Bridge et Boost ne sont plus proposés comme enceintes.
- Rejoindre un groupe dont on fait déjà partie ne le casse plus.
- Volume d'annonce accepté sous la forme « 30 % ».
- Micro : un double clic n'ouvre plus deux enregistrements.

## 0.1.0 — 26/09/2026

Première version.

- Découverte des enceintes par SSDP, balayage du sous-réseau et topologie du
  foyer ; ajout par adresse IP ; adresse corrigée seule si elle change.
- Lecture, volume, muet, favoris Sonos, groupes, minuterie, graves, aigus,
  loudness ; entrée TV, mode nuit et dialogues pour les barres de son.
- Relevé chaque minute : statut, titre, artiste, album, station, pochette,
  source, groupe.
- Annonces par synthèse vocale, Piper (local) ou OpenAI, par-dessus la
  musique ou la TV sur les Sonos S2, avec interruption et reprise sur les S1.
- Message vocal enregistré au micro depuis le dashboard.
- Moteur de synthèse vocale pour tout Jeedom.
