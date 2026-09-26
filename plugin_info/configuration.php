<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
require_once __DIR__ . '/../core/class/sonosbe.class.php';
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-comment-dots"></i> {{Synthèse vocale}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Moteur}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="tts_engine">
					<option value="piper">{{Piper — local, gratuit, hors ligne}}</option>
					<option value="openai">{{OpenAI — en ligne, payant}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Piper tourne sur la machine Jeedom : rien ne sort de la maison. OpenAI donne des voix plus expressives, mais chaque phrase part sur Internet et se paie à l'usage. Une phrase déjà dite est gardée : elle ne se refabrique pas.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Moteur de secours}}</label>
			<div class="col-md-1">
				<input type="checkbox" class="configKey" data-l1key="tts_fallback">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Si le moteur choisi échoue (Internet coupé, clé refusée, voix manquante), l'autre prend le relais s'il est prêt.}}</span>
			</div>
		</div>

		<legend><i class="fas fa-microchip"></i> {{Piper}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Voix}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="piper_voice" id="sel_sonosbePiperVoice">
					<?php
					foreach (sonosbeVoice::VOICES as $key => $voice) {
						echo '<option value="' . $key . '">' . htmlspecialchars($voice['name']) . '</option>';
					}
					?>
				</select>
			</div>
			<div class="col-md-5">
				<a class="btn btn-default btn-sm" id="bt_sonosbePiperInstall"><i class="fas fa-download"></i> {{Télécharger Piper et cette voix}}</a>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Débit}}</label>
			<div class="col-md-2">
				<div class="input-group">
					<input type="number" class="configKey form-control" data-l1key="piper_speed" placeholder="100" min="50" max="200" step="5">
					<span class="input-group-addon">%</span>
				</div>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{100 : débit naturel. 110 à 120 pour des annonces plus vives.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{État}}</label>
			<div class="col-md-8">
				<div id="div_sonosbePiperStatus" class="help-block" style="margin:0;">{{Chargement…}}</div>
				<pre id="pre_sonosbePiperLog" style="display:none;max-height:180px;overflow:auto;margin-top:6px;"></pre>
			</div>
		</div>

		<legend><i class="fas fa-cloud"></i> {{OpenAI}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Clé API}}</label>
			<div class="col-md-4">
				<input type="password" class="configKey form-control" data-l1key="openai_key" autocomplete="new-password" placeholder="sk-…">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Modèle}}</label>
			<div class="col-md-3">
				<input type="text" class="configKey form-control" data-l1key="openai_model" list="dl_sonosbeOpenaiModels" placeholder="<?php echo sonosbeVoice::OPENAI_MODEL; ?>">
				<datalist id="dl_sonosbeOpenaiModels">
					<option value="gpt-4o-mini-tts">
					<option value="tts-1">
					<option value="tts-1-hd">
				</datalist>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Voix}}</label>
			<div class="col-md-3">
				<select class="configKey form-control" data-l1key="openai_voice">
					<?php
					foreach (sonosbeVoice::OPENAI_VOICES as $voice) {
						echo '<option value="' . $voice . '">' . ucfirst($voice) . '</option>';
					}
					?>
				</select>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Ton}}</label>
			<div class="col-md-6">
				<input type="text" class="configKey form-control" data-l1key="openai_instructions" placeholder="{{Parle en français, d'un ton calme et chaleureux.}}">
				<span class="help-block" style="margin:4px 0 0 0;">{{Consigne de diction, comprise par gpt-4o-mini-tts seulement.}}</span>
			</div>
		</div>

		<legend><i class="fas fa-moon"></i> {{Plage de nuit}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Activer}}</label>
			<div class="col-md-1">
				<input type="checkbox" class="configKey" data-l1key="night_enable">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Pendant cette plage, les annonces passent au volume de nuit, sur toutes les enceintes. Une annonce dont le titre contient « urgent » (alarme, fumée) garde son volume et passe toujours.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{De … à …}}</label>
			<div class="col-md-2">
				<input type="time" class="configKey form-control" data-l1key="night_start" placeholder="22:00">
			</div>
			<div class="col-md-2">
				<input type="time" class="configKey form-control" data-l1key="night_end" placeholder="07:00">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Volume de nuit}}</label>
			<div class="col-md-2">
				<div class="input-group">
					<input type="number" class="configKey form-control" data-l1key="night_volume" min="0" max="100" placeholder="15">
					<span class="input-group-addon">%</span>
				</div>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Un plafond : une annonce prévue plus bas reste plus basse.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Retenir les annonces des scénarios}}</label>
			<div class="col-md-1">
				<input type="checkbox" class="configKey" data-l1key="night_block">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{La nuit, les scénarios se taisent, sauf « urgent ». Ce que quelqu'un déclenche lui-même (micro, bouton du dashboard, « Rejouer ») passe toujours, au volume de nuit.}}</span>
			</div>
		</div>

		<legend><i class="fas fa-network-wired"></i> {{Réseau}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Adresse de Jeedom pour les enceintes}}</label>
			<div class="col-md-4">
				<input type="text" class="configKey form-control" data-l1key="sonos_url" placeholder="<?php echo htmlspecialchars(network::getNetworkAccess('internal')); ?>">
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Les enceintes viennent chercher chaque message à cette adresse. Vide : l'accès interne de Jeedom. Mettez une adresse en http:// : un Sonos refuse un certificat auto-signé.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-md-4 control-label">{{Vérifier}}</label>
			<div class="col-md-8">
				<a class="btn btn-default btn-sm" id="bt_sonosbeTestAccess"><i class="fas fa-satellite-dish"></i> {{Tester l'accès des enceintes}}</a>
				<span class="help-block" style="margin:4px 0 0 0;">{{Chaque enceinte active joue un court carillon, servi par Jeedom : le plugin vérifie qu'elle est bien venue le chercher. Enregistrez d'abord la configuration.}}</span>
				<div id="div_sonosbeTestAccess" style="margin-top:6px;"></div>
			</div>
		</div>

		<legend><i class="fas fa-headphones"></i> {{Essai}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Phrase}}</label>
			<div class="col-md-5">
				<input type="text" class="form-control" id="in_sonosbePreviewText" value="{{Bonjour, ceci est un essai de la synthèse vocale.}}">
			</div>
			<div class="col-md-3">
				<a class="btn btn-default btn-sm" id="bt_sonosbePreview"><i class="fas fa-play"></i> {{Écouter ici}}</a>
				<a class="btn btn-default btn-sm" id="bt_sonosbeClearCache" title="{{Efface les phrases déjà fabriquées, pour réentendre une phrase avec les réglages actuels.}}"><i class="fas fa-trash"></i> {{Vider le cache}}</a>
			</div>
		</div>
		<div class="form-group">
			<div class="col-md-offset-4 col-md-8">
				<span class="help-block" style="margin:0;">{{Enregistrez d'abord la configuration : l'essai utilise les réglages enregistrés.}}</span>
				<audio id="audio_sonosbePreview" controls style="display:none;margin-top:6px;width:100%;max-width:420px;"></audio>
			</div>
		</div>
	</fieldset>
</form>

<script>
/*
 * Téléchargement de Piper et écoute d'essai. fetch direct : la page de
 * configuration est chargée dans celle des plugins, sans le JS du plugin.
 */
(function () {
	var URL = 'plugins/sonosbe/core/ajax/sonosbe.ajax.php'
	var statusDiv = document.getElementById('div_sonosbePiperStatus')
	var logPre = document.getElementById('pre_sonosbePiperLog')
	var installButton = document.getElementById('bt_sonosbePiperInstall')
	if (!statusDiv || !installButton) {
		return
	}
	var poll = null

	function call(_action, _data) {
		var body = new URLSearchParams()
		body.append('action', _action)
		for (var key in (_data || {})) {
			body.append(key, _data[key])
		}
		return fetch(URL, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store',
			headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (_response) {
			return _response.json()
		}).then(function (_data) {
			if (!_data || _data.state !== 'ok') {
				throw new Error(_data && _data.result ? _data.result : '{{Erreur inconnue}}')
			}
			return _data.result
		})
	}

	function alertBox(_message, _level) {
		if (window.jeedomUtils) {
			jeedomUtils.showAlert({ message: _message, level: _level })
		} else {
			window.alert(_message)
		}
	}

	function line(_ok, _text) {
		var span = document.createElement('div')
		span.innerHTML = '<i class="fas ' + (_ok ? 'fa-check-circle' : 'fa-times-circle') + '" style="color:' + (_ok ? '#5cb85c' : '#d9534f') + '"></i> '
		span.appendChild(document.createTextNode(_text))
		return span
	}

	function render(_status) {
		statusDiv.innerHTML = ''
		if (_status.arch === '') {
			statusDiv.appendChild(line(false, '{{Piper n\'existe pas pour ce processeur :}} ' + _status.machine))
		}
		statusDiv.appendChild(line(_status.binary, _status.binary ? '{{Piper est installé.}}' : '{{Piper n\'est pas téléchargé.}}'))
		var installed = []
		for (var key in _status.voices) {
			if (_status.voices[key].installed) { installed.push(_status.voices[key].name) }
		}
		statusDiv.appendChild(line(installed.length > 0, installed.length > 0 ? '{{Voix présentes :}} ' + installed.join(', ') : '{{Aucune voix téléchargée.}}'))
		statusDiv.appendChild(line(_status.ffmpeg, _status.ffmpeg ? '{{ffmpeg présent : messages en MP3, micro de tous les navigateurs.}}' : '{{ffmpeg absent : messages en WAV, micro limité à Safari.}}'))
		statusDiv.appendChild(line(_status.problem === '', _status.problem === '' ? '{{Le moteur choisi est prêt.}}' : '{{Moteur choisi :}} ' + _status.problem))
		statusDiv.appendChild(line(_status.baseUrl.indexOf('https://') !== 0, '{{Les enceintes liront les messages sur}} ' + _status.baseUrl))
		if (_status.running || _status.log.length > 0) {
			logPre.style.display = ''
			logPre.textContent = _status.log.join('\n')
			logPre.scrollTop = logPre.scrollHeight
		}
		installButton.classList.toggle('disabled', _status.running)
		if (_status.running && poll === null) {
			poll = setInterval(refresh, 2000)
		}
		if (!_status.running && poll !== null) {
			clearInterval(poll)
			poll = null
		}
	}

	function refresh() {
		if (!document.body.contains(statusDiv)) {
			clearInterval(poll)
			poll = null
			return
		}
		call('piperStatus').then(render).catch(function (_error) {
			statusDiv.textContent = _error.message
		})
	}

	installButton.addEventListener('click', function () {
		if (installButton.classList.contains('disabled')) { return }
		var voice = document.getElementById('sel_sonosbePiperVoice').value
		call('piperInstall', { voice: voice }).then(function (_status) {
			render(_status)
			if (poll === null) { poll = setInterval(refresh, 2000) }
		}).catch(function (_error) { alertBox(_error.message, 'danger') })
	})

	document.getElementById('bt_sonosbePreview').addEventListener('click', function () {
		var button = this
		var audio = document.getElementById('audio_sonosbePreview')
		button.classList.add('disabled')
		call('preview', { text: document.getElementById('in_sonosbePreviewText').value }).then(function (_result) {
			button.classList.remove('disabled')
			audio.style.display = ''
			audio.src = _result.url
			audio.play()
			alertBox('{{Voix fabriquée par}} ' + _result.engine + (_result.cached ? ' {{(déjà en cache)}}' : ''), 'success')
		}).catch(function (_error) {
			button.classList.remove('disabled')
			alertBox(_error.message, 'danger')
		})
	})

	document.getElementById('bt_sonosbeTestAccess').addEventListener('click', function () {
		var button = this
		var target = document.getElementById('div_sonosbeTestAccess')
		if (button.classList.contains('disabled')) { return }
		button.classList.add('disabled')
		target.textContent = '{{Test en cours : chaque enceinte va jouer un carillon…}}'
		call('testAccess').then(function (_rows) {
			button.classList.remove('disabled')
			target.innerHTML = ''
			_rows.forEach(function (_row) {
				var div = line(_row.ok, _row.test + ' : ' + _row.detail)
				target.appendChild(div)
			})
		}).catch(function (_error) {
			button.classList.remove('disabled')
			target.textContent = ''
			alertBox(_error.message, 'danger')
		})
	})

	document.getElementById('bt_sonosbeClearCache').addEventListener('click', function () {
		call('clearCache').then(function () { alertBox('{{Cache vidé.}}', 'success') })
			.catch(function (_error) { alertBox(_error.message, 'danger') })
	})

	refresh()
})()
</script>
