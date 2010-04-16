#!/usr/local/bin/php -q
<?php

error_reporting( E_ALL ^ E_NOTICE );
set_time_limit( 0 );
ob_implicit_flush();

require_once( 'System/Daemon.php' );

System_Daemon::setOptions( array( 
	'appName' => 'mongodaemon', 
	'appDir' => dirname(__FILE__),
	'appDescription' => 'memcache socket server for mongodb',
	'authorName' => 'mengo',
	'authorEmail' => 'tobsn@php.net',
	'logLocation' => '/dev/null'
));

System_Daemon::setSigHandler( SIGTERM, 'sigterm' );
function sigterm( $signal ){ if( $signal === SIGTERM ) { System_Daemon::stop(); } }

// Spawn Deamon!
System_Daemon::start();
	$connected = false;
	function mconnect() {
		global $mongo, $connected;
		if( $connected ) {
			return true;
		}
		else {
			$connected = false;
			$mongo = new Mongo( 'localhost:27018', array( 'timeout' => 2000 ) );
			if( $mongo->connect() ) {
				$connected = true;
				return true;
			}
		}
	}

#	$address = trim( shell_exec('hostname -i') );
	$address = 0;
	$port = 7734;

	function msg( $socket, $buf ) {
		global $mongo, $connected;
		$buf = explode( ' ', $buf );
		$cmd = $buf[0];
		if( $cmd == 'get' ) {
			$key = trim( (string)$buf[1] );
			$key = trim( $key );
			$collection = explode( '|', $key );
			$collection = $collection[0];
			$query = json_decode( $collection[1], true );
			if( mconnect() && !empty( $collection ) ) {
				$collection = $mongo->selectDB( $collection );
				$result = $collection->find( $query );
				$data = array();
				foreach( $result as $d ) {
					if( is_array( $d ) ) {
						$data[] = $d;
					}
				}
				$data = json_encode( $data );
			}
			$msg = 'VALUE '.$key.' 0 '.strlen( $data )."\r\n".$data."\r\nEND\r\n";
		} else {
			$msg = "ERROR\r\n";
		}
		socket_write( $socket, $msg );
	}

	if( ( $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP ) ) < 0 ) {
		echo "socket_create() failed, reason: " . socket_strerror($master) . "\n";
	}
	socket_set_option( $master, SOL_SOCKET,SO_REUSEADDR, 1 );
	if( ( $ret = socket_bind( $master, $address, $port ) ) < 0 ) {
		echo "socket_bind() failed, reason: " . socket_strerror($ret) . "\n";
	}
	if( ( $ret = socket_listen( $master, 5 ) ) < 0 ) {
		echo "socket_listen() failed, reason: " . socket_strerror($ret) . "\n";
	}
	$read_sockets = array( $master );

	while( true ) {
		$changed_sockets = $read_sockets;
		$num_changed_sockets = socket_select( $changed_sockets, $write = NULL, $except = NULL, NULL );
		foreach( $changed_sockets as $socket ) {
			if( $socket == $master ) {
				if( ( $client = socket_accept( $master ) ) < 0 ) {
					echo "socket_accept() failed: reason: " . socket_strerror($msgsock) . "\n";
					continue;
				}
				else {
					array_push( $read_sockets, $client );
				}
			}
			else {
				$bytes = socket_recv( $socket, $buffer, 1024, 0 );
				if( $bytes == 0 ) {
					$index = array_search( $socket, $read_sockets );
					unset( $read_sockets[$index] );
					socket_close( $socket );
				}
				else {
					msg( $socket, $buffer );
				}
			}
		}
	}

System_Daemon::stop();

?>
