<?php
declare(strict_types=1);

namespace V3R\Core\Access;

use V3R\Core\Licensing\Storage\KeyValueStoreInterface;

/**
 * Limitador de tentativas de duas chaves — por identificador (o e-mail
 * pedido) e por origem (o endereço de rede) —, com janela e teto
 * configuráveis, sobre qualquer KeyValueStoreInterface.
 *
 * ⚠️ A DECISÃO QUE ESTA CLASSE EXISTE PARA PROTEGER: o registro da
 * tentativa é INCONDICIONAL. `registerAttempt()` incrementa os dois
 * contadores SEMPRE, e só então devolve se a tentativa era permitida — a
 * decisão é lida antes do incremento, nunca no lugar dele. Por isso a
 * classe não expõe um `check()` separado: com dois métodos públicos, o
 * ponto de chamada pode incrementar dentro do `if` e transformar o próprio
 * bloqueio em oráculo de existência — quem tentasse enumerar veria a
 * diferença entre um e-mail que consome cota e outro que não consome, por
 * comportamento ou por temporização. Aqui esse erro não tem como ser
 * escrito.
 *
 * Corolário para quem chama: chame `registerAttempt()` UMA vez por
 * tentativa, antes de saber se o identificador existe, e use o retorno
 * apenas para decidir se o trabalho caro (procurar, emitir, enviar) sai —
 * nunca para variar a resposta ao usuário, que precisa ser a mesma nos
 * dois casos.
 *
 * Janela deslizante: cada tentativa renova a expiração dos contadores, de
 * modo que rajada insistente permanece bloqueada em vez de reabrir a cada
 * expiração parcial.
 *
 * Nenhum valor identificável vai para a chave do armazenamento: o
 * identificador é normalizado e derivado em sha256, porque nome de
 * transient/option é legível na base.
 */
final class AttemptLimiter {

	public const DEFAULT_WINDOW_SECONDS = 900;

	public const DEFAULT_MAX_ATTEMPTS = 3;

	/** @var KeyValueStoreInterface */
	private $store;

	/** @var string */
	private $keyPrefix;

	/** @var int */
	private $windowSeconds;

	/** @var int */
	private $maxAttempts;

	/**
	 * $keyPrefix é o namespace das chaves no armazenamento, para dois
	 * consumidores no mesmo WordPress não dividirem cota.
	 *
	 * @throws \InvalidArgumentException Prefixo vazio, janela não positiva ou teto abaixo de 1.
	 */
	public function __construct(
		KeyValueStoreInterface $store,
		string $keyPrefix,
		int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
		int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
	) {
		if ( '' === $keyPrefix ) {
			throw new \InvalidArgumentException( 'keyPrefix não pode ser vazio — sem ele dois consumidores no mesmo site dividiriam a mesma cota.' );
		}

		if ( $windowSeconds <= 0 ) {
			throw new \InvalidArgumentException( 'windowSeconds precisa ser positivo — janela zero é contador que nunca conta.' );
		}

		if ( $maxAttempts < 1 ) {
			throw new \InvalidArgumentException( 'maxAttempts precisa ser ao menos 1 — teto zero bloquearia a primeira tentativa de todo mundo.' );
		}

		$this->store         = $store;
		$this->keyPrefix     = $keyPrefix;
		$this->windowSeconds = $windowSeconds;
		$this->maxAttempts   = $maxAttempts;
	}

	/**
	 * Registra uma tentativa e devolve se ela estava dentro da cota.
	 *
	 * O incremento das duas chaves acontece em qualquer caso — inclusive
	 * quando o retorno é `false`. Ver o aviso no topo da classe: não é
	 * detalhe de implementação, é o que impede o bloqueio de virar
	 * oráculo.
	 *
	 * @param string $identifier Aquilo que a pessoa digitou (e-mail). Normalizado.
	 * @param string $origin     Origem da requisição (endereço IP). Vazio agrupa
	 *                           todo mundo num balde só — falha fechando, nunca abrindo.
	 */
	public function registerAttempt( string $identifier, string $origin ): bool {
		$identifierKey = $this->identifierKey( $identifier );
		$originKey     = $this->originKey( $origin );

		// Sem curto-circuito de propósito: os dois contadores são lidos e
		// reescritos em toda tentativa, permitida ou não, para o rastro no
		// armazenamento e o tempo de resposta não dependerem do estado.
		$identifierCount = $this->count( $identifierKey );
		$originCount     = $this->count( $originKey );

		$allowed = $identifierCount < $this->maxAttempts && $originCount < $this->maxAttempts;

		$this->store->set( $identifierKey, $identifierCount + 1, $this->windowSeconds );
		$this->store->set( $originKey, $originCount + 1, $this->windowSeconds );

		return $allowed;
	}

	/**
	 * Libera manualmente o identificador (tela de suporte: alguém legítimo
	 * esbarrou no teto e não pode esperar a janela fechar).
	 */
	public function resetIdentifier( string $identifier ): void {
		$this->store->delete( $this->identifierKey( $identifier ) );
	}

	public function resetOrigin( string $origin ): void {
		$this->store->delete( $this->originKey( $origin ) );
	}

	private function count( string $key ): int {
		$value = $this->store->get( $key );

		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Normaliza antes de derivar: sem isso, variar a caixa do e-mail daria
	 * um contador novo a cada tentativa e o teto não seguraria nada.
	 */
	private function identifierKey( string $identifier ): string {
		return $this->keyPrefix . '_id_' . hash( 'sha256', strtolower( trim( $identifier ) ) );
	}

	private function originKey( string $origin ): string {
		return $this->keyPrefix . '_origin_' . hash( 'sha256', trim( $origin ) );
	}
}
