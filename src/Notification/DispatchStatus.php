<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Resultado possível de um pedido de envio, distinguindo os dois casos que
 * NÃO são falha de canal (duplicidade e preferência) do envio de fato — ver
 * DispatchResult. Nenhuma dessas constantes deve virar "sucesso silencioso":
 * o consumidor decide o que exibir para cada uma.
 */
final class DispatchStatus {

	/** Enviado pelo canal com sucesso. */
	public const SENT = 'sent';

	/** O canal recusou ou falhou o envio — visível e reexecutável. */
	public const FAILED = 'failed';

	/**
	 * O destinatário já havia recebido este mesmo pedido (mesma
	 * identificação de reexecução) — não reenviado, de propósito.
	 */
	public const SKIPPED_DUPLICATE = 'skipped_duplicate';

	/**
	 * O destinatário optou por não receber comunicação dispensável — não
	 * enviado, de propósito. Nunca acontece para MessageCategory::ESSENTIAL.
	 */
	public const SKIPPED_PREFERENCE = 'skipped_preference';

	/** O canal pedido não está registrado no ChannelRegistry. */
	public const UNKNOWN_CHANNEL = 'unknown_channel';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::SENT, self::FAILED, self::SKIPPED_DUPLICATE, self::SKIPPED_PREFERENCE, self::UNKNOWN_CHANNEL );
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
