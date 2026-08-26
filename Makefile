# v3r-core — Makefile
#
# Biblioteca cliente compartilhada (composer library). Não roda WordPress —
# os testes desta fatia são PHP puro. Assume `composer` e `php` no PATH.
#
# O prefixo do Strauss (vendor-prefixed/) roda sozinho como post-install-cmd/
# post-update-cmd do composer.json — `composer install` já deixa tudo pronto.
# `make prefix` só existe para reexecutar manualmente sem reinstalar tudo.

.PHONY: install prefix lint analyse test check clean help

install:
	composer install

prefix:
	composer prefix

lint: install
	composer lint

analyse: install
	composer analyse

test: install
	composer test

check: install
	composer lint
	composer analyse
	composer test

clean:
	rm -rf vendor .phpunit.cache
	find vendor-prefixed -mindepth 1 ! -name '.gitkeep' -delete

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
