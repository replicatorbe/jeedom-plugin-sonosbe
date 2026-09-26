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

/* ================================================================== OUTILS */

function sonosbeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'une enceinte (nom de pièce, titre d'un morceau) est du
   texte, jamais du balisage. */
function sonosbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

function sonosbePrompt(_title, _value, _placeholder, _callback) {
  var options = { title: _title, value: _value, placeholder: _placeholder, callback: function (_v) { if (_v !== null) { _callback(_v) } } }
  if (typeof jeeDialog !== 'undefined') { jeeDialog.prompt(options) } else { bootbox.prompt(options) }
}

function sonosbeAlert(_title, _message, _callback) {
  var options = { title: _title, message: _message, callback: _callback }
  if (typeof jeeDialog !== 'undefined') { jeeDialog.alert(options) } else { bootbox.alert(options) }
}

function sonosbeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/sonosbe/core/ajax/sonosbe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: sonosbeEscape(result.result), level: 'danger' })
        return
      }
      _success(result)
    }
  })
}

function sonosbeFail(_error, _default) {
  jeedomUtils.showAlert({ message: sonosbeEscape((_error && _error.result) ? _error.result : _default), level: 'danger' })
}

function sonosbeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

function sonosbeText(_id, _text) {
  var el = sonosbeEl(_id)
  if (el !== null) { el.textContent = (_text === null || _text === undefined) ? '' : String(_text) }
}

function sonosbeStatus(_text, _level) {
  var span = sonosbeEl('span_sonosbeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

/* ============================================================== DÉCOUVERTE */

/*
 * La recherche part dès le clic, sur le réseau de Jeedom, et se déroule dans
 * un panneau de la page : un compteur montre qu'elle tourne, puis un bilan
 * dit ce qui a été interrogé. Pas de fenêtre de saisie au départ : celle du
 * coeur rend « null » pour un champ vide comme pour Annuler, et une
 * recherche lancée champ vide ne partait jamais.
 */
window.sonosbeSearching = false

function sonosbeSearchPanel(_level, _html) {
  var panel = sonosbeEl('div_sonosbeSearch')
  var status = sonosbeEl('div_sonosbeSearchStatus')
  if (panel === null || status === null) { return }
  panel.style.display = ''
  status.className = 'alert alert-' + _level
  status.innerHTML = _html
}

function sonosbeSearch(_subnet) {
  if (window.sonosbeSearching) { return }
  var subnet = String(_subnet || '').trim()
  var where = subnet !== '' ? sonosbeEscape(subnet) : '{{le réseau de Jeedom}}'
  var results = sonosbeEl('div_sonosbeSearchResults')
  if (results !== null) { results.innerHTML = '' }
  sonosbeEl('bt_sonosbeCreateChecked').style.display = 'none'
  window.sonosbeSearching = true
  var started = Date.now()
  var render = function () {
    var seconds = Math.floor((Date.now() - started) / 1000)
    sonosbeSearchPanel('info', '<i class="fas fa-spinner fa-spin"></i> {{Recherche en cours sur}} ' + where + ' — '
      + seconds + ' s<br><small>{{Annonces SSDP, puis interrogation de chaque adresse sur le port 1400. Comptez 5 à 10 secondes.}}</small>')
  }
  render()
  var timer = setInterval(render, 500)
  sonosbeAjax('discover', { subnet: subnet }, function (result) {
    clearInterval(timer)
    window.sonosbeSearching = false
    sonosbeShowFound(result.result)
  }, function (error) {
    clearInterval(timer)
    window.sonosbeSearching = false
    sonosbeSearchPanel('danger', '<i class="fas fa-times-circle"></i> {{La recherche a échoué :}} '
      + sonosbeEscape((error && error.result) ? error.result : (error && error.statusText) ? error.statusText : '{{erreur inconnue}}'))
  })
}

function sonosbeDiscover() {
  sonosbeSearch('')
}

function sonosbeSearchSubnet() {
  var subnet = sonosbeEl('in_sonosbeSubnet').value.trim()
  if (subnet === '') {
    jeedomUtils.showAlert({ message: '{{Saisissez un sous-réseau, par exemple 192.168.1.0/24.}}', level: 'warning' })
    return
  }
  sonosbeSearch(subnet)
}

function sonosbeSearchClose() {
  sonosbeEl('div_sonosbeSearch').style.display = 'none'
}

function sonosbeAddIp() {
  sonosbePrompt('{{Adresse IP de l\'enceinte}}', '', '192.168.1.50', function (_ip) {
    var ip = String(_ip).trim()
    if (ip === '') { return }
    sonosbeEl('div_sonosbeSearchResults').innerHTML = ''
    sonosbeSearchPanel('info', '<i class="fas fa-spinner fa-spin"></i> {{Interrogation de}} ' + sonosbeEscape(ip) + '…')
    sonosbeAjax('probe', { ip: ip }, function (result) {
      sonosbeShowFound(result.result)
    }, function (error) {
      sonosbeSearchPanel('warning', '<i class="fas fa-exclamation-triangle"></i> ' + sonosbeEscape((error && error.result) ? error.result : '{{Aucune réponse.}}'))
    })
  })
}

function sonosbeShowFound(_result) {
  var devices = (_result && _result.devices) ? _result.devices : []
  var results = sonosbeEl('div_sonosbeSearchResults')
  var create = sonosbeEl('bt_sonosbeCreateChecked')
  window.sonosbeFoundDevices = devices
  var summary = ''
  if (_result && _result.probe) {
    summary = '{{Adresse interrogée :}} ' + sonosbeEscape(_result.probe)
  } else if (_result) {
    summary = sonosbeEscape((_result.subnets || []).join(', ')) + ' — ' + sonosbeEscape(_result.scanned) + ' {{adresses interrogées}}, '
      + sonosbeEscape(_result.ssdp) + ' {{réponse(s) aux annonces SSDP}}, ' + sonosbeEscape(_result.seconds) + ' s'
  }
  if (devices.length === 0) {
    sonosbeSearchPanel('warning', '<i class="fas fa-exclamation-triangle"></i> <b>{{Aucune enceinte Sonos n\'a répondu.}}</b><br>'
      + '<small>' + summary + '</small><br>'
      + '{{Vérifiez que les enceintes sont allumées et sur le même réseau que Jeedom. Si elles sont sur un autre sous-réseau (VLAN, Wi-Fi invité), saisissez-le ci-dessous, ou ajoutez une enceinte par son adresse IP.}}')
    create.style.display = 'none'
    return
  }
  var fresh = devices.filter(function (d) { return !(d.known && d.known_ip === d.ip) }).length
  sonosbeSearchPanel('success', '<i class="fas fa-check-circle"></i> <b>' + devices.length + ' {{enceinte(s) trouvée(s)}}</b>'
    + (fresh === 0 ? ' — {{toutes déjà créées}}' : '') + '<br><small>' + summary + '</small>')
  var html = ''
  for (var i = 0; i < devices.length; i++) {
    var d = devices[i]
    var already = d.known && d.known_ip === d.ip
    html += '<div class="checkbox"><label>'
    html += '<input type="checkbox" class="sonosbeFound" data-index="' + i + '"' + (already ? ' disabled' : ' checked') + '> '
    html += '<b>' + sonosbeEscape(d.room || d.display) + '</b> — ' + sonosbeEscape(d.ip)
    html += ' <span class="label label-info">' + sonosbeEscape(d.display || d.model) + '</span>'
    html += ' <small>S' + sonosbeEscape(d.swgen) + ' · ' + sonosbeEscape(d.version) + ' · {{trouvée par}} ' + sonosbeEscape(d.source) + '</small>'
    if (d.known) {
      html += ' <span class="label label-default">{{déjà créée :}} ' + sonosbeEscape(d.known) + '</span>'
      if (d.known_ip !== d.ip) { html += ' <span class="label label-warning">{{nouvelle adresse, sera mise à jour}}</span>' }
    }
    html += '</label></div>'
  }
  results.innerHTML = html
  create.style.display = fresh > 0 ? '' : 'none'
}

function sonosbeCreateChecked() {
  var chosen = []
  document.querySelectorAll('#div_sonosbeSearchResults .sonosbeFound').forEach(function (_box) {
    if (_box.checked && !_box.disabled) {
      chosen.push({ ip: window.sonosbeFoundDevices[parseInt(_box.getAttribute('data-index'), 10)].ip })
    }
  })
  if (chosen.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucune enceinte cochée.}}', level: 'warning' })
    return
  }
  sonosbeSearchPanel('info', '<i class="fas fa-spinner fa-spin"></i> {{Création de}} ' + chosen.length + ' {{enceinte(s) : lecture de leurs possibilités, de vos favoris et de leur état…}}')
  sonosbeAjax('create', { devices: JSON.stringify(chosen) }, function (result) {
    var r = result.result
    if (r.errors && r.errors.length > 0) {
      var list = r.errors.map(function (_e) { return '<li>' + sonosbeEscape(_e) + '</li>' }).join('')
      sonosbeAlert('{{Création incomplète}}', '<p>' + r.created + ' {{enceinte(s) créée(s). Échecs :}}</p><ul>' + list + '</ul>', function () { sonosbeReload() })
      return
    }
    sonosbeReload()
  }, function (error) {
    sonosbeSearchPanel('danger', '<i class="fas fa-times-circle"></i> {{Échec de la création :}} ' + sonosbeEscape((error && error.result) ? error.result : ''))
  })
}

/* Crée l'équipement « Toutes les enceintes » s'il n'existe pas, et l'ouvre. */
function sonosbeAll() {
  sonosbeAjax('createAll', {}, function (result) {
    sonosbeReload(result.result.id)
  })
}

function sonosbeReload(_id) {
  jeedomUtils.loadPage('index.php?v=d&m=sonosbe&p=sonosbe' + (_id ? '&id=' + _id : ''))
}

/* ================================================================ ÉQUIPEMENT */

function sonosbeRender(_data) {
  var state = sonosbeEl('div_sonosbeState')
  var now = sonosbeEl('div_sonosbeNow')
  if (state === null || now === null) { return }
  if (_data.loading) {
    state.className = 'alert alert-info'
    state.textContent = '{{Chargement…}}'
    now.textContent = ''
    sonosbeText('pre_sonosbeRaw', '')
    return
  }
  if (_data.all) {
    var players = _data.players || []
    state.className = players.length > 0 ? 'alert alert-success' : 'alert alert-warning'
    state.textContent = players.length > 0 ? '{{Annonces envoyées à :}} ' + players.join(', ') : '{{Aucune enceinte active.}}'
    sonosbeText('div_sonosbeAllPlayers', players.length > 0 ? '{{Enceintes actives :}} ' + players.join(', ') : '{{Aucune enceinte active pour le moment.}}')
    sonosbeText('pre_sonosbeRaw', JSON.stringify(_data, null, 2))
    return
  }
  var caps = sonosbeEl('div_sonosbeCaps')
  if (caps !== null) {
    var labels = []
    labels.push(_data.audioClip
      ? '<span class="label label-success" title="{{API locale des S2 : le message passe par-dessus la musique ou la TV}}">{{Annonces par-dessus le son}}</span>'
      : '<span class="label label-warning" title="{{Pas d\'API locale : l\'annonce interrompt la lecture, rétablie ensuite}}">{{Annonces avec interruption}}</span>')
    if (_data.homeTheater) { labels.push('<span class="label label-info">{{Barre de son : TV, mode nuit, dialogues}}</span>') }
    labels.push('<span class="label label-default">' + sonosbeEscape(_data.favorites) + ' {{favori(s)}}</span>')
    caps.innerHTML = labels.join(' ')
  }
  if (_data.online) {
    state.className = 'alert alert-success'
    state.textContent = '{{Enceinte joignable.}}'
  } else {
    state.className = 'alert alert-warning'
    state.textContent = _data.problem ? '{{Enceinte injoignable :}} ' + _data.problem : '{{Pas encore relevée.}}'
  }
  var group = (_data.group || []).length > 1
    ? '{{Groupe :}} ' + _data.group.join(' + ') + (_data.isCoordinator ? ' ({{coordinatrice}})' : ' ({{suit}} ' + _data.coordinator + ')')
    : '{{Seule, hors de tout groupe.}}'
  var n = _data.now || {}
  var lines = []
  if (n.state) {
    var playing = { PLAYING: '{{Lecture}}', PAUSED_PLAYBACK: '{{Pause}}', STOPPED: '{{Arrêt}}', TRANSITIONING: '{{Chargement}}' }
    lines.push('<b>' + sonosbeEscape(playing[n.state] || n.state) + '</b>' + (n.title ? ' — ' + sonosbeEscape(n.title) : '') + (n.artist ? ' · ' + sonosbeEscape(n.artist) : ''))
    if (n.station) { lines.push('{{Station :}} ' + sonosbeEscape(n.station)) }
  }
  lines.push(sonosbeEscape(group))
  if (n.at) { lines.push('<small>{{Relevé le}} ' + sonosbeEscape(n.at) + '</small>') }
  now.innerHTML = lines.join('<br>')
  sonosbeText('pre_sonosbeRaw', JSON.stringify(_data, null, 2))
}

function sonosbeRefresh() {
  var id = sonosbeCurrentId()
  if (id === '') { return }
  sonosbeStatus('{{Relevé en cours…}}', 'info')
  sonosbeAjax('refresh', { id: id }, function (result) {
    sonosbeStatus('{{Relevé à jour.}}', 'success')
    sonosbeRender(result.result)
  }, function (error) {
    sonosbeStatus((error && error.result) ? error.result : '{{Échec du relevé}}', 'danger')
  })
}

function sonosbeSay() {
  var id = sonosbeCurrentId()
  var text = sonosbeEl('in_sonosbeSay').value
  var volume = document.querySelector('.eqLogicAttr[data-l2key="announce_volume"]').value
  var button = sonosbeEl('bt_sonosbeSay')
  button.classList.add('disabled')
  sonosbeAjax('say', { id: id, text: text, volume: volume }, function (result) {
    button.classList.remove('disabled')
    var messages = {
      clip: '{{Annonce envoyée, par-dessus le son.}}',
      interrupt: '{{Annonce passée, lecture rétablie.}}',
      all: '{{Annonce envoyée à toutes les enceintes.}}',
      blocked: '{{Annonce retenue par la plage de nuit.}}'
    }
    var r = result.result
    var text = messages[r.method] || '{{Annonce envoyée.}}'
    if (r.errors && r.errors.length > 0) { text += ' {{Échecs :}} ' + r.errors.join(' ; ') }
    jeedomUtils.showAlert({ message: sonosbeEscape(text), level: (r.errors && r.errors.length > 0) ? 'warning' : 'success' })
  }, function (error) {
    button.classList.remove('disabled')
    sonosbeFail(error, '{{Annonce impossible}}')
  })
}

function sonosbeMicClick() {
  if (!window.sonosbeMic) { return }
  var volume = document.querySelector('.eqLogicAttr[data-l2key="announce_volume"]').value
  window.sonosbeMic.toggle(sonosbeEl('bt_sonosbeMic'), { id: sonosbeCurrentId(), volume: volume })
}

function printEqLogic(_eqLogic) {
  sonosbeStatus('', '')
  sonosbeRender({ loading: true })
  var conf = (_eqLogic && _eqLogic.configuration) ? _eqLogic.configuration : {}
  var all = conf.kind === 'all'
  document.querySelectorAll('.sonosbeAllOnly').forEach(function (_el) { _el.style.display = all ? '' : 'none' })
  document.querySelectorAll('.sonosbeDeviceOnly').forEach(function (_el) { _el.style.display = all ? 'none' : '' })
  sonosbeText('span_sonosbeRoom', conf.room)
  sonosbeText('span_sonosbeModel', conf.model)
  sonosbeText('span_sonosbeGen', conf.swgen ? 'S' + conf.swgen : '')
  sonosbeText('span_sonosbeVersion', conf.version ? conf.version + ' (' + (conf.software || '') + ')' : '')
  var tryBox = sonosbeEl('fs_sonosbeTry')
  if (tryBox !== null) { tryBox.style.display = (_eqLogic && _eqLogic.id) ? '' : 'none' }
  if (_eqLogic && _eqLogic.id) {
    var id = String(_eqLogic.id)
    sonosbeAjax('data', { id: id }, function (result) {
      /* Réponse tardive d'un équipement ouvert avant celui-ci : ignorée. */
      if (String(result.result.id) !== String(sonosbeCurrentId())) { return }
      sonosbeRender(result.result)
    })
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'
  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : ce script est réexécuté à chaque visite. Les
   gestionnaires sont redéfinis à chaque fois ; les écouteurs, eux, ne sont
   posés qu'une fois sur le document et appellent les gestionnaires du
   moment. */
window.sonosbeHandlers = {
  click: function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_sonosbeDiscover: sonosbeDiscover,
      bt_sonosbeAddIp: sonosbeAddIp,
      bt_sonosbeAll: sonosbeAll,
      bt_sonosbeCreateChecked: sonosbeCreateChecked,
      bt_sonosbeSearchSubnet: sonosbeSearchSubnet,
      bt_sonosbeSearchClose: sonosbeSearchClose,
      bt_sonosbeRefresh: sonosbeRefresh,
      bt_sonosbeSay: sonosbeSay,
      bt_sonosbeMic: sonosbeMicClick
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
  },
  change: function () {},
  keydown: function (_event) {
    if (_event.key === 'Enter' && _event.target && _event.target.id === 'in_sonosbeSay') {
      _event.preventDefault()
      sonosbeSay()
    }
    if (_event.key === 'Enter' && _event.target && _event.target.id === 'in_sonosbeSubnet') {
      _event.preventDefault()
      sonosbeSearchSubnet()
    }
  }
}

if (!window.sonosbeListening) {
  window.sonosbeListening = true
  ;['click', 'change', 'keydown'].forEach(function (_type) {
    document.addEventListener(_type, function (_event) {
      if (window.sonosbeHandlers && typeof window.sonosbeHandlers[_type] === 'function') {
        window.sonosbeHandlers[_type](_event)
      }
    })
  })
}
