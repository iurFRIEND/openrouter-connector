# SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
app_name=openrouter_connector
app_version=$(shell sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml)
project_dir=$(CURDIR)
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
cert_dir=$(HOME)/.nextcloud/certificates
# a Nextcloud server checkout (or installation) is needed to sign the app: occ integrity:sign-app
occ_dir ?= /var/www/html

.PHONY: all build dev npm npm-dev composer lint test appstore clean

all: build

# Production build of the frontend
build: npm

dev: npm-dev

npm:
	npm ci
	npm run build

npm-dev:
	npm ci
	npm run dev

composer:
	composer install --prefer-dist

# Every check the CI runs
lint: composer
	composer run lint
	composer run cs:check
	composer run psalm
	npm run lint
	npm run stylelint

test: composer
	composer run test:unit

clean:
	rm -rf $(build_dir)

# Builds the release archive build/$(app_name)-$(app_version).tar.gz. If the
# app store certificate is available, the archive is signed with occ and a
# signature for the app store release form is printed.
appstore: clean build
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude-from=.nextcloudignore \
	--exclude=/build \
	$(project_dir)/ $(sign_dir)/$(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ] && [ -f $(occ_dir)/occ ]; then \
		php $(occ_dir)/occ integrity:sign-app --privateKey=$(cert_dir)/$(app_name).key --certificate=$(cert_dir)/$(app_name).crt --path=$(sign_dir)/$(app_name)/ ;\
	else \
		echo "!!! WARNING: signature key or occ not found, the archive is not signed" ;\
	fi
	tar -czf $(build_dir)/$(app_name)-$(app_version).tar.gz \
		-C $(sign_dir) $(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "App store release signature:" ;\
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name)-$(app_version).tar.gz | openssl base64 | tee $(build_dir)/sign.txt ;\
	fi
	@echo "Archive: $(build_dir)/$(app_name)-$(app_version).tar.gz"
