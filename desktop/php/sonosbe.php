<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('sonosbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_sonosbeDiscover">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Rechercher des Sonos}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_sonosbeAddIp">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter par adresse IP}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_sonosbeAll">
				<i class="fas fa-broadcast-tower"></i>
				<br>
				<span>{{Toutes les enceintes}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<!-- Recherche : remplie par le JS, dans la page plutôt que dans une
		     fenêtre, pour montrer qu'elle tourne et ce qu'elle a interrogé. -->
		<div id="div_sonosbeSearch" style="display:none;margin:5px;">
			<legend><i class="fas fa-search"></i> {{Recherche}}</legend>
			<div id="div_sonosbeSearchStatus" class="alert alert-info"></div>
			<div id="div_sonosbeSearchResults"></div>
			<div class="form-inline" style="margin-top:10px;">
				<a class="btn btn-success btn-sm" id="bt_sonosbeCreateChecked" style="display:none;"><i class="fas fa-check-circle"></i> {{Créer les enceintes cochées}}</a>
				<span style="margin-left:15px;">{{Chercher sur un autre sous-réseau :}}</span>
				<input type="text" class="form-control input-sm" id="in_sonosbeSubnet" placeholder="192.168.1.0/24" style="width:160px;">
				<a class="btn btn-default btn-sm" id="bt_sonosbeSearchSubnet"><i class="fas fa-search"></i> {{Chercher}}</a>
				<a class="btn btn-default btn-sm" id="bt_sonosbeSearchClose"><i class="fas fa-times"></i> {{Fermer}}</a>
			</div>
		</div>

		<legend><i class="fas fa-volume-up"></i> {{Mes enceintes}}</legend>
		<?php
		if (count($eqLogics) === 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune enceinte pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Rechercher des Sonos » : le plugin écoute les annonces du réseau et interroge toutes les adresses du sous-réseau de Jeedom. Vous pouvez aussi saisir une adresse IP.}}</li>';
			echo '<li>{{Créez les enceintes trouvées. Les commandes et la liste de vos favoris Sonos sont remplies aussitôt.}}</li>';
			echo '<li>{{Pour les annonces, ouvrez la configuration du plugin et téléchargez Piper, ou renseignez une clé OpenAI.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="' . ($eqLogic->isAll() ? 'fas fa-broadcast-tower' : ($eqLogic->isHomeTheater() ? 'fas fa-tv' : 'fas fa-volume-up')) . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-info">' . htmlspecialchars((string) $eqLogic->getConfiguration('display', '')) . '</span> ';
			echo '<span class="label label-default">' . htmlspecialchars($eqLogic->ip()) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>

						<fieldset class="sonosbeAllOnly" style="display:none;">
							<legend><i class="fas fa-broadcast-tower"></i> {{Toutes les enceintes}}</legend>
							<div class="alert alert-info">
								{{Les annonces, sons et messages vocaux de cet équipement partent sur chaque enceinte active, chacune à son volume d'annonce, avec son carillon et sa méthode. La voix n'est fabriquée qu'une fois.}}
								<div id="div_sonosbeAllPlayers" style="margin-top:6px;"></div>
							</div>
						</fieldset>

						<fieldset class="sonosbeDeviceOnly">
							<legend><i class="fas fa-bullhorn"></i> {{Annonces}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Volume}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="announce_volume" min="0" max="100" placeholder="30">
										<span class="input-group-addon">%</span>
									</div>
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Volume des annonces et des messages vocaux. Dans un scénario, le titre de la commande « Annonce » peut le remplacer et ajouter des mots-clés : « 40 », « 40 carillon », « sans-carillon », « urgent » (passe outre la plage de nuit).}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Carillon}}</label>
								<div class="col-sm-9">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="announce_chime">
									<span class="help-block" style="margin:4px 0 0 0;">{{Un « ding-dong » avant chaque annonce, pour attirer l'attention avant les premiers mots. Le mot-clé « carillon » ou « sans-carillon » dans le titre décide pour une annonce.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Méthode}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="announce_method">
										<option value="auto">{{Automatique}}</option>
										<option value="clip">{{Par-dessus le son (Sonos S2)}}</option>
										<option value="interrupt">{{En interrompant la lecture}}</option>
									</select>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Par-dessus : la musique ou la TV baissent, le message passe, tout reprend seul. En interrompant : le plugin note ce qui joue, passe le message, puis le rétablit ; seule possibilité sur une enceinte S1. Automatique : la première si l'enceinte la connaît.}}</span>
								</div>
							</div>
						</fieldset>

						<fieldset id="fs_sonosbeTry" style="display:none;">
							<legend><i class="fas fa-comment-dots"></i> {{Essayer}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Texte}}</label>
								<div class="col-sm-9">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_sonosbeSay" value="{{Bonjour, ceci est une annonce de Jeedom.}}">
										<span class="input-group-btn">
											<a class="btn btn-primary roundedRight" id="bt_sonosbeSay"><i class="fas fa-bullhorn"></i> {{Annoncer}}</a>
										</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Message vocal}}</label>
								<div class="col-sm-9">
									<a class="btn btn-default" id="bt_sonosbeMic" data-label=" {{Enregistrer un message}}"><i class="fas fa-microphone"></i><span class="sonosbeMicLabel"> {{Enregistrer un message}}</span></a>
									<span class="help-block" style="margin:4px 0 0 0;">{{Un clic pour enregistrer, un second pour envoyer. Le micro n'est accessible que si Jeedom est ouvert en https://. La commande « Message vocal » met le même bouton sur le dashboard.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6 sonosbeDeviceOnly">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-plug"></i> {{Enceinte}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.1.50">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Si elle change, le plugin la corrige seul dès qu'une autre enceinte répond.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Pièce Sonos}}</label>
								<div class="col-sm-9"><span id="span_sonosbeRoom"></span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Modèle}}</label>
								<div class="col-sm-9">
									<span id="span_sonosbeModel"></span>
									<span id="span_sonosbeGen" class="label label-default" style="margin-left:6px;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version}}</label>
								<div class="col-sm-9"><span id="span_sonosbeVersion"></span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Possibilités}}</label>
								<div class="col-sm-9" id="div_sonosbeCaps"></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_sonosbeRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<br>
									<span id="span_sonosbeStatus" style="display:inline-block;margin-top:6px;"></span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-music"></i> {{En ce moment}}</legend>
							<div id="div_sonosbeNow" class="help-block" style="margin:0 15px;"></div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_sonosbeState">{{Chargement…}}</div>
					<legend><i class="fas fa-history"></i> {{Dernières annonces}}</legend>
					<div id="div_sonosbeHistory"></div>
					<legend><i class="fas fa-code"></i> {{Dernier relevé}}</legend>
					<pre id="pre_sonosbeRaw" style="max-height:520px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'sonosbeMic', 'js', 'sonosbe'); ?>
<?php include_file('desktop', 'sonosbe', 'js', 'sonosbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
