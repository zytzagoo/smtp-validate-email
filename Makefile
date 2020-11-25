PIDFILE=/tmp/mailhog.pid
MAILHOG=./bin/mailhog

lc = $(subst A,a,$(subst B,b,$(subst C,c,$(subst D,d,$(subst E,e,$(subst F,f,$(subst G,g,$(subst H,h,$(subst I,i,$(subst J,j,$(subst K,k,$(subst L,l,$(subst M,m,$(subst N,n,$(subst O,o,$(subst P,p,$(subst Q,q,$(subst R,r,$(subst S,s,$(subst T,t,$(subst U,u,$(subst V,v,$(subst W,w,$(subst X,x,$(subst Y,y,$(subst Z,z,$1))))))))))))))))))))))))))

ifeq '$(findstring ;,$(PATH))' ';'
    #UNAME := Windows
    UNAME := $(shell uname 2>NUL || echo Windows)
else
    UNAME := $(shell uname 2>/dev/null || echo Unknown)
    UNAME := $(patsubst CYGWIN%,Cygwin,$(UNAME))
    UNAME := $(patsubst MSYS%,MSYS,$(UNAME))
    UNAME := $(patsubst MINGW%,MSYS,$(UNAME))
endif
UNAME := $(call lc,$(UNAME))

PLAT=386
# https://apple.stackexchange.com/questions/140651/why-does-arch-output-i386
# https://stackoverflow.com/questions/12763296/os-x-arch-command-incorrect
UNAME_P := $(shell sh -c 'uname -m 2>/dev/null || echo not')
ifeq ($(UNAME_P),x86_64)
	PLAT := amd64
endif
ifneq ($(filter %86,$(UNAME_P)),)
	PLAT := 386
endif
ifneq ($(filter arm%,$(UNAME_P)),)
	PLAT := arm
endif

MAILHOG_URL=https://github.com/mailhog/MailHog/releases/download/v1.0.1/MailHog_$(UNAME)_$(PLAT)

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
	make $(MAILHOG)

clean: ## Removes installed dev dependencies
	rm composer.lock
	rm -rf ./vendor
	rm -rf $(MAILHOG)

test: server-start ## Runs tests
	"./vendor/bin/phpunit" --no-coverage
	make server-stop

coverage: server-start ## Runs tests with code coverage
	"./vendor/bin/phpunit" --coverage-html=./coverage/ --coverage-clover=./coverage/clover.xml
	make server-stop

server-start: server-stop $(PIDFILE) ## Stops and starts the smtp server

server-stop: ## Stops smtp server if it's running
	./bin/stop-server.sh $(PIDFILE)

$(PIDFILE): ## Starts the smtp server
	$(MAILHOG) & echo $$! > $@

$(MAILHOG): ## Downloads platform-specific mailhog binary
#	@echo $(MAILHOG_URL)
	wget $(MAILHOG_URL) -O $@
	chmod +x $(MAILHOG)

.PHONY: help install test clean coverage server-start server-stop
