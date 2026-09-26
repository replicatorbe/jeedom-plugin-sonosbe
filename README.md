# Plugin Sonos pour Jeedom

Pilote les enceintes Sonos en réseau local, sans cloud et sans dépendance, et
les fait parler : annonces par synthèse vocale (Piper en local, ou OpenAI)
jouées par-dessus la musique ou la TV, et messages vocaux enregistrés au micro
depuis le dashboard.

- Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md)
- Changements : [docs/fr_FR/changelog.md](docs/fr_FR/changelog.md)

## Fonctionnement

| Fichier | Rôle |
|---|---|
| `core/class/sonosbe.class.php` | Équipements, commandes, relevé, annonces |
| `core/class/sonosbeUpnp.class.php` | Protocole UPnP (port 1400), toutes les enceintes |
| `core/class/sonosbeWs.class.php` | API locale des S2 (websocket, port 1443), pour les annonces |
| `core/class/sonosbeVoice.class.php` | Synthèse vocale, messages du micro, fichiers audio |
| `desktop/js/sonosbeMic.js` | Enregistrement au micro, partagé par le widget et la page |
| `resources/install-piper.sh` | Téléchargement de Piper et d'une voix |

## Tests

```bash
php tests/run.php            # rejeu hors ligne sur des réponses réelles d'une Sonos Beam
php tests/check-classes.php  # pièges du coeur de Jeedom, contre l'installation locale
```

## Licence

AGPL.
