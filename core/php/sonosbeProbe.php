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
 * Témoin du test d'accès : GET …/sonosbeProbe.php?t=<jeton>
 *
 * Sert le carillon, et note qui est venu le chercher. C'est ainsi que le
 * test sait qu'une enceinte joint réellement Jeedom, au lieu de supposer
 * qu'une annonce acceptée a été entendue. Sans session : une enceinte n'en
 * a pas. Seul un jeton créé par le test, valable deux minutes, répond. Un
 * paramètre et pas un chemin « .php/jeton.wav », que le .htaccess de
 * Jeedom refuse.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../class/sonosbe.class.php';

$token = isset($_GET['t']) ? (string) $_GET['t'] : '';
if (!preg_match('#^([a-f0-9]{32})$#', $token, $m)) {
    http_response_code(404);
    die();
}
$key = 'sonosbe::probe::' . $m[1];
$probe = cache::byKey($key)->getValue(null);
if (!is_array($probe) || time() - (int) $probe['created'] > 120) {
    http_response_code(404);
    die();
}
if (!isset($probe['hits'])) {
    $probe['hits'] = array();
}
$probe['hits'][] = array('at' => time(), 'ip' => (string) $_SERVER['REMOTE_ADDR'],
                         'agent' => substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 120));
cache::set($key, $probe, 300);

$file = sonosbeVoice::chimeFile();
header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    readfile($file);
}
