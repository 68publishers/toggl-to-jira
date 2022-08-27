init:
	make cache-clear
	cp ./.env.dist ./.env
	make stop
	make start
	make install-composer

stop:
	docker compose stop

start:
	docker compose up -d

down:
	docker compose down

cache-clear:
	rm -rf var/cache/*
	rm -rf var/log/*

install-composer:
	docker exec -it t2j-app composer install --no-interaction --no-ansi --prefer-dist --no-progress --optimize-autoloader
