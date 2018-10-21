PIDFILE=/tmp/smtp-sink.pid

help: ## What you're currently reading
	@IFS=$$'\n' ; \
	help_lines=(`fgrep -h "##" $(MAKEFILE_LIST) | fgrep -v fgrep | sed -e 's/\\$$//' | sed -e 's/##/:/'`); \
	clear ; \
	printf "Usage: make [target]\n\n" ; \
	printf "%-30s %s\n" "[target]" "help" ; \
	printf "%-30s %s\n" "--------" "----" ; \
	for help_line in $${help_lines[@]}; do \
		IFS=$$':' ; \
		help_split=($$help_line) ; \
		help_command=`echo $${help_split[0]} | sed -e 's/^ *//' -e 's/ *$$//'` ; \
		help_info=`echo $${help_split[2]} | sed -e 's/^ *//' -e 's/ *$$//'` ; \
		printf '\033[36m'; \
		printf "%-30s %s" $$help_command ; \
		printf '\033[0m'; \
		printf "%s\n" $$help_info; \
	done; \
	printf "\n"; \

install: ## Installs dev dependencies
	composer install
	npm install

test: server-start ## Runs tests
	"./vendor/bin/phpunit" --no-coverage
	make server-stop

coverage: server-start ## Runs tests with code coverage
	"./vendor/bin/phpunit" --coverage-html=./coverage/ --coverage-clover=./coverage/clover.xml
	make server-stop

$(PIDFILE): ## Starts the smtp-sink server
	./node_modules/.bin/smtp-sink -w allowed-sender@example.org & echo $$! > $@

server-start: server-stop $(PIDFILE) ## Stops and starts the smtp-sink server

server-stop: ## Stops smtp-sink server if it's running
	./scripts/stop-server.sh $(PIDFILE)

clean: ## Removes installed dev dependencies
	rm -rf ./vendor
	rm -rf ./node_modules

.PHONY: help install test clean coverage server-start server-stop
