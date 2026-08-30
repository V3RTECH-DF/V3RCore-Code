<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification;

use PHPUnit\Framework\TestCase;
use V3R\Core\Notification\TemplateRenderer;

final class TemplateRendererTest extends TestCase {

	private TemplateRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new TemplateRenderer();
	}

	public function test_replaces_known_markers(): void {
		$out = $this->renderer->render(
			'Olá {{nome}}, sua credencial vence em {{data}}.',
			array(
				'nome' => 'Maria',
				'data' => '10/09/2026',
			)
		);

		self::assertSame( 'Olá Maria, sua credencial vence em 10/09/2026.', $out );
	}

	public function test_marker_without_value_stays_literal(): void {
		$out = $this->renderer->render( 'Olá {{nome}}, bem-vindo.', array() );

		self::assertSame( 'Olá {{nome}}, bem-vindo.', $out );
	}

	public function test_changing_only_the_template_text_changes_output_without_code(): void {
		// Prova o requisito de negócio: mudar a frase é só trocar o template,
		// os mesmos placeholders continuam servindo.
		$placeholders = array( 'nome' => 'João' );

		$out1 = $this->renderer->render( 'Olá {{nome}}.', $placeholders );
		$out2 = $this->renderer->render( 'Prezado(a) {{nome}}, tudo bem?', $placeholders );

		self::assertSame( 'Olá João.', $out1 );
		self::assertSame( 'Prezado(a) João, tudo bem?', $out2 );
	}

	public function test_missing_placeholders_lists_unresolved_markers(): void {
		$missing = $this->renderer->missingPlaceholders(
			'Olá {{nome}}, seu código é {{codigo}}.',
			array( 'nome' => 'Ana' )
		);

		self::assertSame( array( 'codigo' ), $missing );
	}

	public function test_missing_placeholders_is_empty_when_all_covered(): void {
		$missing = $this->renderer->missingPlaceholders(
			'Olá {{nome}}.',
			array( 'nome' => 'Ana' )
		);

		self::assertSame( array(), $missing );
	}
}
