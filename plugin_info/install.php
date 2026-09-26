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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function sonosbe_install() {
    /* Le point d'entrée du démon ne parle qu'à un processus local. */
    config::save('api::sonosbe::mode', 'localhost', 'core');
}

/* Exécutée dans la requête HTTP de la page des plugins : rien de lent ici,
 * aucune interrogation d'enceinte. */
function sonosbe_update() {
    try {
        sonosbe::upgradeCommands();
    } catch (Throwable $e) {
        log::add('sonosbe', 'error', __('Mise à jour du plugin :', __FILE__) . ' ' . $e->getMessage());
    }
}

/* Appelée aussi à la simple désactivation du plugin : ne rien y détruire.
 * Piper et ses voix restent dans data/piper, réutilisés à la réactivation. */
function sonosbe_remove() {
}
