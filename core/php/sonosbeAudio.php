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
 * Sert aux enceintes les fichiers de data/audio : GET ?f=cache/<nom>.mp3
 *
 * Pourquoi un script et pas Apache directement : le .htaccess racine de
 * Jeedom n'autorise qu'une liste d'extensions (mp3, aac, mp4…). Un WAV — le
 * carillon, la voix de Piper quand ffmpeg manque — ou un M4A du micro de
 * Safari y seraient refusés (403). Et un chemin « script.php/nom.wav » y est
 * refusé aussi : d'où le paramètre.
 *
 * Sans session, comme l'exige une enceinte : les noms sont aléatoires ou
 * salés (sonosbeVoice), et seuls les fichiers audio de data/audio/cache et
 * data/audio/rec sont servis. Le coeur de Jeedom n'est pas chargé : une
 * enceinte qui lit un message ne doit pas attendre son démarrage.
 */

const SONOSBE_TYPES = array(
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'm4a'  => 'audio/mp4',
    'aac'  => 'audio/aac',
    'flac' => 'audio/flac',
    'ogg'  => 'audio/ogg',
);

$name = isset($_GET['f']) ? (string) $_GET['f'] : '';
if (!preg_match('#^(cache|rec)/([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)\.(mp3|wav|m4a|aac|flac|ogg)$#', $name, $m)) {
    http_response_code(404);
    die();
}
$root = realpath(__DIR__ . '/../../data/audio');
$file = realpath(__DIR__ . '/../../data/audio/' . $name);
if ($root === false || $file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    die();
}

$size = filesize($file);
$start = 0;
$end = $size - 1;
/* Plage d'octets : certaines enceintes lisent un fichier par morceaux. */
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $r)) {
    if ($r[1] === '' && $r[2] !== '') {
        $start = max(0, $size - (int) $r[2]);
    } else {
        $start = (int) $r[1];
        if ($r[2] !== '') {
            $end = min($end, (int) $r[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        die();
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Type: ' . SONOSBE_TYPES[$m[3]]);
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    die();
}
$handle = fopen($file, 'rb');
fseek($handle, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $left));
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($handle);
