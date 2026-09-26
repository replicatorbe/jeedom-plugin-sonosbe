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
 * Point d'entrée du démon, protégé par la clé API du plugin :
 *   GET  ?apikey=…&action=players&connected=1,2  → les enceintes à écouter
 *   POST ?apikey=…&action=changed {"changed":{ID:{"topology":bool}}}
 *                                                → enceintes à relire
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../class/sonosbe.class.php';

if (!jeedom::apiAccess(init('apikey'), 'sonosbe')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('action') == 'players') {
    /* Preuve de vie du démon, et enceintes qu'il écoute réellement. */
    cache::set('sonosbe::daemon_seen', time());
    $connected = array_values(array_filter(array_map('intval', explode(',', (string) init('connected')))));
    cache::set('sonosbe::live', array('at' => time(), 'eqs' => $connected));
    header('Content-Type: application/json');
    echo json_encode(array('players' => sonosbe::daemonPlayers()));
    die();
}

if (init('action') == 'changed') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $changed = (is_array($payload) && isset($payload['changed']) && is_array($payload['changed'])) ? $payload['changed'] : array();
    try {
        sonosbe::daemonChanged($changed);
    } catch (Throwable $e) {
        log::add('sonosbe', 'error', __('Événements du démon :', __FILE__) . ' ' . $e->getMessage());
    }
    echo 'OK';
    die();
}

http_response_code(400);
echo 'Unknown action';
