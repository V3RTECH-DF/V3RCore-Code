<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification;

use PHPUnit\Framework\TestCase;
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
}
