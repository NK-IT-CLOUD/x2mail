APP_NAME := x2mail
APP_VERSION := $(shell grep -oP '<version>\K[^<]+' appinfo/info.xml)
CERT_DIR := $(HOME)/.nextcloud/certificates

.PHONY: update-core build clean sign release validate

update-core:
	bash scripts/update-core.sh
	bash scripts/apply-sm-patches.sh

build: clean
	@echo "Building $(APP_NAME)-$(APP_VERSION).tar.gz ..."
	@mkdir -p build
	tar czf build/$(APP_NAME)-$(APP_VERSION).tar.gz \
		--transform 's,^,$(APP_NAME)/,' \
		--exclude='app/data' \
		--exclude='build' \
		--exclude='.git' \
		--exclude='.gitignore' \
		--exclude='.gitmessage' \
		--exclude='Makefile' \
		--exclude='scripts' \
		--exclude='docs' \
		--exclude='SM_VERSION' \
		--exclude='*.tar.gz' \
		appinfo css img js l10n lib templates app README.md LICENSE CHANGELOG.md
	@echo "Built: build/$(APP_NAME)-$(APP_VERSION).tar.gz"

sign: build
	@test -f $(CERT_DIR)/$(APP_NAME).key || (echo "ERROR: $(CERT_DIR)/$(APP_NAME).key not found"; exit 1)
	@echo "Signing build/$(APP_NAME)-$(APP_VERSION).tar.gz ..."
	@openssl dgst -sha512 -sign $(CERT_DIR)/$(APP_NAME).key build/$(APP_NAME)-$(APP_VERSION).tar.gz | openssl base64 -A > build/$(APP_NAME)-$(APP_VERSION).tar.gz.sig
	@echo "Signature: build/$(APP_NAME)-$(APP_VERSION).tar.gz.sig"

validate: build
	@echo "Validating tarball structure ..."
	@tar tzf build/$(APP_NAME)-$(APP_VERSION).tar.gz | head -1 | grep -q "^$(APP_NAME)/" || (echo "ERROR: top-level folder must be $(APP_NAME)/"; exit 1)
	@tar tzf build/$(APP_NAME)-$(APP_VERSION).tar.gz | grep -q "$(APP_NAME)/appinfo/info.xml" || (echo "ERROR: appinfo/info.xml missing"; exit 1)
	@tar tzf build/$(APP_NAME)-$(APP_VERSION).tar.gz | grep -q "\.git" && (echo "ERROR: .git found in tarball"; exit 1) || true
	@echo "OK: tarball structure valid"

release: sign validate
	@echo ""
	@echo "Release $(APP_NAME) v$(APP_VERSION)"
	@echo "  Tarball:   build/$(APP_NAME)-$(APP_VERSION).tar.gz"
	@echo "  Signature: build/$(APP_NAME)-$(APP_VERSION).tar.gz.sig"
	@echo ""
	@echo "To publish on GitHub:"
	@echo "  gh release create v$(APP_VERSION) build/$(APP_NAME)-$(APP_VERSION).tar.gz --title v$(APP_VERSION) --repo NK-IT-CLOUD/$(APP_NAME)"
	@echo ""
	@echo "To publish on NC App Store:"
	@echo "  Upload URL: https://github.com/NK-IT-CLOUD/$(APP_NAME)/releases/download/v$(APP_VERSION)/$(APP_NAME)-$(APP_VERSION).tar.gz"
	@echo "  Signature:  $$(cat build/$(APP_NAME)-$(APP_VERSION).tar.gz.sig)"

clean:
	rm -rf build/
