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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/sonosbeUpnp.class.php';
require_once __DIR__ . '/sonosbeWs.class.php';
require_once __DIR__ . '/sonosbeVoice.class.php';

/*
 * Pilotage local des enceintes Sonos, sans cloud ni dépendance.
 *
 * Deux protocoles, chacun pour ce qu'il fait le mieux :
 *
 *   - UPnP (port 1400), que parlent toutes les enceintes, S1 comme S2 :
 *     lecture, volume, favoris, groupes, entrée TV, réglages de la barre, et
 *     le relevé de l'état chaque minute ;
 *
 *   - l'API locale des S2 (websocket, port 1443), pour les annonces : la
 *     fonction audioClip joue un message par-dessus ce qui passe, TV
 *     comprise, et laisse l'enceinte reprendre d'elle-même. Sans elle (S1, ou
 *     choix de l'utilisateur), l'annonce interrompt la lecture et le plugin
 *     la rétablit ensuite.
 *
 * Les ordres de lecture vont au coordinateur du groupe de l'enceinte : dans
 * un groupe Sonos, lui seul a un transport. Le volume et les réglages restent
 * propres à chaque enceinte.
 *
 * L'identifiant logique d'un équipement est l'identifiant Sonos de
 * l'enceinte (RINCON_…) : une adresse IP qui change est corrigée par la
 * topologie, sans recréer l'équipement.
 */
class sonosbe extends eqLogic {

    /* ============================================================= RÉGLAGES */

    const POLL_TIMEOUT = 2;
    const ACTION_TIMEOUT = 4;

    /* Une enceinte injoignable depuis ce nombre de relevés n'est plus relevée
     * qu'une minute sur cinq. */
    const OFFLINE_AFTER = 3;

    const VOLUME_STEP = 5;
    const DEFAULT_ANNOUNCE_VOLUME = 30;

    /* Les annonces sans API locale bloquent le scénario pendant le message :
     * au-delà, on rend la main même si l'enceinte n'a pas fini. */
    const INTERRUPT_MAX_SECONDS = 120;

    const TOPOLOGY_KEY = 'sonosbe::topology';
    const FAVORITES_KEY = 'sonosbe::favorites';

    /* ================================================================ CRON */

    /* Une fois par minute : la topologie (qui corrige les adresses), puis
     * l'état de chaque enceinte. */
    public static function cron() {
        $eqLogics = self::configured();
        if (empty($eqLogics)) {
            return;
        }
        try {
            self::refreshTopology($eqLogics);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Topologie :', __FILE__) . ' ' . $e->getMessage());
        }
        /* Relus : la topologie vient peut-être de corriger une adresse, sur
         * d'autres instances que celles-ci. */
        $eqLogics = self::configured();
        $slowTurn = ((int) date('i')) % 5 === 0;
        foreach ($eqLogics as $eqLogic) {
            if (!$slowTurn && (int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER) {
                continue;
            }
            try {
                $eqLogic->poll();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public static function cronHourly() {
        try {
            sonosbeVoice::purge();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Purge des fichiers audio :', __FILE__) . ' ' . $e->getMessage());
        }
        $eqLogics = self::configured();
        if (empty($eqLogics)) {
            return;
        }
        try {
            self::refreshFavorites($eqLogics);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Favoris :', __FILE__) . ' ' . $e->getMessage());
        }
        try {
            self::recoverLost($eqLogics);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Recherche des enceintes perdues :', __FILE__) . ' ' . $e->getMessage());
        }
        foreach ($eqLogics as $eqLogic) {
            if ($eqLogic->getConfiguration('capabilities_at', '') === '' && (int) $eqLogic->getCache('failures', 0) === 0) {
                try {
                    $eqLogic->detectCapabilities();
                } catch (Throwable $e) {
                    log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
                }
            }
        }
    }

    /*
     * Une enceinte injoignable a peut-être changé d'adresse. La topologie la
     * retrouve dès qu'une autre enceinte répond ; mais si toutes sont
     * perdues, ou s'il n'y en a qu'une, il faut chercher sur le réseau. Une
     * fois par heure, et seulement dans ce cas.
     */
    public static function recoverLost($_eqLogics) {
        $lost = array();
        foreach ($_eqLogics as $eqLogic) {
            if ((int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER) {
                $lost[$eqLogic->uid()] = $eqLogic;
            }
        }
        if (empty($lost)) {
            return;
        }
        foreach (self::discover()['devices'] as $device) {
            if (!isset($lost[$device['uid']]) || $device['ip'] === $lost[$device['uid']]->ip()) {
                continue;
            }
            $eqLogic = $lost[$device['uid']];
            log::add(__CLASS__, 'info', sprintf(__('%s : retrouvée à l\'adresse %s (était %s)', __FILE__),
                $eqLogic->getHumanName(), $device['ip'], $eqLogic->ip()));
            $eqLogic->applyDescription($device);
            $eqLogic->save();
            try {
                $eqLogic->poll();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* Lignes du plugin dans la page Santé ; rien n'y interroge d'enceinte. */
    public static function health() {
        $eqLogics = self::configured();
        $offline = array_filter($eqLogics, function ($eq) { return (int) $eq->getCache('failures', 0) >= self::OFFLINE_AFTER; });
        $names = function ($_list) {
            return htmlspecialchars(implode(', ', array_map(function ($eq) { return $eq->getHumanName(); }, $_list)), ENT_QUOTES);
        };
        $engine = (string) config::byKey('tts_engine', __CLASS__, 'piper');
        $problem = sonosbeVoice::engineProblem($engine);
        return array(
            array(
                'test'   => __('Enceintes joignables', __FILE__),
                'result' => (count($eqLogics) - count($offline)) . '/' . count($eqLogics) . (empty($offline) ? '' : ' — ' . $names($offline)),
                'advice' => empty($offline) ? '' : __('Vérifiez que ces enceintes sont alimentées et sur le réseau. Une adresse IP changée est corrigée seule : aussitôt si une autre enceinte répond, sinon par une recherche sur le réseau, une fois par heure.', __FILE__),
                'state'  => empty($offline),
            ),
            array(
                'test'   => __('Synthèse vocale', __FILE__),
                'result' => sonosbeVoice::engineName($engine) . ($problem === '' ? ' — ' . __('prête', __FILE__) : ' — ' . htmlspecialchars($problem, ENT_QUOTES)),
                'advice' => $problem === '' ? '' : __('Voir la configuration du plugin.', __FILE__),
                'state'  => $problem === '',
            ),
        );
    }

    /* Jeedom peut se servir du plugin comme moteur de synthèse vocale pour
     * tout le système (Réglages → Système → Configuration → TTS). Le coeur
     * attend un MP3 à l'emplacement donné. */
    public static function tts($_filename, $_text) {
        $clip = sonosbeVoice::speak($_text);
        if (substr($clip['file'], -4) === '.mp3' || sonosbeVoice::ffmpeg() === '') {
            copy($clip['file'], $_filename);
            return;
        }
        sonosbeVoice::run(array(sonosbeVoice::ffmpeg(), '-y', '-v', 'error', '-i', $clip['file'], '-f', 'mp3', $_filename), '', 30);
    }

    /* ======================================================== CYCLE DE VIE */

    /* Aucune exception ici : le coeur crée l'équipement avec son seul nom. */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        $this->setConfiguration('ip', trim((string) $this->getConfiguration('ip', '')));
        if ($this->getConfiguration('announce_method', '') === '') {
            $this->setConfiguration('announce_method', 'auto');
        }
        $uid = (string) $this->getConfiguration('uid', '');
        if ($uid !== '') {
            $this->setLogicalId($uid);
        }
    }

    public function postSave() {
        $this->createCommands();
        if ($this->getCache('ip_seen', '') !== $this->getConfiguration('ip')) {
            $this->setCache('ip_seen', $this->getConfiguration('ip'));
            $this->setCache('failures', 0);
        }
    }

    public function isConfigured() {
        return $this->ip() !== '';
    }

    public function ip() {
        return trim((string) $this->getConfiguration('ip', ''));
    }

    public function uid() {
        return (string) $this->getConfiguration('uid', '');
    }

    public function isHomeTheater() {
        return (int) $this->getConfiguration('ht', 0) === 1;
    }

    public function hasAudioClip() {
        return (int) $this->getConfiguration('audio_clip', 0) === 1;
    }

    private static function configured() {
        return array_values(array_filter(self::byType(__CLASS__, true), function ($eq) { return $eq->isConfigured(); }));
    }

    /* ============================================================ DÉCOUVERTE */

    /* Réponse de l'enceinte à cette adresse ; exception si ce n'est pas un
     * Sonos. */
    public static function probe($_ip) {
        $ip = trim((string) $_ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception(__('Adresse IP invalide :', __FILE__) . ' ' . $ip);
        }
        $bodies = self::multiGet(array($ip => sonosbeUpnp::descriptionUrl($ip)), 2000, 4000);
        $device = $bodies[$ip] === null ? null : sonosbeUpnp::parseDescription($bodies[$ip], $ip);
        if ($device === null) {
            throw new Exception(sprintf(__('Aucune enceinte Sonos ne répond à %s.', __FILE__), $ip));
        }
        return self::markKnown($device);
    }

    private static function markKnown($_device) {
        $existing = self::byLogicalId($_device['uid'], __CLASS__);
        $_device['known'] = is_object($existing) ? $existing->getHumanName() : '';
        $_device['known_ip'] = is_object($existing) ? $existing->ip() : '';
        return $_device;
    }

    /*
     * Recherche des Sonos du réseau, sans rien créer. Trois voies, fusionnées
     * par identifiant : l'annonce SSDP (multicast, que ni Docker ni les VLAN
     * ne laissent passer), un balayage HTTP du port 1400 sur le sous-réseau,
     * et la topologie du foyer, que la première enceinte trouvée donne en
     * entier. Les enceintes appairées (arrières, caisson, second d'une paire
     * stéréo) sont écartées : elles se pilotent par leur enceinte principale.
     */
    public static function discover($_subnet = '') {
        $started = microtime(true);
        $ips = array();
        $ssdp = self::ssdpSearch();
        foreach ($ssdp as $ip) {
            $ips[$ip] = 'SSDP';
        }
        $prefixes = array();
        if (trim((string) $_subnet) !== '') {
            $prefixes[] = self::subnetPrefix($_subnet);
        } else {
            foreach (self::localIps() as $ip) {
                $prefixes[] = substr($ip, 0, strrpos($ip, '.'));
            }
        }
        $self = self::localIps();
        foreach (array_unique($prefixes) as $prefix) {
            for ($i = 1; $i <= 254; $i++) {
                $ip = $prefix . '.' . $i;
                if (!isset($ips[$ip]) && !in_array($ip, $self, true)) {
                    $ips[$ip] = 'HTTP';
                }
            }
        }
        if (empty($ips)) {
            throw new Exception(__('Impossible de déterminer le réseau local : précisez un sous-réseau.', __FILE__));
        }
        $urls = array();
        foreach ($ips as $ip => $source) {
            $urls[$ip] = sonosbeUpnp::descriptionUrl($ip);
        }
        $found = array();
        foreach (self::multiGet($urls, 1000, 3000) as $ip => $body) {
            $device = $body === null ? null : sonosbeUpnp::parseDescription($body, $ip);
            if ($device !== null) {
                $device['source'] = $ips[$ip];
                $found[$device['uid']] = $device;
            }
        }
        /* La topologie : enceintes d'un autre sous-réseau, et tri des
         * enceintes appairées. */
        $visible = null;
        foreach ($found as $device) {
            try {
                $zgs = sonosbeUpnp::call($device['ip'], 'ZoneGroupTopology', 'GetZoneGroupState', array(), 4);
                $visible = array();
                foreach (sonosbeUpnp::parseZoneGroupState($zgs['ZoneGroupState']) as $group) {
                    foreach ($group['members'] as $member) {
                        $visible[$member['uid']] = $member;
                    }
                }
                break;
            } catch (Throwable $e) {
                continue;
            }
        }
        if (is_array($visible)) {
            $found = array_intersect_key($found, $visible);
            $missing = array();
            foreach ($visible as $uid => $member) {
                if (!isset($found[$uid]) && $member['ip'] !== '') {
                    $missing[$member['ip']] = sonosbeUpnp::descriptionUrl($member['ip']);
                }
            }
            foreach (self::multiGet($missing, 1500, 3000) as $ip => $body) {
                $device = $body === null ? null : sonosbeUpnp::parseDescription($body, $ip);
                if ($device !== null) {
                    $device['source'] = __('topologie', __FILE__);
                    $found[$device['uid']] = $device;
                }
            }
        }
        $found = array_map(array(__CLASS__, 'markKnown'), array_values($found));
        usort($found, function ($a, $b) { return strnatcasecmp($a['room'], $b['room']); });
        /* Ce qui a été interrogé : la page le montre, pour qu'une recherche
         * vide ne laisse pas deviner si elle a seulement eu lieu. */
        return array(
            'devices' => $found,
            'subnets' => array_map(function ($p) { return $p . '.0/24'; }, array_values(array_unique($prefixes))),
            'scanned' => count($ips),
            'ssdp'    => count($ssdp),
            'seconds' => round(microtime(true) - $started, 1),
        );
    }

    /*
     * M-SEARCH SSDP : les adresses qui répondent en ZonePlayer. La socket
     * n'est pas « connectée » à l'adresse multicast : les réponses arrivent
     * de l'adresse de chaque enceinte, qu'une socket connectée à
     * 239.255.255.250 rejetterait toutes.
     */
    public static function ssdpSearch($_seconds = 2) {
        $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            return array();
        }
        $request = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: 1\r\n"
                 . "ST: urn:schemas-upnp-org:device:ZonePlayer:1\r\n\r\n";
        stream_set_blocking($socket, false);
        $ips = array();
        $started = microtime(true);
        $deadline = $started + $_seconds;
        /* Trois envois espacés : l'UDP ne garantit rien, et une enceinte en
         * veille rate parfois le premier. */
        $sends = array(0.0, 0.4, 0.9);
        while (microtime(true) < $deadline) {
            if (!empty($sends) && microtime(true) - $started >= $sends[0]) {
                array_shift($sends);
                @stream_socket_sendto($socket, $request, 0, '239.255.255.250:1900');
            }
            $read = array($socket);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                $answer = (string) @stream_socket_recvfrom($socket, 4096);
                if (preg_match('#LOCATION:\s*http://([\d.]+):1400/#i', $answer, $m)) {
                    $ips[$m[1]] = true;
                }
            }
        }
        fclose($socket);
        return array_keys($ips);
    }

    /* Interfaces qui ne mènent pas au réseau de la maison. */
    const VIRTUAL_INTERFACES = '/^(docker|br-|veth|virbr|lxc|lxd|tun|tap|wg|tailscale|zt|vmnet|vboxnet|cni|flannel|kube)/';

    public static function localIps() {
        $ips = array();
        $out = array();
        @exec('ip -4 -o addr show scope global 2>/dev/null', $out);
        foreach ($out as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\//', $line, $m)
                && !preg_match(self::VIRTUAL_INTERFACES, $m[1])) {
                $ips[] = $m[2];
            }
        }
        if (empty($ips)) {
            $internal = parse_url(network::getNetworkAccess('internal'), PHP_URL_HOST);
            if (filter_var($internal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $internal;
            }
        }
        return array_values(array_unique($ips));
    }

    /* « 192.168.1 », « 192.168.1.0 » ou « 192.168.1.0/24 » => « 192.168.1 ». */
    public static function subnetPrefix($_subnet) {
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\.(\d{1,3}))?(?:\/(\d{1,2}))?$/', trim((string) $_subnet), $m)
            || max((int) $m[1], (int) $m[2], (int) $m[3]) > 255) {
            throw new Exception(__('Sous-réseau illisible : saisissez par exemple 192.168.1.0/24.', __FILE__));
        }
        if (isset($m[5]) && $m[5] !== '' && (int) $m[5] !== 24) {
            throw new Exception(__('Seul un sous-réseau en /24 peut être parcouru.', __FILE__));
        }
        return (int) $m[1] . '.' . (int) $m[2] . '.' . (int) $m[3];
    }

    /* Lectures HTTP en parallèle : clé => URL, rend clé => corps ou null. */
    public static function multiGet($_urls, $_connectMs, $_totalMs) {
        if (empty($_urls)) {
            return array();
        }
        $multi = curl_multi_init();
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 128);
        }
        $handles = array();
        foreach ($_urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_CONNECTTIMEOUT_MS => $_connectMs,
                CURLOPT_TIMEOUT_MS        => $_totalMs,
                CURLOPT_PROXY             => '',
            ));
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active && curl_multi_select($multi, 0.2) === -1) {
                usleep(10000);
            }
        } while ($active && $status == CURLM_OK);
        $out = array();
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $out[$key] = ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_string($body)) ? $body : null;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    /* Crée l'équipement d'une enceinte trouvée, ou corrige son adresse. */
    public static function createFromProbe($_device) {
        $eqLogic = self::byLogicalId($_device['uid'], __CLASS__);
        $created = !is_object($eqLogic);
        if ($created) {
            $eqLogic = new sonosbe();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId($_device['uid']);
            $name = $_device['room'] !== '' ? $_device['room'] : $_device['display'];
            /* Deux enceintes dans une même pièce Sonos (une paire non
             * appairée, par exemple) : le modèle les distingue. */
            foreach (self::byType(__CLASS__) as $other) {
                if (strcasecmp($other->getName(), $name) === 0) {
                    $name .= ' (' . $_device['display'] . ')';
                    break;
                }
            }
            $eqLogic->setName($name);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setConfiguration('announce_volume', self::DEFAULT_ANNOUNCE_VOLUME);
            $eqLogic->setConfiguration('announce_method', 'auto');
        }
        $eqLogic->applyDescription($_device);
        $eqLogic->save();
        try {
            $eqLogic->detectCapabilities();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        try {
            self::refreshTopology(self::configured());
            self::refreshFavorites(array($eqLogic));
            $eqLogic->poll();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        return $eqLogic;
    }

    public function applyDescription($_device) {
        foreach (array('ip', 'uid', 'room', 'model', 'model_nb', 'display', 'version', 'software', 'swgen', 'mac', 'serial') as $key) {
            if (isset($_device[$key])) {
                $this->setConfiguration($key, $_device[$key]);
            }
        }
        /* L'API locale confirme plus tard ; le modèle suffit pour commencer. */
        if ($this->getConfiguration('capabilities_at', '') === '' && isset($_device['ht'])) {
            $this->setConfiguration('ht', $_device['ht']);
        }
    }

    /*
     * Ce que l'enceinte sait faire, dit par l'API locale des S2 : annonces
     * (AUDIO_CLIP) et fonctions de barre de son (HT_PLAYBACK). Une S1 n'a pas
     * cette API : on s'en tient au modèle, et les annonces interrompent.
     */
    public function detectCapabilities() {
        if ((int) $this->getConfiguration('swgen', 2) < 2) {
            $this->setConfiguration('audio_clip', 0);
            $this->setConfiguration('capabilities_at', date('Y-m-d H:i:s'));
            $this->save(true);
            return;
        }
        $answer = sonosbeWs::command($this->ip(), array('namespace' => 'groups:1', 'command' => 'getGroups'));
        foreach (isset($answer[1]['players']) ? $answer[1]['players'] : array() as $player) {
            if (!isset($player['id']) || $player['id'] !== $this->uid()) {
                continue;
            }
            $capabilities = isset($player['capabilities']) ? (array) $player['capabilities'] : array();
            $this->setConfiguration('capabilities', implode(',', $capabilities));
            $this->setConfiguration('audio_clip', in_array('AUDIO_CLIP', $capabilities, true) ? 1 : 0);
            $this->setConfiguration('ht', in_array('HT_PLAYBACK', $capabilities, true) ? 1 : 0);
            $this->setConfiguration('household', isset($answer[0]['householdId']) ? $answer[0]['householdId'] : '');
            $this->setConfiguration('capabilities_at', date('Y-m-d H:i:s'));
            $this->save(true);
            $this->createCommands();
            return;
        }
        throw new Exception(__('L\'API locale ne connaît pas cette enceinte.', __FILE__));
    }

    /* ============================================================ TOPOLOGIE */

    /*
     * Les groupes du foyer, demandés à la première enceinte qui répond. Au
     * passage : l'adresse des enceintes qui en ont changé est corrigée, et la
     * liste « Rejoindre le groupe de » est tenue à jour.
     */
    public static function refreshTopology($_eqLogics) {
        $groups = null;
        foreach ($_eqLogics as $eqLogic) {
            if ((int) $eqLogic->getCache('failures', 0) >= self::OFFLINE_AFTER && count($_eqLogics) > 1) {
                continue;
            }
            try {
                $zgs = sonosbeUpnp::call($eqLogic->ip(), 'ZoneGroupTopology', 'GetZoneGroupState', array(), self::POLL_TIMEOUT);
                $groups = sonosbeUpnp::parseZoneGroupState($zgs['ZoneGroupState']);
                break;
            } catch (Throwable $e) {
                continue;
            }
        }
        if ($groups === null) {
            return null;
        }
        cache::set(self::TOPOLOGY_KEY, array('at' => time(), 'groups' => $groups));
        $players = array();
        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                $players[$member['uid']] = $member;
            }
        }
        foreach (self::byType(__CLASS__) as $eqLogic) {
            $uid = $eqLogic->uid();
            if (isset($players[$uid]) && $players[$uid]['ip'] !== '' && $players[$uid]['ip'] !== $eqLogic->ip()) {
                log::add(__CLASS__, 'info', sprintf(__('%s : nouvelle adresse %s (était %s)', __FILE__),
                    $eqLogic->getHumanName(), $players[$uid]['ip'], $eqLogic->ip()));
                $eqLogic->setConfiguration('ip', $players[$uid]['ip']);
                $eqLogic->save(true);
                $eqLogic->setCache('failures', 0);
            }
            $eqLogic->updateJoinList($players);
        }
        return $groups;
    }

    public static function topology() {
        $topology = cache::byKey(self::TOPOLOGY_KEY)->getValue(array());
        return (is_array($topology) && isset($topology['groups'])) ? $topology['groups'] : array();
    }

    /* Le groupe de cette enceinte : coordinateur et membres. Seule, elle est
     * son propre coordinateur. */
    public function group() {
        foreach (self::topology() as $group) {
            foreach ($group['members'] as $member) {
                if ($member['uid'] === $this->uid()) {
                    $coordinator = null;
                    foreach ($group['members'] as $candidate) {
                        if ($candidate['uid'] === $group['coordinator']) {
                            $coordinator = $candidate;
                        }
                    }
                    if ($coordinator === null || $coordinator['ip'] === '') {
                        break;
                    }
                    return array('coordinator' => $coordinator, 'members' => $group['members']);
                }
            }
        }
        $self = array('uid' => $this->uid(), 'ip' => $this->ip(), 'name' => (string) $this->getConfiguration('room', $this->getName()));
        return array('coordinator' => $self, 'members' => array($self));
    }

    public function coordinatorIp() {
        return $this->group()['coordinator']['ip'];
    }

    public function isCoordinator() {
        return $this->group()['coordinator']['uid'] === $this->uid();
    }

    private function updateJoinList($_players) {
        $cmd = $this->getCmd('action', 'join');
        if (!is_object($cmd)) {
            return;
        }
        $names = array();
        foreach ($_players as $uid => $player) {
            if ($uid !== $this->uid()) {
                $names[$uid] = self::listText($player['name']);
            }
        }
        /* Triée par pièce, pas par identifiant. */
        natcasesort($names);
        $choices = array();
        foreach ($names as $uid => $name) {
            $choices[] = $uid . '|' . $name;
        }
        $list = implode(';', $choices);
        if ($cmd->getConfiguration('listValue', '') !== $list) {
            $cmd->setConfiguration('listValue', $list);
            $cmd->save();
        }
    }

    /* ============================================================== FAVORIS */

    /* Les favoris Sonos sont ceux du foyer : une seule lecture pour toutes
     * les enceintes. */
    public static function refreshFavorites($_eqLogics) {
        $favorites = null;
        foreach ($_eqLogics as $eqLogic) {
            try {
                $answer = sonosbeUpnp::call($eqLogic->ip(), 'ContentDirectory', 'Browse', array(
                    'ObjectID' => 'FV:2', 'BrowseFlag' => 'BrowseDirectChildren', 'Filter' => '*',
                    'StartingIndex' => 0, 'RequestedCount' => 200, 'SortCriteria' => ''), self::ACTION_TIMEOUT);
                $favorites = array();
                foreach (sonosbeUpnp::parseDidl($answer['Result']) as $item) {
                    if ($item['title'] === '' || $item['res'] === '') {
                        continue;
                    }
                    $favorites[] = array('id' => $item['id'], 'title' => $item['title'], 'res' => $item['res'],
                                         'resmd' => $item['resmd'], 'art' => $item['art'], 'description' => $item['description']);
                }
                break;
            } catch (Throwable $e) {
                continue;
            }
        }
        if ($favorites === null) {
            return null;
        }
        cache::set(self::FAVORITES_KEY, $favorites);
        $list = implode(';', array_map(function ($f) { $t = self::listText($f['title']); return $t . '|' . $t; }, $favorites));
        foreach (self::byType(__CLASS__) as $eqLogic) {
            $cmd = $eqLogic->getCmd('action', 'favorite');
            if (is_object($cmd) && $cmd->getConfiguration('listValue', '') !== $list) {
                $cmd->setConfiguration('listValue', $list);
                $cmd->save();
            }
        }
        return $favorites;
    }

    public static function favorites() {
        $favorites = cache::byKey(self::FAVORITES_KEY)->getValue(array());
        return is_array($favorites) ? $favorites : array();
    }

    /* Une valeur de liste Jeedom ne peut contenir ni « ; » ni « | ». */
    public static function listText($_text) {
        return trim(str_replace(array(';', '|'), array(',', '/'), (string) $_text));
    }

    public static function findFavorite($_ref) {
        $ref = trim((string) $_ref);
        $favorites = self::favorites();
        foreach ($favorites as $favorite) {
            if ($favorite['id'] === $ref || self::listText($favorite['title']) === $ref) {
                return $favorite;
            }
        }
        /* Un scénario peut donner le nom sans respecter la casse. */
        foreach ($favorites as $favorite) {
            if (strcasecmp(self::listText($favorite['title']), $ref) === 0) {
                return $favorite;
            }
        }
        throw new Exception(__('Favori Sonos introuvable :', __FILE__) . ' ' . $ref);
    }

    /* ================================================================ RELEVÉ */

    /*
     * L'état de l'enceinte : ce qui joue vient du coordinateur de son groupe,
     * le volume et les réglages d'elle-même.
     */
    public function poll() {
        $ip = $this->ip();
        $coordinatorIp = $this->coordinatorIp();
        /* Joignable ou non, c'est l'enceinte elle-même qui le dit : dans un
         * groupe, le coordinateur peut répondre pour une enceinte éteinte. */
        try {
            $volume = sonosbeUpnp::call($ip, 'RenderingControl', 'GetVolume', array('InstanceID' => 0, 'Channel' => 'Master'), self::POLL_TIMEOUT);
        } catch (sonosbeUnreachable $e) {
            $this->noteFailure($e->getMessage());
            return false;
        }
        $values = array();
        /* Ce qui joue vient du coordinateur. S'il ne répond pas (enceinte
         * d'un autre étage éteinte), on garde les dernières valeurs plutôt
         * que de tout effacer. */
        $now = null;
        $transport = $this->quietCall($coordinatorIp, 'AVTransport', 'GetTransportInfo', array('InstanceID' => 0));
        if (isset($transport['CurrentTransportState'])) {
            $state = $transport['CurrentTransportState'];
            $values['state'] = isset(sonosbeUpnp::STATE_NAMES[$state]) ? sonosbeUpnp::STATE_NAMES[$state] : $state;
            $values['playing'] = $state === 'PLAYING' ? 1 : 0;
            $media = $this->quietCall($coordinatorIp, 'AVTransport', 'GetMediaInfo', array('InstanceID' => 0));
            $position = $this->quietCall($coordinatorIp, 'AVTransport', 'GetPositionInfo', array('InstanceID' => 0));
            $now = sonosbeUpnp::nowPlaying((array) $media, (array) $position, $coordinatorIp);
            $values['source'] = __(sonosbeUpnp::SOURCE_NAMES[$now['source']], __FILE__);
            $values['title'] = $now['title'];
            $values['artist'] = $now['artist'];
            $values['album'] = $now['album'];
            $values['station'] = $now['station'];
            $values['cover'] = $now['art'];
            /* Le son de la TV passe par la barre : ce qu'un scénario veut
             * savoir avant de baisser les volets ou de parler. */
            $values['tv_active'] = ($now['source'] === 'tv' && in_array($state, array('PLAYING', 'TRANSITIONING'), true)) ? 1 : 0;
            $now['state'] = $state;
        }

        if (isset($volume['CurrentVolume'])) {
            $values['volume'] = (int) $volume['CurrentVolume'];
        }
        $mute = $this->quietCall($ip, 'RenderingControl', 'GetMute', array('InstanceID' => 0, 'Channel' => 'Master'));
        if (isset($mute['CurrentMute'])) {
            $values['mute'] = (int) $mute['CurrentMute'];
        }
        foreach (array('bass' => array('GetBass', 'CurrentBass'), 'treble' => array('GetTreble', 'CurrentTreble')) as $key => $call) {
            $answer = $this->quietCall($ip, 'RenderingControl', $call[0], array('InstanceID' => 0));
            if (isset($answer[$call[1]])) {
                $values[$key] = (int) $answer[$call[1]];
            }
        }
        $loudness = $this->quietCall($ip, 'RenderingControl', 'GetLoudness', array('InstanceID' => 0, 'Channel' => 'Master'));
        if (isset($loudness['CurrentLoudness'])) {
            $values['loudness'] = (int) $loudness['CurrentLoudness'];
        }
        if ($this->isHomeTheater()) {
            foreach (array('night' => 'NightMode', 'speech' => 'DialogLevel') as $key => $eq) {
                $answer = $this->quietCall($ip, 'RenderingControl', 'GetEQ', array('InstanceID' => 0, 'EQType' => $eq));
                if (isset($answer['CurrentValue'])) {
                    $values[$key] = (int) $answer['CurrentValue'] > 0 ? 1 : 0;
                }
            }
        }
        $sleep = $this->quietCall($coordinatorIp, 'AVTransport', 'GetRemainingSleepTimerDuration', array('InstanceID' => 0));
        if (is_array($sleep)) {
            $remaining = isset($sleep['RemainingSleepTimerDuration']) ? $sleep['RemainingSleepTimerDuration'] : '';
            $values['sleep'] = $remaining === '' ? 0 : (int) ceil(sonosbeUpnp::hmsToSeconds($remaining) / 60);
        }
        $group = $this->group();
        $values['group'] = count($group['members']) > 1
            ? implode(' + ', array_map(function ($m) { return $m['name']; }, $group['members']))
            : __('Seule', __FILE__);

        $this->clearFailure();
        foreach ($values as $logicalId => $value) {
            $this->publishCmd($logicalId, $value);
        }
        if ($now !== null) {
            $this->setCache('now', $now + array('at' => date('Y-m-d H:i:s')));
        }
        return true;
    }

    /* Un réglage que l'enceinte ne connaît pas ne doit pas faire échouer le
     * relevé entier. */
    private function quietCall($_ip, $_service, $_action, $_args) {
        try {
            return sonosbeUpnp::call($_ip, $_service, $_action, $_args, self::POLL_TIMEOUT);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
            return null;
        }
    }

    /* Écriture d'une valeur. Ce nom, et surtout pas setCmd() : utils::a2o()
     * appelle « set » + chaque clé du formulaire, et la page envoie « cmd ». */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === null) {
            return;
        }
        $cmd = $this->getCmd('info', $_logicalId);
        if (is_object($cmd)) {
            $this->checkAndUpdateCmd($cmd, $_value);
        }
    }

    public function noteFailure($_message) {
        $failures = (int) $this->getCache('failures', 0) + 1;
        $this->setCache('failures', $failures);
        $this->setCache('problem', $_message);
        $this->publishCmd('online', 0);
        log::add(__CLASS__, $failures === self::OFFLINE_AFTER ? 'warning' : 'debug', $this->getHumanName() . ' : ' . $_message);
    }

    private function clearFailure() {
        if ((int) $this->getCache('failures', 0) >= self::OFFLINE_AFTER) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('de nouveau joignable.', __FILE__));
        }
        $this->setCache('failures', 0);
        $this->setCache('problem', '');
        $this->publishCmd('online', 1);
    }

    /* ============================================================= ACTIONS */

    public function runAction($_logicalId, $_options) {
        $message = isset($_options['message']) ? $_options['message'] : '';
        $title = isset($_options['title']) ? $_options['title'] : '';
        /* Commandes de voix : communes aux enceintes et à « Toutes les
         * enceintes », qui n'a pas d'adresse. */
        switch ($_logicalId) {
            case 'announce':
                $this->announce($message, $title);
                return;
            case 'play_url':
                $this->playAudio($message, $title);
                return;
            case 'replay':
                $this->replayLast();
                return;
            case 'prepare':
                /* La voix est fabriquée et gardée en cache, sans être jouée :
                 * l'annonce qui suivra partira sans attendre la synthèse. */
                sonosbeVoice::speak($message);
                return;
            case 'micro':
                throw new Exception(__('« Message vocal » s\'utilise depuis le dashboard : un clic pour enregistrer, un second pour envoyer.', __FILE__));
        }
        if ($this->isAll()) {
            throw new Exception(__('Commande inconnue :', __FILE__) . ' ' . $_logicalId);
        }
        if (!$this->isConfigured()) {
            throw new Exception(__('Aucune adresse IP n\'est renseignée.', __FILE__));
        }
        $ip = $this->ip();
        $av = function ($_action, $_args = array()) {
            return sonosbeUpnp::call($this->coordinatorIp(), 'AVTransport', $_action, array('InstanceID' => 0) + $_args, self::ACTION_TIMEOUT);
        };
        $rc = function ($_action, $_args = array()) use ($ip) {
            return sonosbeUpnp::call($ip, 'RenderingControl', $_action, array('InstanceID' => 0) + $_args, self::ACTION_TIMEOUT);
        };
        $slider = isset($_options['slider']) ? $_options['slider'] : null;
        $refreshTopology = false;

        switch ($_logicalId) {
            case 'refresh':
                self::refreshTopology(self::configured());
                self::refreshFavorites(array($this));
                break;
            case 'play':
                $av('Play', array('Speed' => 1));
                break;
            case 'pause':
                $this->pauseOrStop($av);
                break;
            case 'stop':
                $av('Stop');
                break;
            case 'toggle':
                $state = $av('GetTransportInfo');
                if (isset($state['CurrentTransportState']) && $state['CurrentTransportState'] === 'PLAYING') {
                    $this->pauseOrStop($av);
                } else {
                    $av('Play', array('Speed' => 1));
                }
                break;
            case 'next':
                $av('Next');
                break;
            case 'previous':
                $av('Previous');
                break;
            case 'volume_set':
                $rc('SetVolume', array('Channel' => 'Master', 'DesiredVolume' => self::clamp($slider, 0, 100)));
                break;
            case 'volume_up':
            case 'volume_down':
                $rc('SetRelativeVolume', array('Channel' => 'Master',
                    'Adjustment' => $_logicalId === 'volume_up' ? self::VOLUME_STEP : -self::VOLUME_STEP));
                break;
            case 'mute_on':
            case 'mute_off':
                $rc('SetMute', array('Channel' => 'Master', 'DesiredMute' => $_logicalId === 'mute_on' ? 1 : 0));
                break;
            case 'bass_set':
                $rc('SetBass', array('DesiredBass' => self::clamp($slider, -10, 10)));
                break;
            case 'treble_set':
                $rc('SetTreble', array('DesiredTreble' => self::clamp($slider, -10, 10)));
                break;
            case 'loudness_on':
            case 'loudness_off':
                $rc('SetLoudness', array('Channel' => 'Master', 'DesiredLoudness' => $_logicalId === 'loudness_on' ? 1 : 0));
                break;
            case 'night_on':
            case 'night_off':
                $rc('SetEQ', array('EQType' => 'NightMode', 'DesiredValue' => $_logicalId === 'night_on' ? 1 : 0));
                break;
            case 'speech_on':
            case 'speech_off':
                $rc('SetEQ', array('EQType' => 'DialogLevel', 'DesiredValue' => $_logicalId === 'speech_on' ? 1 : 0));
                break;
            case 'tv':
                $this->switchToTv();
                break;
            case 'favorite':
                $this->playFavorite(isset($_options['select']) ? $_options['select'] : '');
                break;
            case 'sleep_set':
                $minutes = self::clamp($slider, 0, 1440);
                $av('ConfigureSleepTimer', array('NewSleepTimerDuration' => $minutes > 0 ? sonosbeUpnp::secondsToHms($minutes * 60) : ''));
                break;
            case 'join':
                $this->joinGroupOf(isset($_options['select']) ? $_options['select'] : '');
                $refreshTopology = true;
                break;
            case 'leave':
                sonosbeUpnp::call($ip, 'AVTransport', 'BecomeCoordinatorOfStandaloneGroup', array('InstanceID' => 0), self::ACTION_TIMEOUT);
                $refreshTopology = true;
                break;
            default:
                throw new Exception(__('Commande inconnue :', __FILE__) . ' ' . $_logicalId);
        }
        /* L'ordre est passé : un relevé qui échoue ensuite ne doit pas le
         * faire paraître en échec au scénario. L'enceinte applique l'ordre de
         * façon asynchrone : un instant avant de relire, sans quoi on relève
         * encore l'ancien état. */
        usleep(300000);
        try {
            if ($refreshTopology) {
                self::refreshTopology(self::configured());
            }
            foreach ($refreshTopology ? self::configured() : array($this) as $eqLogic) {
                $eqLogic->poll();
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . __('relevé après l\'ordre :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /* Une radio ou la TV ne se mettent pas en pause : Sonos répond 701. */
    private function pauseOrStop($_av) {
        try {
            $_av('Pause');
        } catch (sonosbeUpnpError $e) {
            if ($e->getCode() !== 701) {
                throw $e;
            }
            $_av('Stop');
        }
    }

    private static function clamp($_value, $_min, $_max) {
        if (!is_numeric($_value)) {
            throw new Exception(__('Valeur numérique attendue.', __FILE__));
        }
        return max($_min, min($_max, (int) round((float) $_value)));
    }

    public function switchToTv() {
        if (!$this->isHomeTheater()) {
            throw new Exception(__('Cette enceinte n\'a pas d\'entrée TV.', __FILE__));
        }
        /* Une barre groupée quitte d'abord son groupe : l'entrée TV est la
         * sienne, pas celle du coordinateur. */
        if (!$this->isCoordinator()) {
            sonosbeUpnp::call($this->ip(), 'AVTransport', 'BecomeCoordinatorOfStandaloneGroup', array('InstanceID' => 0), self::ACTION_TIMEOUT);
        }
        sonosbeUpnp::call($this->ip(), 'AVTransport', 'SetAVTransportURI', array('InstanceID' => 0,
            'CurrentURI' => 'x-sonos-htastream:' . $this->uid() . ':spdif', 'CurrentURIMetaData' => ''), self::ACTION_TIMEOUT);
        try {
            sonosbeUpnp::call($this->ip(), 'AVTransport', 'Play', array('InstanceID' => 0, 'Speed' => 1), self::ACTION_TIMEOUT);
        } catch (sonosbeUpnpError $e) {
            /* TV éteinte : l'entrée est choisie, elle jouera à l'allumage. */
        }
    }

    public function playFavorite($_ref) {
        if (empty(self::favorites())) {
            self::refreshFavorites(array($this));
        }
        $favorite = self::findFavorite($_ref);
        $group = $this->group();
        $ip = $group['coordinator']['ip'];
        $uid = $group['coordinator']['uid'];
        $call = function ($_action, $_args = array()) use ($ip) {
            return sonosbeUpnp::call($ip, 'AVTransport', $_action, array('InstanceID' => 0) + $_args, self::ACTION_TIMEOUT);
        };
        if (sonosbeUpnp::favoriteIsContainer($favorite)) {
            $call('RemoveAllTracksFromQueue');
            $call('AddURIToQueue', array('EnqueuedURI' => $favorite['res'], 'EnqueuedURIMetaData' => $favorite['resmd'],
                                         'DesiredFirstTrackNumberEnqueued' => 0, 'EnqueueAsNext' => 0));
            $call('SetAVTransportURI', array('CurrentURI' => 'x-rincon-queue:' . $uid . '#0', 'CurrentURIMetaData' => ''));
        } else {
            $call('SetAVTransportURI', array('CurrentURI' => $favorite['res'], 'CurrentURIMetaData' => $favorite['resmd']));
        }
        $call('Play', array('Speed' => 1));
    }

    /* Rejoint le groupe d'une autre enceinte, désignée par son identifiant
     * Sonos ou par le nom de sa pièce. */
    public function joinGroupOf($_ref) {
        $ref = trim((string) $_ref);
        foreach (self::topology() as $group) {
            foreach ($group['members'] as $member) {
                if ($member['uid'] === $ref || strcasecmp($member['name'], $ref) === 0) {
                    if ($member['uid'] === $this->uid()) {
                        throw new Exception(__('Une enceinte ne peut pas rejoindre son propre groupe.', __FILE__));
                    }
                    /* Déjà dans ce groupe : rien à faire. Le demander quand
                     * même, à son propre coordinateur, casserait le groupe. */
                    if ($group['coordinator'] === $this->uid()
                        || in_array($this->uid(), array_map(function ($m) { return $m['uid']; }, $group['members']), true)) {
                        return;
                    }
                    sonosbeUpnp::call($this->ip(), 'AVTransport', 'SetAVTransportURI', array('InstanceID' => 0,
                        'CurrentURI' => 'x-rincon:' . $group['coordinator'], 'CurrentURIMetaData' => ''), self::ACTION_TIMEOUT);
                    return;
                }
            }
        }
        throw new Exception(__('Enceinte introuvable :', __FILE__) . ' ' . $ref);
    }

    /* ============================================================ ANNONCES */

    /*
     * Toutes les annonces passent par ici : texte (announce), son (playAudio),
     * micro, dernière annonce rejouée, et l'équipement « Toutes les
     * enceintes ». Les options :
     *
     *   title   le titre de la commande : volume et mots-clés, voir
     *           sonosbeVoice::titleOptions() — « 40 carillon urgent » ;
     *   label   ce que dit l'annonce, pour le journal et « Dernière annonce » ;
     *   manual  déclenchée par quelqu'un (micro, page, dashboard) : la plage
     *           de nuit baisse le volume mais ne la bloque pas.
     *
     * Rend array(method, url) ; method vaut « blocked » si la plage de nuit
     * a retenu l'annonce, ce qui n'est pas une erreur pour un scénario.
     */
    public function playClip($_clip, $_opts = array()) {
        $opts = $_opts + array('title' => '', 'label' => '', 'manual' => false, 'remember' => true);
        if ($this->isAll()) {
            return $this->playClipEverywhere($_clip, $opts);
        }
        $title = sonosbeVoice::titleOptions($opts['title']);
        $volume = $title['volume'] !== null ? $title['volume'] : $this->defaultAnnounceVolume();
        $chime = $title['chime'] !== null ? $title['chime'] : (int) $this->getConfiguration('announce_chime', 0) === 1;

        if (!$title['urgent'] && sonosbeVoice::nightActive()) {
            if (!$opts['manual'] && (int) config::byKey('night_block', __CLASS__, 0) === 1) {
                log::add(__CLASS__, 'info', sprintf(__('%s : annonce retenue, plage de nuit (« %s »)', __FILE__), $this->getHumanName(), $opts['label']));
                return array('method' => 'blocked', 'url' => '');
            }
            $volume = min($volume, sonosbeVoice::nightVolume());
        }

        $items = array();
        if ($chime) {
            $chimeFile = sonosbeVoice::chimeFile();
            $items[] = array('url' => sonosbeVoice::url($chimeFile), 'duration' => sonosbeVoice::duration($chimeFile),
                             'margin' => 0.4, 'mime' => 'audio/wav');
        }
        if (isset($_clip['file']) && !is_file($_clip['file'])) {
            throw new Exception(__('Ce message n\'est plus disponible : les fichiers sont effacés au bout d\'un jour.', __FILE__));
        }
        $items[] = array('url' => isset($_clip['url']) ? $_clip['url'] : sonosbeVoice::url($_clip['file']),
                         'duration' => isset($_clip['duration']) ? (float) $_clip['duration'] : 0.0, 'margin' => 1.0,
                         'mime' => sonosbeVoice::mimeOf(isset($_clip['url']) ? $_clip['url'] : $_clip['file']));

        $method = (string) $this->getConfiguration('announce_method', 'auto');
        $used = '';
        if ($method !== 'interrupt' && $this->hasAudioClip()) {
            try {
                foreach ($items as $item) {
                    $this->queuedAudioClip($item['url'], $volume, $item['duration'], $item['margin']);
                }
                $used = 'clip';
            } catch (Throwable $e) {
                if ($method === 'clip') {
                    throw $e;
                }
                log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('annonce par l\'API locale impossible, on interrompt la lecture à la place.', __FILE__) . ' ' . $e->getMessage());
            }
        }
        if ($used === '') {
            if ($method === 'clip' && !$this->hasAudioClip()) {
                throw new Exception(__('Cette enceinte n\'a pas l\'API locale des Sonos S2 : choisissez un autre mode d\'annonce.', __FILE__));
            }
            $this->announceByInterrupting($items, $volume, $opts['label']);
            $used = 'interrupt';
        }
        if ($opts['remember']) {
            $this->rememberClip($_clip, $opts);
        }
        log::add(__CLASS__, 'info', sprintf('%s : %s « %s » (volume %d%s)', $this->getHumanName(),
            $used === 'clip' ? __('annonce', __FILE__) : __('annonce avec interruption', __FILE__), $opts['label'], $volume,
            $chime ? ', ' . __('carillon', __FILE__) : ''));
        return array('method' => $used, 'url' => end($items)['url']);
    }

    public function defaultAnnounceVolume() {
        $volume = $this->getConfiguration('announce_volume', '');
        return is_numeric($volume) ? max(0, min(100, (int) $volume)) : self::DEFAULT_ANNOUNCE_VOLUME;
    }

    public function announce($_text, $_title = '', $_manual = false) {
        $text = sonosbeVoice::cleanText($_text);
        if ($text === '') {
            throw new Exception(__('Aucun texte à annoncer.', __FILE__));
        }
        $clip = sonosbeVoice::speak($text);
        return $this->playClip($clip, array('title' => $_title, 'label' => $text, 'manual' => $_manual));
    }

    /* Un son par son URL, ou un fichier de la machine Jeedom, que l'on copie
     * là où l'enceinte peut le lire. */
    public function playAudio($_source, $_title = '') {
        $source = trim((string) $_source);
        if (preg_match('#^https?://#i', $source)) {
            $clip = array('url' => $source, 'duration' => 0);
        } else {
            $real = realpath($source);
            if ($real === false || !is_file($real) || !preg_match('/\.(mp3|wav|m4a|aac|flac|ogg)$/i', $real)
                || strpos($real, realpath(__DIR__ . '/../../../..')) !== 0) {
                throw new Exception(__('Fichier audio introuvable, ou hors du dossier de Jeedom :', __FILE__) . ' ' . $source);
            }
            $copy = sonosbeVoice::audioDir('rec') . '/' . bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($real, PATHINFO_EXTENSION));
            copy($real, $copy);
            $clip = array('file' => $copy, 'duration' => sonosbeVoice::duration($copy));
        }
        return $this->playClip($clip, array('title' => $_title, 'label' => basename($source)));
    }

    /* La dernière annonce, gardée pour « Rejouer la dernière annonce ». Le
     * fichier est déjà là : rien n'est refabriqué. */
    private function rememberClip($_clip, $_opts) {
        $this->setCache('last_clip', array('clip' => $_clip, 'title' => (string) $_opts['title'], 'label' => (string) $_opts['label']));
        $this->publishCmd('announce_last', $_opts['label']);
    }

    public function replayLast() {
        $last = $this->getCache('last_clip', null);
        if (!is_array($last) || !isset($last['clip'])) {
            throw new Exception(__('Aucune annonce à rejouer.', __FILE__));
        }
        /* Rejouer, c'est quelqu'un qui n'a pas entendu : pas bloqué la nuit. */
        return $this->playClip($last['clip'], array('title' => $last['title'], 'label' => $last['label'], 'manual' => true));
    }

    /* ======================================================= TEST D'ACCÈS */

    const PROBE_WAIT = 8;

    /*
     * Première cause d'une annonce muette : l'enceinte ne joint pas Jeedom à
     * l'adresse donnée (Jeedom en Docker, en HTTPS seulement, adresse
     * interne erronée). Le test le constate au lieu de le supposer : chaque
     * enceinte reçoit un carillon servi par sonosbeProbe.php, qui note qui
     * est venu le chercher.
     */
    public static function testAccess() {
        $rows = array();
        $base = sonosbeVoice::baseUrl();
        $https = stripos($base, 'https://') === 0;
        $rows[] = array('test' => __('Adresse donnée aux enceintes', __FILE__), 'ok' => !$https,
            'detail' => $base . ($https ? ' — ' . __('une enceinte refuse un certificat auto-signé : mettez une adresse en http://', __FILE__) : ''));

        $url = self::probeUrl(self::newProbe());
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
                                     CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => false));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        $selfOk = $body !== false && $code === 200 && strpos($type, 'audio/') === 0;
        $rows[] = array('test' => __('Jeedom se joint lui-même à cette adresse', __FILE__), 'ok' => $selfOk,
            'detail' => $selfOk ? __('le fichier de test est servi', __FILE__)
                : ($error !== '' ? $error : 'HTTP ' . $code . ' ' . $type) . ' — ' . __('vérifiez l\'adresse dans la configuration du plugin', __FILE__));

        $players = self::configured();
        if (empty($players)) {
            $rows[] = array('test' => __('Enceintes', __FILE__), 'ok' => false, 'detail' => __('aucune enceinte active', __FILE__));
            return $rows;
        }
        foreach ($players as $player) {
            $token = self::newProbe();
            $label = sprintf(__('%s lit un fichier sur Jeedom', __FILE__), $player->getName());
            try {
                $played = $player->playClip(array('url' => self::probeUrl($token), 'duration' => 1.9),
                    array('title' => min($player->defaultAnnounceVolume(), 25) . ' sans-carillon', 'label' => __('Test d\'accès', __FILE__),
                          'manual' => true, 'remember' => false));
            } catch (Throwable $e) {
                $rows[] = array('test' => $label, 'ok' => false, 'detail' => __('l\'enceinte a refusé l\'annonce :', __FILE__) . ' ' . $e->getMessage());
                continue;
            }
            $hits = array();
            $deadline = microtime(true) + self::PROBE_WAIT;
            while (microtime(true) < $deadline) {
                $probe = cache::byKey('sonosbe::probe::' . $token)->getValue(array());
                $hits = isset($probe['hits']) ? $probe['hits'] : array();
                if (!empty($hits)) {
                    break;
                }
                usleep(250000);
            }
            $rows[] = array('test' => $label, 'ok' => !empty($hits),
                'detail' => !empty($hits)
                    ? sprintf(__('fichier lu depuis %s (%s)', __FILE__), $hits[0]['ip'],
                        $played['method'] === 'clip' ? __('par-dessus le son', __FILE__) : __('avec interruption', __FILE__))
                    : __('l\'enceinte a accepté l\'annonce mais n\'est pas venue chercher le fichier : elle ne joint pas Jeedom à cette adresse', __FILE__));
        }
        return $rows;
    }

    private static function newProbe() {
        $token = bin2hex(random_bytes(16));
        cache::set('sonosbe::probe::' . $token, array('created' => time()), 300);
        return $token;
    }

    private static function probeUrl($_token) {
        return sonosbeVoice::baseUrl() . '/plugins/sonosbe/core/php/sonosbeProbe.php?t=' . $_token;
    }

    /* ============================================== TOUTES LES ENCEINTES */

    /* L'équipement « Toutes les enceintes » : pas d'adresse, il répartit
     * ses annonces sur chaque enceinte active. */
    public function isAll() {
        return $this->getConfiguration('kind', '') === 'all';
    }

    public static function allEquipment($_create = false) {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->isAll()) {
                return $eqLogic;
            }
        }
        if (!$_create) {
            return null;
        }
        $eqLogic = new sonosbe();
        $eqLogic->setEqType_name(__CLASS__);
        $eqLogic->setLogicalId('all');
        $eqLogic->setName(__('Toutes les enceintes', __FILE__));
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setConfiguration('kind', 'all');
        $eqLogic->save();
        return $eqLogic;
    }

    /*
     * La voix est fabriquée une fois ; chaque enceinte la joue à son volume
     * (ou à celui du titre), avec son carillon et sa méthode. Les enceintes
     * qui jouent par-dessus le son partent d'abord : elles rendent la main
     * aussitôt, quand une annonce avec interruption attend la fin du
     * message.
     */
    private function playClipEverywhere($_clip, $_opts) {
        $players = self::configured();
        if (empty($players)) {
            throw new Exception(__('Aucune enceinte active.', __FILE__));
        }
        usort($players, function ($a, $b) {
            $ra = ($a->hasAudioClip() && $a->getConfiguration('announce_method', 'auto') !== 'interrupt') ? 0 : 1;
            $rb = ($b->hasAudioClip() && $b->getConfiguration('announce_method', 'auto') !== 'interrupt') ? 0 : 1;
            return $ra - $rb;
        });
        $results = array();
        $errors = array();
        foreach ($players as $player) {
            try {
                $results[$player->getName()] = $player->playClip($_clip, $_opts)['method'];
            } catch (Throwable $e) {
                $errors[] = $player->getName() . ' : ' . $e->getMessage();
                log::add(__CLASS__, 'warning', $player->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        if (empty($results)) {
            throw new Exception(__('Aucune enceinte n\'a pu faire l\'annonce.', __FILE__) . ' ' . implode(' ; ', $errors));
        }
        if ($_opts['remember']) {
            $this->rememberClip($_clip, $_opts);
        }
        return array('method' => 'all', 'players' => $results, 'errors' => $errors, 'url' => '');
    }

    /*
     * Deux annonces rapprochées (deux lignes d'un scénario, un scénario et le
     * micro, le carillon et son message) se suivent au lieu de se couper : la
     * suivante attend la fin de la précédente, dont la durée est connue. Une
     * annonce jouée par URL, de durée inconnue, n'impose pas d'attente.
     */
    const CLIP_MAX_WAIT = 60;

    private function queuedAudioClip($_url, $_volume, $_duration, $_margin = 1.0) {
        $lock = self::lock('clip-' . $this->uid());
        try {
            $busyUntil = (float) $this->getCache('clip_busy_until', 0);
            $wait = min(self::CLIP_MAX_WAIT, $busyUntil - microtime(true));
            if ($wait > 0) {
                usleep((int) ($wait * 1000000));
            }
            $this->audioClip($_url, $_volume);
            /* Marge : l'enceinte met un instant à charger le fichier et à
             * baisser le son. */
            $this->setCache('clip_busy_until', $_duration > 0 ? microtime(true) + $_duration + $_margin : 0);
        } finally {
            self::unlock($lock);
        }
    }

    public function audioClip($_url, $_volume) {
        $headers = array('namespace' => 'audioClip:1', 'command' => 'loadAudioClip', 'playerId' => $this->uid());
        $household = (string) $this->getConfiguration('household', '');
        if ($household !== '') {
            $headers['householdId'] = $household;
        }
        $body = array('name' => 'Jeedom', 'appId' => 'com.jeedom.sonosbe', 'streamUrl' => $_url, 'volume' => (int) $_volume);
        return sonosbeWs::command($this->ip(), $headers, $body, 6);
    }

    /*
     * Annonce sans API locale : on note ce qui joue, on joue les sons (le
     * carillon puis le message) sur le coordinateur du groupe, on attend la
     * fin, et on rétablit la source, la position et le volume. Une file
     * d'attente reprend au morceau et à la seconde près ; une radio ou la
     * TV, à leur flux.
     */
    public function announceByInterrupting($_items, $_volume, $_label) {
        $group = $this->group();
        $coordinatorIp = $group['coordinator']['ip'];
        $coordinatorUid = $group['coordinator']['uid'];
        $ip = $this->ip();
        $av = function ($_action, $_args = array()) use ($coordinatorIp) {
            return sonosbeUpnp::call($coordinatorIp, 'AVTransport', $_action, array('InstanceID' => 0) + $_args, self::ACTION_TIMEOUT);
        };
        $rc = function ($_action, $_args = array()) use ($ip) {
            return sonosbeUpnp::call($ip, 'RenderingControl', $_action, array('InstanceID' => 0, 'Channel' => 'Master') + $_args, self::ACTION_TIMEOUT);
        };

        /* Deux annonces sur le même groupe se suivent au lieu de se
         * mélanger : la seconde attend la fin de la première. */
        $lock = self::lock('announce-' . $coordinatorUid);
        try {
            /* Ce qui joue, noté avant de toucher à quoi que ce soit : si la
             * lecture de l'état échoue, on n'a encore rien changé. */
            $state = $av('GetTransportInfo')['CurrentTransportState'];
            $media = $av('GetMediaInfo');
            $position = $av('GetPositionInfo');
            $volume = (int) $rc('GetVolume')['CurrentVolume'];
            $mute = (int) $rc('GetMute')['CurrentMute'];
            $source = sonosbeUpnp::sourceOf($media['CurrentURI']);

            /* À partir d'ici, quoi qu'il arrive au message (fichier illisible,
             * enceinte qui refuse), ce qui jouait est rétabli. */
            try {
                if ($state === 'PLAYING' && $source === 'queue') {
                    $av('Pause');
                }
                $rc('SetVolume', array('DesiredVolume' => $_volume));
                if ($mute) {
                    $rc('SetMute', array('DesiredMute' => 0));
                }
                foreach ($_items as $item) {
                    $av('SetAVTransportURI', array('CurrentURI' => $item['url'], 'CurrentURIMetaData' => sonosbeUpnp::didlForUrl($item['url'], $_label, $item['mime'])));
                    $av('Play', array('Speed' => 1));
                    $this->waitEndOfClip($av, $item['duration']);
                }
            } finally {
                $this->restoreAfterInterrupt($av, $rc, $coordinatorUid, $state, $media, $position, $source, $volume, $mute);
            }
        } finally {
            self::unlock($lock);
        }
    }

    /* Fin du message : l'enceinte repasse à l'arrêt (ou quelqu'un a mis en
     * pause). La durée connue sert de garde-fou, avec une marge pour le
     * chargement du fichier. */
    private function waitEndOfClip($_av, $_duration) {
        $started = microtime(true);
        $limit = min($_duration > 0 ? $_duration + 6 : self::INTERRUPT_MAX_SECONDS, self::INTERRUPT_MAX_SECONDS);
        usleep(800000);
        while (microtime(true) - $started < $limit) {
            try {
                $now = $_av('GetTransportInfo')['CurrentTransportState'];
                if (in_array($now, array('STOPPED', 'NO_MEDIA_PRESENT', 'PAUSED_PLAYBACK'), true)) {
                    return;
                }
            } catch (Throwable $e) {
                /* Un relevé manqué n'arrête pas l'attente. */
            }
            usleep(400000);
        }
    }

    /*
     * Rétablit ce qui jouait avant une annonce. Chaque étape est tentée même
     * si la précédente échoue : un morceau impossible à repositionner ne doit
     * pas empêcher de rendre le volume ni de relancer la lecture.
     */
    private function restoreAfterInterrupt($_av, $_rc, $_coordinatorUid, $_state, $_media, $_position, $_source, $_volume, $_mute) {
        $steps = array();
        $steps[] = function () use ($_rc, $_volume) { $_rc('SetVolume', array('DesiredVolume' => $_volume)); };
        if ($_mute) {
            $steps[] = function () use ($_rc) { $_rc('SetMute', array('DesiredMute' => 1)); };
        }
        $queue = 'x-rincon-queue:' . $_coordinatorUid . '#0';
        if ($_media['CurrentURI'] === '') {
            /* Rien ne jouait : on remet la file d'attente, sans quoi un
             * « Lecture » plus tard rejouerait l'annonce. */
            $steps[] = function () use ($_av, $queue) { $_av('SetAVTransportURI', array('CurrentURI' => $queue, 'CurrentURIMetaData' => '')); };
        } elseif ($_source === 'queue') {
            $steps[] = function () use ($_av, $queue) { $_av('SetAVTransportURI', array('CurrentURI' => $queue, 'CurrentURIMetaData' => '')); };
            if ((int) $_position['Track'] > 0) {
                $steps[] = function () use ($_av, $_position) { $_av('Seek', array('Unit' => 'TRACK_NR', 'Target' => (int) $_position['Track'])); };
                if (sonosbeUpnp::hmsToSeconds($_position['RelTime']) > 0) {
                    $steps[] = function () use ($_av, $_position) { $_av('Seek', array('Unit' => 'REL_TIME', 'Target' => $_position['RelTime'])); };
                }
            }
        } else {
            $steps[] = function () use ($_av, $_media) {
                $_av('SetAVTransportURI', array('CurrentURI' => $_media['CurrentURI'], 'CurrentURIMetaData' => $_media['CurrentURIMetaData']));
            };
        }
        if ($_media['CurrentURI'] !== '' && ($_state === 'PLAYING' || $_state === 'TRANSITIONING')) {
            $steps[] = function () use ($_av) { $_av('Play', array('Speed' => 1)); };
        }
        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                /* La TV reprend seule quand elle émet ; un flux n'est pas
                 * toujours repositionnable. */
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . __('rétablissement après annonce :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }

    /* Verrou nommé, partagé entre processus (scénarios, cron, page). */
    private static function lock($_name) {
        $file = jeedom::getTmpFolder(__CLASS__) . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', $_name) . '.lock';
        $lock = @fopen($file, 'c');
        if ($lock === false) {
            throw new Exception(__('Verrou impossible à créer :', __FILE__) . ' ' . $file);
        }
        flock($lock, LOCK_EX);
        return $lock;
    }

    private static function unlock($_lock) {
        flock($_lock, LOCK_UN);
        fclose($_lock);
    }

    /* =========================================================== COMMANDES */

    public function createCommands() {
        if ($this->getId() == '') {
            return;
        }
        $this->createVoiceCommands();
        if ($this->isAll()) {
            return;
        }
        $state = $this->addCmdIfMissing('state', 'Statut', 'info', 'string', array('order' => 1, 'isVisible' => 1, 'generic' => 'MEDIA_STATUS'));
        $playing = $this->addCmdIfMissing('playing', 'En lecture', 'info', 'binary', array('order' => 2));
        $volume = $this->addCmdIfMissing('volume', 'Volume', 'info', 'numeric', array('order' => 3, 'generic' => 'VOLUME', 'unite' => '%', 'min' => 0, 'max' => 100));
        $mute = $this->addCmdIfMissing('mute', 'Muet', 'info', 'binary', array('order' => 4));
        $this->addCmdIfMissing('cover', 'Pochette', 'info', 'string', array('order' => 5, 'isVisible' => 1,
            'template' => 'sonosbe::cover', 'display' => array('showNameOndashboard' => 0)));
        $this->addCmdIfMissing('title', 'Titre', 'info', 'string', array('order' => 6, 'isVisible' => 1, 'generic' => 'MEDIA_TITLE'));
        $this->addCmdIfMissing('artist', 'Artiste', 'info', 'string', array('order' => 7, 'isVisible' => 1, 'generic' => 'MEDIA_ARTIST'));
        $this->addCmdIfMissing('album', 'Album', 'info', 'string', array('order' => 8, 'generic' => 'MEDIA_ALBUM'));
        $this->addCmdIfMissing('station', 'Station', 'info', 'string', array('order' => 9));
        $this->addCmdIfMissing('source', 'Source', 'info', 'string', array('order' => 10, 'isVisible' => 1));
        $this->addCmdIfMissing('group', 'Groupe', 'info', 'string', array('order' => 11));
        $sleep = $this->addCmdIfMissing('sleep', 'Mise en veille dans', 'info', 'numeric', array('order' => 12, 'unite' => 'min', 'min' => 0));
        $bass = $this->addCmdIfMissing('bass', 'Graves', 'info', 'numeric', array('order' => 13, 'min' => -10, 'max' => 10));
        $treble = $this->addCmdIfMissing('treble', 'Aigus', 'info', 'numeric', array('order' => 14, 'min' => -10, 'max' => 10));
        $loudness = $this->addCmdIfMissing('loudness', 'Loudness', 'info', 'binary', array('order' => 15));
        $this->addCmdIfMissing('online', 'En ligne', 'info', 'binary', array('order' => 16));

        $this->addCmdIfMissing('previous', 'Précédent', 'action', 'other', array('order' => 100, 'isVisible' => 1, 'generic' => 'MEDIA_PREVIOUS',
            'display' => array('icon' => '<i class="fas fa-step-backward"></i>', 'showNameOndashboard' => 0)));
        $this->addCmdIfMissing('play', 'Lecture', 'action', 'other', array('order' => 101, 'isVisible' => 1, 'generic' => 'MEDIA_RESUME', 'value' => $playing,
            'display' => array('icon' => '<i class="fas fa-play"></i>', 'showNameOndashboard' => 0)));
        $this->addCmdIfMissing('pause', 'Pause', 'action', 'other', array('order' => 102, 'isVisible' => 1, 'generic' => 'MEDIA_PAUSE', 'value' => $playing,
            'display' => array('icon' => '<i class="fas fa-pause"></i>', 'showNameOndashboard' => 0)));
        $this->addCmdIfMissing('next', 'Suivant', 'action', 'other', array('order' => 103, 'isVisible' => 1, 'generic' => 'MEDIA_NEXT',
            'display' => array('icon' => '<i class="fas fa-step-forward"></i>', 'showNameOndashboard' => 0)));
        $this->addCmdIfMissing('stop', 'Stop', 'action', 'other', array('order' => 104, 'generic' => 'MEDIA_STOP',
            'display' => array('icon' => '<i class="fas fa-stop"></i>')));
        $this->addCmdIfMissing('toggle', 'Lecture ou pause', 'action', 'other', array('order' => 105, 'value' => $playing));
        $this->addCmdIfMissing('volume_set', 'Régler le volume', 'action', 'slider', array('order' => 106, 'isVisible' => 1,
            'generic' => 'SET_VOLUME', 'value' => $volume, 'min' => 0, 'max' => 100));
        $this->addCmdIfMissing('volume_down', 'Volume -', 'action', 'other', array('order' => 107,
            'display' => array('icon' => '<i class="fas fa-volume-down"></i>')));
        $this->addCmdIfMissing('volume_up', 'Volume +', 'action', 'other', array('order' => 108,
            'display' => array('icon' => '<i class="fas fa-volume-up"></i>')));
        $this->addCmdIfMissing('mute_on', 'Couper le son', 'action', 'other', array('order' => 109, 'generic' => 'MEDIA_MUTE', 'value' => $mute));
        $this->addCmdIfMissing('mute_off', 'Rétablir le son', 'action', 'other', array('order' => 110, 'generic' => 'MEDIA_UNMUTE', 'value' => $mute));
        $this->addCmdIfMissing('favorite', 'Jouer un favori', 'action', 'select', array('order' => 111, 'isVisible' => 1));
        $this->addCmdIfMissing('join', 'Rejoindre le groupe de', 'action', 'select', array('order' => 115));
        $this->addCmdIfMissing('leave', 'Quitter le groupe', 'action', 'other', array('order' => 116));
        $this->addCmdIfMissing('sleep_set', 'Minuterie de veille', 'action', 'slider', array('order' => 117, 'value' => $sleep, 'min' => 0, 'max' => 180));
        $this->addCmdIfMissing('bass_set', 'Régler les graves', 'action', 'slider', array('order' => 118, 'value' => $bass, 'min' => -10, 'max' => 10));
        $this->addCmdIfMissing('treble_set', 'Régler les aigus', 'action', 'slider', array('order' => 119, 'value' => $treble, 'min' => -10, 'max' => 10));
        $this->addCmdIfMissing('loudness_on', 'Loudness on', 'action', 'other', array('order' => 120, 'value' => $loudness));
        $this->addCmdIfMissing('loudness_off', 'Loudness off', 'action', 'other', array('order' => 121, 'value' => $loudness));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array('order' => 150, 'isVisible' => 1,
            'display' => array('icon' => '<i class="fas fa-sync"></i>', 'showNameOndashboard' => 0)));

        if ($this->isHomeTheater()) {
            $this->addCmdIfMissing('tv_active', 'TV en cours', 'info', 'binary', array('order' => 19));
            $night = $this->addCmdIfMissing('night', 'Mode nuit', 'info', 'binary', array('order' => 20));
            $speech = $this->addCmdIfMissing('speech', 'Dialogues renforcés', 'info', 'binary', array('order' => 21));
            $this->addCmdIfMissing('tv', 'Passer sur la TV', 'action', 'other', array('order' => 130, 'isVisible' => 1,
                'display' => array('icon' => '<i class="fas fa-tv"></i>')));
            $this->addCmdIfMissing('night_on', 'Mode nuit on', 'action', 'other', array('order' => 131, 'value' => $night));
            $this->addCmdIfMissing('night_off', 'Mode nuit off', 'action', 'other', array('order' => 132, 'value' => $night));
            $this->addCmdIfMissing('speech_on', 'Dialogues renforcés on', 'action', 'other', array('order' => 133, 'value' => $speech));
            $this->addCmdIfMissing('speech_off', 'Dialogues renforcés off', 'action', 'other', array('order' => 134, 'value' => $speech));
        }

        /* Listes remplies dès la création, sans attendre le relevé suivant. */
        $favorites = self::favorites();
        $cmd = $this->getCmd('action', 'favorite');
        if (!empty($favorites) && is_object($cmd) && $cmd->getConfiguration('listValue', '') === '') {
            $cmd->setConfiguration('listValue', implode(';', array_map(function ($f) { $t = self::listText($f['title']); return $t . '|' . $t; }, $favorites)));
            $cmd->save();
        }
    }

    /* Annonces : sur chaque enceinte et sur « Toutes les enceintes ». Le
     * titre porte le volume et des mots-clés (carillon, sans-carillon,
     * urgent). */
    private function createVoiceCommands() {
        $options = __('Volume et options : 40 carillon urgent', __FILE__);
        $this->addCmdIfMissing('announce_last', 'Dernière annonce', 'info', 'string', array('order' => 17));
        $this->addCmdIfMissing('announce', 'Annonce', 'action', 'message', array('order' => 113, 'isVisible' => 1,
            'display' => array('title_placeholder' => $options, 'message_placeholder' => __('Texte à dire', __FILE__))));
        $this->addCmdIfMissing('play_url', 'Jouer un son', 'action', 'message', array('order' => 112,
            'display' => array('title_placeholder' => $options,
                               'message_placeholder' => __('URL http://… ou chemin d\'un fichier audio', __FILE__))));
        $this->addCmdIfMissing('micro', 'Message vocal', 'action', 'other', array('order' => 114, 'isVisible' => 1,
            'template' => 'sonosbe::micro'));
        $this->addCmdIfMissing('replay', 'Rejouer la dernière annonce', 'action', 'other', array('order' => 140,
            'display' => array('icon' => '<i class="fas fa-redo"></i>')));
        $this->addCmdIfMissing('prepare', 'Préparer une annonce', 'action', 'message', array('order' => 141,
            'display' => array('title_disable' => 1, 'message_placeholder' => __('Texte à fabriquer à l\'avance, sans le jouer', __FILE__))));
    }

    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd($_type, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new sonosbeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * tout l'enregistrement de l'équipement. */
        $name = cleanComponanteName(__($_name, __FILE__));
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(0);
        if (isset($_options['order']))    { $cmd->setOrder($_options['order']); }
        if (!empty($_options['unite']))   { $cmd->setUnite($_options['unite']); }
        if (!empty($_options['generic'])) { $cmd->setGeneric_type($_options['generic']); }
        if (isset($_options['min']))      { $cmd->setConfiguration('minValue', $_options['min']); }
        if (isset($_options['max']))      { $cmd->setConfiguration('maxValue', $_options['max']); }
        if (!empty($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        if (isset($_options['value']) && is_object($_options['value'])) {
            $cmd->setValue($_options['value']->getId());
        }
        foreach (isset($_options['display']) ? $_options['display'] : array() as $key => $value) {
            $cmd->setDisplay($key, $value);
        }
        $cmd->save();
        return $cmd;
    }

    /* ================================================================ PAGE */

    public function toAjax() {
        if ($this->isAll()) {
            return array('id' => $this->getId(), 'all' => true,
                         'players' => array_map(function ($eq) { return $eq->getName(); }, self::configured()));
        }
        $group = $this->group();
        $caps = array_values(array_filter(explode(',', (string) $this->getConfiguration('capabilities', ''))));
        return array(
            'id'           => $this->getId(),
            'online'       => (int) $this->getCache('failures', 0) === 0 && is_array($this->getCache('now', null)),
            'failures'     => (int) $this->getCache('failures', 0),
            'problem'      => (string) $this->getCache('problem', ''),
            'now'          => $this->getCache('now', array()),
            'group'        => array_map(function ($m) { return $m['name']; }, $group['members']),
            'coordinator'  => $group['coordinator']['name'],
            'isCoordinator'=> $group['coordinator']['uid'] === $this->uid(),
            'audioClip'    => $this->hasAudioClip(),
            'homeTheater'  => $this->isHomeTheater(),
            'capabilities' => $caps,
            'favorites'    => count(self::favorites()),
            'announceUrl'  => sonosbeVoice::baseUrl(),
        );
    }
}

/* Obligatoire même réduite : sans elle, le coeur refuse de créer ou d'ouvrir
 * un équipement du plugin. */
class sonosbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        $eqLogic->runAction($this->getLogicalId(), $_options);
    }
}
