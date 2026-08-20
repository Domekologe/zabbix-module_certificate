<?php declare(strict_types = 1);
/**
 * Certificate Monitor - "Test connection" probe.
 *
 * Opens a TLS connection from the Zabbix FRONTEND process and reports what the peer presents.
 *
 * IMPORTANT CAVEAT
 * ----------------
 * The check that is configured by this module is executed by a Zabbix agent 2, not by the frontend.
 * The frontend web server and that agent may sit in completely different network segments, may resolve
 * different DNS views and may trust different CA bundles. A failure here therefore proves nothing about
 * the monitoring that is being configured; it is a convenience check and never a hard block.
 *
 * Only PHP core functions are used: stream_socket_client() with an SSL context, and openssl_x509_parse().
 * No Composer dependency and no shell command.
 *
 * Author: Domekologe <support@domekologe.eu>
 */

namespace Modules\CertMonitor\Includes;

class CertProbe {

	/**
	 * Connection timeout in seconds, for both the TCP connect and the TLS handshake.
	 */
	public const TIMEOUT = 5;

	/**
	 * Probe one website.
	 *
	 * @param string $hostname  DNS name used for SNI and for host name verification.
	 * @param int    $port      TCP port.
	 * @param string $address   Optional address used to connect instead of $hostname.
	 *
	 * @return array  See makeResult() for the structure. 'ok' is false on any failure.
	 */
	public static function probe(string $hostname, int $port, string $address = ''): array {
		$result = self::makeResult($hostname, $port, $address);

		if (!extension_loaded('openssl')) {
			$result['error'] = _('The PHP OpenSSL extension is not available on the frontend server, so no test connection can be made.');

			return $result;
		}

		$connect_to = $address !== '' ? $address : $hostname;

		// Step 1: name resolution. Reported separately so that a DNS problem is distinguishable.
		if (filter_var($connect_to, FILTER_VALIDATE_IP) !== false) {
			$result['resolved'] = [$connect_to];
		}
		else {
			$resolved = self::resolve($connect_to);

			if (!$resolved) {
				$result['error'] = _s('The name "%1$s" could not be resolved by the frontend server.', $connect_to);

				return $result;
			}

			$result['resolved'] = $resolved;
		}

		// Step 2: TCP + TLS, accepting any certificate so that even a broken one can be inspected.
		$sent_chain = [];
		$peer = self::connect($connect_to, $port, $hostname, false, $error, $sent_chain);

		if ($peer === null) {
			$result['error'] = $error;

			return $result;
		}

		$result['connected'] = true;
		$result['chain'] = self::describeChain($sent_chain);
		$result['chain_length'] = count($result['chain']);

		// Step 3: parse the certificate that the peer presented.
		$parsed = @openssl_x509_parse($peer);

		if (!is_array($parsed)) {
			$result['error'] = _('The peer presented a certificate that could not be parsed.');

			return $result;
		}

		$result = self::describe($result, $parsed);

		// Step 4: repeat the handshake with full verification to find out whether a client that trusts the
		// system CA bundle would accept this certificate.
		$verify_error = '';
		$verified = self::connect($connect_to, $port, $hostname, true, $verify_error) !== null;

		$result['verified'] = $verified;
		$result['verify_error'] = $verified ? '' : $verify_error;

		// A server that sends only its own certificate leaves the client to find the intermediate on
		// its own. Browsers and OpenSSL-based tools often paper over that by fetching it via the AIA
		// extension or by reusing a cached copy; Go - and therefore Zabbix agent 2 - never does, and
		// reports "certificate signed by unknown authority" instead. Detecting it here explains why
		// the same CA works elsewhere and fails on this endpoint.
		$result['chain_incomplete'] = !$verified
			&& $result['chain_length'] <= 1
			&& !$result['self_signed'];

		$result['ok'] = true;

		return $result;
	}

	/**
	 * Empty result skeleton, so that every caller sees the same keys.
	 *
	 * @return array
	 */
	private static function makeResult(string $hostname, int $port, string $address): array {
		return [
			'ok' => false,
			'error' => '',
			'hostname' => $hostname,
			'port' => $port,
			'address' => $address,
			'resolved' => [],
			'connected' => false,
			'subject_cn' => '',
			'issuer_cn' => '',
			'not_before' => null,
			'not_after' => null,
			'days_left' => null,
			'alternative_names' => [],
			'self_signed' => false,
			'verified' => false,
			'verify_error' => '',
			'chain' => [],
			'chain_length' => 0,
			'chain_incomplete' => false
		];
	}

	/**
	 * Reduce the certificates the server sent into readable "subject <- issuer" lines.
	 *
	 * @param array $chain  Certificate resources captured with capture_peer_cert_chain.
	 *
	 * @return array  List of ['subject' => string, 'issuer' => string].
	 */
	private static function describeChain(array $chain): array {
		$described = [];

		foreach ($chain as $certificate) {
			$parsed = @openssl_x509_parse($certificate);

			if (!is_array($parsed)) {
				continue;
			}

			$described[] = [
				'subject' => self::distinguishedName($parsed['subject'] ?? []),
				'issuer' => self::distinguishedName($parsed['issuer'] ?? [])
			];
		}

		return $described;
	}

	/**
	 * Resolve a DNS name to a list of A/AAAA addresses.
	 *
	 * gethostbynamel() only knows about IPv4, so an AAAA lookup is added when the DNS functions are
	 * available. A name that only has an AAAA record still counts as resolvable.
	 *
	 * @param string $name
	 *
	 * @return array  List of addresses; empty when the name does not resolve.
	 */
	private static function resolve(string $name): array {
		$addresses = [];

		$ipv4 = @gethostbynamel($name);

		if (is_array($ipv4)) {
			$addresses = $ipv4;
		}

		if (function_exists('dns_get_record')) {
			$records = @dns_get_record($name, DNS_AAAA);

			if (is_array($records)) {
				foreach ($records as $record) {
					if (array_key_exists('ipv6', $record)) {
						$addresses[] = (string) $record['ipv6'];
					}
				}
			}
		}

		return array_values(array_unique($addresses));
	}

	/**
	 * Open one TLS connection and return the captured peer certificate resource.
	 *
	 * @param string      $connect_to  Address or name to connect to.
	 * @param int         $port
	 * @param string      $sni         Name sent as SNI and used for host name verification.
	 * @param bool        $verify      Whether the peer and its name have to verify successfully.
	 * @param string|null $error       Receives a human readable error message on failure.
	 *
	 * @return mixed  The peer certificate (an OpenSSL certificate resource/object), or null on failure.
	 */
	private static function connect(string $connect_to, int $port, string $sni, bool $verify, ?string &$error,
			?array &$chain = null) {
		$error = '';
		$chain = [];

		$context = stream_context_create([
			'ssl' => [
				'capture_peer_cert' => true,
				// The chain the server actually sends is the single most useful diagnostic for
				// "certificate signed by unknown authority": Go, and therefore the Zabbix agent, does
				// not fetch a missing intermediate via AIA the way browsers do.
				'capture_peer_cert_chain' => true,
				'verify_peer' => $verify,
				'verify_peer_name' => $verify,
				'allow_self_signed' => !$verify,
				'SNI_enabled' => true,
				'peer_name' => $sni,
				// Do not follow a peer that offers an absurd chain.
				'verify_depth' => 10
			]
		]);

		// An IPv6 literal has to be bracketed in a stream URL.
		$literal = strpos($connect_to, ':') !== false && filter_var($connect_to, FILTER_VALIDATE_IP) !== false
			? '['.$connect_to.']'
			: $connect_to;

		$errno = 0;
		$errstr = '';

		$stream = @stream_socket_client('ssl://'.$literal.':'.$port, $errno, $errstr, self::TIMEOUT,
			STREAM_CLIENT_CONNECT, $context
		);

		if ($stream === false) {
			$error = $errstr !== ''
				? $errstr
				: _s('Cannot connect to %1$s:%2$s.', $connect_to, (string) $port);

			// stream_socket_client() puts the OpenSSL detail into the last PHP warning, which is
			// suppressed above; error_get_last() is the only way to recover it.
			$last = error_get_last();

			if ($last !== null && strpos((string) $last['message'], 'stream_socket_client') !== false) {
				$detail = trim(preg_replace('/^.*?:\s*/', '', (string) $last['message']));

				if ($detail !== '' && stripos($error, $detail) === false) {
					$error .= ' ('.$detail.')';
				}
			}

			return null;
		}

		$params = stream_context_get_params($stream);
		fclose($stream);

		if (!array_key_exists('options', $params) || !array_key_exists('ssl', $params['options'])
				|| !array_key_exists('peer_certificate', $params['options']['ssl'])) {
			$error = _('The TLS handshake succeeded but the peer certificate could not be captured.');

			return null;
		}

		if (array_key_exists('peer_certificate_chain', $params['options']['ssl'])
				&& is_array($params['options']['ssl']['peer_certificate_chain'])) {
			$chain = $params['options']['ssl']['peer_certificate_chain'];
		}

		return $params['options']['ssl']['peer_certificate'];
	}

	/**
	 * Copy the interesting fields out of the openssl_x509_parse() output into the result.
	 *
	 * @param array $result
	 * @param array $parsed
	 *
	 * @return array
	 */
	private static function describe(array $result, array $parsed): array {
		$result['subject_cn'] = self::distinguishedName($parsed['subject'] ?? []);
		$result['issuer_cn'] = self::distinguishedName($parsed['issuer'] ?? []);

		if (array_key_exists('validFrom_time_t', $parsed)) {
			$result['not_before'] = (int) $parsed['validFrom_time_t'];
		}

		if (array_key_exists('validTo_time_t', $parsed)) {
			$result['not_after'] = (int) $parsed['validTo_time_t'];
			$result['days_left'] = CertHelper::daysLeft($result['not_after']);
		}

		// "subjectAltName" is a comma separated list of typed entries, e.g. "DNS:a.example, DNS:b.example".
		if (array_key_exists('extensions', $parsed) && is_array($parsed['extensions'])
				&& array_key_exists('subjectAltName', $parsed['extensions'])) {
			foreach (explode(',', (string) $parsed['extensions']['subjectAltName']) as $entry) {
				$entry = trim($entry);

				if ($entry !== '') {
					$result['alternative_names'][] = $entry;
				}
			}
		}

		$result['self_signed'] = $result['subject_cn'] !== '' && $result['subject_cn'] === $result['issuer_cn'];

		return $result;
	}

	/**
	 * Render a distinguished name array as "CN=..., O=..." with the common name first.
	 *
	 * @param mixed $dn  The "subject" or "issuer" array of openssl_x509_parse().
	 *
	 * @return string
	 */
	private static function distinguishedName($dn): string {
		if (!is_array($dn)) {
			return '';
		}

		$parts = [];

		// The common name is the interesting part, so it is printed first when present.
		foreach (['CN', 'O', 'OU', 'C'] as $key) {
			if (!array_key_exists($key, $dn)) {
				continue;
			}

			$value = $dn[$key];

			if (is_array($value)) {
				$value = implode('/', array_map('strval', $value));
			}

			$parts[] = $key.'='.$value;
		}

		return implode(', ', $parts);
	}
}
