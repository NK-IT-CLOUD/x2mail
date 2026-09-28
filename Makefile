APP_NAME := x2mail
APP_VERSION := $(shell grep -oP '<version>\K[^<]+' appinfo/info.xml)
CERT_DIR := $(HOME)/.nextcloud/certificates
STAGE := build/stage

TERSER := terser
TERSER_OPTS := --compress
CLEANCSS := cleancss

# JS files to minify (relative to stage dir)
JS_FILES := app/x2mail/v/current/static/js/app.js \
            app/x2mail/v/current/static/js/libs.js \
            app/x2mail/v/current/static/js/boot.js \
            app/x2mail/v/current/static/js/sieve.js \
            app/x2mail/v/current/static/js/openpgp.js \
            app/x2mail/v/current/app/plugins/nextcloud/js/webdav.js \
            app/x2mail/v/current/app/plugins/nextcloud/js/message.js \
            app/x2mail/v/current/app/plugins/nextcloud/js/composer.js \
            app/x2mail/v/current/app/plugins/nextcloud/js/messagelist.js \
            js/setup-wizard.js \
            js/x2mail.js

# CSS files to minify (relative to stage dir)
CSS_FILES := app/x2mail/v/current/static/css/app.css \
             app/x2mail/v/current/static/css/boot.css \
             app/x2mail/v/current/themes/x2mail/styles.css \
             app/x2mail/v/current/app/plugins/nextcloud/style.css \
             css/embed.css \
             css/setup-wizard.css

WEBMAIL_STAGE := build/webmail

# $(call minify,<dir>) minifies every JS_FILES/CSS_FILES entry that exists below <dir>.
define minify
	@for f in $(JS_FILES); do \
		if [ -f "$(1)/$$f" ]; then \
			$(TERSER) "$(1)/$$f" $(TERSER_OPTS) -o "$(1)/$$f" && echo "  $$f"; \
		fi; \
	done
	@for f in $(CSS_FILES); do \
		if [ -f "$(1)/$$f" ]; then \
			$(CLEANCSS) -o "$(1)/$$f" "$(1)/$$f" && echo "  $$f"; \
		fi; \
	done
endef

.PHONY: build clean sign release validate webmail-stage

build: clean
	@echo "==> Staging files ..."
	@mkdir -p $(STAGE)
	@cp -a appinfo css img js l10n lib templates app README.md LICENSE CHANGELOG.md $(STAGE)/
	@echo "==> Minifying JS/CSS ..."
	$(call minify,$(STAGE))
	@echo "==> Building $(APP_NAME)-$(APP_VERSION).tar.gz ..."
	@mkdir -p build
	cd $(STAGE) && tar czf ../$(APP_NAME)-$(APP_VERSION).tar.gz \
		--transform 's,^,$(APP_NAME)/,' \
		--exclude='app/data' \
		*
	@rm -rf $(STAGE)
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
	@if tar tzf build/$(APP_NAME)-$(APP_VERSION).tar.gz | grep -q "\.git"; then echo "ERROR: .git found in tarball"; exit 1; fi
	@if tar tzf build/$(APP_NAME)-$(APP_VERSION).tar.gz | grep -q "^$(APP_NAME)/standalone/"; then echo "ERROR: standalone/ found in tarball"; exit 1; fi
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

webmail-stage:
	@rm -rf $(WEBMAIL_STAGE)
	@mkdir -p $(WEBMAIL_STAGE)
	@cp -a app standalone $(WEBMAIL_STAGE)/
	@rm -rf $(WEBMAIL_STAGE)/app/data $(WEBMAIL_STAGE)/standalone/vendor \
		$(WEBMAIL_STAGE)/standalone/tests $(WEBMAIL_STAGE)/standalone/.phpunit.cache
	@mkdir -p $(WEBMAIL_STAGE)/appinfo
	@cp -a appinfo/info.xml $(WEBMAIL_STAGE)/appinfo/info.xml
	@echo "==> Minifying JS/CSS ..."
	$(call minify,$(WEBMAIL_STAGE))
	@echo "Staged: $(WEBMAIL_STAGE)"
