<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification;

use PHPUnit\Framework\TestCase;
use V3R\Core\Notification\Message;
use V3R\Core\Notification\MessageCategory;

final class MessageTest extends TestCase {

	public function test_rejects_invalid_category(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', 'urgente', 'evento-1' );
	}

	public function test_rejects_empty_dispatch_id(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', MessageCategory::ESSENTIAL, '' );
	}

	public function test_accepts_valid_categories(): void {
		foreach ( MessageCategory::all() as $category ) {
			$message = new Message( 'email', 'a@rit.org.br', 'Assunto', 'Corpo', $category, 'evento-' . $category );

			self::assertSame( $category, $message->getCategory() );
		}
	}
}
