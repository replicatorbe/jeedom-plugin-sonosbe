<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Le protocole historique des Sonos : UPnP, c'est-à-dire du SOAP sur HTTP,
 * port 1400. Toutes les enceintes le parlent, S1 comme S2, et c'est ce
 * qu'utilisent tous les pilotes Sonos (soco pour Home Assistant). Aucune
 * dépendance : curl et DOMDocument, présents sur tout Jeedom.
 *
 * Rien ici ne connaît Jeedom : pas de commande, pas de cache. Les fonctions
 * d'analyse sont publiques et pures, pour être rejouées hors ligne sur des
 * réponses réelles (tests/fixtures).
 */
class sonosbeUpnp {

    const PORT = 1400;

    /* Service => chemin de contrôle et espace de noms. */
    const SERVICES = array(
        'AVTransport'           => array('/MediaRenderer/AVTransport/Control', 'urn:schemas-upnp-org:service:AVTransport:1'),
        'RenderingControl'      => array('/MediaRenderer/RenderingControl/Control', 'urn:schemas-upnp-org:service:RenderingControl:1'),
        'GroupRenderingControl' => array('/MediaRenderer/GroupRenderingControl/Control', 'urn:schemas-upnp-org:service:GroupRenderingControl:1'),
        'ZoneGroupTopology'     => array('/ZoneGroupTopology/Control', 'urn:schemas-upnp-org:service:ZoneGroupTopology:1'),
        'ContentDirectory'      => array('/MediaServer/ContentDirectory/Control', 'urn:schemas-upnp-org:service:ContentDirectory:1'),
        'DeviceProperties'      => array('/DeviceProperties/Control', 'urn:schemas-upnp-org:service:DeviceProperties:1'),
    );

    /* Codes d'erreur UPnP que Sonos renvoie réellement, en clair. */
    const ERRORS = array(
        401 => 'action inconnue',
        402 => 'argument invalide',
        501 => 'échec de l\'action',
        701 => 'transition impossible dans l\'état actuel',
        711 => 'mode de lecture illégal',
        714 => 'format audio non pris en charge',
        716 => 'ressource introuvable',
        800 => 'commande refusée : l\'enceinte suit un groupe',
    );

    /* =========================================================== TRANSPORT */

    /*
     * Appel d'une action ; rend les arguments de sortie, nom => texte. Une
     * erreur UPnP lève une exception qui porte son code : l'appelant peut
     * distinguer un refus (701 pendant une transition) d'une panne.
     */
    public static function call($_ip, $_service, $_action, $_args = array(), $_timeout = 4) {
        if (!isset(self::SERVICES[$_service])) {
            throw new Exception('Service UPnP inconnu : ' . $_service);
        }
        list($path, $urn) = self::SERVICES[$_service];
        $ch = curl_init('http://' . $_ip . ':' . self::PORT . $path);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => self::envelope($urn, $_action, $_args),
            CURLOPT_HTTPHEADER     => array('Content-Type: text/xml; charset="utf-8"', 'SOAPACTION: "' . $urn . '#' . $_action . '"'),
            CURLOPT_CONNECTTIMEOUT => min(3, $_timeout),
            CURLOPT_TIMEOUT        => $_timeout,
            CURLOPT_PROXY          => '',
        ));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $error !== '') {
            throw new sonosbeUnreachable(sprintf('Sonos injoignable (%s) : %s', $_ip, $error));
        }
        if ($code !== 200) {
            $fault = self::faultCode((string) $body);
            $text = isset(self::ERRORS[$fault]) ? self::ERRORS[$fault] : 'HTTP ' . $code;
            throw new sonosbeUpnpError($_action . ' : ' . $text . ($fault ? ' (UPnP ' . $fault . ')' : ''), $fault);
        }
        return self::parseResponse((string) $body, $_action);
    }

    public static function envelope($_urn, $_action, $_args) {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
             . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
             . '<s:Body><u:' . $_action . ' xmlns:u="' . $_urn . '">';
        foreach ($_args as $name => $value) {
            $xml .= '<' . $name . '>' . self::xml((string) $value) . '</' . $name . '>';
        }
        return $xml . '</u:' . $_action . '></s:Body></s:Envelope>';
    }

    public static function xml($_text) {
        return htmlspecialchars((string) $_text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /* Les arguments de sortie : les enfants de l'élément <ActionResponse>. */
    public static function parseResponse($_body, $_action) {
        $dom = self::dom($_body);
        if ($dom === null) {
            throw new Exception($_action . ' : réponse illisible');
        }
        $out = array();
        foreach ($dom->getElementsByTagNameNS('*', $_action . 'Response') as $response) {
            foreach ($response->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $out[$child->localName] = $child->textContent;
                }
            }
            break;
        }
        return $out;
    }

    public static function faultCode($_body) {
        return preg_match('#<errorCode>\s*(\d+)\s*</errorCode>#', $_body, $m) ? (int) $m[1] : 0;
    }

    /* Sans réseau ni entités externes : le XML vient d'un appareil du réseau
     * local, et le DIDL imbriqué, des services de musique. */
    private static function dom($_xml) {
        if (trim((string) $_xml) === '') {
            return null;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML((string) $_xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    /* ======================================================= IDENTIFICATION */

    public static function descriptionUrl($_ip) {
        return 'http://' . $_ip . ':' . self::PORT . '/xml/device_description.xml';
    }

    /* Ce que dit la fiche UPnP d'une enceinte ; null si ce n'est pas une
     * enceinte Sonos. */
    public static function parseDescription($_xml, $_ip) {
        $dom = self::dom($_xml);
        if ($dom === null) {
            return null;
        }
        $first = function ($_tag) use ($dom) {
            $list = $dom->getElementsByTagName($_tag);
            return $list->length > 0 ? trim($list->item(0)->textContent) : '';
        };
        $udn = preg_replace('/^uuid:/', '', $first('UDN'));
        if (strpos($udn, 'RINCON_') !== 0 || stripos($first('manufacturer'), 'sonos') === false) {
            return null;
        }
        /* L'enceinte principale d'une pièce déclare ses sous-appareils (lecteur,
         * serveur de médias) : c'est la liste des services du premier qui
         * nous intéresse, pas leurs noms. */
        return array(
            'ip'       => (string) $_ip,
            'uid'      => $udn,
            'room'     => $first('roomName'),
            'model'    => $first('modelName'),
            'model_nb' => $first('modelNumber'),
            'display'  => $first('displayName'),
            'version'  => $first('displayVersion'),
            'software' => $first('softwareVersion'),
            'swgen'    => (int) ($first('swGen') !== '' ? $first('swGen') : 1),
            'mac'      => $first('MACAddress'),
            'serial'   => $first('serialNum'),
            'ht'       => self::isHomeTheater($first('modelName')) ? 1 : 0,
        );
    }

    /* Barres de son et ampli : entrée TV, mode nuit, dialogues. Les S2 le
     * disent aussi par leurs capacités (HT_PLAYBACK) ; le nom de modèle sert
     * pour les S1, qui n'ont pas l'API locale. */
    public static function isHomeTheater($_model) {
        return (bool) preg_match('/\b(Beam|Arc|Ray|Playbar|Playbase|Amp)\b/i', (string) $_model);
    }

    /* ============================================================ TOPOLOGIE */

    /*
     * GetZoneGroupState : les groupes du foyer, chacun avec son coordinateur
     * et ses membres. Les membres invisibles (enceintes arrière, caisson
     * appairés à une barre) sont écartés : on ne les pilote pas à part.
     */
    public static function parseZoneGroupState($_xml) {
        $dom = self::dom($_xml);
        if ($dom === null) {
            return array();
        }
        $groups = array();
        foreach ($dom->getElementsByTagName('ZoneGroup') as $group) {
            $members = array();
            foreach ($group->getElementsByTagName('ZoneGroupMember') as $member) {
                /* Invisible : enceinte appairée. IsZoneBridge : Bridge ou
                 * Boost, qui relaient le réseau Sonos sans jouer de son. */
                if ($member->getAttribute('Invisible') === '1' || $member->getAttribute('IsZoneBridge') === '1') {
                    continue;
                }
                $ip = parse_url($member->getAttribute('Location'), PHP_URL_HOST);
                $members[] = array(
                    'uid'  => $member->getAttribute('UUID'),
                    'ip'   => is_string($ip) ? $ip : '',
                    'name' => $member->getAttribute('ZoneName'),
                );
            }
            if (empty($members)) {
                continue;
            }
            $groups[] = array(
                'id'          => $group->getAttribute('ID'),
                'coordinator' => $group->getAttribute('Coordinator'),
                'members'     => $members,
            );
        }
        return $groups;
    }

    /* ============================================================ MÉTADONNÉES */

    /*
     * Un document DIDL-Lite : métadonnées d'un morceau, ou liste de favoris.
     * Rend une liste d'éléments ; les champs absents valent ''.
     */
    public static function parseDidl($_xml) {
        $dom = self::dom($_xml);
        if ($dom === null) {
            return array();
        }
        $items = array();
        foreach (array('item', 'container') as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $get = function ($_name) use ($node) {
                    foreach ($node->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === $_name) {
                            return trim($child->textContent);
                        }
                    }
                    return '';
                };
                $items[] = array(
                    'id'          => $node->getAttribute('id'),
                    'title'       => $get('dc:title'),
                    'artist'      => $get('dc:creator') !== '' ? $get('dc:creator') : $get('upnp:artist'),
                    'album'       => $get('upnp:album'),
                    'art'         => $get('upnp:albumArtURI'),
                    'class'       => $get('upnp:class'),
                    'res'         => $get('res'),
                    'resmd'       => $get('r:resMD'),
                    'stream'      => $get('r:streamContent'),
                    'description' => $get('r:description'),
                );
            }
        }
        return $items;
    }

    /* D'où vient ce qui joue, d'après l'URI du transport. */
    public static function sourceOf($_uri) {
        $uri = (string) $_uri;
        if ($uri === '') {
            return 'none';
        }
        $prefixes = array(
            'x-sonos-htastream:'   => 'tv',
            'x-rincon-stream:'     => 'line_in',
            'x-rincon-queue:'      => 'queue',
            'x-rincon:'            => 'group',
            'x-sonosapi-stream:'   => 'radio',
            'x-sonosapi-radio:'    => 'radio',
            'x-rincon-mp3radio:'   => 'radio',
            'x-sonosapi-hls:'      => 'radio',
            'aac:'                 => 'radio',
            'hls-radio:'           => 'radio',
            'x-sonos-vli:'         => 'app',
            'x-sonos-vanished:'    => 'none',
        );
        foreach ($prefixes as $prefix => $source) {
            if (strpos($uri, $prefix) === 0) {
                return $source;
            }
        }
        return 'stream';
    }

    const SOURCE_NAMES = array(
        'none'    => 'Aucune',
        'tv'      => 'TV',
        'line_in' => 'Entrée ligne',
        'queue'   => 'File d\'attente',
        'group'   => 'Groupe',
        'radio'   => 'Radio',
        'app'     => 'Appli (AirPlay, Spotify…)',
        'stream'  => 'Flux',
    );

    const STATE_NAMES = array(
        'PLAYING'          => 'Lecture',
        'PAUSED_PLAYBACK'  => 'Pause',
        'STOPPED'          => 'Arrêt',
        'TRANSITIONING'    => 'Chargement',
        'NO_MEDIA_PRESENT' => 'Arrêt',
    );

    /*
     * Ce qui joue, en clair, à partir de GetMediaInfo et GetPositionInfo du
     * coordinateur. Une radio met le titre en cours dans r:streamContent
     * (« Artiste - Titre ») et le nom de la station dans le média.
     */
    public static function nowPlaying($_media, $_position, $_coordinatorIp) {
        $uri = isset($_media['CurrentURI']) ? (string) $_media['CurrentURI'] : '';
        if ($uri === '' && isset($_position['TrackURI'])) {
            $uri = (string) $_position['TrackURI'];
        }
        $source = self::sourceOf($uri);
        $track = self::parseDidl(isset($_position['TrackMetaData']) ? $_position['TrackMetaData'] : '');
        $track = empty($track) ? array() : $track[0];
        $media = self::parseDidl(isset($_media['CurrentURIMetaData']) ? $_media['CurrentURIMetaData'] : '');
        $media = empty($media) ? array() : $media[0];

        $out = array('source' => $source, 'uri' => $uri, 'title' => '', 'artist' => '', 'album' => '', 'station' => '', 'art' => '');
        if ($source === 'tv') {
            $out['title'] = 'TV';
            return $out;
        }
        if ($source === 'line_in') {
            $out['title'] = isset($media['title']) && $media['title'] !== '' ? $media['title'] : 'Entrée ligne';
            return $out;
        }
        $pick = function ($_key) use ($track) { return isset($track[$_key]) ? $track[$_key] : ''; };
        $out['title'] = $pick('title');
        $out['artist'] = $pick('artist');
        $out['album'] = $pick('album');
        $out['art'] = $pick('art');
        if ($source === 'radio') {
            $out['station'] = isset($media['title']) ? $media['title'] : '';
            $stream = $pick('stream');
            /* Certaines radios mettent des balises techniques en guise de
             * titre (« ZPSTR_CONNECTING », « TYPE=SNG|TITLE … »). */
            if ($stream !== '' && strpos($stream, 'ZPSTR_') !== 0) {
                if (preg_match('/^TYPE=SNG\|TITLE (.*?)\|ARTIST (.*?)\|/', $stream, $m)) {
                    $out['title'] = $m[1];
                    $out['artist'] = $m[2];
                } elseif (strpos($stream, ' - ') !== false) {
                    list($out['artist'], $out['title']) = array_map('trim', explode(' - ', $stream, 2));
                } else {
                    $out['title'] = $stream;
                }
            } elseif ($out['title'] === '' || strpos($out['title'], 'x-sonosapi') === 0 || $out['title'] === $out['station']) {
                $out['title'] = $out['station'];
            }
            if ($out['art'] === '' && isset($media['art'])) {
                $out['art'] = $media['art'];
            }
        }
        /* Pochette servie par l'enceinte elle-même : chemin relatif. */
        if ($out['art'] !== '' && $out['art'][0] === '/') {
            $out['art'] = 'http://' . $_coordinatorIp . ':' . self::PORT . $out['art'];
        }
        return $out;
    }

    /* « 0:03:25 » => 205. */
    public static function hmsToSeconds($_hms) {
        if (!preg_match('/^(\d+):(\d{1,2}):(\d{1,2})/', (string) $_hms, $m)) {
            return 0;
        }
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    public static function secondsToHms($_seconds) {
        $s = max(0, (int) $_seconds);
        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    /*
     * Un favori se joue de deux façons. Une radio ou un flux se charge
     * directement dans le transport ; une liste de lecture ou un album (un
     * conteneur) doit passer par la file d'attente.
     */
    public static function favoriteIsContainer($_favorite) {
        $res = (string) $_favorite['res'];
        foreach (array('x-rincon-cpcontainer:', 'x-rincon-playlist:', 'file:///jffs/settings/savedqueues.rsq') as $prefix) {
            if (strpos($res, $prefix) === 0) {
                return true;
            }
        }
        $meta = self::parseDidl($_favorite['resmd']);
        return !empty($meta) && strpos($meta[0]['class'], 'object.container') === 0;
    }

    /* DIDL minimal pour jouer un fichier audio par son URL. */
    public static function didlForUrl($_url, $_title, $_mime = 'audio/mpeg') {
        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/"'
             . ' xmlns:r="urn:schemas-rinconnetworks-com:metadata-1-0/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">'
             . '<item id="jeedom" parentID="-1" restricted="true"><dc:title>' . self::xml($_title) . '</dc:title>'
             . '<upnp:class>object.item.audioItem.musicTrack</upnp:class>'
             . '<res protocolInfo="http-get:*:' . self::xml($_mime) . ':*">' . self::xml($_url) . '</res></item></DIDL-Lite>';
    }
}

/* L'enceinte n'a pas répondu du tout : panne réseau, et non refus. */
class sonosbeUnreachable extends Exception {
}

/* L'enceinte a répondu par une erreur UPnP, dont le code est celui de
 * l'exception. */
class sonosbeUpnpError extends Exception {
}
