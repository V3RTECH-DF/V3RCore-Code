<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification;

use PHPUnit\Framework\TestCase;
use V3R\Core\Notification\Attachment;
use V3R\Core\Notification\DispatchStatus;
use V3R\Core\Notification\EmailChannel;
use V3R\Core\Notification\Message;
use V3R\Core\Notification\MessageCategory;

final class EmailChannelTest extends TestCase {

	public function test_send_delegates_to_transport_and_reports_sent_on_true(): void {
		$captured = null;
		$channel  = new EmailChannel(
			function ( string $to, string $subject, string $body ) use ( &$captured ): bool {
				$captured = array( $to, $subject, $body );

				return true;
			}
		);

		$message = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-1' );
		$result  = $channel->send( $message );

		self::assertSame( DispatchStatus::SENT, $result->getStatus() );
		self::assertSame( array( 'a@rit.org.br', 'Assunto', 'Corpo' ), $captured );
	}

	public function test_send_reports_failure_when_transport_returns_false(): void {
		$channel = new EmailChannel(
			static function (): bool {
				return false;
			}
		);

		$message = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-2' );
		$result  = $channel->send( $message );

		self::assertSame( DispatchStatus::FAILED, $result->getStatus() );
		self::assertNotNull( $result->getFailureReason() );
	}

	public function test_send_rejects_message_addressed_to_another_channel(): void {
		$channel = new EmailChannel(
			static function (): bool {
				return true;
			}
		);

		$this->expectException( \InvalidArgumentException::class );

		$channel->send( new Message( 'sms', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-3' ) );
	}

	/**
	 * Issue #23 (RIT360 Flow): transporte customizado, sem declarar suporte a
	 * anexo, RECUSA explicitamente — nunca manda a mensagem sem o arquivo
	 * prometido.
	 */
	public function test_a_transport_that_does_not_declare_attachment_support_refuses_a_message_with_attachment(): void {
		$sent    = false;
		$channel = new EmailChannel(
			static function () use ( &$sent ): bool {
				$sent = true;

				return true;
			}
		);

		self::assertFalse( $channel->supportsAttachments() );

		$message = new Message(
			'email',
			'a@rit.org.br',
			'Assunto',
			'Corpo',
			MessageCategory::ESSENTIAL,
			'evento-anexo-recusado',
			array( new Attachment( '/tmp/termo.pdf', 'termo.pdf' ) )
		);

		$result = $channel->send( $message );

		self::assertSame( DispatchStatus::FAILED, $result->getStatus() );
		self::assertNotNull( $result->getFailureReason() );
		self::assertFalse( $sent, 'o transporte nunca deveria ter sido chamado — a recusa é ANTES do envio.' );
	}

	/** Controle negativo: o mesmo transporte, sem anexo na mensagem, envia normalmente. */
	public function test_a_transport_that_does_not_declare_attachment_support_still_sends_a_message_without_attachment(): void {
		$channel = new EmailChannel(
			static function (): bool {
				return true;
			}
		);

		$message = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-sem-anexo' );
		$result  = $channel->send( $message );

		self::assertSame( DispatchStatus::SENT, $result->getStatus() );
	}

	/**
	 * O transporte padrão (wp_mail, `$transport === null`) sempre declara
	 * suporte a anexo — é a via que `Infrastructure\Notification\CredentialPdfMailer`
	 * (RIT360 Flow) passa a usar em vez de chamar `wp_mail()` por fora.
	 */
	public function test_the_default_transport_declares_attachment_support(): void {
		$channel = new EmailChannel();

		self::assertTrue( $channel->supportsAttachments() );
	}

	/**
	 * Transporte customizado que DECLARA suporte a anexo recebe os
	 * caminhos dos arquivos como quarto argumento.
	 */
	public function test_a_transport_that_declares_attachment_support_receives_the_attachment_paths(): void {
		$captured = null;
		$channel  = new EmailChannel(
			function ( string $to, string $subject, string $body, array $attachmentPaths ) use ( &$captured ): bool {
				$captured = $attachmentPaths;

				return true;
			},
			true
		);

		$message = new Message(
			'email',
			'a@rit.org.br',
			'Assunto',
			'Corpo',
			MessageCategory::ESSENTIAL,
			'evento-anexo-aceito',
			array( new Attachment( '/tmp/termo.pdf', 'termo.pdf' ) )
		);

		$result = $channel->send( $message );

		self::assertSame( DispatchStatus::SENT, $result->getStatus() );
		self::assertSame( array( '/tmp/termo.pdf' ), $captured );
	}
}
