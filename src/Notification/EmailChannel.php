<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Canal de e-mail, sobre wp_mail(). O transporte é injetável (mesmo padrão
 * de Licensing\HttpApiClient/Transport\HttpTransportInterface) para permitir
 * testar a lógica de despacho sem WordPress carregado, e para o consumidor
 * trocar o transporte (ex.: SMTP dedicado) sem tocar nesta classe.
 */
final class EmailChannel implements ChannelInterface {

	public const NAME = 'email';

	/** @var callable */
	private $transport;

	/**
	 * @param (callable(string, string, string): bool)|null $transport
	 *   Recebe (to, subject, body). Nulo usa wp_mail() diretamente. Devolve
	 *   bool — o mesmo contrato de wp_mail(): true quando o e-mail foi
	 *   aceito para envio (não garante entrega na caixa do destinatário, só
	 *   que o transporte o aceitou).
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport ?? static function ( string $to, string $subject, string $body ): bool {
			if ( ! function_exists( 'wp_mail' ) ) {
				return false;
			}

			return (bool) wp_mail( $to, $subject, $body );
		};
	}

	public function name(): string {
		return self::NAME;
	}

	public function send( Message $message ): DispatchResult {
		if ( self::NAME !== $message->getChannel() ) {
			throw new \InvalidArgumentException(
				"EmailChannel recebeu mensagem destinada ao canal '{$message->getChannel()}', não '" . self::NAME . "'."
			);
		}

		$accepted = ( $this->transport )( $message->getRecipient(), $message->getSubject(), $message->getBody() );

		if ( ! $accepted ) {
			return DispatchResult::failed( 'O transporte de e-mail recusou ou falhou o envio (wp_mail retornou falso).' );
		}

		return DispatchResult::sent();
	}
}
