<?php
	include("../classes/Translation.php");
?>

<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
       "http://www.w3.org/TR/html4/loose.dtd">

<html>
	<head>
		<title>OpenSeaMap: Karte bearbeiten</title>
		<meta name="AUTHOR" content="Olaf Hannemann" />
		<meta http-equiv="content-type" content="text/html; charset=utf-8"/>
		<meta http-equiv="content-language" content="<?=$t->getCurrentLanguage()?>" />
		<link rel="stylesheet" type="text/css" href="map-edit.css">
		<script type="text/javascript" src="http://www.openlayers.org/api/OpenLayers.js"></script>
		<script type="text/javascript" src="http://www.openstreetmap.org/openlayers/OpenStreetMap.js"></script>
		<script type="text/javascript" src="javascript/prototype.js"></script>
		<script type="text/javascript">

			//global variables
			var map;
			var layer_mapnik;
			var layer_tah;
			var layer_markers;
			var _Saving = false;			//Saving data in progress
			var _ChangeSetId = "-1";		//OSM-Changeset ID
			var _NodeId = "-1";				//OSM-Node ID
			var _Comment = null;			//Comment for Changeset
			var _Version = null;			//Version of the node
			var _xmlOsm = null;				//XML Data read from OSM database
			var _xmlNode = null;			//XML-Data for node creation
			var _userName = null;			//OSM-Username of the user
			var _userPassword = null;		//OSM-Password of the user
			var controls;					//OpenLayer-Controls
			var _ToDo = null;				//actually selected action
			var _moving = false;			//needed for cursor and first fixing
			var click;						//click-event
			var seamarkType;				//seamarks
			var arrayMarker = new Array();	//Array of displayed Markers
			var arrayNodes = new Array();	//Array of available Nodes

			// position and zoomlevel (will be overriden with permalink parameters)
			var lon = 12.0915;
			var lat = 54.1878;
			var zoom = 16;

			function init() {
				// Set current language for internationalization
				OpenLayers.Lang.setCode("<?= $t->getCurrentLanguage() ?>");
				document.getElementById("selectLanguage").value = "<?= $t->getCurrentLanguage() ?>";
				// look for existing cookies
				if (document.cookie != "")  {
					var user = readCookie("user");
					var pass = readCookie("pass");
					if (user != "-1" && pass != "-1") {
						document.getElementById('loginUsername').value = user;
						document.getElementById('loginPassword').value = pass;
						loginUser_login();
					}
				}
				// Display the map
				drawmap();
				// Load seamark-nodes from database
				updateSeamarks();
			}

			// Language selection has been changed
			function onLanguageChanged() {
				window.location.href = "./map_edit.php?lang=" + document.getElementById("selectLanguage").value;
			}

			function setCookie(key, value) {
				var expireDate = new Date;
				expireDate.setMonth(expireDate.getMonth() + 6);
				document.cookie = key + "=" + value + ";" + "expires=" + expireDate.toGMTString() + ";";
			}

			function readCookie(argument) {
 				var buff = document.cookie;
				var args = buff.split(";");
				for(i = 0; i < args.length; i++) {
					var a = args[i].split("=");
					if(trim(a[0]) == argument) {
						return trim(a[1]);
					}
				}
				return "-1";
			}

			function jumpTo(lon, lat, zoom) {
				var x = Lon2Merc(lon);
				var y = Lat2Merc(lat);
				map.setCenter(new OpenLayers.LonLat(x, y), zoom);
			}

			function Lon2Merc(lon) {
				return 20037508.34 * lon / 180;
			}

			function Lat2Merc(lat) {
				var PI = 3.14159265358979323846;
				lat = Math.log(Math.tan( (90 + lat) * PI / 360)) / (PI / 180);
				return 20037508.34 * lat / 180;
			}

			function plusfacteur(a) { return a * (20037508.34 / 180); }
			function moinsfacteur(a) { return a / (20037508.34 / 180); }
			function y2lat(a) { return 180/Math.PI * (2 * Math.atan(Math.exp(moinsfacteur(a)*Math.PI/180)) - Math.PI/2); }
			function lat2y(a) { return plusfacteur(180/Math.PI * Math.log(Math.tan(Math.PI/4+a*(Math.PI/180)/2))); }
			function x2lon(a) { return moinsfacteur(a); }
			function lon2x(a) { return plusfacteur(a); }

			function getTileURL(bounds) {
				var res = this.map.getResolution();
				var x = Math.round((bounds.left - this.maxExtent.left) / (res * this.tileSize.w));
				var y = Math.round((this.maxExtent.top - bounds.top) / (res * this.tileSize.h));
				var z = this.map.getZoom();
				var limit = Math.pow(2, z);
				if (y < 0 || y >= limit) {
					return null;
				} else {
					x = ((x % limit) + limit) % limit;
					url = this.url;
					path= z + "/" + x + "/" + y + "." + this.type;
					if (url instanceof Array) {
						url = this.selectUrl(path, url);
					}
					return url+path;
				}
			}

			OpenLayers.Control.Click = OpenLayers.Class(OpenLayers.Control, {
				defaultHandlerOptions: {
					'single': true,
					'double': false,
					'pixelTolerance': 0,
					'stopSingle': false,
					'stopDouble': false
				},
				initialize: function(options) {
					this.handlerOptions = OpenLayers.Util.extend(
						{}, this.defaultHandlerOptions
					);
					OpenLayers.Control.prototype.initialize.apply(
						this, arguments
					);
					this.handler = new OpenLayers.Handler.Click(
						this, {
							'click': this.trigger
						}, this.handlerOptions
					);
				},

				trigger: function(e) {
					var lonlat = map.getLonLatFromViewPortPx(e.xy);
					var pos  = lonlat.transform(map.getProjectionObject(),map.displayProjection);
					lon = pos.lon;
					lat = pos.lat;
					clickSeamarkMap();
				}
			});

			// Draw the map
			function drawmap() {

				OpenLayers.Lang.setCode('de');

				map = new OpenLayers.Map('map', {
					projection: new OpenLayers.Projection("EPSG:900913"),
					displayProjection: new OpenLayers.Projection("EPSG:4326"),
					eventListeners: {
						"moveend": mapEvent,
						"zoomend": mapEvent
					},
					controls: [
						new OpenLayers.Control.Permalink(),
						new OpenLayers.Control.Navigation(),
						new OpenLayers.Control.LayerSwitcher(),
						new OpenLayers.Control.MousePosition(),
						new OpenLayers.Control.ScaleLine({topOutUnits : "nmi", bottomOutUnits: "km", topInUnits: 'nmi', bottomInUnits: 'km', maxWidth: '40'}),
						new OpenLayers.Control.PanZoomBar()],
						maxExtent:
						new OpenLayers.Bounds(-20037508.34, -20037508.34, 20037508.34, 20037508.34),
					numZoomLevels: 18,
					maxResolution: 156543,
					units: 'meters'
				});

				// Mapnik
				layer_mapnik = new OpenLayers.Layer.OSM.Mapnik("Mapnik");
				// Osmarender
				layer_tah = new OpenLayers.Layer.OSM.Osmarender("Osmarender");
				// seamark
				layer_seamap = new OpenLayers.Layer.TMS("Seezeichen", "http://tiles.openseamap.org/seamark/",
				{ numZoomLevels: 18, type: 'png', getURL: getTileURL, isBaseLayer: false, displayOutsideMaxExtent: true});
				// markers
				layer_markers = new OpenLayers.Layer.Markers("Address",
				{ projection: new OpenLayers.Projection("EPSG:4326"), visibility: true, displayInLayerSwitcher: false });
				// click events
				click = new OpenLayers.Control.Click();

				map.addLayers([layer_mapnik, layer_tah, layer_seamap, layer_markers]);
				map.addControl(click);

				jumpTo(lon, lat, zoom);
			}

			// Map event listener
			function mapEvent(event) {
				// needed later on for loading data on the fly
			}

			// add a marker on the map
			function addMarker(id, popupText) {
				var pos = new OpenLayers.LonLat(Lon2Merc(lon), Lat2Merc(lat));
				var feature = new OpenLayers.Feature(layer_markers, pos);
				var size = new OpenLayers.Size(32,32);
				var offset = new OpenLayers.Pixel(-16, -16);
				var icon = new OpenLayers.Icon('./resources/action/circle_blue.png', size, offset);

				feature.closeBox = true;
				feature.popupClass = OpenLayers.Class(OpenLayers.Popup.FramedCloud, {minSize: new OpenLayers.Size(260, 100) } );
				feature.data.popupContentHTML = popupText;
				feature.data.overflow = "hidden";

				arrayMarker[id] = new OpenLayers.Marker(pos, icon.clone());
				arrayMarker[id].feature = feature;

				markerClick = function(evt) {
					if (this.popup == null) {
						this.popup = this.createPopup(this.closeBox);
						map.addPopup(this.popup);
						this.popup.show();
					} else {
						this.popup.toggle();
					}
				};
				layer_markers.addMarker(arrayMarker[id]);
				arrayMarker[id].events.register("mousedown", feature, markerClick);
			}

			// remove a marker from the map
			function removeMarker() {
				layer_markers.removeMarker(arrayMarker[_NodeId]);
			}

			// Send new node to OSM_Database
			function sendNodeOsm(action) {
				// Creating XML
				var xmlOSM = "<\?xml version='1.0' encoding='UTF-8'\?>\n"
				xmlOSM += "<osm version=\"0.6\" generator=\"OpenSeaMap-Editor\"> \n";
				xmlOSM += "<node id=\"" + _NodeId + "\" changeset=\"" + _ChangeSetId + "\" version=\"" + _Version + "\" lat=\"" + lat + "\" lon=\"" + lon + "\">\n";
				xmlOSM += _xmlNode;
				xmlOSM += "</node>\n</osm>";
				// Sending content
				osmNode(action, xmlOSM);
			}

			function closeChangeSetOsm() {
				_ChangeSetId = "-1";
			}

			function showPositionDialog() {
				// reset old values
				document.getElementById("pos-lat").value = "0.0";
				document.getElementById("pos-lon").value = "0.0";
				//show dialog
				document.getElementById("position_dialog").style.visibility = "visible";
				// activate click event for entering a new position
				click.activate();
				// set cursor to crosshair style
				map.div.style.cursor="crosshair";
				// remeber that we are in moving mode
			}

			
			function clickSeamarkMap() {
				// remove existing temp marker
				if (_moving) {
					//FIXME Dirty workaround for not getting a defined state of marker creation
					layer_markers.removeMarker(arrayMarker["2"]);
				}
				// display new coordinates
				document.getElementById("pos-lat").value = lat;
				document.getElementById("pos-lon").value = lon;
				// display temporary marker for orientation
				addMarker("2", "");
				arrayMarker["2"].setUrl('./resources/action/circle_red.png');
				// set actual position as center
				jumpTo(lon, lat, map.getZoom());
				//FIXME Dirty workaround for not getting a defined state of marker creation
				_moving = true;
			}

			function onPositionDialogCancel() {
				// hide position dialog
				document.getElementById("position_dialog").style.visibility = "collapse";
				// disable click event
				map.div.style.cursor="default";
				click.deactivate();
				_moving = false;
				// remove existing temp marker
				if (arrayMarker["2"] != 'undefined') {
					layer_markers.removeMarker(arrayMarker["2"]);
				}
				arrayMarker[_NodeId].setUrl('./resources/action/circle_blue.png');
			}

			function onEditDialogCancel(id) {
				arrayMarker[id].setUrl('./resources/action/circle_blue.png');
			}

			function addSeamark(seamark) {
				showPositionDialog();
				document.getElementById("add_seamark_dialog").style.visibility = "collapse";
				// set the seamark type
				seamarkType = seamark;
				// remember what we are doing
				_ToDo = "add";
			}

			function addSeamarkPosOk(latValue, lonValue) {
				lon = parseFloat(lonValue);
				lat = parseFloat(latValue);
				if (_NodeId != "-1") {
					arrayMarker[_NodeId].setUrl('./resources/action/circle_blue.png');
				}
				// remove existing temp marker
				if (arrayMarker["2"] != 'undefined') {
					layer_markers.removeMarker(arrayMarker["2"]);
				}
				_NodeId = "1";
				addMarker(_NodeId, "");
				arrayMarker[_NodeId].setUrl('./resources/action/circle_red.png');
				addSeamarkEdit();
			}

			function addSeamarkEdit() {
				editWindow = window.open("./dialogs/edit_seamark.php" + "?mode=create&type=" + seamarkType + "&lang=<?=$t->getCurrentLanguage()?>", "Bearbeiten", "width=630, height=420, resizable=yes");
 				editWindow.focus();
			}

			// Editing of the Seamark finished with OK
			function editSeamarkOk(xmlTags, todo) {
				_xmlNode = xmlTags;
				_ToDo = todo;
				if (!_userName) {
					alert("<?=$t->tr("logged_out_save")?>");
					_Saving = true;
					loginUser();
				} else {
					document.getElementById('send_dialog').style.visibility = 'visible';
					document.getElementById('sendComment').focus();
				}
			}

			function editSeamarkEdit(id, version, pos_lat, pos_lon) {
				if (_NodeId != "-1") {
					arrayMarker[_NodeId].setUrl('./resources/action/circle_blue.png');
				}
				_Version = version;
				_NodeId = id;
				lat = pos_lat;
				lon = pos_lon;

				if (arrayMarker[id].feature.popup != null) {
					arrayMarker[id].feature.popup.hide();
				}
				arrayMarker[id].setUrl('./resources/action/circle_red.png');
				editWindow = window.open('./dialogs/edit_seamark.php?mode=update&id=' + id + "&version=" + version + "&lang=<?=$t->getCurrentLanguage()?>" , "Bearbeiten", "width=630, height=420, resizable=yes");
 				editWindow.focus();
			}

			function moveSeamarkEdit(id, version) {
				if (_NodeId != "-1") {
					arrayMarker[_NodeId].setUrl('./resources/action/circle_blue.png');
				}
				_NodeId = id;
				_Version = version;
				if (arrayMarker[id].feature.popup != null) {
					arrayMarker[id].feature.popup.hide();
				}
				arrayMarker[id].setUrl('./resources/action/circle_yellow.png');
				showPositionDialog()
				// remember what we are doing
				_ToDo = "move";
			}

			function moveSeamarkOk() {
				// set popup text for the new marker
				var popupText = "ID = " + _NodeId;
				popupText += " - Lat = " + lat;
				popupText += " - Lon = " + lon;
				popupText += " - Version = " + _Version;
				// add marker at the new position
				addMarker(_NodeId, popupText);
				arrayMarker[_NodeId].setUrl('./resources/action/circle_red.png');
				moveSeamarkSave();
			}

			function moveSeamarkSave() {
				if (arrayMarker[id].feature.popup != null) {
					arrayMarker[id].feature.popup.hide();
				}
				editWindow = window.open('./dialogs/edit_seamark.php?mode=move&id=' + _NodeId + "&version=" + _Version + "&lang=<?=$t->getCurrentLanguage()?>", "Bearbeiten", "width=630, height=420, resizable=yes");
 				editWindow.focus();
			}

			function deleteSeamarkEdit(id, version) {
				if (_NodeId != "-1") {
					arrayMarker[_NodeId].setUrl('./resources/action/circle_blue.png');
				}
				_NodeId = id;
				_Version = version;
				if (arrayMarker[id].feature.popup != null) {
					arrayMarker[id].feature.popup.hide();
				}
				arrayMarker[id].setUrl('./resources/action/delete.png');
				editWindow = window.open('./dialogs/edit_seamark.php?mode=delete&id=' + _NodeId + "&version=" + version + "&lang=<?=$t->getCurrentLanguage()?>", "Löschen", "width=380, height=420, resizable=yes");
 				editWindow.focus();
			}

			// Entering a new position finished
			function positionOk(latValue, lonValue) {
				switch (_ToDo) {
					case "add":
						addSeamarkPosOk(latValue, lonValue);
						break;
					case "move":
						moveSeamarkOk();
						break;
				}
				// set actual position as center
				jumpTo(lon, lat, map.getZoom());
				// nothing todo left
				_ToDo = null;
				// disable click event
				map.div.style.cursor="default";
				click.deactivate();
				_moving = false;
				// hide position dialog
				document.getElementById('position_dialog').style.visibility = 'hidden';
			}

			// Open login window
			function loginUser() {
				document.getElementById('login_dialog').style.visibility = 'visible';
				document.getElementById('loginUsername').focus();
			}

			// Logout user and close changeset
			function logoutUser() {
				// close existing changeset
				if (_ChangeSetId >= 1) {
					//osmChangeSet("close", "void");
				}
				// delete user data
				_userName = null;
				_userPassword = null;
				// show login screen on the sidebar
				document.getElementById('login').style.visibility = 'visible';
				document.getElementById('logout').style.visibility = 'hidden';
				document.getElementById('loggedInName').style.visibility = 'hidden';
			}

			// Get user name and password from login dialog
			function loginUser_login() {
				_userName = document.getElementById('loginUsername').value;
				_userPassword = document.getElementById('loginPassword').value;
				setCookie("user", _userName);
				setCookie("pass", _userPassword);
				//document.getElementById('logout').innerHTML("hallo");
				document.getElementById('login').style.visibility = 'hidden';
				document.getElementById('logout').style.visibility = 'visible';
				document.getElementById('loggedInName').style.visibility = 'visible';
				document.getElementById('login_dialog').style.visibility = 'hidden';
				document.getElementById('loggedInName').innerHTML=""+ _userName +"";
				if (_Saving) {
					document.getElementById('send_dialog').style.visibility = 'visible';
					document.getElementById('sendComment').focus();
				}
			}

			function sendingOk() {
				_Comment = document.getElementById('sendComment').value;
				if (_Comment == "") {
					alert("<?=$t->tr("enterComment")?>");
					return;
				}
				if (_ChangeSetId == "-1") {
					osmChangeSet("create", _ToDo);
				} else {
					sendNodeOsm(_ToDo);
				}
				document.getElementById('send_dialog').style.visibility = 'hidden';
			}

			function showSeamarkAdd(visible) {
				if (visible) {
					document.getElementById('add_seamark_dialog').style.visibility = 'visible';
					document.getElementById('add_landmark_dialog').style.visibility = 'hidden';
					document.getElementById('add_harbour_dialog').style.visibility = 'hidden';
				} else {
					document.getElementById('add_seamark_dialog').style.visibility = 'hidden';
				}
			}

			function showLandmarkAdd(visible) {
				if (visible) {
					document.getElementById('add_landmark_dialog').style.visibility = 'visible';
					document.getElementById('add_seamark_dialog').style.visibility = 'hidden';
					document.getElementById('add_harbour_dialog').style.visibility = 'hidden';
				} else {
					document.getElementById('add_landmark_dialog').style.visibility = 'hidden';
				}
			}

			function showHarbourAdd(visible) {
				if (visible) {
					document.getElementById('add_harbour_dialog').style.visibility = 'visible';
					document.getElementById('add_seamark_dialog').style.visibility = 'hidden';
					document.getElementById('add_landmark_dialog').style.visibility = 'hidden';
				} else {
					document.getElementById('add_harbour_dialog').style.visibility = 'hidden';
				}
			}


			function updateNode() {
				// FIXME: it is not necessary to reload all nodes. The updated one should be enough.
				updateSeamarks();
			}

			function trim (buffer) {
				  return buffer.replace (/^\s+/, '').replace (/\s+$/, '');
			}

			function osmChangeSet(action, todo) {
				var url = './api/changeset.php';
				var params = new Object();
				var dialog;

				params["action"] = action;
				params["id"] = _ChangeSetId;
				params["comment"] = _Comment;
				params["userName"] = _userName;
				params["userPassword"] = _userPassword;

				if (action = "create") {
					dialog = "creating";
				} else {
					dialog = "closing";
				}
				
				document.getElementById(dialog).style.visibility = "visible";

				new Ajax.Request(url, {
					method: 'get',
					parameters : params,
					onSuccess: function(transport) {
						var response = transport.responseText;
						if (action = "create") {
							if (parseInt(response) > 0) {
								_ChangeSetId = trim(response);
								//alert(_ChangeSetId + " : " + todo);
								sendNodeOsm(todo);
								document.getElementById(dialog).style.visibility = "collapse";
								return "0";
							} else {
								document.getElementById(dialog).style.visibility = "collapse";
								alert("Erzeugen des Changesets Fehlgeschlagen: " + response);
								return "-1";
							}
						}
					},
					onFailure: function() {
						document.getElementById(dialog).style.visibility = "collapse";
						alert("damm");
						return "-1";
					},
					onException: function(request, exception) {
						document.getElementById(dialog).style.visibility = "collapse";
						alert("mist: " + exception + request);
						return "-1";
					}
				});
			}

			function osmNode(action, data) {
				var url = "./api/node.php";
				var params = new Object();
				params["action"] = action;
				params["changeset_id"] = _ChangeSetId;
				params["node_id"] = _NodeId;
				params["comment"] = _Comment;
				params["name"] = _userName;
				params["password"] = _userPassword;
				params["data"] = data;

				document.getElementById("saving").style.visibility = "visible";

				new Ajax.Request(url, {
					method: "get",
					parameters : params,
					onSuccess: function(transport) {
						var response = transport.responseText;
						switch (action) {
							case "create":
								_NodeId = trim(response);
							case "move":
							case "update":
							case "delete":
								updateNode();
								break;
							case "get":
								alert("Node= " + response);
								break;
						}
						document.getElementById("saving").style.visibility = "collapse";
						return "0";
					},
					onFailure: function() {
						document.getElementById("saving").style.visibility = "collapse";
						alert("Error while sending data");
						return "-1";
					},
					onException: function(request, exception) {
						document.getElementById("saving").style.visibility = "collapse";
						alert("Error: " + exception + request);
						return "-1";
					}
				});
			}

			// Get seamarks from database
			function updateSeamarks() {
				var zoomLevel = map.getZoom();
				if (zoomLevel > 15) {
					document.getElementById("loading").style.visibility = "visible";

					var url = './api/map.php';
					var params = new Object();
					var bounds = map.getExtent().toArray();
					params["n"] = y2lat(bounds[3]);
					params["s"] = y2lat(bounds[1]);
					params["w"] = x2lon(bounds[0]);
					params["e"] = x2lon(bounds[2]);

					new Ajax.Request(url, {
						method: 'get',
						parameters : params,
						onSuccess: function(transport) {
							var response = transport.responseText;
							_xmlOsm = response;
							readOsmXml();
							//alert(response);
							document.getElementById("loading").style.visibility = "collapse";
							if (_NodeId != "-1" && _NodeId != "1") {
								arrayMarker[_NodeId].setUrl('./resources/action/circle_green.png');
							}
							return "0";
						},
						onFailure: function() {
							alert("Error while sending data");
							document.getElementById("loading").style.visibility = "collapse";
							return "-1";
						},
						onException: function(request, exception) {
							alert("Error: " + exception);
							document.getElementById("loading").style.visibility = "collapse";
							return "-1";
						}
					});
				} else {
					alert("Der Zoomlevel ist zu klein!");
				}
			}

			function readOsmXml() {

				var xmlData = _xmlOsm;
				var xmlObject;
				var show = false;
				// Browserweiche für den DOMParser:
				// Mozilla and Netscape browsers
				if (document.implementation.createDocument) {
					xmlParser = new DOMParser();
					xmlObject = xmlParser.parseFromString(xmlData, "text/xml");
				 // MSIE
				} else if (window.ActiveXObject) {
					xmlObject = new ActiveXObject("Microsoft.XMLDOM")
					xmlObject.async="false"
					xmlObject.loadXML(xmlData)
				}
				var root = xmlObject.getElementsByTagName('osm')[0];
				var items = root.getElementsByTagName("node");

				layer_markers.clearMarkers();
				for (var i=0; i < items.length; ++i) {
					// get one node after the other
					var item = items[i];
					// Ensure Seamark is visible (don't add deleted ones)
					if(item.getAttribute("visible") == "true") {
						// get Lat/Lon of the node
						lat = parseFloat(item.getAttribute("lat"));
						lon = parseFloat(item.getAttribute("lon"));
						id = item.getAttribute("id");
						var version = parseInt(item.getAttribute("version"));
						// Set head of the popup text
						var popupText = "ID = " + id;
						popupText += " - Lat = " + lat;
						popupText += " - Lon = " + lon;
						popupText += " - Version = " + version;
						popupText += "<br/> <br/>";
						arrayNodes[id] = "";

						// Getting the tags (key value pairs)
						var tags = item.getElementsByTagName("tag");
						for (var n=0; n < tags.length; ++n) {
							var tag = tags[n];
							var key = tag.getAttribute("k");
							if (key == "seamark") {
								show = true;
							}
							var val = tag.getAttribute("v");
							arrayNodes[id] += key + "," + val + "|";
							popupText += "<br/><input type=\"text\"  size=\"25\"  name=\"kev\" value=\"" + key + "\"/>";
							popupText += " - <input type=\"text\" name=\"value\" value=\"" + val + "\"/>";
						}
						//if (show) {
							popupText += "<br/> <br/>";
							popupText += "<input type=\"button\" value=\"<?=$t->tr("edit")?>\" onclick=\"editSeamarkEdit(" + id + "," + version + "," + lat + "," + lon + ")\">&nbsp;&nbsp;";
							popupText += "<input type=\"button\" value=\"<?=$t->tr("move")?>\"onclick=\"moveSeamarkEdit(" + id + "," + version + ")\">&nbsp;&nbsp;";
							popupText += "<input type=\"button\" value=\"<?=$t->tr("delete")?>\"onclick=\"deleteSeamarkEdit(" + id + "," + version + ")\">";
							addMarker(id, popupText);
							show = false;
						//}
					}
				}
				//FIXME: dirty hack for redrawing the map. Needed for popup click events.
				map.zoomOut();
				map.zoomIn();
			}

			// Some api stuff*********************************************************************************************************
			function getChangeSetId() {
				return _ChangeSetId;
			}

			function setChangeSetId(id) {
				_ChangeSetId = id;
			}

			function getComment() {
				return _Comment;
			}

			function setComment(value) {
				_Comment = value;
			}

			function getKeys(id) {
				return arrayNodes[id];
			}

			// Event handling*********************************************************************************************************
			function checkKeyReturn(e) {
				if (e.keyCode == 13) {
					//alert("You pressed enter!");
					return true;
				} else {
					return false;
				}
			}


		</script>
	</head>
	<body onload=init();>
		<!--Sidebar ****************************************************************************************************************** -->
		<div id="head" class="sidebar" style="position:absolute; top:2px; left:0px;">
			<a><b>OpenSeaMap - Editor</b></a>
		</div>
		<div id="language" class="sidebar" style="position:absolute; top:30px; left:0px;">
			<hr>
			<?=$t->tr("language")?>:&nbsp;
			<select id="selectLanguage" onChange="onLanguageChanged()">
				<option value="en"/>English
				<option value="de"/>Deutsch
			</select>
		</div>
		<div id="login" class="sidebar" style="position:absolute; top:70px; left:0px;">
			<hr>
			<p><?=$t->tr("logged_out")?></p>
			<input type="button" value='<?=$t->tr("login")?>' onclick="loginUser()">
		</div>
		<div id="logout" class="sidebar" style="position:absolute; top:70px; left:0px; visibility:hidden;" >
			<hr>
			<p><?=$t->tr("logged_in")?></p><br/><br/>
			<input type="button" value='<?=$t->tr("logout")?>' onclick="logoutUser()" >
		</div>
		<div id="loggedInName" style="position:absolute; top:132px; left:10px; visibility:hidden;">- - -</div>
		<div style="position:absolute; top:185px; left:11.5%;"><a href="http://wiki.openstreetmap.org/wiki/de:Seekarte" target="blank"><?=$t->tr("help")?></a></div>
		<div id="data" class="sidebar" style="position:absolute; top:200px; left:0px;">
			<hr>
			<b><?=$t->tr("data")?></b>
			<br/><br/>
			<select id="pos-iala">
				<option selected value="A" disabled = "true"/>IALA - A
			</select>&nbsp; &nbsp;
			<input type="button" value='<?=$t->tr("reload")?>' onclick="updateSeamarks()">
		</div>
		<div style="position:absolute; top:295px; left:11.5%;"><a href="http://wiki.openstreetmap.org/wiki/de:Seekarte" target="blank"><?=$t->tr("help")?></a></div>
		<div id="action" class="sidebar" style="position:absolute; top:305px; left:0px;">
			<hr>
			<a><b><?=$t->tr("add")?></b></a><br/><br/>
			<table width="100%" border="0" cellspacing="0" cellpadding="5" valign="top">
				<tr>
					<td	onclick="showSeamarkAdd(true)"
						onmouseover="this.parentNode.style.backgroundColor = 'gainsboro';"
						onmouseout="this.parentNode.style.backgroundColor = 'white';"
						style="cursor:pointer"><?=$t->tr("Seezeichen")?>
					</td>
					<td>
						<IMG src="resources/action/go-next.png" width="16" height="16" align="right" border="0"/>
					</td>
				</tr>
				<tr>
					<td	onclick="showLandmarkAdd(true)"
						onmouseover="this.parentNode.style.backgroundColor = 'gainsboro';"
						onmouseout="this.parentNode.style.backgroundColor = 'white';"
						style="cursor:pointer"><?=$t->tr("Leuchtfeuer")?>
					</td>
					<td>
						<IMG src="resources/action/go-next.png" width="16" height="16" align="right" border="0"/>
					</td>
				</tr>
				<tr>
					<td	onclick="showHarbourAdd(true)"
						onmouseover="this.parentNode.style.backgroundColor = 'gainsboro';"
						onmouseout="this.parentNode.style.backgroundColor = 'white';"
						style="cursor:pointer"><?=$t->tr("Hafen")?>
					</td>
					<td>
						<IMG src="./resources/action/go-next.png" width="16" height="16" align="right" border="0"/>
					</td>
				</tr>
			</table>
		</div>
		<!--Map ********************************************************************************************************************** -->
		<div id="map" style="position:absolute; bottom:0px; right:0px;"></div>
		<div style="position:absolute; bottom:50px; left:3%;">
			Version 0.0.92.6
		</div>
		<div style="position:absolute; bottom:10px; left:4%;">
			<img src="../resources/icons/somerights20.png" title="This work is licensed under the Creative Commons Attribution-ShareAlike 2.0 License" onClick="window.open('http://creativecommons.org/licenses/by-sa/2.0')" />
		</div>
		<!--Sidebar dialogs ********************************************************************************************************** -->
		<!--Add Seamark-Data-Dialog-->
		<div id="add_seamark_dialog" class="dialog" style="position:absolute; top:50px; left:15%;">
			<?php include ("./dialogs/add_seamark.php"); ?>
		</div>
		<!--Add Landmark-Data-Dialog-->
		<div id="add_landmark_dialog" class="dialog" style="position:absolute; top:150px; left:15%; width:300px; height:300px">
			<?php include ("./dialogs/add_light.php"); ?>
		</div>
		<!--Add Harbour-Data-Dialog-->
		<div id="add_harbour_dialog" class="dialog" style="position:absolute; top:150px; left:15%; width:300px; height:300px;">
			<?php include ("./dialogs/add_harbour.php"); ?>
		</div>
		<!--Pop up dialogs  ********************************************************************************************************** -->
		<!--Position-Dialog-->
		<div id="position_dialog" class="dialog" style="position:absolute; top:25px; left:20%;">
			<?php include ("./dialogs/new_position.php"); ?>
		</div>
		<div id="login_dialog" class="dialog" style="position:absolute; top:40%; left:40%;">
			<?php include ("./dialogs/user_login.php"); ?>
		</div>
		<div id="send_dialog" class="dialog" style="position:absolute; top:45%; left:40%;">
			<?php include ("./dialogs/sending.php"); ?>
		</div>
		<!--Status dialog ************************************************************************************************************ -->
		<!--Load Data Wait-Dialog-->
		<div id="loading" class="infobox" style="position:absolute; top:50%; left:50%;">
			<img src="resources/action/wait.gif" width="22" height="22" /> &nbsp;&nbsp;<?=$t->tr("dataLoad")?>
		</div>
		<!--Create Changeset Wait-Dialog-->
		<div id="creating" class="infobox" style="position:absolute; top:50%; left:50%;">
			<img src="resources/action/wait.gif" width="22" height="22" /> &nbsp;&nbsp;<?=$t->tr("changesetCreate")?>
		</div>
		<!--Close Changeset Wait-Dialog-->
		<div id="closing" class="infobox" style="position:absolute; top:50%; left:50%;">
			<img src="resources/action/wait.gif" width="22" height="22" /> &nbsp;&nbsp;<?=$t->tr("changesetClose")?>
		</div>
		<!--Save Data Wait-Dialog-->
		<div id="saving" class="infobox" style="position:absolute; top:50%; left:50%;">
			<img src="resources/action/wait.gif" width="22" height="22" /> &nbsp;&nbsp;<?=$t->tr("dataSave")?>
		</div>
	</body>
</html>
