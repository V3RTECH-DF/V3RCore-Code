<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Canal de e-mail, sobre wp_mail(). O transporte é injetável (mesmo padrão
 * de Licensing\HttpApiClient/Transport\HttpTransportInterface) para permitir
 * testar a lógica de despacho sem WordPress carregado, e para o consumidor
 * trocar o transporte (ex.: SMTP dedicado) sem tocar nesta classe.
 *
 * Suporte a anexo (RIT360 Flow, issue #23): o transporte padrão (`$transport
 * === null`, sobre wp_mail()) SEMPRE sabe entregar anexo — é o mesmo
 * `wp_mail()` de sempre, só com o parâmetro de anexos preenchido. Um
 * transporte CUSTOMIZADO (injetado pelo consumidor) só é tratado como capaz
 * de anexar quando `$transportSupportsAttachments = true` é passado
 * explicitamente — o padrão é recusar, nunca ignorar o anexo em silêncio.
 * Ver AttachmentCapableInterface.
 */
final class EmailChannel implements ChannelInterface, AttachmentCapableInterface {

	public const NAME = 'email';

	/** @var callable */
	private $transport;

	/** @var bool */
	private $attachmentsSupported;

	/**
	 * @param (callable(string, string, string, string[]): bool)|null $transport
	 *   Recebe (to, subject, body, attachmentPaths). Nulo usa wp_mail()
	 *   diretamente. Devolve bool — o mesmo contrato de wp_mail(): true
	 *   quando o e-mail foi aceito para envio (não garante entrega na caixa
	 *   do destinatário, só que o transporte o aceitou). Um transporte
	 *   customizado com assinatura antiga (3 parâmetros, sem
	 *   `attachmentPaths`) continua funcionando: PHP ignora o argumento
	 *   extra — só não recebe anexo (ver `$transportSupportsAttachments`).
	 * @param bool                                                    $transportSupportsAttachments Só é relevante quando
	 *                                                      `$transport` não é nulo — ver docblock da classe.
	 */
	public function __construct( ?callable $transport = null, bool $transportSupportsAttachments = false ) {
		if ( null === $transport ) {
			$this->transport            = static function ( string $to, string $subject, string $body, array $attachmentPaths = array() ): bool {
				if ( ! function_exists( 'wp_mail' ) ) {
					return false;
				}

				return (bool) wp_mail( $to, $subject, $body, array(), $attachmentPaths );
			};
			$this->attachmentsSupported = true;
		} else {
			$this->transport            = $transport;
			$this->attachmentsSupported = $transportSupportsAttachments;
		}
	}

	public function name(): string {
		return self::NAME;
	}

	public function supportsAttachments(): bool {
		return $this->attachmentsSupported;
	}

	public function send( Message $message ): DispatchResult {
		if ( self::NAME !== $message->getChannel() ) {
			throw new \InvalidArgumentException(
				"EmailChannel recebeu mensagem destinada ao canal '{$message->getChannel()}', não '" . self::NAME . "'."
			);
		}

		if ( $message->hasAttachments() && ! $this->attachmentsSupported ) {
			return DispatchResult::failed( 'Este canal não sabe entregar anexo, e a mensagem exigia um — envio recusado (nunca enviado sem o arquivo prometido).' );
		}

		$attachmentPaths = array_map(
			static function ( Attachment $attachment ): string {
				return $attachment->getPath();
			},
			$message->getAttachments()
		);

		$accepted = ( $this->transport )( $message->getRecipient(), $message->getSubject(), $message->getBody(), $attachmentPaths );

		if ( ! $accepted ) {
			return DispatchResult::failed( 'O transporte de e-mail recusou ou falhou o envio (wp_mail retornou falso).' );
		}

		return DispatchResult::sent();
	}
}
