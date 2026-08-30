<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Contrato de preferência de recebimento — implementado pelo plugin
 * consumidor (mesmo motivo de SendLogInterface: sem tabela própria na
 * biblioteca).
 *
 * A escolha do destinatário vale para QUALQUER canal que venha depois — a
 * pergunta é sobre a categoria da comunicação (MessageCategory), não sobre
 * um canal específico. NotificationDispatcher nunca consulta isto para
 * MessageCategory::ESSENTIAL: comunicação essencial não pode ser suprimida
 * pela preferência.
 */
interface RecipientPreferenceInterface {

	/**
	 * @param string $recipient Mesmo identificador usado em Message::getRecipient().
	 * @param string $category  Uma das constantes de MessageCategory.
	 */
	public function mayReceive( string $recipient, string $category ): bool;
}
