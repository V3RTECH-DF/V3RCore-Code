# v3r-core — Makefile
#
# Biblioteca cliente compartilhada (composer library). Não roda WordPress —
# os testes desta fatia são PHP puro. Assume `composer` e `php` no PATH.
#
# Esta biblioteca NÃO se auto-prefixa (nem a si mesma, nem o
# plugin-update-checker): quem prefixa v3r-core, via Strauss, é o plugin
# hospedeiro — numa única passada, junto com as dependências transitivas.
# Ver docs/integracao-em-plugin.md.

.PHONY: install lint analyse test test-js check clean help

install:
	composer install

lint: install
	composer lint

analyse: install
	composer analyse

test: install
	composer test

# Espelho JS dos ativos de front (src/Assets/js), sobre o MESMO conjunto de
# casos da metade PHP. Sem dependências: runner nativo do Node.
test-js:
	npm test

check: install
	composer lint
	composer analyse
	composer test
	npm test

clean:
	rm -rf vendor .phpunit.cache

help:
	@echo ""
	@echo "v3r-core — Comandos disponíveis"
	@echo "────────────────────────────────────────"
	@echo "  make install   Instala dependências (composer install)"
	@echo "  make lint      Executa PHPCS"
	@echo "  make analyse   Executa PHPStan"
	@echo "  make test      Executa PHPUnit"
	@echo "  make test-js   Executa os testes do espelho JS (node --test)"
	@echo "  make check     Roda lint + analyse + test + test-js, nesta ordem"
	@echo "  make clean     Remove vendor/ e cache de testes"
	@echo ""
