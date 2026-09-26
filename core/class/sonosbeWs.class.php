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
 * L'API locale des Sonos S2 : une websocket en TLS sur le port 1443, qui
 * parle le « Control API » officiel de Sonos, sans passer par le cloud.
 *
 * On ne s'en sert que pour ce que l'UPnP ne sait pas faire : les annonces
 * (audioClip). L'enceinte baisse ce qui joue, TV comprise, passe le message
 * par-dessus, puis reprend d'elle-même. C'est ce que fait Home Assistant
 * (bibliothèque sonos-websocket), avec la même clé d'API publique.
 *
 * Client websocket minimal, écrit ici pour n'avoir aucune dépendance : une
 * connexion par commande, trames texte masquées, réponse attendue par son
 * en-tête « response ». Le certificat de l'enceinte est auto-signé : il n'est
 * pas vérifié, la connexion ne sort pas du réseau local.
 */
class sonosbeWs {

    const PORT = 1443;
    const API_KEY = '123e4567-e89b-12d3-a456-426655440000';
    const PROTOCOL = 'v1.api.smartspeaker.audio';

    private $_socket;
    private $_buffer = '';
    private $_deadline = 0.0;

    /*
     * Envoie une commande et rend [en-têtes, corps] de la réponse. Si
     * l'enceinte réclame l'identifiant du foyer (householdId), elle le donne
     * dans sa réponse d'erreur : la commande est renvoyée une fois avec lui.
     */
    public static function command($_ip, $_headers, $_body = array(), $_timeout = 5) {
        $ws = new sonosbeWs();
        $ws->open($_ip, $_timeout);
        try {
            $answer = $ws->send($_headers, $_body);
            if (!self::success($answer) && self::errorCode($answer) === 'ERROR_MISSING_PARAMETERS'
                && !isset($_headers['householdId']) && isset($answer[0]['householdId'])) {
                $_headers['householdId'] = $answer[0]['householdId'];
                $answer = $ws->send($_headers, $_body);
            }
        } finally {
            $ws->close();
        }
        if (!self::success($answer)) {
            throw new Exception(sprintf('%s refusé : %s', $_headers['command'], self::errorText($answer)));
        }
        return $answer;
    }

    public static function success($_answer) {
        return isset($_answer[0]['success']) && $_answer[0]['success'] === true;
    }

    public static function errorCode($_answer) {
        return isset($_answer[1]['errorCode']) ? (string) $_answer[1]['errorCode'] : '';
    }

    public static function errorText($_answer) {
        $code = self::errorCode($_answer);
        $reason = isset($_answer[1]['reason']) ? (string) $_answer[1]['reason'] : '';
        return trim($code . ($reason !== '' ? ' — ' . $reason : '')) ?: 'réponse inattendue';
    }

    /* ============================================================ CONNEXION */

    public function open($_ip, $_timeout) {
        $this->_deadline = microtime(true) + $_timeout;
        $context = stream_context_create(array('ssl' => array(
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        )));
        $socket = @stream_socket_client('tls://' . $_ip . ':' . self::PORT, $errno, $errstr, $_timeout,
            STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new sonosbeUnreachable(sprintf('API locale injoignable (%s:%d) : %s', $_ip, self::PORT, $errstr ?: $errno));
        }
        $this->_socket = $socket;
        $key = base64_encode(random_bytes(16));
        $this->write("GET /websocket/api HTTP/1.1\r\n"
            . 'Host: ' . $_ip . ':' . self::PORT . "\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . 'Sec-WebSocket-Protocol: ' . self::PROTOCOL . "\r\n"
            . 'X-Sonos-Api-Key: ' . self::API_KEY . "\r\n\r\n");
        while (strpos($this->_buffer, "\r\n\r\n") === false) {
            $this->fill();
        }
        list($head, $this->_buffer) = explode("\r\n\r\n", $this->_buffer, 2);
        if (!preg_match('#^HTTP/1\.1 101#', $head)) {
            $this->close();
            throw new Exception('API locale refusée : ' . strtok($head, "\r\n"));
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (stripos($head, 'Sec-WebSocket-Accept: ' . $accept) === false) {
            $this->close();
            throw new Exception('API locale : poignée de main websocket invalide');
        }
    }

    public function close() {
        if (is_resource($this->_socket)) {
            @fwrite($this->_socket, self::encodeFrame('', 0x8));
            @fclose($this->_socket);
        }
        $this->_socket = null;
    }

    /* Envoie une commande, et attend sa réponse en ignorant les événements
     * qui arriveraient entre-temps. */
    public function send($_headers, $_body) {
        $_headers['cmdId'] = bin2hex(random_bytes(4));
        $this->write(self::encodeFrame(json_encode(array($_headers, (object) $_body))));
        while (true) {
            $frame = self::decodeFrame($this->_buffer);
            if ($frame === null) {
                $this->fill();
                continue;
            }
            if ($frame['opcode'] === 0x9) {
                $this->write(self::encodeFrame($frame['payload'], 0xA));
                continue;
            }
            if ($frame['opcode'] === 0x8) {
                throw new Exception('API locale : connexion fermée par l\'enceinte');
            }
            if ($frame['opcode'] !== 0x1) {
                continue;
            }
            $message = json_decode($frame['payload'], true);
            if (!is_array($message) || !isset($message[0]) || !is_array($message[0])) {
                continue;
            }
            $head = $message[0];
            $mine = (isset($head['cmdId']) && $head['cmdId'] === $_headers['cmdId'])
                 || (isset($head['response']) && $head['response'] === $_headers['command'])
                 || (isset($head['type']) && $head['type'] === 'globalError');
            if ($mine) {
                return array($head, isset($message[1]) && is_array($message[1]) ? $message[1] : array());
            }
        }
    }

    private function write($_data) {
        $total = strlen($_data);
        $done = 0;
        while ($done < $total) {
            $n = @fwrite($this->_socket, substr($_data, $done));
            if ($n === false || $n === 0) {
                throw new Exception('API locale : écriture impossible');
            }
            $done += $n;
        }
    }

    private function fill() {
        $left = $this->_deadline - microtime(true);
        if ($left <= 0) {
            throw new Exception('API locale : pas de réponse dans le délai');
        }
        stream_set_timeout($this->_socket, (int) floor($left), (int) (($left - floor($left)) * 1000000));
        $chunk = @fread($this->_socket, 65536);
        if ($chunk === false || ($chunk === '' && (feof($this->_socket) || stream_get_meta_data($this->_socket)['timed_out']))) {
            throw new Exception('API locale : pas de réponse dans le délai');
        }
        $this->_buffer .= $chunk;
    }

    /* ================================================================ TRAMES */

    /* Trame finale ; masquée, comme l'exige la norme pour un client. */
    public static function encodeFrame($_payload, $_opcode = 0x1, $_mask = null) {
        $mask = $_mask === null ? random_bytes(4) : $_mask;
        $length = strlen($_payload);
        $frame = chr(0x80 | $_opcode);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $_payload[$i] ^ $mask[$i % 4];
        }
        return $frame . $mask . $masked;
    }

    /*
     * Extrait la première trame complète du tampon, qu'elle retire ; null si
     * elle n'est pas encore arrivée en entier. Les fragments (FIN à 0) sont
     * recollés : une réponse longue, comme la liste des favoris, peut
     * arriver en plusieurs morceaux.
     */
    public static function decodeFrame(&$_buffer) {
        $offset = 0;
        $payload = '';
        $opcode = null;
        while (true) {
            if (strlen($_buffer) < $offset + 2) {
                return null;
            }
            $b0 = ord($_buffer[$offset]);
            $b1 = ord($_buffer[$offset + 1]);
            $length = $b1 & 0x7F;
            $pos = $offset + 2;
            if ($length === 126) {
                if (strlen($_buffer) < $pos + 2) {
                    return null;
                }
                $length = unpack('n', substr($_buffer, $pos, 2))[1];
                $pos += 2;
            } elseif ($length === 127) {
                if (strlen($_buffer) < $pos + 8) {
                    return null;
                }
                $length = unpack('J', substr($_buffer, $pos, 8))[1];
                $pos += 8;
            }
            $mask = '';
            if ($b1 & 0x80) {
                if (strlen($_buffer) < $pos + 4) {
                    return null;
                }
                $mask = substr($_buffer, $pos, 4);
                $pos += 4;
            }
            if (strlen($_buffer) < $pos + $length) {
                return null;
            }
            $data = substr($_buffer, $pos, $length);
            if ($mask !== '') {
                for ($i = 0; $i < $length; $i++) {
                    $data[$i] = $data[$i] ^ $mask[$i % 4];
                }
            }
            $frameOpcode = $b0 & 0x0F;
            /* Une trame de contrôle peut s'intercaler entre deux fragments. */
            if ($frameOpcode >= 0x8 && $opcode !== null) {
                $_buffer = substr($_buffer, 0, $offset) . substr($_buffer, $pos + $length);
                return array('opcode' => $frameOpcode, 'payload' => $data);
            }
            if ($opcode === null) {
                $opcode = $frameOpcode;
            }
            $payload .= $data;
            $offset = $pos + $length;
            if ($b0 & 0x80) {
                $_buffer = (string) substr($_buffer, $offset);
                return array('opcode' => $opcode, 'payload' => $payload);
            }
        }
    }
}
