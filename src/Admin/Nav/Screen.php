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
 *
 * `surfaces` é a quarta opção (adoção do V3RLGPD, 07/09/2026): em quais
 * superfícies esta tela existe, quando o plugin desenha navegação em mais
 * de um lugar a partir da mesma declaração — ex.: o painel do wp-admin e
 * uma página pública do site do cliente. Os rótulos são strings livres,
 * escolhidas pelo plugin; a biblioteca não sabe o que é "painel" ou
 * "público". **Lista vazia (o padrão) significa "todas as superfícies"**
 * — nada do que já foi declarado antes desta opção muda de comportamento.
 * Não é permissão: é escopo — uma tela fora da superfície pedida some da
 * árvore e do mapa daquela superfície independentemente de quem pergunta
 * poder vê-la (docs/navegacao-do-painel.md §2 e §5).
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

	/** @var string[] */
	private $surfaces;

	/**
	 * @param string   $slug
	 * @param string   $label
	 * @param ?string  $group
	 * @param string   $permission
	 * @param ?int     $order
	 * @param bool     $hidden
	 * @param string[] $surfaces Superfícies em que a tela existe. Vazio (padrão) = todas.
	 *
	 * @throws \InvalidArgumentException `slug`, `label` ou `permission` vazios.
	 */
	public function __construct( string $slug, string $label, ?string $group, string $permission, ?int $order = null, bool $hidden = false, array $surfaces = array() ) {
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
		$this->surfaces   = $surfaces;
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

	/**
	 * Superfícies declaradas para esta tela, ou lista vazia quando ela não
	 * restringe nenhuma — nesse caso, `belongsToSurface()` responde `true`
	 * para qualquer superfície pedida.
	 *
	 * @return string[]
	 */
	public function surfaces(): array {
		return $this->surfaces;
	}

	/**
	 * Se esta tela pertence à superfície informada. Tela sem `surfaces`
	 * declaradas pertence a qualquer superfície — inclusive quando
	 * nenhuma é pedida (`null`).
	 */
	public function belongsToSurface( ?string $surface ): bool {
		if ( array() === $this->surfaces ) {
			return true;
		}

		if ( null === $surface ) {
			return true;
		}

		return in_array( $surface, $this->surfaces, true );
	}
}
