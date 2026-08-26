<?php
declare(strict_types=1);

namespace V3R\Core\Support;

/**
 * Decide o identificador estável de domínio de um site e se ele é um
 * ambiente de teste/desenvolvimento — que nunca consome cota de ativação
 * de licença.
 */
class SiteIdentity {

	/**
	 * Sufixos de TLD/host que sempre identificam ambiente local ou de teste,
	 * independentemente de prefixo.
	 *
	 * @var string[]
	 */
	private const LOCAL_TLD_SUFFIXES = array( '.local', '.test', '.localhost' );

	/**
	 * Hosts exatos que são sempre ambiente local.
	 *
	 * @var string[]
	 */
	private const LOCAL_EXACT_HOSTS = array( 'localhost', '127.0.0.1' );

	/**
	 * Prefixos de rótulo (primeiro segmento do host) que identificam
	 * ambiente de teste/homologação. Atenção: é o PRIMEIRO segmento,
	 * não uma ocorrência da string em qualquer parte do host — por isso
	 * "meustaging.com.br" não é ambiente de teste, mas "staging.foo.com" é.
	 *
	 * @var string[]
	 */
	private const STAGING_LABEL_PREFIXES = array( 'staging', 'dev' );

	/**
	 * Domínio de homologação próprio da V3RTECH — ele e qualquer subdomínio
	 * seu nunca consomem cota.
	 */
	private const HOMOLOG_DOMAIN = 'teste.bpky.pro.br';

	/**
	 * Valores de WP_ENVIRONMENT_TYPE que caracterizam ambiente não produtivo.
	 *
	 * @var string[]
	 */
	private const NON_PRODUCTION_ENV_TYPES = array( 'local', 'development', 'staging' );

	/**
	 * Normaliza uma URL de site em um identificador estável de domínio:
	 * remove protocolo, prefixo "www.", porta e barra final, e converte
	 * para minúsculas.
	 *
	 * Exemplo: "https://WWW.Exemplo.com.br:443/" e "http://exemplo.com.br"
	 * normalizam para o mesmo valor "exemplo.com.br".
	 */
	public function normalizeDomain( string $siteUrl ): string {
		$host = $this->extractHost( $siteUrl );

		if ( '' === $host ) {
			return '';
		}

		$host = strtolower( $host );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = (string) substr( $host, 4 );
		}

		return rtrim( $host, '/' );
	}

	/**
	 * Extrai só o host de uma URL, aceitando também entradas sem esquema
	 * (ex.: "exemplo.com.br" ou "exemplo.com.br:8080").
	 */
	private function extractHost( string $siteUrl ): string {
		$siteUrl = trim( $siteUrl );

		if ( '' === $siteUrl ) {
			return '';
		}

		if ( false === strpos( $siteUrl, '://' ) ) {
			$siteUrl = 'http://' . ltrim( $siteUrl, '/' );
		}

		$parts = parse_url( $siteUrl );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';

		// parse_url não separa porta de host quando não há esquema explícito
		// em algumas entradas malformadas; removemos qualquer ":porta" residual.
		$host = preg_replace( '/:\d+$/', '', $host );

		return null === $host ? '' : $host;
	}

	/**
	 * Decide se o domínio informado é ambiente de teste/desenvolvimento e,
	 * portanto, não deve consumir cota de ativação de licença.
	 *
	 * Considera, nesta ordem: constante WP_ENVIRONMENT_TYPE (quando definida
	 * e o WordPress a expuser via wp_get_environment_type()), depois os
	 * padrões de host conhecidos.
	 */
	public function isTestEnvironment( string $siteUrl ): bool {
		if ( $this->isNonProductionEnvironmentType() ) {
			return true;
		}

		$host = $this->normalizeDomain( $siteUrl );

		if ( '' === $host ) {
			// Sem host reconhecível, trata como ambiente de teste por segurança
			// (nunca queremos consumir cota de um dado malformado).
			return true;
		}

		return $this->isLocalHost( $host )
			|| $this->isLocalTld( $host )
			|| $this->isStagingLabel( $host )
			|| $this->isHomologDomain( $host );
	}

	private function isLocalHost( string $host ): bool {
		return in_array( $host, self::LOCAL_EXACT_HOSTS, true );
	}

	private function isLocalTld( string $host ): bool {
		foreach ( self::LOCAL_TLD_SUFFIXES as $suffix ) {
			if ( $this->endsWith( $host, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verdadeiro apenas quando o PRIMEIRO segmento do host (o rótulo mais à
	 * esquerda) for exatamente "staging" ou "dev" — ex.: "staging.foo.com.br"
	 * e "dev.foo.com.br" contam; "meustaging.com.br" não conta.
	 */
	private function isStagingLabel( string $host ): bool {
		$firstLabel = strtok( $host, '.' );

		return in_array( $firstLabel, self::STAGING_LABEL_PREFIXES, true );
	}

	private function isHomologDomain( string $host ): bool {
		return self::HOMOLOG_DOMAIN === $host || $this->endsWith( $host, '.' . self::HOMOLOG_DOMAIN );
	}

	private function endsWith( string $haystack, string $needle ): bool {
		$length = strlen( $needle );

		if ( 0 === $length ) {
			return true;
		}

		return substr( $haystack, -$length ) === $needle;
	}

	/**
	 * Lê WP_ENVIRONMENT_TYPE (constante do WordPress, disponível desde 5.5)
	 * quando definida como local/development/staging. Fora do contexto do
	 * WordPress (ex.: em teste), simplesmente não encontra a constante e
	 * retorna falso, sem quebrar.
	 */
	private function isNonProductionEnvironmentType(): bool {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return in_array( wp_get_environment_type(), self::NON_PRODUCTION_ENV_TYPES, true );
		}

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			return in_array( strtolower( (string) WP_ENVIRONMENT_TYPE ), self::NON_PRODUCTION_ENV_TYPES, true );
		}

		return false;
	}
}
