# unifi-influxdb
Push UniFi Controller statistics into InfluxDB.

## Installation
### Download and Requirements
```
apt install php7.2-cli php7.2-curl composer unzip
git clone https://github.com/level66network/unifi-influxdb.git
cd unifi-influxdb
composer install
```

### Configuration
Duplicate 'config.php.tpl' as 'config.php' and edit the setting according your needs.

### Run

#### Daemon Mode
Fetches and pushes the data every 30 seconds.
```
./unifi-influxdb.php --daemon
```

#### One Time
Fetches and pushes the data only once.
```
./unifi-influxdb.php
```

### systemd
```
cp systemd/system/unifi-influxdb.service /etc/systemd/system/unifi-influxdb.service
/bin/systemctl daemon-reload
/bin/systemctl enable unifi-influxdb.service
/bin/systemctl start unifi-influxdb.service
```

