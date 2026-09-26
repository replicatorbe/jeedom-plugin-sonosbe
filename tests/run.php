<?php
/* Rejeu hors ligne des protocoles et du traitement audio.
 *
 *   php tests/run.php
 *
 * Les fixtures de tests/fixtures/ sont des réponses réelles d'une Sonos Beam
 * (S2, version 18.8), identifiants masqués. Les métadonnées de morceaux et
 * de radios sont écrites d'après le format que les enceintes renvoient :
 * chaque valeur douteuse rencontrée en production doit y laisser un cas. */

date_default_timezone_set('Europe/Brussels');

/* Le strict nécessaire du coeur pour les classes annexes. */
function __($_text, $_file = null) { return $_text; }
class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        return isset(self::$values[$_plugin . '::' . $_key]) ? self::$values[$_plugin . '::' . $_key] : $_default;
    }
    public static function save($_key, $_value, $_plugin = 'core') { self::$values[$_plugin . '::' . $_key] = $_value; }
}
class log {
    public static function add($_plugin, $_level, $_message) {}
}

require_once __DIR__ . '/../core/class/sonosbeUpnp.class.php';
require_once __DIR__ . '/../core/class/sonosbeWs.class.php';
require_once __DIR__ . '/../core/class/sonosbeVoice.class.php';

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-58s %s\n", $_label, str_replace("\n", ' ', var_export($_actual, true)));
    } else {
        $failed++;
        printf("  ECHEC %-58s obtenu %s, attendu %s\n", $_label,
            str_replace("\n", ' ', var_export($_actual, true)), str_replace("\n", ' ', var_export($_expected, true)));
    }
}

function section($_title) {
    echo "\n" . $_title . "\n";
}

function fixture($_name) {
    return file_get_contents(__DIR__ . '/fixtures/' . $_name);
}

function soap($_body, $_action) {
    return sonosbeUpnp::parseResponse($_body, $_action);
}

/* ------------------------------------------------------------------ SOAP */
section('Réponses SOAP réelles');
check('GetVolume', soap(fixture('vol.xml'), 'GetVolume'), array('CurrentVolume' => '42'));
check('GetEQ NightMode', soap(fixture('night.xml'), 'GetEQ'), array('CurrentValue' => '0'));
$media = soap(fixture('media.xml'), 'GetMediaInfo');
check('GetMediaInfo à l\'arrêt : URI vide', $media['CurrentURI'], '');
check('GetMediaInfo : PlayMedium', $media['PlayMedium'], 'NONE');
check('Erreur UPnP 402 lue', sonosbeUpnp::faultCode(fixture('err.xml')), 402);
check('Réponse d\'une autre action : rien', soap(fixture('vol.xml'), 'GetMute'), array());
$envelope = sonosbeUpnp::envelope('urn:x', 'SetAVTransportURI', array('CurrentURI' => 'a&b<c>', 'CurrentURIMetaData' => '<DIDL-Lite/>'));
check('Enveloppe : arguments échappés', strpos($envelope, '<CurrentURI>a&amp;b&lt;c&gt;</CurrentURI>') !== false, true);
check('Enveloppe : DIDL échappé', strpos($envelope, '<CurrentURIMetaData>&lt;DIDL-Lite/&gt;</CurrentURIMetaData>') !== false, true);

/* ----------------------------------------------------------- DESCRIPTION */
section('Fiche UPnP');
$device = sonosbeUpnp::parseDescription(fixture('description_beam.xml'), '192.168.1.50');
check('identifiant', $device['uid'], 'RINCON_000E58A1B2C301400');
check('pièce', $device['room'], 'Salon');
check('modèle', $device['model'], 'Sonos Beam');
check('nom court', $device['display'], 'Beam');
check('génération', $device['swgen'], 2);
check('barre de son', $device['ht'], 1);
check('Un autre appareil UPnP n\'est pas un Sonos', sonosbeUpnp::parseDescription('<root><device><manufacturer>Acme</manufacturer><UDN>uuid:1234</UDN></device></root>', '1.2.3.4'), null);
check('XML invalide', sonosbeUpnp::parseDescription('<html>', '1.2.3.4'), null);
check('Five : pas une barre', sonosbeUpnp::isHomeTheater('Sonos Five'), false);
check('Arc : barre', sonosbeUpnp::isHomeTheater('Sonos Arc Ultra'), true);
check('Amp : entrée TV', sonosbeUpnp::isHomeTheater('Sonos Amp'), true);

/* ------------------------------------------------------------- TOPOLOGIE */
section('Topologie');
$zgs = soap(fixture('zgs.xml'), 'GetZoneGroupState');
$groups = sonosbeUpnp::parseZoneGroupState($zgs['ZoneGroupState']);
check('un groupe', count($groups), 1);
check('coordinateur', $groups[0]['coordinator'], 'RINCON_000E58A1B2C301400');
check('membre : adresse tirée de Location', $groups[0]['members'][0]['ip'], '192.168.1.50');
check('membre : pièce', $groups[0]['members'][0]['name'], 'Salon');
/* Une barre avec ses arrières et son caisson, et une paire stéréo : les
 * enceintes appairées sont invisibles, seule la principale est pilotée. */
$paired = '<ZoneGroupState><ZoneGroups>'
    . '<ZoneGroup Coordinator="RINCON_A01400" ID="RINCON_A01400:1">'
    . '<ZoneGroupMember UUID="RINCON_A01400" Location="http://10.0.0.2:1400/xml/device_description.xml" ZoneName="Salon">'
    . '<Satellite UUID="RINCON_B01400" Location="http://10.0.0.3:1400/xml/device_description.xml" ZoneName="Salon" Invisible="1"/>'
    . '</ZoneGroupMember>'
    . '<ZoneGroupMember UUID="RINCON_C01400" Location="http://10.0.0.4:1400/xml/device_description.xml" ZoneName="Cuisine"/>'
    . '<ZoneGroupMember UUID="RINCON_D01400" Location="http://10.0.0.5:1400/xml/device_description.xml" ZoneName="Cuisine" Invisible="1"/>'
    . '<ZoneGroupMember UUID="RINCON_E01400" Location="http://10.0.0.6:1400/xml/device_description.xml" ZoneName="BOOST" IsZoneBridge="1"/>'
    . '</ZoneGroup></ZoneGroups></ZoneGroupState>';
$groups = sonosbeUpnp::parseZoneGroupState($paired);
check('groupe Salon + Cuisine : deux membres visibles, ni appairées ni Boost', array_map(function ($m) { return $m['uid']; }, $groups[0]['members']), array('RINCON_A01400', 'RINCON_C01400'));

/* ---------------------------------------------------------------- FAVORIS */
section('Favoris');
$browse = soap(fixture('fav.xml'), 'Browse');
$favorites = sonosbeUpnp::parseDidl($browse['Result']);
check('un favori', count($favorites), 1);
check('titre', $favorites[0]['title'], 'Fun Radio 101.9 (Danse)');
check('URI (entités décodées une fois)', $favorites[0]['res'], 'x-sonosapi-stream:s2109?sid=333&flags=8224&sn=3');
check('métadonnées de lecture présentes', strpos($favorites[0]['resmd'], '<DIDL-Lite') === 0, true);
check('une radio se charge directement', sonosbeUpnp::favoriteIsContainer($favorites[0]), false);
$playlist = array('res' => 'x-rincon-cpcontainer:1006206cspotify%3aplaylist%3a37i9', 'resmd' => '');
check('une playlist passe par la file', sonosbeUpnp::favoriteIsContainer($playlist), true);
$album = array('res' => 'x-sonos-http:album.mp4', 'resmd' => '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"><container id="x"><dc:title>A</dc:title><upnp:class>object.container.album.musicAlbum</upnp:class></container></DIDL-Lite>');
check('un album (conteneur) passe par la file', sonosbeUpnp::favoriteIsContainer($album), true);

/* ------------------------------------------------------------ CE QUI JOUE */
section('Ce qui joue');
$ns = 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns:r="urn:schemas-rinconnetworks-com:metadata-1-0/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"';
$idle = sonosbeUpnp::nowPlaying($media, soap(fixture('pos.xml'), 'GetPositionInfo'), '192.168.1.50');
check('à l\'arrêt : aucune source', $idle['source'], 'none');
check('à l\'arrêt : aucun titre', $idle['title'], '');

$tv = sonosbeUpnp::nowPlaying(array('CurrentURI' => 'x-sonos-htastream:RINCON_000E58A1B2C301400:spdif', 'CurrentURIMetaData' => ''),
    array('TrackURI' => '', 'TrackMetaData' => ''), '192.168.1.50');
check('TV : source', $tv['source'], 'tv');
check('TV : titre', $tv['title'], 'TV');

$track = '<DIDL-Lite ' . $ns . '><item id="-1" parentID="-1"><res>x-sonos-spotify:spotify%3atrack%3a1</res>'
    . '<upnp:albumArtURI>/getaa?s=1&amp;u=x-sonos-spotify%3aspotify%253atrack%253a1</upnp:albumArtURI>'
    . '<dc:title>Bohemian Rhapsody</dc:title><upnp:class>object.item.audioItem.musicTrack</upnp:class>'
    . '<dc:creator>Queen</dc:creator><upnp:album>A Night at the Opera</upnp:album></item></DIDL-Lite>';
$queue = sonosbeUpnp::nowPlaying(array('CurrentURI' => 'x-rincon-queue:RINCON_000E58A1B2C301400#0', 'CurrentURIMetaData' => ''),
    array('TrackURI' => 'x-sonos-spotify:spotify%3atrack%3a1', 'TrackMetaData' => $track), '192.168.1.50');
check('file : source', $queue['source'], 'queue');
check('file : titre', $queue['title'], 'Bohemian Rhapsody');
check('file : artiste', $queue['artist'], 'Queen');
check('file : album', $queue['album'], 'A Night at the Opera');
check('file : pochette relative rendue absolue', $queue['art'], 'http://192.168.1.50:1400/getaa?s=1&u=x-sonos-spotify%3aspotify%253atrack%253a1');

$station = '<DIDL-Lite ' . $ns . '><item id="R:0/0/0" parentID="R:0/0" restricted="true"><dc:title>Fun Radio 101.9 (Danse)</dc:title>'
    . '<upnp:class>object.item.audioItem.audioBroadcast</upnp:class></item></DIDL-Lite>';
$onAir = '<DIDL-Lite ' . $ns . '><item id="-1" parentID="-1" restricted="true"><res>x-sonosapi-stream:s2109?sid=254</res>'
    . '<r:streamContent>David Guetta - Titanium</r:streamContent><dc:title>x-sonosapi-stream:s2109?sid=254</dc:title>'
    . '<upnp:class>object.item</upnp:class></item></DIDL-Lite>';
$radio = sonosbeUpnp::nowPlaying(array('CurrentURI' => 'x-sonosapi-stream:s2109?sid=254', 'CurrentURIMetaData' => $station),
    array('TrackURI' => 'x-sonosapi-stream:s2109?sid=254', 'TrackMetaData' => $onAir), '192.168.1.50');
check('radio : source', $radio['source'], 'radio');
check('radio : station', $radio['station'], 'Fun Radio 101.9 (Danse)');
check('radio : titre tiré du flux', $radio['title'], 'Titanium');
check('radio : artiste tiré du flux', $radio['artist'], 'David Guetta');

$connecting = str_replace('David Guetta - Titanium', 'ZPSTR_CONNECTING', $onAir);
$radio = sonosbeUpnp::nowPlaying(array('CurrentURI' => 'x-sonosapi-stream:s2109?sid=254', 'CurrentURIMetaData' => $station),
    array('TrackURI' => 'x-sonosapi-stream:s2109?sid=254', 'TrackMetaData' => $connecting), '192.168.1.50');
check('radio en connexion : le nom de la station, pas la balise', $radio['title'], 'Fun Radio 101.9 (Danse)');

$tagged = str_replace('David Guetta - Titanium', 'TYPE=SNG|TITLE Hello|ARTIST Adele|ALBUM 25', $onAir);
$radio = sonosbeUpnp::nowPlaying(array('CurrentURI' => 'x-sonosapi-stream:s2109?sid=254', 'CurrentURIMetaData' => $station),
    array('TrackURI' => 'x-sonosapi-stream:s2109?sid=254', 'TrackMetaData' => $tagged), '192.168.1.50');
check('radio au format TYPE=SNG : titre', $radio['title'], 'Hello');
check('radio au format TYPE=SNG : artiste', $radio['artist'], 'Adele');

check('source : entrée ligne', sonosbeUpnp::sourceOf('x-rincon-stream:RINCON_1'), 'line_in');
check('source : suit un groupe', sonosbeUpnp::sourceOf('x-rincon:RINCON_1'), 'group');
check('source : AirPlay / Spotify Connect', sonosbeUpnp::sourceOf('x-sonos-vli:RINCON_1:2,spotify:abc'), 'app');
check('source : fichier HTTP', sonosbeUpnp::sourceOf('http://192.168.1.10/a.mp3'), 'stream');
check('durée 1:02:03', sonosbeUpnp::hmsToSeconds('1:02:03'), 3723);
check('durée NOT_IMPLEMENTED', sonosbeUpnp::hmsToSeconds('NOT_IMPLEMENTED'), 0);
check('minuterie 90 min', sonosbeUpnp::secondsToHms(5400), '01:30:00');
$didl = sonosbeUpnp::didlForUrl('http://j/a.mp3?x=1&y=2', 'Message <urgent>');
check('DIDL d\'un fichier : relu', sonosbeUpnp::parseDidl($didl)[0]['res'], 'http://j/a.mp3?x=1&y=2');
check('DIDL d\'un fichier : titre échappé puis relu', sonosbeUpnp::parseDidl($didl)[0]['title'], 'Message <urgent>');

/* ------------------------------------------------------------- WEBSOCKET */
section('Trames websocket');
$mask = "\x01\x02\x03\x04";
$frame = sonosbeWs::encodeFrame('{"a":1}', 0x1, $mask);
check('trame courte : en-tête texte final', bin2hex(substr($frame, 0, 2)), '8187');
$buffer = $frame;
$decoded = sonosbeWs::decodeFrame($buffer);
check('trame masquée relue', $decoded['payload'], '{"a":1}');
check('tampon vidé', $buffer, '');
$long = str_repeat('x', 70000);
$buffer = sonosbeWs::encodeFrame($long, 0x1, $mask) . 'SUITE';
check('trame de 70 000 octets relue', strlen(sonosbeWs::decodeFrame($buffer)['payload']), 70000);
check('la suite reste dans le tampon', $buffer, 'SUITE');
/* Réponse du serveur : non masquée, en deux fragments, avec un ping au
 * milieu. */
$part1 = "\x01\x03" . 'abc';
$ping = "\x89\x00";
$part2 = "\x80\x03" . 'def';
$buffer = substr($part1, 0, 3);
check('trame incomplète : attendre', sonosbeWs::decodeFrame($buffer), null);
$buffer = $part1 . $ping . $part2;
$first = sonosbeWs::decodeFrame($buffer);
check('ping intercalé rendu d\'abord', $first['opcode'], 0x9);
$second = sonosbeWs::decodeFrame($buffer);
check('fragments recollés', $second['payload'], 'abcdef');
check('opcode du message recollé', $second['opcode'], 0x1);
$error = array(array('namespace' => 'groups:1', 'householdId' => 'Sonos_X', 'success' => false, 'type' => 'globalError'),
               array('errorCode' => 'ERROR_MISSING_PARAMETERS', 'reason' => 'Missing householdId'));
check('erreur : code', sonosbeWs::errorCode($error), 'ERROR_MISSING_PARAMETERS');
check('erreur : pas un succès', sonosbeWs::success($error), false);

/* ------------------------------------------------------------------ AUDIO */
section('WAV');
/* Comme Piper : 22 050 Hz, mono, 16 bits. Une seconde de signal. */
$pcm = str_repeat(pack('v', 1000), 22050);
$wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
check('durée', sonosbeVoice::wavDuration($wav), 1.0);
$padded = sonosbeVoice::padWav($wav, 350, 250);
check('durée avec silences', sonosbeVoice::wavDuration($padded), 1.6);
$info = sonosbeVoice::wavInfo($padded);
check('en-tête réécrit : données à 44', $info['data_offset'], 44);
check('silence au début', substr($padded, 44, 4), "\x00\x00\x00\x00");
check('signal conservé après le silence', substr($padded, 44 + 15434, 2), pack('v', 1000));
check('taille RIFF cohérente', unpack('V', substr($padded, 4, 4))[1], strlen($padded) - 8);
/* Écrit en flux : taille des données inconnue (0xFFFFFFFF), et un bloc LIST
 * avant les données, comme en produit ffmpeg. */
$streamed = 'RIFF' . pack('V', 0xFFFFFFFF) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
          . 'LIST' . pack('V', 4) . 'INFO' . 'data' . pack('V', 0xFFFFFFFF) . str_repeat("\x01\x00", 12000);
check('taille inconnue : fin du fichier', sonosbeVoice::wavDuration($streamed), 0.5);
check('pas un WAV', sonosbeVoice::wavDuration('ID3xxxx'), 0.0);

section('Texte');
check('balises et retours à la ligne', sonosbeVoice::cleanText("<b>Bonjour</b>\n  tout\tle monde "), 'Bonjour tout le monde');
check('entités', sonosbeVoice::cleanText('Porte d&#39;entr&eacute;e'), 'Porte d\'entrée');
check('texte vide', sonosbeVoice::cleanText(" \n "), '');

section('Titre d\'une annonce');
check('vide : réglages de l\'équipement', sonosbeVoice::titleOptions(''), array('volume' => null, 'chime' => null, 'urgent' => false));
check('« 40 »', sonosbeVoice::titleOptions('40')['volume'], 40);
check('« 40 % »', sonosbeVoice::titleOptions('40 %')['volume'], 40);
check('« 35% carillon »', sonosbeVoice::titleOptions('35% carillon'), array('volume' => 35, 'chime' => true, 'urgent' => false));
check('« Urgent, 80 » (casse, virgule)', sonosbeVoice::titleOptions('Urgent, 80'), array('volume' => 80, 'chime' => null, 'urgent' => true));
check('« sans-carillon »', sonosbeVoice::titleOptions('sans-carillon')['chime'], false);
check('volume borné à 100', sonosbeVoice::titleOptions('250')['volume'], 100);
check('mot inconnu ignoré', sonosbeVoice::titleOptions('bonjour'), array('volume' => null, 'chime' => null, 'urgent' => false));

section('Plage de nuit');
check('23:30 dans 22:00–07:00', sonosbeVoice::inTimeRange('23:30', '22:00', '07:00'), true);
check('03:00 dans 22:00–07:00', sonosbeVoice::inTimeRange('03:00', '22:00', '07:00'), true);
check('07:00 hors de 22:00–07:00 (fin exclue)', sonosbeVoice::inTimeRange('07:00', '22:00', '07:00'), false);
check('12:00 hors de 22:00–07:00', sonosbeVoice::inTimeRange('12:00', '22:00', '07:00'), false);
check('13:00 dans 12:30–14:00 (même jour)', sonosbeVoice::inTimeRange('13:00', '12:30', '14:00'), true);
check('bornes égales : jamais', sonosbeVoice::inTimeRange('10:00', '10:00', '10:00'), false);
check('borne illisible : jamais', sonosbeVoice::inTimeRange('10:00', '', '07:00'), false);
check('format 22h00 accepté', sonosbeVoice::inTimeRange('22:10', '22h00', '6h30'), true);

section('Carillon');
$chime = sonosbeVoice::chimeWav();
check('durée connue', sonosbeVoice::wavDuration($chime), 1.9);
check('WAV lisible', sonosbeVoice::wavInfo($chime)['fmt']['rate'], 44100);
$peak = 0;
foreach (unpack('s*', substr($chime, 44)) as $sample) {
    $peak = max($peak, abs($sample));
}
check('pas de saturation', $peak < 32767, true);
check('audible', $peak > 8000, true);
check('silence à la fin (fondu)', abs(unpack('s', substr($chime, -2))[1]) < 50, true);

printf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
