include .makefile/common.mk
include .makefile/docker.mk
include .makefile/composer.mk
include .makefile/node.mk
include .makefile/laravel.mk
include .makefile/test.mk

## Setup application
setup: filesystem-setup build start composer-install npm-install npm-build laravel-setup urls

## Clean-up application
clean: stop filesystem-clean
