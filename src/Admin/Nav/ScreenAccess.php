<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Quem responde "esta pessoa pode ver esta tela?" — a biblioteca nunca
 * pergunta ao WordPress diretamente (docs/navegacao-do-painel.md §3). O
 * plugin escolhe a implementação: `CapabilityAccess` para quem usa
 * capability nativa, ou uma própria para quem tem matriz de papéis
 * editável pelo cliente.
 *
 * **Contrato de desempenho, não só de comportamento:** a árvore e o gate
 * de acesso direto consultam `canView()` uma vez por tela visível, e o
 * mesmo `$permission` costuma se repetir entre telas de um mesmo módulo.
 * Implementações são responsáveis por guardar a própria resposta durante
 * a requisição — sem isso, uma matriz de papéis consultada por SQL ou por
 * chamada remota vira uma consulta por tela desenhada. O cache é sempre
 * por requisição; nunca persiste entre requisições.
 */
interface ScreenAccess {

	public function canView( string $permission ): bool;
}
