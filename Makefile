# v3r-core — Makefile
#
# Biblioteca cliente compartilhada (composer library). Não roda WordPress —
# os testes desta fatia são PHP puro. Assume `composer` e `php` no PATH.

.PHONY: install prefix lint analyse test check clean help

install:
	composer install

prefix: install
	composer prefix

lint: install
	composer lint

analyse: prefix
	composer analyse

test: prefix
	composer test

check: prefix
	composer lint
	composer analyse
	composer test

clean:
	rm -rf vendor vendor-prefixed .phpunit.cache

help:
	@echo ""
	@echo "v3r-core — Comandos disponíveis"
	@echo "────────────────────────────────────────"
	@echo "  make install   Instala dependências (composer install)"
	@echo "  make prefix    Gera vendor-prefixed/ via Strauss"
	@echo "  make lint      Executa PHPCS"
	@echo "  make analyse   Executa PHPStan"
	@echo "  make test      Executa PHPUnit"
	@echo "  make check     Roda lint + analyse + test, nesta ordem"
	@echo "  make clean     Remove vendor/, vendor-prefixed/ e cache de testes"
	@echo ""
