<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Uma tela declarada por um plugin — a unidade que alimenta, ao mesmo
 * tempo, a navegação (o que aparece na árvore, ver TreeBuilder) e o gate
 * de acesso direto (o que a URL permite, ver NavCapabilityGate). É essa
 * dupla origem que torna impossível declarar uma coisa e esquecer a outra
 * (docs/navegacao-do-painel.md §1).
 *
 * Valor imutável: uma vez construído, nada muda. `group` é opcional —
 * ausente, a tela entra na árvore como item de primeiro nível (§5).
 *
 * `order` é opcional e ordena entre irmãos, em qualquer nível: entre telas
 * soltas e grupos no primeiro nível, e entre as telas de um mesmo grupo.
 * Sem `order` declarada, vale a ordem de declaração. ⚠️ Entre irmãos,
 * declare `order` para todos ou para nenhum — misturar quem declara com
 * quem não compara escalas diferentes (valor declarado contra posição de
 * inserção) e o resultado surpreende (docs/navegacao-do-painel.md §5).
 *
 * `hidden` é a terceira opção que faltava ao contrato (RIT360 Flow,
 * 06/09/2026): tela declarada, guardada e endereçável como qualquer outra,
 * mas fora da árvore que `TreeBuilder` devolve — para quem roteia no
 * cliente e precisa declarar rota transitória sem poluir o menu, sem por
 * isso perder a guarda (docs/navegacao-do-painel.md §4). Nome em inglês,
 * coerente com o resto da classe (`slug`/`label`/`group`/`permission`/
 * `order` já são inglês).
 */
final class Screen {

	/** @var string */
	private $slug;

	/** @var string */
	private $label;

	/** @var string|null */
	private $group;

	/** @var string */
	private $permission;

	/** @var int|null */
	private $order;

	/** @var bool */
	private $hidden;

	/**
	 * @throws \InvalidArgumentException `slug`, `label` ou `permission` vazios.
	 */
	public function __construct( string $slug, string $label, ?string $group, string $permission, ?int $order = null, bool $hidden = false ) {
		if ( '' === trim( $slug ) ) {
			throw new \InvalidArgumentException( 'Screen::slug não pode ser vazio.' );
		}

		if ( '' === trim( $label ) ) {
			throw new \InvalidArgumentException( 'Screen::label não pode ser vazio.' );
		}

		if ( '' === trim( $permission ) ) {
			throw new \InvalidArgumentException( 'Screen::permission não pode ser vazio.' );
		}

		$this->slug       = $slug;
		$this->label      = $label;
		$this->group      = ( null !== $group && '' !== trim( $group ) ) ? $group : null;
		$this->permission = $permission;
		$this->order      = $order;
		$this->hidden     = $hidden;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function label(): string {
		return $this->label;
	}

	/** Chave do grupo ao qual a tela pertence, ou `null` — navegação plana. */
	public function group(): ?string {
		return $this->group;
	}

	/** A chave que o `ScreenAccess` do plugin entende (docs/navegacao-do-painel.md §3). */
	public function permission(): string {
		return $this->permission;
	}

	/** Ordem declarada entre irmãos, ou `null` — cai para a ordem de declaração (§5). */
	public function order(): ?int {
		return $this->order;
	}

	/**
	 * Fora da árvore (`TreeBuilder`), mas registrada, guardada e
	 * endereçável como qualquer outra — a guarda (§4) e a contagem de
	 * "enxerga ao menos uma tela" (`NavCapabilityGate`) não distinguem
	 * tela oculta de tela normal; só a árvore distingue.
	 */
	public function hidden(): bool {
		return $this->hidden;
	}
}
