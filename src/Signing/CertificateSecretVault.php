<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use V3R\Core\Licensing\Storage\KeyValueStoreInterface;

/**
 * Guarda a senha do certificado cifrada, com a chave de cifragem vinda de
 * FORA do banco (issue #27, corrigindo V3RProp#64 — "a senha do
 * certificado fica em texto claro no banco").
 *
 * ⚠️ Esta chave NÃO é como o par de constantes de licenciamento (ADR-010):
 * lá a chave pública é a mesma em todo plugin da casa, de propósito, e não
 * é segredo. Aqui é o oposto — esta chave PRECISA ser secreta e PRECISA
 * ser própria de cada site. Se ela viesse embutida no build do plugin
 * (como o par de licenciamento), qualquer pessoa que baixasse o plugin
 * teria a chave que decifra a senha de certificado de qualquer cliente.
 * Por isso não há default de produção, nem placeholder, nem função de
 * fallback: sem uma chave que o próprio site gerou e configurou, o cofre
 * se recusa a operar (CertificateVaultException::CHAVE_DE_CIFRAGEM_INDISPONIVEL)
 * em vez de gravar em texto claro.
 *
 * Cifragem autenticada (sodium_crypto_secretbox, XSalsa20-Poly1305): além
 * de sigilo, decifrar com a chave errada ou sobre dado alterado falha de
 * forma detectável (CertificateVaultException::FALHA_AO_DECIFRAR), nunca
 * devolve lixo em silêncio.
 *
 * Mesma decisão de disponibilidade de sodium do SignatureVerifier: nunca
 * assume, sempre confere function_exists() antes de usar.
 *
 * Persiste pela mesma abstração do resto da biblioteca
 * (Licensing\Storage\KeyValueStoreInterface).
 *
 * Perder esta chave é uma degradação RECUPERÁVEL, não uma perda de dados
 * (issue #27): os documentos já emitidos continuam abrindo e o código de
 * autenticidade continua conferindo (AuthenticityRegistry não depende
 * desta chave); só o certificado precisa ser cadastrado de novo.
 */
final class CertificateSecretVault {

	private const KEY_BYTES = 32;

	private const NONCE_BYTES = 24;

	/** @var KeyValueStoreInterface */
	private $store;

	/** @var string */
	private $keyPrefix;

	/**
	 * Chave crua (32 bytes), ou null quando não configurada/malformada —
	 * ver decodeCipherKey().
	 *
	 * @var string|null
	 */
	private $cipherKey;

	/**
	 * @param KeyValueStoreInterface $store            Onde a senha cifrada é persistida.
	 * @param string                 $keyPrefix        Separa dois consumidores no mesmo WordPress — mesmo
	 *                                                 padrão de Access\AttemptLimiter e AuthenticityRegistry.
	 * @param string|null            $cipherKeyBase64  Chave de cifragem em base64 (32 bytes crus), resolvida
	 *                                                 por quem chama — tipicamente lida de uma constante do
	 *                                                 wp-config.php do site (ver fromConstant()). Null ou
	 *                                                 formato inválido tornam o cofre indisponível
	 *                                                 (isAvailable() === false), nunca um fallback silencioso.
	 *
	 * @throws \InvalidArgumentException Prefixo vazio.
	 */
	public function __construct( KeyValueStoreInterface $store, string $keyPrefix, ?string $cipherKeyBase64 ) {
		if ( '' === $keyPrefix ) {
			throw new \InvalidArgumentException( 'CertificateSecretVault: keyPrefix não pode ser vazio.' );
		}

		$this->store     = $store;
		$this->keyPrefix = $keyPrefix;
		$this->cipherKey = self::decodeCipherKey( $cipherKeyBase64 );
	}

	/**
	 * Adaptador fino de conveniência (mesmo desenho da ADR-010: decisão
	 * pura separada da leitura da constante global). Lê uma constante do
	 * wp-config.php do site — por convenção `V3R_SIGNING_ENCRYPTION_KEY`,
	 * base64 de 32 bytes gerados pelo próprio site (ex.:
	 * `base64_encode(random_bytes(32))`) — e nunca aplica default nenhum:
	 * não existe valor de produção seguro para uma chave que precisa ser
	 * secreta e própria do site.
	 */
	public static function fromConstant(
		KeyValueStoreInterface $store,
		string $keyPrefix,
		string $constantName = 'V3R_SIGNING_ENCRYPTION_KEY'
	): self {
		$configured = defined( $constantName ) ? (string) constant( $constantName ) : null;

		return new self( $store, $keyPrefix, $configured );
	}

	/**
	 * Função pura: decide se um valor base64 configurado é uma chave
	 * utilizável, sem tocar em constante nenhuma — é o que torna esta
	 * decisão testável (inclusive o estado "chave ainda não configurada")
	 * sem precisar definir/indefinir constante global em teste.
	 */
	public static function decodeCipherKey( ?string $base64 ): ?string {
		if ( null === $base64 || '' === $base64 ) {
			return null;
		}

		$raw = base64_decode( $base64, true );

		if ( false === $raw || self::KEY_BYTES !== strlen( $raw ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Verdadeiro quando há chave de cifragem utilizável E a extensão sodium
	 * (ou o polyfill) está disponível. Todo método que grava ou lê a senha
	 * confere isto antes de agir.
	 */
	public function isAvailable(): bool {
		return null !== $this->cipherKey && function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Cifra e grava a senha do certificado.
	 *
	 * @throws CertificateVaultException CHAVE_DE_CIFRAGEM_INDISPONIVEL se isAvailable() for falso —
	 *                                    nunca grava em texto claro como alternativa.
	 */
	public function storePassword( string $plaintextPassword ): void {
		$this->assertAvailable();
		/** @var string $cipherKey Não-nulo — assertAvailable() acima garante. */
		$cipherKey = $this->cipherKey;

		$nonce      = random_bytes( self::NONCE_BYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintextPassword, $nonce, $cipherKey );

		$this->store->set(
			$this->storageKey(),
			array(
				'nonce'      => base64_encode( $nonce ),
				'ciphertext' => base64_encode( $ciphertext ),
			)
		);
	}

	/**
	 * Decifra e devolve a senha do certificado — usado pelo automático
	 * (issue #27: "o automático continua funcionando sem interação").
	 *
	 * @throws CertificateVaultException CHAVE_DE_CIFRAGEM_INDISPONIVEL, SENHA_NAO_ENCONTRADA ou
	 *                                    FALHA_AO_DECIFRAR (chave errada ou dado alterado).
	 */
	public function retrievePassword(): string {
		$this->assertAvailable();
		/** @var string $cipherKey Não-nulo — assertAvailable() acima garante. */
		$cipherKey = $this->cipherKey;

		$data = $this->store->get( $this->storageKey() );

		if ( ! is_array( $data ) || ! isset( $data['nonce'], $data['ciphertext'] )
			|| ! is_string( $data['nonce'] ) || ! is_string( $data['ciphertext'] ) ) {
			throw new CertificateVaultException(
				CertificateVaultException::SENHA_NAO_ENCONTRADA,
				'CertificateSecretVault: nenhuma senha de certificado guardada sob este prefixo.'
			);
		}

		$nonce      = base64_decode( $data['nonce'], true );
		$ciphertext = base64_decode( $data['ciphertext'], true );

		if ( false === $nonce || false === $ciphertext ) {
			throw new CertificateVaultException(
				CertificateVaultException::FALHA_AO_DECIFRAR,
				'CertificateSecretVault: nonce ou ciphertext guardados não são base64 válido.'
			);
		}

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $cipherKey );

		if ( false === $plaintext ) {
			throw new CertificateVaultException(
				CertificateVaultException::FALHA_AO_DECIFRAR,
				'CertificateSecretVault: falha ao decifrar — chave de cifragem errada, ou dado alterado.'
			);
		}

		return $plaintext;
	}

	/**
	 * Remove a senha guardada (recadastro do certificado, ou reação a
	 * suspeita de chave comprometida).
	 */
	public function clear(): void {
		$this->store->delete( $this->storageKey() );
	}

	/**
	 * @throws CertificateVaultException CHAVE_DE_CIFRAGEM_INDISPONIVEL.
	 */
	private function assertAvailable(): void {
		if ( ! $this->isAvailable() ) {
			throw new CertificateVaultException(
				CertificateVaultException::CHAVE_DE_CIFRAGEM_INDISPONIVEL,
				'CertificateSecretVault: sem chave de cifragem utilizável (não configurada, formato ' .
				'inválido, ou sodium indisponível) — recusando operar em vez de gravar em texto claro.'
			);
		}
	}

	private function storageKey(): string {
		return $this->keyPrefix . '_signing_cert_password';
	}
}
