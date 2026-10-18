<?php
/**
 * Minimal OpenID Connect client for the AgentID issuer.
 *
 * Scope is deliberately narrow and fail-closed:
 *  - Authorization Code flow only, PKCE S256 mandatory.
 *  - ID tokens must be ES256 and are verified against the issuer JWKS
 *    with OpenSSL. No other algorithm is accepted.
 *  - Issuer, audience, expiry, issued-at, nonce and actor_type are
 *    all checked before any claim is trusted.
 *
 * @package AgentKeySignIn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OIDC client for the AgentID issuer.
 */
class AgentID_OIDC {

	const DISCOVERY_TTL = 43200; // 12 hours.
	const JWKS_TTL      = 21600; // 6 hours.
	const LEEWAY        = 60;

	/**
	 * Fetch (and cache) the issuer discovery document.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|WP_Error
	 */
	public static function discovery( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'agentid_sso_discovery' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = wp_remote_get(
			AGENTID_ISSUER . '/.well-known/openid-configuration',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'agentid_discovery_http', __( 'AgentID discovery did not return HTTP 200.', 'agentkey-sign-in-agentid' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['issuer'] ) || AGENTID_ISSUER !== $data['issuer'] ) {
			return new WP_Error( 'agentid_discovery_issuer', __( 'AgentID discovery returned an unexpected issuer.', 'agentkey-sign-in-agentid' ) );
		}
		foreach ( array( 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint' ) as $field ) {
			if ( empty( $data[ $field ] ) || 0 !== strpos( $data[ $field ], AGENTID_ISSUER . '/' ) ) {
				return new WP_Error( 'agentid_discovery_endpoint', __( 'AgentID discovery returned an unexpected endpoint.', 'agentkey-sign-in-agentid' ) );
			}
		}
		if ( empty( $data['id_token_signing_alg_values_supported'] ) || ! is_array( $data['id_token_signing_alg_values_supported'] ) || ! in_array( 'ES256', $data['id_token_signing_alg_values_supported'], true ) ) {
			return new WP_Error( 'agentid_discovery_alg', __( 'AgentID discovery does not advertise ES256.', 'agentkey-sign-in-agentid' ) );
		}
		set_transient( 'agentid_sso_discovery', $data, self::DISCOVERY_TTL );
		return $data;
	}

	/**
	 * Fetch (and cache) the issuer JWKS.
	 *
	 * @param bool       $force           Skip the cache.
	 * @param array|null $known_discovery An already-fetched discovery
	 *                                    document to reuse instead of
	 *                                    fetching it again (the
	 *                                    settings connection test
	 *                                    validates discovery and JWKS
	 *                                    back to back).
	 * @return array|WP_Error JWKS document with a "keys" array.
	 */
	public static function jwks( $force = false, $known_discovery = null ) {
		if ( ! $force ) {
			$cached = get_transient( 'agentid_sso_jwks' );
			if ( is_array( $cached ) && ! empty( $cached['keys'] ) ) {
				return $cached;
			}
		}
		$discovery = is_array( $known_discovery ) ? $known_discovery : self::discovery( $force );
		if ( is_wp_error( $discovery ) ) {
			return $discovery;
		}
		$response = wp_remote_get(
			$discovery['jwks_uri'],
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'agentid_jwks_http', __( 'AgentID JWKS did not return HTTP 200.', 'agentkey-sign-in-agentid' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
			return new WP_Error( 'agentid_jwks_shape', __( 'AgentID JWKS returned no keys.', 'agentkey-sign-in-agentid' ) );
		}
		set_transient( 'agentid_sso_jwks', $data, self::JWKS_TTL );
		return $data;
	}

	/**
	 * Base64url-decode.
	 *
	 * @param string $data Base64url input.
	 * @return string|false
	 */
	public static function b64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a JWT segment, not obfuscation.
	}

	/**
	 * Base64url-encode.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a PKCE value, not obfuscation.
	}

	/**
	 * Encode an ASN.1 length.
	 *
	 * @param int $length Length in bytes.
	 * @return string
	 */
	private static function der_length( $length ) {
		if ( $length < 0x80 ) {
			return chr( $length );
		}
		$bytes = '';
		while ( $length > 0 ) {
			$bytes  = chr( $length & 0xff ) . $bytes;
			$length = $length >> 8;
		}
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Wrap bytes in an ASN.1 TLV with the given tag.
	 *
	 * @param int    $tag   Tag byte.
	 * @param string $value Content bytes.
	 * @return string
	 */
	private static function der_tlv( $tag, $value ) {
		return chr( $tag ) . self::der_length( strlen( $value ) ) . $value;
	}

	/**
	 * Build a PEM SubjectPublicKeyInfo for a P-256 EC JWK.
	 *
	 * Structure: SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 },
	 * BIT STRING ( 0x00 || 0x04 || x || y ) }
	 *
	 * @param array $jwk JWK with kty=EC, crv=P-256, x, y.
	 * @return string|WP_Error
	 */
	public static function jwk_ec_to_pem( array $jwk ) {
		if ( empty( $jwk['kty'] ) || 'EC' !== $jwk['kty'] || empty( $jwk['crv'] ) || 'P-256' !== $jwk['crv'] ) {
			return new WP_Error( 'agentid_jwk_type', __( 'Signing key is not a P-256 EC key.', 'agentkey-sign-in-agentid' ) );
		}
		$x = isset( $jwk['x'] ) ? self::b64url_decode( (string) $jwk['x'] ) : false;
		$y = isset( $jwk['y'] ) ? self::b64url_decode( (string) $jwk['y'] ) : false;
		if ( false === $x || false === $y || 32 !== strlen( $x ) || 32 !== strlen( $y ) ) {
			return new WP_Error( 'agentid_jwk_coords', __( 'Signing key coordinates are malformed.', 'agentkey-sign-in-agentid' ) );
		}
		$oid_ec_public_key = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // 1.2.840.10045.2.1
		$oid_prime256v1    = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // 1.2.840.10045.3.1.7
		$algorithm         = self::der_tlv( 0x30, $oid_ec_public_key . $oid_prime256v1 );
		$bit_string        = self::der_tlv( 0x03, "\x00\x04" . $x . $y );
		$spki              = self::der_tlv( 0x30, $algorithm . $bit_string );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Convert a raw JWS ECDSA signature (r || s) to DER form for OpenSSL.
	 *
	 * @param string $raw Raw signature bytes, 64 for P-256.
	 * @return string|WP_Error
	 */
	public static function ecdsa_raw_to_der( $raw ) {
		if ( 64 !== strlen( $raw ) ) {
			return new WP_Error( 'agentid_sig_length', __( 'Signature has an unexpected length.', 'agentkey-sign-in-agentid' ) );
		}
		$parts = array( substr( $raw, 0, 32 ), substr( $raw, 32, 32 ) );
		$der   = '';
		foreach ( $parts as $part ) {
			$part = ltrim( $part, "\x00" );
			if ( '' === $part ) {
				$part = "\x00";
			}
			if ( ord( $part[0] ) & 0x80 ) {
				$part = "\x00" . $part;
			}
			$der .= self::der_tlv( 0x02, $part );
		}
		return self::der_tlv( 0x30, $der );
	}

	/**
	 * Verify an AgentID ID token and return its claims.
	 *
	 * @param string $jwt       The ID token.
	 * @param array  $jwks      JWKS document.
	 * @param string $client_id Expected audience.
	 * @param string $nonce     Expected nonce, or empty to skip.
	 * @return array|WP_Error Claims on success.
	 */
	public static function verify_id_token( $jwt, array $jwks, $client_id, $nonce = '' ) {
		$segments = explode( '.', (string) $jwt );
		if ( 3 !== count( $segments ) ) {
			return new WP_Error( 'agentid_jwt_shape', __( 'ID token is malformed.', 'agentkey-sign-in-agentid' ) );
		}
		list( $head64, $body64, $sig64 ) = $segments;
		$header_json                     = self::b64url_decode( $head64 );
		$claims_json                     = self::b64url_decode( $body64 );
		$signature                       = self::b64url_decode( $sig64 );
		if ( false === $header_json || false === $claims_json || false === $signature ) {
			return new WP_Error( 'agentid_jwt_decode', __( 'ID token could not be decoded.', 'agentkey-sign-in-agentid' ) );
		}
		$header = json_decode( $header_json, true );
		$claims = json_decode( $claims_json, true );
		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new WP_Error( 'agentid_jwt_json', __( 'ID token is not valid JSON.', 'agentkey-sign-in-agentid' ) );
		}
		if ( empty( $header['alg'] ) || 'ES256' !== $header['alg'] ) {
			return new WP_Error( 'agentid_jwt_alg', __( 'ID token uses an unexpected signing algorithm.', 'agentkey-sign-in-agentid' ) );
		}
		$jwk = null;
		foreach ( $jwks['keys'] as $candidate ) {
			if ( ! empty( $header['kid'] ) && isset( $candidate['kid'] ) && $candidate['kid'] === $header['kid'] ) {
				$jwk = $candidate;
				break;
			}
			if ( empty( $header['kid'] ) && null === $jwk ) {
				$jwk = $candidate;
			}
		}
		if ( null === $jwk ) {
			return new WP_Error( 'agentid_jwt_kid', __( 'ID token signing key was not found in the issuer JWKS.', 'agentkey-sign-in-agentid' ) );
		}
		$pem = self::jwk_ec_to_pem( $jwk );
		if ( is_wp_error( $pem ) ) {
			return $pem;
		}
		$der_sig = self::ecdsa_raw_to_der( $signature );
		if ( is_wp_error( $der_sig ) ) {
			return $der_sig;
		}
		$verified = openssl_verify( $head64 . '.' . $body64, $der_sig, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return new WP_Error( 'agentid_jwt_signature', __( 'ID token signature verification failed.', 'agentkey-sign-in-agentid' ) );
		}
		$now = time();
		if ( empty( $claims['iss'] ) || AGENTID_ISSUER !== $claims['iss'] ) {
			return new WP_Error( 'agentid_claim_iss', __( 'ID token issuer mismatch.', 'agentkey-sign-in-agentid' ) );
		}
		$audiences = array();
		if ( isset( $claims['aud'] ) ) {
			$audiences = is_array( $claims['aud'] ) ? array_values( $claims['aud'] ) : array( $claims['aud'] );
			foreach ( $audiences as $audience_value ) {
				if ( ! is_string( $audience_value ) ) {
					return new WP_Error( 'agentid_claim_aud', __( 'ID token audience is malformed.', 'agentkey-sign-in-agentid' ) );
				}
			}
		}
		if ( ! in_array( $client_id, $audiences, true ) ) {
			return new WP_Error( 'agentid_claim_aud', __( 'ID token audience mismatch.', 'agentkey-sign-in-agentid' ) );
		}
		// OIDC Core 3.1.3.7: with multiple audiences the authorized
		// party (azp) must be present and must be this client; whenever
		// azp is present at all it must match.
		if ( isset( $claims['azp'] ) && ( ! is_string( $claims['azp'] ) || $client_id !== $claims['azp'] ) ) {
			return new WP_Error( 'agentid_claim_azp', __( 'ID token authorized party mismatch.', 'agentkey-sign-in-agentid' ) );
		}
		if ( count( $audiences ) > 1 && ! isset( $claims['azp'] ) ) {
			return new WP_Error( 'agentid_claim_azp', __( 'ID token for multiple audiences has no authorized party.', 'agentkey-sign-in-agentid' ) );
		}
		if ( ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || (int) $claims['exp'] < $now - self::LEEWAY ) {
			return new WP_Error( 'agentid_claim_exp', __( 'ID token has expired.', 'agentkey-sign-in-agentid' ) );
		}
		// iat is required on ID tokens (OIDC Core 2); it must be a
		// number and must not sit beyond the allowed clock skew.
		if ( ! isset( $claims['iat'] ) || ! is_numeric( $claims['iat'] ) ) {
			return new WP_Error( 'agentid_claim_iat', __( 'ID token has no valid issued-at time.', 'agentkey-sign-in-agentid' ) );
		}
		if ( (int) $claims['iat'] > $now + 300 ) {
			return new WP_Error( 'agentid_claim_iat', __( 'ID token was issued in the future.', 'agentkey-sign-in-agentid' ) );
		}
		if ( isset( $claims['nbf'] ) ) {
			if ( ! is_numeric( $claims['nbf'] ) ) {
				return new WP_Error( 'agentid_claim_nbf', __( 'ID token not-before time is malformed.', 'agentkey-sign-in-agentid' ) );
			}
			if ( (int) $claims['nbf'] > $now + self::LEEWAY ) {
				return new WP_Error( 'agentid_claim_nbf', __( 'ID token is not valid yet.', 'agentkey-sign-in-agentid' ) );
			}
		}
		if ( '' !== $nonce && ( empty( $claims['nonce'] ) || ! hash_equals( $nonce, (string) $claims['nonce'] ) ) ) {
			return new WP_Error( 'agentid_claim_nonce', __( 'ID token nonce mismatch.', 'agentkey-sign-in-agentid' ) );
		}
		if ( empty( $claims['sub'] ) || ! is_string( $claims['sub'] ) ) {
			return new WP_Error( 'agentid_claim_sub', __( 'ID token has no subject.', 'agentkey-sign-in-agentid' ) );
		}
		// AgentID tokens always carry actor_type; fail closed if it is
		// absent or is not an agent. A human identity provider's token
		// must never be accepted here.
		if ( empty( $claims['actor_type'] ) || 'agent' !== $claims['actor_type'] ) {
			return new WP_Error( 'agentid_claim_actor', __( 'ID token does not identify an agent.', 'agentkey-sign-in-agentid' ) );
		}
		return $claims;
	}

	/**
	 * Exchange an authorization code for tokens at the token endpoint.
	 *
	 * @param array  $discovery   Discovery document.
	 * @param string $code        Authorization code.
	 * @param string $verifier    PKCE code verifier.
	 * @param string $client_id   Client ID.
	 * @param string $secret      Client secret (may be empty for public clients).
	 * @return array|WP_Error Token response.
	 */
	public static function exchange_code( array $discovery, $code, $verifier, $client_id, $secret ) {
		$body    = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => agentid_sso_redirect_uri(),
			'code_verifier' => $verifier,
		);
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== (string) $secret ) {
			// client_secret_basic. RFC 6749 section 2.3.1: each
			// credential is form-encoded BEFORE the two are joined
			// with a colon — otherwise a colon inside the client ID
			// is ambiguous, and a plus or percent sequence in either
			// value is misread by a conforming server.
			$headers['Authorization'] = 'Basic ' . base64_encode( rawurlencode( (string) $client_id ) . ':' . rawurlencode( (string) $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} else {
			$body['client_id'] = $client_id;
		}
		$response = wp_remote_post(
			$discovery['token_endpoint'],
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || empty( $data['id_token'] ) ) {
			return new WP_Error( 'agentid_token_exchange', __( 'AgentID token exchange failed.', 'agentkey-sign-in-agentid' ) );
		}
		return $data;
	}

	/**
	 * Fetch claims from the userinfo endpoint.
	 *
	 * @param array  $discovery    Discovery document.
	 * @param string $access_token Access token.
	 * @return array|WP_Error
	 */
	public static function userinfo( array $discovery, $access_token ) {
		$response = wp_remote_get(
			$discovery['userinfo_endpoint'],
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'agentid_userinfo_http', __( 'AgentID userinfo did not return HTTP 200.', 'agentkey-sign-in-agentid' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['sub'] ) ) {
			return new WP_Error( 'agentid_userinfo_shape', __( 'AgentID userinfo returned no subject.', 'agentkey-sign-in-agentid' ) );
		}
		return $data;
	}
}
