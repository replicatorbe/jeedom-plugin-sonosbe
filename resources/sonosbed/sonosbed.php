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
 * Démon temps réel du plugin Sonos : une oreille, rien de plus.
 *
 * Il garde une connexion à l'API locale de chaque enceinte S2 (websocket en
 * TLS, port 1443), s'y abonne aux événements — lecture, morceau, volume,
 * groupes — et, quand l'un arrive, prévient le plugin, qui relit l'état par
 * UPnP, exactement comme le relevé de la minute. Le démon ne décode pas les
 * événements : une seule façon de lire l'état, à un seul endroit, et le
 * relevé de la minute prend le relais si le démon s'arrête.
 *
 * Les connexions partent de Jeedom vers les enceintes : aucun port à ouvrir
 * côté Jeedom, ce qui marche aussi derrière Docker ou un NAT.
 *
 * Il ne charge pas core.inc.php : PHP en ligne de commande, curl et openssl,
 * déjà exigés par Jeedom, suffisent.
 *
 *   php sonosbed.php --callback URL --pid FICHIER --stamp FICHIER --keyfile FICHIER --loglevel debug --timezone Europe/Brussels
 *
 * La clé API arrive dans un fichier que le démon efface aussitôt lu, jamais
 * en argument : ps est lisible par tous les utilisateurs de la machine.
 */

error_reporting(E_ALL);
set_time_limit(0);
$options = getopt('', array('callback:', 'pid:', 'stamp:', 'keyfile:', 'loglevel:', 'timezone:'));
if (!empty($options['timezone']) && in_array($options['timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($options['timezone']);
}
$callback = isset($options['callback']) ? $options['callback'] : '';
$pidFile  = isset($options['pid']) ? $options['pid'] : '';
$stamp    = isset($options['stamp']) ? $options['stamp'] : '';
$logLevel = isset($options['loglevel']) ? $options['loglevel'] : 'error';
$apiKey   = '';
if (!empty($options['keyfile']) && is_readable($options['keyfile'])) {
    $apiKey = trim((string) file_get_contents($options['keyfile']));
    @unlink($options['keyfile']);
}

/* Tous les niveaux que log::convertLogLevel() du coeur peut rendre. */
$levels = array('debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'error' => 3,
                'critical' => 3, 'alert' => 3, 'emergency' => 3, 'none' => 4);
$threshold = isset($levels[$logLevel]) ? $levels[$logLevel] : 3;

function sbLog($_level, $_message) {
    global $levels, $threshold;
    if ($levels[$_level] < $threshold) {
        return;
    }
    echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] : ' . $_message . "\n";
}

if ($callback === '' || $pidFile === '' || $apiKey === '') {
    sbLog('error', 'Arguments manquants : --callback, --pid et --keyfile (contenant la clé API) sont obligatoires.');
    exit(1);
}
foreach (array('curl_init', 'openssl_open') as $function) {
    if (!function_exists($function)) {
        sbLog('error', 'Extension PHP absente : ' . $function . '.');
        exit(1);
    }
}

file_put_contents($pidFile, (string) getmypid());

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/* Horloge monotone : un recalage NTP en arrière ne doit pas figer le démon. */
function sbClock() {
    return hrtime(true) / 1e9;
}

/* ---------------------------------------------------------------- JEEDOM */

function sbHandle($_query) {
    global $callback, $apiKey;
    $url = $callback . (strpos($callback, '?') === false ? '?' : '&')
         . 'apikey=' . rawurlencode($apiKey) . '&' . $_query;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        /* Un relevé interroge chaque enceinte concernée par UPnP. */
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_PROXY          => '',
        /* Le callback est en boucle locale, parfois derrière un certificat
         * auto-signé si l'accès interne est en https. */
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ));
    return $ch;
}

/* Jeedom refuse la clé (clé régénérée, accès API restreint) : inutile
 * d'insister, le démon s'arrête. La gestion automatique du coeur le relance
 * avec la clé du moment. */
function sbRefused($_code) {
    if ($_code === 401 || $_code === 403) {
        sbLog('error', 'Jeedom refuse l\'accès (HTTP ' . $_code . ') : clé API changée ou accès API du plugin restreint. Arrêt du démon.');
        global $pidFile;
        @unlink($pidFile);
        exit(1);
    }
}

/* Requêtes vers Jeedom, en continuant de servir les connexions : un relevé
 * peut prendre une seconde, et pendant ce temps les enceintes parlent. */
function sbRun($_handles) {
    $multi = curl_multi_init();
    foreach ($_handles as $ch) {
        curl_multi_add_handle($multi, $ch);
    }
    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) {
            wsPoll(0.05);
        }
    } while ($active && $status == CURLM_OK);
    $out = array();
    foreach ($_handles as $key => $ch) {
        $out[$key] = array('code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                           'body' => curl_multi_getcontent($ch), 'error' => curl_error($ch));
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $out;
}

/* Les enceintes à écouter, selon le plugin. L'appel sert aussi de preuve de
 * vie : un démon qui ne joint plus Jeedom est déclaré arrêté, puis relancé. */
function sbPlayers($_connected) {
    $r = sbRun(array(sbHandle('action=players&connected=' . implode(',', $_connected))))[0];
    sbRefused($r['code']);
    if ($r['code'] !== 200 || !is_string($r['body'])) {
        return array(null, 'HTTP ' . $r['code'] . ' ' . $r['error']);
    }
    $data = json_decode($r['body'], true);
    if (!is_array($data) || !isset($data['players']) || !is_array($data['players'])) {
        return array(null, 'liste illisible');
    }
    return array($data['players'], '');
}

/* ------------------------------------------------------------- WEBSOCKET */

/*
 * Une connexion par enceinte : TCP, puis TLS (certificat auto-signé de
 * l'enceinte, non vérifié : on ne sort pas du réseau local), puis poignée de
 * main websocket avec la clé d'API publique de l'API locale Sonos. Tout est
 * non bloquant : une enceinte éteinte ne doit pas retenir les autres.
 *
 * Une fois ouverte : getGroups pour connaître le groupe de l'enceinte, puis
 * abonnements. Chaque événement marque l'enceinte « à relire » ; un
 * changement de groupe marque aussi la topologie, et relance les
 * abonnements sur le nouveau groupe.
 */

const SB_PORT = 1443;
const SB_API_KEY = '123e4567-e89b-12d3-a456-426655440000';
const SB_PROTOCOL = 'v1.api.smartspeaker.audio';

/* Une connexion n'est jugée stable qu'après ce temps. */
const WS_STABLE = 60;

$live = array();      /* [eq] => connexion */
$changed = array();   /* [eq] => array('topology' => bool), pas encore transmis */
$flushedAt = -INF;
$flushRetryAt = -INF;
$flushFailures = 0;

function wsFrame($_opcode, $_payload) {
    $len = strlen($_payload);
    $head = chr(0x80 | $_opcode);
    if ($len < 126) {
        $head .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $head .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $head .= chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $_payload[$i] ^ $mask[$i % 4];
    }
    return $head . $mask . $masked;
}

function wsSend(&$_c, $_data) {
    $_c['out'] .= $_data;
    wsWrite($_c);
}

/* Commande de l'API locale : [en-têtes, corps], en trame texte. */
function wsCommand(&$_c, $_headers, $_body = array()) {
    $_headers['cmdId'] = bin2hex(random_bytes(4));
    wsSend($_c, wsFrame(1, json_encode(array($_headers, (object) $_body))));
}

/* Écrit ce que la socket accepte ; le reste attend le tour suivant. */
function wsWrite(&$_c) {
    if ($_c['out'] === '' || !is_resource($_c['sock']) || $_c['state'] === 'connecting' || $_c['state'] === 'tls') {
        return;
    }
    $n = @fwrite($_c['sock'], $_c['out']);
    if ($n === false) {
        wsClose($_c, 'écriture impossible');
        return;
    }
    $_c['out'] = (string) substr($_c['out'], $n);
}

function wsClose(&$_c, $_why) {
    if (is_resource($_c['sock'])) {
        if ($_c['state'] === 'open') {
            @fwrite($_c['sock'], wsFrame(8, ''));
        }
        @fclose($_c['sock']);
    }
    $clock = sbClock();
    $stable = $_c['state'] === 'open' && $clock - $_c['openedAt'] >= WS_STABLE;
    /* Une connexion qui a tenu repart de zéro ; une qui tombe aussitôt, ou
     * qui ne s'ouvre pas, espace ses essais jusqu'à deux minutes. */
    $_c['fails'] = $stable ? 1 : $_c['fails'] + 1;
    $_c['sock'] = null;
    $_c['state'] = 'idle';
    $_c['buf'] = '';
    $_c['frag'] = '';
    $_c['out'] = '';
    $_c['group'] = '';
    $_c['next'] = $clock + min(120, 5 * (1 << min(5, $_c['fails'] - 1)));
    if (!$_c['down']) {
        $_c['down'] = true;
        sbLog('info', 'Connexion temps réel à ' . $_c['name'] . ' perdue (' . $_why . '), état relu chaque minute en attendant');
    } else {
        sbLog('debug', 'Connexion temps réel à ' . $_c['name'] . ' : ' . $_why);
    }
}

function wsConnect(&$_c) {
    $sock = @stream_socket_client('tcp://' . $_c['ip'] . ':' . SB_PORT, $errno, $error, 0,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if ($sock === false) {
        $_c['state'] = 'connecting';
        wsClose($_c, $error !== '' ? $error : 'connexion impossible');
        return;
    }
    stream_set_blocking($sock, false);
    stream_context_set_option($sock, 'ssl', 'verify_peer', false);
    stream_context_set_option($sock, 'ssl', 'verify_peer_name', false);
    stream_context_set_option($sock, 'ssl', 'allow_self_signed', true);
    $_c['sock'] = $sock;
    $_c['state'] = 'connecting';
    $_c['deadline'] = sbClock() + 6;
}

/* TLS en non bloquant : enable_crypto rend 0 tant que la négociation n'est
 * pas finie, true quand elle l'est, false si elle échoue. */
function wsTls(&$_c) {
    $result = @stream_socket_enable_crypto($_c['sock'], true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($result === true) {
        wsHandshake($_c);
    } elseif ($result === false) {
        wsClose($_c, 'négociation TLS refusée');
    }
}

function wsHandshake(&$_c) {
    $key = base64_encode(random_bytes(16));
    $_c['accept'] = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $_c['state'] = 'handshake';
    $_c['last'] = sbClock();
    wsSend($_c, "GET /websocket/api HTTP/1.1\r\nHost: " . $_c['ip'] . ':' . SB_PORT . "\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . 'Sec-WebSocket-Key: ' . $key . "\r\nSec-WebSocket-Version: 13\r\n"
        . 'Sec-WebSocket-Protocol: ' . SB_PROTOCOL . "\r\n"
        . 'X-Sonos-Api-Key: ' . SB_API_KEY . "\r\n\r\n");
}

/* Lit tout ce qui est arrivé : en TLS, des données déchiffrées peuvent
 * attendre dans la couche SSL sans que select() le signale. Rend les
 * messages texte complets. */
function wsRead(&$_c) {
    $got = false;
    while (is_resource($_c['sock'])) {
        $chunk = @fread($_c['sock'], 65536);
        if ($chunk === false || ($chunk === '' && feof($_c['sock']))) {
            wsClose($_c, 'fermée par l\'enceinte');
            return array();
        }
        if ($chunk === '') {
            break;
        }
        $_c['buf'] .= $chunk;
        $got = true;
    }
    if (!$got) {
        return array();
    }
    $_c['last'] = sbClock();
    $messages = array();

    if ($_c['state'] === 'handshake') {
        $end = strpos($_c['buf'], "\r\n\r\n");
        if ($end === false) {
            if (strlen($_c['buf']) > 8192) {
                wsClose($_c, 'réponse illisible');
            }
            return array();
        }
        $head = substr($_c['buf'], 0, $end);
        if (!preg_match('#^HTTP/1\.[01] 101 #', $head)
            || !preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $m) || $m[1] !== $_c['accept']) {
            wsClose($_c, 'refusée : ' . strtok($head, "\r\n"));
            return array();
        }
        $_c['buf'] = (string) substr($_c['buf'], $end + 4);
        $_c['state'] = 'open';
        $_c['openedAt'] = sbClock();
        $_c['pingAt'] = sbClock();
        wsGetGroups($_c);
    }

    while (strlen($_c['buf']) >= 2) {
        $b1 = ord($_c['buf'][0]);
        $b2 = ord($_c['buf'][1]);
        $len = $b2 & 127;
        $off = 2;
        if ($len === 126) {
            if (strlen($_c['buf']) < 4) {
                break;
            }
            $len = unpack('n', substr($_c['buf'], 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (strlen($_c['buf']) < 10) {
                break;
            }
            $len = unpack('J', substr($_c['buf'], 2, 8))[1];
            $off = 10;
        }
        if ($len < 0 || $len > 4194304) {
            wsClose($_c, 'trame démesurée');
            return array();
        }
        $masked = ($b2 & 128) !== 0;
        if ($masked) {
            $off += 4;
        }
        if (strlen($_c['buf']) < $off + $len) {
            break;
        }
        $payload = (string) substr($_c['buf'], $off, $len);
        if ($masked) {
            $mask = substr($_c['buf'], $off - 4, 4);
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        $_c['buf'] = (string) substr($_c['buf'], $off + $len);
        $opcode = $b1 & 15;
        $fin = ($b1 & 128) !== 0;
        switch ($opcode) {
            case 1:
            case 2:
                $_c['fragOp'] = $opcode;
                $_c['frag'] = $payload;
                break;
            case 0:
                $_c['frag'] .= $payload;
                break;
            case 8:
                wsClose($_c, 'fermée par l\'enceinte');
                return $messages;
            case 9:
                wsSend($_c, wsFrame(10, $payload));
                continue 2;
            default:
                continue 2;
        }
        if ($fin) {
            if ($_c['fragOp'] === 1) {
                $messages[] = $_c['frag'];
            }
            $_c['frag'] = '';
        }
    }
    return $messages;
}

/* Le groupe de l'enceinte, avant de s'y abonner. */
function wsGetGroups(&$_c) {
    $headers = array('namespace' => 'groups:1', 'command' => 'getGroups');
    if ($_c['household'] !== '') {
        $headers['householdId'] = $_c['household'];
    }
    wsCommand($_c, $headers);
}

function wsSubscribe(&$_c) {
    wsCommand($_c, array('namespace' => 'groups:1', 'command' => 'subscribe', 'householdId' => $_c['household']));
    wsCommand($_c, array('namespace' => 'playerVolume:1', 'command' => 'subscribe', 'playerId' => $_c['uid']));
    if ($_c['group'] !== '') {
        wsCommand($_c, array('namespace' => 'playback:1', 'command' => 'subscribe', 'groupId' => $_c['group']));
        wsCommand($_c, array('namespace' => 'playbackMetadata:1', 'command' => 'subscribe', 'groupId' => $_c['group']));
    }
}

/* Réponses et événements d'une connexion. */
function wsHandle($_eq, &$_c, $_message) {
    global $changed;
    $data = json_decode($_message, true);
    if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
        return;
    }
    $head = $data[0];
    $body = isset($data[1]) && is_array($data[1]) ? $data[1] : array();
    if (isset($head['householdId']) && $_c['household'] === '') {
        $_c['household'] = (string) $head['householdId'];
    }

    if (isset($head['response'])) {
        if ($head['response'] === 'getGroups') {
            if (empty($head['success'])) {
                /* Foyer inconnu au premier appel : l'enceinte le donne dans
                 * sa réponse d'erreur, on redemande avec lui. */
                if ($_c['household'] !== '' && !$_c['retried']) {
                    $_c['retried'] = true;
                    wsGetGroups($_c);
                } else {
                    wsClose($_c, 'getGroups refusé');
                }
                return;
            }
            $previous = $_c['group'];
            $_c['group'] = '';
            foreach (isset($body['groups']) ? $body['groups'] : array() as $group) {
                if (isset($group['playerIds']) && in_array($_c['uid'], (array) $group['playerIds'], true)) {
                    $_c['group'] = (string) $group['id'];
                }
            }
            if ($_c['group'] !== $previous || !$_c['subscribed']) {
                $_c['subscribed'] = true;
                wsSubscribe($_c);
                sbLog('debug', $_c['name'] . ' : abonné (groupe ' . ($_c['group'] !== '' ? $_c['group'] : '?') . ')');
            }
        } elseif (empty($head['success'])) {
            sbLog('debug', $_c['name'] . ' : ' . $head['response'] . ' refusé — '
                . (isset($body['errorCode']) ? $body['errorCode'] : 'sans détail'));
        }
        return;
    }

    /* Un événement : l'état de l'enceinte a changé. Le contenu ne sert pas,
     * le plugin relit tout par le même chemin que le relevé de la minute. */
    $type = isset($head['type']) ? (string) $head['type'] : '';
    $topology = in_array($type, array('groups', 'groupCoordinatorChanged'), true);
    if (!isset($changed[$_eq])) {
        $changed[$_eq] = array('topology' => false);
    }
    $changed[$_eq]['topology'] = $changed[$_eq]['topology'] || $topology;
    sbLog('debug', $_c['name'] . ' : événement ' . ($type !== '' ? $type : '?'));
    /* Le groupe a pu changer : abonnements à reprendre sur le nouveau. */
    if ($topology) {
        wsGetGroups($_c);
    }
}

/* Tient la liste des connexions en accord avec celle du plugin. */
function wsSync($_wanted) {
    global $live;
    $seen = array();
    foreach ($_wanted as $player) {
        $eq = (int) $player['eq'];
        $seen[$eq] = true;
        if (isset($live[$eq]) && $live[$eq]['ip'] !== $player['ip']) {
            wsClose($live[$eq], 'adresse changée');
            unset($live[$eq]);
        }
        if (!isset($live[$eq])) {
            $live[$eq] = array('ip' => (string) $player['ip'], 'uid' => (string) $player['uid'],
                               'household' => (string) (isset($player['household']) ? $player['household'] : ''),
                               'name' => (string) $player['name'], 'sock' => null, 'state' => 'idle', 'buf' => '',
                               'frag' => '', 'fragOp' => 1, 'out' => '', 'accept' => '', 'fails' => 0, 'down' => false,
                               'next' => sbClock(), 'last' => sbClock(), 'pingAt' => sbClock(), 'openedAt' => 0,
                               'deadline' => 0, 'group' => '', 'subscribed' => false, 'retried' => false);
        }
    }
    foreach (array_keys($live) as $eq) {
        if (!isset($seen[$eq])) {
            if (is_resource($live[$eq]['sock'])) {
                @fclose($live[$eq]['sock']);
            }
            unset($live[$eq]);
        }
    }
}

/* Un tour : connexions, TLS, écritures en attente, lectures, pings. Attend
 * au plus $_timeout secondes : c'est le rythme de la boucle principale. */
function wsPoll($_timeout) {
    global $live;
    $clock = sbClock();
    $read = array();
    $write = array();
    foreach ($live as $eq => &$c) {
        if ($c['state'] === 'idle' && $clock >= $c['next']) {
            $c['subscribed'] = false;
            $c['retried'] = false;
            wsConnect($c);
        }
        if (($c['state'] === 'connecting' || $c['state'] === 'tls' || $c['state'] === 'handshake') && $clock > $c['deadline'] + 4) {
            wsClose($c, 'délai de connexion dépassé');
            continue;
        }
        if ($c['state'] === 'connecting') {
            $write[$eq] = $c['sock'];
        } elseif ($c['state'] === 'tls') {
            wsTls($c);
            if ($c['state'] === 'tls') {
                $read[$eq] = $c['sock'];
                $write[$eq] = $c['sock'];
            }
        }
        if ($c['state'] === 'handshake' || $c['state'] === 'open') {
            if ($c['state'] === 'open' && $clock - $c['pingAt'] > 30) {
                $c['pingAt'] = $clock;
                wsSend($c, wsFrame(9, 'jeedom'));
                if ($c['state'] !== 'open') {
                    continue;
                }
            }
            $read[$eq] = $c['sock'];
            if ($c['out'] !== '') {
                $write[$eq] = $c['sock'];
            }
        }
    }
    unset($c);
    if (empty($read) && empty($write)) {
        usleep((int) ($_timeout * 1000000));
        return;
    }
    $r = array_values($read);
    $w = array_values($write);
    $e = null;
    $n = @stream_select($r, $w, $e, 0, (int) ($_timeout * 1000000));
    if ($n === false || $n === 0) {
        /* Rien de signalé : en TLS, il peut quand même rester des données
         * déchiffrées en attente. Un essai de lecture ne coûte rien. */
        foreach ($read as $eq => $sock) {
            if (isset($live[$eq]) && $live[$eq]['state'] === 'open') {
                foreach (wsRead($live[$eq]) as $message) {
                    wsHandle($eq, $live[$eq], $message);
                }
            }
        }
    } else {
        foreach ($write as $eq => $sock) {
            if (!in_array($sock, $w, true) || !isset($live[$eq]) || $live[$eq]['sock'] !== $sock) {
                continue;
            }
            if ($live[$eq]['state'] === 'connecting') {
                /* TCP ouvert : place au TLS. */
                $live[$eq]['state'] = 'tls';
                wsTls($live[$eq]);
            } elseif ($live[$eq]['state'] !== 'tls') {
                wsWrite($live[$eq]);
            }
        }
        foreach ($read as $eq => $sock) {
            if (!isset($live[$eq]) || $live[$eq]['sock'] !== $sock
                || ($live[$eq]['state'] !== 'handshake' && $live[$eq]['state'] !== 'open')) {
                continue;
            }
            foreach (wsRead($live[$eq]) as $message) {
                wsHandle($eq, $live[$eq], $message);
            }
        }
    }
    /* Le silence se juge après la lecture. Avec un ping toutes les trente
     * secondes, une enceinte vivante répond toujours avant. */
    $clock = sbClock();
    foreach ($live as $eq => &$c) {
        if (($c['state'] === 'handshake' || $c['state'] === 'open') && $clock - $c['last'] > 75) {
            wsClose($c, 'silence');
        } elseif ($c['state'] === 'open' && $c['down'] && $clock - $c['openedAt'] >= WS_STABLE) {
            $c['down'] = false;
            sbLog('info', 'Connexion temps réel à ' . $c['name'] . ' rétablie');
        }
    }
    unset($c);
}

function wsConnected() {
    global $live;
    $eqs = array();
    foreach ($live as $eq => $c) {
        if ($c['state'] === 'open' && $c['subscribed']) {
            $eqs[] = $eq;
        }
    }
    return $eqs;
}

/*
 * Prévient le plugin, au plus deux fois et demie par seconde : un
 * changement de morceau produit plusieurs événements d'affilée (lecture,
 * métadonnées, volume), un seul relevé suffit. En cas d'échec, ils sont
 * gardés et les essais s'espacent.
 */
function wsFlush() {
    global $changed, $flushedAt, $flushRetryAt, $flushFailures;
    $clock = sbClock();
    if (empty($changed) || $clock - $flushedAt < 0.4 || $clock < $flushRetryAt) {
        return;
    }
    $sending = $changed;
    $changed = array();
    $flushedAt = $clock;
    $ch = sbHandle('action=changed');
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode(array('changed' => $sending)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));
    $r = sbRun(array($ch))[0];
    sbRefused($r['code']);
    if ($r['code'] === 200) {
        $flushFailures = 0;
        return;
    }
    foreach ($sending as $eq => $flags) {
        if (!isset($changed[$eq])) {
            $changed[$eq] = $flags;
        } else {
            $changed[$eq]['topology'] = $changed[$eq]['topology'] || $flags['topology'];
        }
    }
    $flushFailures++;
    $flushRetryAt = sbClock() + min(30, 2 * (1 << min(4, $flushFailures - 1)));
    if ($flushFailures === 1) {
        sbLog('warning', 'Transmission des événements à Jeedom en échec (HTTP ' . $r['code'] . '), nouvel essai en s\'espaçant');
    }
}

/* ----------------------------------------------------------------- BOUCLE */

$players = null;
$stampSeen = null;
$fetchedAt = -INF;
$failures = 0;
$retryAt = -INF;

sbLog('info', 'Démarrage du démon Sonos (PID ' . getmypid() . ')');

while ($running) {
    $clock = sbClock();

    /* La liste des enceintes est relue quand le plugin le signale (fichier
     * témoin récrit à chaque changement d'équipement), et chaque minute. */
    $content = ($stamp !== '' && is_readable($stamp)) ? @file_get_contents($stamp) : '';
    if (($players === null || $content !== $stampSeen || $clock - $fetchedAt > 60) && $clock >= $retryAt) {
        list($fresh, $problem) = sbPlayers(wsConnected());
        if ($fresh !== null) {
            wsSync($fresh);
            if ($failures > 0) {
                sbLog('info', 'Jeedom de nouveau joignable');
            }
            if ($players === null || count($fresh) !== count($players)) {
                sbLog('info', count($fresh) . ' enceinte(s) à écouter');
            }
            $players = $fresh;
            $stampSeen = $content;
            $fetchedAt = $clock;
            $failures = 0;
        } else {
            $failures++;
            $retryAt = $clock + min(60, 5 * (1 << min(4, $failures - 1)));
            if ($failures === 1) {
                sbLog('warning', 'Callback Jeedom en échec (' . $problem . '), nouvel essai en s\'espaçant');
            }
        }
    }

    wsPoll(0.2);
    wsFlush();
}

foreach ($live as $eq => $c) {
    if (is_resource($c['sock'])) {
        @fclose($c['sock']);
    }
}
@unlink($pidFile);
sbLog('info', 'Arrêt du démon Sonos');
