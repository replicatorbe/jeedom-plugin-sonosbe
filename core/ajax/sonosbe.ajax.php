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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* Les classes annexes (voix, protocoles) ne sont pas chargées par
     * l'autoload du coeur, qui ne connaît que la classe du plugin. */
    require_once __DIR__ . '/../class/sonosbe.class.php';

    if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    /* ------------------------------------------------------------------
     * Ce qui suit sert au dashboard : tout utilisateur connecté, pourvu
     * qu'il ait le droit d'utiliser l'équipement.
     * ------------------------------------------------------------------ */

    /* Pochette de ce qui joue, relayée par Jeedom : l'enceinte la sert en
     * HTTP sur le réseau local, qu'un navigateur en HTTPS ou hors de la
     * maison ne peut pas joindre. Seule l'adresse relevée sur l'enceinte est
     * suivie, jamais une URL fournie par la requête. */
    if (init('action') == 'cover') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'sonosbe' || !$eqLogic->hasRight('r')) {
            http_response_code(404);
            die();
        }
        $now = $eqLogic->getCache('now', array());
        $url = is_array($now) && isset($now['art']) ? (string) $now['art'] : '';
        if (!preg_match('#^https?://#i', $url)) {
            http_response_code(404);
            die();
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                                     CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
                                     CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS));
        $image = curl_exec($ch);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($image === false || $code !== 200 || strpos($type, 'image/') !== 0) {
            http_response_code(404);
            die();
        }
        header_remove('Content-Type');
        header('Content-Type: ' . $type);
        header('Cache-Control: private, max-age=3600');
        echo $image;
        die();
    }

    ajax::init();

    /* Message enregistré au micro, depuis le dashboard ou la page du
     * plugin. */
    if (init('action') == 'voice') {
        $cmd = cmd::byId(init('cmd_id'));
        $eqLogic = is_object($cmd) ? $cmd->getEqLogic() : eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'sonosbe') {
            throw new Exception(__('Équipement introuvable.', __FILE__));
        }
        if (!$eqLogic->hasRight('x')) {
            throw new Exception(__('Vous n\'avez pas le droit d\'utiliser cette enceinte.', __FILE__));
        }
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(__('Enregistrement non reçu.', __FILE__));
        }
        $clip = sonosbeVoice::recording($_FILES['audio']['tmp_name'], (string) $_FILES['audio']['type']);
        /* Quelqu'un vient de parler dans le micro : la plage de nuit baisse
         * le volume, mais ne retient pas le message. */
        ajax::success($eqLogic->playClip($clip, array('title' => (string) init('volume', ''),
            'label' => __('Message vocal', __FILE__), 'manual' => true)));
    }

    /* ------------------------------------------------------------------
     * Le reste : page du plugin, administrateurs seulement.
     * ------------------------------------------------------------------ */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    function sonosbeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'sonosbe') {
            throw new Exception(__('Équipement introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    if (init('action') == 'discover') {
        unautorizedInDemo();
        ajax::success(sonosbe::discover(init('subnet')));
    }

    if (init('action') == 'probe') {
        unautorizedInDemo();
        $device = sonosbe::probe(init('ip'));
        $device['source'] = 'IP';
        ajax::success(array('devices' => array($device)));
    }

    if (init('action') == 'create') {
        unautorizedInDemo();
        $devices = json_decode(init('devices'), true);
        if (!is_array($devices) || count($devices) === 0) {
            throw new Exception(__('Aucune enceinte à créer.', __FILE__));
        }
        $created = 0;
        $errors = array();
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['ip'])) {
                continue;
            }
            try {
                /* L'enceinte est réinterrogée : on ne croit pas le navigateur
                 * sur parole. */
                sonosbe::createFromProbe(sonosbe::probe($device['ip']));
                $created++;
            } catch (Throwable $e) {
                $errors[] = $device['ip'] . ' : ' . $e->getMessage();
            }
        }
        ajax::success(array('created' => $created, 'errors' => $errors));
    }

    if (init('action') == 'refresh') {
        $eqLogic = sonosbeEq();
        if ($eqLogic->isAll()) {
            ajax::success($eqLogic->toAjax());
        }
        sonosbe::refreshTopology(array($eqLogic));
        sonosbe::refreshFavorites(array($eqLogic));
        try {
            $eqLogic->detectCapabilities();
        } catch (Throwable $e) {
            log::add('sonosbe', 'debug', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        $eqLogic->poll();
        ajax::success($eqLogic->toAjax());
    }

    if (init('action') == 'data') {
        ajax::success(sonosbeEq()->toAjax());
    }

    /* Essai d'annonce depuis la page de l'équipement. */
    if (init('action') == 'say') {
        ajax::success(sonosbeEq()->announce(init('text'), init('volume'), true));
    }

    /* Écoute dans le navigateur, sans passer par une enceinte. */
    if (init('action') == 'preview') {
        $clip = sonosbeVoice::speak(init('text'));
        ajax::success(array('url' => sonosbeVoice::relativeUrl($clip['file']) . '&t=' . time(),
                            'engine' => $clip['engine'], 'duration' => $clip['duration'], 'cached' => $clip['cached']));
    }

    /* L'équipement « Toutes les enceintes », créé à la demande. */
    if (init('action') == 'createAll') {
        unautorizedInDemo();
        ajax::success(array('id' => sonosbe::allEquipment(true)->getId()));
    }

    /* Chaque enceinte joint-elle Jeedom ? Joue un carillon sur chacune. */
    if (init('action') == 'testAccess') {
        unautorizedInDemo();
        ajax::success(sonosbe::testAccess());
    }

    if (init('action') == 'piperStatus') {
        ajax::success(sonosbeVoice::piperStatus());
    }

    if (init('action') == 'piperInstall') {
        unautorizedInDemo();
        sonosbeVoice::installPiper(init('voice'));
        ajax::success(sonosbeVoice::piperStatus());
    }

    if (init('action') == 'clearCache') {
        sonosbeVoice::clearCache();
        ajax::success();
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable : en PHP 8 une Error n'hérite pas d'Exception. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
