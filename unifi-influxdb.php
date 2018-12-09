<?php
/* Check if requirements set */
if(file_exists(__DIR__ . '/vendor/autoload.php')){
	require_once(__DIR__ . '/vendor/autoload.php');
}else{
	exit('Composer not initialized!');
}

/* Check if configuration exsists */
if(file_exists(__DIR__ . '/config.php')){
	require_once(__DIR__ . '/config.php');
}else{
	exit('Configuration does not exsist.');
}

/* Check if daemon flag is set */
foreach($argv as $arg){
	if($arg == '-d' or $arg == '--daemon'){
		$daemon = true;
	}else{
		$daemon = false;
	}
}

/* Connect to UniFi Controller */
$unifi = new UniFi_API\Client($cfg['UNIFI']['USER'], $cfg['UNIFI']['PASSWORD'], $cfg['UNIFI']['URL'], $cfg['UNIFI']['SITE'], $cfg['UNIFI']['VERSION'], true);

/* Connect to InfluxDB */
if($cfg['INFLUXDB']['USER'] and $cfg['INFLUXDB']['PASSWORD']){
	$influxdb = new InfluxDB\Client($cfg['INFLUXDB']['HOST'], $cfg['INFLUXDB']['PORT'], $cfg['INFLUXDB']['USER'], $cfg['INFLUXDB']['PASSWORD'], $cfg['INFLUXDB']['SSL']);
}else{
	$influxdb = new InfluxDB\Client($cfg['INFLUXDB']['HOST'], $cfg['INFLUXDB']['PORT'], null, null, $cfg['INFLUXDB']['SSL']);
}

/* Test InfluxDB connection */
if($influxdb->listDatabases() === false){
	exit('Connection to InfluxDB not working properly!');
}

/* Use InfluxDB database and check if exsists */
$db = $influxdb->selectDB($cfg['INFLUXDB']['DB']);
if(!$db->exists()){
	exit('Database ' . $cfg['INFLUXDB']['DB'] . ' does not exsist in InfluxDB. Please create the database and start over again!');
}

/* Check UniFi Controller login */
if($unifi->login()){

	/* Beginning of daemon loop */
	do{
		/* Fetch sites from UniFi Controller */
		$unifi_sites = $unifi->list_sites();

		/* Prepare global counters */
		$total_clients = 0;
		$total_guests = 0;
		$total_devices = 0;
		$total_devices_connected = 0;
		$total_devices_disconnected = 0;

		/* Iterate through sites */
		foreach($unifi_sites as $site){

			/* Switch to site */
			$unifi->set_site($site->name);

			/* Fetch client list from UniFi Controller */
			$clients = $unifi->list_clients();

			/* Fetch device list from UniFi Controller */
			$devices = $unifi->list_devices();

			/* Prepare data arrays */
			$essid = Array();
			$aps = Array(
				'connected' => Array(),
				'disconnected' => Array()
			);

			/* Iterate through clients to fetch metrics */
			foreach($clients as $client){
				/* Check if SSID exsits and update couter variable */
				if(array_key_exists($client->essid, $essid)){
					$essid[$client->essid]++;
				}else{
					$essid[$client->essid] = 1;
				}
			}

			foreach($devices as $device){
				/* Check if device connected */
				if($device->state == 1){
					$total_devices_connected++;
					$aps['connected'][] = $device;
				}elseif($device->state == 0){
					$total_devices_disconnected++;
					$aps['disconnected'][] = $device;
				}
			}

			/* Update global data */
			$total_clients += count($clients);
			$total_devices += count($devices);

			/* Prepare data for DB */
			$data_points = Array();
			foreach($essid as $key => $value){
				$data_points[] = new InfluxDB\Point('client', $value, ['site_id' => $site->name, 'site_name' => $site->desc, 'essid' => $key]);
			}
			$data_points[] = new InfluxDB\Point('client', count($clients), ['site_id' => $site->name, 'site_name' => $site->desc, 'essid' => 'total-count']);

			/* Fetch device count */
			if(count($devices) > 0){
				$data_points[] = new InfluxDB\Point('device', count($devices), ['site_id' => $site->name, 'site_name' => $site->desc, 'state' => 'undefined']);
				$data_points[] = new InfluxDB\Point('device', count($aps['connected']), ['site_id' => $site->name, 'site_name' => $site->desc, 'state' => 'connected']);
				$data_points[] = new InfluxDB\Point('device', count($aps['disconnected']), ['site_id' => $site->name, 'site_name' => $site->desc, 'state' => 'disconnected']);
			}

			/* Write data to DB */
			$db->writePoints($data_points, InfluxDB\Database::PRECISION_SECONDS);

		}

		/* Create measurement data */
		$data_points = Array();
		$data_points[] = new InfluxDB\Point('site', count($unifi_sites));
		$data_points[] = new InfluxDB\Point('client', $total_clients, ['site_id' => 'all-sites', 'essid' => 'total-count']);

		$data_points[] = new InfluxDB\Point('device', $total_devices, ['site_id' => 'all-sites', 'state' => 'undefined']);
		$data_points[] = new InfluxDB\Point('device', $total_devices_connected, ['site_id' => 'all-sites', 'state' => 'connected']);
		$data_points[] = new InfluxDB\Point('device', $total_devices_disconnected, ['site_id' => 'all-sites', 'state' => 'disconnected']);

		$data_points[] = new InfluxDB\Point('guest', $total_guests, ['site_id' => 'all-sites']);

		/* Write data to DB */
		$db->writePoints($data_points, InfluxDB\Database::PRECISION_SECONDS);

		/* Take a nap if running in daemon mode */
		if($daemon){
			sleep(30);
		}

	/* Ending of daemon loop */
	}while($daemon);

}else{
	exit('Connection to UniFi Controller not working properly!');
}
