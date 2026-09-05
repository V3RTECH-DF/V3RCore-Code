<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Entrega segura de material sensível em arquivo, para quando o assinador
 * exigir isso (issue #27, corrigindo V3RProp#62 — "a chave privada é
 * escrita em pasta pública durante a assinatura").
 *
 * Quatro garantias:
 *
 * 1. Local NÃO servido pela web: por padrão sys_get_temp_dir(), nunca uma
 *    pasta dentro do webroot (uploads, etc.) — quem instancia pode passar
 *    outro diretório, mas a responsabilidade de garantir que ele também
 *    não é servido é de quem escolhe.
 * 2. Permissão restrita: chmod 0600 logo após escrever.
 * 3. Nome imprevisível: sufixo de random_bytes(), não sequencial nem
 *    derivado de nada público (nunca o nome do documento, por exemplo).
 * 4. Remoção garantida no encerramento do processo — não só no caminho
 *    feliz: dispose() explícito, e register_shutdown_function() para
 *    cobrir exceção não tratada/erro fatal/exit() no meio do
 *    processamento (write() registra o callback antes de devolver a
 *    instância). __destruct() é uma segunda rede, para o caso raro de uma
 *    instância nunca passar pelo caminho de write() — na prática, como o
 *    próprio callback de shutdown mantém uma referência à instância, o
 *    objeto normalmente só é destruído no fim do processo mesmo, junto
 *    com a shutdown function; não conte com __destruct() disparando
 *    antes disso. NENHUM dos dois cobre o processo morto por sinal não
 *    capturável (kill -9, OOM killer) — para esse caso existe
 *    sweepOrphans(), que o hospedeiro roda periodicamente (ex.: cron) para
 *    remover sobras mais velhas que um limiar.
 */
final class EphemeralSecretFile {

	private const PREFIX = 'v3r-core-signing-';

	/** @var string */
	private $path;

	/** @var bool */
	private $disposed = false;

	private function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Escreve $contents num arquivo novo, imprevisível, com permissão
	 * restrita, e agenda a remoção garantida.
	 *
	 * @param string      $contents  O material sensível (bytes do certificado, chave, etc.).
	 * @param string|null $directory Diretório de destino. Default: sys_get_temp_dir().
	 *
	 * @throws \RuntimeException Falha ao escrever o arquivo.
	 */
	public static function write( string $contents, ?string $directory = null ): self {
		$directory = rtrim( $directory ?? sys_get_temp_dir(), '/\\' );
		$path      = $directory . DIRECTORY_SEPARATOR . self::PREFIX . bin2hex( random_bytes( 16 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- classe roda também fora do WordPress (ver docblock); sem WP_Filesystem garantido disponível.
		$written = file_put_contents( $path, $contents, LOCK_EX );

		if ( false === $written ) {
			throw new \RuntimeException( "EphemeralSecretFile: falha ao escrever '{$path}'." );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- idem acima.
		chmod( $path, 0600 );

		$instance = new self( $path );

		// Cobre exceção não tratada, erro fatal do PHP e exit() no meio do
		// processamento — tudo que ainda passa pelo ciclo normal de
		// shutdown do PHP. NÃO cobre kill -9/OOM killer (ver sweepOrphans()).
		register_shutdown_function(
			static function () use ( $instance ): void {
				$instance->dispose();
			}
		);

		return $instance;
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Remove o arquivo agora. Idempotente — chamar mais de uma vez (ex.:
	 * explicitamente e depois via shutdown function) não é erro.
	 */
	public function dispose(): void {
		if ( $this->disposed ) {
			return;
		}

		if ( is_file( $this->path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- classe roda fora do WordPress; arquivo pode já ter sido removido por outra via (dispose() chamado mais de uma vez em ordens diferentes) — a garantia é "não sobrar arquivo", não "unlink nunca falha".
			@unlink( $this->path );
		}

		$this->disposed = true;
	}

	/**
	 * Segunda rede de segurança — ver o item 4 do docblock da classe: na
	 * via normal (write()), a shutdown function registrada já mantém uma
	 * referência à instância, então este destructor tende a rodar junto
	 * dela, no fim do processo. Cobre o caso de uma instância ser
	 * descartada sem nunca ter passado pelo caminho usual.
	 */
	public function __destruct() {
		$this->dispose();
	}

	/**
	 * Varre $directory à procura de sobras — arquivos com o prefixo desta
	 * classe, mais velhos que $maxAgeSeconds — e remove. Cobre o único
	 * caso que dispose()/destruct()/shutdown function não cobrem: processo
	 * morto por sinal não capturável, sem rodar nenhum código de
	 * encerramento. O hospedeiro chama isto periodicamente (ex.: um cron
	 * do WordPress).
	 *
	 * @return int Quantidade de arquivos removidos.
	 */
	public static function sweepOrphans( ?string $directory = null, int $maxAgeSeconds = 3600 ): int {
		$directory  = rtrim( $directory ?? sys_get_temp_dir(), '/\\' );
		$pattern    = $directory . DIRECTORY_SEPARATOR . self::PREFIX . '*';
		$candidates = glob( $pattern );

		if ( false === $candidates ) {
			return 0;
		}

		$removed = 0;
		$now     = time();

		foreach ( $candidates as $file ) {
			$mtime = is_file( $file ) ? filemtime( $file ) : false;

			if ( false !== $mtime && ( $now - $mtime ) >= $maxAgeSeconds ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- classe roda fora do WordPress; varredura best-effort, arquivo pode já ter sumido entre o glob() e aqui.
				if ( @unlink( $file ) ) {
					++$removed;
				}
			}
		}

		return $removed;
	}
}
