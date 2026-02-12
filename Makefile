.PHONY: help fix cs-check stan test check all

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

fix: ## Run PHP CS Fixer (auto-fix)
	vendor/bin/php-cs-fixer fix --diff --verbose

cs-check: ## Run PHP CS Fixer in dry-run mode
	vendor/bin/php-cs-fixer fix --diff --verbose --dry-run

stan: ## Run PHPStan static analysis
	vendor/bin/phpstan analyse --configuration=phpstan.neon.dist

test: ## Run PHPUnit tests
	vendor/bin/phpunit

check: cs-check stan test ## Run all checks (cs-check + stan + test)

all: fix stan test ## Fix code style, then run stan and tests
