<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification;

use PHPUnit\Framework\TestCase;
use V3R\Core\Notification\ChannelRegistry;
use V3R\Core\Notification\DispatchStatus;
use V3R\Core\Notification\Message;
use V3R\Core\Notification\MessageCategory;
use V3R\Core\Notification\NotificationDispatcher;
use V3R\Core\Tests\Notification\Support\ConfigurableRecipientPreference;
use V3R\Core\Tests\Notification\Support\InMemorySendLog;
use V3R\Core\Tests\Notification\Support\RecordingChannel;

final class NotificationDispatcherTest extends TestCase {

	private function makeDispatcher(
		RecordingChannel $channel,
		InMemorySendLog $log,
		ConfigurableRecipientPreference $preferences
	): NotificationDispatcher {
		return new NotificationDispatcher( new ChannelRegistry( array( $channel ) ), $log, $preferences );
	}

	public function test_successful_send_is_recorded_as_delivered(): void {
		$channel    = new RecordingChannel( true );
		$log        = new InMemorySendLog();
		$dispatcher = $this->makeDispatcher( $channel, $log, new ConfigurableRecipientPreference() );
		$message    = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-1' );

		$result = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::SENT, $result->getStatus() );
		$record = $log->get( 'evento-1' );
		self::assertNotNull( $record );
		self::assertTrue( $record->wasDelivered() );
	}

	public function test_failed_send_is_visible_and_recorded_as_not_delivered(): void {
		$channel    = new RecordingChannel( false );
		$log        = new InMemorySendLog();
		$dispatcher = $this->makeDispatcher( $channel, $log, new ConfigurableRecipientPreference() );
		$message    = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-2' );

		$result = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::FAILED, $result->getStatus() );
		self::assertNotNull( $result->getFailureReason() );
		$record = $log->get( 'evento-2' );
		self::assertNotNull( $record );
		self::assertFalse( $record->wasDelivered() );
	}

	public function test_retrying_a_failed_dispatch_calls_the_channel_again(): void {
		// Falha NÃO é "já enviado" — reexecutar precisa tentar de novo.
		$channel    = new RecordingChannel( false );
		$log        = new InMemorySendLog();
		$dispatcher = $this->makeDispatcher( $channel, $log, new ConfigurableRecipientPreference() );
		$message    = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-3' );

		$dispatcher->dispatch( $message );
		$dispatcher->dispatch( $message );

		self::assertSame( 2, $channel->callCount() );
	}

	public function test_retrying_a_successful_dispatch_does_not_send_twice(): void {
		$channel    = new RecordingChannel( true );
		$log        = new InMemorySendLog();
		$dispatcher = $this->makeDispatcher( $channel, $log, new ConfigurableRecipientPreference() );
		$message    = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-4' );

		$first  = $dispatcher->dispatch( $message );
		$second = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::SENT, $first->getStatus() );
		self::assertSame( DispatchStatus::SKIPPED_DUPLICATE, $second->getStatus() );
		self::assertSame( 1, $channel->callCount(), 'canal não pode ser chamado de novo para quem já recebeu' );
	}

	public function test_dispensable_message_is_skipped_when_recipient_opted_out(): void {
		$channel     = new RecordingChannel( true );
		$log         = new InMemorySendLog();
		$preferences = new ConfigurableRecipientPreference();
		$preferences->denyFor( 'a@rit.org.br', MessageCategory::DISPENSABLE );
		$dispatcher = $this->makeDispatcher( $channel, $log, $preferences );
		$message    = new Message( 'email', 'a@rit.org.br', 'Cobrança', 'Corpo', MessageCategory::DISPENSABLE, 'evento-5' );

		$result = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::SKIPPED_PREFERENCE, $result->getStatus() );
		self::assertSame( 0, $channel->callCount() );
	}

	public function test_dispensable_message_is_sent_when_recipient_did_not_opt_out(): void {
		// Controle negativo do teste anterior: sem opt-out, o mesmo tipo de
		// mensagem dispensável é enviado normalmente.
		$channel    = new RecordingChannel( true );
		$log        = new InMemorySendLog();
		$dispatcher = $this->makeDispatcher( $channel, $log, new ConfigurableRecipientPreference() );
		$message    = new Message( 'email', 'a@rit.org.br', 'Cobrança', 'Corpo', MessageCategory::DISPENSABLE, 'evento-6' );

		$result = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::SENT, $result->getStatus() );
		self::assertSame( 1, $channel->callCount() );
	}

	public function test_essential_message_is_sent_even_when_recipient_opted_out_of_that_category(): void {
		// Preferência nunca suprime comunicação essencial — mesmo com opt-out
		// registrado para a categoria, ESSENTIAL ignora a preferência.
		$channel     = new RecordingChannel( true );
		$log         = new InMemorySendLog();
		$preferences = new ConfigurableRecipientPreference();
		$preferences->denyFor( 'a@rit.org.br', MessageCategory::ESSENTIAL );
		$dispatcher = $this->makeDispatcher( $channel, $log, $preferences );
		$message    = new Message( 'email', 'a@rit.org.br', 'Credencial revogada', 'Corpo', MessageCategory::ESSENTIAL, 'evento-7' );

		$result = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::SENT, $result->getStatus() );
		self::assertSame( 1, $channel->callCount() );
	}

	public function test_unknown_channel_fails_visibly_instead_of_silently_dropping(): void {
		$log        = new InMemorySendLog();
		$dispatcher = new NotificationDispatcher( new ChannelRegistry(), $log, new ConfigurableRecipientPreference() );
		$message    = new Message( 'sms', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, 'evento-8' );

		$result = $dispatcher->dispatch( $message );

		self::assertSame( DispatchStatus::UNKNOWN_CHANNEL, $result->getStatus() );
		self::assertNotNull( $result->getFailureReason() );
	}
}
