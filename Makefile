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

restart:
	make stop
	make start

cache-clear:
	rm -rf var/cache/*
	rm -rf var/log/*

install-composer:
	docker exec -it t2j-app composer install --no-interaction --no-ansi --prefer-dist --no-progress --optimize-autoloader

build:
	rm -rf var/cache/*
	rm -rf var/log/*
	docker build -f ./docker/prod/Dockerfile . -t 68publishers/toggl-to-jira:latest

build-push:
	docker push 68publishers/toggl-to-jira:latest

rebuild:
	make build
	make start
