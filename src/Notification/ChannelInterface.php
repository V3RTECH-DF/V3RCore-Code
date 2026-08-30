<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Contrato de canal de envio. E-mail (EmailChannel) é a primeira
 * implementação; acrescentar um canal novo (SMS, WhatsApp, push) é
 * implementar esta interface e registrá-lo no ChannelRegistry — nunca muda
 * quem chama NotificationDispatcher::dispatch().
 *
 * Implementações NUNCA lançam exceção para falha esperada de envio (destino
 * inválido, serviço indisponível): devolvem DispatchResult::failed() — a
 * exceção é reservada para erro de programação (ex.: mensagem destinada a
 * outro canal).
 */
interface ChannelInterface {

	/**
	 * Nome do canal, usado como chave no ChannelRegistry e conferido contra
	 * Message::getChannel().
	 */
	public function name(): string;

	public function send( Message $message ): DispatchResult;
}
