<?php
// A real HTTP/TLS receiver. Binding port zero lets the OS reserve the fixture port.
$certificate = $argv[1];
$context = stream_context_create( array( 'ssl' => array( 'local_cert' => $certificate, 'verify_peer' => false ) ) );
$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context );
if ( false === $server ) throw new RuntimeException( $error );
fwrite( STDOUT, stream_socket_get_name( $server, false ) . "\n" );
fflush( STDOUT );
$connection = stream_socket_accept( $server, 15 );
if ( false === $connection ) exit( 1 );
stream_set_timeout( $connection, 10 );
if ( 'http' !== $certificate && ! @stream_socket_enable_crypto( $connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER ) ) {
	fclose( $connection );
	fclose( $server );
	exit( 0 );
}
$headers = '';
while ( false !== ( $line = fgets( $connection ) ) && "\r\n" !== $line ) $headers .= $line;
preg_match( '/Content-Length:\s*(\d+)/i', $headers, $length );
if ( stripos( $headers, 'Expect: 100-continue' ) !== false ) fwrite( $connection, "HTTP/1.1 100 Continue\r\n\r\n" );
$body = '';
$remaining = (int) ( $length[1] ?? 0 );
while ( $remaining > 0 ) {
	$chunk = fread( $connection, min( 65536, $remaining ) );
	if ( false === $chunk || '' === $chunk ) exit( 2 );
	$body .= $chunk;
	$remaining -= strlen( $chunk );
}
fwrite( $connection, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n" );
fclose( $connection );
fclose( $server );
fwrite( STDOUT, json_encode( array( 'sha256' => hash( 'sha256', $body ), 'request' => strtok( $headers, "\r\n" ) ) ) . "\n" );
