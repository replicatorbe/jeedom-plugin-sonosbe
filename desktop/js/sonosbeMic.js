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
 * Enregistrement d'un message au micro, envoyé à une enceinte Sonos.
 *
 * Partagé par le widget « Message vocal » (dashboard, mobile) et la page de
 * l'équipement. Un clic démarre, un second clic envoie ; au-delà de
 * MAX_SECONDS l'envoi part seul. Le navigateur n'ouvre le micro que sur une
 * page en HTTPS (ou sur localhost) : sinon, on le dit au lieu d'échouer en
 * silence.
 *
 * Sans dépendance à jQuery ni aux utilitaires du desktop : le mobile de
 * Jeedom ne les a pas tous.
 */
if (!window.sonosbeMic) {
  window.sonosbeMic = {
    MAX_SECONDS: 60,
    URL: 'plugins/sonosbe/core/ajax/sonosbe.ajax.php',
    recorder: null,
    stream: null,
    chunks: [],
    button: null,
    target: null,
    timer: null,
    started: 0,
    cancelled: false,
    /* Vrai pendant que le navigateur demande l'accès au micro : un second
       clic à ce moment ouvrirait un second enregistrement. */
    starting: false,

    notify: function (_message, _level) {
      if (window.jeedomUtils && typeof jeedomUtils.showAlert === 'function') {
        jeedomUtils.showAlert({ message: _message, level: _level || 'info' })
      } else if (window.jeedom && typeof jeedom.notify === 'function') {
        jeedom.notify('Sonos', _message, _level === 'danger' ? 'error' : (_level || 'success'))
      } else {
        window.alert(_message)
      }
    },

    /* Premier clic : enregistre. Second clic, sur le même bouton : envoie.
       Un clic sur un autre bouton pendant l'enregistrement l'annule. */
    toggle: function (_button, _target) {
      if (this.starting) { return }
      if (this.recorder !== null) {
        if (_button === this.button) {
          this.stop()
        } else {
          this.cancel()
        }
        return
      }
      this.start(_button, _target)
    },

    start: function (_button, _target) {
      var self = this
      if (!window.isSecureContext || !navigator.mediaDevices || typeof window.MediaRecorder === 'undefined') {
        this.notify('Le micro n\'est accessible que si Jeedom est ouvert en HTTPS (ou depuis la machine elle-même). Ouvrez Jeedom par son adresse https:// pour enregistrer un message.', 'warning')
        return
      }
      this.starting = true
      navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } }).then(function (_stream) {
        self.starting = false
        var types = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm']
        var options = {}
        for (var i = 0; i < types.length; i++) {
          if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) {
            options.mimeType = types[i]
            break
          }
        }
        self.stream = _stream
        self.chunks = []
        self.cancelled = false
        self.button = _button
        self.target = _target
        self.recorder = new MediaRecorder(_stream, options)
        self.recorder.addEventListener('dataavailable', function (_event) {
          if (_event.data && _event.data.size > 0) { self.chunks.push(_event.data) }
        })
        self.recorder.addEventListener('stop', function () { self.finish() })
        self.recorder.start()
        self.started = Date.now()
        self.render()
        self.timer = setInterval(function () {
          if ((Date.now() - self.started) / 1000 >= self.MAX_SECONDS) {
            self.stop()
          } else {
            self.render()
          }
        }, 250)
      }).catch(function (_error) {
        self.starting = false
        self.notify('Micro indisponible : ' + (_error && _error.message ? _error.message : _error), 'danger')
      })
    },

    stop: function () {
      if (this.recorder !== null && this.recorder.state !== 'inactive') {
        this.recorder.stop()
      }
    },

    cancel: function () {
      this.cancelled = true
      this.stop()
    },

    render: function () {
      if (this.button === null) { return }
      var label = this.button.querySelector('.sonosbeMicLabel')
      if (this.recorder !== null) {
        var seconds = Math.floor((Date.now() - this.started) / 1000)
        this.button.classList.add('sonosbeMicRecording')
        if (label) { label.textContent = ' ' + seconds + ' s — cliquer pour envoyer' }
      } else {
        this.button.classList.remove('sonosbeMicRecording')
        if (label) { label.textContent = this.button.getAttribute('data-label') || '' }
      }
    },

    finish: function () {
      var self = this
      clearInterval(this.timer)
      this.timer = null
      if (this.stream) {
        this.stream.getTracks().forEach(function (_track) { _track.stop() })
      }
      var mime = this.recorder && this.recorder.mimeType ? this.recorder.mimeType : 'audio/webm'
      var blob = new Blob(this.chunks, { type: mime })
      var button = this.button
      var target = this.target || {}
      var cancelled = this.cancelled
      var duration = (Date.now() - this.started) / 1000
      this.recorder = null
      this.stream = null
      this.chunks = []
      this.render()
      this.button = null
      if (cancelled) { return }
      if (duration < 0.6 || blob.size === 0) {
        this.notify('Message trop court : cliquez une fois pour enregistrer, une seconde fois pour envoyer.', 'warning')
        return
      }
      var form = new FormData()
      form.append('action', 'voice')
      if (target.cmd_id) { form.append('cmd_id', target.cmd_id) }
      if (target.id) { form.append('id', target.id) }
      if (target.volume !== undefined && target.volume !== '') { form.append('volume', target.volume) }
      var extension = mime.indexOf('mp4') !== -1 ? 'm4a' : (mime.indexOf('ogg') !== -1 ? 'ogg' : 'webm')
      form.append('audio', blob, 'message.' + extension)
      if (button) { button.classList.add('sonosbeMicSending') }
      fetch(this.URL, { method: 'POST', body: form, credentials: 'same-origin' }).then(function (_response) {
        return _response.json()
      }).then(function (_result) {
        if (_result.state !== 'ok') { throw new Error(_result.result) }
        self.notify('Message vocal envoyé.', 'success')
      }).catch(function (_error) {
        self.notify('Envoi du message impossible : ' + (_error && _error.message ? _error.message : _error), 'danger')
      }).then(function () {
        if (button) { button.classList.remove('sonosbeMicSending') }
      })
    }
  }

  /* Style commun : bouton rouge qui pulse pendant l'enregistrement. */
  var style = document.createElement('style')
  style.textContent = '.sonosbeMicRecording{background-color:#d9534f!important;color:#fff!important;animation:sonosbeMicPulse 1s infinite}'
    + '.sonosbeMicSending{opacity:.5;pointer-events:none}'
    + '@keyframes sonosbeMicPulse{50%{opacity:.65}}'
  document.head.appendChild(style)
}
