# Changelog

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
