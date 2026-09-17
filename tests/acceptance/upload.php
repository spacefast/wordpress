<?php

$fixture_dir = sys_get_temp_dir() . '/spacefast-upload-' . bin2hex( random_bytes( 6 ) );
mkdir( $fixture_dir );
file_put_contents( $fixture_dir . '/openssl.cnf', "[req]\ndistinguished_name=dn\nx509_extensions=extensions\nprompt=no\n[dn]\nCN=localhost\n[extensions]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n" );
$config = array( 'config' => $fixture_dir . '/openssl.cnf', 'private_key_bits' => 2048, 'digest_alg' => 'sha256' );
$key = openssl_pkey_new( $config );
$csr = openssl_csr_new( array( 'commonName' => 'localhost' ), $key, $config );
$cert = openssl_csr_sign( $csr, null, $key, 1, $config );
openssl_x509_export( $cert, $cert_pem );
openssl_pkey_export( $key, $key_pem );
file_put_contents( $fixture_dir . '/ca.pem', $cert_pem );
file_put_contents( $fixture_dir . '/server.pem', $cert_pem . $key_pem );
$file = $fixture_dir . '/asset.bin';
$bytes = random_bytes( 256 * 1024 );
file_put_contents( $file, $bytes );

function spacefast_receiver( string $certificate ): array {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/upload-server.php', $certificate ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) throw new RuntimeException( 'Receiver could not start.' );
	stream_set_timeout( $pipes[1], 15 );
	$address = trim( (string) fgets( $pipes[1] ) );
	spacefast_accept( 1 === preg_match( '/^127\.0\.0\.1:\d+$/', $address ), 'Receiver did not reserve a port.' );
	return array( $process, $pipes, $address );
}
function spacefast_receiver_finish( $process, array $pipes ): array {
	$receipt = json_decode( (string) stream_get_contents( $pipes[1] ), true );
	$errors = stream_get_contents( $pipes[2] );
	foreach ( $pipes as $pipe ) fclose( $pipe );
	spacefast_accept( 0 === proc_close( $process ), 'Upload receiver failed: ' . $errors );
	return $receipt ?? array();
}

$allow_host = static fn( $external, $host ) => '127.0.0.1' === $host || $external;
$allow_port = static fn( $ports, $host, $url ) => array_merge( $ports, array( (int) wp_parse_url( $url, PHP_URL_PORT ) ) );
add_filter( 'http_request_host_is_external', $allow_host, 10, 2 );
add_filter( 'http_allowed_safe_ports', $allow_port, 10, 3 );
$client = new Spacefast_Client();
try {
	list( $process, $pipes, $address ) = spacefast_receiver( $fixture_dir . '/server.pem' );
	$target = array( 'url' => 'https://' . $address . '/upload?secret=never-display', 'method' => 'PUT', 'headers' => array( 'Authorization' => 'Bearer never-display' ) );
	$failed = $client->upload_static_file( $target, $file );
	spacefast_receiver_finish( $process, $pipes );
	spacefast_accept( false === $failed['ok'] && false === $failed['retryable'], 'Untrusted TLS must fail without disabling verification.' );
	spacefast_accept( 60 === $failed['data']['curlCode'], 'Certificate failure lost its cURL code.' );
	spacefast_accept( '127.0.0.1' === $failed['data']['host'] && isset( $failed['data']['durationMs'] ), 'Transport diagnostics lack host or duration.' );
	spacefast_accept( ! str_contains( wp_json_encode( $failed ), 'never-display' ) && ! str_contains( wp_json_encode( $failed ), $fixture_dir ), 'Upload diagnostics expose a credential or local path.' );

	$trust = static function ( array $args ) use ( $fixture_dir ): array {
		$args['sslcertificates'] = $fixture_dir . '/ca.pem';
		return $args;
	};
	add_filter( 'http_request_args', $trust );
	try {
		list( $process, $pipes, $address ) = spacefast_receiver( $fixture_dir . '/server.pem' );
		$target['url'] = 'https://' . $address . '/upload?secret=never-display';
		$uploaded = $client->upload_static_file( $target, $file );
		$receipt = spacefast_receiver_finish( $process, $pipes );
		spacefast_accept( true === $uploaded['ok'], 'Streaming upload did not honor WordPress certificate configuration: ' . wp_json_encode( $uploaded ) );
		spacefast_accept( hash( 'sha256', $bytes ) === $receipt['sha256'], 'TLS upload changed or truncated bytes.' );
	} finally {
		remove_filter( 'http_request_args', $trust );
	}

	list( $process, $pipes, $address ) = spacefast_receiver( 'http' );
	define( 'WP_PROXY_HOST', '127.0.0.1' );
	define( 'WP_PROXY_PORT', (int) substr( $address, strrpos( $address, ':' ) + 1 ) );
	$target['url'] = 'http://unresolvable.sf.localhost/upload';
	$uploaded = $client->upload_static_file( $target, $file );
	$receipt = spacefast_receiver_finish( $process, $pipes );
	spacefast_accept( true === $uploaded['ok'], 'Streaming upload bypassed the WordPress proxy.' );
	spacefast_accept( hash( 'sha256', $bytes ) === $receipt['sha256'], 'Proxy upload changed the file bytes.' );
	spacefast_accept( 'PUT http://unresolvable.sf.localhost/upload HTTP/1.1' === $receipt['request'], 'The receiver was not used as an HTTP proxy.' );
} finally {
	remove_filter( 'http_request_host_is_external', $allow_host );
	remove_filter( 'http_allowed_safe_ports', $allow_port );
	foreach ( glob( $fixture_dir . '/*' ) as $path ) unlink( $path );
	rmdir( $fixture_dir );
}
fwrite( STDOUT, "Spacefast real TLS and proxy upload acceptance: PASS\n" );
