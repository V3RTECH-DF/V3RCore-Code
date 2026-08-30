<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Contrato do registro de envios — implementado pelo plugin consumidor,
 * NUNCA pela biblioteca (V3RCore não tem tabela própria nem mecanismo de
 * migração; decisão de arquitetura registrada na issue #1 do RIT360 Flow e
 * em docs/ARCHITECTURE.md §3 desta lib).
 *
 * `wasDispatched()` é o que sustenta a reexecução sem duplicidade
 * (NotificationDispatcher::dispatch()): antes de mandar, o dispatcher
 * pergunta se aquele dispatchId já foi registrado como enviado.
 */
interface SendLogInterface {

	/**
	 * Verdadeiro quando já existe um envio bem-sucedido registrado para este
	 * dispatchId. Uma falha registrada NÃO conta como "já enviado" — é
	 * exatamente o caso que precisa poder ser reexecutado.
	 */
	public function wasDispatched( string $dispatchId ): bool;

	public function record( SendRecord $record ): void;
}
