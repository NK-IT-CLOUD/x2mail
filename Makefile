APP_NAME := x2mail
APP_VERSION := $(shell grep -oP '<version>\K[^<]+' appinfo/info.xml)

.PHONY: update-core build clean

update-core:
	bash scripts/update-core.sh

build: clean
	@echo "Building $(APP_NAME)-$(APP_VERSION).tar.gz ..."
	@mkdir -p build
	tar czf build/$(APP_NAME)-$(APP_VERSION).tar.gz \
		--transform 's,^,$(APP_NAME)/,' \
		--exclude='app/data' \
		--exclude='build' \
		--exclude='.git' \
		--exclude='.gitignore' \
		--exclude='Makefile' \
		--exclude='scripts' \
		--exclude='*.tar.gz' \
		appinfo css img js l10n lib templates app README.md LICENSE
	@echo "Built: build/$(APP_NAME)-$(APP_VERSION).tar.gz"

clean:
	rm -rf build/
